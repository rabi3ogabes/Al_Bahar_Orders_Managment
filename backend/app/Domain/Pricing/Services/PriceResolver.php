<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantBranchSetting;
use App\Domain\Pricing\Data\Availability;
use App\Domain\Pricing\Data\EffectivePrice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Effective pricing & branch availability — the single authority used by catalog APIs, cart, checkout and orders.
 *
 * PRICE PRECEDENCE (first level that defines a regular price wins; its sale price applies if within its window):
 *   1. Variant + branch override   (product_variant_branch_settings.price)
 *   2. Product + branch override   (product_branch_settings.price)
 *   3. Variant global price        (product_variants.price)
 *   4. Product global price        (products.price)
 *
 * AVAILABILITY (checked BEFORE pricing; an unavailable item never falls back to another level's price):
 *   - product.status = active, variant.status = active, branch.status = active
 *   - product enabled in branch: available_in_all_branches unless a product_branch_settings row disables it,
 *     or (when not all branches) a product_branch_settings row with is_enabled = true exists
 *   - variant not disabled by product_variant_branch_settings.is_enabled = false
 *
 * Call prime() before resolving many variants to load all branch settings in two queries (no N+1).
 */
class PriceResolver
{
    /** @var array<string, ProductBranchSetting|null> keyed "productId|branchId" */
    private array $productSettings = [];

    /** @var array<string, ProductVariantBranchSetting|null> keyed "variantId|branchId" */
    private array $variantSettings = [];

    /** @param iterable<ProductVariant> $variants */
    public function prime(iterable $variants, ?string $branchId): static
    {
        if (! $branchId) {
            return $this;
        }
        $variants = collect($variants);
        $productIds = $variants->pluck('product_id')->unique()->values();
        $variantIds = $variants->pluck('id')->unique()->values();

        $ps = ProductBranchSetting::where('branch_id', $branchId)->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        foreach ($productIds as $pid) {
            $this->productSettings[$pid.'|'.$branchId] = $ps[$pid] ?? null;
        }
        $vs = ProductVariantBranchSetting::where('branch_id', $branchId)->whereIn('product_variant_id', $variantIds)->get()->keyBy('product_variant_id');
        foreach ($variantIds as $vid) {
            $this->variantSettings[$vid.'|'.$branchId] = $vs[$vid] ?? null;
        }

        return $this;
    }

    public function availability(ProductVariant $variant, ?Branch $branch): Availability
    {
        $product = $variant->product;
        if (! $product->isActive()) {
            return Availability::no('product_inactive');
        }
        if (! $variant->isActive()) {
            return Availability::no('variant_inactive');
        }
        if (! $branch) {
            return Availability::ok();
        }
        if (! $branch->isActive()) {
            return Availability::no('branch_inactive');
        }
        $ps = $this->productSetting($product, $branch->id);
        $enabled = $product->available_in_all_branches ? ($ps === null || $ps->is_enabled) : ($ps !== null && $ps->is_enabled);
        if (! $enabled) {
            return Availability::no('not_in_branch');
        }
        $vs = $this->variantSetting($variant, $branch->id);
        if ($vs !== null && ! $vs->is_enabled) {
            return Availability::no('not_in_branch');
        }

        return Availability::ok();
    }

    /** Returns null when the variant is not purchasable at the branch (never silently falls back). */
    public function resolve(ProductVariant $variant, ?Branch $branch, ?CarbonImmutable $at = null): ?EffectivePrice
    {
        if (! $this->availability($variant, $branch)->purchasable) {
            return null;
        }

        return $this->price($variant, $branch?->id, $at);
    }

    /** Pure precedence calculation (assumes availability was checked). */
    public function price(ProductVariant $variant, ?string $branchId, ?CarbonImmutable $at = null): EffectivePrice
    {
        $at ??= CarbonImmutable::now();
        $product = $variant->product;
        $currency = $product->currency ?? 'QAR';

        $levels = [];
        if ($branchId) {
            $levels['variant_branch'] = $this->variantSetting($variant, $branchId);
            $levels['product_branch'] = $this->productSetting($product, $branchId);
        }
        $levels['variant'] = $variant;
        $levels['product'] = $product;

        foreach ($levels as $source => $row) {
            if ($row === null || $row->price === null) {
                continue;
            }
            $regular = Money::of($row->price, $currency);
            $sale = null;
            if ($row->sale_price !== null && $this->windowActive($row->sale_starts_at, $row->sale_ends_at, $at)) {
                $candidate = Money::of($row->sale_price, $currency);
                // A "sale" never increases the customer price unless the product explicitly allows it.
                if ($candidate->lessThan($regular) || ($product->allow_sale_above_price && ! $candidate->equals($regular))) {
                    $sale = $candidate;
                }
            }

            return new EffectivePrice($regular, $sale, $source, $sale ? $row->sale_ends_at : null);
        }

        // products.price is NOT NULL, so this is unreachable; kept as a guard.
        return new EffectivePrice(Money::zero($currency), null, 'product');
    }

    /** Lowest effective final price across a product's purchasable variants (for "from" prices on cards). */
    public function fromPrice(Product $product, ?Branch $branch): ?EffectivePrice
    {
        /** @var Collection<int, ProductVariant> $variants */
        $variants = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();
        $best = null;
        foreach ($variants as $variant) {
            $variant->setRelation('product', $product);
            $price = $this->resolve($variant, $branch);
            if ($price && ($best === null || $price->final()->lessThan($best->final()))) {
                $best = $price;
            }
        }

        return $best;
    }

    private function windowActive(mixed $starts, mixed $ends, CarbonImmutable $at): bool
    {
        return ($starts === null || $at->greaterThanOrEqualTo($starts)) && ($ends === null || $at->lessThan($ends));
    }

    private function productSetting(Product $product, string $branchId): ?ProductBranchSetting
    {
        $key = $product->id.'|'.$branchId;
        if (! array_key_exists($key, $this->productSettings)) {
            $this->productSettings[$key] = ProductBranchSetting::where('product_id', $product->id)->where('branch_id', $branchId)->first();
        }

        return $this->productSettings[$key];
    }

    private function variantSetting(ProductVariant $variant, string $branchId): ?ProductVariantBranchSetting
    {
        $key = $variant->id.'|'.$branchId;
        if (! array_key_exists($key, $this->variantSettings)) {
            $this->variantSettings[$key] = ProductVariantBranchSetting::where('product_variant_id', $variant->id)->where('branch_id', $branchId)->first();
        }

        return $this->variantSettings[$key];
    }
}
