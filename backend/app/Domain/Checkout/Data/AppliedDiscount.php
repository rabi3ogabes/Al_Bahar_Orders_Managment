<?php

namespace App\Domain\Checkout\Data;

use App\Domain\Deals\Models\Deal;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;

/**
 * One discount on a quote: a promotion / coupon (`source` "promotion") or the deal prices of one deal (`source` "deal": no promotion, no coupon).
 */
final class AppliedDiscount
{
    public const PROMOTION = 'promotion';

    public const DEAL = 'deal';

    /** @param array<string, Money> $allocations lineKey → amount */
    public function __construct(
        public readonly ?Promotion $promotion,
        public readonly ?Coupon $coupon,
        public readonly Money $amount,
        public readonly array $allocations = [],
        public readonly bool $freeDelivery = false,
        public ?Money $deliveryDiscount = null,
        public readonly ?Deal $deal = null,
    ) {
        $this->deliveryDiscount ??= Money::zero();
    }

    /**
     * The deal prices of one deal as a discount: `$amount` is what the customer saves on its units, split per line in `$allocations`.
     *
     * @param  array<string, Money>  $allocations  lineKey → amount
     */
    public static function forDeal(Deal $deal, Money $amount, array $allocations): self
    {
        return new self(null, null, $amount, $allocations, false, null, $deal);
    }

    public function source(): string
    {
        return $this->deal !== null ? self::DEAL : self::PROMOTION;
    }

    /** The id of whatever made the discount (the deal or the promotion): what a quote hash is built from. */
    public function sourceId(): string
    {
        return (string) ($this->deal?->id ?? $this->promotion?->id);
    }

    public function label(?string $locale = null): string
    {
        return (string) ($this->deal?->localized('name', $locale) ?? $this->promotion?->localized('name', $locale));
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source(),
            'promotion_id' => $this->promotion?->id,
            'deal_id' => $this->deal?->id,
            'label' => $this->label(),
            'code' => $this->coupon?->code,
            'amount' => $this->amount->plus($this->deliveryDiscount)->jsonSerialize(),
            'free_delivery' => $this->freeDelivery,
        ];
    }
}
