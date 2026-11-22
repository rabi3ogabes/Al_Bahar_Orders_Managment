<?php

namespace App\Domain\Reports\Services\Metrics;

use App\Domain\Operations\Services\LiveBoard;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE definition of an order and of what happens to its parts, for every screen that counts them (daily close, weekly owner pack, scorecard, live board,
 * dashboard). Read-only; every query starts from an indexed date of the window and groups in the database, so a day, a week and a year cost what they hold.
 * An order is "a part at a branch" in the sense that an order shared by two branches is one order, with a part at each.
 *
 *  - REAL ORDER: not waiting for its online payment, and not an online order that expired unpaid ({@see self::realSql()}). The scorecard's "orders handled",
 *    the daily close's "orders placed" and every count of what happened to an order start from here.
 *  - PLACED: distinct real orders placed in the window with a part at the branch. Added up over several branches an order shared between them would be counted
 *    once per branch, so a figure for "all branches together" asks {@see self::placedTogether()} instead of adding.
 *  - CANCELLED: parts cancelled in the window (a part, not an order), real orders only.
 *  - REACHED THE CUSTOMER: parts delivered, or collected at the counter, in the window.
 *  - OPEN / NEVER ACCEPTED: of the orders placed in the window, the parts that are not finished or cancelled NOW, and the ones nobody has accepted NOW.
 */
final class Orders
{
    /** Part states that are still work for somebody (not finished, not cancelled, not returned): the live board's list, so the board and the daily close never disagree. */
    public const OPEN_STATUSES = LiveBoard::OPEN;

    /** A branch's own work waiting to be done: what the dashboard calls "to prepare". */
    public const TO_PREPARE = ['pending', 'accepted', 'preparing'];

    /** On the road to the customer. */
    public const ON_THE_ROAD = ['dispatched', 'out_for_delivery'];

    /**
     * An order that really happened: not waiting for its online payment, and not an online order that expired unpaid (cancelled without ever being
     * confirmed).
     */
    public static function realSql(string $o = 'o'): string
    {
        return "({$o}.status <> 'pending_payment' and not ({$o}.status = 'cancelled' and {$o}.confirmed_at is null and {$o}.payment_method = 'sadad'))";
    }

    /**
     * Distinct real orders placed in the window with a part at each branch, and with a part at ANY of them (`together`, one pass): an order shared between two
     * branches is one order of each, and one order of all of them.
     *
     * @param  list<string>  $ids
     * @return array{branches: array<string, int>, together: int}
     */
    public static function placed(array $ids, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = self::placedQuery($ids, $start, $end)->groupByRaw('grouping sets ((f.branch_id), ())')
            ->selectRaw('f.branch_id, grouping(f.branch_id) as everyone, count(distinct f.order_id) as n')->get();
        $out = ['branches' => [], 'together' => 0];
        foreach ($rows as $r) {
            if ((int) $r->everyone === 1) {
                $out['together'] = (int) $r->n;
            } else {
                $out['branches'][(string) $r->branch_id] = (int) $r->n;
            }
        }

        return $out;
    }

    /**
     * Parts cancelled in the window, by the reason written in the part's history when it was cancelled (null when none was written).
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, int>> branch id => reason ('' when none) => parts
     */
    public static function cancelledByReason(array $ids, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = self::cancelledQuery($ids, $start, $end)
            ->leftJoin('order_status_history as h', fn ($j) => $j->on('h.order_fulfillment_id', '=', 'f.id')->on('h.order_id', '=', 'f.order_id')
                ->where('h.to_status', 'cancelled')->where('h.kind', 'fulfillment'))
            ->groupBy('f.branch_id', 'h.reason')->selectRaw('f.branch_id, h.reason as reason, count(distinct f.id) as n')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->branch_id][(string) $r->reason] = (int) $r->n;
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int> branch id => parts cancelled in the window
     */
    public static function cancelled(array $ids, CarbonInterface $start, CarbonInterface $end): array
    {
        return self::cancelledQuery($ids, $start, $end)->groupBy('f.branch_id')->selectRaw('f.branch_id, count(*) as n')->pluck('n', 'branch_id')->map(fn (mixed $n) => (int) $n)->all();
    }

    /**
     * Parts that reached the customer in the window: delivered, or collected at the counter. A part counts once.
     *
     * @param  list<string>  $ids
     * @return array<string, int> branch id => parts
     */
    public static function reached(array $ids, CarbonInterface $start, CarbonInterface $end): array
    {
        return DB::table('order_fulfillments as f')->whereIn('f.branch_id', $ids)
            ->where(fn ($q) => $q->whereBetween('f.delivered_at', [$start, $end])->orWhereBetween('f.picked_up_at', [$start, $end]))
            ->groupBy('f.branch_id')->selectRaw('f.branch_id, count(*) as n')->pluck('n', 'branch_id')->map(fn (mixed $n) => (int) $n)->all();
    }

    /**
     * Of the orders placed in the window: the parts still open and the parts nobody has accepted, as they stand NOW (a past day's figure keeps changing until
     * the work is done). Null as the list of branches means every branch.
     *
     * @param  list<string>|null  $ids
     * @return array<string, array{open: int, never_accepted: int}>
     */
    public static function standing(?array $ids, CarbonInterface $start, CarbonInterface $end): array
    {
        $open = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        $rows = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->when($ids !== null, fn ($q) => $q->whereIn('f.branch_id', $ids))
            ->whereBetween('o.placed_at', [$start, $end])->whereRaw(self::realSql())->groupBy('f.branch_id')
            ->selectRaw("f.branch_id, count(*) filter (where f.status in ({$open})) as open, count(*) filter (where f.status = 'pending') as never_accepted", self::OPEN_STATUSES)->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->branch_id] = ['open' => (int) $r->open, 'never_accepted' => (int) $r->never_accepted];
        }

        return $out;
    }

    /** @param  list<string>  $ids */
    private static function placedQuery(array $ids, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->whereIn('f.branch_id', $ids)
            ->whereBetween('o.placed_at', [$start, $end])->whereRaw(self::realSql());
    }

    /** @param  list<string>  $ids */
    private static function cancelledQuery(array $ids, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->whereIn('f.branch_id', $ids)
            ->whereBetween('f.cancelled_at', [$start, $end])->whereRaw(self::realSql());
    }
}
