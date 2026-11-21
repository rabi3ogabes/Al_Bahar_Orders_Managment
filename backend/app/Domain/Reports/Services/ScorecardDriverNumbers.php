<?php

namespace App\Domain\Reports\Services;

use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The raw cases behind a driver's scorecard for one window, restricted to the branches the viewer may see (a driver who works for two branches shows each
 * branch's manager only the work done for THAT branch, and only the cash taken there). A driver is a person who is a delivery driver at one of those
 * branches, or who was given a delivery at one of them in the window. Read-only; one grouped query per family; every query is bounded by the window and
 * by the branches. No customer is named anywhere: the order NUMBER is the only thing that identifies a delivery.
 *
 * How a delivery is judged (on time, failed) is the same as for a branch ({@see ScorecardBranchNumbers}): arrived on the promised day or earlier, in Qatar
 * time; a failed delivery is one whose (last) failure fell in the window. The trip is measured on the wall clock (a driver's road is not paused by the
 * branch's opening hours), from "out for delivery" to "delivered".
 */
final class ScorecardDriverNumbers
{
    /** The most rows of one kind the order list of a driver shows. */
    public const LEDGER_CAP = 100;

    /**
     * @param  list<string>  $branchIds
     * @return array<string, array<string, mixed>> driver id => raw cases (only drivers with something in the window, or who are drivers of these branches)
     */
    public function forPeriod(array $branchIds, ScorecardPeriod $period, ?string $onlyDriver = null): array
    {
        $out = [];
        if ($branchIds === []) {
            return $out;
        }
        [$start, $end] = $period->bounds();

        foreach ($this->employed($branchIds, $onlyDriver) as $id) {
            $out[$id] = self::blank();
        }
        foreach ($this->delivered($branchIds, $start, $end, $onlyDriver) as $id => $d) {
            $out[$id] = array_merge($out[$id] ?? self::blank(), $d);
        }
        foreach ($this->failed($branchIds, $start, $end, $onlyDriver) as $id => $byReason) {
            $out[$id] = array_merge($out[$id] ?? self::blank(), ['failed' => array_sum($byReason), 'fail_reasons' => $byReason]);
        }
        foreach ($this->declines($branchIds, $start, $end, $onlyDriver) as $id => $d) {
            $out[$id] = array_merge($out[$id] ?? self::blank(), $d);
        }
        // Cash is only added for people who are drivers by the rules above: a cashier who takes cash at the counter is not a driver.
        foreach ($this->handIns($branchIds, $start, $end, array_keys($out)) as $id => $h) {
            $out[$id] = array_merge($out[$id], $h);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function blank(): array
    {
        return [
            'delivered' => 0, 'promised' => 0, 'on_time' => 0, 'trips' => 0, 'trip_median' => null, 'trip_p90' => null, 'trip_avg' => null,
            'failed' => 0, 'fail_reasons' => [], 'declined' => 0, 'timeouts' => 0, 'off_duty' => 0,
            'handins' => 0, 'cash_net' => '0.00', 'cash_abs' => '0.00', 'variances' => 0,
        ];
    }

    /**
     * Cash the drivers hold NOW (not a figure of the window): how much, in how many collections, and since when the oldest one.
     *
     * @param  list<string>  $branchIds
     * @param  list<string>  $driverIds
     * @return array<string, array{held: int, amount: string, oldest: ?string}> driver id => cash in hand
     */
    public function standing(array $branchIds, array $driverIds): array
    {
        if ($branchIds === [] || $driverIds === []) {
            return [];
        }
        $rows = DB::table('cash_collections')->whereIn('branch_id', $branchIds)->whereIn('collected_by', $driverIds)->where('tender', 'cash')->whereIn('status', ['collected', 'partial'])
            ->where('amount_collected', '>', 0)->whereNull('handed_in_at')->groupBy('collected_by')
            ->selectRaw('collected_by, count(*) as n, coalesce(sum(amount_collected), 0)::numeric(14,2) as amount, min(collected_at) as oldest')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->collected_by] = ['held' => (int) $r->n, 'amount' => Money::of((string) $r->amount)->toDecimal(), 'oldest' => $r->oldest !== null ? (string) $r->oldest : null];
        }

        return $out;
    }

    /**
     * The orders behind a driver's numbers, newest first: what was delivered (on time or late), what failed (and why), the jobs handed back or not
     * answered, the cash still held, and the hand-ins counted. Each family is capped at {@see self::LEDGER_CAP}; `truncated` says whether any was.
     *
     * @param  list<string>  $branchIds
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function ledger(array $branchIds, string $driverId, ScorecardPeriod $period): array
    {
        if ($branchIds === []) {
            return ['rows' => [], 'truncated' => false];
        }
        [$start, $end] = $period->bounds();
        $zone = BusinessTime::zone();
        $cap = self::LEDGER_CAP;
        $rows = [];
        $truncated = false;
        $take = function (iterable $found, callable $shape) use (&$rows, &$truncated, $cap): void {
            $n = 0;
            foreach ($found as $r) {
                $n++;
                $rows[] = $shape($r);
            }
            $truncated = $truncated || $n >= $cap;
        };

        $take(DB::table('deliveries as d')->join('order_fulfillments as f', 'f.id', '=', 'd.order_fulfillment_id')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->join('branches as b', 'b.id', '=', 'd.branch_id')->whereIn('d.branch_id', $branchIds)->where('d.driver_id', $driverId)->whereBetween('d.delivered_at', [$start, $end])
            ->orderByDesc('d.delivered_at')->orderBy('d.id')->limit($cap)
            ->selectRaw('o.number, b.code as branch, d.delivered_at as at, f.slot_date, (d.delivered_at at time zone ?)::date as delivered_day,
                case when d.out_for_delivery_at is not null and d.out_for_delivery_at <= d.delivered_at then round(extract(epoch from (d.delivered_at - d.out_for_delivery_at)) / 60) end as trip_minutes', [$zone])->get(),
            function ($r) {
                $late = $r->slot_date === null ? null : (int) CarbonImmutable::parse((string) $r->slot_date)->diffInDays(CarbonImmutable::parse((string) $r->delivered_day), false);

                return ['kind' => 'delivered', 'order' => (string) $r->number, 'branch' => (string) $r->branch, 'at' => (string) $r->at, 'result' => $late === null ? 'no_promise' : ($late <= 0 ? 'on_time' : 'late'),
                    'days_late' => $late !== null && $late > 0 ? $late : null, 'minutes' => $r->trip_minutes !== null ? (int) $r->trip_minutes : null, 'reason' => null, 'amount' => null];
            });

        $take(DB::table('deliveries as d')->join('order_fulfillments as f', 'f.id', '=', 'd.order_fulfillment_id')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->join('branches as b', 'b.id', '=', 'd.branch_id')->whereIn('d.branch_id', $branchIds)->where('d.driver_id', $driverId)->whereBetween('d.failed_at', [$start, $end])
            ->orderByDesc('d.failed_at')->orderBy('d.id')->limit($cap)->selectRaw('o.number, b.code as branch, d.failed_at as at, d.failure_reason as reason')->get(),
            fn ($r) => ['kind' => 'failed', 'order' => (string) $r->number, 'branch' => (string) $r->branch, 'at' => (string) $r->at, 'result' => 'failed', 'days_late' => null, 'minutes' => null,
                'reason' => ScorecardCauses::failReason($r->reason), 'amount' => null]);

        $take(DB::table('delivery_declines as dd')->join('deliveries as d', 'd.id', '=', 'dd.delivery_id')->join('order_fulfillments as f', 'f.id', '=', 'd.order_fulfillment_id')
            ->join('orders as o', 'o.id', '=', 'f.order_id')->join('branches as b', 'b.id', '=', 'd.branch_id')->whereIn('d.branch_id', $branchIds)->where('dd.driver_id', $driverId)
            ->whereBetween('dd.created_at', [$start, $end])->orderByDesc('dd.created_at')->orderBy('dd.id')->limit($cap)->selectRaw('o.number, b.code as branch, dd.created_at as at, dd.reason')->get(),
            fn ($r) => ['kind' => 'declined', 'order' => (string) $r->number, 'branch' => (string) $r->branch, 'at' => (string) $r->at,
                'result' => in_array($r->reason, [DeliveryDecline::TIMEOUT, DeliveryDecline::OFF_DUTY], true) ? (string) $r->reason : 'declined',
                'days_late' => null, 'minutes' => null, 'reason' => in_array($r->reason, ScorecardCauses::DECLINE_REASONS, true) ? (string) $r->reason : null, 'amount' => null]);

        $take(DB::table('cash_collections as c')->leftJoin('order_fulfillments as f', 'f.id', '=', 'c.order_fulfillment_id')->leftJoin('orders as o', 'o.id', '=', 'f.order_id')
            ->join('branches as b', 'b.id', '=', 'c.branch_id')->whereIn('c.branch_id', $branchIds)->where('c.collected_by', $driverId)->where('c.tender', 'cash')->whereIn('c.status', ['collected', 'partial'])
            ->where('c.amount_collected', '>', 0)->whereNull('c.handed_in_at')->orderBy('c.collected_at')->orderBy('c.id')->limit($cap)
            ->selectRaw('o.number, b.code as branch, c.collected_at as at, c.amount_collected as amount')->get(),
            fn ($r) => ['kind' => 'cash_held', 'order' => $r->number !== null ? (string) $r->number : null, 'branch' => (string) $r->branch, 'at' => (string) $r->at, 'result' => 'held', 'days_late' => null,
                'minutes' => null, 'reason' => null, 'amount' => Money::of((string) $r->amount)->toDecimal()]);

        $take(DB::table('cash_handovers as h')->join('branches as b', 'b.id', '=', 'h.branch_id')->whereIn('h.branch_id', $branchIds)->where('h.collector_id', $driverId)
            ->whereBetween('h.created_at', [$start, $end])->orderByDesc('h.created_at')->orderBy('h.id')->limit($cap)->selectRaw('h.number, b.code as branch, h.created_at as at, h.variance')->get(),
            fn ($r) => ['kind' => 'handin', 'order' => null, 'ref' => (string) $r->number, 'branch' => (string) $r->branch, 'at' => (string) $r->at, 'result' => Money::of((string) $r->variance)->isZero() ? 'balanced' : 'difference',
                'days_late' => null, 'minutes' => null, 'reason' => null, 'amount' => Money::of((string) $r->variance)->toDecimal()]);

        usort($rows, fn (array $a, array $b) => [$b['at'], (string) $b['order']] <=> [$a['at'], (string) $a['order']]);

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /** @return list<string> ids of the active delivery drivers of these branches */
    private function employed(array $branchIds, ?string $onlyDriver): array
    {
        return DB::table('branch_employees')->whereIn('branch_id', $branchIds)->where('is_active', true)->where('is_delivery_driver', true)
            ->when($onlyDriver !== null, fn ($q) => $q->where('user_id', $onlyDriver))->distinct()->orderBy('user_id')->pluck('user_id')->map(fn (mixed $id) => (string) $id)->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function delivered(array $ids, CarbonImmutable $start, CarbonImmutable $end, ?string $onlyDriver): array
    {
        $trip = 'd.out_for_delivery_at is not null and d.out_for_delivery_at <= d.delivered_at';
        $minutes = 'extract(epoch from (d.delivered_at - d.out_for_delivery_at)) / 60';
        $rows = DB::table('deliveries as d')->join('order_fulfillments as f', 'f.id', '=', 'd.order_fulfillment_id')->whereIn('d.branch_id', $ids)->whereNotNull('d.driver_id')
            ->whereBetween('d.delivered_at', [$start, $end])->when($onlyDriver !== null, fn ($q) => $q->where('d.driver_id', $onlyDriver))->groupBy('d.driver_id')
            ->selectRaw("d.driver_id, count(*) as delivered, count(*) filter (where f.slot_date is not null) as promised,
                count(*) filter (where f.slot_date is not null and (d.delivered_at at time zone ?)::date <= f.slot_date) as on_time,
                count(*) filter (where {$trip}) as trips,
                percentile_cont(0.5) within group (order by {$minutes}) filter (where {$trip}) as trip_median,
                percentile_cont(0.9) within group (order by {$minutes}) filter (where {$trip}) as trip_p90,
                avg({$minutes}) filter (where {$trip}) as trip_avg", [BusinessTime::zone()])->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->driver_id] = ['delivered' => (int) $r->delivered, 'promised' => (int) $r->promised, 'on_time' => (int) $r->on_time, 'trips' => (int) $r->trips,
                'trip_median' => $r->trip_median !== null ? (float) $r->trip_median : null, 'trip_p90' => $r->trip_p90 !== null ? (float) $r->trip_p90 : null,
                'trip_avg' => $r->trip_avg !== null ? (float) $r->trip_avg : null];
        }

        return $out;
    }

    /** @return array<string, array<string, int>> driver id => failure reason => deliveries */
    private function failed(array $ids, CarbonImmutable $start, CarbonImmutable $end, ?string $onlyDriver): array
    {
        $rows = DB::table('deliveries as d')->whereIn('d.branch_id', $ids)->whereNotNull('d.driver_id')->whereBetween('d.failed_at', [$start, $end])
            ->when($onlyDriver !== null, fn ($q) => $q->where('d.driver_id', $onlyDriver))->groupBy('d.driver_id', 'd.failure_reason')
            ->selectRaw('d.driver_id, d.failure_reason as reason, count(*) as n')->get();
        $out = [];
        foreach ($rows as $r) {
            $code = ScorecardCauses::failReason($r->reason);
            $out[(string) $r->driver_id][$code] = ($out[(string) $r->driver_id][$code] ?? 0) + (int) $r->n;
        }

        return $out;
    }

    /** @return array<string, array{declined: int, timeouts: int, off_duty: int}> */
    private function declines(array $ids, CarbonImmutable $start, CarbonImmutable $end, ?string $onlyDriver): array
    {
        $rows = DB::table('delivery_declines as dd')->join('deliveries as d', 'd.id', '=', 'dd.delivery_id')->whereIn('d.branch_id', $ids)->whereBetween('dd.created_at', [$start, $end])
            ->when($onlyDriver !== null, fn ($q) => $q->where('dd.driver_id', $onlyDriver))->groupBy('dd.driver_id')
            ->selectRaw("dd.driver_id, count(*) filter (where dd.reason not in ('timeout', 'off_duty')) as declined, count(*) filter (where dd.reason = 'timeout') as timeouts,
                count(*) filter (where dd.reason = 'off_duty') as off_duty")->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->driver_id] = ['declined' => (int) $r->declined, 'timeouts' => (int) $r->timeouts, 'off_duty' => (int) $r->off_duty];
        }

        return $out;
    }

    /**
     * The hand-ins of the window by the person who handed the cash in, for these drivers only.
     *
     * @param  list<string>  $driverIds
     * @return array<string, array{handins: int, cash_net: string, cash_abs: string, variances: int}>
     */
    private function handIns(array $ids, CarbonImmutable $start, CarbonImmutable $end, array $driverIds): array
    {
        if ($driverIds === []) {
            return [];
        }
        $rows = DB::table('cash_handovers')->whereIn('branch_id', $ids)->whereIn('collector_id', $driverIds)->whereBetween('created_at', [$start, $end])->groupBy('collector_id')
            ->selectRaw('collector_id, count(*) as n, coalesce(sum(variance), 0)::numeric(14,2) as net, coalesce(sum(abs(variance)), 0)::numeric(14,2) as absolute,
                count(*) filter (where variance <> 0) as variances')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->collector_id] = ['handins' => (int) $r->n, 'cash_net' => Money::of((string) $r->net)->toDecimal(), 'cash_abs' => Money::of((string) $r->absolute)->toDecimal(), 'variances' => (int) $r->variances];
        }

        return $out;
    }
}
