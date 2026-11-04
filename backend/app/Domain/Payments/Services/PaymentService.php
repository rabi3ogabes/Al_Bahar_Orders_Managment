<?php

namespace App\Domain\Payments\Services;

use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Data\GatewayResult;
use App\Domain\Payments\Enums\PaymentStatus as PS;
use App\Domain\Payments\Events\PaymentStatusChanged;
use App\Domain\Payments\Gateways\OfflineGateway;
use App\Domain\Payments\Gateways\SadadGateway;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentTransaction;
use App\Domain\Payments\Models\PaymentWebhookEvent;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use App\Support\Money;
use App\Support\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payment lifecycle. The customer client can never change payment state: only verified provider callbacks,
 * server-to-server status checks, scheduled expiry, or authorized staff collections do.
 */
class PaymentService
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccessControl $access) {}

    public function gateway(string $method): PaymentGateway
    {
        return match ($method) {
            'sadad' => app(SadadGateway::class),
            'cod', 'pay_at_pickup' => app(OfflineGateway::class),
            default => throw DomainException::make('payment_method_unavailable'),
        };
    }

    public function createForOrder(Order $order, ?string $idempotencyKey = null): Payment
    {
        $online = $order->payment_method === 'sadad';
        $attempt = (int) Payment::where('payable_type', 'order')->where('payable_id', $order->id)->max('attempt') + 1;

        return Payment::create([
            'payable_type' => 'order', 'payable_id' => $order->id, 'order_id' => $order->id, 'user_id' => $order->user_id,
            'method' => $order->payment_method, 'provider' => $online ? 'sadad' : 'offline', 'status' => PS::Pending,
            'amount' => $order->grand_total, 'currency' => $order->currency, 'attempt' => $attempt,
            'idempotency_key' => $idempotencyKey ?? 'order:'.$order->id.':'.$attempt,
            'expires_at' => $online ? $order->payment_due_at : null,
        ]);
    }

    /** What the client must do next (redirect for SADAD, nothing for offline methods). */
    public function action(Payment $payment): array
    {
        $base = ['payment_id' => $payment->id, 'method' => $payment->method, 'status' => $payment->status->value,
            'return_url_scheme' => 'albahar://payments/'.$payment->id.'/return', 'expires_at' => $payment->expires_at?->toIso8601String()];
        if ($payment->status !== PS::Pending || $payment->method !== 'sadad') {
            return $base + ['action' => 'none', 'redirect_url' => null];
        }
        $init = $this->gateway('sadad')->initiate($payment);
        $this->transaction($payment, 'initiate', 'sadad', 'pending', null, ['form_action' => $init['form']['action'] ?? null], null);

        return $base + ['action' => 'redirect', 'redirect_url' => $init['redirect_url']];
    }

    /** Retry an online payment for an unpaid order (new attempt; same order; no duplicate charge). */
    public function retry(Order $order, string $idempotencyKey): Payment
    {
        return DB::transaction(function () use ($order, $idempotencyKey) {
            if ($existing = Payment::where('idempotency_key', 'retry:'.$order->id.':'.$idempotencyKey)->first()) {
                return $existing;
            }
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== OrderStatus::PendingPayment || $order->payment_method !== 'sadad' || $order->payment_due_at?->isPast()) {
                throw DomainException::make('payment_retry_not_allowed', 409);
            }
            $open = Payment::where('order_id', $order->id)->whereIn('status', [PS::Pending->value, PS::Processing->value])->lockForUpdate()->get();
            foreach ($open as $p) {
                // Before abandoning an attempt, ask the provider whether it was actually paid (customer closed the app).
                if ($result = $this->gateway('sadad')->fetchStatus($p)) {
                    $this->apply($p, $result, 'status_check');
                    if ($p->fresh()->status->isSettled()) {
                        return $p->fresh();
                    }
                }
                $this->setStatus($p, PS::Cancelled, 'superseded_by_retry');
            }

            return $this->createForOrder($order, 'retry:'.$order->id.':'.$idempotencyKey);
        });
    }

    /** Handle a provider callback/webhook. Idempotent: duplicate events are acknowledged and ignored. */
    public function handleCallback(string $provider, Request $request): array
    {
        $gateway = $this->gateway($provider);
        $result = $gateway->verifyCallback($request);
        $payloadHash = hash('sha256', $request->getContent() ?: json_encode($request->all()));

        if (! $result->authentic) {
            PaymentWebhookEvent::firstOrCreate(['provider' => $provider, 'provider_event_id' => 'invalid:'.$payloadHash], [
                'payload' => Redactor::redact($request->all()), 'signature_valid' => false, 'status' => 'failed', 'error' => $result->message, 'ip' => $request->ip(), 'payload_hash' => $payloadHash]);
            $this->audit->log('payment.webhook_rejected', 'PaymentWebhookEvent', [], [], ['provider' => $provider, 'reason' => $result->message, 'ip' => $request->ip()]);

            return ['ok' => false, 'status' => 'invalid_signature'];
        }

        try {
            $event = DB::transaction(fn () => PaymentWebhookEvent::create(['provider' => $provider, 'provider_event_id' => $result->eventId ?: $payloadHash, 'payload_hash' => $payloadHash,
                'payload' => Redactor::redact($result->raw), 'signature_valid' => true, 'status' => 'received', 'ip' => $request->ip()]));
        } catch (UniqueConstraintViolationException) {
            return ['ok' => true, 'status' => 'duplicate'];
        }

        $payment = $result->paymentReference ? Payment::find($result->paymentReference) : null;
        if (! $payment || $payment->provider !== $provider) {
            $event->update(['status' => 'ignored', 'error' => 'unknown_payment', 'processed_at' => now()]);

            return ['ok' => true, 'status' => 'ignored'];
        }

        // Prefer an authoritative server-to-server confirmation when the provider offers one.
        $confirmed = $result->status === 'paid' ? ($gateway->fetchStatus($payment) ?? $result) : $result;
        $outcome = $this->apply($payment, $confirmed, 'callback', $result->raw);
        $event->update(['status' => 'processed', 'payment_id' => $payment->id, 'processed_at' => now()]);

        return ['ok' => true, 'status' => $outcome];
    }

    /** Re-check pending online payments with the provider (scheduled; resolves ambiguous/missed callbacks). */
    public function reconcilePending(): int
    {
        $n = 0;
        Payment::where('provider', 'sadad')->whereIn('status', [PS::Pending->value, PS::Processing->value])->where('created_at', '<', now()->subMinutes(3))
            ->limit(200)->get()->each(function (Payment $p) use (&$n) {
                if ($r = $this->gateway('sadad')->fetchStatus($p)) {
                    $this->apply($p, $r, 'status_check');
                    $n++;
                }
            });

        return $n;
    }

    /** Apply a verified provider result to a payment under lock, respecting the state machine. */
    public function apply(Payment $payment, GatewayResult $r, string $type, array $raw = []): string
    {
        return DB::transaction(function () use ($payment, $r, $type, $raw) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->transaction($p, $type, $p->provider, $r->status, $r->providerReference, null, $raw ?: $r->raw, $r->amount);

            if ($r->status === 'paid') {
                if ($p->status->isSettled()) {
                    return 'already_paid';
                }
                if ($p->status->isFinal()) {
                    // Money captured after the attempt expired/was cancelled (very late callback). The order and stock are
                    // already released, so never resurrect it: flag for finance to refund/reconcile, and acknowledge.
                    $p->forceFill(['metadata' => array_merge($p->metadata ?? [], ['late_capture' => [
                        'provider_reference' => $r->providerReference, 'amount' => $r->amount, 'at' => now()->toIso8601String(),
                    ]])])->save();
                    $this->audit->log('payment.late_capture_review', $p, [], ['amount' => $r->amount, 'reference' => $r->providerReference]);

                    return 'late_capture_review';
                }
                if ($r->amount !== null && ! Money::of($r->amount)->equals(Money::of($p->amount))) {
                    // Never accept a different amount silently: hold for finance review.
                    $this->setStatus($p, PS::Processing, 'amount_mismatch');
                    $this->audit->log('payment.amount_mismatch', $p, [], ['expected' => $p->amount, 'reported' => $r->amount]);

                    return 'amount_mismatch';
                }
                $this->markPaid($p, $r->providerReference);

                return 'paid';
            }
            if (in_array($r->status, ['failed', 'cancelled'], true)) {
                // Out-of-order safety: a late failure never overrides a payment that is already settled.
                if ($p->status->isSettled() || $p->status->isFinal()) {
                    return 'ignored_out_of_order';
                }
                $this->setStatus($p, $r->status === 'failed' ? PS::Failed : PS::Cancelled, $r->message);

                return $r->status;
            }
            if ($r->status === 'pending' && $p->status === PS::Pending) {
                $this->setStatus($p, PS::Processing, 'provider_pending');
            }

            return 'pending';
        });
    }

    public function markPaid(Payment $p, ?string $providerReference = null, ?Money $amount = null): void
    {
        $amount ??= Money::of($p->amount);
        $p->forceFill(['provider_reference' => $providerReference ?? $p->provider_reference, 'captured_amount' => $amount->toDecimal(), 'paid_at' => now()]);
        $this->setStatus($p, PS::Paid);

        if ($p->payable_type === 'auction_deposit') {
            app(AuctionService::class)->depositPaid($p);
        }
        if ($p->order_id) {
            $order = Order::whereKey($p->order_id)->lockForUpdate()->firstOrFail();
            $order->forceFill(['payment_status' => PS::Paid, 'paid_total' => Money::of($order->paid_total)->plus($amount)->toDecimal()])->save();
            app(OrderService::class)->history($order, null, 'payment', null, PS::Paid->value, auth()->user(), null, ['payment_id' => $p->id]);
            // Paid online orders keep their stock: remove the reservation expiry.
            StockReservation::where('reference_type', 'order_item')->whereIn('reference_id', $order->items()->pluck('id'))
                ->where('status', 'active')->update(['expires_at' => null]);
            app(OrderService::class)->refreshOrderStatus($order, null, 'payment_received');
            if ($order->auction_winner_id) {
                AuctionWinner::whereKey($order->auction_winner_id)->update(['status' => 'paid', 'paid_at' => now()]);
            }
        }
    }

    /** Cancel unpaid online orders whose payment window elapsed (releases stock). */
    public function expireUnpaidOrders(): int
    {
        $n = 0;
        Order::where('status', OrderStatus::PendingPayment->value)->where('payment_method', 'sadad')->where('payment_due_at', '<', now())
            ->limit(200)->get()->each(function (Order $order) use (&$n) {
                // Last chance: the provider may have captured it while callbacks were delayed.
                foreach (Payment::where('order_id', $order->id)->whereIn('status', ['pending', 'processing'])->get() as $p) {
                    if ($r = $this->gateway('sadad')->fetchStatus($p)) {
                        $this->apply($p, $r, 'status_check');
                    }
                }
                if ($order->fresh()->payment_status->isSettled()) {
                    return;
                }
                Payment::where('order_id', $order->id)->whereIn('status', ['pending', 'processing'])->get()->each(fn ($p) => $this->setStatus($p, PS::Expired, 'payment_window_elapsed'));
                $order->forceFill(['payment_status' => PS::Expired])->save();
                app(OrderService::class)->cancelOrder($order, null, 'payment_expired', null, system: true);
                $n++;
            });

        return $n;
    }

    /**
     * Record COD or pay-at-pickup money taken by an employee. Payment becomes paid when the full amount is collected
     * (partial collection only if the business allows it via settings payments.allow_partial_collection).
     */
    public function recordCollection(Payment $payment, OrderFulfillment $fulfillment, User $staff, string $tender, string $amount, ?string $receiptRef = null, string $status = 'collected', ?string $failureReason = null): CashCollection
    {
        if (! in_array($payment->method, ['cod', 'pay_at_pickup'], true) || $payment->order_id !== $fulfillment->order_id) {
            throw DomainException::make('payment_method_unavailable');
        }
        if (! $this->access->allows($staff, 'payments.create', $fulfillment->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }

        return DB::transaction(function () use ($payment, $fulfillment, $staff, $tender, $amount, $receiptRef, $status, $failureReason) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($p->status->isSettled()) {
                throw DomainException::make('payment_already_settled', 409);
            }
            $due = Money::of($p->amount)->minus(Money::of($p->captured_amount));
            $got = Money::of($amount);
            $allowPartial = (bool) app(Settings::class)->get('payments.allow_partial_collection', false);
            if ($status === 'collected' && $got->lessThan($due) && ! $allowPartial) {
                throw DomainException::make('collection_amount_mismatch', 422, ['due' => $due->format()]);
            }
            if ($got->greaterThan($due)) {
                throw DomainException::make('collection_amount_mismatch', 422, ['due' => $due->format()]);
            }
            $collection = CashCollection::create([
                'payment_id' => $p->id, 'order_fulfillment_id' => $fulfillment->id, 'branch_id' => $fulfillment->branch_id, 'collected_by' => $staff->id,
                'context' => $p->method, 'tender' => $tender, 'amount_due' => $due->toDecimal(), 'amount_collected' => $status === 'failed' ? '0.00' : $got->toDecimal(),
                'status' => $status === 'failed' ? 'failed' : ($got->lessThan($due) ? 'partial' : 'collected'),
                'receipt_reference' => $receiptRef, 'failure_reason' => $failureReason, 'collected_at' => now(),
            ]);
            $this->transaction($p, 'collection', 'offline', $collection->status, $receiptRef, null, ['tender' => $tender, 'collected_by' => $staff->id], $collection->amount_collected);
            if ($collection->status === 'collected') {
                $this->markPaid($p, $receiptRef, Money::of($p->captured_amount)->plus($got));
            } elseif ($collection->status === 'partial') {
                $p->forceFill(['captured_amount' => Money::of($p->captured_amount)->plus($got)->toDecimal()])->save();
            }
            $this->audit->log('payment.cash_collected', $collection, [], ['amount' => $collection->amount_collected, 'tender' => $tender, 'status' => $collection->status],
                branchId: $fulfillment->branch_id, actor: $staff);

            return $collection;
        });
    }

    /** Called by OrderService when an order is cancelled: void unpaid payments; request refunds for captured ones. */
    public function onOrderCancelled(Order $order, ?User $actor, string $reason): void
    {
        foreach (Payment::where('order_id', $order->id)->lockForUpdate()->get() as $p) {
            if (in_array($p->status, [PS::Pending, PS::Processing, PS::Authorized], true)) {
                $this->setStatus($p, PS::Cancelled, 'order_cancelled');
            } elseif ($p->status->isSettled() && $p->refundable()->isPositive()) {
                app(RefundService::class)->request($p, $p->refundable(), 'order_cancelled', $reason, $actor, 'cancel:'.$order->id.':'.$p->id, system: true);
            }
        }
        if (! $order->payment_status->isSettled()) {
            $order->forceFill(['payment_status' => $order->payment_status === PS::Expired ? PS::Expired : PS::Cancelled])->save();
        }
    }

    public function setStatus(Payment $p, PS $to, ?string $reason = null): void
    {
        $from = $p->status;
        if ($from === $to) {
            return;
        }
        if (! $from->canTransitionTo($to)) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $from->value]);
        }
        $p->status = $to;
        if ($to === PS::Failed) {
            $p->failed_at = now();
            $p->failure_reason = $reason;
        }
        $p->save();
        if ($p->order_id && ! in_array($to, [PS::Paid], true)) {
            $order = Order::find($p->order_id);
            if ($order && ! $order->payment_status->isSettled() && in_array($to, [PS::Processing, PS::Failed], true)) {
                $order->forceFill(['payment_status' => $to])->save();
            }
            if ($order) {
                app(OrderService::class)->history($order, null, 'payment', $from->value, $to->value, auth()->user(), $reason, ['payment_id' => $p->id], visible: $to !== PS::Processing);
            }
        }
        DB::afterCommit(fn () => event(new PaymentStatusChanged($p->id, $from->value, $to->value)));
    }

    private function transaction(Payment $p, string $type, string $gateway, string $status, ?string $reference, ?array $request, ?array $response, ?string $amount = null): void
    {
        try {
            // Savepoint: in PostgreSQL a failed INSERT would otherwise abort the enclosing transaction.
            DB::transaction(fn () => PaymentTransaction::create(['payment_id' => $p->id, 'type' => $type, 'gateway' => $gateway, 'transaction_reference' => $reference,
                'status' => $status, 'amount' => $amount, 'request' => $request ? Redactor::redact($request) : null, 'response' => $response ? Redactor::redact($response) : null]));
        } catch (UniqueConstraintViolationException) {
            // Same provider reference/type already recorded (duplicate delivery) — the ledger stays unique.
        }
    }
}
