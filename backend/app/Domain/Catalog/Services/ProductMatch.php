<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;

/**
 * What a scanned code resolved to. [variant] is the exact variant when the code (or the product's only variant) names one,
 * and null when the code is a product-level code of a product with several variants (the shopper must choose).
 * [via]: variant_barcode | product_barcode | variant_sku | product_sku | deep_link | url | id | slug.
 */
final readonly class ProductMatch
{
    public function __construct(public Product $product, public ?ProductVariant $variant, public string $via) {}

    public function needsChoice(): bool
    {
        return $this->variant === null;
    }
}
