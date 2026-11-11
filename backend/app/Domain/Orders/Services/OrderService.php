<?php

namespace App\Domain\Orders\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\PromotionEngine;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus as OS;
use App\Domain\Orders\Events\OrderStatusChanged;
use App\Domain\Orders\Events\OrderUpdated;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderNote;
use App\Domain\Orders\Models\OrderStatusHistory;
use App\Domain\Payments\Services\PaymentService;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Order & fulfillment lifecycle.
 *
 * STOCK DEDUCTION POINT (documented policy): stock is RESERVED when the order is placed and DEDUCTED from on-hand
 * (reservation consumed) when goods physically leave the branch — delivery: on dispatch; pickup: on handover.
 * Cancelling before that point releases the reservation (exactly once); returns re-add stock as customer_return.
 *
 * CUSTOMER CANCELLATION POLICY: auto-approved while every fulfillment is pending/accepted; once any branch is
 * preparing/ready the request needs staff approval (orders.approve); after dispatch/handover → use a return.
 */
class OrderService
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly InventoryLedger $ledger,
        private readonly SlotService $slots,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /** Staff-driven fulfillment transition (authorized for the fulfillment's branch). */
    public function transition(OrderFulfillment $fulfillment, FS $to, ?User $actor, ?string $reason = null, array $meta = [], bool $system = false): OrderFulfillment
    {
        if (! $system) {
            $permission = in_array($to, [FS::Cancelled], true) ? 'orders.approve' : 'orders.edit';
            if (! $this->access->allows($actor, $permission, $fulfillment->branch_id)) {
                throw DomainException::make('branch_forbidden', 403);
            }
        }

        return DB::transaction(function () use ($fulfillment, $to, $actor, $reason, $meta) {
            /** @var OrderFulfillment $f */
            $f = OrderFulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($f->order_id)->lockForUpdate()->firstOrFail();
            $from = $f->status;
            if ($from === $to) {
                return $f;
            }
            if (! $from->canTransitionTo($to)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$from->value)]);
            }
            if ($to !== FS::Cancelled && $order->payment_method === 'sadad' && ! $order->payment_status->isSettled()) {
                throw DomainException::make('order_awaiting_payment', 409);
            }
            if (in_array($to, [FS::Ready, FS::Dispatched, FS::OutForDelivery, FS::Delivered], true) && $f->type === 'pickup') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$from->value)]);
            }
            if ($to === FS::PickupReady && $f->type !== 'pickup') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$from->value)]);
            }

            // Side-effects.
            if (in_array($to, [FS::Dispatched, FS::OutForDelivery], true) && ! $from->stockHandedOver()) {
                $this->consumeAll($f, $actor);
            }
            if ($to === FS::Delivered && ! $from->stockHandedOver()) {
                $this->consumeAll($f, $actor);
            }
            if ($to === FS::Cancelled) {
                $this->releaseAll($f, $actor, $reason ?? 'cancelled');
            }
            if ($to === FS::Returned) {
                $this->restockReturn($f, $actor);
            }

            $timestamps = [
                FS::Accepted->value => 'accepted_at', FS::Ready->value => 'ready_at', FS::PickupReady->value => 'ready_at',
                FS::Dispatched->value => 'dispatched_at', FS::Delivered->value => 'delivered_at', FS::PickedUp->value => 'picked_up_at',
                FS::Cancelled->value => 'cancelled_at',
            ];
            $f->status = $to;
            if (isset($timestamps[$to->value]) && ! $f->{$timestamps[$to->value]}) {
                $f->{$timestamps[$to->value]} = now();
            }
            if ($to === FS::Accepted && $actor) {
                $f->assigned_to = $f->assigned_to ?? $actor->id;
            }
            $f->save();

            $this->history($order, $f, 'fulfillment', $from->value, $to->value, $actor, $reason, $meta);
            DB::afterCommit(fn () => rescue(fn () => event(new DispatchUpdated($f->branch_id, $order->number, 'order', $to->value, null, $f->id)), report: true));
            $this->audit->log('order.fulfillment_'.$to->value, $f, ['status' => $from->value], ['status' => $to->value], ['order' => $order->number, 'reason' => $reason], branchId: $f->branch_id, actor: $actor);
            $this->refreshOrderStatus($order, $actor);
            if ($to === FS::Ready && $f->type === 'delivery') {
                // Auto-dispatch branches hand the delivery to a driver as soon as it is ready; a failure never blocks the order.
                DB::afterCommit(fn () => rescue(fn () => app(DispatchService::class)->onFulfillmentReady($f->fresh()), report: true));
            }

            return $f;
        });
    }

    /**
     * Move a DELIVERY fulfillment forward to $target, passing through intermediate states
     * (pending → accepted → preparing → ready → dispatched → out_for_delivery → delivered). Used by drivers and couriers.
     */
    public function advanceTo(OrderFulfillment $f, FS $target, ?User $actor, ?string $reason = null): OrderFulfillment
    {
        $path = [FS::Accepted, FS::Preparing, FS::Ready, FS::Dispatched, FS::OutForDelivery, FS::Delivered];
        $targetIndex = array_search($target, $path, true);
        foreach ($path as $i => $step) {
            if ($targetIndex === false || $i > $targetIndex) {
                break;
            }
            $f = $f->fresh();
            if ($f->status === $step || ($i < $targetIndex && array_search($f->status, $path, true) > $i)) {
                continue;
            }
            if ($f->status->canTransitionTo($step)) {
                $f = $this->transition($f, $step, $actor, $step === $target ? $reason : null, system: true);
            }
        }

        return $f->fresh();
    }

    /** Recompute the parent status from its fulfillments (cancellation_requested is sticky until decided). */
    public function refreshOrderStatus(Order $order, ?User $actor = null, ?string $reason = null): Order
    {
        $order->load('fulfillments');
        $from = $order->status;
        $to = $this->derive($order);
        if ($from === OS::CancellationRequested && ! in_array($to, [OS::Cancelled, OS::PartiallyCancelled], true)
            && $order->cancellations()->where('status', 'requested')->exists()) {
            return $order;
        }
        if ($from !== $to) {
            $order->status = $to;
            if ($to === OS::Completed && ! $order->completed_at) {
                $order->completed_at = now();
            }
            if ($to === OS::Cancelled && ! $order->cancelled_at) {
                $order->cancelled_at = now();
            }
            if ($to === OS::Confirmed && ! $order->confirmed_at) {
                $order->confirmed_at = now();
            }
            $order->save();
            $this->history($order, null, 'order', $from->value, $to->value, $actor, $reason);
            DB::afterCommit(function () use ($order, $from, $to) {
                event(new OrderStatusChanged($order->id, $from->value, $to->value));
                if ($order->user_id) {
                    event(new OrderUpdated($order->user_id, $order->id, $order->number, $to->value));
                }
            });
        }

        return $order;
    }

    public function derive(Order $order): OS
    {
        $statuses = $order->fulfillments->map(fn ($f) => $f->status);
        $active = $statuses->reject(fn (FS $s) => $s === FS::Cancelled);
        $anyCancelled = $statuses->contains(FS::Cancelled);
        $done = fn (FS $s) => in_array($s, [FS::Delivered, FS::PickedUp], true);

        return match (true) {
            $active->isEmpty() => OS::Cancelled,
            $active->every(fn (FS $s) => $s === FS::Returned) => OS::Returned,
            $active->every(fn (FS $s) => $done($s) || $s === FS::Returned) => $anyCancelled ? OS::PartiallyCancelled : OS::Completed,
            $active->contains($done) => OS::PartiallyFulfilled,
            $active->contains(fn (FS $s) => in_array($s, [FS::Dispatched, FS::OutForDelivery, FS::Failed], true)) => OS::OutForDelivery,
            $active->every(fn (FS $s) => $s === FS::PickupReady) => OS::ReadyForPickup,
            $active->contains(fn (FS $s) => in_array($s, [FS::Accepted, FS::Preparing, FS::Ready, FS::PickupReady], true)) => OS::Processing,
            $order->payment_method === 'sadad' && ! $order->payment_status->isSettled() => OS::PendingPayment,
            default => OS::Confirmed,
        };
    }

    /** @return array{status: string, cancellation?: OrderCancellation} */
    public function requestCancellation(Order $order, User $customer, string $reasonCode, ?string $reason = null): array
    {
        if ($order->user_id !== $customer->id) {
            throw DomainException::make('not_found', 404);
        }
        $order->load('fulfillments');
        $statuses = $order->fulfillments->reject(fn ($f) => $f->status === FS::Cancelled)->map(fn ($f) => $f->status);
        if ($statuses->isEmpty() || $statuses->contains(fn (FS $s) => $s->stockHandedOver() || $s->isTerminal())) {
            throw DomainException::make('order_not_cancellable', 409);
        }
        if ($order->cancellations()->where('status', 'requested')->exists()) {
            throw DomainException::make('cancellation_pending', 409);
        }
        $auto = $statuses->every(fn (FS $s) => in_array($s, [FS::Pending, FS::Accepted], true));
        $request = OrderCancellation::create(['order_id' => $order->id, 'requested_by' => $customer->id, 'requested_by_type' => 'customer',
            'reason_code' => $reasonCode, 'reason' => $reason, 'status' => $auto ? 'approved' : 'requested', 'decided_at' => $auto ? now() : null]);
        if ($auto) {
            $this->cancelOrder($order, null, $reasonCode, $reason, system: true);

            return ['status' => 'approved', 'cancellation' => $request];
        }
        DB::transaction(function () use ($order, $customer, $reasonCode) {
            $from = $order->status;
            $order->update(['status' => OS::CancellationRequested]);
            $this->history($order, null, 'order', $from->value, OS::CancellationRequested->value, $customer, $reasonCode);
        });

        return ['status' => 'requested', 'cancellation' => $request];
    }

    public function decideCancellation(OrderCancellation $request, User $staff, bool $approve, ?string $note = null): void
    {
        $order = $request->order()->with('fulfillments')->first();
        foreach ($order->fulfillments as $f) {
            if (! $this->access->allows($staff, 'orders.approve', $f->branch_id)) {
                throw DomainException::make('branch_forbidden', 403);
            }
        }
        if ($request->status !== 'requested') {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $request->status]);
        }
        $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $staff->id, 'decided_at' => now(), 'decision_note' => $note]);
        if ($approve) {
            $this->cancelOrder($order, $staff, $request->reason_code, $note);
        } else {
            $this->refreshOrderStatus($order->fresh(), $staff, 'cancellation_rejected');
        }
        $this->audit->log('order.cancellation_'.($approve ? 'approved' : 'rejected'), $order, [], ['note' => $note], actor: $staff);
    }

    /**
     * Cancel every not-yet-handed-over fulfillment: releases stock & slots once, voids/refunds payment,
     * reverses loyalty and promotion usage. Never deletes history.
     */
    public function cancelOrder(Order $order, ?User $actor, string $reasonCode, ?string $reason = null, bool $system = false): Order
    {
        DB::transaction(function () use ($order, $actor, $reasonCode, $reason, $system) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('fulfillments')->firstOrFail();
            foreach ($order->fulfillments as $f) {
                if ($f->status->canTransitionTo(FS::Cancelled)) {
                    $this->transition($f, FS::Cancelled, $actor, $reasonCode, ['reason' => $reason], system: $system || $actor === null);
                }
            }
            $order->refresh();
            app(PaymentService::class)->onOrderCancelled($order, $actor, $reasonCode);
            if ($order->user) {
                app(LoyaltyService::class)->reverseForOrder($order->user, $order->id, 'Order cancelled');
            }
            app(PromotionEngine::class)->reverseUsage($order->id);
            $this->audit->log('order.cancelled', $order, [], ['reason_code' => $reasonCode, 'reason' => $reason], actor: $actor);
        });

        return $order->fresh(['fulfillments']);
    }

    public function addNote(Order $order, User $staff, string $body, ?string $fulfillmentId = null, bool $internal = true): OrderNote
    {
        $branchIds = $order->branchIds();
        if (! collect($branchIds)->contains(fn ($b) => $this->access->allows($staff, 'orders.view', $b))) {
            throw DomainException::make('branch_forbidden', 403);
        }

        return OrderNote::create(['order_id' => $order->id, 'order_fulfillment_id' => $fulfillmentId, 'author_id' => $staff->id, 'body' => $body, 'is_internal' => $internal]);
    }

    /** Append an immutable status-history entry (order, fulfillment or payment timeline). */
    public function history(Order $order, ?OrderFulfillment $f, string $kind, ?string $from, string $to, ?User $actor, ?string $reason = null, array $meta = [], bool $visible = true): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id, 'order_fulfillment_id' => $f?->id, 'kind' => $kind, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $actor?->id, 'actor_type' => $actor ? $actor->type->value : 'system', 'reason' => $reason,
            'metadata' => $meta ?: null, 'is_customer_visible' => $visible,
        ]);
    }

    private function consumeAll(OrderFulfillment $f, ?User $actor): void
    {
        foreach (OrderItem::where('order_fulfillment_id', $f->id)->get() as $item) {
            if ($r = $this->reservations->forReference('order_item', $item->id)) {
                // The whole fulfillment leaves the branch: consume what is still reserved for this line.
                $this->reservations->consume($r, null, MovementType::SaleDeduction, $actor?->id, 'order', $f->order_id);
            }
            $item->update(['quantity_handed_over' => $item->activeQuantity()]);
        }
    }

    private function releaseAll(OrderFulfillment $f, ?User $actor, string $reason): void
    {
        foreach (OrderItem::where('order_fulfillment_id', $f->id)->get() as $item) {
            if ($r = $this->reservations->forReference('order_item', $item->id)) {
                $this->reservations->release($r, $reason, actorId: $actor?->id);
            }
            $item->update(['quantity_cancelled' => $item->quantity - $item->quantity_handed_over]);
        }
        $this->slots->release($f->delivery_slot_id, $f->slot_date?->toDateString());
        $f->pickup?->update(['status' => 'cancelled']);
    }

    private function restockReturn(OrderFulfillment $f, ?User $actor): void
    {
        foreach (OrderItem::where('order_fulfillment_id', $f->id)->get() as $item) {
            $qty = $item->quantity_handed_over - $item->quantity_returned;
            if ($qty > 0 && $item->product_variant_id) {
                $this->ledger->apply($f->branch_id, $item->product_variant_id, MovementType::CustomerReturn, $qty, 0, 'order', $f->order_id, 'Fulfillment returned', $actor?->id);
                $item->update(['quantity_returned' => $item->quantity_returned + $qty]);
            }
        }
    }
}
