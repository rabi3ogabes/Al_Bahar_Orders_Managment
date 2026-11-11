<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Data\GatewayResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * SADAD (Qatar) hosted Web Checkout.
 *
 * Flow: server creates a signed form → customer's in-app browser auto-posts it to SADAD's hosted page → SADAD posts the
 * result to our callback URL (server-side) → we verify the checksum, record the event idempotently, and (if a status API
 * is configured) confirm server-to-server before marking the payment paid. The browser/app return URL is NEVER proof of
 * payment.
 *
 * Capabilities are configuration-driven (config/platform.php → payments.sadad.capabilities) because they depend on the
 * merchant agreement. Refunds via API are disabled unless explicitly enabled; refunds are otherwise processed manually
 * (bank transfer/cash) with dual control — see docs/integrations/SADAD.md.
 */
class SadadGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'sadad';
    }

    public function capabilities(): array
    {
        return (array) config('platform.payments.sadad.capabilities');
    }

    public function initiate(Payment $payment): array
    {
        $cfg = $this->config();
        $order = $payment->order;
        $fields = [
            'merchant_id' => $cfg['merchant_id'],
            'ORDER_ID' => $payment->id,
            'WEBSITE' => $cfg['website'],
            'TXN_AMOUNT' => $payment->amount,
            'CUST_ID' => $payment->user_id,
            'EMAIL' => $order?->customer_email ?: 'customer@albahar.qa',
            'MOBILE_NO' => ltrim(substr((string) $order?->customer_phone, -8), '+'),
            'SADAD_WEBCHECKOUT_PAGE_LANGUAGE' => ($order?->locale ?? 'en') === 'ar' ? 'Arb' : 'Eng',
            'CALLBACK_URL' => route('webhooks.sadad'),
            'txnDate' => now(config('platform.business_timezone'))->format('Y-m-d H:i:s'),
            'productdetail' => [[
                'order_id' => $order?->number ?? $payment->id,
                'itemname' => 'Order '.($order?->number ?? ''),
                'amount' => $payment->amount,
                'quantity' => 1,
                'type' => 'line_item',
            ]],
        ];
        $fields['checksumhash'] = SadadChecksum::generate(json_encode(['postData' => $fields, 'secretKey' => urlencode($cfg['secret_key'])]), $this->key());

        return [
            'action' => 'redirect',
            'form' => ['action' => $cfg['checkout_url'], 'fields' => $fields],
            // The app opens this signed URL; it renders an auto-submitting form (no secrets exposed to the client).
            'redirect_url' => URL::temporarySignedRoute('payments.sadad.start', now()->addMinutes(30), ['payment' => $payment->id]),
        ];
    }

    public function verifyCallback(Request $request): GatewayResult
    {
        $data = $request->all();
        $checksum = (string) ($data['checksumhash'] ?? '');
        unset($data['checksumhash']);
        if ($checksum === '' || ! $this->configured()) {
            return GatewayResult::invalid('missing_checksum', $data);
        }
        $payload = json_encode(['postData' => $data, 'secretKey' => urlencode($this->config()['secret_key'])]);
        if (! SadadChecksum::verify($payload, $this->key(), $checksum)) {
            return GatewayResult::invalid('invalid_checksum', $data);
        }
        $status = match ((string) ($data['RESPCODE'] ?? '')) {
            '1' => 'paid',
            '400', '402' => 'pending',
            '' => 'unknown',
            default => 'failed',
        };

        return new GatewayResult(true, $status,
            eventId: (string) ($data['transaction_number'] ?? $data['ORDERID'] ?? '').':'.($data['RESPCODE'] ?? ''),
            paymentReference: $data['ORDERID'] ?? null,
            providerReference: $data['transaction_number'] ?? null,
            amount: isset($data['TXNAMOUNT']) ? (string) $data['TXNAMOUNT'] : null,
            message: $data['RESPMSG'] ?? null,
            raw: $data);
    }

    public function fetchStatus(Payment $payment): ?GatewayResult
    {
        if (! ($this->capabilities()['status_api'] ?? false) || ! $this->configured()) {
            return null;
        }
        $cfg = $this->config();
        $body = ['merchant_id' => $cfg['merchant_id'], 'ORDER_ID' => $payment->id];
        $body['checksumhash'] = SadadChecksum::generate(json_encode(['postData' => $body, 'secretKey' => urlencode($cfg['secret_key'])]), $this->key());
        $response = Http::timeout(15)->retry(2, 1000, throw: false)->acceptJson()->post($cfg['status_url'], $body);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        $status = match ((string) data_get($json, 'RESPCODE', data_get($json, 'status'))) {
            '1', 'success', 'SUCCESS' => 'paid',
            '400', '402', 'pending', 'PENDING' => 'pending',
            default => 'failed',
        };

        return new GatewayResult(true, $status, eventId: 'status:'.$payment->id.':'.$status, paymentReference: $payment->id,
            providerReference: data_get($json, 'transaction_number'), amount: (string) data_get($json, 'TXNAMOUNT', $payment->amount), raw: (array) $json);
    }

    public function refund(Refund $refund): GatewayResult
    {
        throw new DomainException('refund_not_supported', __('errors.refund_not_supported'), 422);
    }

    private function configured(): bool
    {
        $c = $this->config();

        return ! empty($c['merchant_id']) && ! empty($c['secret_key']) && ! empty($c['checkout_url']);
    }

    private function config(): array
    {
        return (array) config('platform.payments.sadad');
    }

    private function key(): string
    {
        $c = $this->config();

        return urlencode((string) $c['secret_key']).$c['merchant_id'];
    }
}
