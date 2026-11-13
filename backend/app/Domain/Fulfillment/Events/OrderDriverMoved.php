<?php

namespace App\Domain\Fulfillment\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "driver.moved" for the CUSTOMER following an order (private-orders.{id}, joinable only by the person who placed it).
 * Same event name as the staff one, but only what a customer should see: the driver's first name and vehicle, the
 * position and the delivery step. Never the driver's id, phone, battery or other jobs. Sent only while the driver is
 * carrying that order (picked up or out for delivery).
 */
class OrderDriverMoved implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
        public readonly string $driverFirstName,
        public readonly float $lat,
        public readonly float $lng,
        public readonly string $deliveryStatus,
        public readonly ?string $vehicle = null,
        public readonly ?int $heading = null,
        public readonly ?float $speedKmh = null,
        public readonly ?int $accuracyM = null,
        public readonly ?string $at = null,
        public readonly int $ageSeconds = 0,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->orderId)];
    }

    public function broadcastAs(): string
    {
        return 'driver.moved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'name' => $this->driverFirstName, 'vehicle' => $this->vehicle,
            'lat' => $this->lat, 'lng' => $this->lng, 'heading' => $this->heading, 'speed_kmh' => $this->speedKmh, 'accuracy_m' => $this->accuracyM,
            'at' => $this->at ?? now()->toIso8601String(), 'age_seconds' => $this->ageSeconds, 'delivery_status' => $this->deliveryStatus];
    }
}
