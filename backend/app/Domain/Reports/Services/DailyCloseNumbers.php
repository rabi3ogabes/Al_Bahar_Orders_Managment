<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Support\BranchScope;
use App\Domain\Operations\Services\BranchSchedules;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The figures of one branch on one Qatar business day: the page of the daily close, its printout and the weekly owner pack all read these, so
 * the three can never disagree. Read-only, bounded by the branch and the day (every query starts from an indexed date), aggregates only: not a single
 * customer is named and no order number is returned.
 *
 *  - sales: {@see SalesBasis}, the dashboard's definition (orders placed that day, not cancelled, not waiting for online payment, the branch's own
 *    share of a split order), split by how the customers pay;
 *  - orders placed / delivered / cancelled / returned count what HAPPENED that day: parts placed, parts that reached the customer (delivered or
 *    collected), parts cancelled, and return claims whose goods came back to the branch. An online order that was never paid is not an order;
 *  - cash is cash only (a card terminal is not cash): collected that day, how much of it has been handed in, how much is still with the people
 *    who took it, and the gap the cashier found when counting (a hand-in the day it happened);
 *  - speed is counted in OPENING-HOURS minutes, the clock the dashboard uses (Operations clock), so a night when the shop was shut is not slowness;
 *  - open and never-accepted look at the orders PLACED that day, as they stand now; stock-outs are the branch's stock as it stands now. A closed day
 *    keeps the figures it had when it was closed.
 */
final class DailyCloseNumbers
{
    /** Parts of an order that are still on their way to the customer (not delivered, collected, cancelled or returned). */
    public const OPEN_STATUSES = ['pending', 'accepted', 'preparing', 'ready', 'pickup_ready', 'dispatched', 'out_for_delivery', 'failed'];

    /** How many parts the speed figures read at most (a branch does not get anywhere near this in one day). */
    private const SPEED_SAMPLE = 2000;

    public function __construct(private readonly BranchSchedules $schedules) {}

    /**
     * An order that really happened: not waiting for its online payment, and not an online order that expired unpaid (cancelled without ever
     * being confirmed).
     */
    public static function realOrderSql(string $o = 'o'): string
    {
        return "({$o}.status <> 'pending_payment' and not ({$o}.status = 'cancelled' and {$o}.confirmed_at is null and {$o}.payment_method = 'sadad'))";
    }

    /**
     * The day's figures: date, branch_id, orders {placed, delivered, cancelled, returned}, sales {total, orders, average}, payment_mix {method: {orders, amount}},
     * cash {collected, handed_in, with_collectors, still_out_total, variance, variance_count, open_variances}, refunds {count, amount}, failed_deliveries,
     * speed {accept_minutes, accepted, prepare_minutes, prepared}, open {open, never_accepted}, stock {out, low}. Money is a decimal string.
     *
     * @return array<string, mixed>
     */
    public function for(string $branchId, string $date): array
    {
        [$from, $to] = BusinessTime::dateRange($date, $date);
        $scope = BranchScope::of([$branchId]);

        $sales = SalesBasis::totals($scope, $from, $to);
        $average = $sales['orders'] > 0 ? Money::ofMinor((int) round($sales['total']->minor / $sales['orders'])) : Money::zero();

        return [
            'date' => $date,
            'branch_id' => $branchId,
            'orders' => $this->orderCounts($branchId, $from, $to),
            'sales' => ['total' => $sales['total']->toDecimal(), 'orders' => $sales['orders'], 'average' => $average->toDecimal()],
            'payment_mix' => $this->paymentMix($scope, $from, $to),
            'cash' => $this->cash($branchId, $from, $to),
            'refunds' => $this->refunds($scope, $from, $to),
            'failed_deliveries' => (int) DB::table('deliveries')->where('branch_id', $branchId)->whereBetween('failed_at', [$from, $to])->count(),
            'speed' => $this->speed($branchId, $from, $to),
            'open' => $this->open($branchId, $from, $to),
            'stock' => $this->stock($branchId),
        ];
    }

    /** @return array{placed: int, delivered: int, cancelled: int, returned: int} */
    private function orderCounts(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $placed = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->where('f.branch_id', $branchId)
            ->whereBetween('o.placed_at', [$from, $to])->whereRaw(self::realOrderSql())->count(DB::raw('distinct f.order_id'));
        $delivered = DB::table('order_fulfillments as f')->where('f.branch_id', $branchId)
            ->where(fn ($q) => $q->whereBetween('f.delivered_at', [$from, $to])->orWhereBetween('f.picked_up_at', [$from, $to]))->count();
        $cancelled = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->where('f.branch_id', $branchId)
            ->whereBetween('f.cancelled_at', [$from, $to])->whereRaw(self::realOrderSql())->count();
        $returned = DB::table('return_requests')->where('branch_id', $branchId)->whereBetween('received_at', [$from, $to])->count();

        return ['placed' => (int) $placed, 'delivered' => (int) $delivered, 'cancelled' => (int) $cancelled, 'returned' => (int) $returned];
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
        $cash = fn () => DB::table('cash_collections')->where('branch_id', $branchId)->where('tender', 'cash')->whereIn('status', ['collected', 'partial']);
        $day = $cash()->whereBetween('collected_at', [$from, $to])
            ->selectRaw('coalesce(sum(amount_collected), 0)::numeric(14,2) as collected,
                coalesce(sum(amount_collected) filter (where handed_in_at is not null), 0)::numeric(14,2) as handed_in')->first();
        $stillOut = $cash()->whereNull('handed_in_at')->selectRaw('coalesce(sum(amount_collected), 0)::numeric(14,2) as total')->value('total');
        $counted = DB::table('cash_handovers')->where('branch_id', $branchId)->whereBetween('created_at', [$from, $to])
            ->selectRaw('coalesce(sum(variance), 0)::numeric(14,2) as variance, count(*) filter (where variance <> 0) as variance_count')->first();
        $openVariances = DB::table('cash_handovers')->where('branch_id', $branchId)->where('variance_status', 'open')->count();

        $collected = Money::of((string) $day->collected);
        $handedIn = Money::of((string) $day->handed_in);

        return ['collected' => $collected->toDecimal(), 'handed_in' => $handedIn->toDecimal(), 'with_collectors' => $collected->minus($handedIn)->toDecimal(),
            'still_out_total' => Money::of((string) $stillOut)->toDecimal(), 'variance' => Money::of((string) $counted->variance)->toDecimal(),
            'variance_count' => (int) $counted->variance_count, 'open_variances' => (int) $openVariances];
    }

    /**
     * Refunds paid out that day for orders this branch took part in: the branch's share of each (divided like the sale itself, see
     * {@see SalesBasis::branchFraction()}), so two branches never both claim the whole refund.
     *
     * @return array{count: int, amount: string}
     */
    private function refunds(BranchScope $scope, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $day = fn () => DB::table('refunds as r')->where('r.status', 'processed')->whereNotNull('r.order_id')->whereBetween('r.processed_at', [$from, $to]);
        $perOrder = $day()->selectRaw('r.order_id, sum(r.amount) as amount, count(*) as n')->groupBy('r.order_id');
        // The branch's share is worked out only for the orders that were refunded that day (never for every order of the company).
        $fractions = SalesBasis::branchFraction($scope)->whereIn('f.order_id', $day()->select('r.order_id'));
        $row = DB::query()->fromSub($perOrder, 'ro')->joinSub($fractions, 'bf', 'bf.order_id', '=', 'ro.order_id')
            ->selectRaw('coalesce(sum(round(ro.amount * bf.fraction, 2)), 0)::numeric(14,2) as total, coalesce(sum(ro.n) filter (where bf.fraction > 0), 0) as n')->first();

        return ['count' => (int) ($row->n ?? 0), 'amount' => Money::of((string) ($row->total ?? '0'))->toDecimal()];
    }

    /**
     * Average minutes to accept (from when the part could be accepted to when it was) and to prepare (accepted to ready), for the parts of the
     * orders placed that day, in opening-hours minutes. null when nothing was measured: a day with no orders has no "average".
     *
     * @return array{accept_minutes: ?int, accepted: int, prepare_minutes: ?int, prepared: int}
     */
    private function speed(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->where('f.branch_id', $branchId)
            ->whereBetween('o.placed_at', [$from, $to])->whereRaw(self::realOrderSql())->whereNotNull('f.accepted_at')
            ->orderBy('f.created_at')->orderBy('f.id')->limit(self::SPEED_SAMPLE)
            ->get(['f.created_at as created_at', 'o.confirmed_at as confirmed_at', 'f.accepted_at as accepted_at', 'f.ready_at as ready_at']);
        $this->schedules->preload([$branchId]);
        $accept = [];
        $prepare = [];
        foreach ($rows as $r) {
            $created = CarbonImmutable::parse($r->created_at);
            $confirmed = $r->confirmed_at !== null ? CarbonImmutable::parse($r->confirmed_at) : null;
            $accepted = CarbonImmutable::parse($r->accepted_at);
            $accept[] = $this->schedules->minutes($branchId, $confirmed !== null && $confirmed > $created ? $confirmed : $created, $accepted)['counted'];
            if ($r->ready_at !== null) {
                $prepare[] = $this->schedules->minutes($branchId, $accepted, CarbonImmutable::parse($r->ready_at))['counted'];
            }
        }

        return ['accept_minutes' => $accept === [] ? null : (int) round(array_sum($accept) / count($accept)), 'accepted' => count($accept),
            'prepare_minutes' => $prepare === [] ? null : (int) round(array_sum($prepare) / count($prepare)), 'prepared' => count($prepare)];
    }

    /** @return array{open: int, never_accepted: int} */
    private function open(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->where('f.branch_id', $branchId)
            ->whereBetween('o.placed_at', [$from, $to])->whereRaw(self::realOrderSql())
            ->selectRaw('count(*) filter (where f.status in ('.implode(',', array_fill(0, count(self::OPEN_STATUSES), '?')).')) as open, count(*) filter (where f.status = \'pending\') as never_accepted',
                self::OPEN_STATUSES)->first();

        return ['open' => (int) ($row->open ?? 0), 'never_accepted' => (int) ($row->never_accepted ?? 0)];
    }

    /** @return array{out: int, low: int} the branch's stock as it stands now (the dashboard's meaning of out and low, see {@see StockLevels}) */
    private function stock(string $branchId): array
    {
        $counts = StockLevels::counts(DB::table('branch_inventory as bi')->where('bi.branch_id', $branchId));

        return ['out' => $counts['out'], 'low' => $counts['low']];
    }
}
