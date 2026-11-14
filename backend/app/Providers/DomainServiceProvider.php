<?php

namespace App\Providers;

use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Catalog\Services\PostgresProductSearch;
use App\Domain\Notifications\Listeners\DomainEventSubscriber;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Realtime\OrderRealtime;
use App\Domain\Orders\Realtime\OrderRealtimeObservers;
use App\Domain\Pricing\Services\PriceResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires domain modules: gateway/adapter registries, event listeners and observers.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductSearch::class, PostgresProductSearch::class);
        $this->app->scoped(PriceResolver::class);
        // One collector per request / job / command: what it gathered is sent when that unit of work ends (see OrderRealtime).
        $this->app->scoped(OrderRealtime::class);
    }

    public function boot(): void
    {
        Event::subscribe(DomainEventSubscriber::class);

        // Real time: the order, its branch parts and its deliveries tell OrderRealtime when they change, whoever changed them.
        $hooks = fn () => new OrderRealtimeObservers(app(OrderRealtime::class));
        Order::saved(fn (Order $o) => $hooks()->orderSaved($o));
        OrderFulfillment::saved(fn (OrderFulfillment $f) => $hooks()->fulfillmentSaved($f));
        Delivery::saved(fn (Delivery $d) => $hooks()->deliverySaved($d));
    }
}
