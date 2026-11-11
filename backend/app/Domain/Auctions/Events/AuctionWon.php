<?php

namespace App\Domain\Auctions\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit. Notifies the winner (notifications) and pushes "auction.won" on private-users.{id}. */
class AuctionWon implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public readonly string $winnerId, public readonly ?string $userId = null, public readonly ?string $lotId = null) {}

    public function broadcastWhen(): bool
    {
        return $this->userId !== null;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'auction.won';
    }

    public function broadcastWith(): array
    {
        return ['winner_id' => $this->winnerId, 'lot_id' => $this->lotId];
    }
}
