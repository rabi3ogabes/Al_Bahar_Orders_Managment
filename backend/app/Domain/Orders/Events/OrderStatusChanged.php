<?php

namespace App\Domain\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit whenever a parent order status changes (drives customer notifications). */
class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly string $orderId, public readonly string $from, public readonly string $to) {}
}
