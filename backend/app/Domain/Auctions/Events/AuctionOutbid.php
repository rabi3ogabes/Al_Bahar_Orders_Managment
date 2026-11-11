<?php

namespace App\Domain\Auctions\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit. Notifies the previous leader and pushes "auction.outbid" on private-users.{id}. */
class AuctionOutbid implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public readonly string $userId, public readonly string $lotId, public readonly string $lotTitle, public readonly string $amount) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'auction.outbid';
    }

    public function broadcastWith(): array
    {
        return ['lot_id' => $this->lotId, 'amount' => $this->amount];
    }
}
