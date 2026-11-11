<?php

namespace App\Domain\Pricing\Data;

use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** Server-computed price for one variant at one branch (or globally when no branch is given). */
final class EffectivePrice
{
    public function __construct(
        public readonly Money $regular,
        public readonly ?Money $sale,
        public readonly string $source,          // variant_branch | product_branch | variant | product
        public readonly ?CarbonImmutable $saleEndsAt = null,
    ) {}

    public function final(): Money
    {
        return $this->sale ?? $this->regular;
    }

    public function discountPercent(): int
    {
        if (! $this->sale || $this->regular->minor <= 0 || ! $this->sale->lessThan($this->regular)) {
            return 0;
        }

        return (int) floor(($this->regular->minor - $this->sale->minor) * 100 / $this->regular->minor);
    }

    public function toArray(): array
    {
        return [
            'regular' => $this->regular->jsonSerialize(),
            'sale' => $this->sale?->jsonSerialize(),
            'final' => $this->final()->jsonSerialize(),
            'discount_percent' => $this->discountPercent(),
            'sale_ends_at' => BusinessTime::iso($this->saleEndsAt),
            'source' => $this->source,
        ];
    }
}
