<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\StocktakingItem;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cycle counts. Expected quantities are snapshotted at start; on posting, the variance (counted − expected) is applied
 * as a delta to the CURRENT on_hand, so sales/receipts that happened during counting are preserved.
 * Uncounted lines are ignored (not assumed zero). One open session per branch (partial unique index).
 *
 * Second person (roadmap 4.6): finishing a count with differences puts it "waiting for approval"; the stock is changed only when someone who did not
 * count it approves it (see StocktakeApprovals for the rule, its switch and the small-difference threshold). Every door that posts a count (the
 * dashboard and the staff API) comes through post(), so there is one rule and no bypass.
 */
class StocktakingService
{
    public function __construct(private readonly InventoryLedger $ledger, private readonly AccessControl $access, private readonly AuditLogger $audit, private readonly StocktakeApprovals $approvals) {}

    public function start(User $actor, string $branchId, ?string $categoryId = null, ?string $notes = null): StocktakingSession
    {
        $this->authorize($actor, 'stocktaking.create', $branchId);

        return DB::transaction(function () use ($actor, $branchId, $categoryId, $notes) {
            $session = StocktakingSession::create(['number' => DocumentNumber::make('STK'), 'branch_id' => $branchId, 'status' => 'counting',
                'category_id' => $categoryId, 'notes' => $notes, 'started_by' => $actor->id]);
            $rows = BranchInventory::where('branch_id', $branchId)
                // A category count includes all of its sub-categories (materialised path "/root/child/").
                ->when($categoryId, fn ($q) => $q->whereHas('variant.product.category', fn ($c) => $c->where(fn ($w) => $w->whereKey($categoryId)->orWhere('path', 'like', '%/'.$categoryId.'/%'))))
                ->get(['product_variant_id', 'on_hand']);
            foreach ($rows as $row) {
                $session->items()->create(['product_variant_id' => $row->product_variant_id, 'expected_quantity' => $row->on_hand]);
            }
            $this->audit->log('stocktaking.started', $session, [], ['lines' => $rows->count()], actor: $actor);

            return $session;
        });
    }

    public function count(User $actor, StocktakingSession $session, string $variantId, int $counted): StocktakingItem
    {
        $this->authorize($actor, 'stocktaking.edit', $session->branch_id);
        if ($session->status !== 'counting' || $counted < 0) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $session->status]);
        }
        $item = $session->items()->firstOrNew(['product_variant_id' => $variantId]);
        if (! $item->exists) {
            // Found a variant that had no balance row: expected is its current on_hand (normally 0).
            $item->expected_quantity = BranchInventory::where('branch_id', $session->branch_id)->where('product_variant_id', $variantId)->value('on_hand') ?? 0;
        }
        $item->fill(['counted_quantity' => $counted, 'counted_by' => $actor->id, 'counted_at' => now()])->save();

        return $item;
    }

    /** Finishes the count. With differences it waits for a second person; a count that needs no approval is posted here and now. */
    public function submit(User $actor, StocktakingSession $session): StocktakingSession
    {
        $this->authorize($actor, 'stocktaking.edit', $session->branch_id);

        $session = DB::transaction(function () use ($actor, $session) {
            $session = StocktakingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== 'counting') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $session->status]);
            }
            $summary = $this->approvals->summary($session);
            $session->update(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id, 'variance_lines' => $summary['lines'], 'variance_units' => $summary['units'],
                'variance_value' => $summary['value']?->toDecimal(), 'returned_by' => null, 'returned_at' => null, 'returned_reason' => null]);
            $this->audit->log('stocktaking.submitted', $session, ['status' => 'counting'], ['status' => 'submitted', 'variance_lines' => $summary['lines'],
                'variance_value' => $summary['value']?->toDecimal()], actor: $actor);

            return $this->approvals->postsByItself($summary) ? $this->apply($session, $actor, null, 'auto') : $session;
        });
        if ($session->status === 'submitted') {
            $this->announce(fn () => $this->approvals->announceWaiting($session));
        }

        return $session;
    }

    /** Approves a finished count: the differences become stock movements. Refused for someone who counted it (while the rule is on). */
    public function post(User $actor, StocktakingSession $session): StocktakingSession
    {
        $this->authorize($actor, 'stocktaking.approve', $session->branch_id);
        $this->guardSecondPerson($actor, StocktakingSession::findOrFail($session->id));

        return DB::transaction(function () use ($actor, $session) {
            $session = StocktakingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== 'submitted') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $session->status]);
            }
            $self = in_array((string) $actor->id, $this->approvals->counters($session), true);

            return $this->apply($session, $actor, $actor, $self ? 'self' : 'second_person');
        });
    }

    /** The approver does not agree with the count: it goes back to counting, with the reason, and the people who counted are told. */
    public function sendBack(User $actor, StocktakingSession $session, string $reason): StocktakingSession
    {
        $this->authorize($actor, 'stocktaking.approve', $session->branch_id);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw DomainException::make('stocktake_reason_required');
        }
        $this->guardSecondPerson($actor, StocktakingSession::findOrFail($session->id));

        $session = DB::transaction(function () use ($actor, $session, $reason) {
            $session = StocktakingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== 'submitted') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $session->status]);
            }
            $session->update(['status' => 'counting', 'returned_by' => $actor->id, 'returned_at' => now(), 'returned_reason' => $reason,
                'submitted_at' => null, 'variance_lines' => null, 'variance_units' => null, 'variance_value' => null]);
            $this->audit->log('stocktaking.returned', $session, ['status' => 'submitted'], ['status' => 'counting', 'reason' => $reason], actor: $actor);

            return $session;
        });
        $this->announce(fn () => $this->approvals->announceReturned($session, $actor, $reason));

        return $session;
    }

    /** Someone who counted a session with differences may not approve or send it back while the rule is on. The refusal is written down (outside the caller's transaction, so it is kept). */
    private function guardSecondPerson(User $actor, StocktakingSession $session): void
    {
        if ($session->status === 'submitted' && ! $this->approvals->mayApprove($actor, $session)) {
            $this->audit->log('stocktaking.self_approval_refused', $session, [], [], ['actor' => $actor->id], actor: $actor);
            throw DomainException::make('stocktake_second_person', 403);
        }
    }

    /** Posts the differences (inside the caller's transaction, with the session row locked) and records who approved it and how. */
    private function apply(StocktakingSession $session, User $actor, ?User $approver, string $mode): StocktakingSession
    {
        $variances = [];
        foreach ($session->items()->whereNotNull('counted_quantity')->orderBy('id')->get() as $item) {
            $delta = $item->counted_quantity - $item->expected_quantity;
            if ($delta !== 0) {
                $this->ledger->apply($session->branch_id, $item->product_variant_id, MovementType::StocktakeCorrection, $delta, 0,
                    'stocktaking_session', $session->id, 'Stocktake '.$session->number, $actor->id);
                $variances[$item->product_variant_id] = $delta;
            }
            BranchInventory::where('branch_id', $session->branch_id)->where('product_variant_id', $item->product_variant_id)->update(['last_counted_at' => now()]);
        }
        $session->update(['status' => 'posted', 'approved_by' => $approver?->id, 'posted_at' => now(), 'approval_mode' => $mode]);
        $this->audit->log('stocktaking.posted', $session, [], ['variances' => $variances, 'approval' => $mode], actor: $approver ?? $actor);

        return $session;
    }

    /** An alert that cannot be sent never undoes a count that was already saved. */
    private function announce(callable $send): void
    {
        rescue($send, report: true);
    }

    public function cancel(User $actor, StocktakingSession $session): StocktakingSession
    {
        $this->authorize($actor, 'stocktaking.approve', $session->branch_id);
        if (! in_array($session->status, ['counting', 'submitted'], true)) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $session->status]);
        }
        $old = $session->status;
        $session->update(['status' => 'cancelled']);
        $this->audit->log('stocktaking.cancelled', $session, ['status' => $old], ['status' => 'cancelled'], actor: $actor);

        return $session;
    }

    private function authorize(User $actor, string $permission, string $branchId): void
    {
        if (! $this->access->allows($actor, $permission, $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }
}
