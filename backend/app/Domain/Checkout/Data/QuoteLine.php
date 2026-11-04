<?php

namespace App\Domain\Checkout\Data;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Pricing\Data\EffectivePrice;
use App\Support\Money;

/** One priced line allocated to one branch. A cart line may be split into several QuoteLines across branches. */
final class QuoteLine
{
    public Money $discount;

    public Money $tax;

    public function __construct(
        public readonly string $key,               // stable key: variantId|branchId
        public readonly ProductVariant $variant,
        public readonly Branch $branch,
        public readonly int $quantity,
        public readonly EffectivePrice $price,
    ) {
        $this->discount = Money::zero();
        $this->tax = Money::zero();
    }

    public function subtotal(): Money
    {
        return $this->price->final()->times($this->quantity);
    }

    public function total(): Money
    {
        return $this->subtotal()->minus($this->discount)->plus($this->tax);
    }

    public function productId(): string
    {
        return $this->variant->product_id;
    }

    public function categoryId(): ?string
    {
        return $this->variant->product->category_id;
    }

    public function isOversized(): bool
    {
        return (bool) $this->variant->product->is_oversized;
    }
}
