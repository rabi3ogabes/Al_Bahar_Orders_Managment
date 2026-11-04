<?php

namespace App\Domain\Auctions\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AuctionOutbid
{
    use Dispatchable;

    public function __construct(public readonly string $userId, public readonly string $lotId, public readonly string $lotTitle, public readonly string $amount) {}
}
