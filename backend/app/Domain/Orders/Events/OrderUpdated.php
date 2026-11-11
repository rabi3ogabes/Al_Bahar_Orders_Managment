<?php

namespace App\Domain\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time ping for the customer's apps: "order.updated" on private-users.{id}. Carries only what is needed to
 * refresh the order widget and screens; apps re-read the order for the details.
 */
class OrderUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $orderId,
        public readonly string $number,
        public readonly string $status,
        public readonly ?string $deliveryStatus = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'number' => $this->number, 'status' => $this->status, 'status_label' => __('status.'.$this->status), 'delivery_status' => $this->deliveryStatus];
    }
}
