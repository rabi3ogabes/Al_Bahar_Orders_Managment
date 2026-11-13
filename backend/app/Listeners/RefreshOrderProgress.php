<?php

namespace App\Listeners;

use App\Domain\Fulfillment\Events\OrderDriverMoved;
use App\Domain\Notifications\Jobs\RefreshOrderProgressJob;
use App\Domain\Orders\Events\OrderPlaced;
use Illuminate\Support\Facades\Cache;

/**
 * Feeds the customer's live order card from the two moments no status event covers: the order being placed, and the
 * driver moving (the arrival window may shift). Does nothing but queue a small job, so neither the checkout nor the
 * driver's location upload waits for it; position ticks are let through at most every platform.live_order.tick_seconds per order.
 * Laravel discovers it by the handle() types.
 */
class RefreshOrderProgress
{
    public function handle(OrderPlaced|OrderDriverMoved $event): void
    {
        $orderId = $event->orderId;
        if ($event instanceof OrderDriverMoved && ! Cache::add('order_progress:tick:'.$orderId, 1, (int) config('platform.live_order.tick_seconds', 120))) {
            return;
        }
        rescue(function () use ($orderId) {
            $pending = RefreshOrderProgressJob::dispatch($orderId);
            unset($pending); // a queued job is sent when this object goes away: do it here, inside the rescue
        }, report: true);
    }
}
