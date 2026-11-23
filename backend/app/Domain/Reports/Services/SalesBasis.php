<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Support\BranchScope;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE definition of "sales", used by the dashboard tiles, the 14-day chart and the reports, so the three can never disagree.
 *
 *  - An order counts as a sale when it is not cancelled and not waiting for its online payment ({@see self::EXCLUDED_STATUSES}), on the day it was
 *    PLACED, with the day boundaries taken in the shop's time zone (config platform.business_timezone, Asia/Qatar), never in UTC.
 *  - The amount of a sale is the order's grand total (items, delivery and service fees, tax, less discounts). That is "booked": it says nothing
 *    about whether the money has arrived. What has arrived is a separate number, {@see self::collected()}.
 *  - A branch sees only its own part of an order. An order split between branches is divided in proportion to what each branch's part is worth
 *    (its lines after discount, with tax, the assembly bought for them, plus its own delivery fee: the same weights and the same largest-remainder rounding as
 *    App\Domain\Orders\Services\ParcelShares, which divides cash and refunds, so the figures agree to the cent). The division is done in cents, so the
 *    parts ALWAYS add up to the order's grand total exactly. A fulfillment a branch cancelled is not sales: its part drops out of every view.
 *
 * Every query is read-only and bounded by an indexed date range (orders.placed_at).
 */
final class SalesBasis
{
    /** What a branch part of an order is worth: its live lines (after discount, with tax) plus its own delivery fee. `f` is order_fulfillments, `li` its lines ({@see self::withLines()}). */
    private const WEIGHT_SQL = '(li.lines + f.delivery_fee)';

    /** Orders in these states are never sales: they were cancelled, or the customer has not paid online yet. */
    public const EXCLUDED_STATUSES = ['cancelled', 'pending_payment'];

    /**
     * One row per live fulfillment of every counted order: order_id, fulfillment_id, branch_id, user_id, placed_at, share (the branch's part).
     * The division is computed over ALL the order's fulfillments, so apply the branch scope on top of this query, never inside it.
     *
     * @param  list<string>|null  $userIds  only the orders of these customers (null: every order)
     */
    public static function orderShares(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?array $userIds = null): Builder
    {
        $counted = fn (Builder $q): Builder => $q->whereNotIn('o.status', self::EXCLUDED_STATUSES)
            ->when($from, fn ($q) => $q->where('o.placed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('o.placed_at', '<=', $to))
            // A few customers (their own page, one page of a list): only their orders are read, by the index on orders.user_id.
            ->when($userIds !== null, fn ($q) => $q->whereIn('o.user_id', $userIds));
        $otherPart = fn (Builder $q): Builder => $q->select(DB::raw('1'))->from('order_fulfillments as x')->whereColumn('x.order_id', 'f.order_id')->whereColumn('x.id', '<>', 'f.id');

        // The usual order has ONE part: that part is the whole order, so its share is the grand total and nothing has to be divided (no lines to add up, no sorting).
        // Only an order split between branches goes through the division below. The two give the same cents (see the tests of the numbers).
        $single = $counted(DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id'))
            ->whereNotExists($otherPart)->where('f.status', '<>', 'cancelled')
            ->selectRaw('f.id as fulfillment_id, f.order_id as gk, f.order_id, f.branch_id, o.user_id, o.placed_at, o.grand_total as amount, true as live, round(o.grand_total, 2) as share');

        $split = $counted(self::withLines(DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')))
            ->whereExists($otherPart)
            ->selectRaw("f.id as fulfillment_id, f.order_id as gk, f.order_id, f.branch_id, o.user_id, o.placed_at, o.grand_total as amount,
                (f.status <> 'cancelled') as live, ".self::WEIGHT_SQL.' as weight');
        $divided = self::allocate($split)
            ->select(['s.fulfillment_id', 's.gk', 's.order_id', 's.branch_id', 's.user_id', 's.placed_at', 's.amount', 's.live', 's.share']);

        return DB::query()->fromSub($single->unionAll($divided), 's');
    }

    /**
     * What the customers paid us in the window (money received, not sales booked): online payments the day they were captured, plus every cash,
     * card or transfer collection staff recorded (cash on delivery, pay at pickup). Order payments only: an auction deposit is not a sale.
     *
     * @return array{online: Money, cash: Money, total: Money}
     */
    public static function collected(BranchScope $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $cash = $scope->apply(DB::table('cash_collections as c')->whereIn('c.status', ['collected', 'partial'])->whereBetween('c.collected_at', [$from, $to]), 'c.branch_id')
            ->selectRaw('coalesce(sum(c.amount_collected), 0)::numeric(14,2) as total')->value('total');

        $online = self::withLines(DB::table('order_fulfillments as f')->join('payments as p', 'p.order_id', '=', 'f.order_id'))
            ->whereNotIn('p.method', ['cod', 'pay_at_pickup'])->where('p.captured_amount', '>', 0)->whereBetween('p.paid_at', [$from, $to])
            ->selectRaw('f.id as fulfillment_id, p.id as gk, f.order_id, f.branch_id, p.paid_at as placed_at, p.captured_amount as amount,
                true as live, '.self::WEIGHT_SQL.' as weight');
        $online = $scope->apply(self::allocate($online), 's.branch_id')->selectRaw('coalesce(sum(s.share), 0)::numeric(14,2) as total')->value('total');

        $cash = Money::of((string) $cash);
        $online = Money::of((string) $online);

        return ['online' => $online, 'cash' => $cash, 'total' => $online->plus($cash)];
    }

    /**
     * The sales of a window: the amount and the number of orders.
     *
     * @return array{total: Money, orders: int}
     */
    public static function totals(BranchScope $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = $scope->apply(self::orderShares($from, $to), 's.branch_id')
            ->selectRaw('coalesce(sum(s.share), 0)::numeric(14,2) as total, count(distinct s.order_id) as orders')->first();

        return ['total' => Money::of((string) ($row->total ?? '0')), 'orders' => (int) ($row->orders ?? 0)];
    }

    /**
     * Sales per shop-local day, for the chart and for the "today" tiles: keyed by Y-m-d, each {total: numeric string, orders: int}.
     *
     * @return Collection<(int|string), \stdClass>
     */
    public static function perDay(BranchScope $scope, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $scope->apply(self::orderShares($from, $to), 's.branch_id')
            ->selectRaw("to_char(s.placed_at at time zone ?, 'YYYY-MM-DD') as day, sum(s.share)::numeric(14,2) as total, count(distinct s.order_id) as orders", [BusinessTime::zone()])
            ->groupBy('day')->orderBy('day')->get()->keyBy('day');
    }

    /**
     * The sales of a window branch by branch (each branch's own share of every order it took part in), and of all the branches TOGETHER, in one pass. An order
     * shared between two branches is one order of each, so the orders of the branches add up to more than the orders together, which count it once (the
     * dashboard's figure); the amounts add up exactly.
     *
     * @return array{branches: array<string, array{total: Money, orders: int}>, together: array{total: Money, orders: int}} the branches (of the scope) that sold something, and all of them together
     */
    public static function byBranch(BranchScope $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $scope->apply(self::orderShares($from, $to), 's.branch_id')->selectRaw('s.branch_id, grouping(s.branch_id) as everyone, coalesce(sum(s.share), 0)::numeric(14,2) as total, count(distinct s.order_id) as orders')
            ->groupByRaw('grouping sets ((s.branch_id), ())')->get();
        $out = ['branches' => [], 'together' => ['total' => Money::zero(), 'orders' => 0]];
        foreach ($rows as $r) {
            $line = ['total' => Money::of((string) $r->total), 'orders' => (int) $r->orders];
            if ((int) $r->everyone === 1) {
                $out['together'] = $line;
            } else {
                $out['branches'][(string) $r->branch_id] = $line;
            }
        }

        return $out;
    }

    /** The average basket: sales divided by the orders that made them, to the nearest cent; nothing when there were no orders. */
    public static function average(Money $total, int $orders): Money
    {
        return $orders > 0 ? Money::ofMinor((int) round($total->minor / $orders)) : Money::zero();
    }

    /**
     * Refunds paid out (processed) in the window for orders the scope took part in, each divided between the branches like the sale itself ({@see self::branchFraction()}),
     * so two branches never both claim a whole refund. One row per refund: two refunds of the same amount are two refunds. `$exceptReasons` leaves out refunds whose
     * reason code says the order lines already dropped that money (a cancellation, an item change): the daily close counts every refund, the sales report only the rest.
     * `$onlyOrders` (a query of order ids) keeps only the refunds of those orders.
     *
     * @param  list<string>  $exceptReasons
     * @return array{count: int, amount: Money}
     */
    public static function refunds(BranchScope $scope, CarbonInterface $from, CarbonInterface $to, array $exceptReasons = [], ?Builder $onlyOrders = null): array
    {
        $day = fn () => DB::table('refunds as r')->where('r.status', 'processed')->whereNotNull('r.order_id')->whereBetween('r.processed_at', [$from, $to])
            ->when($exceptReasons !== [], fn ($q) => $q->whereNotIn('r.reason_code', $exceptReasons))
            ->when($onlyOrders !== null, fn ($q) => $q->whereIn('r.order_id', $onlyOrders));
        $perOrder = $day()->selectRaw('r.order_id, sum(r.amount) as amount, count(*) as n')->groupBy('r.order_id');
        // The branch's share is worked out only for the orders that were refunded in the window (never for every order of the company).
        $fractions = self::branchFraction($scope)->whereIn('f.order_id', $day()->select('r.order_id'));
        $row = DB::query()->fromSub($perOrder, 'ro')->joinSub($fractions, 'bf', 'bf.order_id', '=', 'ro.order_id')
            ->selectRaw('coalesce(sum(round(ro.amount * bf.fraction, 2)), 0)::numeric(14,2) as total, coalesce(sum(ro.n) filter (where bf.fraction > 0), 0) as n')->first();

        return ['count' => (int) ($row->n ?? 0), 'amount' => Money::of((string) ($row->total ?? '0'))];
    }

    /**
     * The fraction of each order that belongs to the branches in the scope, for amounts that are not sales (a refund of an order). 1 for a
     * viewer who sees every branch. Rows: order_id, fraction.
     */
    public static function branchFraction(BranchScope $scope): Builder
    {
        $weight = self::WEIGHT_SQL;
        $query = self::withLines(DB::table('order_fulfillments as f'))->groupBy('f.order_id');
        if ($scope->all) {
            return $query->selectRaw('f.order_id, 1.0 as fraction');
        }
        if ($scope->branchIds === []) {
            return $query->selectRaw('f.order_id, 0.0 as fraction');
        }
        $in = implode(',', array_fill(0, count($scope->branchIds), '?'));

        return $query->selectRaw("f.order_id, case when sum({$weight}) > 0
                then coalesce(sum({$weight}) filter (where f.branch_id in ({$in})), 0) / sum({$weight})
                else (count(*) filter (where f.branch_id in ({$in})))::numeric / count(*) end as fraction", [...$scope->branchIds, ...$scope->branchIds]);
    }

    /** Adds, to a query over order_fulfillments `f`, the total of each part's live lines as `li.lines`: worked out once per part, by its order's index. */
    private static function withLines(Builder $query): Builder
    {
        return $query->leftJoinLateral(
            DB::table('order_items as oi')->selectRaw('coalesce(sum(oi.line_total + oi.assembly_total), 0) as lines')
                ->whereColumn('oi.order_id', 'f.order_id')->whereColumn('oi.order_fulfillment_id', 'f.id')->where('oi.quantity', '>', 0),
            'li'
        );
    }

    /**
     * Splits `amount` between the rows of one group (`gk`) in proportion to `weight`, in cents, with the largest-remainder rule (the rows with the
     * biggest leftover fractions get the odd cents, ties in id order): the parts add up to the amount exactly.
     * `base` selects: fulfillment_id, gk, order_id, branch_id, placed_at, amount, live, weight (and anything else callers need).
     * Returns a query over the live rows with the extra column `share`, aliased `s`. A group whose weights are all zero is split equally.
     */
    private static function allocate(Builder $base): Builder
    {
        $minor = DB::query()->fromSub($base, 'b')->selectRaw(
            'b.*, round(b.amount * 100)::bigint as a_minor, round(b.weight * 100)::bigint as w_minor,
             sum(round(b.weight * 100)::bigint) over (partition by b.gk) as total_minor, count(*) over (partition by b.gk) as n'
        );
        $floors = DB::query()->fromSub($minor, 't')->selectRaw(
            't.*, floor(t.a_minor::numeric * (case when t.total_minor > 0 then t.w_minor else 1 end) / (case when t.total_minor > 0 then t.total_minor else t.n end)) as floor_minor,
             mod(t.a_minor::numeric * (case when t.total_minor > 0 then t.w_minor else 1 end), (case when t.total_minor > 0 then t.total_minor else t.n end)::numeric) as remainder'
        );
        $ranked = DB::query()->fromSub($floors, 'p')->selectRaw(
            'p.*, p.a_minor - sum(p.floor_minor) over (partition by p.gk) as leftover,
             row_number() over (partition by p.gk order by p.remainder desc, p.fulfillment_id) as pos'
        );
        $shares = DB::query()->fromSub($ranked, 'q')->selectRaw(
            'q.*, round((q.floor_minor + case when q.pos <= q.leftover then 1 else 0 end) / 100.0, 2) as share'
        );

        return DB::query()->fromSub($shares, 's')->where('s.live', true);
    }
}
