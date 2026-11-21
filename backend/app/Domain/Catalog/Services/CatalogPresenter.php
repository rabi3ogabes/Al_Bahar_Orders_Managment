<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\Services\ExtraDeliveryFee;
use App\Domain\Checkout\Support\Assembly;
use App\Domain\Deals\Services\DealBlocks;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Pricing\Services\PriceResolver;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Builds the API representations of products (cards & details) with server-resolved prices and stock flags.
 * Batch-loads branch settings and inventory so a page of products costs a constant number of queries.
 */
class CatalogPresenter
{
    private const STOCK_DISPLAY_CAP = 10;

    public function __construct(private readonly PriceResolver $prices, private readonly DealBlocks $deals) {}

    /**
     * @param  Collection<int, Product>  $products
     * @param  ?User  $user  the signed-in customer, so a card's `deal` block can say how many units of the deal they may still buy
     */
    public function cards(Collection $products, ?Branch $branch, ?User $user = null): array
    {
        $variants = $products->flatMap(fn (Product $p) => $p->variants->each(fn ($v) => $v->setRelation('product', $p)));
        $this->prices->prime($variants, $branch?->id);
        $stock = $this->stockMap($variants->pluck('id')->all(), $branch?->id);

        $deals = $this->deals->load($products->pluck('id'), $user);

        return $products->map(fn (Product $p) => $this->card($p, $branch, $stock, $user, $deals))->values()->all();
    }

    /**
     * @param  ?array<string, array<string, mixed>>  $deals  the deal entries of a page of products (DealBlocks::load); null = look this product up
     */
    public function card(Product $p, ?Branch $branch, array $stock = [], ?User $user = null, ?array $deals = null): array
    {
        $best = null;
        $inStock = false;
        $normalPrices = [];
        foreach ($p->variants as $v) {
            $v->setRelation('product', $p);
            $price = $this->prices->resolve($v, $branch);
            if ($price) {
                $normalPrices[] = $price;
            }
            if ($price && ($best === null || $price->final()->lessThan($best->final()))) {
                $best = $price;
            }
            $inStock = $inStock || (($stock[$v->id] ?? 0) > 0);
        }
        $entry = ($deals ?? $this->deals->load([$p->id], $user))[$p->id] ?? null;
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
            // An extra delivery fee for bulky items (string money like "50.00", "0.00" = none) and how it is charged: `per_unit` for every unit,
            // `per_line` once whatever the quantity. Clients show a small note on the product page when the fee is above zero.
            'extra_delivery_fee' => Money::of((string) $p->extra_delivery_fee)->toDecimal(),
            'extra_delivery_fee_mode' => in_array($p->extra_delivery_fee_mode, ExtraDeliveryFee::MODES, true) ? $p->extra_delivery_fee_mode : ExtraDeliveryFee::PER_UNIT,
            // Assembly and installation at the door: the fee per unit (string money like "75.00") when the product offers it and the dashboard switch is on,
            // otherwise null. Delivery orders only; the customer chooses it per cart line and the quote charges it.
            'assembly_fee' => Assembly::unitFee($p)?->toDecimal(),
            // The countdown offer this product is in (docs/api/DEALS.md section 5), or null. `price` above stays the normal price.
            'deal' => $entry ? $this->deals->block($entry, $normalPrices) : null,
        ];
    }

    public function detail(Product $p, ?Branch $branch, ?User $user = null): array
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

        $dealEntry = $this->deals->load([$p->id], $user)[$p->id] ?? null;
        $variantRows = $variants->map(function (ProductVariant $v) use ($branch, $stock, $dealEntry) {
            $price = $this->prices->resolve($v, $branch);

            return [
                'id' => $v->id, 'sku' => $v->sku, 'name' => $v->displayName(),
                'attribute_value_ids' => $v->attributeValues->pluck('id')->values(),
                'price' => $price?->toArray(),
                // What this variant costs while the product's deal is on (null when it gets no deal): the card's `deal.deal_price` is the cheapest of these.
                'deal_price' => $dealEntry && $price ? $this->deals->variantPrice($dealEntry, $price)?->jsonSerialize() : null,
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

        return array_merge($this->card($p, $branch, $stock, $user, $dealEntry ? [$p->id => $dealEntry] : []), [
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
            'assembly_service_available' => $p->assembly_service_available || Assembly::offered($p),
            // Days the branch needs before this product can leave (made to order); 0 = ready now. It moves the delivery date.
            'lead_time_days' => (int) $p->lead_time_days,
            'is_oversized' => $p->is_oversized,
            'images' => $p->images->map(fn ($i) => ['url' => $i->url(), 'thumb_url' => $i->url(640), 'alt' => $i->alt() ?? $p->trans('name'), 'variant_id' => $i->product_variant_id])->values(),
            'videos' => $p->videos->map(fn ($v) => ['url' => $v->publicUrl(), 'source' => $v->source, 'title' => $v->{'title_'.$locale} ?: $v->title_en])->values(),
            'attributes' => $attributes,
            'variants' => $variantRows,
            'default_variant_id' => $default?->id,
            'availability' => ['branch_id' => $branch?->id, 'purchasable' => $purchasable && $inStock,
                'reason' => ! $purchasable ? 'not_in_branch' : (! $inStock ? 'out_of_stock' : null)],
            'related' => $this->cards($p->related, $branch, $user),
            'upsell' => app(UpsellService::class)->forProduct($p, $branch),
            'share_url' => rtrim(config('app.url'), '/').'/products/'.($p->trans('slug') ?: $p->id),
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
