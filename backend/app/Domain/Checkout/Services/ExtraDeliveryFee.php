<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Data\DeliveryExtra;
use App\Domain\Checkout\Data\QuoteLine;
use App\Support\Money;

/**
 * The money rule of the extra delivery fee per product (docs/api/CHECKOUT_FEES_V2.md section 3), in one place for the quote, the auction checkout
 * and the order adjustments. Pure functions, whole minor units, never floats.
 *
 *  - `per_unit`: the fee is charged for every unit of the product in the fulfillment (each big box).
 *  - `per_line`: the fee is charged once for the product in the fulfillment, whatever the quantity (1 or more).
 *  - Several variants of one product in the same fulfillment are one product: their quantities are added first.
 *  - Nothing is charged for 0 units or a fee of 0.
 */
final class ExtraDeliveryFee
{
    public const PER_UNIT = 'per_unit';

    public const PER_LINE = 'per_line';

    public const MODES = [self::PER_UNIT, self::PER_LINE];

    /** The biggest fee an admin may set, in QAR (the same limit in the product editor, the admin API and the catalogue import). */
    public const MAX = 100000;

    /** The amount charged for $quantity units of one product whose fee is $fee. */
    public static function amount(Money $fee, string $mode, int $quantity): Money
    {
        if ($quantity <= 0 || ! $fee->isPositive()) {
            return Money::zero($fee->currency);
        }

        return $mode === self::PER_LINE ? $fee : $fee->times($quantity);
    }

    /**
     * What a fulfillment's lines owe, one entry per product (in the order the products first appear in the lines), only for products with a fee.
     *
     * @param  list<QuoteLine>  $lines  the lines of ONE fulfillment
     * @return list<DeliveryExtra>
     */
    public static function forLines(array $lines): array
    {
        $byProduct = [];
        foreach ($lines as $line) {
            $product = $line->variant->product;
            $fee = Money::of((string) $product->extra_delivery_fee);
            if (! $fee->isPositive()) {
                continue;
            }
            $key = $line->productId();
            $byProduct[$key] ??= ['line' => $line, 'quantity' => 0];
            $byProduct[$key]['quantity'] += $line->quantity;
        }

        $extras = [];
        foreach ($byProduct as $productId => $group) {
            $product = $group['line']->variant->product;
            $fee = Money::of((string) $product->extra_delivery_fee);
            $mode = in_array($product->extra_delivery_fee_mode, self::MODES, true) ? $product->extra_delivery_fee_mode : self::PER_UNIT;
            $english = (string) ($product->trans('name', 'en') ?? '');
            $extras[] = new DeliveryExtra((string) $productId, $group['line']->branch->id, $english, (string) ($product->trans('name', 'ar') ?? $english), $mode,
                $group['quantity'], $fee, self::amount($fee, $mode, $group['quantity']));
        }

        return $extras;
    }

    /** @param  list<DeliveryExtra>  $extras */
    public static function total(array $extras): Money
    {
        return array_reduce($extras, fn (Money $carry, DeliveryExtra $e) => $carry->plus($e->amount), Money::zero());
    }
}
