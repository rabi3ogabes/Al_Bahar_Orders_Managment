<?php

namespace App\Listeners;

use App\Domain\Orders\Events\LiveOrderEvent;
use App\Domain\Orders\Events\OrderPlaced;
use App\Domain\Orders\Events\OrderStatusChanged;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\LiveOrderFeed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/** Turns a placed order / an order status change into the live toast broadcast for staff, off the request (queue "notifications"). */
class BroadcastLiveOrder implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(private readonly LiveOrderFeed $feed) {}

    public function handle(OrderPlaced|OrderStatusChanged $event): void
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
