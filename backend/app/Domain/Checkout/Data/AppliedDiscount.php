<?php

namespace App\Domain\Checkout\Data;

use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;

final class AppliedDiscount
{
    /** @param array<string, Money> $allocations lineKey → amount */
    public function __construct(
        public readonly Promotion $promotion,
        public readonly ?Coupon $coupon,
        public readonly Money $amount,
        public readonly array $allocations = [],
        public readonly bool $freeDelivery = false,
        public ?Money $deliveryDiscount = null,
    ) {
        $this->deliveryDiscount ??= Money::zero();
    }

    public function label(?string $locale = null): string
    {
        return $this->promotion->localized('name', $locale);
    }

    public function toArray(): array
    {
        return [
            'promotion_id' => $this->promotion->id,
            'label' => $this->label(),
            'code' => $this->coupon?->code,
            'amount' => $this->amount->plus($this->deliveryDiscount)->jsonSerialize(),
            'free_delivery' => $this->freeDelivery,
        ];
    }
}
