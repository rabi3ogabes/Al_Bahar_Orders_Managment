<?php

namespace App\Domain\Fulfillment\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "dispatch.updated" for ONE DRIVER (private-drivers.{id}): a job of theirs was assigned or moved, or has just been taken away
 * (assigned = false, status "unassigned": the app removes it and closes its window). Same event name as the branch message, so a
 * driver app built before this class existed keeps working; the payload is only about this driver's job (built by
 * OrderRealtimePayload::driver()): no customer, no other driver, no other job. Sent after the commit, straight to the socket server.
 */
class DriverJobUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $driverId, public readonly array $payload) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('drivers.'.$this->driverId)];
    }

    public function broadcastAs(): string
    {
        return 'dispatch.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
