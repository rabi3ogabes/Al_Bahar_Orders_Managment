<?php

namespace App\Domain\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "order.live" for the staff live pages (fleet map): a new order or an order status change, as the small payload built by
 * LiveOrderFeed. Sent on private-live-orders (staff who see orders of every branch) and on private-live-orders.{branchId}
 * for each branch that owns a part of the order, so branch-scoped staff only ever hear their own branches.
 * Sent straight to the socket server by BroadcastLiveOrder once the response has gone out (no queue worker to wait for).
 */
class LiveOrderEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, string|null>  $payload
     * @param  list<string>  $branchIds
     */
    public function __construct(public readonly array $payload, public readonly array $branchIds) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('live-orders'), ...array_map(fn (string $b) => new PrivateChannel('live-orders.'.$b), $this->branchIds)];
    }

    public function broadcastAs(): string
    {
        return 'order.live';
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
