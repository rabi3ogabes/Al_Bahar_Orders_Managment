<?php

namespace App\Domain\Auctions\Events;

use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Services\AuctionPresenter;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast on the public channel "auction-lot.{lotId}" AFTER the bid transaction commits.
 * Payload carries the last committed sequence and the public lot state (never reserve price or auto-bid maximums,
 * and bidders are anonymised). Clients must still resync from REST on reconnect.
 */
class LotUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $lotId, public readonly int $sequence, public readonly string $type, public readonly array $events = []) {}

    public function broadcastOn(): array
    {
        return [new Channel('auction-lot.'.$this->lotId)];
    }

    public function broadcastAs(): string
    {
        return match ($this->type) {
            'bid_accepted' => 'bid.accepted',
            'extended' => 'lot.extended',
            default => 'lot.status',
        };
    }

    public function broadcastWith(): array
    {
        $lot = AuctionLot::find($this->lotId);

        return [
            'sequence' => $this->sequence,
            'type' => $this->type,
            'lot' => $lot ? app(AuctionPresenter::class)->lot($lot, null) : null,
            'events' => array_map(fn ($e) => ['sequence' => $e->sequence, 'type' => $e->type, 'payload' => app(AuctionPresenter::class)->publicPayload($e->payload)], $this->events),
        ];
    }
}
