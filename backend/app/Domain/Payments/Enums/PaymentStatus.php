<?php

namespace App\Domain\Payments\Enums;

/**
 * Payment state machine (separate from order status).
 *   pending → processing → (authorized →) paid → partially_refunded → refunded
 *   pending/processing → failed | cancelled | expired      failed → pending (retry creates a NEW payment attempt)
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Authorized, self::Paid, self::Failed, self::Cancelled, self::Expired],
            self::Processing => [self::Authorized, self::Paid, self::Failed, self::Cancelled, self::Expired],
            self::Authorized => [self::Paid, self::Cancelled, self::Expired],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Failed, self::Cancelled, self::Expired, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded, self::Refunded], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Expired, self::Refunded], true);
    }
}
