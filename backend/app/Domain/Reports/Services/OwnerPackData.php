<?php

namespace App\Domain\Reports\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Reports\Services\Metrics\Assembly;
use App\Domain\Reports\Services\Metrics\Cash;
use App\Domain\Reports\Services\Metrics\Claims;
use App\Domain\Reports\Services\Metrics\Deliveries;
use App\Domain\Reports\Services\Metrics\Orders;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the weekly owner pack: one Monday-to-Sunday week (Qatar time) of the whole company, branch by branch, set beside the week before.
 * AGGREGATES ONLY: counts, totals and rates, plus the names of products (never of customers, never an order number): the pack leaves the dashboard
 * by e-mail, so nothing in it may identify a person. Every query is bounded by the week and grouped by branch in the database.
 *
 * NOTHING is defined here. Every figure comes from the shared definitions the other screens use too ({@see SalesBasis} and the classes of
 * App\Domain\Reports\Services\Metrics), asked about a week, so the pack equals the dashboard, the daily close and the scorecard (docs/guides/NUMBER_DEFINITIONS.md):
 *
 *  - sales and orders: {@see SalesBasis} (the dashboard's definition). The company's line counts an order shared by two branches ONCE, like the dashboard;
 *  - average basket: sales divided by orders;
 *  - delivery performance: of the deliveries that reached the customer that week and had a promised day, the share that arrived on that day or earlier;
 *  - returns and claims rate: return and damage claims OPENED that week per 100 parts that reached the customer that week (null when none did);
 *  - stock-outs: items out of stock NOW (a stock figure cannot be looked up in the past);
 *  - assembly: {@see Assembly}, the paid assembly of the orders placed in the week (net of returns), the visits to do and the ones the driver answered "not done";
 *  - cash difference: what the cashiers found when they counted the cash handed in that week (signed: short is negative);
 *  - days not closed: days of the week, already over, that the branch's manager never closed ({@see DailyCloseService}).
 */
final class OwnerPackData
{
    /** A branch whose sales fell by this share (or more) against the week before is named in "what needs attention". */
    public const SALES_DROP_PERCENT = 20;

    /** A branch that kept fewer than this share of its delivery promises is named in "what needs attention". */
    public const ON_TIME_FLOOR_PERCENT = 80;

    public function __construct(private readonly DailyCloseService $closes) {}

    /** The ISO week (e.g. 2026-W47) that ended most recently: the one the pack covers when it is sent. */
    public static function lastCompletedWeek(?CarbonImmutable $now = null): string
    {
        return self::weekKey(($now ?? BusinessTime::now())->setTimezone(BusinessTime::zone())->startOfWeek()->subWeek());
    }

    public static function weekKey(CarbonImmutable $day): string
    {
        return $day->format('o-\WW');
    }

    /** @return array{0: string, 1: string} the Monday and the Sunday of an ISO week, as Y-m-d (Qatar days) */
    public static function bounds(string $week): array
    {
        if (preg_match('/^(\d{4})-W(0[1-9]|[1-4]\d|5[0-3])$/', $week, $m) !== 1) {
            throw new DomainException('owner_pack_bad_week', __('owner_pack.errors.bad_week'), 422);
        }
        $monday = CarbonImmutable::now(BusinessTime::zone())->setISODate((int) $m[1], (int) $m[2], 1)->startOfDay();
        if (self::weekKey($monday) !== $week) {
            throw new DomainException('owner_pack_bad_week', __('owner_pack.errors.bad_week'), 422); // "2026-W53" in a year with only 52 weeks
        }

        return [$monday->toDateString(), $monday->addDays(6)->toDateString()];
    }

    /**
     * The pack's figures: week, from, to, prev_from, prev_to, branches (id, code, name_en, name_ar, this, prev), total (this, prev), top_units and top_revenue
     * (name_en, name_ar, units, revenue), attention (key, count, branches). Money is a decimal string.
     *
     * @return array<string, mixed>
     */
    public function for(string $week): array
    {
        [$from, $to] = self::bounds($week);
        $prevFrom = CarbonImmutable::parse($from)->subWeek()->toDateString();
        $prevTo = CarbonImmutable::parse($to)->subWeek()->toDateString();
        $branches = Branch::query()->active()->with('hours')->orderBy('sort_order')->orderBy('code')->get();
        $ids = $branches->modelKeys();

        ['branches' => $current, 'together' => $together] = $this->metrics($ids, $from, $to);
        ['branches' => $prev, 'together' => $togetherBefore] = $this->metrics($ids, $prevFrom, $prevTo);
        $stock = $this->stockOuts($ids);
        $unclosed = $this->unclosedDays($branches, $from, $to);
        $stillOut = $this->cashStillOut($ids);

        $rows = [];
        foreach ($branches as $b) {
            $t = $current[$b->id] + ['stock_out' => $stock[$b->id] ?? 0, 'unclosed_days' => $unclosed[$b->id] ?? 0, 'cash_with_collectors' => $stillOut[$b->id] ?? '0.00'];
            $rows[] = ['id' => $b->id, 'code' => $b->code, 'name_en' => (string) $b->name_en, 'name_ar' => (string) ($b->name_ar ?: $b->name_en), 'this' => $t, 'prev' => $prev[$b->id]];
        }
        $total = ['this' => $this->sum(array_column($rows, 'this'), $together), 'prev' => $this->sum(array_column($rows, 'prev'), $togetherBefore)];
        [$topUnits, $topRevenue] = $this->topProducts($from, $to);

        return ['week' => $week, 'from' => $from, 'to' => $to, 'prev_from' => $prevFrom, 'prev_to' => $prevTo, 'branches' => $rows, 'total' => $total,
            'top_units' => $topUnits, 'top_revenue' => $topRevenue, 'attention' => $this->attention($rows, $from, $to)];
    }

    /** Percent change of this week against the week before; null when there was nothing to compare with. */
    public static function change(string|int|float $now, string|int|float $before): ?float
    {
        return (float) $before > 0 ? round(((float) $now - (float) $before) * 100 / (float) $before, 1) : null;
    }

    /**
     * The week's figures per branch, one grouped query each, and the company's sales and orders (an order shared by two branches counted once).
     *
     * @param  list<string>  $branchIds
     * @return array{branches: array<string, array<string, mixed>>, together: array{sales: string, orders: int, average: string}}
     */
    private function metrics(array $branchIds, string $from, string $to): array
    {
        [$start, $end] = BusinessTime::dateRange($from, $to);
        $sales = SalesBasis::byBranch(BranchScope::of($branchIds), $start, $end);
        $reached = Orders::reached($branchIds, $start, $end);
        $promises = Deliveries::promises($branchIds, $start, $end);
        $failed = Deliveries::failures($branchIds, $start, $end);
        $claims = Claims::opened($branchIds, $start, $end);
        $cash = Cash::handIns($branchIds, $start, $end);
        $assembly = Assembly::byBranch($branchIds, $start, $end);

        $out = [];
        foreach ($branchIds as $id) {
            $orders = $sales['branches'][$id]['orders'] ?? 0;
            $total = $sales['branches'][$id]['total'] ?? Money::zero();
            $arrived = $reached[$id] ?? 0;
            $promised = $promises[$id]['promised'] ?? 0;
            $onTime = $promises[$id]['on_time'] ?? 0;
            $opened = array_sum($claims[$id] ?? []);
            $out[$id] = [
                'sales' => $total->toDecimal(), 'orders' => $orders, 'average' => SalesBasis::average($total, $orders)->toDecimal(),
                'reached' => $arrived, 'promised' => $promised, 'on_time' => $onTime, 'on_time_rate' => $promised > 0 ? round($onTime * 100 / $promised, 1) : null,
                'failed_deliveries' => array_sum($failed[$id] ?? []),
                'claims' => $opened, 'claims_rate' => $arrived > 0 ? round($opened * 100 / $arrived, 1) : null,
                'cash_variance' => $cash[$id]['cash_net'] ?? '0.00', 'cash_variance_count' => $cash[$id]['variances'] ?? 0,
                'assembly_net' => $assembly[$id]['net']->toDecimal(), 'assembly_jobs' => $assembly[$id]['jobs'], 'assembly_not_done' => $assembly[$id]['not_done'],
            ];
        }

        $together = $sales['together'];

        return ['branches' => $out, 'together' => ['sales' => $together['total']->toDecimal(), 'orders' => $together['orders'], 'average' => SalesBasis::average($together['total'], $together['orders'])->toDecimal()]];
    }

    /**
     * The company's line: sums of the branches, except the orders (and so the average basket), which are the distinct orders of all the branches together; a rate
     * is recomputed from the sums, never averaged.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{sales: string, orders: int, average: string}  $together
     * @return array<string, mixed>
     */
    private function sum(array $lines, array $together): array
    {
        $n = fn (string $k) => array_sum(array_map(fn (array $l) => (int) ($l[$k] ?? 0), $lines));
        $money = fn (string $k) => array_reduce($lines, fn (Money $c, array $l) => $c->plus(Money::of((string) ($l[$k] ?? '0'))), Money::zero());
        $promised = $n('promised');
        $reached = $n('reached');

        return [
            'sales' => $together['sales'], 'orders' => $together['orders'], 'average' => $together['average'],
            'reached' => $reached, 'promised' => $promised, 'on_time' => $n('on_time'), 'on_time_rate' => $promised > 0 ? round($n('on_time') * 100 / $promised, 1) : null,
            'failed_deliveries' => $n('failed_deliveries'), 'claims' => $n('claims'), 'claims_rate' => $reached > 0 ? round($n('claims') * 100 / $reached, 1) : null,
            'cash_variance' => $money('cash_variance')->toDecimal(), 'cash_variance_count' => $n('cash_variance_count'),
            'assembly_net' => $money('assembly_net')->toDecimal(), 'assembly_jobs' => $n('assembly_jobs'), 'assembly_not_done' => $n('assembly_not_done'),
            'stock_out' => $n('stock_out'), 'unclosed_days' => $n('unclosed_days'), 'cash_with_collectors' => $money('cash_with_collectors')->toDecimal(),
        ];
    }

    /**
     * @param  list<string>  $branchIds
     * @return array<string, int> branch id => items out of stock now
     */
    private function stockOuts(array $branchIds): array
    {
        return array_map(fn (array $c) => $c['out'], StockLevels::countsByBranch($branchIds));
    }

    /**
     * @param  list<string>  $branchIds
     * @return array<string, string> branch id => cash taken in cash on delivery or at the counter that nobody has handed in yet (any day)
     */
    private function cashStillOut(array $branchIds): array
    {
        return array_map(fn (array $h) => $h['amount'], Cash::held($branchIds));
    }

    /**
     * Days of the week that are over for the branch and were never closed. A day on which the branch was shut (its hours say so) and nothing happened
     * (no order placed, no cash taken) has nothing to close, so a branch that does not open on Fridays is not reported every week for its Friday.
     *
     * @param  iterable<Branch>  $branches  with their hours loaded
     * @return array<string, int>
     */
    private function unclosedDays(iterable $branches, string $from, string $to): array
    {
        [$start, $end] = BusinessTime::dateRange($from, $to);
        $zone = BusinessTime::zone();
        $closed = DB::table('daily_closes')->whereBetween('business_date', [$from, $to])->get(['branch_id', 'business_date'])
            ->map(fn ($r) => $r->branch_id.'|'.substr((string) $r->business_date, 0, 10))->flip();
        $busy = DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id')->whereBetween('o.placed_at', [$start, $end])
            ->selectRaw("f.branch_id, to_char(o.placed_at at time zone ?, 'YYYY-MM-DD') as day", [$zone])->distinct()->get()
            ->merge(DB::table('cash_collections')->whereBetween('collected_at', [$start, $end])->selectRaw("branch_id, to_char(collected_at at time zone ?, 'YYYY-MM-DD') as day", [$zone])->distinct()->get())
            ->map(fn ($r) => $r->branch_id.'|'.$r->day)->flip();
        $now = CarbonImmutable::now();
        $out = [];
        foreach ($branches as $b) {
            $n = 0;
            for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
                $date = $d->toDateString();
                $needed = $this->closes->opensOn($b, $date) || isset($busy[$b->id.'|'.$date]);
                if ($needed && $this->closes->closesAt($b, $date) <= $now && ! isset($closed[$b->id.'|'.$date])) {
                    $n++;
                }
            }
            $out[$b->id] = $n;
        }

        return $out;
    }

    /**
     * The five products that sold most units and the five that earned most, in the week: from the order lines of the orders that count as sales. A
     * product's revenue is its lines after discount, less what was cancelled afterwards (the same figure as the sales-by-product report).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function topProducts(string $from, string $to): array
    {
        [$start, $end] = BusinessTime::dateRange($from, $to);
        $base = fn () => DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')->whereBetween('o.placed_at', [$start, $end])
            ->whereNotIn('o.status', SalesBasis::EXCLUDED_STATUSES)
            ->selectRaw('coalesce(oi.product_id::text, oi.sku) as pk, max(oi.name_en) as name_en, max(oi.name_ar) as name_ar,
                coalesce(sum(oi.quantity - oi.quantity_cancelled), 0) as units, coalesce(sum(oi.line_total - oi.unit_final_price * oi.quantity_cancelled), 0)::numeric(14,2) as revenue')
            ->groupByRaw('coalesce(oi.product_id::text, oi.sku)')->havingRaw('sum(oi.quantity - oi.quantity_cancelled) > 0')->limit(5);
        $shape = fn ($rows) => $rows->map(fn ($r) => ['name_en' => (string) $r->name_en, 'name_ar' => (string) ($r->name_ar ?: $r->name_en), 'units' => (int) $r->units,
            'revenue' => Money::of((string) $r->revenue)->toDecimal()])->values()->all();

        return [
            $shape($base()->orderByDesc('units')->orderByDesc('revenue')->orderBy('pk')->get()),
            $shape($base()->orderByDesc('revenue')->orderByDesc('units')->orderBy('pk')->get()),
        ];
    }

    /**
     * What needs attention next week, in the order an owner would act on it. Only things that exist are listed, each as a key (its words are in
     * lang/*\/owner_pack.php) with the number it is about and, where it helps, the branches it is about.
     *
     * @param  list<array<string, mixed>>  $rows  the per-branch lines
     * @return list<array<string, mixed>>
     */
    private function attention(array $rows, string $from, string $to): array
    {
        [$start, $end] = BusinessTime::dateRange($from, $to);
        $list = [];
        $branchNames = fn (array $picked) => array_values(array_map(fn (array $r) => $r['code'], $picked));

        $unclosed = array_sum(array_map(fn (array $r) => (int) $r['this']['unclosed_days'], $rows));
        if ($unclosed > 0) {
            $list[] = ['key' => 'unclosed_days', 'count' => $unclosed, 'branches' => $branchNames(array_filter($rows, fn (array $r) => $r['this']['unclosed_days'] > 0))];
        }
        $neverAccepted = array_sum(array_column(Orders::standing(null, $start, $end), 'never_accepted'));
        if ($neverAccepted > 0) {
            $list[] = ['key' => 'never_accepted', 'count' => $neverAccepted];
        }
        $openVariances = array_sum(Cash::openVariances(null));
        if ($openVariances > 0) {
            $list[] = ['key' => 'open_variances', 'count' => $openVariances];
        }
        $cash = array_reduce($rows, fn (Money $c, array $r) => $c->plus(Money::of((string) $r['this']['cash_with_collectors'])), Money::zero());
        if (! $cash->isZero()) {
            $list[] = ['key' => 'cash_with_collectors', 'count' => $cash->toDecimal(), 'branches' => $branchNames(array_filter($rows, fn (array $r) => ! Money::of((string) $r['this']['cash_with_collectors'])->isZero()))];
        }
        $claims = (int) DB::table('return_requests')->whereIn('status', ['requested', 'under_review'])->count();
        if ($claims > 0) {
            $list[] = ['key' => 'claims_waiting', 'count' => $claims];
        }
        $out = array_sum(array_map(fn (array $r) => (int) $r['this']['stock_out'], $rows));
        if ($out > 0) {
            $list[] = ['key' => 'stock_out', 'count' => $out, 'branches' => $branchNames(array_filter($rows, fn (array $r) => $r['this']['stock_out'] > 0))];
        }
        $dropped = array_filter($rows, function (array $r) {
            $change = self::change($r['this']['sales'], $r['prev']['sales']);

            return $change !== null && $change <= -self::SALES_DROP_PERCENT;
        });
        if ($dropped !== []) {
            $list[] = ['key' => 'sales_down', 'count' => count($dropped), 'branches' => $branchNames($dropped)];
        }
        $late = array_filter($rows, fn (array $r) => $r['this']['on_time_rate'] !== null && $r['this']['on_time_rate'] < self::ON_TIME_FLOOR_PERCENT);
        if ($late !== []) {
            $list[] = ['key' => 'late_deliveries', 'count' => count($late), 'branches' => $branchNames($late)];
        }

        return $list;
    }
}
