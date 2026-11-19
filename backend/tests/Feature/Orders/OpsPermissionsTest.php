<?php

use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Sales\OrderShow;
use Livewire\Livewire;

require_once __DIR__.'/OpsFixtures.php';
require_once __DIR__.'/AdjustmentFixtures.php';

/** A cash on delivery order of branch A, accepted; returns [order, fulfillment, world]. */
function opsAcceptedOrder(int $qty = 1): array
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => $qty]);
    $order = Order::with('fulfillments')->find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id'));
    $f = $order->fulfillments->first();
    opsWalk($f, [FS::Accepted]);

    return [$order, $f->fresh(), $w];
}

// ── Bug 4: POST fulfillments/{id}/transition needs the right permission for the step ──────────────────────────────────────────

it('lets the order desk accept, prepare and ready a part but not hand goods over, return or cancel it', function () {
    [$order, $f, $w] = opsAcceptedOrder();
    $desk = actingAsStaff(staffWithRole('sales_employee', $w->a)); // orders.edit only
    foreach (['preparing', 'ready'] as $step) {
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $step])->assertOk();
    }
    foreach (['dispatched', 'out_for_delivery', 'cancelled'] as $step) {
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $step])->assertForbidden()->assertJsonPath('code', 'branch_forbidden');
    }
    expect($f->fresh()->status->value)->toBe('ready')->and(stockOf($w->a, $w->v1))->toBe(['on_hand' => 5, 'reserved' => 1])->and($desk->id)->not->toBeEmpty();

    // Delivered needs the delivery right as well, so the desk cannot complete an order (or touch the stock) without a driver, proof and cash.
    opsWalk($f, [FS::Dispatched]);
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'delivered'])->assertForbidden();
    expect($f->fresh()->status->value)->toBe('dispatched')->and($order->fresh()->status->value)->not->toBe('completed');
});

it('needs orders.approve for returned and cancelled, and keeps the stock untouched when refused', function () {
    [$order, $f, $w] = opsAcceptedOrder();
    opsWalk($f, [FS::Preparing, FS::Ready, FS::Dispatched, FS::Delivered]);
    $onHand = stockOf($w->a, $w->v1)['on_hand'];

    // deliveries.edit + orders.edit but not orders.approve: no role of ours is exactly this, so give a custom mix through two users.
    actingAsStaff(staffWithRole('sales_employee', $w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'returned'])->assertForbidden();
    actingAsStaff(staffWithRole('delivery_employee', $w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'returned'])->assertForbidden();
    expect($f->fresh()->status->value)->toBe('delivered')->and(stockOf($w->a, $w->v1)['on_hand'])->toBe($onHand);

    actingAsStaff(staffWithRole('order_manager', $w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'returned', 'reason' => 'damaged'])->assertOk()->assertJsonPath('data.status', 'returned');
    expect(stockOf($w->a, $w->v1)['on_hand'])->toBe($onHand + 1);
});

it('lets someone with the delivery right (and not the order-desk right) take the delivery steps, but not accept an order', function () {
    [$order, $f, $w] = opsAcceptedOrder();
    opsWalk($f, [FS::Preparing, FS::Ready]);
    actingAsStaff(staffWithRole('delivery_employee', $w->a)); // deliveries.view / edit, payments.create
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'dispatched'])->assertOk();
    expect($f->fresh()->status->value)->toBe('dispatched');

    [$o2, $f2] = opsAcceptedOrderAgain($w);
    opsWalk($f2, [FS::Preparing]);
    $this->postJson("/api/v1/admin/fulfillments/{$f2->id}/transition", ['status' => 'ready'])->assertForbidden();
});

/** Another order in the same world, waiting to be accepted. */
function opsAcceptedOrderAgain(object $w): array
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id'));
    $f = $order->fulfillments->first();
    opsWalk($f, [FS::Accepted]);
    actingAsStaff(staffWithRole('delivery_employee', $w->a));

    return [$order, $f->fresh()];
}

it('does not let anyone mark an unpaid cash on delivery order delivered by hand: no driver, no proof, no cash means no delivery', function () {
    [$order, $f, $w] = opsAcceptedOrder();
    opsWalk($f, [FS::Preparing, FS::Ready, FS::Dispatched]);
    actingAsStaff(staffWithRole('branch_manager', $w->a)); // every right there is at the branch
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'delivered'])->assertStatus(409)->assertJsonPath('code', 'cod_collection_required')
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'QAR'));
    expect($f->fresh()->status->value)->toBe('dispatched')->and($order->fresh()->status->value)->toBe('out_for_delivery')->and($order->fresh()->payment_status->value)->toBe('pending');
});

it('still lets an authorised user mark an order delivered when it is already paid (an online order)', function () {
    $a = adjustmentWorld(['payment' => 'sadad', 'qty' => [1, 0]]); // paid online, branch A part being prepared
    opsWalk($a->f, [FS::Ready, FS::Dispatched]);
    actingAsStaff(staffWithRole('branch_manager', $a->w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'delivered'])->assertOk();
    expect($a->order->fresh()->status->value)->toBe('completed');
});

it('the system (the delivery flow) can still deliver after it recorded the collection', function () {
    [$order, $f, $w] = opsAcceptedOrder();
    opsWalk($f, [FS::Preparing, FS::Ready, FS::Dispatched, FS::Delivered]);
    expect($f->fresh()->status->value)->toBe('delivered');
});

// ── The dashboard follows the same rule ───────────────────────────────────────────────────────────────────────────────────────────

it('the order page lets the order desk accept and prepare but hides and refuses "Mark returned"', function () {
    [$order, $f, $w] = opsAcceptedOrder();
    $desk = staffWithRole('sales_employee', $w->a);
    $this->actingAs($desk);
    Livewire::test(OrderShow::class, ['order' => $order])->call('advance', $f->id, 'preparing')->assertHasNoErrors();
    expect($f->fresh()->status->value)->toBe('preparing');

    opsWalk($f, [FS::Ready, FS::Dispatched, FS::Delivered]);
    $page = Livewire::test(OrderShow::class, ['order' => $order])->assertViewHas('actions', fn (array $a) => $a[$f->id]['return'] === false);
    $page->call('open', 'return_fulfillment', $f->id)->set('form.reason', 'damaged on arrival')->call('confirmDialog')->assertForbidden();
    expect($f->fresh()->status->value)->toBe('delivered')->and(stockOf($w->a, $w->v1)['on_hand'])->toBe(4);

    $this->actingAs(staffWithRole('order_manager', $w->a));
    $manager = Livewire::test(OrderShow::class, ['order' => $order])->assertViewHas('actions', fn (array $a) => $a[$f->id]['return'] === true);
    $manager->call('open', 'return_fulfillment', $f->id)->set('form.reason', 'damaged on arrival')->call('confirmDialog')->assertHasNoErrors();
    expect($f->fresh()->status->value)->toBe('returned')->and(stockOf($w->a, $w->v1)['on_hand'])->toBe(5);
});

// ── Removing the last item cancels: a manager's decision ──────────────────────────────────────────────────────────────────────────

it('lets the order desk reduce items but needs a manager to remove the last one (which cancels the parcel and, here, the order)', function () {
    $a = adjustmentWorld(['qty' => [2, 0]]); // two sofas at branch A
    $desk = actingAsStaff(staffWithRole('sales_employee', $a->w->a));

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']], extra: ['preview' => true])->assertForbidden()->assertJsonPath('code', 'approval_required');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertForbidden()->assertJsonPath('code', 'approval_required')
        ->assertJsonPath('message', __('ops_integrity.errors.approval_required'));
    expect($a->f->fresh()->status->value)->toBe('preparing')->and($a->order->fresh()->status->value)->not->toBe('cancelled')->and($desk->id)->not->toBeEmpty();

    actingAsStaff($a->mgr); // an order manager has orders.approve
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertOk()->assertJsonPath('data.order_cancelled', true);
    expect($a->order->fresh()->status->value)->toBe('cancelled');
});
