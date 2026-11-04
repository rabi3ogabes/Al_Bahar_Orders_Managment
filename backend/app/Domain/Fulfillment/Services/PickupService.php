<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Fulfillment\Models\PickupReservation;
use App\Domain\Fulfillment\Models\PickupVerification;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

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
            $issuedAt = now();
            $r->forceFill([
                'status' => 'ready', 'ready_at' => $issuedAt,
                'token_hash' => hash('sha256', $this->tokenFor($r->id, $issuedAt)),
                'token_expires_at' => $issuedAt->copy()->addHours((int) config('platform.pickup.token_ttl_hours')),
                'token_used_at' => null,
            ])->save();
            $this->audit->log('pickup.ready', $r, [], [], branchId: $f->branch_id, actor: $staff);

            return $r;
        });
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
        if (! $this->access->allows($staff, 'pickup.approve', $reservation->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        if ($alternateCollector && ! config('platform.pickup.alternate_collector_enabled')) {
            throw DomainException::make('alternate_collector_not_allowed');
        }

        return DB::transaction(function () use ($reservation, $staff, $items, $collectorName, $alternateCollector, $collection) {
            $r = PickupReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($r->status, ['ready', 'partially_collected'], true) || $r->token_expires_at?->isPast()) {
                throw DomainException::make('pickup_not_ready', 409);
            }
            $f = $r->fulfillment()->with('order')->first();
            $order = $f->order;

            if ($order->payment_method === 'pay_at_pickup' && ! $order->payment_status->isSettled()) {
                if (! $collection) {
                    throw DomainException::make('pickup_payment_required', 409, ['amount' => \App\Support\Money::of($order->grand_total)->format()]);
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
            PickupVerification::create(['pickup_reservation_id' => $r->id, 'verified_by' => $staff->id, 'branch_id' => $r->branch_id, 'result' => 'success',
                'collector_name' => $collectorName, 'is_alternate_collector' => $alternateCollector, 'items_handed_over' => $handed, 'ip' => request()->ip()]);
            if ($complete) {
                $this->orders->transition($f, FS::PickedUp, $staff, null, ['collector' => $collectorName, 'alternate' => $alternateCollector], system: true);
            } else {
                $this->orders->history($order, $f, 'fulfillment', $f->status->value, 'partially_picked_up', $staff, null, ['items' => $handed]);
            }
            $this->audit->log('pickup.handover', $r, [], ['items' => $handed, 'complete' => $complete, 'alternate' => $alternateCollector], branchId: $r->branch_id, actor: $staff);

            return $r->fresh();
        });
    }

    private function tokenFor(string $reservationId, $issuedAt): string
    {
        $raw = hash_hmac('sha256', 'pickup|'.$reservationId.'|'.$issuedAt->format('U'), (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
