<?php

namespace App\Domain\Reports\Services\Metrics;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * THE definition of a return or damage claim, for the weekly owner pack, the scorecard and the daily close. Two different things, with two different names:
 *
 *  - CLAIMS OPENED: claims a customer opened in the window (`created_at`), by the reason they gave. The pack's "claims per 100 delivered" and the scorecard's
 *    "returns and claims" count these.
 *  - GOODS RETURNED: claims whose goods came back to the branch in the window (`received_at`). The daily close's "returned by customers" counts these. A claim
 *    opened on one day and received on another is one of each, on its own day.
 */
final class Claims
{
    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, int>> branch id => reason code ('' when none) => claims opened in the window
     */
    public static function opened(array $ids, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = DB::table('return_requests')->whereIn('branch_id', $ids)->whereBetween('created_at', [$start, $end])->groupBy('branch_id', 'reason_code')
            ->selectRaw('branch_id, reason_code as reason, count(*) as n')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->branch_id][(string) $r->reason] = (int) $r->n;
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int> branch id => claims whose goods came back in the window
     */
    public static function received(array $ids, CarbonInterface $start, CarbonInterface $end): array
    {
        return DB::table('return_requests')->whereIn('branch_id', $ids)->whereBetween('received_at', [$start, $end])->groupBy('branch_id')
            ->selectRaw('branch_id, count(*) as n')->pluck('n', 'branch_id')->map(fn (mixed $n) => (int) $n)->all();
    }
}
