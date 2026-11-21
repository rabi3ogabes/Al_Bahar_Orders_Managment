<?php

namespace App\Domain\Checkout\Data;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\Support\Assembly;
use App\Domain\Deals\Data\LineDeal;
use App\Domain\Pricing\Data\EffectivePrice;
use App\Support\Money;

/** One priced line allocated to one branch. A cart line may be split into several QuoteLines across branches. */
final class QuoteLine
{
    public Money $discount;

    public Money $tax;

    /** What a deal did to this line (units at the deal price, why fewer than all); null when no deal covers the product. Set by DealEngine. */
    public ?LineDeal $deal = null;

    /** The fee per unit of the assembly the customer chose for this line (null = none, not offered, or not a delivery). Set by QuoteService; never from the client. */
    public ?Money $assemblyFee = null;

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

    /** The saving of the units that got the deal price (zero without a deal). Already part of `discount`. */
    public function dealSavings(): Money
    {
        return $this->deal?->savings() ?? Money::zero();
    }

    /** Units a promotion may still discount: the ones that did not get a deal price. */
    public function promotionQuantity(): int
    {
        return $this->quantity - ($this->deal?->units ?? 0);
    }

    public function total(): Money
    {
        return $this->subtotal()->minus($this->discount)->plus($this->tax);
    }

    /** What this line costs for assembly: the fee per unit times its units (zero without assembly). */
    public function assemblyTotal(): Money
    {
        return Assembly::lineTotal($this->assemblyFee, $this->quantity);
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
