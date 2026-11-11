<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Pricing\Services\PriceResolver;
use Illuminate\Support\Collection;

/**
 * Builds the API representations of products (cards & details) with server-resolved prices and stock flags.
 * Batch-loads branch settings and inventory so a page of products costs a constant number of queries.
 */
class CatalogPresenter
{
    private const STOCK_DISPLAY_CAP = 10;

    public function __construct(private readonly PriceResolver $prices) {}

    /** @param Collection<int, Product> $products */
    public function cards(Collection $products, ?Branch $branch): array
    {
        $variants = $products->flatMap(fn (Product $p) => $p->variants->each(fn ($v) => $v->setRelation('product', $p)));
        $this->prices->prime($variants, $branch?->id);
        $stock = $this->stockMap($variants->pluck('id')->all(), $branch?->id);

        return $products->map(fn (Product $p) => $this->card($p, $branch, $stock))->values()->all();
    }

    public function card(Product $p, ?Branch $branch, array $stock = []): array
    {
        $best = null;
        $inStock = false;
        foreach ($p->variants as $v) {
            $v->setRelation('product', $p);
            $price = $this->prices->resolve($v, $branch);
            if ($price && ($best === null || $price->final()->lessThan($best->final()))) {
                $best = $price;
            }
            $inStock = $inStock || (($stock[$v->id] ?? 0) > 0);
        }
        $image = $p->images->firstWhere('product_variant_id', null) ?? $p->images->first();
        $badges = array_values(array_filter([
            $p->is_new_arrival ? 'new' : null,
            $best && $best->discountPercent() > 0 ? 'sale' : null,
            $p->is_bestseller ? 'bestseller' : null,
            $p->is_featured ? 'featured' : null,
        ]));

        return [
            'id' => $p->id,
            'slug' => $p->trans('slug'),
            'sku' => $p->sku,
            // The one variant to add from a product card (null when the shopper must choose, e.g. colour or size).
            'variant_id' => $p->variants->count() === 1 ? $p->variants->first()->id : null,
            'name' => $p->trans('name'),
            'short_description' => $p->trans('short_description'),
            'brand' => $p->brand ? ['id' => $p->brand->id, 'name' => $p->brand->localized('name')] : null,
            'image' => $image ? ['url' => $image->url(), 'thumb_url' => $image->url(640), 'alt' => $image->alt() ?? $p->trans('name')] : null,
            'price' => $best?->toArray(),
            'rating' => ['average' => round((float) $p->rating_average, 1), 'count' => (int) $p->rating_count],
            'badges' => $badges,
            'in_stock' => $inStock,
            'purchasable' => $best !== null,
            'has_variants' => $p->variants->count() > 1,
        ];
    }

    public function detail(Product $p, ?Branch $branch): array
    {
        $p->loadMissing(['translations', 'brand', 'category.translations', 'images', 'videos', 'warranty',
            'variants' => fn ($q) => $q->where('status', 'active')->with('attributeValues.attribute', 'images'),
            'related' => fn ($q) => $q->active()->with(['translations', 'brand', 'images', 'variants' => fn ($v) => $v->where('status', 'active')])->limit(12)]);
        $variants = $p->variants->each(fn ($v) => $v->setRelation('product', $p));
        $this->prices->prime($variants, $branch?->id);
        $stock = $this->stockMap($variants->pluck('id')->all(), $branch?->id);

        $attributes = $variants->flatMap->attributeValues->unique('id')->groupBy('product_attribute_id')
            ->map(function ($values) {
                $attr = $values->first()->attribute;

                return ['code' => $attr->code, 'name' => $attr->localized('name'), 'type' => $attr->type,
                    'values' => $values->sortBy('sort_order')->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->localized('value'), 'color_hex' => $v->color_hex])->values()];
            })->values();

        $variantRows = $variants->map(function (ProductVariant $v) use ($branch, $stock) {
            $price = $this->prices->resolve($v, $branch);

            return [
                'id' => $v->id, 'sku' => $v->sku, 'name' => $v->displayName(),
                'attribute_value_ids' => $v->attributeValues->pluck('id')->values(),
                'price' => $price?->toArray(),
                'available_in_branch' => $price !== null,
                'in_stock' => ($stock[$v->id] ?? 0) > 0,
                'available_quantity' => min(self::STOCK_DISPLAY_CAP, max(0, $stock[$v->id] ?? 0)),
                'image_url' => $v->images->first()?->url(640),
            ];
        })->values();

        $default = $variants->firstWhere('is_default', true) ?? $variants->first();
        $purchasable = $variantRows->contains('available_in_branch', true);
        $inStock = $variantRows->contains('in_stock', true);
        $locale = app()->getLocale();

        return array_merge($this->card($p, $branch, $stock), [
            'description' => $p->trans('description'),
            'material' => $p->trans('material'),
            'category' => $p->category ? ['id' => $p->category->id, 'slug' => $p->category->slug, 'name' => $p->category->trans('name')] : null,
            'tags' => $p->tags ?? [],
            'specifications' => collect($p->specifications ?? [])->map(fn ($s) => [
                'label' => $s['label_'.$locale] ?? $s['label_en'] ?? '', 'value' => $s['value_'.$locale] ?? $s['value_en'] ?? '',
            ])->values(),
            'dimensions' => ($p->length || $p->width || $p->height) ? ['length' => (float) $p->length, 'width' => (float) $p->width, 'height' => (float) $p->height, 'unit' => $p->dimension_unit] : null,
            'weight' => $p->weight ? ['value' => (float) $p->weight, 'unit' => $p->weight_unit] : null,
            'warranty' => $p->warranty ? ['duration_months' => $p->warranty->duration_months, 'provider' => $p->warranty->provider, 'terms' => $p->warranty->localized('terms')] : null,
            'requires_assembly' => $p->requires_assembly,
            'assembly_service_available' => $p->assembly_service_available,
            'is_oversized' => $p->is_oversized,
            'images' => $p->images->map(fn ($i) => ['url' => $i->url(), 'thumb_url' => $i->url(640), 'alt' => $i->alt() ?? $p->trans('name'), 'variant_id' => $i->product_variant_id])->values(),
            'videos' => $p->videos->map(fn ($v) => ['url' => $v->publicUrl(), 'source' => $v->source, 'title' => $v->{'title_'.$locale} ?: $v->title_en])->values(),
            'attributes' => $attributes,
            'variants' => $variantRows,
            'default_variant_id' => $default?->id,
            'availability' => ['branch_id' => $branch?->id, 'purchasable' => $purchasable && $inStock,
                'reason' => ! $purchasable ? 'not_in_branch' : (! $inStock ? 'out_of_stock' : null)],
            'related' => $this->cards($p->related, $branch),
            'share_url' => rtrim(config('app.url'), '/').'/products/'.$p->slug,
            'seo' => ['title' => $p->trans('seo_title') ?? $p->trans('name'), 'description' => $p->trans('seo_description') ?? $p->trans('short_description')],
        ]);
    }

    /** @return array<string, int> variantId → available units (at the branch, or summed across branches) */
    public function stockMap(array $variantIds, ?string $branchId): array
    {
        if (! $variantIds) {
            return [];
        }

        return BranchInventory::whereIn('product_variant_id', $variantIds)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! $branchId, fn ($q) => $q->whereHas('branch', fn ($b) => $b->where('status', 'active')))
            ->selectRaw('product_variant_id, sum(on_hand - reserved) as available')->groupBy('product_variant_id')
            ->pluck('available', 'product_variant_id')->map(fn ($v) => (int) $v)->all();
    }
}
