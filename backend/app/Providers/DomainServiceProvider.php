<?php

namespace App\Providers;

use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Catalog\Services\PostgresProductSearch;
use App\Domain\Notifications\Listeners\DomainEventSubscriber;
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
    }

    public function boot(): void
    {
        Event::subscribe(DomainEventSubscriber::class);
    }
}
