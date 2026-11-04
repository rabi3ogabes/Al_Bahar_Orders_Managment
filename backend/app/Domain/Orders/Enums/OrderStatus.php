<?php

namespace App\Domain\Orders\Enums;

/** Parent order status. Derived from fulfillment statuses by OrderStatusResolver (plus explicit cancellation states). */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case PartiallyFulfilled = 'partially_fulfilled';
    case ReadyForPickup = 'ready_for_pickup';
    case OutForDelivery = 'out_for_delivery';
    case Completed = 'completed';
    case CancellationRequested = 'cancellation_requested';
    case Cancelled = 'cancelled';
    case PartiallyCancelled = 'partially_cancelled';
    case Returned = 'returned';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::PartiallyCancelled, self::Returned], true);
    }
}
