<?php

namespace App\Domain\Payments\Services;

use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\ParcelShares;
use App\Domain\Payments\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Cash on delivery for an order that comes from more than one branch. Each branch's driver collects HIS part, not the whole order:
 * the delivery's `cod_amount` is that part's share of what is still due on the payment (amount less what was already collected), split by
 * ParcelShares among the parts that still have to be delivered. The shares always add up to what is due, so the last driver collects exactly
 * what is left and the payment closes to the dirham. An order with one part is unchanged: its share is everything that is due.
 *
 * Recomputed whenever the picture changes (a delivery is created, a part is delivered or cancelled, an item adjustment changes the total),
 * from the stored payment and parts, so it can never drift from them.
 */
final class CodSplit
{
    /** Parts that still have to be delivered (and so still have cash to collect). */
    private const DONE = [FS::Cancelled, FS::Delivered, FS::PickedUp, FS::Returned];

    /** What the delivery of $f must collect now (zero for an order that is not cash on delivery, or is already settled). */
    public static function expectedFor(Order $order, OrderFulfillment $f): Money
    {
        $due = self::due($order);
        if (! $due->isPositive()) {
            return $due;
        }
        $open = self::open($order, $f);

        return self::shares($due, $open)[$f->id] ?? Money::zero($due->currency);
    }

    /** Re-works `cod_amount` of every delivery of the order that is still to be made. */
    public static function refresh(Order $order): void
    {
        if ($order->payment_method !== 'cod') {
            return;
        }
        $due = self::due($order);
        $shares = self::shares($due, self::open($order));
        foreach (Delivery::whereIn('order_fulfillment_id', $order->fulfillments()->select('id'))
            ->whereNotIn('status', [DS::Delivered->value, DS::Returned->value, DS::Cancelled->value])->get() as $d) {
            $new = ($shares[$d->order_fulfillment_id] ?? Money::zero($due->currency))->toDecimal();
            if (Money::of($d->cod_amount)->toDecimal() !== $new) {
                $d->forceFill(['cod_amount' => $new])->save();
            }
        }
    }

    /** What the customer still owes in cash: the open cash-on-delivery payment's amount less what drivers already collected. */
    public static function due(Order $order): Money
    {
        $zero = Money::zero($order->currency ?: 'QAR');
        if ($order->payment_method !== 'cod') {
            return $zero;
        }
        $payment = Payment::where('order_id', $order->id)->where('method', 'cod')->orderByDesc('attempt')->first();
        if ($payment === null) {
            return Money::of($order->grand_total, $zero->currency);
        }
        if ($payment->status->isSettled() || $payment->status->isFinal()) {
            return $zero;
        }

        return Money::of($payment->amount, $zero->currency)->minus(Money::of($payment->captured_amount, $zero->currency))->max($zero);
    }

    /** @return Collection<int, OrderFulfillment> the delivery parts still to be delivered, plus $include (a part about to get its delivery) */
    private static function open(Order $order, ?OrderFulfillment $include = null): Collection
    {
        return OrderFulfillment::where('order_id', $order->id)->where('type', 'delivery')->get()
            ->filter(fn (OrderFulfillment $p) => ! in_array($p->status, self::DONE, true) || $p->id === $include?->id)->values();
    }

    /**
     * @param  Collection<int, OrderFulfillment>  $parts
     * @return array<string, Money>
     */
    private static function shares(Money $due, Collection $parts): array
    {
        return ParcelShares::split($due, $parts);
    }
}
