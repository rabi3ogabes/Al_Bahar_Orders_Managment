<?php

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use App\Support\Geo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** Puts the driver somewhere, as if the phone had reported a fix $secondsAgo seconds ago. */
function driverIsAt(object $d, float $lat, float $lng, int $secondsAgo = 0, array $extra = []): void
{
    DriverProfile::where('user_id', $d->driver->id)->update(['last_lat' => $lat, 'last_lng' => $lng, 'last_fix_at' => now()->subSeconds($secondsAgo), 'last_seen_at' => now()] + $extra);
}

function trackingOf(object $d, ?User $as = null): TestResponse
{
    Sanctum::actingAs($as ?? $d->customer, ['customer']);

    return test()->getJson("/api/v1/orders/{$d->orderId}/tracking");
}

describe('customer tracking', function () {
    it('shows an order on the road: driver first name, vehicle, position with its age, destination, branch and ETA', function () {
        $d = deliveryAt('out_for_delivery');   // destination 25.30, 51.50
        $d->driver->update(['name' => 'Ahmed Al Thani']);
        driverIsAt($d, 25.28, 51.52, 20, ['last_heading' => 90, 'last_speed_kmh' => 31.5, 'last_accuracy_m' => 9, 'vehicle' => 'Honda · 123456']);

        $t = trackingOf($d)->assertOk()->json('data');
        $road = Geo::distanceM(25.28, 51.52, 25.30, 51.50) * 1.35;
        expect($t)->toHaveKeys(['order_id', 'number', 'status', 'tracking', 'live', 'delivery_status', 'driver', 'destination', 'branch', 'eta', 'stale_after_seconds'])
            ->and($t['order_id'])->toBe($d->orderId)->and($t['tracking'])->toBeTrue()->and($t['live'])->toBeTrue()->and($t['delivery_status'])->toBe('out_for_delivery')
            ->and($t['stale_after_seconds'])->toBe(120)
            ->and($t['driver'])->toMatchArray(['name' => 'Ahmed', 'vehicle' => 'Honda · 123456', 'status' => 'out_for_delivery', 'lat' => 25.28, 'lng' => 51.52,
                'heading' => 90, 'speed_kmh' => 31.5, 'accuracy_m' => 9, 'stale' => false])
            ->and($t['driver']['age_seconds'])->toBeBetween(20, 25)->and(Carbon\Carbon::parse($t['driver']['at'])->diffInSeconds(now(), true))->toBeBetween(19, 26)
            ->and(array_keys($t['driver']))->not->toContain('id', 'phone', 'user_id', 'battery', 'on_duty')
            ->and($t['destination'])->toBe(['lat' => 25.3, 'lng' => 51.5])
            ->and($t['branch'])->toHaveKeys(['name', 'lat', 'lng'])->and($t['branch']['lat'])->toBeFloat()
            ->and($t['eta']['source'])->toBe('estimate')->and($t['eta']['distance_m'])->toBe((int) round($road))->and($t['eta']['minutes'])->toBe((int) ceil($road / 1000 / 28 * 60))
            ->and(Carbon\Carbon::parse($t['eta']['updated_at'])->diffInSeconds(now(), true))->toBeLessThan(5);
    });

    it('marks an old position stale so the map can grey the marker', function () {
        $d = deliveryAt('picked_up');
        driverIsAt($d, 25.28, 51.52, 300);
        $t = trackingOf($d)->assertOk()->json('data');
        expect($t['tracking'])->toBeTrue()->and($t['driver']['stale'])->toBeTrue()->and($t['driver']['age_seconds'])->toBeGreaterThanOrEqual(300);
        driverIsAt($d, 25.28, 51.52, 119);
        expect(trackingOf($d)->json('data.driver.stale'))->toBeFalse();
        driverIsAt($d, 25.28, 51.52, 121);
        expect(trackingOf($d)->json('data.driver.stale'))->toBeTrue();
    });

    it('shows a driver block without a position when the phone has not reported yet', function () {
        $d = deliveryAt('picked_up');
        $t = trackingOf($d)->assertOk()->json('data');
        expect($t['tracking'])->toBeFalse()->and($t['live'])->toBeTrue()->and($t['driver']['lat'])->toBeNull()->and($t['driver']['stale'])->toBeTrue()->and($t['driver']['age_seconds'])->toBeNull()->and($t['eta'])->toBeNull();
    });

    it('hides the driver until the order has been picked up', function (string $status) {
        $d = deliveryAt($status);
        driverIsAt($d, 25.28, 51.52);
        $t = trackingOf($d)->assertOk()->json('data');
        expect($t['tracking'])->toBeFalse()->and($t['live'])->toBeFalse()->and($t['driver'])->toBeNull()->and($t['eta'])->toBeNull()
            ->and($t['delivery_status'])->toBe($status === 'assigned' ? null : $status)->and($t['destination'])->toBe(['lat' => 25.3, 'lng' => 51.5])->and($t['branch'])->not->toBeNull();
    })->with(['assigned', 'accepted']);

    it('answers an order with no delivery yet', function () {
        $w = commerceWorld();
        [$f] = readyOrder($w);
        Sanctum::actingAs($w->customer, ['customer']);
        $this->getJson("/api/v1/orders/{$f->order_id}/tracking")->assertOk()->assertJsonPath('data.tracking', false)->assertJsonPath('data.driver', null)
            ->assertJsonPath('data.delivery_status', null)->assertJsonPath('data.eta', null);
    });

    it('keeps the final position for ten minutes after delivery, then drops the driver', function () {
        $d = deliveryAt('out_for_delivery');
        driverIsAt($d, 25.2990, 51.5010);
        DriverLocation::create(['driver_id' => $d->driver->id, 'delivery_id' => $d->delivery->id, 'lat' => 25.2999, 'lng' => 51.5001, 'recorded_at' => now()->subMinutes(4)]);
        Delivery::whereKey($d->delivery->id)->update(['status' => 'delivered', 'delivered_at' => now()->subMinutes(3)]);
        // The driver has moved on to somewhere else entirely: the customer must see the drop-off point, not that.
        driverIsAt($d, 25.45, 51.35);

        $t = trackingOf($d)->assertOk()->json('data');
        expect($t['tracking'])->toBeTrue()->and($t['live'])->toBeFalse()->and($t['delivery_status'])->toBe('delivered')->and($t['eta'])->toBeNull()
            ->and($t['driver']['lat'])->toBe(25.2999)->and($t['driver']['lng'])->toBe(51.5001)->and($t['driver']['status'])->toBe('delivered');

        Delivery::whereKey($d->delivery->id)->update(['delivered_at' => now()->subMinutes(11)]);
        $t = trackingOf($d)->assertOk()->json('data');
        expect($t['tracking'])->toBeFalse()->and($t['driver'])->toBeNull()->and($t['delivery_status'])->toBeNull()->and($t['destination'])->not->toBeNull();
    });

    it('does not show another customer’s order, to anyone', function () {
        $d = deliveryAt('out_for_delivery');
        driverIsAt($d, 25.28, 51.52);
        $stranger = User::factory()->create();
        trackingOf($d, $stranger)->assertNotFound();
        // A made-up id, and a malformed one, are not found either (not a 500).
        $this->getJson('/api/v1/orders/'.Str::uuid().'/tracking')->assertNotFound();
        $this->getJson('/api/v1/orders/not-a-uuid/tracking')->assertNotFound();
        // The driver and branch staff use their own endpoints: a staff token is not a customer.
        Sanctum::actingAs($d->driver, ['staff']);
        $this->getJson("/api/v1/orders/{$d->orderId}/tracking")->assertForbidden();
        Sanctum::actingAs($d->mgr, ['staff']);
        $this->getJson("/api/v1/orders/{$d->orderId}/tracking")->assertForbidden();
        // Signed out.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/orders/{$d->orderId}/tracking")->assertUnauthorized();
        // The owner still can.
        trackingOf($d)->assertOk();
    });
});

describe('arrival estimate', function () {
    it('is kept for 30 seconds per delivery', function () {
        $d = deliveryAt('out_for_delivery');
        driverIsAt($d, 25.28, 51.52);
        $first = trackingOf($d)->json('data.eta');
        driverIsAt($d, 25.2999, 51.5001);   // almost there, but the estimate is cached
        $second = trackingOf($d)->json('data.eta');
        expect($second)->toBe($first);
        Cache::forget('eta:'.$d->delivery->id);
        expect(trackingOf($d)->json('data.eta.distance_m'))->toBeLessThan($first['distance_m'])->and(trackingOf($d)->json('data.eta.minutes'))->toBe(1);
    });

    it('is skipped when the order has no map pin', function () {
        $d = deliveryAt('out_for_delivery', ['latitude' => null, 'longitude' => null]);
        driverIsAt($d, 25.28, 51.52);
        $t = trackingOf($d)->assertOk()->json('data');
        expect($t['tracking'])->toBeTrue()->and($t['destination'])->toBeNull()->and($t['eta'])->toBeNull();
    });

    it('uses the routing server when one is configured', function () {
        app(Settings::class)->set('map.routing_url', 'https://router.example.com/');
        Http::fake(['router.example.com/*' => Http::response(['code' => 'Ok', 'routes' => [['duration' => 610.2, 'distance' => 4321.7]]])]);
        $d = deliveryAt('out_for_delivery');
        driverIsAt($d, 25.28, 51.52);

        $eta = trackingOf($d)->assertOk()->json('data.eta');
        expect($eta['source'])->toBe('osrm')->and($eta['minutes'])->toBe(11)->and($eta['distance_m'])->toBe(4322);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://router.example.com/route/v1/driving/51.520000,25.280000;51.500000,25.300000') && $r['overview'] === 'false');
        Http::assertSentCount(1);
        trackingOf($d)->assertOk();   // cached: no second request
        Http::assertSentCount(1);
    });

    it('falls back to the straight-line estimate when the routing server fails, and leaves it alone for a minute', function (callable $fake) {
        app(Settings::class)->set('map.routing_url', 'https://router.example.com');
        Http::fake($fake());
        $d = deliveryAt('out_for_delivery');
        driverIsAt($d, 25.28, 51.52);

        $r = trackingOf($d)->assertOk();
        expect($r->json('data.eta.source'))->toBe('estimate')->and($r->json('data.eta.minutes'))->toBeGreaterThanOrEqual(1)->and($r->json('data.tracking'))->toBeTrue();
        // The estimate is cached too; drop it to prove the failed router is not asked again straight away.
        Cache::forget('eta:'.$d->delivery->id);
        expect(trackingOf($d)->json('data.eta.source'))->toBe('estimate');
        Http::assertSentCount(1);
    })->with([
        'server error' => [fn () => fn () => ['router.example.com/*' => Http::response('boom', 500)]],
        'no route found' => [fn () => fn () => ['router.example.com/*' => Http::response(['code' => 'NoRoute', 'routes' => []])]],
        'garbage body' => [fn () => fn () => ['router.example.com/*' => Http::response('<html>', 200)]],
        'timeout or refused connection' => [fn () => fn () => ['router.example.com/*' => fn () => throw new ConnectionException('timed out')]],
    ]);

    it('never contacts an invalid routing URL', function () {
        app(Settings::class)->set('map.routing_url', 'http://router.example.com');
        Http::fake();
        $d = deliveryAt('out_for_delivery');
        driverIsAt($d, 25.28, 51.52);
        expect(trackingOf($d)->json('data.eta.source'))->toBe('estimate');
        Http::assertNothingSent();
    });
});

describe('staff fleet', function () {
    it('lists the drivers on duty with position, age, vehicle and the job in hand', function () {
        $d = deliveryAt('out_for_delivery');
        driverIsAt($d, 25.28, 51.52, 30, ['last_heading' => 180, 'last_speed_kmh' => 22.0, 'last_accuracy_m' => 7, 'last_battery' => 41, 'vehicle' => 'Van']);
        actingAsStaff($d->mgr);

        $r = $this->getJson('/api/v1/admin/dispatch/fleet')->assertOk();
        $row = $r->json('data.0');
        expect($r->json('data'))->toHaveCount(1)
            ->and($row)->toMatchArray(['id' => $d->driver->id, 'name' => $d->driver->name, 'vehicle' => 'Van', 'on_duty' => true, 'lat' => 25.28, 'lng' => 51.52, 'heading' => 180,
                'speed_kmh' => 22.0, 'accuracy_m' => 7, 'battery' => 41, 'stale' => false, 'open_jobs' => 1])
            ->and($row['age_seconds'])->toBeBetween(30, 35)->and($row['at'])->toBeString()->and($row['phone'])->toBe($d->driver->phone)
            ->and($row['job'])->toMatchArray(['delivery_id' => $d->delivery->id, 'status' => 'out_for_delivery', 'order_id' => $d->orderId, 'destination' => ['lat' => 25.3, 'lng' => 51.5]])
            ->and($row['job']['order_number'])->toBeString()
            ->and($r->json('meta.stale_after_seconds'))->toBe(120)->and($r->json('meta.at'))->toBeString();
    });

    it('marks an old fix stale', function () {
        $d = deliveryAt('picked_up');
        driverIsAt($d, 25.28, 51.52, 500);
        actingAsStaff($d->mgr);
        $row = $this->getJson('/api/v1/admin/dispatch/fleet')->json('data.0');
        expect($row['stale'])->toBeTrue()->and($row['age_seconds'])->toBeGreaterThanOrEqual(500);
    });

    it('shows a driver who has not reported a position yet without coordinates', function () {
        $w = commerceWorld();
        $driver = driverFor($w->a);
        actingAsStaff(staffWithRole('order_manager', $w->a));
        $row = $this->getJson('/api/v1/admin/dispatch/fleet')->json('data.0');
        expect($row['id'])->toBe($driver->id)->and($row['lat'])->toBeNull()->and($row['lng'])->toBeNull()->and($row['stale'])->toBeTrue()->and($row['age_seconds'])->toBeNull()->and($row['job'])->toBeNull();
    });

    it('lists only drivers on duty, or still carrying an order', function () {
        $d = deliveryAt('picked_up');
        $idle = driverFor($d->w->a);
        $off = driverFor($d->w->a, onDuty: false);
        DriverProfile::whereIn('user_id', [$d->driver->id, $idle->id, $off->id])->update(['last_lat' => 25.3, 'last_lng' => 51.5, 'last_fix_at' => now(), 'last_seen_at' => now()]);
        $ids = fn () => collect($this->getJson('/api/v1/admin/dispatch/fleet')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        actingAsStaff($d->mgr);
        expect($ids())->toBe(collect([$d->driver->id, $idle->id])->sort()->values()->all());

        // The busy driver switches off duty with the order still on the road: stays on the map until it is delivered.
        actingAsStaff($d->driver);
        $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => false])->assertOk();
        actingAsStaff($d->mgr);
        $row = collect($this->getJson('/api/v1/admin/dispatch/fleet')->json('data'))->firstWhere('id', $d->driver->id);
        expect($row['on_duty'])->toBeFalse()->and($row['lat'])->toBe(25.3)->and($ids())->toBe(collect([$d->driver->id, $idle->id])->sort()->values()->all());

        Delivery::whereKey($d->delivery->id)->update(['status' => 'delivered', 'delivered_at' => now()]);
        expect($ids())->toBe([$idle->id]);
    });

    it('is scoped to the branches the staff member works for', function () {
        $w = commerceWorld();
        $mine = driverFor($w->a);
        $theirs = driverFor($w->b);
        $both = driverFor([$w->a, $w->b]);
        $managerA = staffWithRole('order_manager', $w->a);
        $super = staffWithRole('super_admin');

        actingAsStaff($managerA);
        expect(collect($this->getJson('/api/v1/admin/dispatch/fleet')->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$mine->id, $both->id])->sort()->values()->all());
        // Asking for another branch can only narrow, never widen.
        expect($this->getJson('/api/v1/admin/dispatch/fleet?branch_id='.$w->b->id)->assertOk()->json('data'))->toBe([]);

        actingAsStaff($super);
        expect(collect($this->getJson('/api/v1/admin/dispatch/fleet')->json('data'))->pluck('id')->all())->toContain($mine->id, $theirs->id, $both->id);
        expect(collect($this->getJson('/api/v1/admin/dispatch/fleet?branch_id='.$w->b->id)->json('data'))->pluck('id')->all())->toContain($theirs->id, $both->id)->not->toContain($mine->id);
    });

    it('is closed to customers, signed-out callers and staff without delivery access', function () {
        $w = commerceWorld();
        driverFor($w->a);
        Sanctum::actingAs($w->customer, ['customer']);
        $this->getJson('/api/v1/admin/dispatch/fleet')->assertForbidden();
        actingAsStaff(staffWithRole('auction_manager'));
        $this->getJson('/api/v1/admin/dispatch/fleet')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/dispatch/fleet')->assertUnauthorized();
    });
});

describe('delivery route history', function () {
    /** $n breadcrumbs along a sine-wave path, 5 seconds apart, ending $endMinutesAgo minutes ago. */
    function breadcrumbs(object $d, int $n, int $endMinutesAgo = 5): void
    {
        $start = now()->subMinutes($endMinutesAgo)->subSeconds(($n - 1) * 5);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['driver_id' => $d->driver->id, 'delivery_id' => $d->delivery->id, 'lat' => round(25.28 + sin($i / 12) * 0.004, 6), 'lng' => round(51.50 + $i * 0.00004, 6),
                'speed_kmh' => 30.0, 'heading' => 90, 'accuracy_m' => 6, 'recorded_at' => $start->copy()->addSeconds($i * 5)];
        }
        DriverLocation::insert($rows);
    }

    it('returns the path reduced to at most 300 points, with totals, the destination and the branch', function () {
        $d = deliveryAt('out_for_delivery');
        breadcrumbs($d, 1000);
        driverIsAt($d, 25.285, 51.54, 10);
        actingAsStaff($d->mgr);

        $r = $this->getJson("/api/v1/admin/deliveries/{$d->delivery->id}/route")->assertOk();
        $data = $r->json('data');
        $raw = DriverLocation::orderBy('recorded_at')->get();
        expect($data['delivery_id'])->toBe($d->delivery->id)->and($data['status'])->toBe('out_for_delivery')->and($data['order_id'])->toBe($d->orderId)
            ->and($data['driver'])->toBe(['id' => $d->driver->id, 'name' => $d->driver->name])
            ->and($data['destination'])->toBe(['lat' => 25.3, 'lng' => 51.5])->and($data['branch'])->toHaveKeys(['name', 'lat', 'lng'])
            ->and($data['live'])->toMatchArray(['lat' => 25.285, 'lng' => 51.54, 'stale' => false])
            ->and($data['route']['raw_count'])->toBe(1000)->and(count($data['route']['points']))->toBeLessThanOrEqual(300)->and(count($data['route']['points']))->toBeGreaterThan(20)
            ->and($data['route']['points'][0])->toMatchArray(['lat' => (float) $raw->first()->lat, 'lng' => (float) $raw->first()->lng, 'speed_kmh' => 30.0, 'heading' => 90])
            ->and($data['route']['points'][count($data['route']['points']) - 1]['lat'])->toBe((float) $raw->last()->lat)
            ->and($data['route']['duration_s'])->toBe(999 * 5)->and($data['route']['distance_m'])->toBeGreaterThan(2000)
            ->and($data['route']['started_at'])->toBe($data['route']['points'][0]['at'])->and($data['route']['ended_at'])->toBe($data['route']['points'][count($data['route']['points']) - 1]['at']);
        // Oldest first.
        $times = array_map(fn ($p) => strtotime($p['at']), $data['route']['points']);
        expect($times)->toBe(collect($times)->sort()->values()->all());
    });

    it('finds the places the driver stood still', function () {
        $d = deliveryAt('out_for_delivery');
        $t0 = now()->subMinutes(30);
        $rows = [];
        // Drive, stand 4 minutes at the same corner (a stop), drive on, stand 30 seconds (not a stop).
        foreach ([[25.2800, 0], [25.2810, 20], [25.2820, 40]] as [$lat, $s]) {
            $rows[] = ['lat' => $lat, 'lng' => 51.50, 't' => $t0->copy()->addSeconds($s)];
        }
        foreach (range(0, 8) as $i) {
            $rows[] = ['lat' => 25.2830 + $i * 0.0000005, 'lng' => 51.50, 't' => $t0->copy()->addSeconds(60 + $i * 30)];
        }
        foreach ([[25.2900, 400], [25.2950, 420], [25.2950, 450]] as [$lat, $s]) {
            $rows[] = ['lat' => $lat, 'lng' => 51.51, 't' => $t0->copy()->addSeconds($s)];
        }
        DriverLocation::insert(array_map(fn ($r) => ['driver_id' => $d->driver->id, 'delivery_id' => $d->delivery->id, 'lat' => $r['lat'], 'lng' => $r['lng'], 'recorded_at' => $r['t']], $rows));
        actingAsStaff($d->mgr);

        $stops = $this->getJson("/api/v1/admin/deliveries/{$d->delivery->id}/route")->assertOk()->json('data.route.stops');
        expect($stops)->toHaveCount(1)->and($stops[0]['lat'])->toEqualWithDelta(25.2830, 0.0001)->and($stops[0]['duration_s'])->toBe(240)
            ->and($stops[0])->toHaveKeys(['lat', 'lng', 'from', 'to', 'duration_s']);
    });

    it('answers a delivery that has no recording yet', function () {
        $d = deliveryAt('accepted');
        actingAsStaff($d->mgr);
        $this->getJson("/api/v1/admin/deliveries/{$d->delivery->id}/route")->assertOk()
            ->assertJsonPath('data.route.points', [])->assertJsonPath('data.route.stops', [])->assertJsonPath('data.route.distance_m', 0)
            ->assertJsonPath('data.route.duration_s', null)->assertJsonPath('data.route.raw_count', 0);
    });

    it('is only for staff who may see that branch’s deliveries', function () {
        $d = deliveryAt('out_for_delivery');
        breadcrumbs($d, 10);
        $url = "/api/v1/admin/deliveries/{$d->delivery->id}/route";

        actingAsStaff($d->mgr);
        $this->getJson($url)->assertOk();
        actingAsStaff(staffWithRole('super_admin'));
        $this->getJson($url)->assertOk();
        actingAsStaff(staffWithRole('order_manager', $d->w->b));        // another branch
        $this->getJson($url)->assertForbidden();
        actingAsStaff(staffWithRole('auction_manager'));                  // no delivery permission
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($d->customer, ['customer']);
        $this->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
        actingAsStaff($d->mgr);
        $this->getJson('/api/v1/admin/deliveries/'.Str::uuid().'/route')->assertNotFound();
        $this->getJson('/api/v1/admin/deliveries/nope/route')->assertNotFound();
    });

    it('is served to the dashboard session too, with the same rules', function () {
        $d = deliveryAt('out_for_delivery');
        breadcrumbs($d, 12);
        $this->app['auth']->forgetGuards();
        $this->actingAs($d->mgr)->getJson(route('admin.deliveries.route', $d->delivery))->assertOk()->assertJsonPath('data.route.raw_count', 12);
        $this->actingAs(staffWithRole('order_manager', $d->w->b))->getJson(route('admin.deliveries.route', $d->delivery))->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson(route('admin.deliveries.route', $d->delivery))->assertStatus(403);
    });
});
