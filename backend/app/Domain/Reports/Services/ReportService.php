<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational & financial reports. Every report:
 *  - takes a business-date range interpreted in Asia/Qatar,
 *  - is restricted to the viewer's server-derived branch scope (an optional branch filter can only narrow it),
 *  - aggregates from immutable snapshots (order lines, ledgers), so history never shifts when catalog data changes.
 * Monetary aggregates are summed by PostgreSQL NUMERIC and returned as decimal strings.
 */
class ReportService
{
    public const REPORTS = [
        'sales_summary' => 'reports.view', 'sales_by_branch' => 'reports.view', 'sales_by_product' => 'reports.view', 'sales_by_category' => 'reports.view',
        'sales_by_brand' => 'reports.view', 'sales_by_payment_method' => 'reports.view', 'sales_by_fulfillment' => 'reports.view', 'order_status' => 'reports.view',
        'inventory_levels' => 'inventory.view', 'inventory_valuation' => 'inventory.view', 'stock_movements' => 'inventory.view', 'fast_slow_movers' => 'inventory.view',
        'transfers' => 'transfers.view', 'damaged_lost' => 'inventory.view',
        'customers' => 'customers.view', 'deliveries' => 'deliveries.view', 'pickups' => 'pickup.view', 'auctions' => 'auctions.view',
        'finance_collections' => 'payments.view', 'finance_refunds' => 'refunds.view', 'finance_outstanding' => 'payments.view', 'sadad_reconciliation' => 'reconciliation.view',
    ];

    public function __construct(private readonly AccessControl $access) {}

    /** @return array{columns: list<string>, rows: list<array>, summary: array} */
    public function run(User $user, string $report, string $from, string $to, ?string $branchId = null): array
    {
        $perm = self::REPORTS[$report] ?? throw DomainException::make('not_found', 404);
        if (! $this->access->allows($user, 'reports.view') || ! $this->access->allows($user, $perm)) {
            throw DomainException::make('forbidden', 403);
        }
        $scope = $this->access->scope($user, $perm)->narrow($branchId);
        [$start, $end] = BusinessTime::dateRange($from, $to);

        return $this->{lcfirst(str_replace('_', '', ucwords($report, '_')))}($scope, $start, $end);
    }

    // ── Sales ────────────────────────────────────────────────────────────────
    private function lines(BranchScope $s, $start, $end): Builder
    {
        $q = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereBetween('o.placed_at', [$start, $end])->whereNotIn('o.status', ['pending_payment']);

        return $s->apply($q, 'oi.branch_id');
    }

    private function salesSummary(BranchScope $s, $start, $end): array
    {
        $l = $this->lines($s, $start, $end)->selectRaw('count(distinct o.id) as orders, sum(oi.line_subtotal) as gross, sum(oi.discount_amount) as discounts,
            sum(oi.unit_final_price * oi.quantity_cancelled) as cancelled, sum(oi.unit_final_price * oi.quantity_returned) as returned')->first();
        $refunds = $s->apply(DB::table('refunds as r')->join('order_fulfillments as f', 'f.order_id', '=', 'r.order_id')
            ->where('r.status', 'processed')->whereBetween('r.processed_at', [$start, $end]), 'f.branch_id')->distinct()->sum('r.amount');
        $net = (float) $l->gross - (float) $l->discounts - (float) $l->cancelled - (float) $refunds;
        $orders = (int) $l->orders;

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'orders', 'value' => $orders], ['metric' => 'gross_sales', 'value' => $this->d($l->gross)],
            ['metric' => 'discounts', 'value' => $this->d($l->discounts)], ['metric' => 'cancelled', 'value' => $this->d($l->cancelled)],
            ['metric' => 'refunds', 'value' => $this->d($refunds)], ['metric' => 'net_sales', 'value' => $this->d($net)],
            ['metric' => 'average_order_value', 'value' => $this->d($orders ? $net / $orders : 0)],
        ], 'summary' => ['net_sales' => $this->d($net), 'orders' => $orders]];
    }

    private function salesByBranch(BranchScope $s, $start, $end): array
    {
        return $this->grouped($this->lines($s, $start, $end)->join('branches as b', 'b.id', '=', 'oi.branch_id'), 'b.code, b.name_en', ['b.code as branch', 'b.name_en as name']);
    }

    private function salesByProduct(BranchScope $s, $start, $end): array
    {
        return $this->grouped($this->lines($s, $start, $end), 'oi.sku, oi.name_en', ['oi.sku', 'oi.name_en as product'], 200);
    }

    private function salesByCategory(BranchScope $s, $start, $end): array
    {
        return $this->grouped($this->lines($s, $start, $end)->leftJoin('products as p', 'p.id', '=', 'oi.product_id')->leftJoin('category_translations as ct', fn ($j) => $j->on('ct.category_id', '=', 'p.category_id')->where('ct.locale', 'en')),
            'ct.name', ["coalesce(ct.name, 'Uncategorised') as category"]);
    }

    private function salesByBrand(BranchScope $s, $start, $end): array
    {
        return $this->grouped($this->lines($s, $start, $end)->leftJoin('products as p', 'p.id', '=', 'oi.product_id')->leftJoin('brands as br', 'br.id', '=', 'p.brand_id'),
            'br.name_en', ["coalesce(br.name_en, 'Unbranded') as brand"]);
    }

    private function salesByPaymentMethod(BranchScope $s, $start, $end): array
    {
        return $this->grouped($this->lines($s, $start, $end), 'o.payment_method', ['o.payment_method']);
    }

    private function salesByFulfillment(BranchScope $s, $start, $end): array
    {
        return $this->grouped($this->lines($s, $start, $end), 'o.fulfillment_type', ['o.fulfillment_type']);
    }

    private function orderStatus(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('orders as o')->join('order_fulfillments as f', 'f.order_id', '=', 'o.id')->whereBetween('o.placed_at', [$start, $end]), 'f.branch_id')
            ->selectRaw('o.status, count(distinct o.id) as orders')->groupBy('o.status')->orderByDesc('orders')->get();

        return ['columns' => ['status', 'orders'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => ['orders' => $rows->sum('orders')]];
    }

    // ── Inventory ────────────────────────────────────────────────────────────
    private function inventoryLevels(BranchScope $s): array
    {
        $rows = $s->apply(DB::table('branch_inventory as bi')->join('branches as b', 'b.id', '=', 'bi.branch_id')->join('product_variants as v', 'v.id', '=', 'bi.product_variant_id'), 'bi.branch_id')
            ->selectRaw("b.code as branch, v.sku, bi.on_hand, bi.reserved, (bi.on_hand - bi.reserved) as available, bi.minimum_stock,
                CASE WHEN bi.on_hand - bi.reserved <= 0 THEN 'out' WHEN bi.minimum_stock > 0 AND bi.on_hand - bi.reserved <= bi.minimum_stock THEN 'low' ELSE 'ok' END as state")
            ->orderBy('available')->limit(5000)->get();

        return ['columns' => ['branch', 'sku', 'on_hand', 'reserved', 'available', 'minimum_stock', 'state'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'summary' => ['low' => $rows->where('state', 'low')->count(), 'out' => $rows->where('state', 'out')->count()]];
    }

    private function inventoryValuation(BranchScope $s): array
    {
        $rows = $s->apply(DB::table('branch_inventory as bi')->join('branches as b', 'b.id', '=', 'bi.branch_id')->join('product_variants as v', 'v.id', '=', 'bi.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id'), 'bi.branch_id')
            ->selectRaw('b.code as branch, sum(bi.on_hand) as units, sum(bi.on_hand * coalesce(bi.average_cost, v.cost_price, p.cost_price, 0)) as cost_value,
                sum(bi.on_hand * coalesce(v.price, p.price)) as retail_value')->groupBy('b.code')->orderBy('b.code')->get();

        return ['columns' => ['branch', 'units', 'cost_value', 'retail_value'], 'rows' => $rows->map(fn ($r) => ['branch' => $r->branch, 'units' => (int) $r->units,
            'cost_value' => $this->d($r->cost_value), 'retail_value' => $this->d($r->retail_value)])->all(),
            'summary' => ['cost_value' => $this->d($rows->sum('cost_value')), 'retail_value' => $this->d($rows->sum('retail_value'))]];
    }

    private function stockMovements(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('stock_movements as m')->join('branches as b', 'b.id', '=', 'm.branch_id')->whereBetween('m.created_at', [$start, $end]), 'm.branch_id')
            ->selectRaw('b.code as branch, m.type, count(*) as movements, sum(m.quantity) as net_quantity')->groupBy('b.code', 'm.type')->orderBy('b.code')->get();

        return ['columns' => ['branch', 'type', 'movements', 'net_quantity'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => []];
    }

    private function fastSlowMovers(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('product_variants as v')->join('branch_inventory as bi', 'bi.product_variant_id', '=', 'v.id')
            ->leftJoin('stock_movements as m', fn ($j) => $j->on('m.product_variant_id', '=', 'v.id')->on('m.branch_id', '=', 'bi.branch_id')
                ->where('m.type', 'sale_deduction')->whereBetween('m.created_at', [$start, $end])), 'bi.branch_id')
            ->selectRaw('v.sku, sum(-coalesce(m.quantity, 0)) as units_sold, max(bi.on_hand) as on_hand')->groupBy('v.sku')->orderByDesc('units_sold')->limit(500)->get();

        return ['columns' => ['sku', 'units_sold', 'on_hand'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'summary' => ['fast' => $rows->take(10)->pluck('sku'), 'slow' => $rows->where('units_sold', 0)->pluck('sku')->take(20)->values()]];
    }

    private function transfers(BranchScope $s, $start, $end): array
    {
        $q = DB::table('stock_transfers as t')->join('stock_transfer_items as i', 'i.stock_transfer_id', '=', 't.id')->whereBetween('t.created_at', [$start, $end]);
        if (! $s->all) {
            $q->where(fn ($w) => $w->whereIn('t.source_branch_id', $s->branchIds)->orWhereIn('t.destination_branch_id', $s->branchIds));
        }
        $rows = $q->selectRaw('t.status, count(distinct t.id) as transfers, sum(i.quantity_dispatched) as dispatched, sum(i.quantity_received) as received, sum(i.quantity_damaged) as damaged')
            ->groupBy('t.status')->get();

        return ['columns' => ['status', 'transfers', 'dispatched', 'received', 'damaged'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => []];
    }

    private function damagedLost(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('stock_movements as m')->join('branches as b', 'b.id', '=', 'm.branch_id')->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->whereIn('m.type', ['damaged', 'lost'])->whereBetween('m.created_at', [$start, $end]), 'm.branch_id')
            ->selectRaw('b.code as branch, v.sku, m.type, sum(-m.quantity) as units, max(m.reason) as reason')->groupBy('b.code', 'v.sku', 'm.type')->get();

        return ['columns' => ['branch', 'sku', 'type', 'units', 'reason'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => ['units' => $rows->sum('units')]];
    }

    // ── Customers, delivery, pickup, auctions ────────────────────────────────
    private function customers(BranchScope $s, $start, $end): array
    {
        $orders = $s->apply(DB::table('orders as o')->join('order_fulfillments as f', 'f.order_id', '=', 'o.id')->whereNotIn('o.status', ['pending_payment', 'cancelled']), 'f.branch_id')
            ->selectRaw('o.user_id, min(o.placed_at) as first_order, count(distinct o.id) as orders, sum(distinct o.grand_total) as spend')->groupBy('o.user_id');
        $rows = DB::query()->fromSub($orders, 'c')->get();
        $inRange = $rows->filter(fn ($r) => $r->first_order >= $start->toDateTimeString() && $r->first_order <= $end->toDateTimeString());
        $active = $rows->filter(fn ($r) => true);
        $repeat = $rows->where('orders', '>', 1)->count();

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'new_customers', 'value' => $inRange->count()], ['metric' => 'customers_with_orders', 'value' => $active->count()],
            ['metric' => 'repeat_customers', 'value' => $repeat], ['metric' => 'repeat_rate_percent', 'value' => $rows->count() ? round($repeat * 100 / $rows->count(), 1) : 0],
            ['metric' => 'average_lifetime_value', 'value' => $this->d($rows->count() ? $rows->sum('spend') / $rows->count() : 0)],
            ['metric' => 'new_registrations', 'value' => DB::table('users')->where('type', 'customer')->whereBetween('created_at', [$start, $end])->count()],
        ], 'summary' => []];
    }

    private function deliveries(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('deliveries as d')->leftJoin('users as u', 'u.id', '=', 'd.driver_id')->leftJoin('delivery_providers as p', 'p.id', '=', 'd.delivery_provider_id')
            ->whereBetween('d.created_at', [$start, $end]), 'd.branch_id')
            ->selectRaw("coalesce(p.name, u.name, 'Unassigned') as executor, d.mode, count(*) as deliveries,
                sum(CASE WHEN d.status = 'delivered' THEN 1 ELSE 0 END) as delivered, sum(d.attempt_count) as attempts,
                sum(CASE WHEN d.status IN ('failed','returned','returning') THEN 1 ELSE 0 END) as failed,
                round(avg(extract(epoch FROM (d.delivered_at - d.picked_up_at)) / 60)::numeric, 1) as avg_minutes_on_road")
            ->groupBy('executor', 'd.mode')->orderByDesc('deliveries')->get();

        return ['columns' => ['executor', 'mode', 'deliveries', 'delivered', 'attempts', 'failed', 'avg_minutes_on_road'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => []];
    }

    private function pickups(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('pickup_reservations as p')->join('branches as b', 'b.id', '=', 'p.branch_id')->whereBetween('p.created_at', [$start, $end]), 'p.branch_id')
            ->selectRaw("b.code as branch, count(*) as pickups, sum(CASE WHEN p.status = 'collected' THEN 1 ELSE 0 END) as collected,
                round(avg(extract(epoch FROM (p.collected_at - p.ready_at)) / 3600)::numeric, 1) as avg_hours_waiting")
            ->groupBy('b.code')->get();

        return ['columns' => ['branch', 'pickups', 'collected', 'avg_hours_waiting'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => []];
    }

    private function auctions(BranchScope $s, $start, $end): array
    {
        $rows = DB::table('auction_lots as l')->join('auctions as a', 'a.id', '=', 'l.auction_id')->whereBetween('l.closed_at', [$start, $end])
            ->leftJoin('auction_winners as w', fn ($j) => $j->on('w.auction_lot_id', '=', 'l.id')->where('w.rank', 1))
            ->selectRaw("a.reference, l.lot_number, l.status, l.bid_count, l.current_price as winning_bid,
                CASE WHEN l.reserve_price IS NULL THEN 'n/a' WHEN l.current_price >= l.reserve_price THEN 'met' ELSE 'not_met' END as reserve, w.status as winner_status")
            ->orderBy('a.reference')->orderBy('l.lot_number')->get();

        return ['columns' => ['reference', 'lot_number', 'status', 'bid_count', 'winning_bid', 'reserve', 'winner_status'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'summary' => ['lots' => $rows->count(), 'sold' => $rows->where('status', 'sold')->count(), 'unpaid' => $rows->where('winner_status', 'defaulted')->count(),
                'avg_bids' => round($rows->avg('bid_count') ?? 0, 1), 'conversion_percent' => $rows->count() ? round($rows->where('winner_status', 'paid')->count() * 100 / $rows->count(), 1) : 0]];
    }

    // ── Finance ──────────────────────────────────────────────────────────────
    private function financeCollections(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('cash_collections as c')->join('branches as b', 'b.id', '=', 'c.branch_id')->join('users as u', 'u.id', '=', 'c.collected_by')
            ->whereBetween('c.collected_at', [$start, $end]), 'c.branch_id')
            ->selectRaw('b.code as branch, c.context, c.tender, u.name as collected_by, count(*) as collections, sum(c.amount_collected) as collected,
                sum(CASE WHEN c.handed_in_at IS NULL AND c.tender = \'cash\' THEN c.amount_collected ELSE 0 END) as not_handed_in')
            ->groupBy('b.code', 'c.context', 'c.tender', 'u.name')->get();

        return ['columns' => ['branch', 'context', 'tender', 'collected_by', 'collections', 'collected', 'not_handed_in'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'summary' => ['collected' => $this->d($rows->sum('collected'))]];
    }

    private function financeRefunds(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('refunds as r')->join('order_fulfillments as f', 'f.order_id', '=', 'r.order_id')->whereBetween('r.created_at', [$start, $end]), 'f.branch_id')
            ->selectRaw('r.status, r.method, count(distinct r.id) as refunds, sum(distinct r.amount) as amount')->groupBy('r.status', 'r.method')->get();

        return ['columns' => ['status', 'method', 'refunds', 'amount'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => []];
    }

    private function financeOutstanding(BranchScope $s, $start, $end): array
    {
        $rows = $s->apply(DB::table('orders as o')->join('order_fulfillments as f', 'f.order_id', '=', 'o.id')
            ->whereIn('o.payment_method', ['cod', 'pay_at_pickup'])->whereNotIn('o.status', ['cancelled'])->whereNotIn('o.payment_status', ['paid', 'refunded', 'partially_refunded'])
            ->whereBetween('o.placed_at', [$start, $end]), 'f.branch_id')
            ->selectRaw('o.number, o.payment_method, o.status, (o.grand_total - o.paid_total) as outstanding')->distinct()->orderBy('o.number')->get();

        return ['columns' => ['number', 'payment_method', 'status', 'outstanding'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => ['total' => $this->d($rows->sum('outstanding'))]];
    }

    private function sadadReconciliation(BranchScope $s, $start, $end): array
    {
        $rows = DB::table('payments as p')->where('p.provider', 'sadad')->whereBetween('p.created_at', [$start, $end])
            ->leftJoin('payment_transactions as t', fn ($j) => $j->on('t.payment_id', '=', 'p.id')->where('t.type', 'callback')->where('t.status', 'paid'))
            ->selectRaw("p.id, p.status, p.amount, p.captured_amount, p.refunded_amount, p.provider_reference,
                CASE WHEN p.status IN ('paid','partially_refunded','refunded') AND t.id IS NULL THEN 'missing_callback'
                     WHEN t.amount IS NOT NULL AND t.amount <> p.amount THEN 'amount_mismatch'
                     WHEN (p.metadata->>'late_capture') IS NOT NULL THEN 'late_capture' ELSE 'ok' END as check")
            ->orderBy('p.created_at')->get();

        return ['columns' => ['id', 'status', 'amount', 'captured_amount', 'refunded_amount', 'provider_reference', 'check'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'summary' => ['exceptions' => $rows->where('check', '!=', 'ok')->count(), 'captured' => $this->d($rows->sum('captured_amount'))]];
    }

    private function grouped(Builder $q, string $groupBy, array $select, int $limit = 100): array
    {
        $rows = $q->selectRaw(implode(', ', $select).', count(distinct o.id) as orders, sum(oi.quantity - oi.quantity_cancelled) as units,
            sum(oi.line_subtotal) as gross, sum(oi.discount_amount) as discounts, sum(oi.line_total - oi.unit_final_price * oi.quantity_cancelled) as net')
            ->groupByRaw($groupBy)->orderByDesc('net')->limit($limit)->get();
        $cols = array_map(fn ($c) => trim(str_contains($c, ' as ') ? substr($c, strrpos($c, ' as ') + 4) : substr($c, strrpos($c, '.') + 1)), $select);

        return ['columns' => [...$cols, 'orders', 'units', 'gross', 'discounts', 'net'],
            'rows' => $rows->map(fn ($r) => array_merge((array) $r, ['gross' => $this->d($r->gross), 'discounts' => $this->d($r->discounts), 'net' => $this->d($r->net)]))->all(),
            'summary' => ['net' => $this->d($rows->sum('net'))]];
    }

    private function d($v): string
    {
        return number_format((float) ($v ?? 0), 2, '.', '');
    }
}
