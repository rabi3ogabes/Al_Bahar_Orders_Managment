<?php

namespace App\Domain\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A variant went from 0 to >0 available at a branch. Listeners notify subscribed customers (after commit). */
class BackInStock
{
    use Dispatchable;

    public function __construct(public readonly string $branchId, public readonly string $variantId) {}
}
