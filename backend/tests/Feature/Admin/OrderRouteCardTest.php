<?php

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Sales\OrderShow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->w = commerceWorld();
    $this->w->a->update(['latitude' => 25.3221, 'longitude' => 51.5291]);
    $this->mgr = staffWithRole('branch_manager', $this->w->a);
    $this->driver = staffWithRole('delivery_employee', $this->w->a);
    BranchEmployee::where('user_id', $this->driver->id)->update(['is_delivery_driver' => true]);
    DriverProfile::create(['user_id' => $this->driver->id, 'on_duty' => true]);
});

/** A placed delivery order, handed to the driver; its customer address gets a map position. */
function routedOrder(object $w, object $mgr, object $driver, string $upTo = 'assigned'): array
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->findOrFail(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id'));
    $order->update(['address_snapshot' => array_merge($order->address_snapshot ?? [], ['latitude' => 25.3105, 'longitude' => 51.5188])]);
    $f = $order->fulfillments->first();
    actingAsStaff($mgr);
    foreach (['accepted', 'preparing', 'ready'] as $s) {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }
    test()->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $delivery = Delivery::firstOrFail();
    if ($upTo === 'out') {
        actingAsStaff($driver);
        foreach (['accept', 'pickup', 'out-for-delivery'] as $step) {
            test()->postJson("/api/v1/admin/deliveries/{$delivery->id}/{$step}")->assertOk();
        }
    }

    return [$order, $delivery];
}

function recordRoute(Delivery $d, string $driverId, int $points = 20): void
{
    foreach (range(0, $points - 1) as $i) {
        DriverLocation::create(['driver_id' => $driverId, 'delivery_id' => $d->id, 'lat' => 25.3221 - $i * 0.0006, 'lng' => 51.5291 - $i * 0.0005, 'speed_kmh' => 30, 'heading' => 215, 'recorded_at' => now()->subMinutes(30)->addSeconds($i * 40)]);
    }
}

it('shows a route card for a delivery with a recorded route, with the destination, the branch and the stops', function () {
    [$order, $delivery] = routedOrder($this->w, $this->mgr, $this->driver);
    recordRoute($delivery, $this->driver->id);

    $this->actingAs($this->mgr);
    $c = Livewire::test(OrderShow::class, ['order' => $order])->assertOk();
    $card = $c->viewData('routeCards')[$order->fulfillments->first()->id];

    expect($card['routes'][0]['points'])->not->toBeEmpty()->and($card['routes'][0]['points'][0])->toHaveKeys(['lat', 'lng', 'at'])
        ->and($card['destination'])->toBe(['lat' => 25.3105, 'lng' => 51.5188])
        ->and($card['origin'])->toMatchArray(['lat' => 25.3221, 'lng' => 51.5291])
        ->and($card['summary']['km'])->toBeGreaterThan(0)
        ->and($card['driver'])->toBeNull()          // not on the road yet: no live driver, no ETA
        ->and($card['eta'])->toBeNull();
    $c->assertSeeHtml('data-route=')->assertSee(__('maps.route_title'));
});

it('adds the live driver and the arrival estimate once the order is out for delivery, with the first name only', function () {
    [$order, $delivery] = routedOrder($this->w, $this->mgr, $this->driver, 'out');
    recordRoute($delivery, $this->driver->id);
    DriverProfile::where('user_id', $this->driver->id)->update(['last_lat' => 25.3190, 'last_lng' => 51.5250, 'last_seen_at' => now(), 'last_fix_at' => now(), 'last_heading' => 215, 'last_speed_kmh' => 28]);

    $this->actingAs($this->mgr);
    $card = Livewire::test(OrderShow::class, ['order' => $order])->viewData('routeCards')[$order->fulfillments->first()->id];

    expect($card['driver'])->toMatchArray(['lat' => 25.319, 'lng' => 51.525, 'heading' => 215, 'name' => explode(' ', $this->driver->name)[0]])
        ->and($card['eta']['minutes'])->toBeInt()->toBeGreaterThanOrEqual(1)
        ->and($card['fingerprint'])->toContain('out_for_delivery');
});

it('shows no route card when nothing was recorded and the driver is not on the road', function () {
    [$order] = routedOrder($this->w, $this->mgr, $this->driver);

    $this->actingAs($this->mgr);
    Livewire::test(OrderShow::class, ['order' => $order])->assertViewHas('routeCards', [])->assertDontSeeHtml('data-route=')->assertViewHas('mapConfig', null);
});

it('keeps the card data off x-data and keys the element by the delivery state, so Livewire replaces a stale map instead of rebuilding it every render', function () {
    [$order, $delivery] = routedOrder($this->w, $this->mgr, $this->driver);
    recordRoute($delivery, $this->driver->id);

    $this->actingAs($this->mgr);
    $html = Livewire::test(OrderShow::class, ['order' => $order])->html();
    expect($html)->toMatch('/<div wire:key="rc-'.$delivery->id.'-[0-9a-f]{32}" wire:ignore data-route="/')
        ->and($html)->toContain('x-data="{ ctl: null, destroy() { this.ctl?.destroy(); } }"')   // a constant: nothing in it changes between renders
        ->and($html)->toContain('window.mountRouteCard($el, JSON.parse($el.dataset.route))');
});
