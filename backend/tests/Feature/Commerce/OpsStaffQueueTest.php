<?php

use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Orders/OpsFixtures.php';

/** An online (SADAD) order of branch A that is still waiting for its payment. */
function opsUnpaidOnlineOrder(object $w): Order
{
    opsSadad();
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);

    return Order::with('fulfillments')->find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'sadad'])->assertCreated()->json('data.order.id'));
}

/** A pickup order of branch A (a lamp), paid at the counter, not touched yet. */
function opsPickupLamp(object $w): Order
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);

    return Order::with('fulfillments.pickup')->find(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
}

// ── Bug 6: the staff app lists only what a branch can act on ───────────────────────────────────────────────────────────────────

it('leaves an online order that is not paid yet out of the queue and the counts, and shows it once it is paid', function () {
    $w = commerceWorld();
    $order = opsUnpaidOnlineOrder($w);
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));

    expect($order->status->value)->toBe('pending_payment');
    $this->getJson('/api/v1/admin/dispatch/queue?branch_id='.$w->a->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/dispatch/queue?stage=new&branch_id='.$w->a->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$w->a->id)->assertOk()->assertJsonPath('data.new', 0)->assertJsonPath('data.preparing', 0);
    // ...and the order cannot be accepted anyway (what the app showed as "New" used to fail with order_awaiting_payment).
    $this->postJson("/api/v1/admin/fulfillments/{$order->fulfillments->first()->id}/transition", ['status' => 'accepted'])->assertStatus(409)->assertJsonPath('code', 'order_awaiting_payment');

    app(PaymentService::class)->markPaid(Payment::where('order_id', $order->id)->firstOrFail());
    $rows = $this->getJson('/api/v1/admin/dispatch/queue?stage=new&branch_id='.$w->a->id)->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['number'])->toBe($order->number)->and($rows[0]['payment_method'])->toBe('sadad')
        ->and($mgr->id)->not->toBeEmpty();
    $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$w->a->id)->assertOk()->assertJsonPath('data.new', 1);
    $this->postJson("/api/v1/admin/fulfillments/{$rows[0]['fulfillment_id']}/transition", ['status' => 'accepted'])->assertOk();
});

it('does not announce an unpaid online order to the branch, and announces it when the payment arrives', function () {
    $w = commerceWorld();
    Event::fake([DispatchUpdated::class]);
    $order = opsUnpaidOnlineOrder($w); // placed through HTTP: its deferred messages have been sent by now
    Event::assertNotDispatched(DispatchUpdated::class);

    app(PaymentService::class)->markPaid(Payment::where('order_id', $order->id)->firstOrFail());
    // Payment is not an HTTP request here, so the deferred messages are run by hand.
    app(DeferredCallbackCollection::class)->invoke();
    Event::assertDispatched(DispatchUpdated::class, fn (DispatchUpdated $e) => $e->branchId === $w->a->id && $e->orderNumber === $order->number && ($e->payload['stage'] ?? null) === 'new');
});

it('does not hide cash orders, which can be accepted at once', function () {
    $w = commerceWorld();
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    actingAsStaff($mgr);
    $this->getJson('/api/v1/admin/dispatch/queue?stage=new&branch_id='.$w->a->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_id', $orderId);
});

// ── Bug 6: pickup parts, opt in ─────────────────────────────────────────────────────────────────────────────────────────────────

it('keeps the default queue and overview exactly as they were and adds the part type to every row', function () {
    $w = commerceWorld();
    $pickup = opsPickupLamp($w);
    [$f, $mgr] = readyOrder($w);
    actingAsStaff($mgr);

    $rows = $this->getJson('/api/v1/admin/dispatch/queue?branch_id='.$w->a->id)->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['fulfillment_id'])->toBe($f->id)->and($rows[0]['type'])->toBe('delivery');
    $overview = $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$w->a->id)->assertOk()->json('data');
    expect($overview['new'])->toBe(0)->and($overview['ready'])->toBe(1)->and(array_slice(array_keys($overview), 0, 9))->toBe(['branch', 'new', 'preparing', 'ready', 'out_for_delivery', 'failed', 'waiting_for_driver', 'drivers_on_duty', 'drivers_total'])
        ->and(array_slice(array_keys($overview), 9))->toBe(['pickup', 'branches', 'can']); // the staff app's additions (docs/api/STAFF_QUEUE.md) come after the old keys
    expect($pickup->fulfillments->first()->type)->toBe('pickup');
});

it('lists the branch\'s pickup parts too with include_pickup=1: by stage, with their type and the counts', function () {
    $w = commerceWorld();
    $pickup = opsPickupLamp($w);
    $pf = $pickup->fulfillments->first();
    [$f, $mgr] = readyOrder($w); // a delivery part, ready (signed in as its manager)
    actingAsStaff($mgr);

    $new = $this->getJson('/api/v1/admin/dispatch/queue?include_pickup=1&stage=new&branch_id='.$w->a->id)->assertOk()->json('data');
    expect($new)->toHaveCount(1)->and($new[0]['fulfillment_id'])->toBe($pf->id)->and($new[0]['type'])->toBe('pickup')->and($new[0]['status'])->toBe('pending');

    // Ready to collect counts as "ready" next to the delivery part that is ready for a driver.
    app(PickupService::class)->markReady($pf->fresh(), staffWithRole('pickup_employee', $w->a));
    $ready = collect($this->getJson('/api/v1/admin/dispatch/queue?include_pickup=1&stage=ready&branch_id='.$w->a->id)->assertOk()->json('data'))->keyBy('type');
    expect($ready->keys()->sort()->values()->all())->toBe(['delivery', 'pickup'])->and($ready['pickup']['status'])->toBe('pickup_ready')->and($ready['delivery']['fulfillment_id'])->toBe($f->id);
    $this->getJson('/api/v1/admin/dispatch/overview?include_pickup=1&branch_id='.$w->a->id)->assertOk()->assertJsonPath('data.ready', 2)->assertJsonPath('data.waiting_for_driver', 1);
    // Without the flag the pickup part is still not there.
    $this->getJson('/api/v1/admin/dispatch/queue?stage=ready&branch_id='.$w->a->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'delivery');
    $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$w->a->id)->assertOk()->assertJsonPath('data.ready', 1);
});

it('keeps pickup parts of an unpaid online order and of other branches out of the opt-in queue as well', function () {
    $w = commerceWorld();
    opsSadad();
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $online = Order::with('fulfillments')->find(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'sadad'])->assertCreated()->json('data.order.id'));
    $mgrB = actingAsStaff(staffWithRole('order_manager', $w->b));

    expect($online->status->value)->toBe('pending_payment');
    actingAsStaff(staffWithRole('order_manager', $w->a));
    $this->getJson('/api/v1/admin/dispatch/queue?include_pickup=1&branch_id='.$w->a->id)->assertOk()->assertJsonCount(0, 'data');
    actingAsStaff($mgrB);
    $this->getJson('/api/v1/admin/dispatch/queue?include_pickup=1&branch_id='.$w->a->id)->assertForbidden();
});

it('treats a malformed include_pickup as off', function () {
    $w = commerceWorld();
    opsPickupLamp($w);
    actingAsStaff(staffWithRole('order_manager', $w->a));
    $this->getJson('/api/v1/admin/dispatch/queue?include_pickup=maybe&branch_id='.$w->a->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/dispatch/queue?include_pickup=true&branch_id='.$w->a->id)->assertOk()->assertJsonCount(1, 'data');
    expect(FS::Pending->value)->toBe('pending');
});
