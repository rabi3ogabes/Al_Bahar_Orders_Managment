<?php

namespace App\Domain\Reports\Services;

use App\Domain\Operations\Services\BranchSchedules;
use App\Domain\Reports\Services\Metrics\Cash;
use App\Domain\Reports\Services\Metrics\Claims;
use App\Domain\Reports\Services\Metrics\Deliveries;
use App\Domain\Reports\Services\Metrics\Orders;
use App\Domain\Reports\Services\Metrics\Speed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The raw cases behind a branch's scorecard for one window: counts, the list of minutes each order took, the reasons things went wrong. No rounding, no
 * minimum-sample rule and no comparison here: that is {@see ScorecardService}'s job. Read-only. One grouped query per family of measures, every one
 * bounded by the window and by the branches asked about (and started from an indexed date), and aggregating in the database; the only rows that come
 * back one by one are the order timestamps whose minutes can only be counted in PHP (see {@see self::timing()}).
 *
 * WHICH ORDERS COUNT, and how a thing is measured, is decided ONCE, in the shared definitions the daily close, the weekly owner pack and the dashboard use
 * too ({@see Orders}, {@see Deliveries}, {@see Cash}, {@see Claims}, {@see Speed} in App\Domain\Reports\Services\Metrics, and {@see SalesBasis}), so a number here
 * is never different from the same number there. This class only asks them about a window and keeps what is particular to the scorecard: the reasons, the
 * pickups that were collected in time, and the replacements offered.
 */
class ScorecardBranchNumbers
{
    /** The most orders whose timestamps are read for the speed figures of one window (the newest ones); a window with more says so. */
    public const TIMING_CAP = 20000;

    /** How many orders are read for the speed figures of one window (a property so a test can shrink it). */
    protected int $timingCap = self::TIMING_CAP;

    private readonly Speed $speed;

    public function __construct(BranchSchedules $schedules)
    {
        $this->speed = new Speed($schedules);
    }

    /**
     * `branches`: branch id => raw cases (every branch asked about has an entry, zeros when nothing happened). `placed_together`: the distinct real orders placed
     * with a part at ANY of these branches, for the line of "all the branches together", where an order shared by two of them is one order (adding the branches'
     * own counts would count it twice).
     *
     * @param  list<string>  $branchIds
     * @return array{branches: array<string, array<string, mixed>>, placed_together: int}
     */
    public function forPeriod(array $branchIds, ScorecardPeriod $period): array
    {
        $out = [];
        foreach ($branchIds as $id) {
            $out[$id] = self::blank();
        }
        if ($branchIds === []) {
            return ['branches' => $out, 'placed_together' => 0];
        }
        [$start, $end] = $period->bounds();

        $placed = Orders::placed($branchIds, $start, $end);
        foreach ($placed['branches'] as $id => $n) {
            $out[$id]['placed'] = $n;
        }
        foreach ($this->cancelled($branchIds, $start, $end) as $id => $byReason) {
            $out[$id]['cancelled'] = array_sum($byReason);
            $out[$id]['cancel_reasons'] = $byReason;
        }
        foreach ($this->speed->minutes($branchIds, $start, $end, $this->timingCap) as $id => $t) {
            $out[$id]['accept'] = $t['accept'];
            $out[$id]['prepare'] = $t['prepare'];
            $out[$id]['timing_capped'] = $t['capped'];
        }
        foreach (Orders::reached($branchIds, $start, $end) as $id => $n) {
            $out[$id]['reached'] = $n;
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

        return ['branches' => $out, 'placed_together' => $placed['together']];
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
        foreach (Cash::openVariances($branchIds) as $id => $n) {
            $out[$id]['open_variances'] = $n;
        }
        // Cash is "held" by the same rule the exception inbox uses (AttentionFeed::cashItems): cash, collected, something to hand in, not handed in yet.
        foreach (Cash::held($branchIds, $lateBefore) as $id => $h) {
            $out[$id] = array_merge($out[$id], ['cash_held' => $h['held'], 'cash_held_amount' => $h['amount'], 'cash_late' => $h['late'], 'cash_late_amount' => $h['late_amount'], 'cash_oldest' => $h['oldest']]);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function blank(): array
    {
        return [
            'placed' => 0, 'accept' => [], 'prepare' => [], 'timing_capped' => false,
            'reached' => 0, 'delivered' => 0, 'promised' => 0, 'on_time' => 0, 'moved' => 0,
            'collected' => 0, 'collected_in_time' => 0, 'pass_expired' => 0,
            'cancelled' => 0, 'cancel_reasons' => [], 'failed' => 0, 'fail_reasons' => [], 'claims' => 0, 'claim_reasons' => [], 'substitutions' => 0,
            'handins' => 0, 'cash_net' => '0.00', 'cash_abs' => '0.00', 'variances' => 0,
        ];
    }

    /**
     * Parts cancelled in the window, by the reason written when they were cancelled.
     *
     * @return array<string, array<string, int>> branch id => reason code => parts
     */
    private function cancelled(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->byReason(Orders::cancelledByReason($ids, $start, $end), ScorecardCauses::cancelReason(...));
    }

    /**
     * Delivery parts that reached the customer in the window: how many had a promised day, how many arrived on that day or earlier (a Qatar day), and
     * how many of those were moved to another slot on the way (the promise they are measured against is the LAST slot the customer was given).
     *
     * @return array<string, array{delivered: int, promised: int, on_time: int, moved: int}>
     */
    private function deliveries(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Deliveries::promises($ids, $start, $end, withMoved: true);
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
        return $this->byReason(Deliveries::failures($ids, $start, $end), ScorecardCauses::failReason(...));
    }

    /** @return array<string, array<string, int>> branch id => claim reason => claims opened in the window */
    private function claims(array $ids, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->byReason(Claims::opened($ids, $start, $end), fn (?string $code) => $code === null || $code === '' ? 'other' : $code);
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
        return Cash::handIns($ids, $start, $end);
    }

    /**
     * Folds the reasons a definition returned into the scorecard's known reason codes ("Other" for anything else).
     *
     * @param  array<string, array<string, int>>  $found  branch id => reason as written => count
     * @param  callable(?string): string  $normalise
     * @return array<string, array<string, int>>
     */
    private function byReason(array $found, callable $normalise): array
    {
        $out = [];
        foreach ($found as $branch => $reasons) {
            foreach ($reasons as $reason => $n) {
                $code = $normalise($reason === '' ? null : (string) $reason);
                $out[$branch][$code] = ($out[$branch][$code] ?? 0) + $n;
            }
        }

        return $out;
    }
}
