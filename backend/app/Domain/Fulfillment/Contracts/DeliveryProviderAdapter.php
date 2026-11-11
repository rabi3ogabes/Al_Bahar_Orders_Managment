<?php

namespace App\Domain\Fulfillment\Contracts;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use Illuminate\Http\Request;

/**
 * Third-party courier adapter. Not every provider supports every operation — callers MUST check capabilities()
 * (keys: quote, create_booking, cancel_booking, tracking, webhook, proof). Provider-specific logic lives only in
 * adapters, never in order/fulfillment controllers.
 */
interface DeliveryProviderAdapter
{
    public function __construct(DeliveryProvider $provider);

    /** @return array<string, bool> */
    public function capabilities(): array;

    /** @return array{fee: string, currency: string, eta_minutes: ?int} */
    public function quote(Delivery $delivery): array;

    /** @return array{reference: string, tracking_number: ?string, fee: ?string, raw: array} */
    public function createBooking(Delivery $delivery): array;

    public function cancelBooking(Delivery $delivery): bool;

    /** @return array{status: string, raw: array} status mapped to DeliveryStatus values */
    public function track(Delivery $delivery): array;

    /** @return array{authentic: bool, event_id: ?string, reference: ?string, status: ?string, proof_url: ?string, raw: array} */
    public function parseWebhook(Request $request): array;
}
