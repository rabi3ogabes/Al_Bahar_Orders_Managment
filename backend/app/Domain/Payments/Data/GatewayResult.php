<?php

namespace App\Domain\Payments\Data;

final class GatewayResult
{
    public function __construct(
        public readonly bool $authentic,
        public readonly string $status,              // paid | failed | pending | cancelled | refunded | unknown
        public readonly ?string $eventId = null,     // provider-unique id used for idempotency
        public readonly ?string $paymentReference = null,  // our payment id / order id as echoed by provider
        public readonly ?string $providerReference = null, // provider transaction number
        public readonly ?string $amount = null,      // decimal string as reported by provider
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    public static function invalid(string $message, array $raw = []): self
    {
        return new self(false, 'unknown', null, null, null, null, $message, $raw);
    }
}
