<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentWebhookController extends Controller
{
    /** SADAD server-to-server callback. Always answers 200 for authentic events so the provider stops retrying. */
    public function sadad(Request $request, PaymentService $payments): JsonResponse
    {
        $result = $payments->handleCallback('sadad', $request);

        return response()->json(['received' => $result['ok'], 'status' => $result['status']], $result['ok'] ? 200 : 400);
    }

    /** Signed, short-lived page that auto-posts the SADAD hosted-checkout form (opened in the app's secure browser). */
    public function sadadStart(Request $request, Payment $payment, PaymentService $payments): Response
    {
        abort_unless($request->hasValidSignature() && $payment->method === 'sadad' && $payment->status->value === 'pending', 410);
        $form = $payments->gateway('sadad')->initiate($payment)['form'];

        return response()->view('payments.sadad-redirect', ['form' => $form, 'locale' => $payment->order?->locale ?? 'en'])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** Browser return page. Shows status only — never changes payment state. The app polls GET /payments/{id}. */
    public function sadadReturn(Request $request): Response
    {
        $payment = Payment::find($request->input('ORDERID') ?? $request->input('payment'));

        return response()->view('payments.return', ['payment' => $payment, 'deepLink' => $payment ? 'albahar://payments/'.$payment->id.'/return' : 'albahar://orders',
            'webLink' => $payment ? '/orders/'.$payment->order_id.'?payment='.$payment->id : '/account/orders'])
            ->header('Cache-Control', 'no-store');
    }

    public function delivery(Request $request, string $provider, DeliveryService $deliveries): JsonResponse
    {
        $p = DeliveryProvider::where('code', $provider)->firstOrFail();
        $status = $deliveries->handleProviderWebhook($p, $request);

        return response()->json(['status' => $status], $status === 'invalid_signature' ? 400 : 200);
    }
}
