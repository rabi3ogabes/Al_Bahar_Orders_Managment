<?php

namespace App\Listeners;

use App\Domain\Operations\Services\OrderAlerts;
use App\Domain\Orders\Events\OrderPlaced;
use App\Domain\Orders\Events\OrderStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the branch team a new order is waiting for them (staff.order_new: push and the in-app inbox), off the request (queue "notifications").
 *  - OrderPlaced: a cash-on-delivery or pay-at-pickup order is new at once; an online (SADAD) order is still unpaid, which OrderAlerts skips;
 *  - OrderStatusChanged pending_payment -> confirmed: the online order was paid, so it is new now.
 * Safe to run twice for the same order (a replayed event, a retried job): OrderAlerts claims each branch part once in `ops_reminders`.
 * Laravel discovers it by the handle() types.
 */
class AnnounceNewOrder implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(private readonly OrderAlerts $alerts) {}

    public function handle(OrderPlaced|OrderStatusChanged $event): void
    {
        if ($event instanceof OrderStatusChanged && ! ($event->from === 'pending_payment' && $event->to === 'confirmed')) {
            return;
        }
        $this->alerts->announceNew($event->orderId);
    }
}
