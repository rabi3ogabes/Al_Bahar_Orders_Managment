<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\PickupVerification;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\LoyaltyAccount;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Payments\Models\CashCollection;
use App\Livewire\Admin\Sales\CustomerIndex;
use App\Livewire\Admin\Sales\CustomerShow;
use App\Livewire\Admin\Sales\DeliveryIndex;
use App\Livewire\Admin\Sales\DriverConsole;
use App\Livewire\Admin\Sales\OrderIndex;
use App\Livewire\Admin\Sales\OrderShow;
use App\Livewire\Admin\Sales\PickupIndex;
use App\Livewire\Admin\Sales\PickupScanner;
use App\Livewire\Admin\Sales\ReturnIndex;
use App\Support\DocumentNumber;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->w = commerceWorld();
    $this->admin = User::factory()->superAdmin()->create();
    $this->mgrA = staffWithRole('branch_manager', $this->w->a);
    $this->mgrB = staffWithRole('branch_manager', $this->w->b);
});

function placeDeliveryOrder(object $w, int $qty = 1): Order
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => $qty]);

    return Order::with('fulfillments')->findOrFail(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id'));
}

function placePickup(object $w): Order
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);

    return Order::with('fulfillments.pickup')->findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
}

const SALES_SCREENS = ['admin.orders.index', 'admin.pickup.index', 'admin.pickup.scan', 'admin.deliveries.index', 'admin.customers.index', 'admin.returns.index'];

it('renders every sales screen for an authorized user', function (string $route) {
    $this->actingAs($this->admin)->get(route($route))->assertOk();
    $this->actingAs($this->mgrA)->get(route($route))->assertOk();
})->with(SALES_SCREENS);

it('forbids sales screens to staff without the permission', function (string $route) {
    $this->actingAs(staffWithRole('inventory_manager', $this->w->a))->get(route($route))->assertForbidden();
})->with(SALES_SCREENS);

it('renders order, customer and invoice pages and blocks other branches', function () {
    $order = placeDeliveryOrder($this->w);
    $this->actingAs($this->mgrA)->get(route('admin.orders.show', $order))->assertOk()->assertSee($order->number);
    $this->actingAs($this->mgrA)->get(route('admin.customers.show', $this->w->customer))->assertOk();
    $this->actingAs($this->mgrA)->get(route('admin.orders.invoice', $order))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($this->mgrB)->get(route('admin.orders.show', $order))->assertForbidden();
    $this->actingAs($this->mgrB)->get(route('admin.orders.invoice', $order))->assertForbidden();
    $this->actingAs($this->mgrB)->get(route('admin.customers.show', $this->w->customer))->assertForbidden();
});

it('never leaks other branches’ orders, pickups or deliveries', function () {
    $order = placeDeliveryOrder($this->w);
    $this->actingAs($this->mgrA);
    expect(Livewire::test(OrderIndex::class)->viewData('orders')->pluck('id')->all())->toBe([$order->id]);
    $this->actingAs($this->mgrB);
    expect(Livewire::test(OrderIndex::class)->viewData('orders')->total())->toBe(0)
        ->and(Livewire::test(OrderIndex::class)->set('branch', $this->w->a->id)->viewData('orders')->total())->toBe(0)
        ->and(Livewire::test(CustomerIndex::class)->viewData('customers')->total())->toBe(0)
        ->and(Livewire::test(DeliveryIndex::class)->viewData('rows')->total())->toBe(0);
    Livewire::test(OrderShow::class, ['order' => $order])->assertForbidden();
});

it('moves a fulfillment through the state machine from the order screen', function () {
    $order = placeDeliveryOrder($this->w);
    $f = $order->fulfillments->first();
    $this->actingAs($this->mgrA);
    $c = Livewire::test(OrderShow::class, ['order' => $order])
        ->assertViewHas('actions', fn ($a) => collect($a[$f->id]['steps'])->pluck('to')->filter()->values()->all() === ['accepted', 'preparing']);
    $c->call('advance', $f->id, 'accepted');
    expect($f->fresh()->status->value)->toBe('accepted');
    $c->call('advance', $f->id, 'preparing')->call('advance', $f->id, 'ready');
    expect($f->fresh()->status->value)->toBe('ready')->and($order->fresh()->status->value)->toBe('processing');
    // Only valid, directly-driven transitions are accepted.
    $c->call('advance', $f->id, 'delivered')->assertStatus(422);
    // The other branch's manager cannot drive it.
    $this->actingAs($this->mgrB);
    Livewire::test(OrderShow::class, ['order' => $order])->assertForbidden();
});

it('adds internal notes and cancels an order with a reason', function () {
    $order = placeDeliveryOrder($this->w);
    $this->actingAs($this->mgrA);
    Livewire::test(OrderShow::class, ['order' => $order])->set('note', 'Customer asked for afternoon slot')->call('addNote')->assertHasNoErrors()
        ->call('open', 'cancel_order')->set('form.reason', 'Customer called to cancel')->call('confirmDialog')->assertHasNoErrors();
    expect($order->fresh()->status->value)->toBe('cancelled')->and($order->notes()->count())->toBe(1)
        ->and(stockOf($this->w->a, $this->w->v1)['reserved'])->toBe(0);
});

it('verifies a pickup pass, rejects tampered codes and records a partial then full handover with payment', function () {
    $order = placePickup($this->w);
    $f = $order->fulfillments->first();
    $pickupStaff = staffWithRole('pickup_employee', $this->w->a);
    $this->actingAs($pickupStaff);
    Livewire::test(PickupIndex::class, ['tab' => 'preparing'])->call('markReady', $f->id);
    $pass = app(PickupService::class)->pass($f->pickup->fresh());
    expect($pass)->not->toBeNull();

    // Tampered / invalid codes are rejected and logged.
    $scanner = Livewire::test(PickupScanner::class)->assertSet('branchId', $this->w->a->id)
        ->set('code', substr($pass['qr_payload'], 0, -2).'xx')->call('verify')->assertSet('result', 'invalid_token')->assertSet('reservationId', null);
    $scanner->set('code', 'not-a-pass')->call('verify')->assertSet('result', 'invalid_token');
    expect(PickupVerification::where('result', 'invalid_token')->count())->toBe(2);

    $scanner->set('code', $pass['qr_payload'])->call('verify')->assertSet('result', 'success');
    $item1 = $f->items()->where('product_variant_id', $this->w->v1->id)->first();
    $item2 = $f->items()->where('product_variant_id', $this->w->v2->id)->first();
    // Payment must be collected first: an underpayment is refused.
    $scanner->set("handover.{$item1->id}", 1)->set("handover.{$item2->id}", 0)->set('collection.amount', '1.00')->call('confirmHandover')->assertDispatched('toast', type: 'error');
    expect($f->fresh()->pickup->status)->toBe('ready');
    $scanner->set('collection.amount', $order->grand_total)->set('collection.tender', 'card_terminal')->call('confirmHandover')->assertSet('completed', true);
    expect($f->fresh()->pickup->status)->toBe('partially_collected')->and($order->fresh()->payment_status->value)->toBe('paid');

    $scanner->call('resetScan')->set('code', $pass['qr_payload'])->call('verify')->assertSet('result', 'success')->call('confirmHandover');
    expect($f->fresh()->status->value)->toBe('picked_up')->and($order->fresh()->status->value)->toBe('completed');
    // The used pass can no longer be verified.
    $scanner->call('resetScan')->set('code', $pass['qr_payload'])->call('verify')->assertSet('result', 'already_used');
    // Another branch's staff cannot verify at branch A.
    $this->actingAs(staffWithRole('pickup_employee', $this->w->b));
    Livewire::test(PickupScanner::class)->set('branchId', $this->w->a->id)->assertSet('branchId', $this->w->a->id)->set('code', $pass['qr_payload'])->call('verify')->assertForbidden();
});

it('runs the driver flow from dispatch to delivered with COD collection', function () {
    Storage::fake('local');
    $order = placeDeliveryOrder($this->w);
    $f = $order->fulfillments->first();
    $driver = staffWithRole('delivery_employee', $this->w->a);
    BranchEmployee::where('user_id', $driver->id)->update(['is_delivery_driver' => true]);
    $this->actingAs($this->mgrA)->get(route('admin.driver'))->assertForbidden(); // not a driver
    $this->actingAs($driver)->get(route('admin.driver'))->assertOk();

    $this->actingAs($this->mgrA);
    $show = Livewire::test(OrderShow::class, ['order' => $order]);
    foreach (['accepted', 'preparing', 'ready'] as $s) {
        $show->call('advance', $f->id, $s);
    }
    Livewire::test(DeliveryIndex::class)->assertViewHas('rows', fn ($r) => $r->pluck('id')->contains($f->id))
        ->call('open', 'create', $f->id)->set('form.driver_id', $driver->id)->call('confirmDialog')->assertHasNoErrors();
    $d = Delivery::firstOrFail();
    expect($d->status->value)->toBe('assigned')->and($d->driver_id)->toBe($driver->id);

    $this->actingAs($driver);
    $c = Livewire::test(DriverConsole::class)->assertViewHas('active', fn ($a) => $a->pluck('id')->all() === [$d->id]);
    $c->call('act', $d->id, 'accept');
    expect($d->fresh()->status->value)->toBe('accepted');
    $c->call('act', $d->id, 'pickup')->call('act', $d->id, 'out');
    expect($d->fresh()->status->value)->toBe('out_for_delivery')->and(stockOf($this->w->a, $this->w->v1)['on_hand'])->toBe(4);

    // Proof photo and full COD are required.
    $c->call('openSheet', $d->id, 'deliver')->set('cod.amount', $order->grand_total)->call('deliver')->assertHasErrors('proof');
    $c->set('proof', UploadedFile::fake()->image('door.jpg'))->set('cod.amount', '10.00')->call('deliver')->assertDispatched('toast', type: 'error');
    $c->set('cod.amount', $order->grand_total)->call('deliver')->assertHasNoErrors();
    expect($d->fresh()->status->value)->toBe('delivered')->and($order->fresh()->payment_status->value)->toBe('paid')
        ->and($order->fresh()->status->value)->toBe('completed')
        ->and(CashCollection::where('status', 'collected')->value('collected_by'))->toBe($driver->id);

    // Drivers only see their own deliveries.
    $other = staffWithRole('delivery_employee', $this->w->a);
    BranchEmployee::where('user_id', $other->id)->update(['is_delivery_driver' => true]);
    $this->actingAs($other);
    Livewire::test(DriverConsole::class)->assertViewHas('active', fn ($a) => $a->isEmpty())->call('act', $d->id, 'accept')->assertNotFound();
});

it('records a failed delivery and returns it to the branch from the dispatch board', function () {
    $order = placeDeliveryOrder($this->w);
    $f = $order->fulfillments->first();
    $this->actingAs($this->mgrA);
    $show = Livewire::test(OrderShow::class, ['order' => $order]);
    foreach (['accepted', 'preparing', 'ready'] as $s) {
        $show->call('advance', $f->id, $s);
    }
    $board = Livewire::test(DeliveryIndex::class)->call('open', 'create', $f->id)->call('confirmDialog');
    $d = Delivery::firstOrFail();
    $board->call('act', $d->id, 'pickup')->call('act', $d->id, 'out')
        ->call('open', 'fail', $d->id)->set('form.reason_code', 'customer_absent')->call('confirmDialog')->assertHasNoErrors();
    expect($d->fresh()->status->value)->toBe('failed');
    $board->set('lane', 'exceptions')->assertViewHas('rows', fn ($r) => $r->pluck('id')->contains($d->id))->call('act', $d->id, 'return');
    expect($d->fresh()->status->value)->toBe('returned')->and(stockOf($this->w->a, $this->w->v1)['on_hand'])->toBe(5);
});

it('decides, receives and refunds a return request', function () {
    $order = placePickup($this->w);
    $f = $order->fulfillments->first();
    $staff = staffWithRole('pickup_employee', $this->w->a);
    $svc = app(PickupService::class);
    $r = $svc->markReady($f, $staff);
    $svc->handover($r, $staff, [], 'Noor', collection: ['tender' => 'cash', 'amount' => $order->grand_total]);
    $item = $f->items()->where('product_variant_id', $this->w->v1->id)->first();
    $rr = ReturnRequest::create(['number' => DocumentNumber::make('RT'), 'order_id' => $order->id, 'user_id' => $this->w->customer->id, 'branch_id' => $this->w->a->id,
        'reason_code' => 'damaged', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]]]);

    $this->actingAs($this->mgrB);
    expect(Livewire::test(ReturnIndex::class)->viewData('returns')->total())->toBe(0);
    Livewire::test(ReturnIndex::class)->call('select', $rr->id)->assertForbidden();

    $this->actingAs($this->mgrA);
    $c = Livewire::test(ReturnIndex::class)->call('select', $rr->id)->assertSet('refundAmount', '1000.00')
        ->call('decide', false)->assertHasErrors('note');
    $c->call('decide', true);
    expect($rr->fresh()->status)->toBe('approved');
    $c->call('receive');
    expect($rr->fresh()->status)->toBe('received')->and($item->fresh()->quantity_returned)->toBe(1)->and(stockOf($this->w->a, $this->w->v1)['on_hand'])->toBe(4);
    $c->call('refund');
    expect($rr->fresh()->status)->toBe('refunded')->and($rr->fresh()->refund_id)->not->toBeNull();
    $c->call('close');
    expect($rr->fresh()->status)->toBe('closed');
});

it('masks customer PII unless the viewer may edit customers, and adjusts points with a reason', function () {
    placeDeliveryOrder($this->w);
    $this->w->customer->update(['email' => 'noor@example.com']);
    $this->actingAs($this->mgrA)->get(route('admin.customers.index'))->assertOk()->assertDontSee('noor@example.com')->assertDontSee($this->w->customer->phone);
    $care = staffWithRole('customer_support');
    $this->actingAs($care)->get(route('admin.customers.index'))->assertOk()->assertSee('noor@example.com');

    $marketing = staffWithRole('marketing_manager');
    $this->actingAs($marketing);
    $c = Livewire::test(CustomerShow::class, ['customer' => $this->w->customer, 'tab' => 'loyalty'])
        ->set('adjust.points', 300)->set('adjust.reason', 'Goodwill for late delivery')->call('adjustPoints')->assertHasNoErrors();
    expect(LoyaltyAccount::where('user_id', $this->w->customer->id)->value('balance'))->toBe(300);
    $c->set('adjust.direction', 'debit')->set('adjust.points', 500)->set('adjust.reason', 'Correction of error')->call('adjustPoints')->assertDispatched('toast', type: 'error');
    expect(LoyaltyAccount::where('user_id', $this->w->customer->id)->value('balance'))->toBe(300);
    $c->call('askStatus', 'suspended')->assertForbidden(); // marketing cannot edit customer status

    $this->actingAs($care);
    Livewire::test(CustomerShow::class, ['customer' => $this->w->customer])->call('askStatus', 'suspended')->set('statusReason', 'Chargeback fraud investigation')->call('changeStatus');
    expect($this->w->customer->fresh()->status->value)->toBe('suspended');
});
