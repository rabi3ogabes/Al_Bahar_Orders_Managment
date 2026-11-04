<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Wires domain modules: gateway/adapter registries, event listeners and observers.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Domain\Catalog\Contracts\ProductSearch::class, \App\Domain\Catalog\Services\PostgresProductSearch::class);
        $this->app->scoped(\App\Domain\Pricing\Services\PriceResolver::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::subscribe(\App\Domain\Notifications\Listeners\DomainEventSubscriber::class);
    }
}
