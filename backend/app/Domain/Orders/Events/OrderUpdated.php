<?php

namespace App\Domain\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "order.updated" on private-users.{id}: the customer's apps and web store hear that their order moved, with the whole new state
 * (status, payment state, the state of every branch part and its delivery) and the order's version `seq`, so a screen can change
 * at once and ignore a message older than the one it already shows. Built by OrderRealtime after the change was committed; sent
 * straight to the socket server (never waits for a queue worker). Carries nothing the customer may not see.
 *
 * Also the trigger of the customer's push (SendRealtimePush), which only needs the public properties below.
 */
class OrderUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, mixed> $payload the message body built by OrderRealtimePayload::customer(); a bare event falls back to the short legacy shape */
    public function __construct(
        public readonly string $userId,
        public readonly string $orderId,
        public readonly string $number,
        public readonly string $status,
        public readonly ?string $deliveryStatus = null,
        public readonly array $payload = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload !== [] ? $this->payload
            : ['order_id' => $this->orderId, 'number' => $this->number, 'status' => $this->status, 'status_label' => __('status.'.$this->status), 'delivery_status' => $this->deliveryStatus];
    }
}
