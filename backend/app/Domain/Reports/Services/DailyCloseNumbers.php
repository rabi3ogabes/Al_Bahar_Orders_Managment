<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Support\BranchScope;
use App\Domain\Reports\Services\Metrics\Assembly;
use App\Domain\Reports\Services\Metrics\Cash;
use App\Domain\Reports\Services\Metrics\Claims;
use App\Domain\Reports\Services\Metrics\Deliveries;
use App\Domain\Reports\Services\Metrics\Orders;
use App\Domain\Reports\Services\Metrics\Speed;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The figures of one branch on one Qatar business day: the page of the daily close and its printout read these. Read-only, bounded by the branch and the
 * day (every query starts from an indexed date), aggregates only: not a single customer is named and no order number is returned.
 *
 * NOTHING is defined here. Every figure comes from the shared definitions the other screens use too ({@see SalesBasis} and the classes of
 * App\Domain\Reports\Services\Metrics), asked about one branch and one day, so the weekly owner pack, the scorecard and the dashboard can never say a different
 * number for the same branch and day. The one rule written down once for all of them is docs/guides/NUMBER_DEFINITIONS.md.
 *
 *  - sales: {@see SalesBasis}, the dashboard's definition (orders placed that day, not cancelled, not waiting for online payment, the branch's own
 *    share of a split order), split by how the customers pay;
 *  - orders placed / delivered / cancelled / returned count what HAPPENED that day: parts placed, parts that reached the customer (delivered or
 *    collected), parts cancelled, and return claims whose goods came back to the branch. An online order that was never paid is not an order;
 *  - assembly: {@see Assembly}, the paid assembly of the orders placed that day (net of what went back), the visits to do and the ones the driver answered "not done";
 *  - cash is cash only (a card terminal is not cash): collected that day, how much of it has been handed in, how much is still with the people
 *    who took it, and the gap the cashier found when counting (a hand-in the day it happened);
 *  - speed is counted in OPENING-HOURS minutes, the clock the dashboard uses (Operations clock), so a night when the shop was shut is not slowness;
 *  - open and never-accepted look at the orders PLACED that day, as they stand now; stock-outs are the branch's stock as it stands now. A closed day
 *    keeps the figures it had when it was closed.
 */
final class DailyCloseNumbers
{
    public function __construct(private readonly Speed $timing) {}

    /**
     * The day's figures: date, branch_id, orders {placed, delivered, cancelled, returned}, sales {total, orders, average}, payment_mix {method: {orders, amount}},
     * cash {collected, handed_in, with_collectors, still_out_total, variance, variance_count, open_variances}, refunds {count, amount}, failed_deliveries, assembly {net, jobs, not_done},
     * speed {accept_minutes, accepted, prepare_minutes, prepared}, open {open, never_accepted}, stock {out, low}. Money is a decimal string.
     *
     * @return array<string, mixed>
     */
    public function for(string $branchId, string $date): array
    {
        [$from, $to] = BusinessTime::dateRange($date, $date);
        $ids = [$branchId];
        $scope = BranchScope::of($ids);
        $sales = SalesBasis::totals($scope, $from, $to);

        return [
            'date' => $date,
            'branch_id' => $branchId,
            'orders' => $this->orderCounts($branchId, $from, $to),
            'sales' => ['total' => $sales['total']->toDecimal(), 'orders' => $sales['orders'], 'average' => SalesBasis::average($sales['total'], $sales['orders'])->toDecimal()],
            'payment_mix' => $this->paymentMix($scope, $from, $to),
            'cash' => $this->cash($branchId, $from, $to),
            'refunds' => $this->refunds($scope, $from, $to),
            'assembly' => $this->assembly($branchId, $from, $to),
            'failed_deliveries' => array_sum(Deliveries::failures($ids, $from, $to)[$branchId] ?? []),
            'speed' => $this->speed($branchId, $from, $to),
            'open' => $this->open($branchId, $from, $to),
            'stock' => $this->stock($branchId),
        ];
    }

    /** @return array{placed: int, delivered: int, cancelled: int, returned: int} */
    private function orderCounts(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = [$branchId];

        return ['placed' => Orders::placed($ids, $from, $to)['branches'][$branchId] ?? 0, 'delivered' => Orders::reached($ids, $from, $to)[$branchId] ?? 0,
            'cancelled' => Orders::cancelled($ids, $from, $to)[$branchId] ?? 0, 'returned' => Claims::received($ids, $from, $to)[$branchId] ?? 0];
    }

    /**
     * How the day's sales were paid: the branch's share of each order, by the way the customer chose to pay. Every method is listed (a method nobody
     * used is zero), so the page and the printout always have the same rows.
     *
     * @return array<string, array{orders: int, amount: string}>
     */
    private function paymentMix(BranchScope $scope, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $mix = array_fill_keys(['cod', 'sadad', 'pay_at_pickup'], ['orders' => 0, 'amount' => '0.00']);
        $rows = $scope->apply(SalesBasis::orderShares($from, $to), 's.branch_id')->join('orders as po', 'po.id', '=', 's.order_id')
            ->selectRaw('po.payment_method as method, count(distinct s.order_id) as orders, coalesce(sum(s.share), 0)::numeric(14,2) as amount')
            ->groupBy('po.payment_method')->orderBy('po.payment_method')->get();
        foreach ($rows as $r) {
            $mix[(string) $r->method] = ['orders' => (int) $r->orders, 'amount' => Money::of((string) $r->amount)->toDecimal()];
        }

        return $mix;
    }

    /** @return array{collected: string, handed_in: string, with_collectors: string, still_out_total: string, variance: string, variance_count: int, open_variances: int} */
    private function cash(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = [$branchId];
        $day = Cash::takenQuery()->where('branch_id', $branchId)->whereBetween('collected_at', [$from, $to])
            ->selectRaw('coalesce(sum(amount_collected), 0)::numeric(14,2) as collected,
                coalesce(sum(amount_collected) filter (where handed_in_at is not null), 0)::numeric(14,2) as handed_in')->first();
        $counted = Cash::handIns($ids, $from, $to)[$branchId] ?? ['cash_net' => '0.00', 'variances' => 0];

        $collected = Money::of((string) $day->collected);
        $handedIn = Money::of((string) $day->handed_in);

        return ['collected' => $collected->toDecimal(), 'handed_in' => $handedIn->toDecimal(), 'with_collectors' => $collected->minus($handedIn)->toDecimal(),
            'still_out_total' => Cash::held($ids)[$branchId]['amount'] ?? '0.00', 'variance' => $counted['cash_net'],
            'variance_count' => $counted['variances'], 'open_variances' => Cash::openVariances($ids)[$branchId] ?? 0];
    }

    /**
     * Refunds paid out that day for orders this branch took part in: the branch's share of each ({@see SalesBasis::refunds()}).
     *
     * @return array{count: int, amount: string}
     */
    private function refunds(BranchScope $scope, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $refunds = SalesBasis::refunds($scope, $from, $to);

        return ['count' => $refunds['count'], 'amount' => $refunds['amount']->toDecimal()];
    }

    /**
     * Average minutes to accept (from when the part could be accepted to when it was) and to prepare (accepted to ready), for the parts of the
     * orders placed that day, in opening-hours minutes ({@see Speed}). null when nothing was measured: a day with no orders has no "average".
     *
     * @return array{accept_minutes: ?int, accepted: int, prepare_minutes: ?int, prepared: int}
     */
    private function speed(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $readings = $this->timing->minutes([$branchId], $from, $to)[$branchId] ?? ['accept' => [], 'prepare' => []];

        return ['accept_minutes' => Speed::average($readings['accept']), 'accepted' => count($readings['accept']),
            'prepare_minutes' => Speed::average($readings['prepare']), 'prepared' => count($readings['prepare'])];
    }

    /** @return array{net: string, jobs: int, not_done: int} */
    private function assembly(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $a = Assembly::byBranch([$branchId], $from, $to)[$branchId];

        return ['net' => $a['net']->toDecimal(), 'jobs' => $a['jobs'], 'not_done' => $a['not_done']];
    }

    /** @return array{open: int, never_accepted: int} */
    private function open(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Orders::standing([$branchId], $from, $to)[$branchId] ?? ['open' => 0, 'never_accepted' => 0];
    }

    /** @return array{out: int, low: int} the branch's stock as it stands now (the dashboard's meaning of out and low, see {@see StockLevels}) */
    private function stock(string $branchId): array
    {
        $counts = StockLevels::countsByBranch([$branchId])[$branchId] ?? ['low' => 0, 'out' => 0];

        return ['out' => $counts['out'], 'low' => $counts['low']];
    }
}
