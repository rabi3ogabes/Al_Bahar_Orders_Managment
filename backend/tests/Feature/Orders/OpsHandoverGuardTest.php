<?php

use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Models\DeliveryProviderEvent;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Orders\Services\ParcelShares;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Livewire\Admin\Sales\OrderShow;
use App\Support\DomainException;
use App\Support\Money;
use Livewire\Livewire;

require_once __DIR__.'/OpsFixtures.php';

/** A pickup order of branch A (3 sofas + 1 lamp) paid at the counter, its pass issued; returns [order, fulfillment, staff]. */
function opsPickupOrder(): array
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 3]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $order = Order::with('fulfillments.pickup')->find(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
    $f = $order->fulfillments->first();
    $staff = staffWithRole('branch_manager', $w->a);
    app(PickupService::class)->markReady($f, $staff);

    return [$order, $f->fresh(), $staff, $w];
}

// ── Bug 2: a whole-order cancellation after goods left the branch ────────────────────────────────────────────────────────────────

it('refuses to cancel a whole order when one of its parts is already on the road, and changes nothing', function () {
    $s = opsSplitOrder();
    $driverA = driverFor($s->w->a);
    opsWalk($s->fa, [FS::Accepted, FS::Preparing, FS::Ready]);
    opsWalk($s->fb, [FS::Accepted, FS::Preparing]);
    actingAsStaff($s->mgrA);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$s->fa->id}/assign", ['driver_id' => $driverA->id])->assertOk();
    $delivery = $s->fa->deliveries()->firstOrFail();
    actingAsStaff($driverA);
    foreach (['accept', 'pickup'] as $step) {
        $this->postJson("/api/v1/admin/deliveries/{$delivery->id}/{$step}")->assertOk();
    }
    $before = ['on_hand' => stockOf($s->w->b, $s->w->v1)['on_hand'], 'reserved' => stockOf($s->w->b, $s->w->v1)['reserved']];

    actingAsStaff(staffWithRole('admin')); // company-wide manager
    $this->postJson("/api/v1/admin/orders/{$s->order->id}/cancel", ['reason_code' => 'other', 'reason' => 'changed'])->assertStatus(409)
        ->assertJsonPath('code', 'order_partly_handed_over')->assertJsonPath('message', __('ops_integrity.errors.order_partly_handed_over'));
    expect($s->fb->fresh()->status->value)->toBe('preparing')->and($s->fa->fresh()->status->value)->toBe('dispatched')
        ->and($delivery->fresh()->status->value)->toBe('picked_up')->and(Payment::where('order_id', $s->order->id)->firstOrFail()->status->value)->toBe('pending')
        ->and(['on_hand' => stockOf($s->w->b, $s->w->v1)['on_hand'], 'reserved' => stockOf($s->w->b, $s->w->v1)['reserved']])->toBe($before);
});

it('keeps the customer\'s cancellation request open and tells the manager clearly when goods already left', function () {
    $s = opsSplitOrder();
    opsWalk($s->fa, [FS::Accepted, FS::Preparing]);
    opsWalk($s->fb, [FS::Accepted, FS::Preparing]);
    actingAsCustomer($s->w->customer);
    $this->postJson("/api/v1/orders/{$s->order->id}/cancel", ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.status', 'requested');
    $request = OrderCancellation::where('order_id', $s->order->id)->firstOrFail();

    // What a race (or older code) can leave behind: a part already dispatched while the request was waiting.
    $s->fa->forceFill(['status' => 'dispatched'])->save();
    $mgr = staffWithRole('admin');
    actingAsStaff($mgr);
    $this->postJson("/api/v1/admin/cancellations/{$request->id}/decide", ['approve' => true])->assertStatus(409)->assertJsonPath('code', 'order_partly_handed_over');
    expect($request->fresh()->status)->toBe('requested')->and($request->fresh()->decided_at)->toBeNull()->and($s->fb->fresh()->status->value)->toBe('preparing');

    // The manager can still decline it: the order carries on.
    $this->postJson("/api/v1/admin/cancellations/{$request->id}/decide", ['approve' => false, 'note' => 'goods are on the way'])->assertOk();
    expect($request->fresh()->status)->toBe('rejected');
});

it('does not let the customer ask to cancel an order that is partly handed over', function () {
    [$order, $f, $staff, $w] = opsPickupOrder();
    $item = $f->items()->where('product_variant_id', $w->v1->id)->first();
    $r = $f->pickup->fresh();
    app(PickupService::class)->handover($r, $staff, [$item->id => 1], 'Noor', collection: ['tender' => 'cash', 'amount' => $order->grand_total]);
    expect($f->fresh()->status->value)->toBe('pickup_ready'); // a part handed over, the rest not: the part stays "ready for pickup"

    actingAsCustomer($w->customer);
    $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason_code' => 'changed_mind'])->assertStatus(409)->assertJsonPath('code', 'order_not_cancellable');
});

it('refuses a staff cancellation of a pickup order that is partly handed over, and allows it before the first hand-over', function () {
    [$order, $f, $staff, $w] = opsPickupOrder();
    $mgr = actingAsStaff($staff);
    $item = $f->items()->where('product_variant_id', $w->v1->id)->first();
    app(PickupService::class)->handover($f->pickup->fresh(), $staff, [$item->id => 1], 'Noor', collection: ['tender' => 'cash', 'amount' => $order->grand_total]);

    $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason_code' => 'other', 'reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'order_partly_handed_over');
    expect($order->fresh()->status->value)->not->toBe('cancelled')->and($f->fresh()->status->value)->toBe('pickup_ready');
    expect($mgr->id)->toBe($staff->id);

    [$other, $of] = opsPickupOrder2($w);
    $this->postJson("/api/v1/admin/orders/{$other->id}/cancel", ['reason_code' => 'other', 'reason' => 'x'])->assertOk();
    expect($other->fresh()->status->value)->toBe('cancelled')->and($of->fresh()->status->value)->toBe('cancelled');
});

/** A second pickup order in the same world (nothing handed over yet). */
function opsPickupOrder2(object $w): array
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $order = Order::with('fulfillments.pickup')->find(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
    actingAsStaff(staffWithRole('branch_manager', $w->a));

    return [$order, $order->fulfillments->first()];
}

// ── Bug 2 (money): cancelling one part must never refund more than that part's share ─────────────────────────────────────────────

it('cash on delivery: cancelling one part takes exactly its share off what is owed, the other part collects the rest', function () {
    $s = opsSplitOrder();
    $payment = Payment::where('order_id', $s->order->id)->firstOrFail();
    $total = Money::of($payment->amount);
    $shares = ParcelShares::split($total, $s->order->fulfillments);
    actingAsStaff(staffWithRole('admin'));

    $this->postJson("/api/v1/admin/fulfillments/{$s->fb->id}/transition", ['status' => 'cancelled', 'reason' => 'out of stock'])->assertOk();
    $payment->refresh();
    expect(Money::of($payment->amount)->toDecimal())->toBe($total->minus($shares[$s->fb->id])->toDecimal())
        ->and($payment->status->value)->toBe('pending')->and(Refund::count())->toBe(0)
        ->and($s->order->fresh()->status->value)->not->toBe('cancelled')->and($s->fb->fresh()->status->value)->toBe('cancelled');
});

it('paid online: cancelling one part asks finance to refund exactly that part\'s share, once, never the whole order', function () {
    $s = opsSplitOrder('sadad');
    $payment = Payment::where('order_id', $s->order->id)->firstOrFail();
    $captured = Money::of($payment->captured_amount);
    $shares = ParcelShares::split($captured, $s->order->fulfillments);
    actingAsStaff(staffWithRole('admin'));

    $this->postJson("/api/v1/admin/fulfillments/{$s->fb->id}/transition", ['status' => 'cancelled'])->assertOk();
    $refunds = Refund::where('payment_id', $payment->id)->get();
    expect($refunds)->toHaveCount(1)->and($refunds[0]->status)->toBe('requested')->and($refunds[0]->amount)->toBe($shares[$s->fb->id]->toDecimal())
        ->and(Money::of($refunds[0]->amount)->lessThan($captured))->toBeTrue()->and($payment->fresh()->status->value)->toBe('paid');

    // A second cancel of the same part (a retry) asks for nothing more.
    app(OrderService::class)->transition($s->fb->fresh(), FS::Cancelled, null, system: true);
    expect(Refund::where('payment_id', $payment->id)->count())->toBe(1);

    // The other part cancelled too: the order is cancelled and what is left to refund is exactly the other part's share (the total never exceeds what was paid).
    $this->postJson("/api/v1/admin/fulfillments/{$s->fa->id}/transition", ['status' => 'cancelled'])->assertOk();
    expect($s->order->fresh()->status->value)->toBe('cancelled')
        ->and(Money::of((string) Refund::where('payment_id', $payment->id)->sum('amount'))->toDecimal())->toBe($captured->toDecimal());
});

// ── Bug 3: no hand-over while a cancellation request waits ───────────────────────────────────────────────────────────────────────────

it('refuses to hand goods over while the customer\'s cancellation request is undecided, and lets them go once it is declined', function () {
    $d = opsAssignedDelivery('accepted');
    actingAsCustomer($d->w->customer);
    $this->postJson("/api/v1/orders/{$d->order->id}/cancel", ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.status', 'requested');

    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/pickup")->assertStatus(409)->assertJsonPath('code', 'cancellation_pending')
        ->assertJsonPath('message', __('ops_integrity.errors.cancellation_pending'));
    expect($d->delivery->fresh()->status->value)->toBe('accepted')->and($d->f->fresh()->status->value)->toBe('ready')
        ->and(stockOf($d->w->a, $d->w->v1)['on_hand'])->toBe(5); // nothing was deducted

    actingAsStaff($d->mgr);
    foreach (['dispatched', 'out_for_delivery'] as $status) {
        $this->postJson("/api/v1/admin/fulfillments/{$d->f->id}/transition", ['status' => $status])->assertStatus(409)->assertJsonPath('code', 'cancellation_pending');
    }
    expect($d->f->fresh()->status->value)->toBe('ready');

    $request = OrderCancellation::where('order_id', $d->order->id)->firstOrFail();
    $this->postJson("/api/v1/admin/cancellations/{$request->id}/decide", ['approve' => false])->assertOk();
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/pickup")->assertOk()->assertJsonPath('data.status', 'picked_up');
    expect($d->f->fresh()->status->value)->toBe('dispatched');
});

it('lets the branch keep preparing and readying while a cancellation request waits', function () {
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $f = $order->fulfillments->first();
    $mgr = staffWithRole('order_manager', $w->a);
    app(OrderService::class)->transition($f, FS::Accepted, $mgr);
    app(OrderService::class)->transition($f->fresh(), FS::Preparing, $mgr);
    $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.status', 'requested');

    actingAsStaff($mgr);
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'ready'])->assertOk();
    expect($f->fresh()->status->value)->toBe('ready')->and($order->fresh()->status->value)->toBe('cancellation_requested');
});

it('refuses the pickup hand-over while a cancellation request waits, even for part of the goods', function () {
    [$order, $f, $staff, $w] = opsPickupOrder();
    // A request on a pickup order that is not handed over yet.
    actingAsCustomer($w->customer);
    $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.status', 'requested');
    $item = $f->items()->where('product_variant_id', $w->v1->id)->first();

    actingAsStaff($staff);
    $this->postJson("/api/v1/admin/pickup/{$f->pickup->id}/handover", ['items' => [$item->id => 1], 'collection' => ['tender' => 'cash', 'amount' => $order->grand_total]])
        ->assertStatus(409)->assertJsonPath('code', 'cancellation_pending');
    expect($item->fresh()->quantity_handed_over)->toBe(0)->and($f->pickup->fresh()->status)->toBe('ready');
});

it('refuses a courier\'s picked-up message for an order whose cancellation waits, and records it instead of failing', function () {
    $d = opsAssignedDelivery('accepted');
    $provider = DeliveryProvider::create(['code' => 'mockc', 'name' => 'Mock Courier', 'driver' => 'mock', 'is_active' => true, 'webhook_secret' => 'whsec', 'settings' => []]);
    $d->delivery->forceFill(['mode' => 'provider', 'delivery_provider_id' => $provider->id, 'provider_reference' => 'MOCK-1', 'driver_id' => null])->save();
    actingAsCustomer($d->w->customer);
    $this->postJson("/api/v1/orders/{$d->order->id}/cancel", ['reason_code' => 'changed_mind'])->assertOk();

    $json = json_encode(['event_id' => 'c1', 'reference' => 'MOCK-1', 'status' => 'picked_up']);
    $this->call('POST', '/webhooks/delivery/mockc', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_MOCK_SIGNATURE' => hash_hmac('sha256', $json, 'whsec')], $json)->assertOk()->assertJsonPath('status', 'rejected');
    expect($d->f->fresh()->status->value)->toBe('ready')->and($d->delivery->fresh()->status->value)->toBe('accepted')
        ->and(DeliveryProviderEvent::where('provider_event_id', 'c1')->value('error'))->toBe('cancellation_pending');
});

it('the delivery service itself refuses pickUp during a pending cancellation (not only the HTTP layer)', function () {
    $d = opsAssignedDelivery('accepted');
    OrderCancellation::create(['order_id' => $d->order->id, 'requested_by' => $d->w->customer->id, 'requested_by_type' => 'customer', 'reason_code' => 'changed_mind', 'status' => 'requested']);
    expect(fn () => app(DeliveryService::class)->pickUp($d->delivery, $d->driver))->toThrow(DomainException::class, __('ops_integrity.errors.cancellation_pending'));
});

it('hides "Cancel order" on the order page once goods left the branch, explains why, and still refuses it if asked', function () {
    $s = opsSplitOrder();
    opsWalk($s->fa, [FS::Accepted, FS::Preparing, FS::Ready, FS::Dispatched]);
    opsWalk($s->fb, [FS::Accepted, FS::Preparing]);
    $this->actingAs(staffWithRole('admin'));

    $page = Livewire::test(OrderShow::class, ['order' => $s->order])->assertViewHas('cancellable', false)->assertViewHas('partlyHandedOver', true)
        ->assertSee(__('ops_integrity.ui.partly_handed_over'));
    $page->call('open', 'cancel_order')->set('form.reason', 'changed my mind')->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect($s->fb->fresh()->status->value)->toBe('preparing')->and($s->order->fresh()->status->value)->not->toBe('cancelled');

    // The part still at the branch can be cancelled on its own, and the dialog says what happens to the money.
    $page->call('open', 'cancel_fulfillment', $s->fb->id)->assertSee(__('ops_integrity.ui.cancel_part_hint'))->set('form.reason', 'out of stock')->call('confirmDialog')->assertHasNoErrors();
    expect($s->fb->fresh()->status->value)->toBe('cancelled');
});
