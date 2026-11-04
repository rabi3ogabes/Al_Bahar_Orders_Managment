<?php

namespace App\Domain\Auctions\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AuctionWon
{
    use Dispatchable;

    public function __construct(public readonly string $winnerId) {}
}
