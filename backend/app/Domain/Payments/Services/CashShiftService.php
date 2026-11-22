<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Models\Refund;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cash shift of a branch drawer (docs/guides/CASH_SHIFT.md).
 *
 *   open (counted float) ──▶ hand-ins in, bank deposits and cash refunds out ──▶ close (end-of-day count) ──▶ Z-report
 *
 *  - WHO: whoever may receive cash at the branch (`payments.edit`, the same level as {@see CashDrawerService::handIn}). Reading needs `payments.view`.
 *  - ONE open shift per branch (a partial unique index backs it), so one drawer is never opened twice. Opening and closing twice with the same count
 *    (a double tap) answers with the shift that already exists instead of failing or doing it again.
 *  - EXPECTED CASH = float + hand-ins received (what the receiver COUNTED) - hand-ins banked in the shift - cash refunds paid out of the drawer - petty-cash
 *    payouts that were not voided ({@see CashPayoutService}). All money is {@see Money} (whole dirham), never floats.
 *  - THE COUNT is by note (QAR 500/200/100/50/10/5/1) plus a coins total; the server adds it up, nobody types a total.
 *  - VARIANCE reuses the hand-in rules: above `cash.shift_note_above` a written reason is needed (same wording); above `cash.shift_review_above` a
 *    second person who holds `payments.override` and neither opened nor closed the shift has to review it.
 *  - Cash received while no shift is open follows the `cash.shift_policy` setting: refused ("block"), or added to the next shift opened ("attach").
 *  - Row locks: receiving, banking and closing all take the shift row first, so a hand-in is either inside the shift (and in its Z-report) or after it.
 */
final class CashShiftService
{
    /** The notes of the Qatari riyal, largest first. Coins are counted as one amount. */
    public const NOTES = [500, 200, 100, 50, 10, 5, 1];

    private const MAX_COUNT = 100000;

    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly CashShiftSettings $settings,
    ) {}

    // ── Reading ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function openShift(string $branchId): ?CashShift
    {
        return CashShift::query()->where('branch_id', $branchId)->where('status', 'open')->first();
    }

    /**
     * What a shift holds, worked out from the rows that belong to it (one query, however many shifts): the float, the hand-ins received (split by where
     * the cash came from), what was banked, the cash refunds, the expected cash, and the cash the cashier collected personally and has not handed in.
     *
     * @param  Collection<int, CashShift>  $shifts
     * @return array<string, array<string, mixed>> shift id => figures: float, received (+ _cod, _counter, _difference), handins, collections, deposits (+ _count), refunds (+ _count), payouts (+ _count), expected, own_count, own_amount
     */
    public function figuresFor(Collection $shifts): array
    {
        if ($shifts->isEmpty()) {
            return [];
        }
        $ids = $shifts->pluck('id')->all();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT s.id,
                (SELECT count(*) FROM cash_handovers h WHERE h.cash_shift_id = s.id) AS handins,
                (SELECT coalesce(sum(h.counted_amount), 0) FROM cash_handovers h WHERE h.cash_shift_id = s.id) AS received,
                (SELECT coalesce(sum(h.collections_count), 0) FROM cash_handovers h WHERE h.cash_shift_id = s.id) AS collections,
                (SELECT coalesce(sum(c.amount_collected), 0) FROM cash_collections c JOIN cash_handovers h ON h.id = c.cash_handover_id WHERE h.cash_shift_id = s.id AND c.context = 'cod') AS cod,
                (SELECT coalesce(sum(c.amount_collected), 0) FROM cash_collections c JOIN cash_handovers h ON h.id = c.cash_handover_id WHERE h.cash_shift_id = s.id AND c.context <> 'cod') AS counter,
                (SELECT count(*) FROM cash_handovers h WHERE h.deposit_shift_id = s.id) AS deposits_count,
                (SELECT coalesce(sum(h.counted_amount), 0) FROM cash_handovers h WHERE h.deposit_shift_id = s.id) AS deposits,
                (SELECT count(*) FROM refunds r WHERE r.cash_shift_id = s.id) AS refunds_count,
                (SELECT coalesce(sum(r.amount), 0) FROM refunds r WHERE r.cash_shift_id = s.id) AS refunds,
                (SELECT count(*) FROM cash_payouts p WHERE p.cash_shift_id = s.id AND p.voided_at IS NULL) AS payouts_count,
                (SELECT coalesce(sum(p.amount), 0) FROM cash_payouts p WHERE p.cash_shift_id = s.id AND p.voided_at IS NULL) AS payouts,
                (SELECT count(*) FROM cash_collections c WHERE c.branch_id = s.branch_id AND c.collected_by = s.opened_by AND c.collected_at >= s.opened_at
                    AND c.tender = 'cash' AND c.status IN ('collected', 'partial') AND c.amount_collected > 0 AND c.handed_in_at IS NULL) AS own_count,
                (SELECT coalesce(sum(c.amount_collected), 0) FROM cash_collections c WHERE c.branch_id = s.branch_id AND c.collected_by = s.opened_by AND c.collected_at >= s.opened_at
                    AND c.tender = 'cash' AND c.status IN ('collected', 'partial') AND c.amount_collected > 0 AND c.handed_in_at IS NULL) AS own_amount
             FROM cash_shifts s WHERE s.id IN ({$in})",
            $ids,
        );
        $float = $shifts->mapWithKeys(fn (CashShift $s) => [$s->id => Money::of((string) $s->opening_total)]);

        $out = [];
        foreach ($rows as $r) {
            $received = Money::of((string) $r->received);
            $cod = Money::of((string) $r->cod);
            $counter = Money::of((string) $r->counter);
            $deposits = Money::of((string) $r->deposits);
            $refunds = Money::of((string) $r->refunds);
            $payouts = Money::of((string) $r->payouts);
            $out[(string) $r->id] = [
                'float' => $float[(string) $r->id],
                'received' => $received, 'received_cod' => $cod, 'received_counter' => $counter, 'received_difference' => $received->minus($cod)->minus($counter),
                'handins' => (int) $r->handins, 'collections' => (int) $r->collections,
                'deposits' => $deposits, 'deposits_count' => (int) $r->deposits_count,
                'refunds' => $refunds, 'refunds_count' => (int) $r->refunds_count,
                'payouts' => $payouts, 'payouts_count' => (int) $r->payouts_count,
                'expected' => $float[(string) $r->id]->plus($received)->minus($deposits)->minus($refunds)->minus($payouts),
                'own_count' => (int) $r->own_count, 'own_amount' => Money::of((string) $r->own_amount),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> the figures of one shift (see {@see self::figuresFor()}) */
    public function figures(CashShift $shift): array
    {
        return $this->figuresFor(new Collection([$shift]))[$shift->id];
    }

    /**
     * Adds up a count: how many notes of each value and the coins as one amount. Anything else (a negative or fractional number of notes, an unknown value,
     * coins with more than two decimals) is refused, so the total can only be what was really counted.
     *
     * @param  array<int|string, mixed>  $notes  value => how many
     * @return array{notes: array<int, int>, coins: Money, total: Money}
     */
    public function count(array $notes, string|int|null $coins): array
    {
        $clean = [];
        $total = Money::zero();
        foreach (array_keys($notes) as $value) {
            if (! in_array((int) $value, self::NOTES, true) || (string) (int) $value !== (string) $value) {
                throw DomainException::make('cash_shift_count_invalid');
            }
        }
        foreach (self::NOTES as $value) {
            $n = $notes[$value] ?? $notes[(string) $value] ?? 0;
            $n = $n === '' ? 0 : $n;
            if (! (is_int($n) || (is_string($n) && ctype_digit($n))) || (int) $n < 0 || (int) $n > self::MAX_COUNT) {
                throw DomainException::make('cash_shift_count_invalid');
            }
            $clean[(string) $value] = (int) $n;
            $total = $total->plus(Money::ofMinor($value * 100 * (int) $n));
        }
        $coins = trim((string) ($coins ?? ''));
        $coins = $coins === '' ? '0' : $coins;
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $coins) !== 1) {
            throw DomainException::make('cash_shift_count_invalid');
        }
        $coinMoney = Money::of($coins);

        return ['notes' => $clean, 'coins' => $coinMoney, 'total' => $total->plus($coinMoney)];
    }

    // ── Opening ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Opens the drawer of a branch with the counted float. A second tap with the same count by the same person answers with the shift that is already
     * open; anyone else (or a different count) is told who has the drawer.
     *
     * @param  array<int|string, mixed>  $notes
     */
    public function open(User $actor, string $branchId, array $notes, string|int|null $coins = null): CashShift
    {
        $this->authorize($actor, 'payments.edit', $branchId);
        $count = $this->count($notes, $coins);

        try {
            return DB::transaction(function () use ($actor, $branchId, $count) {
                $existing = CashShift::query()->where('branch_id', $branchId)->where('status', 'open')->lockForUpdate()->first();
                if ($existing !== null) {
                    return $this->sameOpening($existing, $actor, $count['total']);
                }
                $previousClose = CashShift::query()->where('branch_id', $branchId)->where('status', 'closed')->max('closed_at');
                $shift = CashShift::create([
                    'branch_id' => $branchId, 'status' => 'open', 'opened_by' => $actor->id, 'opened_at' => now(),
                    'opening_notes' => $count['notes'], 'opening_coins' => $count['coins']->toDecimal(), 'opening_total' => $count['total']->toDecimal(),
                ]);
                // Cash that was received while no shift was open (policy "attach") belongs to the shift that opens next, back to the previous close.
                // The very first shift of a branch claims nothing: what came in before shifts existed is not part of any drawer count.
                $claimed = $previousClose === null ? 0 : CashHandover::query()->where('branch_id', $branchId)->whereNull('cash_shift_id')->where('created_at', '>=', $previousClose)
                    ->update(['cash_shift_id' => $shift->id]);
                $this->audit->log('cash_shift.opened', $shift, [], ['float' => $count['total']->toDecimal(), 'notes' => $count['notes'], 'coins' => $count['coins']->toDecimal(),
                    'claimed_handins' => $claimed], branchId: $branchId, actor: $actor);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            // Two people (or two taps) opened the drawer at the same moment: the other one won.
            return $this->sameOpening($this->openShift($branchId) ?? throw DomainException::make('cash_shift_already_open', 409), $actor, $count['total']);
        }
    }

    /** The open shift answered to a second "open": the same person with the same float is a double tap; anything else is refused. */
    private function sameOpening(CashShift $existing, User $actor, Money $float): CashShift
    {
        if ($existing->opened_by === $actor->id && Money::of((string) $existing->opening_total)->equals($float)) {
            return $existing;
        }
        $existing->loadMissing('opener:id,name');

        throw DomainException::make('cash_shift_already_open', 409, ['name' => (string) $existing->opener?->name, 'since' => $existing->opened_at->setTimezone(BusinessTime::zone())->format('H:i')]);
    }

    // ── What the drawer attaches to ──────────────────────────────────────────────────────────────────────────────

    /**
     * The shift that cash being received now belongs to (the open one, locked until the caller's transaction ends), or null when none is open and the
     * policy lets the cash in anyway. With the policy "block" and no open shift the cash is refused. Call inside a transaction.
     */
    public function forReceiving(string $branchId): ?CashShift
    {
        $shift = CashShift::query()->where('branch_id', $branchId)->where('status', 'open')->lockForUpdate()->first();
        if ($shift === null && $this->settings->blocksWithoutShift()) {
            throw DomainException::make('cash_shift_required', 422);
        }

        return $shift;
    }

    /** The shift a bank deposit leaves the drawer in (locked), or null when the drawer is shut (the cash had left before the next count). Call inside a transaction. */
    public function forDeposit(string $branchId): ?CashShift
    {
        return CashShift::query()->where('branch_id', $branchId)->where('status', 'open')->lockForUpdate()->first();
    }

    /**
     * A refund paid out in cash comes out of the drawer of the branch it was paid at: the open shift of one of the order's branches, the one the person who
     * paid it opened first. No open shift, nothing to attach. Call inside the transaction that marks the refund paid.
     */
    public function attachRefund(Refund $refund, User $actor): void
    {
        $order = $refund->order_id ? Order::query()->find($refund->order_id, ['id']) : null;
        $branchIds = array_values(array_filter($order?->branchIds() ?? [], fn (string $b) => $this->access->allows($actor, 'payments.edit', $b)));
        if ($branchIds === []) {
            return;
        }
        $shift = CashShift::query()->where('status', 'open')->whereIn('branch_id', $branchIds)
            ->orderByRaw('opened_by = ? desc', [$actor->id])->orderBy('opened_at')->orderBy('id')->lockForUpdate()->first();
        if ($shift === null) {
            return;
        }
        Refund::query()->whereKey($refund->id)->update(['cash_shift_id' => $shift->id]);
        $this->audit->log('cash_shift.refund_paid', $shift, [], ['refund' => $refund->number, 'amount' => $refund->amount], branchId: $shift->branch_id, actor: $actor);
    }

    // ── Closing ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Closes the shift with the end-of-day count: freezes the figures of the Z-report, hands out the next Z number of the branch and, when the difference
     * is large, leaves it for a second person to review. Closing twice with the same count answers with the closed shift; with another count it is refused.
     *
     * @param  array<int|string, mixed>  $notes
     */
    public function close(User $actor, CashShift $shift, array $notes, string|int|null $coins = null, ?string $note = null): CashShift
    {
        $this->authorize($actor, 'payments.edit', $shift->branch_id);
        $count = $this->count($notes, $coins);
        $note = $note !== null ? trim($note) : null;

        return DB::transaction(function () use ($actor, $shift, $count, $note) {
            $s = CashShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($s->status === 'closed') {
                if ($s->closed_by === $actor->id && Money::of((string) $s->closing_total)->equals($count['total'])) {
                    return $s;
                }

                throw DomainException::make('cash_shift_already_closed', 409);
            }
            // A hand-in that slipped in without a shift just after this one opened (it took the lock first) still belongs to it.
            CashHandover::query()->where('branch_id', $s->branch_id)->whereNull('cash_shift_id')->where('created_at', '>=', $s->opened_at)->update(['cash_shift_id' => $s->id]);

            $figures = $this->figures($s);
            $expected = $figures['expected'];
            $variance = $count['total']->minus($expected);
            $size = Money::ofMinor(abs($variance->minor));
            if ($size->greaterThan($this->settings->noteAbove()) && ($note === null || mb_strlen($note) < 3)) {
                throw DomainException::make('cash_variance_note_required', 422, ['expected' => $expected->format()]);
            }
            $review = $size->greaterThan($this->settings->reviewAbove());
            $number = $this->nextNumber($s->branch_id);

            $s->update([
                'status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now(),
                'closing_notes' => $count['notes'], 'closing_coins' => $count['coins']->toDecimal(), 'closing_total' => $count['total']->toDecimal(),
                'expected_total' => $expected->toDecimal(), 'variance' => $variance->toDecimal(),
                'variance_note' => $note !== null && $note !== '' ? $note : null, 'variance_status' => $review ? 'open' : null,
                'z_number' => $number, 'summary' => $this->summaryOf($figures),
            ]);
            $this->audit->log('cash_shift.closed', $s, ['status' => 'open'], ['status' => 'closed', 'z' => $s->zLabel(), 'expected' => $expected->toDecimal(),
                'counted' => $count['total']->toDecimal(), 'variance' => $variance->toDecimal(), 'notes' => $count['notes'], 'coins' => $count['coins']->toDecimal(),
                'needs_review' => $review, 'note' => $s->variance_note], branchId: $s->branch_id, actor: $actor);

            return $s;
        });
    }

    /** A second person signs off a large difference. Same rule as a hand-in variance: `payments.override`, and not someone who handled the drawer. */
    public function reviewVariance(User $reviewer, CashShift $shift, string $resolution): CashShift
    {
        $this->authorize($reviewer, 'payments.override', $shift->branch_id);
        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 3) {
            throw DomainException::make('cash_variance_note_required', 422, ['expected' => Money::of((string) $shift->expected_total)->format()]);
        }
        if (in_array($reviewer->id, [$shift->opened_by, $shift->closed_by], true)) {
            throw DomainException::make('cash_review_needs_third_person', 403);
        }

        return DB::transaction(function () use ($reviewer, $shift, $resolution) {
            $s = CashShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if (! $s->hasOpenVariance()) {
                throw DomainException::make('cash_no_open_variance', 409);
            }
            $s->update(['variance_status' => 'resolved', 'variance_resolved_by' => $reviewer->id, 'variance_resolved_at' => now(), 'variance_resolution' => $resolution]);
            $this->audit->log('cash_shift.variance_reviewed', $s, ['variance_status' => 'open'], ['variance_status' => 'resolved', 'variance' => $s->variance, 'resolution' => $resolution],
                branchId: $s->branch_id, actor: $reviewer);

            return $s;
        });
    }

    // ── Printing ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Counts one print of the Z-report (and writes it to the audit log). The first print of a shift is the original, every later one is a COPY.
     *
     * @return int how many times the report has been printed, this print included (1 = the original)
     */
    public function recordPrint(CashShift $shift, User $actor): int
    {
        $row = DB::connection()->selectOne("UPDATE cash_shifts SET print_count = print_count + 1 WHERE id = ? AND status = 'closed' RETURNING print_count", [$shift->id], false);
        if ($row === null) {
            throw DomainException::make('cash_shift_not_closed', 409);
        }
        $this->audit->log('cash_shift.z_printed', $shift, [], ['z' => $shift->zLabel(), 'print' => (int) $row->print_count, 'copy' => (int) $row->print_count > 1], branchId: $shift->branch_id, actor: $actor);

        return (int) $row->print_count;
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────────────────

    /** The next Z number of a branch: one atomic upsert, so two closes can never get the same number and a number is never handed out twice. */
    private function nextNumber(string $branchId): int
    {
        $row = DB::connection()->selectOne(
            'INSERT INTO cash_shift_counters (branch_id, last_number) VALUES (?, 1) ON CONFLICT (branch_id) DO UPDATE SET last_number = cash_shift_counters.last_number + 1 RETURNING last_number',
            [$branchId],
            false,
        );

        return (int) $row->last_number;
    }

    /**
     * What the Z-report keeps of the figures, as plain strings.
     *
     * @param  array<string, mixed>  $f  {@see self::figuresFor()}
     * @return array<string, mixed>
     */
    private function summaryOf(array $f): array
    {
        return [
            'float' => $f['float']->toDecimal(),
            'received' => ['total' => $f['received']->toDecimal(), 'cod' => $f['received_cod']->toDecimal(), 'counter' => $f['received_counter']->toDecimal(),
                'difference' => $f['received_difference']->toDecimal(), 'handins' => $f['handins'], 'collections' => $f['collections']],
            'deposits' => ['amount' => $f['deposits']->toDecimal(), 'count' => $f['deposits_count']],
            'refunds' => ['amount' => $f['refunds']->toDecimal(), 'count' => $f['refunds_count']],
            'payouts' => ['amount' => $f['payouts']->toDecimal(), 'count' => $f['payouts_count']],
            'expected' => $f['expected']->toDecimal(),
            'own' => ['amount' => $f['own_amount']->toDecimal(), 'count' => $f['own_count']],
        ];
    }

    private function authorize(User $actor, string $permission, string $branchId): void
    {
        if (! $this->access->allows($actor, $permission, $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }
}
