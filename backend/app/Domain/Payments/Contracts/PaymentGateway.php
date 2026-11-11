<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Data\GatewayResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use Illuminate\Http\Request;

/**
 * Payment provider adapter. Providers differ: always consult capabilities() before calling an optional operation.
 * Capability keys: hosted_checkout, status_api, refund_api, void, authorize_capture.
 */
interface PaymentGateway
{
    public function code(): string;

    /** @return array<string, bool> */
    public function capabilities(): array;

    /**
     * Create the provider-side payment request. Returns how the customer continues:
     * ['action' => 'redirect', 'form' => ['action' => url, 'fields' => [...]]] or ['action' => 'none'].
     */
    public function initiate(Payment $payment): array;

    /** Authenticate & parse a callback/webhook. Never trusts unsigned data. */
    public function verifyCallback(Request $request): GatewayResult;

    /** Server-to-server status lookup (only if capabilities()['status_api']). */
    public function fetchStatus(Payment $payment): ?GatewayResult;

    /** Provider refund (only if capabilities()['refund_api']). */
    public function refund(Refund $refund): GatewayResult;
}
