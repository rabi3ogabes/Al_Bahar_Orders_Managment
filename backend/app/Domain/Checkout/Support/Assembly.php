<?php

namespace App\Domain\Checkout\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Settings\Services\Settings;
use App\Support\Money;

/**
 * Paid assembly and installation at the customer's door (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). The one place that says what is
 * offered and what it costs, so the product page, the cart, the quote, the order and its refunds can never disagree.
 *
 *  - The dashboard switch (Settings > Checkout & fees > Assembly and installation, default on) turns the whole feature on or off.
 *  - A product offers it only when it has an assembly fee above zero (empty = not offered). The fee is per unit, in QAR.
 *  - The customer chooses it per cart line and it covers every unit of the line. Delivery orders only: it is done at the door, never at a pickup.
 *  - It is a flat service charge: promotions, deals and coupons do not touch it and the tax rate (a goods tax) does not apply to it,
 *    exactly like the delivery and service fees.
 *  - Once an order is placed the fee per unit is frozen on the order line, and the line owes fee x the units still on it: a unit that is
 *    removed, cancelled or returned takes its fee with it, to the dirham (a fee per unit has no rounding to argue about).
 */
final class Assembly
{
    /** The dashboard switch. */
    public static function enabled(): bool
    {
        return (bool) app(Settings::class)->get('checkout.assembly_enabled', true);
    }

    /** Days added to the promised date when an order has assembly (Settings; 0 = no change). */
    public static function extraDays(): int
    {
        return max(0, min(30, (int) app(Settings::class)->get('checkout.assembly_extra_days', 0)));
    }

    /** What one unit of the product costs to assemble, or null when it is not offered (switch off, no fee, or a zero fee). */
    public static function unitFee(Product $product): ?Money
    {
        if (! self::enabled() || $product->assembly_fee === null) {
            return null;
        }
        $fee = Money::of((string) $product->assembly_fee);

        return $fee->isPositive() ? $fee : null;
    }

    public static function offered(Product $product): bool
    {
        return self::unitFee($product) !== null;
    }

    /** What a line owes for assembly: the fee per unit times the units it still has. */
    public static function lineTotal(?Money $unitFee, int $units): Money
    {
        return $unitFee === null || $units <= 0 ? Money::zero() : $unitFee->times($units);
    }

    /** The fee per unit of a placed order line (null when the customer did not buy assembly for it). */
    public static function placedFee(OrderItem $item): ?Money
    {
        if ($item->assembly_fee === null) {
            return null;
        }
        $fee = Money::of((string) $item->assembly_fee);

        return $fee->isPositive() ? $fee : null;
    }

    /**
     * The assembly fee that goes back to the customer with $units units of a placed line (a return, a cancelled unit): the frozen fee per unit times
     * the units, never more than the line still owes. A line without assembly, or a line that already lost its units, gives zero.
     */
    public static function refundShare(OrderItem $item, int $units): Money
    {
        $fee = self::placedFee($item);
        if ($fee === null || $units <= 0) {
            return Money::zero();
        }

        return $fee->times($units)->min(Money::of((string) $item->assembly_total));
    }
}
