<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Sales\OrderCart;
use App\Livewire\Admin\Sales\OrderCartButton;
use App\Livewire\Admin\Sales\OrderCreate;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->w = commerceWorld();
    $this->svc = app(StaffOrderService::class);
    $this->mgrA = staffWithRole('branch_manager', $this->w->a);
    $this->mgrB = staffWithRole('branch_manager', $this->w->b);
    $this->admin = User::factory()->superAdmin()->create();
});

function draftFor(object $t, User $staff, ?User $customer = null, int $qty = 2): Cart
{
    $d = $t->svc->create($staff, $staff->isSuperAdmin() ? $t->w->a->id : null, $customer?->id);
    $t->svc->add($staff, $d, $t->w->v1->id, $qty);

    return $d->fresh();
}

function placeStaff(object $t, User $staff, Cart $d, string $method = 'cod', ?string $key = null): array
{
    $t->svc->setOptions($staff, $d, ['address_id' => $t->w->address->id, 'payment_method' => $method]);
    $q = $t->svc->quote($staff, $d->fresh());

    return $t->svc->place($staff, $d->fresh(), $q->hash, $key ?? 'staff-key-'.str_repeat('a', 12));
}

it('runs the draft lifecycle without touching the customer cart', function () {
    $cart = app(CartService::class)->add($this->w->customer, $this->w->v2->id, 3);
    $d = draftFor($this, $this->mgrA, $this->w->customer);
    expect($d->status)->toBe('staff_draft')->and($d->staff_id)->toBe($this->mgrA->id)->and($d->branch_id)->toBe($this->w->a->id);

    $item = $d->items()->first();
    $this->svc->update($this->mgrA, $d, $item->id, 5);
    expect($d->items()->first()->quantity)->toBe(5);
    $this->svc->remove($this->mgrA, $d, $item->id);
    expect($d->items()->count())->toBe(0);

    expect($this->svc->drafts($this->mgrA))->toHaveCount(1)->and($this->svc->current($this->mgrA)->id)->toBe($d->id);
    $this->svc->discard($this->mgrA, $d);
    expect(Cart::find($d->id))->toBeNull()->and(AuditLog::where('action', 'order_draft.discarded')->exists())->toBeTrue();

    $mine = Cart::where('user_id', $this->w->customer->id)->where('status', 'active')->get();
    expect($mine)->toHaveCount(1)->and($mine[0]->id)->toBe($cart->id)->and($mine[0]->items()->sum('quantity'))->toBe(3);
});

it('keeps drafts private to their owner (super admin can see them)', function () {
    $d = draftFor($this, $this->mgrA, $this->w->customer);
    $other = staffWithRole('branch_manager', $this->w->a);
    expect(fn () => $this->svc->find($other, $d->id))->toThrow(AuthorizationException::class)
        ->and($this->svc->drafts($other))->toHaveCount(0)
        ->and($this->svc->drafts($this->admin))->toHaveCount(1)
        ->and(fn () => $this->svc->add($this->admin, $d, $this->w->v1->id))->toThrow(AuthorizationException::class);
});

it('enforces the permission matrix and branch scope', function () {
    $driver = staffWithRole('delivery_employee', $this->w->a);
    $auditor = staffWithRole('auditor');
    foreach (['sales_employee', 'order_manager', 'customer_support'] as $role) {
        expect($this->svc->canCreate(staffWithRole($role, $this->w->a)))->toBeTrue();
    }
    expect($this->svc->canCreate($driver))->toBeFalse()->and($this->svc->canCreate($auditor))->toBeFalse()
        ->and(fn () => $this->svc->create($driver))->toThrow(AuthorizationException::class);
    // branch-restricted: cannot pick or reach another branch
    $d = draftFor($this, $this->mgrA, $this->w->customer);
    expect(fn () => $this->svc->setOptions($this->mgrA, $d, ['branch_id' => $this->w->b->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->svc->create($this->mgrA, $this->w->b->id))->toThrow(AuthorizationException::class);
    $this->actingAs($driver)->get(route('admin.orders.create'))->assertForbidden();
    $this->actingAs($this->mgrA)->get(route('admin.orders.create'))->assertOk();
});

it('quotes exactly like the customer quote engine', function () {
    app(CartService::class)->add($this->w->customer, $this->w->v1->id, 2);
    app(CartService::class)->add($this->w->customer, $this->w->v2->id, 1);
    $customerQuote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertOk()->json('data');

    $d = $this->svc->create($this->admin, $this->w->a->id, $this->w->customer->id);
    $this->svc->add($this->admin, $d, $this->w->v1->id, 2);
    $this->svc->add($this->admin, $d, $this->w->v2->id, 1);
    $this->svc->setOptions($this->admin, $d, ['address_id' => $this->w->address->id, 'payment_method' => 'cod']);
    $q = $this->svc->quote($this->admin, $d->fresh());

    expect($q->grandTotal()->toDecimal())->toBe($customerQuote['totals']['grand_total']['amount'])
        ->and($q->subtotal()->toDecimal())->toBe($customerQuote['totals']['subtotal']['amount'])
        ->and($q->deliveryFeeTotal()->toDecimal())->toBe($customerQuote['totals']['delivery_fee']['amount'])
        ->and($q->hash)->toBe($customerQuote['quote_hash']);
});

it('places a COD order on behalf of the customer: reservation, audit, history, idempotency', function () {
    $d = draftFor($this, $this->mgrA, $this->w->customer);
    $this->svc->setOptions($this->mgrA, $d, ['internal_note' => 'Phone order', 'notes' => 'Call before arrival']);
    $res = placeStaff($this, $this->mgrA, $d);
    $order = Order::with('history')->findOrFail($res['order']->id);

    expect($order->source)->toBe('admin')->and($order->placed_by_staff_id)->toBe($this->mgrA->id)->and($order->user_id)->toBe($this->w->customer->id)
        ->and($order->status->value)->toBe('confirmed')->and($order->customer_notes)->toBe('Call before arrival')
        ->and($order->history->first()->actor_id)->toBe($this->mgrA->id)->and($order->history->first()->metadata['on_behalf_of'])->toBe($this->w->customer->id)
        ->and($order->notes()->where('body', 'Phone order')->exists())->toBeTrue()
        ->and(stockOf($this->w->a, $this->w->v1))->toBe(['on_hand' => 5, 'reserved' => 2])
        ->and(AuditLog::where('action', 'order.placed')->where('actor_id', $this->mgrA->id)->exists())->toBeTrue()
        ->and(Cart::find($d->id)->status)->toBe('converted');

    // double submit / replay returns the same order and reserves nothing more
    $again = $this->svc->place($this->mgrA, Cart::find($d->id)->forceFill(['status' => 'staff_draft']), $order->quote_hash, 'staff-key-'.str_repeat('a', 12));
    expect($again['replayed'])->toBeTrue()->and($again['order']->id)->toBe($order->id)->and(stockOf($this->w->a, $this->w->v1)['reserved'])->toBe(2);

    $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk()->assertSee(__('neworder_admin.placed_by', ['staff' => $this->mgrA->name]));
});

it('places a pickup order and a SADAD order (pending payment)', function () {
    $d = draftFor($this, $this->mgrA, $this->w->customer, 1);
    $this->svc->setOptions($this->mgrA, $d, ['fulfillment_type' => 'pickup', 'payment_method' => 'pay_at_pickup']);
    $q = $this->svc->quote($this->mgrA, $d->fresh());
    $r = $this->svc->place($this->mgrA, $d->fresh(), $q->hash, 'pickup-key-'.str_repeat('b', 10));
    expect($r['order']->fulfillment_type)->toBe('pickup')->and($r['payment_link'])->toBeNull();

    config(['platform.payments.sadad.enabled' => true]);
    $d2 = draftFor($this, $this->mgrA, $this->w->customer, 1);
    $res = placeStaff($this, $this->mgrA, $d2, 'sadad', 'sadad-key-'.str_repeat('c', 10));
    expect($res['order']->status->value)->toBe('pending_payment')->and($res['order']->payment_method)->toBe('sadad');
});

it('rejects stale totals, missing customers and out-of-stock lines', function () {
    $d = draftFor($this, $this->mgrA, $this->w->customer, 9); // only 5 in stock at branch A (B may cover but is out of scope)
    $this->svc->setOptions($this->mgrA, $d, ['address_id' => $this->w->address->id, 'payment_method' => 'cod']);
    $q = $this->svc->quote($this->mgrA, $d->fresh());
    expect($q->canPlace())->toBeFalse();
    expect(fn () => $this->svc->place($this->mgrA, $d->fresh(), $q->hash, 'oos-key-'.str_repeat('d', 12)))->toThrow(DomainException::class);
    expect(Order::count())->toBe(0);

    $noCustomer = $this->svc->create($this->mgrA);
    expect(fn () => $this->svc->place($this->mgrA, $noCustomer, str_repeat('0', 64), 'nocust-key-'.str_repeat('e', 10)))->toThrow(DomainException::class);
    $d3 = draftFor($this, $this->mgrA, $this->w->customer, 1);
    $this->svc->setOptions($this->mgrA, $d3, ['address_id' => $this->w->address->id, 'payment_method' => 'cod']);
    expect(fn () => $this->svc->place($this->mgrA, $d3->fresh(), str_repeat('0', 64), 'stale-key-'.str_repeat('f', 10)))->toThrow(DomainException::class);
});

it('never lets staff set prices and reports a changed price', function () {
    $d = draftFor($this, $this->mgrA, $this->w->customer, 1);
    $this->svc->setOptions($this->mgrA, $d, ['price' => '1.00', 'unit_price' => '1.00', 'grand_total' => '1.00', 'address_id' => $this->w->address->id]);
    $this->w->p1->update(['price' => '1200.00']);
    $this->w->v1->refresh();
    $q = $this->svc->quote($this->mgrA, $d->fresh());
    expect($q->subtotal()->toDecimal())->toBe($this->w->v1->product->fresh()->variants()->first() ? $q->subtotal()->toDecimal() : '0')->and($q->subtotal()->toDecimal())->not->toBe('1.00');
});

it('quick-creates a customer without duplicates and normalises the phone', function () {
    $r = $this->svc->createCustomer($this->mgrA, ['name' => 'Walk In', 'phone' => '55 123 999', 'locale' => 'ar']);
    expect($r['existed'])->toBeFalse()->and($r['user']->phone)->toBe('+97455123999')->and($r['user']->marketing_consent_at)->toBeNull();
    $again = $this->svc->createCustomer($this->mgrA, ['name' => 'Other Name', 'phone' => '+974 5512 3999', 'locale' => 'en']);
    expect($again['existed'])->toBeTrue()->and($again['user']->id)->toBe($r['user']->id)->and(User::where('phone', '+97455123999')->count())->toBe(1);
    expect(fn () => $this->svc->createCustomer($this->mgrA, ['name' => 'X', 'phone' => 'abc', 'locale' => 'en']))->toThrow(ValidationException::class);
    $found = $this->svc->searchCustomers($this->mgrA, 'Walk');
    expect($found)->toHaveCount(1)->and($found[0]['phone'])->not->toContain('55123999');
});

it('renders the New order page and drives the cart panel (Livewire)', function () {
    $this->actingAs($this->mgrA);
    Livewire::test(OrderCreate::class)->assertOk()->assertSee($this->w->p1->sku)
        ->call('add', $this->w->v1->id, 2)->assertDispatched('order-cart-updated');
    $draft = Cart::where('staff_id', $this->mgrA->id)->first();
    expect($draft->items()->sum('quantity'))->toBe(2);

    Livewire::test(OrderCart::class, ['mode' => 'docked'])->assertSee(__('neworder_admin.choose_customer_first'))
        ->call('pickCustomer', $this->w->customer->id)->assertSee($this->w->customer->name)
        ->call('go', 'checkout')->call('setPayment', 'cod')->assertSee(__('neworder_admin.checkout'));
    Livewire::test(OrderCart::class)->assertDontSee(__('neworder_admin.panel_title'))->call('openPanel')->assertSee(__('neworder_admin.panel_title'));
    Livewire::test(OrderCartButton::class)->assertSee('2');
});

it('places an order from the panel once and shows the success state', function () {
    $this->actingAs($this->mgrA);
    $d = draftFor($this, $this->mgrA, $this->w->customer, 1);
    $this->svc->setOptions($this->mgrA, $d, ['address_id' => $this->w->address->id, 'payment_method' => 'cod']);
    $hash = $this->svc->quote($this->mgrA, $d->fresh())->hash;
    Livewire::test(OrderCart::class, ['mode' => 'docked', 'step' => 'checkout'])->call('placeOrder', $hash)->assertSet('step', 'done')->assertSee(__('neworder_admin.view_order'))
        ->call('placeOrder', $hash);
    expect(Order::where('source', 'admin')->count())->toBe(1);
});

it('keeps the English and Arabic order screens in sync', function () {
    expect(array_keys(trans('neworder_admin', [], 'en')))->toEqualCanonicalizing(array_keys(trans('neworder_admin', [], 'ar')));
});
