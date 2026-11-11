<?php

namespace App\Domain\Fulfillment\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "driver.moved": the driver's new position, to the customer following that order (private-orders.{id}) and to the
 * dashboard fleet map (private-fleet). Throttled upstream (about every 3 s per driver).
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
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_values(array_filter([new PrivateChannel('fleet'), $this->orderId ? new PrivateChannel('orders.'.$this->orderId) : null]));
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
