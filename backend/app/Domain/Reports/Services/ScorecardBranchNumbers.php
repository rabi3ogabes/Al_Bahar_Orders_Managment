<?php

namespace App\Domain\Reports\Services;

use App\Domain\Operations\Services\BranchSchedules;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The raw cases behind a branch's scorecard for one window: counts, the list of minutes each order took, the reasons things went wrong. No rounding, no
 * minimum-sample rule and no comparison here: that is {@see ScorecardService}'s job. Read-only. One grouped query per family of measures, every one
 * bounded by the window and by the branches asked about (and started from an indexed date), and aggregating in the database; the only rows that come
 * back one by one are the order timestamps whose minutes can only be counted in PHP (see {@see self::timing()}).
 *
 * WHICH ORDERS COUNT, and how a thing is measured, is the daily close's and the weekly owner pack's definition, so a number here is never different from
 * the same number there. Where this class states a definition again (a SQL condition cannot call a method), the line says which original it follows:
 *
 *  - an order is "real" by {@see DailyCloseNumbers::realOrderSql()} (called, not copied);
 *  - orders handled = DailyCloseNumbers::orderCounts() "placed": distinct orders with a part at the branch, placed in the window;
 *  - time to accept / prepare = DailyCloseNumbers::speed(): parts of the orders PLACED in the window, in OPENING-HOURS minutes (BranchSchedules), from when
 *    the part could be accepted (its creation, or the order's confirmation if that came later) to when it was accepted, and from accepted to ready;
 *    this class takes the median and the 90th percentile of those minutes, the daily close their average;
 *  - delivered on time = OwnerPackData::metrics() on_time: delivery parts that reached the customer in the window and had a promised day, the share that
 *    arrived on that day or earlier (the day in Qatar time);
 *  - cancelled = DailyCloseNumbers::orderCounts() "cancelled": parts cancelled in the window (real orders only);
 *  - failed deliveries = DailyCloseNumbers::for() failed_deliveries: deliveries whose (last) failure fell in the window, here also by reason;
 *  - claims = OwnerPackData::metrics() claims: return and damage claims OPENED in the window;
 *  - cash difference = DailyCloseNumbers::cash() / OwnerPackData cash_variance: the difference the cashiers found on hand-ins received in the window.
 */
class ScorecardBranchNumbers
{
    /** The most orders whose timestamps are read for the speed figures of one window (the newest ones); a window with more says so. */
    public const TIMING_CAP = 20000;

    /** How many orders are read for the speed figures of one window (a property so a test can shrink it). */
    protected int $timingCap = self::TIMING_CAP;

    public function __construct(private readonly BranchSchedules $schedules) {}

    /**
     * @param  list<string>  $branchIds
     * @return array<string, array<string, mixed>> branch id => raw cases (every branch asked about has an entry, zeros when nothing happened)
     */
    public function forPeriod(array $branchIds, ScorecardPeriod $period): array
    {
        $out = [];
        foreach ($branchIds as $id) {
            $out[$id] = self::blank();
        }
        if ($branchIds === []) {
            return $out;
        }
        [$start, $end] = $period->bounds();

        foreach ($this->placed($branchIds, $start, $end) as $id => $n) {
            $out[$id]['placed'] = $n;
        }
        foreach ($this->cancelled($branchIds, $start, $end) as $id => $byReason) {
            $out[$id]['cancelled'] = array_sum($byReason);
            $out[$id]['cancel_reasons'] = $byReason;
        }
        foreach ($this->timing($branchIds, $start, $end) as $id => $t) {
            $out[$id]['accept'] = $t['accept'];
            $out[$id]['prepare'] = $t['prepare'];
            $out[$id]['timing_capped'] = $t['capped'];
        }
        foreach ($this->deliveries($branchIds, $start, $end) as $id => $d) {
            $out[$id] = array_merge($out[$id], $d);
        }
        foreach ($this->pickups($branchIds, $start, $end) as $id => $p) {
            $out[$id] = array_merge($out[$id], $p);
        }
        foreach ($this->failures($branchIds, $start, $end) as $id => $byReason) {
            $out[$id]['failed'] = array_sum($byReason);
            $out[$id]['fail_reasons'] = $byReason;
        }
        foreach ($this->claims($branchIds, $start, $end) as $id => $byReason) {
            $out[$id]['claims'] = array_sum($byReason);
            $out[$id]['claim_reasons'] = $byReason;
        }
        foreach ($this->substitutions($branchIds, $start, $end) as $id => $n) {
            $out[$id]['substitutions'] = $n;
        }
        foreach ($this->handIns($branchIds, $start, $end) as $id => $h) {
            $out[$id] = array_merge($out[$id], $h);
        }

        return $out;
    }

    /**
     * What stands open NOW (not a figure of the window, so it has no "before"): cash differences nobody has explained, and cash that the people who
     * took it have held longer than the limit (the order clock's cash limit, the same one the exception inbox uses).
     *
     * @param  list<string>  $branchIds
     * @return array<string, array{open_variances: int, cash_held: int, cash_held_amount: string, cash_late: int, cash_late_amount: string, cash_oldest: ?string}>
     */
    public function standing(array $branchIds, CarbonImmutable $lateBefore): array
    {
        $out = [];
        foreach ($branchIds as $id) {
            $out[$id] = ['open_variances' => 0, 'cash_held' => 0, 'cash_held_amount' => '0.00', 'cash_late' => 0, 'cash_late_amount' => '0.00', 'cash_oldest' => null];
        }
        if ($branchIds === []) {
            return $out;
        }
        $open = DB::table('cash_handovers')->whereIn('branch_id', $branchIds)->where('variance_status', 'open')->selectRaw('branch_id, count(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');
        foreach ($open as $id => $n) {
            $out[$id]['open_variances'] = (int) $n;
        }
        // Cash is "held" by the same rule the exception inbox uses (AttentionFeed::cashItems): cash, collected, something to hand in, not handed in yet.
        $held = DB::table('cash_collections')->whereIn('branch_id', $branchIds)->where('tender', 'cash')->whereIn('status', ['collected', 'partial'])
            ->where('amount_collected', '>', 0)->whereNull('handed_in_at')
            ->selectRaw('branch_id, count(*) as held, coalesce(sum(amount_collected), 0)::numeric(14,2) as held_amount,
                count(*) filter (where collected_at <= ?) as late, coalesce(sum(amount_collected) filter (where collected_at <= ?), 0)::numeric(14,2) as late_amount, min(collected_at) as oldest',
                [$lateBefore, $lateBefore])->groupBy('branch_id')->get();
        foreach ($held as $r) {
            $out[$r->branch_id] = array_merge($out[$r->branch_id], ['cash_held' => (int) $r->held, 'cash_held_amount' => Money::of((string) $r->held_amount)->toDecimal(),
                'cash_late' => (int) $r->late, 'cash_late_amount' => Money::of((string) $r->late_amount)->toDecimal(), 'cash_oldest' => $r->oldest !== null ? (string) $r->oldest : null]);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function blank(): array
    {
        return [
            'placed' => 0, 'accept' => [], 'prepare' => [], 'timing_capped' => false,
            'delivered' => 0, 'promised' => 0, 'on_time' => 0, 'moved' => 0,
            'collected' => 0, 'collected_in_time' => 0, 'pass_expired' => 0,
            'cancelled' => 0, 'cancel_reasons' => [], 'failed' => 0, 'fail_reasons' => [], 'claims' => 0, 'claim_reasons' => [], 'substitutions' => 0,
            'handins' => 0, 'cash_net' => '0.00', 'cash_abs' => '0.00', 'variances' => 0,
        ];
    }

    /** @return array<string, int> branch id => distinct real orders placed in the window with a part at the branch */
    private function placed(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->whereIn('f.branch_id', $ids)
            ->whereBetween('o.placed_at', [$start, $end])->whereRaw(DailyCloseNumbers::realOrderSql())
            ->groupBy('f.branch_id')->selectRaw('f.branch_id, count(distinct f.order_id) as n')->pluck('n', 'branch_id')->map(fn (mixed $n) => (int) $n)->all();
    }

    /**
     * Parts cancelled in the window, by the reason written when they were cancelled.
     *
     * @return array<string, array<string, int>> branch id => reason code => parts
     */
    private function cancelled(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->leftJoin('order_status_history as h', fn ($j) => $j->on('h.order_fulfillment_id', '=', 'f.id')->on('h.order_id', '=', 'f.order_id')
                ->where('h.to_status', 'cancelled')->where('h.kind', 'fulfillment'))
            ->whereIn('f.branch_id', $ids)->whereBetween('f.cancelled_at', [$start, $end])->whereRaw(DailyCloseNumbers::realOrderSql())
            ->groupBy('f.branch_id', 'h.reason')->selectRaw('f.branch_id, h.reason as reason, count(distinct f.id) as n')->get();

        return $this->byReason($rows, ScorecardCauses::cancelReason(...));
    }

    /**
     * The minutes each order took to accept and to prepare, as lists (the percentiles are taken later, over the lists of all the branches asked about
     * or of one). Opening-hours minutes: a night the branch was shut is not slowness. The window's newest {@see self::TIMING_CAP} orders at most.
     *
     * @return array<string, array{accept: list<float>, prepare: list<float>, capped: bool}>
     */
    private function timing(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->whereIn('f.branch_id', $ids)
            ->whereBetween('o.placed_at', [$start, $end])->whereRaw(DailyCloseNumbers::realOrderSql())->whereNotNull('f.accepted_at')
            ->orderByDesc('o.placed_at')->orderBy('f.id')->limit($this->timingCap)
            ->get(['f.branch_id as branch_id', 'f.created_at as created_at', 'o.confirmed_at as confirmed_at', 'f.accepted_at as accepted_at', 'f.ready_at as ready_at']);
        $this->schedules->preload($ids);
        $capped = $rows->count() >= $this->timingCap;
        $out = [];
        foreach ($rows as $r) {
            $created = CarbonImmutable::parse($r->created_at);
            $confirmed = $r->confirmed_at !== null ? CarbonImmutable::parse($r->confirmed_at) : null;
            $accepted = CarbonImmutable::parse($r->accepted_at);
            $out[$r->branch_id] ??= ['accept' => [], 'prepare' => [], 'capped' => $capped];
            $out[$r->branch_id]['accept'][] = (float) $this->schedules->minutes($r->branch_id, $confirmed !== null && $confirmed > $created ? $confirmed : $created, $accepted)['counted'];
            if ($r->ready_at !== null) {
                $out[$r->branch_id]['prepare'][] = (float) $this->schedules->minutes($r->branch_id, $accepted, CarbonImmutable::parse($r->ready_at))['counted'];
            }
        }

        return $out;
    }

    /**
     * Delivery parts that reached the customer in the window: how many had a promised day, how many arrived on that day or earlier (a Qatar day), and
     * how many of those were moved to another slot on the way (the promise they are measured against is the LAST slot the customer was given).
     *
     * @return array<string, array{delivered: int, promised: int, on_time: int, moved: int}>
     */
    private function deliveries(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('order_fulfillments as f')->whereIn('f.branch_id', $ids)->where('f.type', 'delivery')->whereBetween('f.delivered_at', [$start, $end])
            ->groupBy('f.branch_id')
            ->selectRaw("f.branch_id, count(*) as delivered, count(*) filter (where f.slot_date is not null) as promised,
                count(*) filter (where f.slot_date is not null and (f.delivered_at at time zone ?)::date <= f.slot_date) as on_time,
                count(*) filter (where f.slot_date is not null and exists (
                    select 1 from deliveries d join delivery_status_history h on h.delivery_id = d.id where d.order_fulfillment_id = f.id and h.to_status = 'rescheduled')) as moved",
                [BusinessTime::zone()])->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->branch_id] = ['delivered' => (int) $r->delivered, 'promised' => (int) $r->promised, 'on_time' => (int) $r->on_time, 'moved' => (int) $r->moved];
        }

        return $out;
    }

    /**
     * Pickups: a pickup is IN TIME when the customer collected it within the life of the pass (platform.pickup.token_ttl_hours, 72 by default) after it
     * became ready. The cases are the pickups collected in the window plus those whose pass ran out in the window with nothing collected (so a branch
     * whose customers never come is not shown as perfect).
     *
     * @return array<string, array{collected: int, collected_in_time: int, pass_expired: int}>
     */
    private function pickups(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ttl = max(1, (int) config('platform.pickup.token_ttl_hours', 72));
        $rows = DB::table('order_fulfillments as f')->whereIn('f.branch_id', $ids)->where('f.type', 'pickup')
            ->where(fn ($q) => $q->where(fn ($c) => $c->whereBetween('f.picked_up_at', [$start, $end])->whereNotNull('f.ready_at'))
                ->orWhere(fn ($x) => $x->where('f.status', 'pickup_ready')->whereNull('f.picked_up_at')->whereBetween('f.ready_at', [$start->subHours($ttl), $end->subHours($ttl)])))
            ->groupBy('f.branch_id')
            ->selectRaw('f.branch_id, count(*) filter (where f.picked_up_at is not null) as collected,
                count(*) filter (where f.picked_up_at is not null and f.picked_up_at <= f.ready_at + make_interval(hours => ?::int)) as in_time,
                count(*) filter (where f.picked_up_at is null) as expired', [$ttl])->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->branch_id] = ['collected' => (int) $r->collected, 'collected_in_time' => (int) $r->in_time, 'pass_expired' => (int) $r->expired];
        }

        return $out;
    }

    /**
     * Deliveries that failed in the window, by the reason the driver or the manager gave.
     *
     * @return array<string, array<string, int>> branch id => reason code => deliveries
     */
    private function failures(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('deliveries as d')->whereIn('d.branch_id', $ids)->whereBetween('d.failed_at', [$start, $end])
            ->groupBy('d.branch_id', 'd.failure_reason')->selectRaw('d.branch_id, d.failure_reason as reason, count(*) as n')->get();

        return $this->byReason($rows, ScorecardCauses::failReason(...));
    }

    /** @return array<string, array<string, int>> branch id => claim reason => claims opened in the window */
    private function claims(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('return_requests')->whereIn('branch_id', $ids)->whereBetween('created_at', [$start, $end])
            ->groupBy('branch_id', 'reason_code')->selectRaw('branch_id, reason_code as reason, count(*) as n')->get();

        return $this->byReason($rows, fn (?string $code) => (string) ($code ?? 'other'));
    }

    /** @return array<string, int> branch id => parts that needed a replacement offered to the customer in the window */
    private function substitutions(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::table('order_substitution_requests')->whereIn('branch_id', $ids)->whereBetween('asked_at', [$start, $end])
            ->groupBy('branch_id')->selectRaw('branch_id, count(distinct order_fulfillment_id) as n')->pluck('n', 'branch_id')->map(fn (mixed $n) => (int) $n)->all();
    }

    /**
     * The cash counted in the window (every hand-in a cashier received): how many, the signed total of the differences found (short is negative), the total
     * ignoring the sign, and how many were not exactly right.
     *
     * @return array<string, array{handins: int, cash_net: string, cash_abs: string, variances: int}>
     */
    private function handIns(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('cash_handovers')->whereIn('branch_id', $ids)->whereBetween('created_at', [$start, $end])->groupBy('branch_id')
            ->selectRaw('branch_id, count(*) as n, coalesce(sum(variance), 0)::numeric(14,2) as net, coalesce(sum(abs(variance)), 0)::numeric(14,2) as absolute,
                count(*) filter (where variance <> 0) as variances')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->branch_id] = ['handins' => (int) $r->n, 'cash_net' => Money::of((string) $r->net)->toDecimal(), 'cash_abs' => Money::of((string) $r->absolute)->toDecimal(), 'variances' => (int) $r->variances];
        }

        return $out;
    }

    /**
     * @param  iterable<object{branch_id: string, reason: ?string, n: int|string}>  $rows
     * @param  callable(?string): string  $normalise
     * @return array<string, array<string, int>>
     */
    private function byReason(iterable $rows, callable $normalise): array
    {
        $out = [];
        foreach ($rows as $r) {
            $code = $normalise($r->reason);
            $out[$r->branch_id][$code] = ($out[$r->branch_id][$code] ?? 0) + (int) $r->n;
        }

        return $out;
    }
}
