<?php

namespace App\Domain\Reports\Services\Metrics;

use App\Domain\Operations\Services\BranchSchedules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * THE definition of how fast a branch worked, for the daily close (the average of a day) and the scorecard (the median and the slow end of a period): one
 * list of readings, so the two can only differ in what they do with it.
 *
 *  - The READINGS are the parts of the real orders placed in the window that were accepted: the minutes from when the part COULD be accepted (its creation, or
 *    the order's confirmation when that came later, i.e. when an online payment landed) to when it was accepted; and, for the parts that are ready, from
 *    accepted to ready.
 *  - The CLOCK is the opening-hours clock of the branch (the order clock's own rule, {@see BranchSchedules}): a night the branch was shut is not slowness, and
 *    a branch with no hours counts wall-clock minutes.
 *  - The SAMPLE is the newest {@see self::SAMPLE} parts of the window (a window with more says so): minutes need opening hours, which only PHP can count, so they
 *    are the only rows read one by one.
 */
final class Speed
{
    public const SAMPLE = 20000;

    public function __construct(private readonly BranchSchedules $schedules) {}

    /**
     * @param  list<string>  $ids
     * @return array<string, array{accept: list<float>, prepare: list<float>, capped: bool}> branch id => readings (only branches that have any)
     */
    public function minutes(array $ids, CarbonInterface $start, CarbonInterface $end, int $cap = self::SAMPLE): array
    {
        $rows = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->whereIn('f.branch_id', $ids)
            ->whereBetween('o.placed_at', [$start, $end])->whereRaw(Orders::realSql())->whereNotNull('f.accepted_at')
            ->orderByDesc('o.placed_at')->orderBy('f.id')->limit($cap)
            ->get(['f.branch_id as branch_id', 'f.created_at as created_at', 'o.confirmed_at as confirmed_at', 'f.accepted_at as accepted_at', 'f.ready_at as ready_at']);
        $this->schedules->preload($ids);
        $capped = $rows->count() >= $cap;
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
     * The plain average of some readings, in whole minutes (the daily close's figure); null when nothing was measured: a day with no orders has no "average".
     *
     * @param  list<float>  $readings
     */
    public static function average(array $readings): ?int
    {
        return $readings === [] ? null : (int) round(array_sum($readings) / count($readings));
    }
}
