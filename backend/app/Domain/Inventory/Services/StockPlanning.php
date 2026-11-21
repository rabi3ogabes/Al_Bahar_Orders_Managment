<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Identity\Support\BranchScope;
use App\Domain\Inventory\Support\PlanRow;
use App\Domain\Reports\Services\StockLevels;
use App\Support\SearchNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "What do I have to order today?": every variant a branch holds (or has held), with its demand, days of cover, reorder point and a suggested order,
 * worked out by ONE query so the page, its counters and the CSV agree and the cost does not grow with the number of rows read by PHP.
 *
 *  - Demand = units SOLD per day in the demand window: orders placed in the window, not cancelled and not waiting for their online payment (the same
 *    definition as the dashboard's sales, SalesBasis), in the branch that fulfils them, less what was cancelled or returned. One grouped query for the
 *    whole catalogue, never one per item.
 *  - The days used to divide by are the days the item could be sold: a new item counts from the day it first appeared at the branch (never fewer than
 *    StockPlanningSettings::MIN_BASIS_DAYS, so one busy day does not look like a trend), and the days it has been out of stock in the window are left out.
 *  - One large order that is more than half of the window's units is counted like an ordinary order, so a single customer does not make the shelf look empty.
 *  - Days of cover = available / daily demand (available now plus what is already on order, for the reorder lanes).
 *  - Reorder point = daily demand x (lead days + safety days), never below the item's own minimum stock; a fixed number on the item replaces it.
 *  - Suggested order = enough to reach daily demand x (lead + safety + cycle days), but never less than twice the reorder point (an order is not tiny) and
 *    never less than the number of customers waiting, less what is available and already on order; a fixed order quantity on the item replaces it.
 *
 * Lanes (an item can be in several; its badge and its place in the list use the most urgent one): out of stock now (nothing available), running low
 * (under the low-cover days), below reorder point, slow / overstocked (over the slow-cover days, or nothing sold in a whole window).
 */
final class StockPlanning
{
    /** The lane filters the page offers: `order` is "order today" (out, low or reorder, with something to order). */
    public const LANES = ['order', 'out', 'low', 'reorder', 'slow', 'all'];

    private const LANE_SQL = [
        'order' => 'needs_order', 'out' => 'is_out', 'low' => 'is_low', 'reorder' => 'is_reorder', 'slow' => 'is_slow', 'all' => 'TRUE',
    ];

    /** Most rows one call returns (the page size is 25; the CSV is the big caller). */
    public const MAX_ROWS = 20000;

    /** @param array<string, int>|null $settings the numbers of {@see StockPlanningSettings::all()} (null = the saved ones) */
    public function __construct(private readonly ?array $settings = null) {}

    /**
     * How many items each lane holds, and how many customers are waiting for the ones that are out.
     *
     * @return array{order: int, out: int, low: int, reorder: int, slow: int, all: int, customers: int}
     */
    public function counts(BranchScope $scope, string $search = '', ?CarbonImmutable $now = null): array
    {
        return $this->view($scope, 'all', $search, 1, 0, $now)['counts'];
    }

    /**
     * One page of a lane, most urgent first: out of stock (most customers waiting, then longest out), then running low and below reorder point (fewest days of
     * cover first), then the slow movers (most overstocked first).
     *
     * @param  list<string>  $ids  only these stock rows (branch_inventory ids), e.g. the one a button was pressed on
     * @return list<PlanRow>
     */
    public function rows(BranchScope $scope, string $lane, string $search = '', int $limit = 25, int $offset = 0, ?CarbonImmutable $now = null, array $ids = []): array
    {
        return $this->view($scope, $lane, $search, $limit, $offset, $now, $ids)['rows'];
    }

    /**
     * The page and its counters from ONE statement, so they always describe the same moment (the numbers are worked out once, however many lists the
     * page shows).
     *
     * @param  list<string>  $ids
     * @return array{counts: array{order: int, out: int, low: int, reorder: int, slow: int, all: int, customers: int}, rows: list<PlanRow>}
     */
    public function view(BranchScope $scope, string $lane, string $search = '', int $limit = 25, int $offset = 0, ?CarbonImmutable $now = null, array $ids = []): array
    {
        $now ??= CarbonImmutable::now();
        $predicate = self::LANE_SQL[$lane] ?? self::LANE_SQL['all'];
        [$chain, $bindings] = $this->chain($scope, $search, $now, $ids);
        // Most urgent first. The same ORDER BY picks the page and keeps it in order inside the JSON.
        $urgency = "lane_rank ASC, CASE WHEN lane = 'out' THEN -waiting ELSE 0 END ASC,
            CASE lane WHEN 'out' THEN coalesce(extract(epoch FROM out_since), 1e10) WHEN 'slow' THEN -coalesce(position_days, 1e9) ELSE coalesce(position_days, 1e9) END ASC, id ASC";
        $tail = "SELECT
            (SELECT jsonb_build_object(
                'order', count(*) FILTER (WHERE needs_order), 'out', count(*) FILTER (WHERE is_out), 'low', count(*) FILTER (WHERE is_low),
                'reorder', count(*) FILTER (WHERE is_reorder), 'slow', count(*) FILTER (WHERE is_slow), 'all', count(*),
                'customers', (SELECT count(DISTINCT a.user_id) FROM stock_alerts a WHERE a.type = 'back_in_stock' AND a.notified_at IS NULL AND a.expires_at > ?
                    AND EXISTS (SELECT 1 FROM planned o WHERE o.is_out AND o.product_variant_id = a.product_variant_id AND (a.branch_id IS NULL OR a.branch_id = o.branch_id))))
                FROM planned) AS counts,
            (SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY {$urgency}), '[]'::jsonb) FROM (
                SELECT * FROM planned WHERE {$predicate} ORDER BY {$urgency} LIMIT ? OFFSET ?) t) AS page";
        $row = $this->read(fn () => DB::selectOne($chain.' '.$tail, [...$bindings, $now->toIso8601String(), max(1, min(self::MAX_ROWS, $limit)), max(0, $offset)]));

        $counts = (array) json_decode((string) $row->counts, true);
        $page = (array) json_decode((string) $row->page, true);

        return [
            'counts' => ['order' => (int) $counts['order'], 'out' => (int) $counts['out'], 'low' => (int) $counts['low'], 'reorder' => (int) $counts['reorder'],
                'slow' => (int) $counts['slow'], 'all' => (int) $counts['all'], 'customers' => (int) $counts['customers']],
            'rows' => array_values(array_map(fn (array $r) => PlanRow::fromRow((object) $r), $page)),
        ];
    }

    /**
     * Runs a read of the planning query with PostgreSQL's JIT compiler off for this transaction only: the query is wide and the compiler costs more than the
     * query itself (measured: 450 ms of 1,260 ms on 40,000 stock rows).
     *
     * @template T
     *
     * @param  callable(): T  $query
     * @return T
     */
    private function read(callable $query): mixed
    {
        return DB::transaction(function () use ($query) {
            DB::statement('SET LOCAL jit = off');

            return $query();
        });
    }

    /**
     * Wish-list count per variant (lists that hold the variant, or its whole product), for the variants of a page only.
     *
     * @param  list<string>  $variantIds
     * @return array<string, int> variant id => lists
     */
    public function wishlistCounts(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return DB::table('product_variants as v')
            ->join('wishlist_items as wi', fn ($j) => $j->on('wi.product_id', '=', 'v.product_id')->where(fn ($w) => $w->whereNull('wi.product_variant_id')->orWhereColumn('wi.product_variant_id', 'v.id')))
            ->whereIn('v.id', $variantIds)->groupBy('v.id')->selectRaw('v.id as variant_id, count(distinct wi.wishlist_id)::int as lists')
            ->pluck('lists', 'variant_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * What to prefill on a purchase order for these variants: the cost of each (the last order's cost, else the variant's, else the product's) and the
     * supplier that delivered them most recently (the one most of them came from).
     *
     * @param  list<string>  $variantIds
     * @return array{supplier_id: string|null, costs: array<string, string>}
     */
    public function purchaseDefaults(array $variantIds): array
    {
        if ($variantIds === []) {
            return ['supplier_id' => null, 'costs' => []];
        }
        $last = [];
        foreach (DB::table('purchase_order_items as poi')->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereIn('poi.product_variant_id', $variantIds)->whereIn('po.status', ['approved', 'partially_received', 'received', 'closed'])
            ->orderByDesc('po.created_at')->orderByDesc('poi.id')->limit(1000)->get(['poi.product_variant_id', 'poi.unit_cost', 'po.supplier_id']) as $row) {
            $last[$row->product_variant_id] ??= $row;
        }
        $fallback = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->whereIn('v.id', $variantIds)
            ->selectRaw('v.id, coalesce(v.cost_price, p.cost_price) as cost')->pluck('cost', 'id');
        $costs = [];
        foreach ($variantIds as $id) {
            $cost = isset($last[$id]) ? $last[$id]->unit_cost : ($fallback[$id] ?? null);
            $costs[$id] = $cost === null ? '' : rtrim(rtrim(number_format((float) $cost, 4, '.', ''), '0'), '.');
        }
        $suppliers = collect($last)->pluck('supplier_id')->countBy()->sortDesc();

        return ['supplier_id' => $suppliers->keys()->first() !== null ? (string) $suppliers->keys()->first() : null, 'costs' => $costs];
    }

    /**
     * The common table expressions that make every number, and their bindings in the order they appear.
     *
     * @param  list<string>  $ids
     * @return array{0: string, 1: list<mixed>}
     */
    private function chain(BranchScope $scope, string $search, CarbonImmutable $now, array $ids = []): array
    {
        $v = $this->settings ?? StockPlanningSettings::all();
        $window = (int) $v['window_days'];
        $lead = (int) $v['lead_days'];
        $safety = (int) $v['safety_days'];
        $cycle = (int) $v['cycle_days'];
        $low = (int) $v['low_days'];
        $slow = (int) $v['slow_days'];
        $min = StockPlanningSettings::MIN_BASIS_DAYS;
        $spikeMin = StockPlanningSettings::SPIKE_MIN_UNITS;
        $stamp = $now->toIso8601String();
        $since = $now->subDays($window)->toIso8601String();

        [$scopeOi, $bOi] = $this->within($scope, 'oi.branch_id');
        [$scopePo, $bPo] = $this->within($scope, 'po.branch_id');
        [$scopeAlert, $bAlert] = $this->within($scope, 'a.branch_id');
        [$scopeBi, $bBi] = $this->within($scope, 'bi.branch_id');
        [$searchSql, $bSearch] = $this->searching($search);
        $idsSql = $ids === [] ? '' : 'AND bi.id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
        $had = StockLevels::hadStockSql('bi');

        $sql = "WITH demand AS (
                SELECT d.branch_id, d.product_variant_id, sum(d.net)::int AS units, count(*)::int AS sale_lines, max(d.net)::int AS biggest
                FROM (
                    SELECT oi.branch_id, oi.product_variant_id, greatest(oi.quantity - oi.quantity_cancelled - oi.quantity_returned, 0) AS net
                    FROM orders o
                    JOIN order_items oi ON oi.order_id = o.id
                    JOIN order_fulfillments f ON f.id = oi.order_fulfillment_id
                    WHERE o.placed_at >= ? AND o.status NOT IN ('cancelled', 'pending_payment') AND f.status <> 'cancelled'
                      AND oi.product_variant_id IS NOT NULL AND {$scopeOi}
                ) d
                WHERE d.net > 0
                GROUP BY d.branch_id, d.product_variant_id
            ),
            incoming AS (
                SELECT po.branch_id, poi.product_variant_id, sum(poi.quantity_ordered - poi.quantity_received)::int AS qty, min(po.expected_at) AS next_at
                FROM purchase_orders po
                JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                WHERE po.status IN ('pending_approval', 'approved', 'partially_received') AND poi.quantity_ordered > poi.quantity_received AND {$scopePo}
                GROUP BY po.branch_id, poi.product_variant_id
            ),
            alerts_any AS (
                SELECT a.product_variant_id, count(DISTINCT a.user_id)::int AS n FROM stock_alerts a
                WHERE a.type = 'back_in_stock' AND a.notified_at IS NULL AND a.expires_at > ? AND a.branch_id IS NULL
                GROUP BY a.product_variant_id
            ),
            alerts_branch AS (
                SELECT a.product_variant_id, a.branch_id, count(DISTINCT a.user_id)::int AS n FROM stock_alerts a
                WHERE a.type = 'back_in_stock' AND a.notified_at IS NULL AND a.expires_at > ? AND a.branch_id IS NOT NULL AND {$scopeAlert}
                GROUP BY a.product_variant_id, a.branch_id
            ),
            base AS (
                SELECT bi.id, bi.branch_id, bi.product_variant_id, bi.on_hand, bi.reserved, bi.on_hand - bi.reserved AS available, bi.minimum_stock, bi.out_since,
                    bi.lead_time_days, bi.safety_days, bi.reorder_point AS rop_fixed, bi.reorder_quantity AS qty_fixed,
                    coalesce(d.units, 0) AS units, coalesce(d.sale_lines, 0) AS sale_lines, coalesce(d.biggest, 0) AS biggest,
                    coalesce(i.qty, 0) AS incoming, i.next_at,
                    coalesce(aa.n, 0) + coalesce(ab.n, 0) AS waiting,
                    greatest(1, ceil(extract(epoch FROM (?::timestamptz - bi.created_at)) / 86400.0))::int AS days_known,
                    CASE WHEN bi.on_hand - bi.reserved <= 0 AND bi.out_since IS NOT NULL
                        THEN least({$window}, greatest(0, ceil(extract(epoch FROM (?::timestamptz - bi.out_since)) / 86400.0)))::int ELSE 0 END AS out_days
                FROM branch_inventory bi
                LEFT JOIN demand d ON d.branch_id = bi.branch_id AND d.product_variant_id = bi.product_variant_id
                LEFT JOIN incoming i ON i.branch_id = bi.branch_id AND i.product_variant_id = bi.product_variant_id
                LEFT JOIN alerts_any aa ON aa.product_variant_id = bi.product_variant_id
                LEFT JOIN alerts_branch ab ON ab.product_variant_id = bi.product_variant_id AND ab.branch_id = bi.branch_id
                WHERE {$scopeBi} AND (bi.on_hand > 0 OR {$had}) {$idsSql} {$searchSql}
            ),
            history AS MATERIALIZED (
                SELECT b.*, least({$window}, b.days_known) AS history_days,
                    greatest({$min}, least({$window}, b.days_known) - b.out_days) AS selling_days,
                    (b.days_known < {$min}) AS little_history,
                    CASE WHEN b.sale_lines >= 2 AND b.units >= {$spikeMin} AND b.biggest * 2 > b.units
                        THEN b.units - b.biggest + (b.units - b.biggest)::numeric / (b.sale_lines - 1) ELSE b.units::numeric END AS adj_units
                FROM base b
            ),
            calc AS (
                SELECT h.*, h.adj_units / h.selling_days AS daily, (h.adj_units <> h.units) AS spiky,
                    coalesce(h.lead_time_days, {$lead}) AS lead, coalesce(h.safety_days, {$safety}) AS safety,
                    (h.lead_time_days IS NOT NULL) AS lead_is_fixed, (h.safety_days IS NOT NULL) AS safety_is_fixed,
                    h.available + h.incoming AS position
                FROM history h
            ),
            plan AS MATERIALIZED (
                SELECT c.*,
                    CASE WHEN c.daily > 0 THEN c.available / c.daily END AS cover_days,
                    CASE WHEN c.daily > 0 THEN c.position / c.daily END AS position_days,
                    coalesce(c.rop_fixed, greatest(ceil(c.daily * (c.lead + c.safety))::int, c.minimum_stock)) AS rop,
                    (c.rop_fixed IS NOT NULL) AS rop_is_fixed
                FROM calc c
            ),
            lanes AS (
                SELECT p.*,
                    (p.available <= 0) AS is_out,
                    (p.available > 0 AND p.daily > 0 AND p.position_days < {$low}) AS is_low,
                    (p.available > 0 AND p.rop > 0 AND p.position <= p.rop) AS is_reorder,
                    (p.available > 0 AND ((p.daily > 0 AND p.position_days > {$slow}) OR (p.units = 0 AND p.days_known >= {$window}))) AS is_slow,
                    greatest(ceil(p.daily * (p.lead + p.safety + {$cycle}))::int, p.rop * 2, p.waiting) AS target
                FROM plan p
            ),
            staged AS (
                SELECT l.*,
                    CASE WHEN l.qty_fixed IS NOT NULL THEN (CASE WHEN l.incoming > 0 AND l.position > l.rop THEN 0 ELSE l.qty_fixed END)
                         ELSE greatest(l.target - l.position, 0) END AS suggest,
                    (l.qty_fixed IS NOT NULL) AS qty_is_fixed,
                    CASE WHEN l.is_out THEN 'out' WHEN l.is_low THEN 'low' WHEN l.is_reorder THEN 'reorder' WHEN l.is_slow THEN 'slow' ELSE 'ok' END AS lane,
                    CASE WHEN l.is_out THEN 0 WHEN l.is_low THEN 1 WHEN l.is_reorder THEN 2 WHEN l.is_slow THEN 3 ELSE 4 END AS lane_rank
                FROM lanes l
            ),
            planned AS (
                SELECT s.*, ((s.is_out OR s.is_low OR s.is_reorder) AND s.suggest > 0) AS needs_order FROM staged s
            )";

        $bindings = [$since, ...$bOi, ...$bPo, $stamp, $stamp, ...$bAlert, $stamp, $stamp, ...$bBi, ...$ids, ...$bSearch];

        return [$sql, $bindings];
    }

    /** @return array{0: string, 1: list<string>} the SQL that keeps rows inside the branch scope, and its bindings (an empty scope reads nothing) */
    private function within(BranchScope $scope, string $column): array
    {
        if ($scope->all) {
            return ['TRUE', []];
        }
        if ($scope->branchIds === []) {
            return ['FALSE', []];
        }

        return ["{$column} IN (".implode(',', array_fill(0, count($scope->branchIds), '?')).')', $scope->branchIds];
    }

    /** @return array{0: string, 1: list<string>} */
    private function searching(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }
        $like = '%'.addcslashes($search, '%_\\').'%';
        $folded = SearchNormalizer::normalize($search);
        $foldedLike = '%'.addcslashes($folded, '%_\\').'%';

        return ['AND bi.product_variant_id IN (SELECT v.id FROM product_variants v WHERE v.sku ILIKE ? OR v.barcode = ?
                OR v.product_id IN (SELECT t.product_id FROM product_translations t WHERE t.name ILIKE ? OR t.search_text ILIKE ?))', [$like, $search, $like, $folded === '' ? $like : $foldedLike]];
    }
}
