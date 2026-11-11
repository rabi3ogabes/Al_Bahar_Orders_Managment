<?php

use App\Domain\Orders\Events\OrderUpdated;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Facades\Event;

it('puts the add-to-bag variant on product cards that have only one', function () {
    $w = commerceWorld();
    $card = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))->firstWhere('id', $w->p1->id);
    expect($card['variant_id'])->toBe($w->v1->id)->and($card['has_variants'])->toBeFalse();
});

it('pings the customer in real time on private-users when the order or its delivery moves', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $f = Order::with('fulfillments')->find($orderId)->fulfillments->first();

    Event::fake([OrderUpdated::class]);
    actingAsStaff(staffWithRole('order_manager', $w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'accepted'])->assertOk();

    Event::assertDispatched(OrderUpdated::class, fn (OrderUpdated $e) => $e->userId === $w->customer->id && $e->orderId === $orderId && $e->status === 'processing');
    $e = new OrderUpdated($w->customer->id, $orderId, 'AB1', 'processing', 'accepted');
    expect($e->broadcastAs())->toBe('order.updated')->and($e->broadcastOn()[0]->name)->toBe('private-users.'.$w->customer->id)
        ->and($e->broadcastWith())->toHaveKeys(['order_id', 'number', 'status', 'status_label', 'delivery_status']);
});
