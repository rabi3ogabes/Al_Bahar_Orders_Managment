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
 */
class StocktakingService
{
    public function __construct(private readonly InventoryLedger $ledger, private readonly AccessControl $access, private readonly AuditLogger $audit) {}

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

    public function submit(User $actor, StocktakingSession $session): StocktakingSession
    {
        $this->authorize($actor, 'stocktaking.edit', $session->branch_id);
        if ($session->status !== 'counting') {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $session->status]);
        }
        $session->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->audit->log('stocktaking.submitted', $session, ['status' => 'counting'], ['status' => 'submitted'], actor: $actor);

        return $session;
    }

    public function post(User $actor, StocktakingSession $session): StocktakingSession
    {
        $this->authorize($actor, 'stocktaking.approve', $session->branch_id);

        return DB::transaction(function () use ($actor, $session) {
            $session = StocktakingSession::whereKey($session->id)->lockForUpdate()->first();
            if ($session->status !== 'submitted') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $session->status]);
            }
            $variances = [];
            foreach ($session->items()->whereNotNull('counted_quantity')->get() as $item) {
                $delta = $item->counted_quantity - $item->expected_quantity;
                if ($delta !== 0) {
                    $this->ledger->apply($session->branch_id, $item->product_variant_id, MovementType::StocktakeCorrection, $delta, 0,
                        'stocktaking_session', $session->id, 'Stocktake '.$session->number, $actor->id);
                    $variances[$item->product_variant_id] = $delta;
                }
                BranchInventory::where('branch_id', $session->branch_id)->where('product_variant_id', $item->product_variant_id)->update(['last_counted_at' => now()]);
            }
            $session->update(['status' => 'posted', 'approved_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->log('stocktaking.posted', $session, [], ['variances' => $variances], actor: $actor);

            return $session;
        });
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
