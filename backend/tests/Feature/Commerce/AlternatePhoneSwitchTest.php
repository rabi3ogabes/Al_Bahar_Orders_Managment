<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Support\AlternatePhone;
use Livewire\Livewire;

// The dashboard switch "Ask for an alternative phone number" (Settings → General & brand): on by default; off hides the number everywhere
// and ignores it from clients, but keeps what is stored so turning it back on restores everything.

const SWITCH_ALT = '+97455987654';

beforeEach(function () {
    $this->w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1])->assertCreated();
    $this->opts = ['address_id' => $this->w->address->id, 'payment_method' => 'cod'];
});

function altSwitch(bool $on): void
{
    app(Settings::class)->set(AlternatePhone::SETTING, $on);
}

/** A delivery order with a stored alternative number, walked to "ready" at branch A; returns [order, fulfillment, manager]. */
function altOrderReady(object $w): array
{
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod', 'alternate_phone' => SWITCH_ALT])->assertCreated()->json('data.order.id');
    $order = Order::with('fulfillments')->findOrFail($orderId);
    $f = $order->fulfillments->first();
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    foreach (['accepted', 'preparing', 'ready'] as $s) {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }
    actingAsCustomer($w->customer);

    return [$order->fresh(), $f->fresh(), $mgr];
}

it('is on by default and served to the apps and the web store by /app-config', function () {
    expect(AlternatePhone::enabled())->toBeTrue();
    $this->getJson('/api/v1/app-config')->assertOk()->assertJsonPath('data.alternate_phone_enabled', true)
        ->assertJsonPath('data.returns_enabled', true)->assertJsonStructure(['data' => ['designed_by' => ['en', 'ar'], 'ops' => ['status_style']]]);

    altSwitch(false);
    expect(AlternatePhone::enabled())->toBeFalse();
    $this->getJson('/api/v1/app-config')->assertOk()->assertJsonPath('data.alternate_phone_enabled', false)->assertJsonPath('data.returns_enabled', true);
    altSwitch(true);
    $this->getJson('/api/v1/app-config')->assertJsonPath('data.alternate_phone_enabled', true);
});

it('can be switched in Settings → General & brand, with an audit entry, by staff who may edit settings only', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin);
    expect(SettingsIndex::schema()['general'])->toHaveKey('customer_app.alternate_phone_enabled');

    $c = Livewire::test(SettingsIndex::class)->assertSet('values.general.customer_app__alternate_phone_enabled', true)
        ->set('values.general.customer_app__alternate_phone_enabled', false)->call('save')->assertHasNoErrors();
    expect(AlternatePhone::enabled())->toBeFalse();
    $log = AuditLog::where('action', 'settings.updated')->latest('created_at')->first();
    expect($log->metadata['key'] ?? null)->toBe('customer_app.alternate_phone_enabled')->and($log->new_values['value'] ?? null)->toBeFalse();

    $c->set('values.general.customer_app__alternate_phone_enabled', true)->call('save');
    expect(AlternatePhone::enabled())->toBeTrue();

    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(SettingsIndex::class)->set('values.general.customer_app__alternate_phone_enabled', false)->call('save')->assertForbidden();
    expect(AlternatePhone::enabled())->toBeTrue();
});

it('hides the profile number and ignores a client that still sends it, keeping what is stored', function () {
    $this->w->customer->forceFill(['alternate_phone' => SWITCH_ALT])->save();
    $this->getJson('/api/v1/me')->assertJsonPath('data.alternate_phone', SWITCH_ALT);

    altSwitch(false);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.alternate_phone', null);
    // An older app still sends it — a valid number, a garbage one, the main number: never an error, never saved.
    foreach (['+97466111222', 'not a number', $this->w->customer->phone, '', null] as $sent) {
        $this->patchJson('/api/v1/me', ['name' => 'Noor Al Thani', 'alternate_phone' => $sent])->assertOk()->assertJsonPath('data.alternate_phone', null)->assertJsonPath('data.name', 'Noor Al Thani');
    }
    expect($this->w->customer->fresh()->alternate_phone)->toBe(SWITCH_ALT); // untouched, even by alternate_phone => null/''

    altSwitch(true);
    $this->getJson('/api/v1/me')->assertJsonPath('data.alternate_phone', SWITCH_ALT);
    $this->patchJson('/api/v1/me', ['alternate_phone' => '+97466111222'])->assertOk()->assertJsonPath('data.alternate_phone', '+97466111222');
});

it('does not ask for it at checkout, ignores what an older app sends and keeps the profile number', function () {
    $this->w->customer->forceFill(['alternate_phone' => SWITCH_ALT])->save();
    altSwitch(false);

    // Neither key sent (the new app): nothing required.
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts)->assertOk()->json('data');
    expect($q['can_place_order'])->toBeTrue()->and(collect($q['errors'])->pluck('code')->all())->toBe([]);

    // An older app that still asks the question: garbage, the main number, a missing answer — all ignored, no error.
    foreach ([['alternate_phone' => 'garbage'], ['alternate_phone' => $this->w->customer->phone], ['alternate_phone' => null, 'no_alternate_phone' => false], ['no_alternate_phone' => 'maybe']] as $extra) {
        $q = $this->postJson('/api/v1/checkout/quote', $this->opts + $extra)->assertOk()->json('data');
        expect($q['can_place_order'])->toBeTrue()->and(collect($q['errors'])->pluck('code')->all())->toBe([]);
    }

    $orderId = quoteAndPlace($this->opts + ['alternate_phone' => '+97466111222'])->assertCreated()->json('data.order.id');
    expect(Order::findOrFail($orderId)->alternate_phone)->toBeNull()->and($this->w->customer->fresh()->alternate_phone)->toBe(SWITCH_ALT);
});

it('hides it from the driver, the branch queue, the staff order and the dashboard while off, and brings it back when on', function () {
    $driver = driverFor($this->w->a);
    [$order, $f, $mgr] = altOrderReady($this->w);
    expect($order->alternate_phone)->toBe(SWITCH_ALT);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id]);
    actingAsStaff($mgr);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();

    // On: everyone who saw it before still does.
    $queue = fn () => $this->getJson('/api/v1/admin/dispatch/queue?stage=ready&branch_id='.$this->w->a->id)->assertOk();
    expect($queue()->json('data.0.alternate_phone'))->toBe(SWITCH_ALT);
    $this->getJson("/api/v1/admin/orders/{$order->id}")->assertJsonPath('data.alternate_phone', SWITCH_ALT);
    $this->actingAs($mgr)->get(route('admin.orders.show', $order))->assertSee('+97455987654');
    actingAsStaff($driver);
    expect($this->getJson('/api/v1/admin/dispatch/driver/jobs')->json('data.0.alternate_phone'))->toBe(SWITCH_ALT);
    expect(json_encode($this->getJson('/api/v1/admin/me/deliveries')->json()))->toContain('987654');
    $this->actingAs($driver)->get(route('admin.driver'))->assertSee('tel:+97455987654', false);

    altSwitch(false);
    actingAsStaff($mgr);
    $q = $queue();
    expect($q->json('data.0'))->toHaveKey('alternate_phone')->and($q->json('data.0.alternate_phone'))->toBeNull()->and($q->getContent())->not->toContain('987654');
    $detail = $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk();
    expect($detail->json('data.alternate_phone'))->toBeNull()->and($detail->getContent())->not->toContain('987654');
    $this->actingAs($mgr)->get(route('admin.orders.show', $order))->assertOk()->assertDontSee('+97455987654')->assertDontSee('Alternative number');
    actingAsStaff($driver);
    $jobs = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk();
    expect($jobs->json('data.0'))->toHaveKey('alternate_phone')->and($jobs->json('data.0.alternate_phone'))->toBeNull()->and($jobs->getContent())->not->toContain('987654');
    expect($this->getJson('/api/v1/admin/me/deliveries')->getContent())->not->toContain('987654');
    $this->actingAs($driver)->get(route('admin.driver'))->assertOk()->assertDontSee('tel:+97455987654', false)->assertDontSee('Call alternative number');
    expect($order->fresh()->alternate_phone)->toBe(SWITCH_ALT); // kept

    altSwitch(true);
    actingAsStaff($mgr);
    expect($queue()->json('data.0.alternate_phone'))->toBe(SWITCH_ALT);
    $this->actingAs($driver)->get(route('admin.driver'))->assertSee('tel:+97455987654', false);
});

it('still erases the stored number with the account while the switch is off', function () {
    $this->w->customer->forceFill(['alternate_phone' => SWITCH_ALT])->save();
    $orderId = quoteAndPlace($this->opts + ['alternate_phone' => SWITCH_ALT])->assertCreated()->json('data.order.id');
    altSwitch(false);
    $this->postJson('/api/v1/me/deletion-request', ['reason' => 'moving'])->assertSuccessful();
    expect($this->w->customer->fresh()->alternate_phone)->toBeNull()->and(Order::findOrFail($orderId)->alternate_phone)->toBeNull();
});
