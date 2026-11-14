<?php

namespace App\Domain\Fulfillment\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "dispatch.updated" for the BRANCH: every employee of the branch (private-branches.{branchId}) hears that a part of an order, or its
 * delivery, moved: a new order arrived, it was accepted, assigned, picked up, delivered, failed. A delivery change is also sent to the
 * company-wide dashboard (private-fleet), whose staff see every branch anyway. The payload holds the whole current state of that part
 * (stage, delivery, driver) and the order's version `seq`, so the apps move a row between lists at once; built by OrderRealtime after
 * the commit and sent straight to the socket server. No customer data: the apps read it from their REST row.
 *
 * The driver hears their own jobs from DriverJobUpdated, never from here. Also the trigger of the staff and driver pushes
 * (SendRealtimePush), which only need the public properties below.
 */
class DispatchUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, mixed> $payload the message body built by OrderRealtimePayload::branch(); a bare event falls back to the short legacy shape */
    public function __construct(
        public readonly string $branchId,
        public readonly string $orderNumber,
        public readonly string $kind,            // order | delivery
        public readonly string $status,
        public readonly ?string $driverId = null,
        public readonly ?string $fulfillmentId = null,
        public readonly array $payload = [],
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('branches.'.$this->branchId), ...($this->kind === 'delivery' ? [new PrivateChannel('fleet')] : [])];
    }

    public function broadcastAs(): string
    {
        return 'dispatch.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload !== [] ? $this->payload
            : ['number' => $this->orderNumber, 'kind' => $this->kind, 'status' => $this->status, 'fulfillment_id' => $this->fulfillmentId];
    }
}
