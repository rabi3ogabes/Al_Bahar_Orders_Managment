<?php

namespace App\Domain\Fulfillment\Providers;

use App\Domain\Fulfillment\Contracts\DeliveryProviderAdapter;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deterministic courier simulator for development, staging and automated tests (spec: "adapter can be tested with
 * a mock provider"). Refuses to run in production. Behaviour can be steered with settings: {"fail_create": true,
 * "timeout": true}. Webhooks are HMAC-SHA256 signed with the provider's webhook secret (header X-Mock-Signature).
 */
class MockDeliveryProvider implements DeliveryProviderAdapter
{
    public function __construct(private readonly DeliveryProvider $provider)
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The mock delivery provider cannot be used in production.');
        }
    }

    public function capabilities(): array
    {
        return ['quote' => true, 'create_booking' => true, 'cancel_booking' => true, 'tracking' => true, 'webhook' => true, 'proof' => true];
    }

    public function quote(Delivery $delivery): array
    {
        return ['fee' => '25.00', 'currency' => 'QAR', 'eta_minutes' => 180];
    }

    public function createBooking(Delivery $delivery): array
    {
        $settings = $this->provider->settings ?? [];
        if (! empty($settings['timeout'])) {
            throw new ConnectionException('Provider timed out');
        }
        if (! empty($settings['fail_create'])) {
            throw new RuntimeException('Provider rejected the booking');
        }

        return ['reference' => 'MOCK-'.Str::upper(Str::random(10)), 'tracking_number' => null, 'fee' => '25.00', 'raw' => ['accepted' => true]];
    }

    public function cancelBooking(Delivery $delivery): bool
    {
        return true;
    }

    public function track(Delivery $delivery): array
    {
        return ['status' => $delivery->status->value, 'raw' => []];
    }

    public function parseWebhook(Request $request): array
    {
        $secret = (string) $this->provider->webhook_secret;
        $sig = (string) $request->header('X-Mock-Signature');
        $authentic = $secret !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $sig);
        $data = $request->json()->all();

        return ['authentic' => $authentic, 'event_id' => $data['event_id'] ?? null, 'reference' => $data['reference'] ?? null,
            'status' => $data['status'] ?? null, 'proof_url' => $data['proof_url'] ?? null, 'raw' => $data];
    }
}
