<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What each branch part of a split order is worth, so money that belongs to the whole order (the cash a driver collects, a refund when one
 * part is cancelled) can be divided between its parts fairly and EXACTLY: the shares always add up to the amount that was divided.
 *
 * A part's weight is its own price: its lines (after their discount, with their tax) plus the assembly the customer bought for those lines plus
 * its own delivery fee (so the assembly fee of a part that is cancelled goes back with that part). The order's fees and discounts
 * (service fee, a free-delivery coupon, loyalty points) follow the weights, because the amount that is divided is the order's real total.
 * Rounding goes to the parts with the largest remainders (Money::allocate), parts are taken in id order so the same order always splits the same way.
 */
final class ParcelShares
{
    /**
     * @param  Collection<int, OrderFulfillment>  $parcels
     * @return array<string, Money> the share of each part, keyed by fulfillment id
     */
    public static function split(Money $amount, Collection $parcels): array
    {
        $parcels = $parcels->sortBy('id')->values();
        if ($parcels->isEmpty()) {
            return [];
        }
        $lines = OrderItem::whereIn('order_fulfillment_id', $parcels->pluck('id'))->where('quantity', '>', 0)
            ->selectRaw('order_fulfillment_id, sum(line_total + assembly_total) as total')->groupBy('order_fulfillment_id')->pluck('total', 'order_fulfillment_id');
        $weights = [];
        foreach ($parcels as $p) {
            $weights[$p->id] = Money::of((string) ($lines[$p->id] ?? '0'), $amount->currency)->plus(Money::of($p->delivery_fee, $amount->currency))->minor;
        }
        if (array_sum($weights) <= 0) {
            $weights = array_map(fn () => 1, $weights); // parts with no price at all share equally
        }

        return $amount->allocate($weights);
    }
}
