<?php

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;

beforeEach(function () {
    $this->w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 2]);
    $this->orderId = quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $this->order = Order::with('fulfillments')->find($this->orderId);
    $this->f = $this->order->fulfillments->first();
});

it('auto-approves early customer cancellation and releases stock exactly once', function () {
    $this->postJson("/api/v1/orders/{$this->orderId}/cancel", ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.status', 'approved');
    expect($this->order->fresh()->status->value)->toBe('cancelled')->and(stockOf($this->w->a, $this->w->v1))->toBe(['on_hand' => 5, 'reserved' => 0]);

    // A second cancellation attempt and the expiry job do not release again.
    $this->postJson("/api/v1/orders/{$this->orderId}/cancel", ['reason_code' => 'changed_mind'])->assertStatus(409);
    app(\App\Domain\Inventory\Services\ReservationService::class)->expireDue();
    expect(StockMovement::where('type', 'reservation_release')->count())->toBe(1);
});

it('requires staff approval to cancel once preparation started', function () {
    $mgr = staffWithRole('order_manager', $this->w->a);
    app(OrderService::class)->transition($this->f, FS::Accepted, $mgr);
    app(OrderService::class)->transition($this->f->fresh(), FS::Preparing, $mgr);

    $this->postJson("/api/v1/orders/{$this->orderId}/cancel", ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.status', 'requested');
    expect($this->order->fresh()->status->value)->toBe('cancellation_requested')->and(stockOf($this->w->a, $this->w->v1)['reserved'])->toBe(2);

    actingAsStaff($mgr);
    $c = $this->order->cancellations()->first();
    $this->postJson("/api/v1/admin/cancellations/{$c->id}/decide", ['approve' => true])->assertOk();
    expect($this->order->fresh()->status->value)->toBe('cancelled')->and(stockOf($this->w->a, $this->w->v1)['reserved'])->toBe(0);
});

it('deducts stock at dispatch and completes on delivery with a full timeline', function () {
    $mgr = actingAsStaff(staffWithRole('order_manager', $this->w->a));
    foreach (['accepted', 'preparing', 'ready', 'dispatched'] as $s) {
        $this->postJson("/api/v1/admin/fulfillments/{$this->f->id}/transition", ['status' => $s])->assertOk();
    }
    expect(stockOf($this->w->a, $this->w->v1))->toBe(['on_hand' => 3, 'reserved' => 0])->and($this->order->fresh()->status->value)->toBe('out_for_delivery');
    $this->postJson("/api/v1/admin/fulfillments/{$this->f->id}/transition", ['status' => 'delivered'])->assertOk();
    expect($this->order->fresh()->status->value)->toBe('completed');

    actingAsCustomer($this->w->customer);
    $timeline = $this->getJson("/api/v1/orders/{$this->orderId}/timeline")->json('data.*.status');
    expect($timeline)->toContain('confirmed', 'accepted', 'dispatched', 'delivered', 'completed');
    $this->postJson("/api/v1/orders/{$this->orderId}/cancel", ['reason_code' => 'changed_mind'])->assertStatus(409);
});

it('rejects invalid state transitions', function () {
    actingAsStaff(staffWithRole('order_manager', $this->w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$this->f->id}/transition", ['status' => 'delivered'])->assertStatus(409)->assertJsonPath('code', 'invalid_state_transition');
});

it('prevents a branch employee from managing another branch\'s fulfillment', function () {
    actingAsStaff(staffWithRole('order_manager', $this->w->b));
    $this->postJson("/api/v1/admin/fulfillments/{$this->f->id}/transition", ['status' => 'accepted'])->assertForbidden();
    $this->getJson("/api/v1/admin/orders/{$this->orderId}")->assertForbidden();
    $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(0, 'data');
});

it('reorders available items into the cart and serves a PDF invoice', function () {
    $this->postJson("/api/v1/orders/{$this->orderId}/reorder")->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
    $res = $this->get("/api/v1/orders/{$this->orderId}/invoice");
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('pdf');
    expect(\App\Domain\Orders\Models\OrderInvoice::where('order_id', $this->orderId)->exists())->toBeTrue();
});
