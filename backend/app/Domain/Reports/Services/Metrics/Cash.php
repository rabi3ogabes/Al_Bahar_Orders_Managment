<?php

namespace App\Domain\Reports\Services\Metrics;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE definition of the cash figures every screen shows, so a difference or an amount held reads the same on the daily close, the weekly owner pack, the
 * scorecard and the drivers' page. Cash is cash only: a card terminal is not cash. Read-only, grouped in the database, bounded by the window or by the
 * (small, ever-shrinking) set of collections nobody has handed in.
 *
 *  - CASH DIFFERENCE: what the cashier found when counting a hand-in, counted on the day the hand-in was counted. Signed (short is negative) as a net, and also
 *    ignoring the sign, with how many hand-ins were not exact.
 *  - OPEN DIFFERENCES: hand-ins whose difference nobody has explained yet, of ANY date (how things stand now).
 *  - CASH HELD: cash collected (or part-collected), not handed in yet, of ANY date (how things stand now), with how much of it is older than a limit.
 */
final class Cash
{
    /** The cash that has been taken (in full or in part): the base of every cash question. A card terminal is not cash. */
    public static function takenQuery(): Builder
    {
        return DB::table('cash_collections')->where('tender', 'cash')->whereIn('status', ['collected', 'partial']);
    }

    /**
     * The cash held: taken, something to hand in, not handed in yet. The scorecard, the daily close, the weekly owner pack and the "Needs attention" inbox all
     * hold it by this one rule.
     */
    public static function heldQuery(): Builder
    {
        return self::takenQuery()->where('amount_collected', '>', 0)->whereNull('handed_in_at');
    }

    /**
     * The hand-ins counted in the window: how many, the net difference, the difference ignoring the sign and how many were not exact.
     *
     * @param  list<string>  $ids  the branches asked about
     * @param  'branch_id'|'collector_id'  $by  per branch, or per person who handed the cash in
     * @param  list<string>|null  $only  with `collector_id`: only these people
     * @return array<string, array{handins: int, cash_net: string, cash_abs: string, variances: int}>
     */
    public static function handIns(array $ids, CarbonInterface $start, CarbonInterface $end, string $by = 'branch_id', ?array $only = null): array
    {
        $by = $by === 'collector_id' ? 'collector_id' : 'branch_id';
        $rows = DB::table('cash_handovers')->whereIn('branch_id', $ids)->whereBetween('created_at', [$start, $end])->when($only !== null, fn ($q) => $q->whereIn($by, $only))
            ->groupBy($by)->selectRaw("{$by} as who, count(*) as n, coalesce(sum(variance), 0)::numeric(14,2) as net, coalesce(sum(abs(variance)), 0)::numeric(14,2) as absolute,
                count(*) filter (where variance <> 0) as variances")->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->who] = ['handins' => (int) $r->n, 'cash_net' => Money::of((string) $r->net)->toDecimal(), 'cash_abs' => Money::of((string) $r->absolute)->toDecimal(), 'variances' => (int) $r->variances];
        }

        return $out;
    }

    /**
     * Hand-ins with a difference nobody has explained, of any date. Null as the list of branches means every branch.
     *
     * @param  list<string>|null  $ids
     * @return array<string, int> branch id => hand-ins
     */
    public static function openVariances(?array $ids): array
    {
        return DB::table('cash_handovers')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->where('variance_status', 'open')
            ->groupBy('branch_id')->selectRaw('branch_id, count(*) as n')->pluck('n', 'branch_id')->map(fn (mixed $n) => (int) $n)->all();
    }

    /**
     * The cash held NOW: collected, something to hand in, not handed in yet. `late` is how much of it was collected at or before `$lateBefore`.
     *
     * @param  list<string>  $ids  the branches asked about
     * @param  'branch_id'|'collected_by'  $by  per branch, or per person holding it
     * @param  list<string>|null  $only  with `collected_by`: only these people
     * @return array<string, array{held: int, amount: string, late: int, late_amount: string, oldest: ?string}>
     */
    public static function held(array $ids, ?CarbonInterface $lateBefore = null, string $by = 'branch_id', ?array $only = null): array
    {
        $by = $by === 'collected_by' ? 'collected_by' : 'branch_id';
        $lateSql = '0 as late, 0 as late_amount';
        $bindings = [];
        if ($lateBefore !== null) {
            $lateSql = 'count(*) filter (where collected_at <= ?) as late, coalesce(sum(amount_collected) filter (where collected_at <= ?), 0)::numeric(14,2) as late_amount';
            $bindings = [$lateBefore, $lateBefore];
        }
        $rows = self::heldQuery()->whereIn('branch_id', $ids)->when($only !== null, fn ($q) => $q->whereIn($by, $only))->groupBy($by)
            ->selectRaw("{$by} as who, count(*) as held, coalesce(sum(amount_collected), 0)::numeric(14,2) as amount, {$lateSql}, min(collected_at) as oldest", $bindings)->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->who] = ['held' => (int) $r->held, 'amount' => Money::of((string) $r->amount)->toDecimal(), 'late' => (int) $r->late, 'late_amount' => Money::of((string) $r->late_amount)->toDecimal(),
                'oldest' => $r->oldest !== null ? (string) $r->oldest : null];
        }

        return $out;
    }
}
