<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Storefront product listing: filters, sorting and SQL-computed effective prices.
 *
 * The `min_price` / `min_regular` subselects mirror PriceResolver's precedence in SQL so that price filters and
 * price sorting are done by PostgreSQL (one query, paginated) and agree with the prices returned to the client.
 */
class ProductQuery
{
    public function __construct(private readonly ProductSearch $search) {}

    /** @return Builder<Product> */
    public function build(array $f, ?string $branchId): Builder
    {
        $q = Product::query()->active()->select('products.*')
            ->with(['translations', 'brand', 'images' => fn ($i) => $i->limit(50), 'variants' => fn ($v) => $v->where('status', 'active')])
            ->visibleInBranch($branchId)
            ->whereExists(fn ($s) => $s->from('product_variants as av')->whereColumn('av.product_id', 'products.id')->where('av.status', 'active'));

        [$finalSql, $regularSql, $bindings] = $this->priceExpressions($branchId);
        $q->selectRaw("({$finalSql}) as min_price", $bindings)->selectRaw("({$regularSql}) as min_regular", $bindings);

        if (! empty($f['category_id']) && ($category = Category::find($f['category_id']))) {
            $q->whereIn('products.category_id', $category->descendantAndSelfIds());
        }
        if (! empty($f['brand_id'])) {
            $q->whereIn('products.brand_id', (array) $f['brand_id']);
        }
        foreach ($this->groupAttributeValues((array) ($f['attribute_values'] ?? [])) as $valueIds) {
            // AND across attributes, OR within one attribute (e.g. (Grey OR Beige) AND Linen).
            $q->whereExists(fn ($s) => $s->from('product_variants as fv')->join('product_variant_values as pvv', 'pvv.product_variant_id', '=', 'fv.id')
                ->whereColumn('fv.product_id', 'products.id')->where('fv.status', 'active')->whereIn('pvv.product_attribute_value_id', $valueIds));
        }
        foreach (['featured' => 'is_featured', 'new_arrival' => 'is_new_arrival', 'bestseller' => 'is_bestseller'] as $flag => $col) {
            if (! empty($f[$flag])) {
                $q->where('products.'.$col, true);
            }
        }
        if (isset($f['price_min']) && $f['price_min'] !== '') {
            $q->whereRaw("({$finalSql}) >= ?", [...$bindings, $f['price_min']]);
        }
        if (isset($f['price_max']) && $f['price_max'] !== '') {
            $q->whereRaw("({$finalSql}) <= ?", [...$bindings, $f['price_max']]);
        }
        if (! empty($f['on_sale'])) {
            $q->whereRaw("({$finalSql}) < ({$regularSql})", [...$bindings, ...$bindings]);
        }
        if (! empty($f['in_stock'])) {
            $q->whereExists(fn ($s) => $s->from('branch_inventory as bi')->join('product_variants as sv', 'sv.id', '=', 'bi.product_variant_id')
                ->whereColumn('sv.product_id', 'products.id')->where('sv.status', 'active')->whereRaw('bi.on_hand - bi.reserved > 0')
                ->when($branchId, fn ($w) => $w->where('bi.branch_id', $branchId)));
        }

        $term = trim((string) ($f['q'] ?? ''));
        $sort = $f['sort'] ?? ($term !== '' ? 'relevance' : '-created_at');
        if ($term !== '') {
            $this->search->apply($q, $term, $sort === 'relevance');
        }
        match ($sort) {
            'price' => $q->orderByRaw("({$finalSql}) asc nulls last", $bindings),
            '-price' => $q->orderByRaw("({$finalSql}) desc nulls last", $bindings),
            '-rating' => $q->orderByDesc('products.rating_average')->orderByDesc('products.rating_count'),
            '-popularity' => $q->orderByDesc('products.sales_count'),
            'relevance' => null,
            default => $q->orderByDesc('products.published_at'),
        };

        return $q->orderBy('products.id');
    }

    /** @return array{0: string, 1: string, 2: list<mixed>} SQL for min final price & min regular price over purchasable variants */
    public function priceExpressions(?string $branchId): array
    {
        $saleActive = fn (string $t) => "{$t}.sale_price IS NOT NULL AND {$t}.sale_price < {$t}.price AND ({$t}.sale_starts_at IS NULL OR {$t}.sale_starts_at <= now()) AND ({$t}.sale_ends_at IS NULL OR {$t}.sale_ends_at > now())";
        $levelFinal = fn (string $t) => "CASE WHEN {$saleActive($t)} THEN {$t}.sale_price ELSE {$t}.price END";

        if ($branchId) {
            $final = "CASE WHEN vbs.price IS NOT NULL THEN {$levelFinal('vbs')} WHEN pbs.price IS NOT NULL THEN {$levelFinal('pbs')} WHEN v.price IS NOT NULL THEN {$levelFinal('v')} ELSE {$levelFinal('p')} END";
            $regular = 'COALESCE(vbs.price, pbs.price, v.price, p.price)';
            $from = 'FROM product_variants v JOIN products p ON p.id = v.product_id
                LEFT JOIN product_variant_branch_settings vbs ON vbs.product_variant_id = v.id AND vbs.branch_id = ?
                LEFT JOIN product_branch_settings pbs ON pbs.product_id = p.id AND pbs.branch_id = ?
                WHERE v.product_id = products.id AND v.status = \'active\' AND (vbs.is_enabled IS NULL OR vbs.is_enabled)';

            return ["SELECT min({$final}) {$from}", "SELECT min({$regular}) {$from}", [$branchId, $branchId]];
        }
        $final = "CASE WHEN v.price IS NOT NULL THEN {$levelFinal('v')} ELSE {$levelFinal('p')} END";
        $from = "FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.product_id = products.id AND v.status = 'active'";

        return ["SELECT min({$final}) {$from}", "SELECT min(COALESCE(v.price, p.price)) {$from}", []];
    }

    private function groupAttributeValues(array $valueIds): array
    {
        $valueIds = array_values(array_filter(array_map('intval', $valueIds)));
        if (! $valueIds) {
            return [];
        }

        return DB::table('product_attribute_values')->whereIn('id', $valueIds)->get(['id', 'product_attribute_id'])
            ->groupBy('product_attribute_id')->map(fn ($g) => $g->pluck('id')->all())->values()->all();
    }
}
