<?php

namespace App\Domain\Pricing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit of any price change; drives "price drop" alerts. */
class PriceChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly ?string $branchId,
        public readonly ?string $oldPrice,
        public readonly ?string $newPrice,
        public readonly ?string $oldSale,
        public readonly ?string $newSale,
    ) {}
}
