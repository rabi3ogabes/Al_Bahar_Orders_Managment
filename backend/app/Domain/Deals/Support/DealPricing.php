<?php

namespace App\Domain\Deals\Support;

use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Support\Money;

/**
 * The price rule of a deal, as pure functions (no database): what one unit costs at the deal price. Money only through Money, rounded half-up to
 * the dirham-fraction (2 decimals), never below 0.01 and never above the normal price.
 *
 *  - percent      deal = normal × (100 − value) / 100
 *  - amount_off   deal = normal − value
 *  - fixed_price  deal = the product's `deal_price`, for every variant whose normal price is higher; a variant already cheaper gets no deal
 *  - a product's own `deal_price` overrides the computed price for that product, whatever the rule
 */
final class DealPricing
{
    public const MIN_PERCENT = 1;

    public const MAX_PERCENT = 95;

    /** The deal unit price for a unit whose normal (final) price is $normal, or null when the deal would not make it cheaper. */
    public static function unitPrice(Deal $deal, DealProduct $row, Money $normal): ?Money
    {
        $floor = Money::ofMinor(1, $normal->currency);
        if ($row->deal_price !== null) {
            $price = Money::of($row->deal_price, $normal->currency);
        } elseif ($deal->discount_type === Deal::FIXED_PRICE) {
            return null; // a fixed-price deal without a price for this product is incomplete: it never discounts
        } elseif ($deal->discount_type === Deal::PERCENT) {
            $keep = Money::ofMinor(10000 - Money::of($deal->discount_value)->minor)->toDecimal();
            $price = $normal->percent($keep);
        } else {
            $price = $normal->minus(Money::of($deal->discount_value, $normal->currency));
        }
        $price = $price->max($floor);

        return $price->lessThan($normal) ? $price : null;
    }

    /** "-30%": the saving as a whole percent of the normal price (at least 1 once anything is saved). */
    public static function percentOff(Money $normal, Money $deal): int
    {
        if ($normal->minor <= 0 || ! $deal->lessThan($normal)) {
            return 0;
        }

        return max(1, (int) round(($normal->minor - $deal->minor) * 100 / $normal->minor));
    }

    /** A value for the API: "30", "12.5", "50" (no trailing zeros). */
    public static function plain(string|float|int|null $value): string
    {
        $text = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }
}
