<?php

namespace App\Domain\Reports\Services\Metrics;

use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * THE definition of keeping a delivery promise and of a delivery that failed, for the weekly owner pack, the scorecard (branches and drivers) and anything
 * that follows. Read-only, grouped in the database, bounded by the window.
 *
 *  - ON TIME: a delivery that reached the customer in the window and had a promised day (`slot_date`) counts, and it is on time when it arrived on that day or
 *    earlier, the day taken in Qatar time (a delivery at 00:30 on the 15th is already the 15th, though it is the 14th in UTC). Counted by day, not by hour. A
 *    delivery with no promised day is delivered but is neither on time nor late. The promise is the LAST slot the customer was given (a reschedule replaces it);
 *    {@see self::promises()} says how many of them were moved first.
 *  - FAILED: a delivery whose (last) failure fell in the window, counted once whatever the number of attempts.
 */
final class Deliveries
{
    /**
     * "Arrived on the promised day or earlier (the Qatar day)" as SQL, with the binding it needs: for a delivery with a promised day only, so a
     * `count(*) filter (where ...)` over it counts the on-time deliveries and `slot is not null` counts the promised ones.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function onTimeSql(string $delivered = 'f.delivered_at', string $slot = 'f.slot_date'): array
    {
        return ["({$slot} is not null and ({$delivered} at time zone ?)::date <= {$slot})", [BusinessTime::zone()]];
    }

    /**
     * The delivery parts that reached the customer in the window: how many, how many had a promised day, how many arrived on time, and (when asked) how many of
     * the promised ones were moved to another slot on the way.
     *
     * @param  list<string>  $ids
     * @return array<string, array{delivered: int, promised: int, on_time: int, moved: int}>
     */
    public static function promises(array $ids, CarbonInterface $start, CarbonInterface $end, bool $withMoved = false): array
    {
        [$onTime, $bindings] = self::onTimeSql();
        $moved = $withMoved
            ? ", count(*) filter (where f.slot_date is not null and exists (
                    select 1 from deliveries d join delivery_status_history h on h.delivery_id = d.id where d.order_fulfillment_id = f.id and h.to_status = 'rescheduled')) as moved"
            : ', 0 as moved';
        $rows = DB::table('order_fulfillments as f')->whereIn('f.branch_id', $ids)->where('f.type', 'delivery')->whereBetween('f.delivered_at', [$start, $end])->groupBy('f.branch_id')
            ->selectRaw("f.branch_id, count(*) as delivered, count(*) filter (where f.slot_date is not null) as promised, count(*) filter (where {$onTime}) as on_time{$moved}", $bindings)->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->branch_id] = ['delivered' => (int) $r->delivered, 'promised' => (int) $r->promised, 'on_time' => (int) $r->on_time, 'moved' => (int) $r->moved];
        }

        return $out;
    }

    /**
     * Deliveries that failed in the window, by the reason the driver or the manager gave, grouped by branch or by driver (a delivery nobody was given has no driver
     * and is left out of the drivers' figures).
     *
     * @param  list<string>  $ids  the branches asked about
     * @param  'branch_id'|'driver_id'  $by
     * @return array<string, array<string, int>> branch (or driver) id => reason ('' when none was given) => deliveries
     */
    public static function failures(array $ids, CarbonInterface $start, CarbonInterface $end, string $by = 'branch_id', ?string $onlyDriver = null): array
    {
        $by = $by === 'driver_id' ? 'driver_id' : 'branch_id';
        $rows = DB::table('deliveries as d')->whereIn('d.branch_id', $ids)->whereBetween('d.failed_at', [$start, $end])
            ->when($by === 'driver_id', fn ($q) => $q->whereNotNull('d.driver_id'))->when($onlyDriver !== null, fn ($q) => $q->where('d.driver_id', $onlyDriver))
            ->groupBy('d.'.$by, 'd.failure_reason')->selectRaw("d.{$by} as who, d.failure_reason as reason, count(*) as n")->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->who][(string) $r->reason] = (int) $r->n;
        }

        return $out;
    }
}
