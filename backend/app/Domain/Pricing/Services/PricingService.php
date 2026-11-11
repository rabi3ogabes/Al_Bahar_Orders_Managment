<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Catalog\Models\ProductPriceHistory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantBranchSetting;
use App\Domain\Pricing\Events\PriceChanged;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes prices at any precedence level with validation, price history and audit.
 * Every change records actor, old/new regular & sale price, sale window and reason.
 */
class PricingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function setProductPrice(Product $product, ?string $price, ?string $sale, $saleStart = null, $saleEnd = null, ?string $reason = null): void
    {
        $this->validate($price, $sale, $saleStart, $saleEnd, $product->allow_sale_above_price, requirePrice: true);
        $this->apply($product, $product, null, null, $price, $sale, $saleStart, $saleEnd, $reason);
    }

    public function setVariantPrice(ProductVariant $variant, ?string $price, ?string $sale, $saleStart = null, $saleEnd = null, ?string $reason = null): void
    {
        $this->validate($price, $sale, $saleStart, $saleEnd, $variant->product->allow_sale_above_price, saleNeedsPrice: true);
        $this->apply($variant, $variant->product, $variant->id, null, $price, $sale, $saleStart, $saleEnd, $reason);
    }

    public function setProductBranchPrice(Product $product, string $branchId, ?string $price, ?string $sale, $saleStart = null, $saleEnd = null, ?string $reason = null): ProductBranchSetting
    {
        $this->validate($price, $sale, $saleStart, $saleEnd, $product->allow_sale_above_price, saleNeedsPrice: true);
        $row = ProductBranchSetting::firstOrNew(['product_id' => $product->id, 'branch_id' => $branchId]);
        $this->apply($row, $product, null, $branchId, $price, $sale, $saleStart, $saleEnd, $reason);

        return $row;
    }

    public function setVariantBranchPrice(ProductVariant $variant, string $branchId, ?string $price, ?string $sale, $saleStart = null, $saleEnd = null, ?string $reason = null): ProductVariantBranchSetting
    {
        $this->validate($price, $sale, $saleStart, $saleEnd, $variant->product->allow_sale_above_price, saleNeedsPrice: true);
        $row = ProductVariantBranchSetting::firstOrNew(['product_variant_id' => $variant->id, 'branch_id' => $branchId]);
        $this->apply($row, $variant->product, $variant->id, $branchId, $price, $sale, $saleStart, $saleEnd, $reason);

        return $row;
    }

    public function setBranchAvailability(Product $product, string $branchId, bool $enabled, bool $visible = true): ProductBranchSetting
    {
        $row = ProductBranchSetting::firstOrNew(['product_id' => $product->id, 'branch_id' => $branchId]);
        $old = $row->exists ? $row->only(['is_enabled', 'is_visible']) : [];
        $row->fill(['is_enabled' => $enabled, 'is_visible' => $visible, 'updated_by' => auth()->id()])->save();
        $this->audit->log('catalog.branch_availability_changed', $product, $old, ['is_enabled' => $enabled, 'is_visible' => $visible], branchId: $branchId);

        return $row;
    }

    public function setVariantBranchAvailability(ProductVariant $variant, string $branchId, bool $enabled): ProductVariantBranchSetting
    {
        $row = ProductVariantBranchSetting::firstOrNew(['product_variant_id' => $variant->id, 'branch_id' => $branchId]);
        $row->fill(['is_enabled' => $enabled, 'updated_by' => auth()->id()])->save();
        $this->audit->log('catalog.variant_branch_availability_changed', $variant, [], ['is_enabled' => $enabled], branchId: $branchId);

        return $row;
    }

    private function validate(?string $price, ?string $sale, $start, $end, bool $allowAbove, bool $requirePrice = false, bool $saleNeedsPrice = false): void
    {
        if ($requirePrice && $price === null) {
            throw DomainException::make('price_required');
        }
        if (($price !== null && Money::of($price)->isNegative()) || ($sale !== null && Money::of($sale)->isNegative())) {
            throw DomainException::make('price_negative');
        }
        if ($sale !== null && $saleNeedsPrice && $price === null) {
            // A sale price at a level needs a regular price at the same level (precedence is per level).
            throw DomainException::make('sale_requires_price');
        }
        if ($sale !== null && $price !== null && ! $allowAbove && Money::of($sale)->greaterThan(Money::of($price))) {
            throw DomainException::make('sale_above_price');
        }
        if ($start && $end && Carbon::parse($end)->lessThanOrEqualTo(Carbon::parse($start))) {
            throw DomainException::make('sale_window_invalid');
        }
    }

    private function apply($row, Product $product, ?string $variantId, ?string $branchId, ?string $price, ?string $sale, $start, $end, ?string $reason): void
    {
        DB::transaction(function () use ($row, $product, $variantId, $branchId, $price, $sale, $start, $end, $reason) {
            $old = ['price' => $row->price, 'sale_price' => $row->sale_price];
            $row->fill(['price' => $price, 'sale_price' => $sale, 'sale_starts_at' => $start, 'sale_ends_at' => $end]);
            if ($row instanceof Product || $row instanceof ProductBranchSetting || $row instanceof ProductVariantBranchSetting) {
                $row->updated_by = auth()->id();
            }
            $changed = $row->isDirty(['price', 'sale_price', 'sale_starts_at', 'sale_ends_at']);
            $row->save();
            if (! $changed) {
                return;
            }
            ProductPriceHistory::create([
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'branch_id' => $branchId,
                'old_price' => $old['price'], 'new_price' => $price,
                'old_sale_price' => $old['sale_price'], 'new_sale_price' => $sale,
                'sale_starts_at' => $start, 'sale_ends_at' => $end,
                'reason' => $reason,
                'changed_by' => auth()->id(),
            ]);
            $this->audit->log('catalog.price_changed', $product, $old, ['price' => $price, 'sale_price' => $sale], [
                'variant_id' => $variantId, 'level' => $branchId ? ($variantId ? 'variant_branch' : 'product_branch') : ($variantId ? 'variant' : 'product'),
                'reason' => $reason,
            ], branchId: $branchId);
            event(new PriceChanged($product->id, $variantId, $branchId, $old['price'], $price, $old['sale_price'], $sale));
        });
    }
}
