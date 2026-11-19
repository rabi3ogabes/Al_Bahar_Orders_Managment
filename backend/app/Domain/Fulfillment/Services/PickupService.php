<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Fulfillment\Models\PickupReservation;
use App\Domain\Fulfillment\Models\PickupVerification;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Services\OpsError;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Store pickup with opaque QR passes.
 *
 * The QR payload is "ABPK1.<token>" where token = base64url(HMAC-SHA256(app key, reservation id | issued-at)).
 * It contains no order number or personal data, is unguessable, verifiable only server-side (sha256 stored for
 * lookup), expires after platform.pickup.token_ttl_hours, and becomes invalid once everything is collected.
 * Every scan — success or failure — is recorded in pickup_verifications.
 */
class PickupService
{
    private const PREFIX = 'ABPK1.';

    public function __construct(
        private readonly OrderService $orders,
        private readonly ReservationService $reservations,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /** Mark a pickup fulfillment ready and issue (or rotate) its pass. */
    public function markReady(OrderFulfillment $f, User $staff): PickupReservation
    {
        if (! $this->access->allows($staff, 'pickup.edit', $f->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }

        return DB::transaction(function () use ($f, $staff) {
            if ($f->status !== FS::PickupReady) {
                // Authorized above via pickup.edit for this branch; the order transitions are internal steps.
                if ($f->status === FS::Pending || $f->status === FS::Accepted) {
                    $this->orders->transition($f, FS::Preparing, $staff, system: true);
                    $f->refresh();
                }
                $this->orders->transition($f, FS::PickupReady, $staff, system: true);
            }
            $r = PickupReservation::where('order_fulfillment_id', $f->id)->lockForUpdate()->firstOrFail();
            $this->issueToken($r, resetStatus: true);
            $this->audit->log('pickup.ready', $r, [], [], branchId: $f->branch_id, actor: $staff);

            return $r;
        });
    }

    /** Wrong order-number / phone-ending tries allowed per staff member and order before the fallback hand-over locks for MANUAL_LOCK_MINUTES. */
    public const MANUAL_ATTEMPTS = 5;

    public const MANUAL_LOCK_MINUTES = 15;

    /**
     * Gives the customer a NEW pass for a part that is ready for pickup (or partly collected): the old one stops working at once, the new one lasts a
     * full pass lifetime, the customer is told, and who did it and why is written to the audit log. This is the way out when a pass expired (72 h) or
     * the customer lost it; unlike markReady() it never takes a part that was handed over in pieces back to "ready".
     */
    public function reissue(OrderFulfillment $f, User $staff, ?string $reason = null): PickupReservation
    {
        if (! $this->access->allows($staff, 'pickup.edit', $f->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }

        return DB::transaction(function () use ($f, $staff, $reason) {
            $part = OrderFulfillment::whereKey($f->id)->lockForUpdate()->firstOrFail();
            $r = PickupReservation::where('order_fulfillment_id', $part->id)->lockForUpdate()->first();
            if ($part->type !== 'pickup' || $part->status !== FS::PickupReady || $r === null || ! in_array($r->status, ['ready', 'partially_collected'], true)) {
                throw DomainException::make('pickup_not_ready', 409);
            }
            $order = Order::findOrFail($part->order_id);
            $wasExpired = $r->token_expires_at?->isPast() ?? false;
            $this->issueToken($r, resetStatus: false);
            $this->audit->log('pickup.pass_reissued', $r, ['token_expires_at' => $wasExpired ? 'expired' : 'valid'], ['token_expires_at' => $r->token_expires_at?->toIso8601String()],
                ['order' => $order->number, 'reason' => $reason, 'was_expired' => $wasExpired], branchId: $part->branch_id, actor: $staff);
            DB::afterCommit(fn () => rescue(fn () => $this->tellCustomer($order), report: true));

            return $r->fresh();
        });
    }

    /**
     * Hand the goods over WITHOUT the pass: a manager at the counter (pickup.approve at the branch) types the order number and the last four digits of
     * the phone number on the order, and says why the pass is not used. It works for an expired pass or a lost phone, hands over exactly like the
     * scanner does (stock, pay-at-pickup collection, partial hand-over) and is recorded in the audit log with the reason and in the pickup log.
     * Guarded against guessing: five wrong tries per staff member and order lock it for fifteen minutes; the error never says which detail was wrong.
     *
     * @param  array<string, int>  $items  order item id → quantity handed over now (empty = everything outstanding)
     */
    public function handoverByDetails(string $orderNumber, string $phoneLast4, User $staff, string $branchId, string $reason, array $items = [], ?string $collectorName = null, ?array $collection = null): PickupReservation
    {
        if (! $this->access->allows($staff, 'pickup.approve', $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw OpsError::make('pickup_reason_required', 422);
        }
        $number = strtoupper(trim($orderNumber));
        $key = 'pickup:manual:'.$staff->id.':'.hash('sha256', $number);
        if ((int) Cache::get($key, 0) >= self::MANUAL_ATTEMPTS) {
            throw OpsError::make('pickup_manual_locked', 429, ['minutes' => self::MANUAL_LOCK_MINUTES]);
        }

        $order = Order::where('number', $number)->first();
        $f = $order ? OrderFulfillment::where('order_id', $order->id)->where('branch_id', $branchId)->where('type', 'pickup')->where('status', FS::PickupReady->value)->first() : null;
        $r = $f ? PickupReservation::where('order_fulfillment_id', $f->id)->whereIn('status', ['ready', 'partially_collected'])->first() : null;
        $digits = preg_replace('/\D+/', '', (string) $order?->customer_phone) ?? '';
        $given = preg_replace('/\D+/', '', $phoneLast4) ?? '';
        if ($order === null || $r === null || strlen($digits) < 4 || strlen($given) !== 4 || ! hash_equals(substr($digits, -4), $given)) {
            Cache::put($key, (int) Cache::get($key, 0) + 1, now()->addMinutes(self::MANUAL_LOCK_MINUTES));
            PickupVerification::create(['pickup_reservation_id' => $r?->id, 'verified_by' => $staff->id, 'branch_id' => $branchId, 'result' => 'manual_failed', 'ip' => request()->ip()]);
            $this->audit->log('pickup.manual_handover_failed', 'PickupReservation', [], [], ['order' => $number, 'reason' => $reason], branchId: $branchId, actor: $staff);
            throw OpsError::make('pickup_details_mismatch', 422);
        }
        Cache::forget($key);

        return $this->hand($r, $staff, $items, $collectorName ?? $order->customer_name, false, $collection, $reason);
    }

    /** The customer's pass (only for their own order and while ready). */
    public function pass(PickupReservation $r): ?array
    {
        if (! in_array($r->status, ['ready', 'partially_collected'], true) || ! $r->ready_at) {
            return null;
        }

        return [
            'qr_payload' => self::PREFIX.$this->tokenFor($r->id, $r->ready_at),
            'expires_at' => $r->token_expires_at?->toIso8601String(),
            'status' => $r->status,
        ];
    }

    /**
     * Verify a scanned pass at the counter.
     *
     * @return array{result: string, reservation: ?PickupReservation, fulfillment: ?OrderFulfillment}
     */
    public function verify(string $scanned, User $staff, string $branchId): array
    {
        if (! $this->access->allows($staff, 'pickup.view', $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        $token = str_starts_with($scanned, self::PREFIX) ? substr($scanned, strlen(self::PREFIX)) : $scanned;
        $r = PickupReservation::where('token_hash', hash('sha256', $token))->first();
        $result = match (true) {
            ! $r => 'invalid_token',
            $r->branch_id !== $branchId => 'wrong_branch',
            $r->status === 'collected' || $r->token_used_at !== null => 'already_used',
            $r->status === 'cancelled' => 'invalid_token',
            $r->token_expires_at?->isPast() => 'expired',
            ! in_array($r->status, ['ready', 'partially_collected'], true) => 'not_ready',
            default => 'success',
        };
        PickupVerification::create(['pickup_reservation_id' => $r?->id, 'verified_by' => $staff->id, 'branch_id' => $branchId, 'result' => $result, 'ip' => request()->ip()]);
        if ($result !== 'success') {
            $this->audit->log('pickup.verification_failed', 'PickupReservation', [], [], ['result' => $result], branchId: $branchId, actor: $staff);
        }

        return ['result' => $result, 'reservation' => $result === 'success' ? $r : null,
            'fulfillment' => $result === 'success' ? $r->fulfillment()->with(['items', 'order'])->first() : null];
    }

    /**
     * Hand over items (all or part). For pay-at-pickup orders the collection must be recorded first or in this call.
     *
     * @param  array<string, int>  $items  order item id → quantity handed over now (empty = everything outstanding)
     */
    public function handover(PickupReservation $reservation, User $staff, array $items = [], ?string $collectorName = null, bool $alternateCollector = false, ?array $collection = null): PickupReservation
    {
        return $this->hand($reservation, $staff, $items, $collectorName, $alternateCollector, $collection, null);
    }

    /** @param  string|null  $manualReason  set when a manager hands over without the pass: an expired pass is then not a reason to refuse */
    private function hand(PickupReservation $reservation, User $staff, array $items, ?string $collectorName, bool $alternateCollector, ?array $collection, ?string $manualReason): PickupReservation
    {
        if (! $this->access->allows($staff, 'pickup.approve', $reservation->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        if ($alternateCollector && ! config('platform.pickup.alternate_collector_enabled')) {
            throw DomainException::make('alternate_collector_not_allowed');
        }

        return DB::transaction(function () use ($reservation, $staff, $items, $collectorName, $alternateCollector, $collection, $manualReason) {
            $r = PickupReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($r->status, ['ready', 'partially_collected'], true) || ($manualReason === null && $r->token_expires_at?->isPast())) {
                throw DomainException::make('pickup_not_ready', 409);
            }
            $f = $r->fulfillment()->with('order')->first();
            $order = $f->order;
            $this->orders->assertHandoverAllowed($order); // no goods leave while the customer's cancellation waits for a manager

            if ($order->payment_method === 'pay_at_pickup' && ! $order->payment_status->isSettled()) {
                if (! $collection) {
                    throw DomainException::make('pickup_payment_required', 409, ['amount' => Money::of($order->grand_total)->format()]);
                }
                $payment = Payment::where('order_id', $order->id)->latest('attempt')->firstOrFail();
                app(PaymentService::class)->recordCollection($payment, $f, $staff, $collection['tender'], $collection['amount'], $collection['receipt_reference'] ?? null);
            } elseif ($order->payment_method === 'sadad' && ! $order->payment_status->isSettled()) {
                throw DomainException::make('order_awaiting_payment', 409);
            }

            $handed = [];
            foreach (OrderItem::where('order_fulfillment_id', $f->id)->lockForUpdate()->get() as $item) {
                $outstanding = $item->activeQuantity() - $item->quantity_handed_over;
                $qty = $items === [] ? $outstanding : (int) ($items[$item->id] ?? 0);
                if ($qty < 0 || $qty > $outstanding) {
                    throw DomainException::make('quantity_invalid');
                }
                if ($qty === 0) {
                    continue;
                }
                $reservationRow = $this->reservations->forReference('order_item', $item->id);
                if (! $reservationRow || ! $this->reservations->consumePart($reservationRow, $qty, $staff->id, 'order', $order->id)) {
                    throw DomainException::make('stock_would_go_negative', 409);
                }
                $item->update(['quantity_handed_over' => $item->quantity_handed_over + $qty]);
                $handed[] = ['order_item_id' => $item->id, 'quantity' => $qty];
            }
            if (! $handed) {
                throw DomainException::make('quantity_invalid');
            }
            $complete = OrderItem::where('order_fulfillment_id', $f->id)->get()->every(fn ($i) => $i->quantity_handed_over >= $i->activeQuantity());
            $r->forceFill(['status' => $complete ? 'collected' : 'partially_collected', 'collected_at' => $complete ? now() : null,
                'token_used_at' => $complete ? now() : null, 'handed_over_by' => $staff->id,
                'alternate_collector_name' => $alternateCollector ? $collectorName : $r->alternate_collector_name])->save();
            PickupVerification::create(['pickup_reservation_id' => $r->id, 'verified_by' => $staff->id, 'branch_id' => $r->branch_id, 'result' => $manualReason === null ? 'success' : 'manual',
                'collector_name' => $collectorName, 'is_alternate_collector' => $alternateCollector, 'items_handed_over' => $handed, 'ip' => request()->ip()]);
            if ($complete) {
                $this->orders->transition($f, FS::PickedUp, $staff, null, ['collector' => $collectorName, 'alternate' => $alternateCollector], system: true);
            } else {
                $this->orders->history($order, $f, 'fulfillment', $f->status->value, 'partially_picked_up', $staff, null, ['items' => $handed]);
            }
            $this->audit->log($manualReason === null ? 'pickup.handover' : 'pickup.manual_handover', $r, [], ['items' => $handed, 'complete' => $complete, 'alternate' => $alternateCollector],
                $manualReason === null ? [] : ['reason' => $manualReason, 'order' => $order->number, 'checked' => 'phone_last4', 'pass_expired' => $r->token_expires_at?->isPast() ?? false], branchId: $r->branch_id, actor: $staff);

            return $r->fresh();
        });
    }

    /**
     * A fresh pass: the old token stops matching at once. The token comes from the second it was issued, so a second pass issued within the same second
     * is dated one second later (two passes never share a token).
     */
    private function issueToken(PickupReservation $r, bool $resetStatus): void
    {
        $issuedAt = now()->toImmutable()->startOfSecond();
        if ($r->ready_at !== null && $issuedAt->lessThanOrEqualTo($r->ready_at->startOfSecond())) {
            $issuedAt = $r->ready_at->startOfSecond()->addSecond();
        }
        $attributes = ['ready_at' => $issuedAt, 'token_hash' => hash('sha256', $this->tokenFor($r->id, $issuedAt)),
            'token_expires_at' => $issuedAt->addHours((int) config('platform.pickup.token_ttl_hours')), 'token_used_at' => null];
        if ($resetStatus) {
            $attributes['status'] = 'ready';
        }
        $r->forceFill($attributes)->save();
    }

    /** The customer is told their (new) pass is ready: the same message as when the order first became ready for pickup, with the same link to the pass. */
    private function tellCustomer(Order $order): void
    {
        $user = $order->user;
        if ($user === null) {
            return;
        }
        app(NotificationService::class)->notify($user, 'order.ready_for_pickup', ['order_number' => $order->number, 'total' => Money::of($order->grand_total)->format($order->locale ?: 'en'),
            'name' => Str::before($order->customer_name, ' '), 'deep_link' => 'albahar://orders/'.$order->id, 'reference' => $order->id]);
    }

    private function tokenFor(string $reservationId, $issuedAt): string
    {
        $raw = hash_hmac('sha256', 'pickup|'.$reservationId.'|'.$issuedAt->format('U'), (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
