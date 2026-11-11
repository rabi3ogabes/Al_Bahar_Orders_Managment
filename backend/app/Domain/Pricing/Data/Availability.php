<?php

namespace App\Domain\Pricing\Data;

final class Availability
{
    public function __construct(public readonly bool $purchasable, public readonly ?string $reason = null) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function no(string $reason): self
    {
        return new self(false, $reason);
    }
}
