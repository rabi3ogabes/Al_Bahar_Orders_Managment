<?php

namespace App\Domain\Fulfillment\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "driver.moved" for STAFF: the driver's new position to the staff of each branch the driver works for
 * (private-branches.{id}, so a branch only ever sees its own drivers) and to the company-wide fleet map (private-fleet).
 * The customer following an order gets the smaller OrderDriverMoved on private-orders.{id} instead.
 * Throttled upstream (about every 3 s per driver, and not at all while the driver stands still).
 *
 * The payload carries the same position fields as the REST responses (lat, lng, heading, speed_kmh, accuracy_m, at, age_seconds),
 * so a map handles a push and a poll the same way.
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
        public readonly ?int $accuracyM = null,
        public readonly ?int $battery = null,
        public readonly ?string $vehicle = null,
        public readonly ?string $orderNumber = null,
        /** When the GPS fix was taken (ISO 8601). */
        public readonly ?string $at = null,
        public readonly int $ageSeconds = 0,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('fleet'), ...array_map(fn (string $b) => new PrivateChannel('branches.'.$b), $this->branchIds)];
    }

    public function broadcastAs(): string
    {
        return 'driver.moved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['driver_id' => $this->driverId, 'name' => $this->driverName, 'vehicle' => $this->vehicle,
            'lat' => $this->lat, 'lng' => $this->lng, 'heading' => $this->heading, 'speed_kmh' => $this->speedKmh, 'accuracy_m' => $this->accuracyM,
            'battery' => $this->battery, 'at' => $this->at ?? now()->toIso8601String(), 'age_seconds' => $this->ageSeconds,
            'order_id' => $this->orderId, 'order_number' => $this->orderNumber, 'delivery_status' => $this->deliveryStatus];
    }
}
