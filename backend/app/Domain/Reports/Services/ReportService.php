<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational & financial reports. Every report:
 *  - takes a business-date range interpreted in Asia/Qatar,
 *  - is restricted to the viewer's server-derived branch scope (an optional branch filter can only narrow it),
 *  - aggregates from immutable snapshots (order lines, ledgers), so history never shifts when catalog data changes.
 * Monetary aggregates are summed by PostgreSQL NUMERIC and returned as decimal strings.
 *
 * What counts as a sale is defined once, in {@see SalesBasis}: an order that is not cancelled and not waiting for its online payment, on the day it
 * was placed (shop time). The dashboard tiles and chart read the same definition, so the figures agree.
 *
 * Net sales (the sales_summary report) is counted from the order lines, so it never includes delivery fees or tax, and it is:
 *     gross item value  -  line discounts  -  items cancelled after the order was placed  -  refunds NOT already explained by the lines
 * A refund raised by a cancellation or by an item change (reasons order_cancelled and order_adjustment) repays value the lines already dropped, so
 * counting it again would take the same money off twice; only the other refunds (a return, a goodwill refund) are taken off here. Orders cancelled
 * as a whole are not sales at all, so neither their lines nor their cancellation refund appear. "Booked sales" is the dashboard's figure
 * (grand total of those orders, delivery and tax included) and is shown beside it so the two can be reconciled.
 */
class ReportService
{
    public const REPORTS = [
        'sales_summary' => 'reports.view', 'sales_by_branch' => 'reports.view', 'sales_by_product' => 'reports.view', 'sales_by_category' => 'reports.view',
        'sales_by_brand' => 'reports.view', 'sales_by_payment_method' => 'reports.view', 'sales_by_fulfillment' => 'reports.view', 'order_status' => 'reports.view',
        'sales_assembly' => 'reports.view',
        'inventory_levels' => 'inventory.view', 'inventory_valuation' => 'inventory.view', 'stock_movements' => 'inventory.view', 'fast_slow_movers' => 'inventory.view',
        'transfers' => 'transfers.view', 'damaged_lost' => 'inventory.view',
        'customers' => 'customers.view', 'deliveries' => 'deliveries.view', 'pickups' => 'pickup.view', 'auctions' => 'auctions.view',
        'finance_collections' => 'payments.view', 'finance_refunds' => 'refunds.view', 'finance_outstanding' => 'payments.view', 'sadad_reconciliation' => 'reconciliation.view',
    ];

    /**
     * The reports that can be narrowed to "orders with assembly" ({@see AssemblySales::onlyParts()}). The assembly report itself is always about assembly, so it is not listed.
     */
    public const ASSEMBLY_FILTER = ['sales_summary', 'sales_by_branch', 'sales_by_product', 'sales_by_category', 'sales_by_brand', 'sales_by_payment_method', 'sales_by_fulfillment', 'order_status'];

    /** The stock list shows the emptiest rows first and stops here; the low / out counts above it cover every row. */
    public const LEVEL_ROWS = 5000;

    /** Refund reasons whose money the order lines have already dropped (cancelled quantities, items changed by the branch). */
    public const REFUNDS_ALREADY_IN_LINES = ['order_cancelled', 'order_adjustment'];

    /** How many rows of the stock list are returned (a property so a test can shrink it). */
    protected int $levelRows = self::LEVEL_ROWS;

    public function __construct(private readonly AccessControl $access) {}

    /**
     * @param  bool  $assemblyOnly  only the branch parts that have paid assembly (the sales reports in {@see self::ASSEMBLY_FILTER}; any other report ignores it)
     * @return array{columns: list<string>, rows: list<array>, summary: array}
     */
    public function run(User $user, string $report, string $from, string $to, ?string $branchId = null, bool $assemblyOnly = false): array
    {
        $perm = self::REPORTS[$report] ?? throw DomainException::make('not_found', 404);
        if (! $this->access->allows($user, 'reports.view') || ! $this->access->allows($user, $perm)) {
            throw DomainException::make('forbidden', 403);
        }
        $scope = $this->access->scope($user, $perm)->narrow($branchId);
        [$start, $end] = BusinessTime::dateRange($from, $to);

        return $this->{lcfirst(str_replace('_', '', ucwords($report, '_')))}($scope, $start, $end, $assemblyOnly && in_array($report, self::ASSEMBLY_FILTER, true));
    }

    // ── Sales ────────────────────────────────────────────────────────────────
    /** Order lines of the orders that count as sales (see SalesBasis), limited to the viewer's branches. */
    private function lines(BranchScope $s, $start, $end, bool $assemblyOnly = false): Builder
    {
        $q = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereBetween('o.placed_at', [$start, $end])->whereNotIn('o.status', SalesBasis::EXCLUDED_STATUSES);
        if ($assemblyOnly) {
            AssemblySales::onlyParts($q, 'oi.order_fulfillment_id');
        }

        return $s->apply($q, 'oi.branch_id');
    }

    /**
     * Refunds processed in the window that are NOT already explained by the order lines (see the class comment), as the viewer's share: a refund of an order
     * that several branches fulfilled is divided in the same proportions as the sale itself. The division is {@see SalesBasis::refunds()}, the one the daily
     * close uses, so the two cannot disagree. With `$assemblyOnly`, only the refunds of orders that have a part with paid assembly.
     */
    private function refundsOutsideLines(BranchScope $s, $start, $end, bool $assemblyOnly = false): string
    {
        // The orders that have a part with paid assembly AT ONE OF THE VIEWER'S BRANCHES (the same judgement as the lines above): a split order whose assembly sits at
        // another branch is not an assembly order for this viewer, so a refund of it is not taken off their narrowed net sales.
        $onlyOrders = $assemblyOnly ? AssemblySales::onlyParts($s->apply(DB::table('order_fulfillments as af')->select('af.order_id'), 'af.branch_id'), 'af.id') : null;

        return SalesBasis::refunds($s, $start, $end, self::REFUNDS_ALREADY_IN_LINES, $onlyOrders)['amount']->toDecimal();
    }

    private function salesSummary(BranchScope $s, $start, $end, bool $assemblyOnly = false): array
    {
        $l = $this->lines($s, $start, $end, $assemblyOnly)->selectRaw('coalesce(sum(oi.line_subtotal), 0) as gross, coalesce(sum(oi.discount_amount), 0) as discounts,
            coalesce(sum(oi.unit_final_price * oi.quantity_cancelled), 0)::numeric(14,2) as cancelled')->first();
        $gross = Money::of((string) $l->gross);
        $discounts = Money::of((string) $l->discounts);
        $cancelled = Money::of((string) $l->cancelled);
        $refunds = Money::of($this->refundsOutsideLines($s, $start, $end, $assemblyOnly));
        $booked = $assemblyOnly ? AssemblySales::bookedOfAssemblyOrders($s, $start, $end) : SalesBasis::totals($s, $start, $end);
        $net = $gross->minus($discounts)->minus($cancelled)->minus($refunds);
        $orders = $booked['orders']; // the dashboard's count: orders with a live part in the viewer's branches
        $average = $orders > 0 ? Money::ofMinor((int) round($net->minor / $orders)) : Money::zero();
        // Paid assembly is a service charge outside the item lines above (see AssemblySales): its own rows, so net sales keeps its meaning.
        $assembly = AssemblySales::totals($s, $start, $end);

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'orders', 'value' => $orders], ['metric' => 'booked_sales', 'value' => $booked['total']->toDecimal()],
            ['metric' => 'gross_sales', 'value' => $gross->toDecimal()],
            ['metric' => 'discounts', 'value' => $discounts->toDecimal()], ['metric' => 'cancelled', 'value' => $cancelled->toDecimal()],
            ['metric' => 'refunds', 'value' => $refunds->toDecimal()], ['metric' => 'net_sales', 'value' => $net->toDecimal()],
            ['metric' => 'average_order_value', 'value' => $average->toDecimal()],
            ['metric' => 'assembly_jobs', 'value' => $assembly['jobs']], ['metric' => 'assembly_units', 'value' => $assembly['units']],
            ['metric' => 'assembly_gross', 'value' => $assembly['gross']->toDecimal()], ['metric' => 'assembly_refunded', 'value' => $assembly['refunded']->toDecimal()],
            ['metric' => 'assembly_net', 'value' => $assembly['net']->toDecimal()],
        ], 'summary' => ['net_sales' => $net->toDecimal(), 'orders' => $orders, 'booked_sales' => $booked['total']->toDecimal()]];
    }

    /**
     * Paid assembly per branch (see {@see AssemblySales} for what each column means). Always about assembly, so the "orders with assembly" switch changes nothing here.
     */
    private function salesAssembly(BranchScope $s, $start, $end): array
    {
        $rows = AssemblySales::perBranch($s, $start, $end);
        $total = AssemblySales::totals($s, $start, $end);

        return ['columns' => ['branch', 'name', 'jobs', 'units', 'returned_units', 'gross', 'refunded', 'net'],
            'rows' => array_map(fn (array $r) => ['branch' => $r['branch'], 'name' => $r['name'], 'jobs' => $r['jobs'], 'units' => $r['units'], 'returned_units' => $r['returned_units'],
                'gross' => $r['gross']->toDecimal(), 'refunded' => $r['refunded']->toDecimal(), 'net' => $r['net']->toDecimal()], $rows),
            'summary' => ['jobs' => $total['jobs'], 'units' => $total['units'], 'gross' => $total['gross']->toDecimal(), 'refunded' => $total['refunded']->toDecimal(), 'net' => $total['net']->toDecimal()]];
    }

    private function salesByBranch(BranchScope $s, $start, $end, bool $assemblyOnly = false): array
    {
        return $this->grouped($this->lines($s, $start, $end, $assemblyOnly)->join('branches as b', 'b.id', '=', 'oi.branch_id'), 'b.code, b.name_en', ['b.code as branch', 'b.name_en as name']);
    }

    private function salesByProduct(BranchScope $s, $start, $end, bool $assemblyOnly = false): array
    {
        return $this->grouped($this->lines($s, $start, $end, $assemblyOnly), 'oi.sku, oi.name_en', ['oi.sku', 'oi.name_en as product'], 200);
    }

    private function salesByCategory(BranchScope $s, $start, $end, bool $assemblyOnly = false): array
    {
        return $this->grouped($this->lines($s, $start, $end, $assemblyOnly)->leftJoin('products as p', 'p.id', '=', 'oi.product_id')->leftJoin('category_translations as ct', fn ($j) => $j->on('ct.category_id', '=', 'p.category_id')->where('ct.locale', 'en')),
            'ct.name', ["coalesce(ct.name, 'Uncategorised') as category"]);
    }

    private function salesByBrand(BranchScope $s, $start, $end, bool $assemblyOnly = false): array
    {
        return $this->grouped($this->lines($s, $start, $end, $assemblyOnly)->leftJoin('products as p', 'p.id', '=', 'oi.product_id')->leftJoin('brands as br', 'br.id', '=', 'p.brand_id'),
            'br.name_en', ["coalesce(br.name_en, 'Unbranded') as brand"]);
    }

    private function salesByPaymentMethod(BranchScope $s, $start, $end, bool $assemblyOnly = false): array
    {
        return $this->grouped($this->lines($s, $start, $end, $assemblyOnly), 'o.payment_method', ['o.payment_method']);
    }

    private function salesByFulfillment(BranchScope $s, $start, $end, bool $assemblyOnly = false): array
    {
        return $this->grouped($this->lines($s, $start, $end, $assemblyOnly), 'o.fulfillment_type', ['o.fulfillment_type']);
    }

    private function orderStatus(BranchScope $s, $start, $end, bool $assemblyOnly = false): array
    {
        $q = DB::table('orders as o')->join('order_fulfillments as f', 'f.order_id', '=', 'o.id')->whereBetween('o.placed_at', [$start, $end]);
        if ($assemblyOnly) {
            AssemblySales::onlyParts($q, 'f.id');
        }
        $rows = $s->apply($q, 'f.branch_id')
            ->selectRaw('o.status, count(distinct o.id) as orders')->groupBy('o.status')->orderByDesc('orders')->get();

        return ['columns' => ['status', 'orders'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => ['orders' => $rows->sum('orders')]];
    }

    // ── Inventory ────────────────────────────────────────────────────────────
    private function inventoryLevels(BranchScope $s): array
    {
        $scoped = fn () => $s->apply(DB::table('branch_inventory as bi')->join('branches as b', 'b.id', '=', 'bi.branch_id')->join('product_variants as v', 'v.id', '=', 'bi.product_variant_id'), 'bi.branch_id');
        // Same meaning as the dashboard tile (StockLevels): an item a branch never held is not a stock position, so it is not listed or counted.
        [$low, $lowBindings] = StockLevels::lowSql('bi');
        $threshold = StockLevels::defaultThreshold();
        $rows = $scoped()->whereRaw(StockLevels::hadStockSql('bi'))
            ->selectRaw("b.code as branch, v.sku, bi.on_hand, bi.reserved, (bi.on_hand - bi.reserved) as available, bi.minimum_stock,
                CASE WHEN bi.on_hand - bi.reserved <= 0 THEN 'out' WHEN {$low} THEN 'low' ELSE 'ok' END as state", $lowBindings)
            ->orderBy('available')->orderBy('b.code')->orderBy('v.sku')->limit($this->levelRows)->get();
        // The counts come from SQL over every row: the list above is capped, the summary never is.
        $counts = StockLevels::counts($scoped());

        return ['columns' => ['branch', 'sku', 'on_hand', 'reserved', 'available', 'minimum_stock', 'state'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'summary' => ['low' => $counts['low'], 'out' => $counts['out'], 'low_stock_default' => $threshold]];
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
        // Sales are totalled per branch and variant FIRST, then joined to the stock rows: joining raw movements to stock would repeat a branch's
        // on-hand figure once per movement. On hand is the SUM over the viewer's branches (it used to be the largest single branch).
        $sold = DB::table('stock_movements as m')->where('m.type', 'sale_deduction')->whereBetween('m.created_at', [$start, $end])
            ->selectRaw('m.branch_id, m.product_variant_id, sum(-m.quantity) as units')->groupBy('m.branch_id', 'm.product_variant_id');
        $rows = $s->apply(DB::table('branch_inventory as bi')->join('product_variants as v', 'v.id', '=', 'bi.product_variant_id')
            ->leftJoinSub($sold, 'sold', fn ($j) => $j->on('sold.branch_id', '=', 'bi.branch_id')->on('sold.product_variant_id', '=', 'bi.product_variant_id')), 'bi.branch_id')
            ->selectRaw('v.sku, coalesce(sum(sold.units), 0) as units_sold, sum(bi.on_hand) as on_hand')->groupBy('v.sku')->orderByDesc('units_sold')->orderBy('v.sku')->limit(500)->get();

        return ['columns' => ['sku', 'units_sold', 'on_hand'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'summary' => ['fast' => $rows->take(10)->pluck('sku')->all(), 'slow' => $rows->where('units_sold', 0)->pluck('sku')->take(20)->values()->all()]];
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
        // Everything is counted in SQL, one row per customer inside the database and ONE row back: nothing the size of the customer base is loaded
        // into PHP. A customer's lifetime value is what they spent with the viewer's branches (their share of each order, see SalesBasis), counted
        // once per order: orders of the same amount are separate orders. The per-customer figures come from the customer-value rollup (rebuilt every
        // half hour, see CustomerValue): adding up every order the company ever took on each request would take seconds.
        $r = DB::query()->fromSub(app(CustomerValue::class)->rollup($s), 'c')->selectRaw('count(*) as customers, count(*) filter (where c.orders > 1) as repeat,
            count(*) filter (where c.first_order_at >= ? and c.first_order_at <= ?) as new_customers, coalesce(sum(c.spend), 0)::numeric(14,2) as spend', [$start, $end])->first();
        $customers = (int) $r->customers;
        $repeat = (int) $r->repeat;
        $average = $customers > 0 ? Money::ofMinor((int) round(Money::of((string) $r->spend)->minor / $customers)) : Money::zero();

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'new_customers', 'value' => (int) $r->new_customers], ['metric' => 'customers_with_orders', 'value' => $customers],
            ['metric' => 'repeat_customers', 'value' => $repeat], ['metric' => 'repeat_rate_percent', 'value' => $customers ? round($repeat * 100 / $customers, 1) : 0],
            ['metric' => 'average_lifetime_value', 'value' => $average->toDecimal()],
            // Sign-ups belong to no branch: only someone who sees every branch is shown the company-wide count.
            ...($s->all ? [['metric' => 'new_registrations', 'value' => DB::table('users')->where('type', 'customer')->whereBetween('created_at', [$start, $end])->count()]] : []),
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
        // A refund is one row whatever the number of branches that fulfilled its order (it used to be joined to every fulfillment and then
        // de-duplicated by amount, which merged two different refunds of the same amount into one). The branch scope is a filter on its order.
        $q = DB::table('refunds as r')->whereBetween('r.created_at', [$start, $end]);
        if (! $s->all) {
            $q->whereIn('r.order_id', $s->apply(DB::table('order_fulfillments as f')->select('f.order_id'), 'f.branch_id'));
        }
        $rows = $q->selectRaw('r.status, r.method, count(*) as refunds, sum(r.amount) as amount')->groupBy('r.status', 'r.method')->orderBy('r.status')->orderBy('r.method')->get();

        return ['columns' => ['status', 'method', 'refunds', 'amount'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => []];
    }

    private function financeOutstanding(BranchScope $s, $start, $end): array
    {
        $open = $s->apply(DB::table('orders as o')->join('order_fulfillments as f', 'f.order_id', '=', 'o.id')
            ->whereIn('o.payment_method', ['cod', 'pay_at_pickup'])->whereNotIn('o.status', ['cancelled'])->whereNotIn('o.payment_status', ['paid', 'refunded', 'partially_refunded'])
            ->whereBetween('o.placed_at', [$start, $end]), 'f.branch_id')
            ->selectRaw('o.number, o.payment_method, o.status, (o.grand_total - o.paid_total) as outstanding')->distinct();
        // The list shows the first 1,000 orders by number; the total is added up in SQL over all of them, so a long list never changes the total.
        $rows = (clone $open)->orderBy('o.number')->limit(1000)->get();
        $total = DB::query()->fromSub($open, 'o')->sum('outstanding');

        return ['columns' => ['number', 'payment_method', 'status', 'outstanding'], 'rows' => $rows->map(fn ($r) => (array) $r)->all(), 'summary' => ['total' => $this->d($total)]];
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
