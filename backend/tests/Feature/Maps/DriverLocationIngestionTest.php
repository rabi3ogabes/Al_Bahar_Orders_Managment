<?php

use App\Domain\Fulfillment\Events\DriverMoved;
use App\Domain\Fulfillment\Events\OrderDriverMoved;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

const LOCATION_URL = '/api/v1/admin/dispatch/driver/location';

/** Posts a GPS ping as the signed-in driver; the 3-second broadcast gate is reset so each call stands alone. */
function ping(User $driver, array $body = []): TestResponse
{
    Cache::forget('driver:beat:'.$driver->id);

    return test()->postJson(LOCATION_URL, $body + ['latitude' => 25.28, 'longitude' => 51.52]);
}

it('refuses unusable positions with a stable code', function (array $body, string $field) {
    $d = deliveryAt('picked_up');
    ping($d->driver, $body)->assertStatus(422)->assertJsonValidationErrors($field);
    expect(DriverLocation::count())->toBe(0);
})->with([
    'latitude above 90' => [['latitude' => 91], 'latitude'],
    'latitude below -90' => [['latitude' => -91], 'latitude'],
    'longitude above 180' => [['longitude' => 181], 'longitude'],
    'latitude not a number' => [['latitude' => 'north'], 'latitude'],
    'speed over 300' => [['speed_kmh' => 301], 'speed_kmh'],
    'negative speed' => [['speed_kmh' => -1], 'speed_kmh'],
    'heading over 360' => [['heading' => 361], 'heading'],
    'negative heading' => [['heading' => -1], 'heading'],
    'negative accuracy' => [['accuracy' => -1], 'accuracy'],
    'accuracy beyond the column' => [['accuracy' => 40000], 'accuracy'],
    'battery over 100' => [['battery' => 101], 'battery'],
    'garbage timestamp' => [['recorded_at' => 'yesterday-ish'], 'recorded_at'],
]);

it('requires both coordinates', function () {
    $d = deliveryAt('picked_up');
    Cache::forget('driver:beat:'.$d->driver->id);
    $this->postJson(LOCATION_URL, ['latitude' => 25.28])->assertStatus(422)->assertJsonValidationErrors('longitude');
    $this->postJson(LOCATION_URL, ['longitude' => 51.5])->assertStatus(422)->assertJsonValidationErrors('latitude');
    $this->postJson(LOCATION_URL, [])->assertStatus(422);
});

it('refuses a position that cannot be a delivery in Qatar, but allows a generous margin', function (float $lat, float $lng, bool $ok) {
    $d = deliveryAt('picked_up');
    $r = ping($d->driver, ['latitude' => $lat, 'longitude' => $lng]);
    $ok ? $r->assertOk()->assertJsonPath('data.recorded', true) : $r->assertStatus(422)->assertJsonPath('code', 'location_out_of_area');
})->with([
    'doha' => [25.2854, 51.5310, true],
    'al khor' => [25.68, 51.50, true],
    'just over the border, inside the margin' => [26.8, 51.5, true],
    'null island' => [0.0, 0.0, false],
    'riyadh' => [24.7136, 46.6753, false],
    'dubai' => [25.2048, 55.2708, false],
    'north pole' => [89.0, 51.5, false],
]);

it('refuses fixes from the future or too far in the past, and stores the time the phone gave', function () {
    $d = deliveryAt('picked_up');
    ping($d->driver, ['recorded_at' => now()->addMinutes(5)->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'location_in_future');
    ping($d->driver, ['recorded_at' => now()->subMinutes(30)->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'location_too_old');
    expect(DriverLocation::count())->toBe(0);

    $at = now()->subSeconds(8)->startOfSecond();
    ping($d->driver, ['recorded_at' => $at->toIso8601String()])->assertOk()->assertJsonPath('data.recorded', true);
    expect(DriverLocation::first()->recorded_at->getTimestamp())->toBe($at->getTimestamp())
        ->and(DriverProfile::find($d->driver->id)->last_fix_at->getTimestamp())->toBe($at->getTimestamp());
    // A little clock skew (phone ahead by 30 s) is tolerated and recorded as "now".
    ping($d->driver, ['recorded_at' => now()->addSeconds(30)->toIso8601String(), 'latitude' => 25.29])->assertOk()->assertJsonPath('data.recorded', true);
    expect(DriverLocation::count())->toBe(2)->and(DriverLocation::orderByDesc('id')->first()->recorded_at->getTimestamp())->toBeLessThanOrEqual(now()->getTimestamp());
});

it('ignores a fix older than the one it already holds', function () {
    $d = deliveryAt('picked_up');
    ping($d->driver, ['recorded_at' => now()->subSeconds(5)->toIso8601String(), 'latitude' => 25.30])->assertOk()->assertJsonPath('data.recorded', true);
    ping($d->driver, ['recorded_at' => now()->subSeconds(40)->toIso8601String(), 'latitude' => 25.20])->assertOk()
        ->assertJsonPath('data.recorded', false)->assertJsonPath('data.reason', 'out_of_order');
    expect(DriverProfile::find($d->driver->id)->last_lat)->toBe(25.30)->and(DriverLocation::count())->toBe(1);
});

it('requires an authenticated driver of a branch', function () {
    $d = deliveryAt('picked_up');
    $this->app['auth']->forgetGuards();
    $this->postJson(LOCATION_URL, ['latitude' => 25.28, 'longitude' => 51.52])->assertUnauthorized();

    Sanctum::actingAs($d->customer, ['customer']);
    $this->postJson(LOCATION_URL, ['latitude' => 25.28, 'longitude' => 51.52])->assertForbidden();

    actingAsStaff($d->mgr);   // staff, but not a driver
    $this->postJson(LOCATION_URL, ['latitude' => 25.28, 'longitude' => 51.52])->assertForbidden()->assertJsonPath('code', 'driver_not_eligible');
    expect(DriverLocation::count())->toBe(0);
});

it('keeps nothing from a driver who is off duty with no job on the road', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a, onDuty: false);
    actingAsStaff($driver);
    ping($driver, ['latitude' => 25.28, 'longitude' => 51.52])->assertOk()->assertJsonPath('data.recorded', false)->assertJsonPath('data.reason', 'off_duty');
    $p = DriverProfile::find($driver->id);
    expect(DriverLocation::count())->toBe(0)->and($p->last_lat)->toBeNull()->and($p->last_fix_at)->toBeNull();
});

it('still records an off-duty driver who is carrying an order', function () {
    $d = deliveryAt('picked_up');
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => false])->assertOk();
    ping($d->driver)->assertOk()->assertJsonPath('data.recorded', true);
});

it('stores the latest fix and its motion, and a breadcrumb with it', function () {
    $d = deliveryAt('picked_up');
    ping($d->driver, ['latitude' => 25.2860, 'longitude' => 51.5320, 'speed_kmh' => 41.5, 'heading' => 270, 'accuracy' => 12, 'battery' => 64])
        ->assertOk()->assertJsonPath('data', ['ok' => true, 'recorded' => true, 'reason' => null]);
    $p = DriverProfile::find($d->driver->id);
    expect($p->last_lat)->toBe(25.286)->and($p->last_lng)->toBe(51.532)->and($p->last_heading)->toBe(270)->and($p->last_speed_kmh)->toBe(41.5)
        ->and($p->last_accuracy_m)->toBe(12)->and($p->last_battery)->toBe(64)->and($p->last_fix_at)->not->toBeNull();
    $crumb = DriverLocation::first();
    expect($crumb->delivery_id)->toBe($d->delivery->id)->and($crumb->heading)->toBe(270)->and((float) $crumb->speed_kmh)->toBe(41.5)->and($crumb->accuracy_m)->toBe(12);
});

it('keeps the position but not a breadcrumb for a fix inside the 3 second window', function () {
    $d = deliveryAt('picked_up');
    Cache::flush();
    $this->postJson(LOCATION_URL, ['latitude' => 25.28, 'longitude' => 51.52])->assertOk()->assertJsonPath('data.recorded', true);
    $this->postJson(LOCATION_URL, ['latitude' => 25.285, 'longitude' => 51.525])->assertOk()->assertJsonPath('data.recorded', false)->assertJsonPath('data.reason', 'rate_limited');
    expect(DriverLocation::count())->toBe(1)->and(DriverProfile::find($d->driver->id)->last_lat)->toBe(25.285);
});

it('suppresses a duplicate fix a few metres from the last breadcrumb, but still records a stopped driver every 45 seconds', function () {
    $d = deliveryAt('picked_up');
    Cache::flush();
    ping($d->driver, ['latitude' => 25.2800000, 'longitude' => 51.5200000])->assertJsonPath('data.recorded', true);
    // About 2 m east: the same spot.
    ping($d->driver, ['latitude' => 25.2800000, 'longitude' => 51.5200200])->assertOk()->assertJsonPath('data.recorded', false)->assertJsonPath('data.reason', 'duplicate');
    // About 30 m east: moved.
    ping($d->driver, ['latitude' => 25.2800000, 'longitude' => 51.5203000])->assertJsonPath('data.recorded', true);
    expect(DriverLocation::count())->toBe(2);

    // Standing still: after the window the next fix is stored again, so the map shows the driver alive.
    ping($d->driver, ['latitude' => 25.2800000, 'longitude' => 51.5203000])->assertJsonPath('data.reason', 'duplicate');
    Cache::forget('driver:crumb:'.$d->driver->id);
    ping($d->driver, ['latitude' => 25.2800000, 'longitude' => 51.5203000])->assertJsonPath('data.recorded', true);
    expect(DriverLocation::count())->toBe(3);
});

it('does not move the driver on a very coarse fix', function () {
    $d = deliveryAt('picked_up');
    ping($d->driver, ['latitude' => 25.30])->assertJsonPath('data.recorded', true);
    ping($d->driver, ['latitude' => 25.10, 'accuracy' => 2500])->assertOk()->assertJsonPath('data.recorded', false)->assertJsonPath('data.reason', 'low_accuracy');
    expect(DriverProfile::find($d->driver->id)->last_lat)->toBe(25.30)->and(DriverLocation::count())->toBe(1);
});

it('puts the breadcrumb on the delivery that is furthest along when a driver carries two', function () {
    $d = deliveryAt('out_for_delivery');
    // A second, earlier-stage delivery for the same driver: assigned later, only accepted.
    actingAsCustomer($d->customer);
    [$f2] = readyOrder($d->w);
    test()->postJson("/api/v1/admin/dispatch/fulfillments/{$f2->id}/assign", ['driver_id' => $d->driver->id])->assertOk();
    actingAsStaff($d->driver);
    $second = Delivery::where('order_fulfillment_id', $f2->id)->firstOrFail();
    $this->postJson("/api/v1/admin/deliveries/{$second->id}/accept")->assertOk();

    ping($d->driver)->assertJsonPath('data.recorded', true);
    expect(DriverLocation::first()->delivery_id)->toBe($d->delivery->id);
});

describe('broadcasts', function () {
    it('tells staff everything and the customer only what they need', function () {
        $d = deliveryAt('out_for_delivery');
        $d->driver->update(['name' => 'Ahmed Al Thani']);
        DriverProfile::where('user_id', $d->driver->id)->update(['vehicle' => 'Honda · 123456']);
        Event::fake([DriverMoved::class, OrderDriverMoved::class]);
        ping($d->driver, ['speed_kmh' => 30, 'heading' => 90, 'accuracy' => 8, 'battery' => 55])->assertJsonPath('data.recorded', true);

        Event::assertDispatchedTimes(DriverMoved::class, 1);
        Event::assertDispatched(DriverMoved::class, function (DriverMoved $e) use ($d) {
            $names = collect($e->broadcastOn())->map(fn ($c) => $c->name)->all();
            $p = $e->broadcastWith();

            return in_array('private-fleet', $names, true) && in_array('private-branches.'.$d->w->a->id, $names, true)
                && ! collect($names)->contains(fn ($n) => str_starts_with($n, 'private-orders.'))
                && $p['driver_id'] === $d->driver->id && $p['name'] === 'Ahmed Al Thani' && $p['vehicle'] === 'Honda · 123456'
                && $p['lat'] === 25.28 && $p['lng'] === 51.52 && $p['heading'] === 90 && $p['speed_kmh'] === 30.0 && $p['accuracy_m'] === 8 && $p['battery'] === 55
                && $p['order_id'] === $d->orderId && $p['delivery_status'] === 'out_for_delivery' && is_string($p['order_number']) && $p['order_number'] !== ''
                && $p['age_seconds'] === 0 && Carbon\Carbon::parse($p['at'])->diffInSeconds(now(), true) < 5;
        });

        Event::assertDispatchedTimes(OrderDriverMoved::class, 1);
        Event::assertDispatched(OrderDriverMoved::class, function (OrderDriverMoved $e) use ($d) {
            $p = $e->broadcastWith();

            return collect($e->broadcastOn())->map(fn ($c) => $c->name)->all() === ['private-orders.'.$d->orderId] && $e->broadcastAs() === 'driver.moved'
                && $p['name'] === 'Ahmed' && $p['vehicle'] === 'Honda · 123456' && $p['lat'] === 25.28 && $p['heading'] === 90 && $p['delivery_status'] === 'out_for_delivery'
                && ! array_key_exists('driver_id', $p) && ! array_key_exists('battery', $p) && ! array_key_exists('order_number', $p);
        });
    });

    it('does not tell the customer anything before the order is picked up', function () {
        $d = deliveryAt('accepted');
        Event::fake([DriverMoved::class, OrderDriverMoved::class]);
        ping($d->driver)->assertJsonPath('data.recorded', true);
        Event::assertDispatched(DriverMoved::class, fn ($e) => $e->orderId === $d->orderId && $e->deliveryStatus === 'accepted');
        Event::assertNotDispatched(OrderDriverMoved::class);
    });

    it('sends nothing for a position that was not stored', function () {
        $d = deliveryAt('picked_up');
        Event::fake([DriverMoved::class, OrderDriverMoved::class]);
        ping($d->driver, ['accuracy' => 5000]);
        ping($d->driver, ['recorded_at' => now()->subSeconds(1)->toIso8601String(), 'latitude' => 25.28])->assertJsonPath('data.recorded', true);
        ping($d->driver, ['recorded_at' => now()->subSeconds(30)->toIso8601String()]);   // out of order
        Event::assertDispatchedTimes(DriverMoved::class, 1);
    });
});

it('reports the vehicle and refuses an out-of-area start position when going on duty', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a, onDuty: false);
    actingAsStaff($driver);
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true, 'latitude' => 0, 'longitude' => 0])->assertStatus(422)->assertJsonPath('code', 'location_out_of_area');
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true, 'latitude' => 25.28])->assertStatus(422)->assertJsonValidationErrors('longitude');
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true, 'latitude' => 25.28, 'longitude' => 51.52, 'vehicle' => 'Motorbike · 7788'])
        ->assertOk()->assertJsonPath('data.on_duty', true)->assertJsonPath('data.vehicle', 'Motorbike · 7788');
    $p = DriverProfile::find($driver->id);
    expect($p->last_lat)->toBe(25.28)->and($p->last_fix_at)->not->toBeNull();
    $this->getJson('/api/v1/admin/dispatch/driver')->assertJsonPath('data.vehicle', 'Motorbike · 7788');
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true])->assertOk()->assertJsonPath('data.vehicle', 'Motorbike · 7788'); // untouched when not sent
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true, 'vehicle' => null])->assertOk()->assertJsonPath('data.vehicle', null);
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true, 'vehicle' => str_repeat('x', 61)])->assertStatus(422)->assertJsonValidationErrors('vehicle');
});

it('rate limits a runaway app to 60 pings a minute per driver', function () {
    $d = deliveryAt('picked_up');
    Cache::flush();
    foreach (range(1, 60) as $i) {
        $this->postJson(LOCATION_URL, ['latitude' => 25.28, 'longitude' => 51.52])->assertOk();
    }
    $this->postJson(LOCATION_URL, ['latitude' => 25.28, 'longitude' => 51.52])->assertStatus(429);
    // Another driver is not affected.
    $other = driverFor($d->w->a);
    actingAsStaff($other);
    $this->postJson(LOCATION_URL, ['latitude' => 25.28, 'longitude' => 51.52])->assertOk();
});

it('prunes breadcrumbs: stray after a week, order routes after a year', function () {
    $d = deliveryAt('picked_up');
    DriverLocation::create(['driver_id' => $d->driver->id, 'delivery_id' => $d->delivery->id, 'lat' => 25.1, 'lng' => 51.1, 'recorded_at' => now()->subDays(30)]);
    DriverLocation::create(['driver_id' => $d->driver->id, 'delivery_id' => null, 'lat' => 25.2, 'lng' => 51.2, 'recorded_at' => now()->subDays(8)]);
    DriverLocation::create(['driver_id' => $d->driver->id, 'delivery_id' => null, 'lat' => 25.3, 'lng' => 51.3, 'recorded_at' => now()->subDays(2)]);
    DriverLocation::create(['driver_id' => $d->driver->id, 'delivery_id' => $d->delivery->id, 'lat' => 25.4, 'lng' => 51.4, 'recorded_at' => now()->subDays(400)]);
    $this->artisan('drivers:prune-locations')->assertSuccessful();
    expect(DriverLocation::orderBy('lat')->pluck('lat')->map(fn ($v) => (float) $v)->all())->toBe([25.1, 25.3]);
});
