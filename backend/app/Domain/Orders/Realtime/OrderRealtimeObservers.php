<?php

namespace App\Domain\Orders\Realtime;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;

/**
 * Tells OrderRealtime when an order, one of its branch parts or one of its deliveries is created or really changes (a status, the
 * payment state, the driver). Registered in DomainServiceProvider. Saving other columns (notes, totals) sends nothing.
 */
class OrderRealtimeObservers
{
    public function __construct(private readonly OrderRealtime $realtime) {}

    public function orderSaved(Order $o): void
    {
        if ($o->wasRecentlyCreated || $o->wasChanged(['status', 'payment_status'])) {
            $this->realtime->orderChanged($o);
        }
    }

    public function fulfillmentSaved(OrderFulfillment $f): void
    {
        if ($f->wasRecentlyCreated || $f->wasChanged('status')) {
            $this->realtime->fulfillmentChanged($f);
        }
    }

    public function deliverySaved(Delivery $d): void
    {
        if ($d->wasRecentlyCreated || $d->wasChanged(['status', 'driver_id'])) {
            $this->realtime->deliveryChanged($d, $d->wasChanged('driver_id') ? $d->getOriginal('driver_id') : null);
        }
    }
}
