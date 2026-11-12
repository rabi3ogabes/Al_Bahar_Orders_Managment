<?php

namespace App\Domain\Fulfillment\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "driver.moved": the driver's new position, to the customer following that order (private-orders.{id}), the staff of each
 * branch the driver works for (private-branches.{id}, so a branch only ever sees its own drivers) and the company-wide fleet map (private-fleet). Throttled upstream (about every 3 s per driver).
 */
class DriverMoved implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $driverId,
        public readonly string $driverName,
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?string $orderId = null,
        public readonly ?string $deliveryStatus = null,
        public readonly ?int $heading = null,
        public readonly ?float $speedKmh = null,
        /** @var list<string> branches this driver works for: their staff follow the driver on private-branches.{id} */
        public readonly array $branchIds = [],
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_values(array_filter([
            new PrivateChannel('fleet'),
            ...array_map(fn (string $b) => new PrivateChannel('branches.'.$b), $this->branchIds),
            $this->orderId ? new PrivateChannel('orders.'.$this->orderId) : null,
        ]));
    }

    public function broadcastAs(): string
    {
        return 'driver.moved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['driver_id' => $this->driverId, 'name' => $this->driverName, 'lat' => $this->lat, 'lng' => $this->lng, 'order_id' => $this->orderId,
            'delivery_status' => $this->deliveryStatus, 'heading' => $this->heading, 'speed_kmh' => $this->speedKmh, 'at' => now()->toIso8601String()];
    }
}
