<?php

namespace App\Domain\Fulfillment\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case PickedUp = 'picked_up';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Rescheduled = 'rescheduled';
    case Returning = 'returning';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Assigned, self::Accepted, self::PickedUp, self::Cancelled],
            self::Assigned => [self::Accepted, self::PickedUp, self::Pending, self::Cancelled],
            self::Accepted => [self::PickedUp, self::Cancelled, self::Pending],
            self::PickedUp => [self::OutForDelivery, self::Delivered, self::Failed],
            self::OutForDelivery => [self::Delivered, self::Failed],
            self::Failed => [self::Rescheduled, self::Returning, self::OutForDelivery],
            self::Rescheduled => [self::OutForDelivery, self::Returning, self::Assigned],
            self::Returning => [self::Returned],
            self::Delivered, self::Returned, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Delivered, self::Returned, self::Cancelled], true);
    }
}
