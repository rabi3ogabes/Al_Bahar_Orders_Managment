<?php

namespace App\Domain\Orders\Services;

use App\Support\Money;

/**
 * The money rules of an order adjustment, as pure functions (no database): what one line becomes when it is reduced, removed or swapped
 * for a substitute, and what the order totals are for a set of lines. Everything is in whole minor units (dirham), never floats.
 *
 * RULES (docs/api/ORDER_ADJUSTMENTS.md):
 *  - Reducing a line keeps the same share of its discount as the share of its units that stay (a fraction of a dirham is rounded in the
 *    customer's favour), and tax is worked out again on what is left at the line's own rate.
 *  - A substitute costs per unit at most what the original unit cost: min(its current price, the original unit price). It carries the
 *    original line's discount per unit scaled by that price ratio, so its net price per unit can never be higher than the original's.
 *  - Delivery and service fees are never raised or re-priced by a shortage (the customer is not penalised for it). The one fee that goes down is the
 *    extra delivery fee of a bulky product whose units are gone (OrderAdjustmentService::extraDeliveryAfter): it follows the units, and never rises.
 *  - Assembly and installation follows its line: the units that are gone take their assembly fee with them (fee per unit as placed x units left), a
 *    substitute carries none, and it never goes up.
 *  - The total never goes below zero, and loyalty points the new total cannot absorb are handed back (never wasted).
 */
final class AdjustmentMath
{
    /**
     * One order line as money: unit price actually charged, units, discount and tax. A line with 0 units is all zeros.
     *
     * @return array{quantity: int, unit: Money, subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    public static function line(Money $unit, int $quantity, Money $discount, string $taxRate): array
    {
        $quantity = max(0, $quantity);
        $subtotal = $unit->times($quantity);
        $discount = $quantity === 0 ? Money::zero($unit->currency) : $discount->min($subtotal)->max(Money::zero($unit->currency));
        $tax = $quantity === 0 ? Money::zero($unit->currency) : $subtotal->minus($discount)->percent($taxRate);

        return ['quantity' => $quantity, 'unit' => $unit, 'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $subtotal->minus($discount)->plus($tax)];
    }

    /**
     * The line after its quantity went from $from to $to (0 ≤ $to < $from): same unit price, the discount share of the units that stay.
     *
     * @return array{quantity: int, unit: Money, subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    public static function reduced(Money $unit, int $from, int $to, Money $discount, string $taxRate): array
    {
        return self::line($unit, $to, self::keptShare($discount, $from, $to), $taxRate);
    }

    /**
     * The substitute line for $replacedQuantity units of an original line that cost $originalUnit each with $originalDiscount in total.
     *
     * @return array{quantity: int, unit: Money, subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    public static function substitute(Money $originalUnit, int $replacedQuantity, Money $originalDiscount, Money $substituteCurrentUnit, int $quantity, string $taxRate): array
    {
        $unit = self::substituteUnitPrice($originalUnit, $substituteCurrentUnit);
        // The discount of the units it takes the place of, scaled by how much cheaper it is, both rounded up (the customer's way): never more net per unit than the original.
        $share = self::keptShare($originalDiscount, $replacedQuantity, $quantity);
        $scaled = $originalUnit->minor > 0 ? intdiv($share->minor * $unit->minor + $originalUnit->minor - 1, $originalUnit->minor) : 0;

        return self::line($unit, $quantity, Money::ofMinor($scaled, $unit->currency), $taxRate);
    }

    /** What one unit of a substitute costs the customer: never more than the unit it replaces. */
    public static function substituteUnitPrice(Money $originalUnit, Money $substituteCurrentUnit): Money
    {
        return $substituteCurrentUnit->min($originalUnit);
    }

    /** The part of $amount that belongs to the $to units of $from that stay, rounded up to the dirham (a fraction of a dirham goes the customer's way). */
    public static function keptShare(Money $amount, int $from, int $to): Money
    {
        if ($to <= 0 || $from <= 0 || ! $amount->isPositive()) {
            return Money::zero($amount->currency);
        }
        if ($to >= $from) {
            return $amount;
        }

        return Money::ofMinor(intdiv($amount->minor * $to + $from - 1, $from), $amount->currency);
    }

    /**
     * The order total from its parts: goods minus item discounts, plus delivery less its discount, service fee and tax, less loyalty points, never
     * below zero; the cash-on-delivery fee is added after that floor. Orders placed today have none ("0.00"): cash on delivery became free, but orders
     * placed before that still carry their own stored `fee_breakdown.cod_fee` and keep adding up exactly as they did.
     *
     * @param  array{subtotal: Money, item_discount: Money, delivery_fee: Money, delivery_discount: Money, service_fee: Money, cod_fee: Money, tax: Money, loyalty: Money, assembly?: Money}  $p
     *                                                                                                                                                                                            `assembly` is the paid assembly and installation of the lines (absent = none)
     */
    public static function grandTotal(array $p): Money
    {
        $base = $p['subtotal']->minus($p['item_discount'])->plus($p['delivery_fee'])->minus($p['delivery_discount'])->plus($p['service_fee'])->plus($p['tax'])->minus($p['loyalty']);
        if (isset($p['assembly'])) {
            $base = $base->plus($p['assembly']);
        }

        return ($base->isNegative() ? Money::zero($base->currency) : $base)->plus($p['cod_fee']);
    }

    /**
     * Loyalty points a smaller order can no longer absorb. The total before points is $payableBeforeLoyalty; points worth more than that would be wasted,
     * so the whole points that still fit stay applied and the rest go back to the customer.
     *
     * @return array{points: int, discount: Money, returned: int} points still applied, their value, points handed back
     */
    public static function loyaltyThatFits(int $redeemedPoints, Money $discount, Money $payableBeforeLoyalty): array
    {
        if ($redeemedPoints <= 0 || ! $discount->isPositive() || $discount->lessThan($payableBeforeLoyalty) || $discount->equals($payableBeforeLoyalty)) {
            return ['points' => $redeemedPoints, 'discount' => $discount, 'returned' => 0];
        }
        $payable = $payableBeforeLoyalty->isNegative() ? Money::zero($discount->currency) : $payableBeforeLoyalty;
        // Value of one point as it was redeemed on this order (the setting may have changed since).
        $perPoint = intdiv($discount->minor, $redeemedPoints);
        $keep = $perPoint > 0 ? min($redeemedPoints, intdiv($payable->minor, $perPoint)) : 0;

        return ['points' => $keep, 'discount' => Money::ofMinor($keep * $perPoint, $discount->currency), 'returned' => $redeemedPoints - $keep];
    }
}
