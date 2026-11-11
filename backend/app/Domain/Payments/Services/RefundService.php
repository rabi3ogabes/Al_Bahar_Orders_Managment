<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Enums\PaymentStatus as PS;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Refund workflow: requested → approved → processing → processed | failed (or rejected).
 *
 *  - Cumulative refunds (requested + approved + processing + processed) can never exceed the captured amount:
 *    the payment row is locked while the limit is checked.
 *  - Approval requires refunds.approve by someone other than the requester (dual control).
 *  - "original" method uses the gateway refund API only when the provider capability exists; otherwise staff
 *    process the refund manually (cash or bank transfer) with refunds.refund permission, recording the reference.
 *  - Idempotency keys prevent duplicate refund requests on retries.
 */
class RefundService
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccessControl $access) {}

    public function request(Payment $payment, Money $amount, string $reasonCode, ?string $reason, ?User $actor, ?string $idempotencyKey = null, string $method = 'original', bool $system = false): Refund
    {
        if (! $system && ! $this->allowedForPayment($actor, 'refunds.create', $payment)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        if (! $amount->isPositive()) {
            throw DomainException::make('refund_amount_invalid');
        }

        return DB::transaction(function () use ($payment, $amount, $reasonCode, $reason, $actor, $idempotencyKey, $method) {
            if ($idempotencyKey && ($existing = Refund::where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $p->status->isSettled()) {
                throw DomainException::make('refund_payment_not_captured', 409);
            }
            $committed = Money::of((string) (Refund::where('payment_id', $p->id)->whereIn('status', ['requested', 'approved', 'processing', 'processed'])->sum('amount') ?: '0'));
            $remaining = Money::of($p->captured_amount)->minus($committed);
            if ($amount->greaterThan($remaining)) {
                throw DomainException::make('refund_exceeds_captured', 422, ['remaining' => $remaining->format()]);
            }
            if ($method === 'original' && ! (app(PaymentService::class)->gateway($p->method)->capabilities()['refund_api'] ?? false)) {
                $method = $p->method === 'sadad' ? 'manual_bank_transfer' : 'manual_cash';
            }
            $refund = Refund::create([
                'number' => DocumentNumber::make('RF'), 'payment_id' => $p->id, 'order_id' => $p->order_id, 'amount' => $amount->toDecimal(),
                'currency' => $p->currency, 'reason_code' => $reasonCode, 'reason' => $reason, 'method' => $method, 'status' => 'requested',
                'idempotency_key' => $idempotencyKey, 'requested_by' => $actor?->id,
            ]);
            $this->audit->log('refund.requested', $refund, [], ['amount' => $refund->amount, 'method' => $method, 'reason' => $reasonCode], actor: $actor);

            return $refund;
        });
    }

    public function approve(Refund $refund, User $approver): Refund
    {
        if (! $this->allowedForPayment($approver, 'refunds.approve', $refund->payment)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        if ($refund->requested_by === $approver->id && ! $approver->isSuperAdmin()) {
            throw DomainException::make('approval_segregation', 403);
        }

        return DB::transaction(function () use ($refund, $approver) {
            $r = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'requested') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $r->status]);
            }
            $r->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
            $this->audit->log('refund.approved', $r, ['status' => 'requested'], ['status' => 'approved'], actor: $approver);

            if ($r->method === 'original') {
                return $this->processViaGateway($r, $approver);
            }

            return $r;
        });
    }

    public function reject(Refund $refund, User $approver, string $note): Refund
    {
        if (! $this->allowedForPayment($approver, 'refunds.approve', $refund->payment) || $refund->status !== 'requested') {
            throw DomainException::make('branch_forbidden', 403);
        }
        $refund->update(['status' => 'rejected', 'approved_by' => $approver->id, 'failure_reason' => $note]);
        $this->audit->log('refund.rejected', $refund, [], ['note' => $note], actor: $approver);

        return $refund;
    }

    /** Manual refund completion (cash handed back / bank transfer sent) with the external reference. */
    public function markProcessed(Refund $refund, User $staff, string $reference): Refund
    {
        if (! $this->allowedForPayment($staff, 'refunds.refund', $refund->payment)) {
            throw DomainException::make('branch_forbidden', 403);
        }

        return DB::transaction(function () use ($refund, $staff, $reference) {
            $r = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! in_array($r->status, ['approved', 'processing'], true)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $r->status]);
            }
            $this->settle($r, $staff, $reference);

            return $r->fresh();
        });
    }

    private function processViaGateway(Refund $r, User $actor): Refund
    {
        $r->update(['status' => 'processing']);
        try {
            $result = app(PaymentService::class)->gateway($r->payment->method)->refund($r);
            $result->status === 'refunded' ? $this->settle($r, $actor, $result->providerReference) : $r->update(['status' => 'processing']);
        } catch (\Throwable $e) {
            $r->update(['status' => 'failed', 'failure_reason' => $e instanceof DomainException ? $e->getMessage() : 'gateway_error']);
            report($e);
        }

        return $r->fresh();
    }

    private function settle(Refund $r, User $actor, ?string $reference): void
    {
        $p = Payment::whereKey($r->payment_id)->lockForUpdate()->firstOrFail();
        $refunded = Money::of($p->refunded_amount)->plus(Money::of($r->amount));
        if ($refunded->greaterThan(Money::of($p->captured_amount))) {
            throw DomainException::make('refund_exceeds_captured', 422, ['remaining' => $p->refundable()->format()]);
        }
        $r->update(['status' => 'processed', 'processed_by' => $actor->id, 'processed_at' => now(), 'provider_reference' => $reference]);
        $p->forceFill(['refunded_amount' => $refunded->toDecimal()])->save();
        $full = $refunded->equals(Money::of($p->captured_amount));
        app(PaymentService::class)->setStatus($p, $full ? PS::Refunded : PS::PartiallyRefunded, 'refund '.$r->number);

        if ($p->order_id) {
            $order = Order::whereKey($p->order_id)->lockForUpdate()->firstOrFail();
            $order->forceFill(['refunded_total' => Money::of($order->refunded_total)->plus(Money::of($r->amount))->toDecimal(),
                'payment_status' => $full ? PS::Refunded : PS::PartiallyRefunded])->save();
            if ($full && $order->user) {
                app(LoyaltyService::class)->reverseForOrder($order->user, $order->id, 'Order refunded');
            }
        }
        $this->audit->log('refund.processed', $r, [], ['amount' => $r->amount, 'reference' => $reference], actor: $actor);
    }

    private function allowedForPayment(?User $user, string $permission, Payment $payment): bool
    {
        $branchIds = $payment->order?->branchIds() ?? [];
        if (! $branchIds) {
            return $this->access->allowsGlobally($user, $permission);
        }

        return collect($branchIds)->every(fn ($b) => $this->access->allows($user, $permission, $b));
    }
}
