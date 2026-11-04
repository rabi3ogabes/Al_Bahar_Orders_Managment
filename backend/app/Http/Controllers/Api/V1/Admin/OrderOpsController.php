<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\PickupReservation;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderPresenter;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Payments\Services\RefundService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff order operations. Orders are visible to staff of ANY branch that fulfills part of them; every mutation is
 * authorized for the specific fulfillment's branch inside the domain services.
 *
 * @tags Admin · Orders
 */
class OrderOpsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $scope = $this->scope('orders.view', $request->query('branch_id'));
        $q = Order::query()->with(['fulfillments.branch:id,code'])
            ->whereIn('id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('payment_status'), fn ($q, $s) => $q->where('payment_status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('customer_phone', 'like', "%{$s}%")->orWhere('customer_name', 'ilike', "%{$s}%")))
            ->orderByDesc('placed_at');

        return response()->json($q->paginate($this->perPage($request)));
    }

    public function show(Order $order, OrderPresenter $presenter): JsonResponse
    {
        $this->assertCanSee($order);

        return response()->json(['data' => $presenter->detail($order) + [
            'internal_notes' => $order->notes()->with('author:id,name')->latest()->get(),
            'history' => $order->history()->get(),
            'payments' => Payment::where('order_id', $order->id)->with('refunds', 'collections')->get(),
            'cancellations' => $order->cancellations()->get(),
        ]]);
    }

    public function transition(Request $request, OrderFulfillment $fulfillment, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:accepted,preparing,ready,dispatched,out_for_delivery,delivered,cancelled,returned', 'reason' => 'nullable|string|max:500']);
        $f = $orders->transition($fulfillment, FulfillmentStatus::from($data['status']), $this->user(), $data['reason'] ?? null);

        return response()->json(['data' => ['id' => $f->id, 'status' => $f->status->value, 'order_status' => $f->order->fresh()->status->value]]);
    }

    public function decideCancellation(Request $request, OrderCancellation $cancellation, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:500']);
        $orders->decideCancellation($cancellation, $this->user(), (bool) $data['approve'], $data['note'] ?? null);

        return response()->json(['data' => $cancellation->fresh()]);
    }

    public function cancel(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['reason_code' => 'required|string|max:48', 'reason' => 'required|string|max:500']);
        foreach ($order->fulfillments as $f) {
            $this->authorizeBranch('orders.approve', $f->branch_id);
        }

        return response()->json(['data' => ['status' => $orders->cancelOrder($order, $this->user(), $data['reason_code'], $data['reason'])->status->value]]);
    }

    public function note(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:2000', 'fulfillment_id' => 'nullable|uuid']);

        return response()->json(['data' => $orders->addNote($order, $this->user(), $data['body'], $data['fulfillment_id'] ?? null)], 201);
    }

    public function pickupReady(OrderFulfillment $fulfillment, PickupService $pickup): JsonResponse
    {
        $r = $pickup->markReady($fulfillment, $this->user());

        return response()->json(['data' => ['status' => $r->status, 'token_expires_at' => $r->token_expires_at?->toIso8601String()]]);
    }

    public function pickupVerify(Request $request, PickupService $pickup): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:200', 'branch_id' => 'required|uuid']);
        $res = $pickup->verify($data['code'], $this->user(), $data['branch_id']);
        $f = $res['fulfillment'];

        return response()->json(['data' => [
            'result' => $res['result'],
            'reservation_id' => $res['reservation']?->id,
            'order' => $f ? ['number' => $f->order->number, 'customer_name' => $f->order->customer_name, 'payment_method' => $f->order->payment_method,
                'payment_status' => $f->order->payment_status->value, 'amount_due' => Money::of($f->order->grand_total)->minus(Money::of($f->order->paid_total))->jsonSerialize()] : null,
            'items' => $f ? $f->items->map(fn ($i) => ['id' => $i->id, 'name' => $i->name(), 'variant' => $i->variantName(), 'sku' => $i->sku,
                'quantity' => $i->activeQuantity(), 'handed_over' => $i->quantity_handed_over]) : [],
        ]], $res['result'] === 'success' ? 200 : 422);
    }

    public function pickupHandover(Request $request, PickupReservation $reservation, PickupService $pickup): JsonResponse
    {
        $data = $request->validate(['items' => 'nullable|array', 'items.*' => 'integer|min:0', 'collector_name' => 'nullable|string|max:120',
            'alternate_collector' => 'boolean', 'collection' => 'nullable|array', 'collection.tender' => 'required_with:collection|in:cash,card_terminal',
            'collection.amount' => 'required_with:collection|numeric|min:0', 'collection.receipt_reference' => 'nullable|string|max:120']);
        $r = $pickup->handover($reservation, $this->user(), $data['items'] ?? [], $data['collector_name'] ?? null, (bool) ($data['alternate_collector'] ?? false), $data['collection'] ?? null);

        return response()->json(['data' => ['status' => $r->status]]);
    }

    public function createDelivery(Request $request, OrderFulfillment $fulfillment, DeliveryService $deliveries): JsonResponse
    {
        $data = $request->validate(['mode' => 'required|in:internal,provider', 'provider_id' => 'required_if:mode,provider|nullable|uuid|exists:delivery_providers,id',
            'scheduled_for' => 'nullable|date', 'driver_id' => 'nullable|uuid|exists:users,id']);
        $d = $deliveries->create($fulfillment, $this->user(), $data['mode'], $data['provider_id'] ?? null, $data['scheduled_for'] ?? null);
        if (! empty($data['driver_id'])) {
            $d = $deliveries->assignDriver($d, User::findOrFail($data['driver_id']), $this->user());
        }

        return response()->json(['data' => $d], 201);
    }

    public function deliveryAction(Request $request, Delivery $delivery, string $action, DeliveryService $svc): JsonResponse
    {
        $u = $this->user();
        $d = match ($action) {
            'assign' => $svc->assignDriver($delivery, User::findOrFail($request->validate(['driver_id' => 'required|uuid'])['driver_id']), $u),
            'pickup' => $svc->pickUp($delivery, $u),
            'out-for-delivery' => $svc->outForDelivery($delivery, $u, $request->float('latitude') ?: null, $request->float('longitude') ?: null),
            'deliver' => $svc->deliver($delivery, $u, $request->input('recipient_name'), $request->file('proof'), $request->input('proof_type', 'photo'),
                $request->has('cod') ? $request->validate(['cod.tender' => 'required|in:cash,card_terminal', 'cod.amount' => 'required|numeric|min:0', 'cod.receipt_reference' => 'nullable|string'])['cod'] : null),
            'fail' => $svc->fail($delivery, $u, $request->validate(['reason_code' => 'required|in:customer_unreachable,customer_absent,wrong_address,refused,damaged,access_denied,other'])['reason_code'], $request->input('notes')),
            'reschedule' => $svc->reschedule($delivery, $u, $request->validate(['scheduled_for' => 'required|date|after:now'])['scheduled_for']),
            'return' => $svc->returnToBranch($delivery, $u),
            'cancel' => $svc->cancel($delivery, $u, $request->validate(['reason' => 'required|string|max:500'])['reason']),
            'retry-booking' => $svc->book($delivery),
            default => abort(404),
        };

        return response()->json(['data' => $d]);
    }

    /** Driver's own task list (mobile). */
    public function myDeliveries(Request $request): JsonResponse
    {
        $rows = Delivery::with(['fulfillment.order:id,number,customer_name,customer_phone,address_snapshot,payment_method,payment_status,grand_total', 'fulfillment.branch:id,name_en,name_ar'])
            ->where('driver_id', $this->user()->id)->whereNotIn('status', ['delivered', 'returned', 'cancelled'])
            ->orderBy('scheduled_for')->get();

        return response()->json(['data' => $rows]);
    }

    public function collect(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $data = $request->validate(['fulfillment_id' => 'required|uuid', 'tender' => 'required|in:cash,card_terminal', 'amount' => 'required|numeric|min:0',
            'receipt_reference' => 'nullable|string|max:120']);
        $f = OrderFulfillment::where('order_id', $payment->order_id)->findOrFail($data['fulfillment_id']);

        return response()->json(['data' => $payments->recordCollection($payment, $f, $this->user(), $data['tender'], (string) $data['amount'], $data['receipt_reference'] ?? null)], 201);
    }

    public function requestRefund(Request $request, Payment $payment, RefundService $refunds): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason_code' => 'required|string|max:48', 'reason' => 'nullable|string|max:1000',
            'method' => 'nullable|in:original,manual_cash,manual_bank_transfer']);
        $key = $request->header('Idempotency-Key');

        return response()->json(['data' => $refunds->request($payment, Money::of((string) $data['amount']), $data['reason_code'], $data['reason'] ?? null, $this->user(), $key, $data['method'] ?? 'original')], 201);
    }

    public function refundAction(Request $request, Refund $refund, string $action, RefundService $refunds): JsonResponse
    {
        $r = match ($action) {
            'approve' => $refunds->approve($refund, $this->user()),
            'reject' => $refunds->reject($refund, $this->user(), $request->validate(['note' => 'required|string|max:500'])['note']),
            'processed' => $refunds->markProcessed($refund, $this->user(), $request->validate(['reference' => 'required|string|max:128'])['reference']),
            default => abort(404),
        };

        return response()->json(['data' => $r]);
    }

    private function assertCanSee(Order $order): void
    {
        $ok = collect($order->branchIds())->contains(fn ($b) => app(\App\Domain\Identity\Services\AccessControl::class)->allows($this->user(), 'orders.view', $b));
        abort_unless($ok, 403);
    }
}
