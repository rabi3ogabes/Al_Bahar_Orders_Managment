<?php

namespace App\Domain\Reports\Services;

use App\Domain\Checkout\Support\Assembly;
use App\Domain\Identity\Support\BranchScope;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Paid assembly and installation in the sales reports (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md).
 *
 * WHAT COUNTS. The same orders as every other sales figure ({@see SalesBasis}): placed in the window (shop time), not cancelled, not waiting for the online
 * payment, limited to the branches the viewer may see (the branch of the order LINE, as in the other sales reports). Assembly is a per-unit fee frozen on the
 * line (`order_items.assembly_fee`, see App\Domain\Checkout\Support\Assembly), on delivery orders only.
 *
 *  - UNITS    the units of a line that are still on the order: quantity less the units a branch cancelled. A unit an adjustment removed has already left `quantity`.
 *  - GROSS    the assembly those units owe: fee x units, never more than the line still owes (`assembly_total`).
 *  - REFUNDED the assembly of the units that came back (`quantity_returned`): the fee of a returned unit goes back to the customer, the same share
 *             {@see Assembly::refundShare()} puts in a suggested refund, never more than the line's gross.
 *  - NET      gross less refunded. Assembly is a flat service charge: no promotion, tax or deal touches it, so these are the amounts the customer was charged.
 *  - JOBS     the branch parts of an order (one visit to the customer's door) that still have at least one assembly unit left after the returns.
 *
 * Everything is attributed to the day the ORDER was placed, like the sale it belongs to: a return made next month lowers the net of the month the order was
 * placed in, and the report of a past period can therefore change when a later return arrives (the guide says so).
 * Every query is read-only and bounded by the indexed date range on orders.placed_at.
 */
final class AssemblySales
{
    /**
     * Keeps only the rows whose branch part (order fulfillment) has at least one assembly unit left: the "orders with assembly" filter of the sales reports.
     * A part with assembly is judged on its own lines, so a branch that only had furniture in a split order is not counted as an assembly order.
     */
    public static function onlyParts(Builder $query, string $fulfillmentColumn): Builder
    {
        return $query->whereExists(fn (Builder $e) => $e->selectRaw('1')->from('order_items as ax')
            ->whereColumn('ax.order_fulfillment_id', $fulfillmentColumn)->where('ax.assembly_fee', '>', 0)->whereRaw('ax.quantity - ax.quantity_cancelled > 0'));
    }

    /**
     * What the sales summary shows: one row for the whole scope.
     *
     * @return array{jobs: int, units: int, returned_units: int, gross: Money, refunded: Money, net: Money}
     */
    public static function totals(BranchScope $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = DB::query()->fromSub(self::perPart($scope, $from, $to), 'p')->selectRaw(
            'count(*) filter (where p.units - p.returned_units > 0) as jobs, coalesce(sum(p.units), 0) as units, coalesce(sum(p.returned_units), 0) as returned_units,
             coalesce(sum(p.gross), 0)::numeric(14,2) as gross, coalesce(sum(p.refunded), 0)::numeric(14,2) as refunded'
        )->first();

        return self::figures($row);
    }

    /**
     * One row per branch (the branch of the lines), the biggest net first.
     *
     * @return list<array{branch: string, name: string, jobs: int, units: int, returned_units: int, gross: Money, refunded: Money, net: Money}>
     */
    public static function perBranch(BranchScope $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::query()->fromSub(self::perPart($scope, $from, $to), 'p')->join('branches as b', 'b.id', '=', 'p.branch_id')->selectRaw(
            'b.code as branch, b.name_en as name, count(*) filter (where p.units - p.returned_units > 0) as jobs, coalesce(sum(p.units), 0) as units,
             coalesce(sum(p.returned_units), 0) as returned_units, coalesce(sum(p.gross), 0)::numeric(14,2) as gross, coalesce(sum(p.refunded), 0)::numeric(14,2) as refunded,
             coalesce(sum(p.gross - p.refunded), 0)::numeric(14,2) as net'
        )->groupBy('b.code', 'b.name_en')->orderByDesc('net')->orderBy('b.code')->get();

        return $rows->map(fn ($r) => ['branch' => (string) $r->branch, 'name' => (string) $r->name] + self::figures($r))->all();
    }

    /**
     * The sales of the orders with assembly, as the dashboard counts them: the booked amount and the number of orders. Same basis as {@see SalesBasis::totals()}
     * (a split order is divided between its branches), limited to the branch parts that have assembly.
     *
     * @return array{total: Money, orders: int}
     */
    public static function bookedOfAssemblyOrders(BranchScope $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = self::onlyParts($scope->apply(SalesBasis::orderShares($from, $to), 's.branch_id'), 's.fulfillment_id')
            ->selectRaw('coalesce(sum(s.share), 0)::numeric(14,2) as total, count(distinct s.order_id) as orders')->first();

        return ['total' => Money::of((string) ($row->total ?? '0')), 'orders' => (int) ($row->orders ?? 0)];
    }

    /**
     * One row per branch part with assembly: its branch, its units still on the order, the units that came back, and the gross and refunded amounts.
     * Lines that are already gone (every unit cancelled or removed) have no units and so no row of their own value; the part still appears with zeros and is
     * not counted as a job.
     */
    private static function perPart(BranchScope $scope, CarbonInterface $from, CarbonInterface $to): Builder
    {
        $line = $scope->apply(
            DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->whereBetween('o.placed_at', [$from, $to])->whereNotIn('o.status', SalesBasis::EXCLUDED_STATUSES)->where('oi.assembly_fee', '>', 0),
            'oi.branch_id'
        )->selectRaw(
            'oi.branch_id, oi.order_fulfillment_id as part_id, greatest(oi.quantity - oi.quantity_cancelled, 0) as units, oi.quantity_returned as returned_units,
             least(oi.assembly_total, oi.assembly_fee * greatest(oi.quantity - oi.quantity_cancelled, 0)) as gross,
             least(oi.assembly_fee * oi.quantity_returned, least(oi.assembly_total, oi.assembly_fee * greatest(oi.quantity - oi.quantity_cancelled, 0))) as refunded'
        );

        return DB::query()->fromSub($line, 'l')->selectRaw(
            'l.branch_id, l.part_id, sum(l.units) as units, sum(l.returned_units) as returned_units, sum(l.gross) as gross, sum(l.refunded) as refunded'
        )->groupBy('l.branch_id', 'l.part_id');
    }

    /**
     * @return array{jobs: int, units: int, returned_units: int, gross: Money, refunded: Money, net: Money}
     */
    private static function figures(?object $row): array
    {
        $gross = Money::of((string) ($row->gross ?? '0'));
        $refunded = Money::of((string) ($row->refunded ?? '0'));

        return ['jobs' => (int) ($row->jobs ?? 0), 'units' => (int) ($row->units ?? 0), 'returned_units' => (int) ($row->returned_units ?? 0),
            'gross' => $gross, 'refunded' => $refunded, 'net' => $gross->minus($refunded)];
    }
}
