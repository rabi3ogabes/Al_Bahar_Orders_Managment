<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Data\GatewayResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\DomainException;
use Illuminate\Http\Request;

/**
 * Cash on delivery and pay-at-pickup. No customer action; payment becomes "paid" only when an authorized employee
 * records the collection (CashCollection) — never from any client message.
 */
class OfflineGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'offline';
    }

    public function capabilities(): array
    {
        return ['hosted_checkout' => false, 'status_api' => false, 'refund_api' => false, 'void' => true, 'authorize_capture' => false];
    }

    public function initiate(Payment $payment): array
    {
        return ['action' => 'none'];
    }

    public function verifyCallback(Request $request): GatewayResult
    {
        return GatewayResult::invalid('offline_has_no_callbacks');
    }

    public function fetchStatus(Payment $payment): ?GatewayResult
    {
        return null;
    }

    public function refund(Refund $refund): GatewayResult
    {
        throw new DomainException('refund_not_supported', __('errors.refund_not_supported'), 422);
    }
}
