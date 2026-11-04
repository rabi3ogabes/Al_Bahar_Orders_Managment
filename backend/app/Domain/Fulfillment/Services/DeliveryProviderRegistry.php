<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Fulfillment\Contracts\DeliveryProviderAdapter;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Providers\MockDeliveryProvider;
use App\Support\DomainException;

/** Maps delivery_providers.driver → adapter class. Add real couriers here once contracts are signed. */
class DeliveryProviderRegistry
{
    /** @var array<string, class-string<DeliveryProviderAdapter>> */
    private array $drivers = [
        'mock' => MockDeliveryProvider::class,
    ];

    public function register(string $driver, string $class): void
    {
        $this->drivers[$driver] = $class;
    }

    public function for(DeliveryProvider $provider): DeliveryProviderAdapter
    {
        $class = $this->drivers[$provider->driver] ?? null;
        if (! $class || ! $provider->is_active) {
            throw DomainException::make('delivery_provider_unavailable', 422);
        }

        return new $class($provider);
    }

    public function drivers(): array
    {
        return array_keys($this->drivers);
    }
}
