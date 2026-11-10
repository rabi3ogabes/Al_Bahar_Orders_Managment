<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cash accountability for COD and pay-at-pickup collections:
 *
 *   collector (driver / cashier) ──hand-in──▶ branch cashier ──deposit──▶ bank
 *
 *  - Hand-in: the receiver counts the cash; the system compares it with what the collector's open cash collections add up
 *    to. A difference is allowed but must be explained (note) and stays an open variance until finance resolves it.
 *    Nobody can receive their own cash (also enforced by a DB check).
 *  - Deposit: handed-in cash that is still in the branch drawer is banked in one go under a bank reference.
 *  - Card-terminal collections are not cash and never appear here.
 *  - Custody needs `payments.edit` at the branch (branch managers, accountants). Collectors only hold `payments.create`,
 *    so a driver can neither receive a colleague's cash nor bank the drawer.
 * Collections themselves stay immutable facts; they only gain a link to the hand-in that settled them.
 */
class CashDrawerService
{
    public function __construct(private readonly AccessControl $access, private readonly AuditLogger $audit) {}

    /** Cash collections that a collector still holds (not handed in), optionally narrowed to one branch. */
    /** @return Builder<CashCollection> */
    public function openCollections(User|string $collector, ?string $branchId = null): Builder
    {
        return CashCollection::query()
            ->where('collected_by', $collector instanceof User ? $collector->id : $collector)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('tender', 'cash')->whereIn('status', ['collected', 'partial'])->where('amount_collected', '>', 0)
            ->whereNull('handed_in_at');
    }

    /** What the collector owes the branch: [branch_id => ['count' => n, 'amount' => Money, 'oldest' => CarbonImmutable]]. */
    public function balancesFor(User $collector): array
    {
        return $this->openCollections($collector)->groupBy('branch_id')
            ->selectRaw('branch_id, count(*) as n, sum(amount_collected) as total, min(collected_at) as oldest')->get()
            ->mapWithKeys(fn ($r) => [$r->branch_id => ['count' => (int) $r->n, 'amount' => Money::of((string) $r->total), 'oldest' => $r->oldest]])->all();
    }

    /** Cash that has been handed in but not yet banked, per branch. */
    public function drawerBalance(string $branchId): Money
    {
        return Money::of((string) CashHandover::where('branch_id', $branchId)->whereNull('deposited_at')->sum('counted_amount'));
    }

    /**
     * The receiver records the cash a collector hands in at a branch.
     *
     * @param  list<string>|null  $collectionIds  limit the hand-in to these open collections (default: all the collector holds at the branch)
     */
    public function handIn(User $receiver, User $collector, string $branchId, string $countedAmount, ?string $note = null, ?array $collectionIds = null): CashHandover
    {
        if ($receiver->id === $collector->id) {
            throw DomainException::make('cash_self_handover', 422);
        }
        $this->authorize($receiver, 'payments.edit', $branchId);
        $counted = Money::of($countedAmount);
        $note = $note !== null ? trim($note) : null;

        return DB::transaction(function () use ($receiver, $collector, $branchId, $counted, $note, $collectionIds) {
            $rows = $this->openCollections($collector, $branchId)
                ->when($collectionIds !== null, fn ($q) => $q->whereIn('id', $collectionIds))
                ->orderBy('collected_at')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw DomainException::make('cash_nothing_to_hand_in', 422);
            }
            if ($collectionIds !== null && $rows->count() !== count(array_unique($collectionIds))) {
                throw DomainException::make('cash_collection_unavailable', 409);
            }

            $expected = $rows->reduce(fn (Money $c, CashCollection $r) => $c->plus(Money::of($r->amount_collected)), Money::zero());
            $diff = $counted->minus($expected);
            $balanced = $diff->isZero();
            if (! $balanced && ($note === null || mb_strlen($note) < 3)) {
                throw DomainException::make('cash_variance_note_required', 422, ['expected' => $expected->format()]);
            }

            $handover = CashHandover::create([
                'number' => DocumentNumber::make('CH'), 'branch_id' => $branchId, 'collector_id' => $collector->id, 'received_by' => $receiver->id,
                'collections_count' => $rows->count(), 'expected_amount' => $expected->toDecimal(), 'counted_amount' => $counted->toDecimal(), 'variance' => $diff->toDecimal(),
                'status' => $balanced ? 'balanced' : ($diff->isNegative() ? 'short' : 'over'), 'note' => $note,
                'variance_status' => $balanced ? null : 'open',
            ]);
            CashCollection::whereIn('id', $rows->pluck('id'))->update(['handed_in_at' => now(), 'handed_in_to' => $receiver->id, 'cash_handover_id' => $handover->id]);

            $this->audit->log('cash.handed_in', $handover, [], ['collector_id' => $collector->id, 'expected' => $expected->toDecimal(), 'counted' => $counted->toDecimal(),
                'variance' => $diff->toDecimal(), 'collections' => $rows->count()], branchId: $branchId, actor: $receiver);

            return $handover;
        });
    }

    /** Finance explains a variance. The reviewer must be someone other than the two people who handled the cash. */
    public function resolveVariance(User $reviewer, CashHandover $handover, string $resolution): CashHandover
    {
        $this->authorize($reviewer, 'payments.override', $handover->branch_id);
        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 3) {
            throw DomainException::make('cash_variance_note_required', 422, ['expected' => Money::of($handover->expected_amount)->format()]);
        }
        if (in_array($reviewer->id, [$handover->collector_id, $handover->received_by], true)) {
            throw DomainException::make('cash_review_needs_third_person', 403);
        }

        return DB::transaction(function () use ($reviewer, $handover, $resolution) {
            $h = CashHandover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            if (! $h->hasOpenVariance()) {
                throw DomainException::make('cash_no_open_variance', 409);
            }
            $h->update(['variance_status' => 'resolved', 'variance_resolved_by' => $reviewer->id, 'variance_resolved_at' => now(), 'variance_resolution' => $resolution]);
            $this->audit->log('cash.variance_resolved', $h, ['variance_status' => 'open'], ['variance_status' => 'resolved', 'variance' => $h->variance, 'resolution' => $resolution],
                branchId: $h->branch_id, actor: $reviewer);

            return $h;
        });
    }

    /**
     * Bank handed-in cash. All hand-ins must belong to one branch and still be in the drawer.
     *
     * @param  list<string>  $handoverIds
     * @return array{count: int, amount: Money, reference: string}
     */
    public function deposit(User $actor, array $handoverIds, string $bankReference): array
    {
        $reference = trim($bankReference);
        if ($reference === '' || $handoverIds === []) {
            throw DomainException::make('cash_deposit_invalid', 422);
        }

        return DB::transaction(function () use ($actor, $handoverIds, $reference) {
            $rows = CashHandover::whereIn('id', array_unique($handoverIds))->lockForUpdate()->get();
            if ($rows->count() !== count(array_unique($handoverIds)) || $rows->pluck('branch_id')->unique()->count() !== 1) {
                throw DomainException::make('cash_deposit_invalid', 422);
            }
            $branchId = $rows->first()->branch_id;
            $this->authorize($actor, 'payments.edit', $branchId);
            if ($rows->contains(fn (CashHandover $h) => $h->deposited_at !== null)) {
                throw DomainException::make('cash_already_deposited', 409);
            }

            $total = $rows->reduce(fn (Money $c, CashHandover $h) => $c->plus(Money::of($h->counted_amount)), Money::zero());
            CashHandover::whereIn('id', $rows->pluck('id'))->update(['deposited_at' => now(), 'deposit_reference' => $reference, 'deposited_by' => $actor->id]);
            $this->audit->log('cash.deposited', $rows->first(), [], ['handovers' => $rows->pluck('number')->all(), 'amount' => $total->toDecimal(), 'reference' => $reference],
                branchId: $branchId, actor: $actor);

            return ['count' => $rows->count(), 'amount' => $total, 'reference' => $reference];
        });
    }

    private function authorize(User $actor, string $permission, string $branchId): void
    {
        if (! $this->access->allows($actor, $permission, $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }
}
