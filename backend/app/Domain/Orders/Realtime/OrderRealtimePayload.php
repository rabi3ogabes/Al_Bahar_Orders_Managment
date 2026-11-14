<?php

namespace App\Domain\Orders\Realtime;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\BusinessTime;

/**
 * The ONE shape of a real-time order progress message, in three audiences. Every message says which order (order_id, number),
 * what its state is now (status, fulfillment_*, delivery_*), the order's version (seq, grows by one per change: an app ignores a
 * message older than what it already holds) and when it happened (at). Each message carries the whole current state of the part it
 * describes, never just "what changed", so an app can show it at once and a repeated or out-of-order message does no harm.
 *
 * What each audience may know:
 *  - customer: their own order: status, payment state, the state of each branch part and its delivery. No staff, driver or branch ids.
 *  - branch staff: the branch's own part of the order, its delivery and the driver's id and name. No customer data (the apps read it from the REST row).
 *  - driver: only their own job: the delivery, its status and whether it is still theirs. Nothing about other drivers.
 *
 * The payloads are built from models whose relations are loaded (fulfillments.deliveries.driver), after the data was committed.
 */
final class OrderRealtimePayload
{
    /** The queue stage of a delivery part in the staff app (same stages as GET /admin/dispatch/queue); anything finished is "done". */
    public static function stage(FS $status): string
    {
        return match ($status) {
            FS::Pending => 'new',
            FS::Accepted, FS::Preparing => 'preparing',
            FS::Ready => 'ready',
            FS::Dispatched, FS::OutForDelivery => 'out',
            FS::Failed => 'failed',
            default => 'done',
        };
    }

    /** @return array<string, mixed> */
    public static function customer(Order $order, ?OrderFulfillment $changed, int $seq): array
    {
        $changed ??= $order->fulfillments->first();
        $delivery = $changed?->activeDelivery();

        return [
            'order_id' => $order->id, 'number' => $order->number,
            'status' => $order->status->value, 'status_label' => __('status.'.$order->status->value),
            'payment_status' => $order->payment_status->value,
            'fulfillment_id' => $changed?->id, 'fulfillment_status' => $changed?->status->value,
            'delivery_status' => $delivery?->status->value,
            'fulfillments' => $order->fulfillments->map(fn (OrderFulfillment $f) => [
                'id' => $f->id, 'type' => $f->type, 'status' => $f->status->value, 'delivery_status' => $f->activeDelivery()?->status->value,
            ])->values()->all(),
            'seq' => $seq, 'at' => self::at(),
        ];
    }

    /**
     * @param  string  $kind  "order" (the branch part moved) or "delivery" (its delivery moved)
     * @param  bool  $orderLevel  the order itself moved (a cancellation was asked, a payment came in): `status` is then the order's, not the part's
     * @return array<string, mixed>
     */
    public static function branch(Order $order, OrderFulfillment $f, ?Delivery $delivery, string $kind, int $seq, bool $orderLevel = false): array
    {
        return [
            'order_id' => $order->id, 'number' => $order->number, 'kind' => $kind, 'order_level' => $orderLevel,
            'status' => $orderLevel ? $order->status->value : ($kind === 'delivery' && $delivery ? $delivery->status->value : $f->status->value),
            'order_status' => $order->status->value,
            'fulfillment_id' => $f->id, 'fulfillment_type' => $f->type, 'fulfillment_status' => $f->status->value, 'stage' => self::stage($f->status),
            'delivery_id' => $delivery?->id, 'delivery_status' => $delivery?->status->value,
            'driver_id' => $delivery?->driver_id, 'driver_name' => $delivery?->driver?->name,
            'seq' => $seq, 'at' => self::at(),
        ];
    }

    /**
     * @param  bool  $mine  false when the job has just been taken away from this driver (re-assigned): then nothing else about it is sent
     * @return array<string, mixed>
     */
    public static function driver(Order $order, OrderFulfillment $f, Delivery $delivery, bool $mine, int $seq): array
    {
        return [
            'order_id' => $order->id, 'number' => $order->number, 'kind' => 'delivery',
            'status' => $mine ? $delivery->status->value : 'unassigned',
            'fulfillment_id' => $f->id, 'delivery_id' => $delivery->id,
            'delivery_status' => $mine ? $delivery->status->value : null,
            'assigned' => $mine,
            'seq' => $seq, 'at' => self::at(),
        ];
    }

    private static function at(): string
    {
        return (string) BusinessTime::iso(now());
    }
}
