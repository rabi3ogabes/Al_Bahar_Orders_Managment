<?php

namespace App\Domain\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PaymentStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly string $paymentId, public readonly string $from, public readonly string $to) {}
}
