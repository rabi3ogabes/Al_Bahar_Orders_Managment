<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Notifications\Services\NotificationService;
use Illuminate\Support\Str;

/**
 * The two messages a customer gets while the driver is on the way: "your driver is close" (`delivery.nearby`) and "your driver has arrived"
 * (`delivery.arrived`). The words are in the customer's language and carry only the order number and the driver's FIRST name, never a phone number.
 *
 * It decides nothing about WHEN (the callers claim the moment once, in the database, so a repeated tap or a repeated position report never sends
 * twice); it decides whether this delivery may be announced at all: an own driver on the road with a delivery order that has a signed-in customer.
 * A pickup order has no delivery, so it can never get here. The admin's notification controls (Content > Notification controls) are applied by
 * NotificationService, which sends nothing for an event that is switched off.
 */
class DeliveryCustomerAlerts
{
    public function __construct(private readonly NotificationService $notify) {}

    public function nearby(string $deliveryId): bool
    {
        return $this->send($deliveryId, 'delivery.nearby');
    }

    public function arrived(string $deliveryId): bool
    {
        return $this->send($deliveryId, 'delivery.arrived');
    }

    private function send(string $deliveryId, string $event): bool
    {
        $d = Delivery::query()->with(['fulfillment.order.user', 'driver:id,name'])->find($deliveryId);
        $order = $d?->fulfillment?->order;
        $customer = $order?->user;
        if ($d === null || $order === null || $customer === null || $d->fulfillment->type !== 'delivery' || $d->mode !== 'internal' || $d->driver === null) {
            return false;
        }
        // Delivered or failed in the meantime: "your driver is close" would be wrong now.
        if ($d->status !== DS::OutForDelivery) {
            return false;
        }

        return $this->notify->notify($customer, $event, [
            'order_number' => $order->number,
            'driver' => DriverPosition::firstName($d->driver->name),
            'name' => Str::before((string) $order->customer_name, ' '),
            'deep_link' => 'albahar://orders/'.$order->id,
            'reference' => $order->id,
        ]) !== [];
    }
}
