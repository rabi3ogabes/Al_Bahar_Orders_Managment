<?php

namespace App\Domain\Fulfillment\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time ping for the staff apps: "dispatch.updated" on private-branches.{branchId} (every employee of the branch)
 * and, when a driver is involved, on private-drivers.{driverId}. Apps re-read their lists; the payload only says what moved.
 */
class DispatchUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $branchId,
        public readonly string $orderNumber,
        public readonly string $kind,            // order | delivery
        public readonly string $status,
        public readonly ?string $driverId = null,
        public readonly ?string $fulfillmentId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_values(array_filter([
            new PrivateChannel('branches.'.$this->branchId),
            $this->driverId ? new PrivateChannel('drivers.'.$this->driverId) : null,
        ]));
    }

    public function broadcastAs(): string
    {
        return 'dispatch.updated';
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return ['number' => $this->orderNumber, 'kind' => $this->kind, 'status' => $this->status, 'fulfillment_id' => $this->fulfillmentId];
    }
}
