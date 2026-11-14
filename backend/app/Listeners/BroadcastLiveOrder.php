<?php

namespace App\Listeners;

use App\Domain\Orders\Events\LiveOrderEvent;
use App\Domain\Orders\Events\OrderPlaced;
use App\Domain\Orders\Events\OrderStatusChanged;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\LiveOrderFeed;
use Illuminate\Support\Str;

/**
 * Turns a placed order / an order status change into the live toast broadcast for staff. Runs after the response has gone out (defer),
 * so the request never waits for it and no queue worker sits in between: the toast reaches the dashboard within a fraction of a second.
 */
class BroadcastLiveOrder
{
    public function __construct(private readonly LiveOrderFeed $feed) {}

    public function handle(OrderPlaced|OrderStatusChanged $event): void
    {
        defer(fn () => rescue(fn () => $this->broadcast($event), report: true), always: true);
    }

    private function broadcast(OrderPlaced|OrderStatusChanged $event): void
    {
        $order = Order::find($event->orderId);
        if (! $order) {
            return;
        }
        $payload = $event instanceof OrderPlaced
            ? $this->feed->payload($order, 'placed', null, $order->status->value, 'placed:'.$order->id, now())
            : $this->feed->payload($order, 'status', $event->from, $event->to, 'status:'.Str::uuid(), now());

        event(new LiveOrderEvent($payload, $this->feed->branchIds($order)));
    }
}
