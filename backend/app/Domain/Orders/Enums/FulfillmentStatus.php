<?php

namespace App\Domain\Orders\Enums;

/**
 * Branch fulfillment state machine (independent of payment status).
 *
 *   pending → accepted → preparing → ready → dispatched → out_for_delivery → delivered → (returned)
 *                                  ↘ pickup_ready → picked_up → (returned)
 *   dispatched/out_for_delivery → failed → dispatched (re-attempt) | returned | cancelled
 *   pending/accepted/preparing/ready/pickup_ready → cancelled
 */
enum FulfillmentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Dispatched = 'dispatched';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case PickupReady = 'pickup_ready';
    case PickedUp = 'picked_up';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Accepted, self::Preparing, self::Cancelled],
            self::Accepted => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::PickupReady, self::Cancelled],
            self::Ready => [self::Dispatched, self::OutForDelivery, self::Cancelled],
            self::Dispatched => [self::OutForDelivery, self::Delivered, self::Failed],
            self::OutForDelivery => [self::Delivered, self::Failed],
            self::Failed => [self::Dispatched, self::OutForDelivery, self::Returned, self::Cancelled],
            self::PickupReady => [self::PickedUp, self::Cancelled],
            self::Delivered, self::PickedUp => [self::Returned],
            self::Cancelled, self::Returned => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /** States in which stock has physically left the branch (reservation consumed). */
    public function stockHandedOver(): bool
    {
        return in_array($this, [self::Dispatched, self::OutForDelivery, self::Delivered, self::PickedUp, self::Failed, self::Returned], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::PickedUp, self::Cancelled, self::Returned], true);
    }
}
