<?php

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Orders\Models\Order;
use App\Domain\Partners\Services\PartnerAccess;
use App\Domain\Partners\Services\PartnerReport;
use App\Domain\Payments\Models\Payment;
use App\Domain\Settings\Services\Settings;

function partnerOn(string $password = 'Correct-Horse-9'): string
{
    $a = app(PartnerAccess::class);
    $a->setPassword($password);
    $a->setEnabled(true);

    return $a->token();
}

it('answers 404 for a wrong, missing or disabled link', function () {
    $this->get('/partner/nope')->assertNotFound();
    $token = partnerOn();
    $this->get("/partner/{$token}x")->assertNotFound();
    app(PartnerAccess::class)->setEnabled(false);
    $this->get("/partner/{$token}")->assertNotFound();
    $this->getJson("/partner/{$token}/data")->assertNotFound();
});

it('shows the lock screen, refuses data until unlocked, then unlocks with the password', function () {
    $token = partnerOn();
    $this->get("/partner/{$token}")->assertOk()->assertSee('Open statistics')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $this->getJson("/partner/{$token}/data")->assertStatus(401);
    $this->getJson("/partner/{$token}/fleet")->assertStatus(401);
    $this->postJson("/partner/{$token}/unlock", ['password' => 'wrong'])->assertStatus(422);
    $this->postJson("/partner/{$token}/unlock", ['password' => 'Correct-Horse-9'])->assertOk();
    $this->get("/partner/{$token}")->assertOk()->assertSee('id="app"', false);
    $this->getJson("/partner/{$token}/data")->assertOk()->assertJsonStructure(['data' => ['totals' => ['orders', 'net'], 'orders']]);
    expect(DB::table('partner_access_logs')->pluck('event')->all())->toContain('failed')->toContain('unlocked');
});

it('throttles password guessing', function () {
    $token = partnerOn();
    foreach (range(1, 6) as $i) {
        $this->postJson("/partner/{$token}/unlock", ['password' => 'bad'.$i])->assertStatus(422);
    }
    $this->postJson("/partner/{$token}/unlock", ['password' => 'Correct-Horse-9'])->assertStatus(429);
});

it('signs partners out when the password or the link changes', function () {
    $token = partnerOn();
    $this->postJson("/partner/{$token}/unlock", ['password' => 'Correct-Horse-9'])->assertOk();
    $this->getJson("/partner/{$token}/data")->assertOk();
    app(PartnerAccess::class)->setPassword('Another-Pass-77');
    $this->getJson("/partner/{$token}/data")->assertStatus(401);
});

it('reports the day: items, fees, SADAD transaction, SADAD fee and net', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $order = Order::find($orderId);
    $order->update(['paid_total' => '100.00', 'delivery_fee_total' => '10.00']);
    Payment::create(['payable_type' => 'order', 'payable_id' => $orderId, 'order_id' => $orderId, 'user_id' => $w->customer->id, 'method' => 'sadad', 'provider' => 'sadad',
        'status' => 'paid', 'amount' => '100.00', 'captured_amount' => '100.00', 'provider_reference' => 'SDD-42', 'paid_at' => now()]);
    app(Settings::class)->set('payments.sadad.fee_percent', 2);
    app(Settings::class)->set('payments.sadad.fee_fixed', '0.50');

    $day = app(PartnerReport::class)->day(now(config('platform.business_timezone'))->toDateString());
    $row = collect($day['orders'])->firstWhere('id', $orderId);
    expect($row['sadad']['reference'])->toBe('SDD-42')->and($row['sadad']['status'])->toBe('paid')
        ->and($row['sadad_fee'])->toBe('2.50')       // 100 × 2% + 0.50
        ->and($row['net'])->toBe('87.50')            // 100 − 2.50 − 10.00 delivery
        ->and($row['item_count'])->toBe(2)
        ->and($day['sadad_fee_configured'])->toBeTrue();
});

it('gives an order its items, transactions and the recorded route', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f, $mgr] = readyOrder($w);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $delivery = Delivery::first();
    foreach ([[25.28, 51.52], [25.29, 51.53], [25.30, 51.54]] as $i => [$lat, $lng]) {
        DriverLocation::create(['driver_id' => $driver->id, 'delivery_id' => $delivery->id, 'lat' => $lat, 'lng' => $lng, 'recorded_at' => now()->addSeconds($i * 10)]);
    }
    $o = app(PartnerReport::class)->order($f->order_id);
    expect($o['items'])->not->toBeEmpty()->and($o['items'][0])->toHaveKeys(['name', 'quantity', 'unit_price', 'line_total'])
        ->and($o['deliveries'][0]['route'])->toHaveCount(3)->and($o['deliveries'][0]['route'][0]['lat'])->toBe(25.28)
        ->and($o['deliveries'][0]['driver'])->toBe(explode(' ', $driver->name)[0]);
});

it('keeps order routes for a year but drops stray pings after a week', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f] = readyOrder($w);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $d = Delivery::first();
    DriverLocation::create(['driver_id' => $driver->id, 'delivery_id' => $d->id, 'lat' => 25.1, 'lng' => 51.1, 'recorded_at' => now()->subDays(30)]);
    DriverLocation::create(['driver_id' => $driver->id, 'delivery_id' => null, 'lat' => 25.2, 'lng' => 51.2, 'recorded_at' => now()->subDays(30)]);
    $this->artisan('drivers:prune-locations')->assertSuccessful();
    expect(DriverLocation::count())->toBe(1)->and(DriverLocation::first()->delivery_id)->toBe($d->id);
});

it('lists the routes of a day with first names only, and keeps the endpoint behind the password', function () {
    $token = partnerOn();
    $day = now(config('platform.business_timezone'))->toDateString();
    $this->getJson("/partner/{$token}/routes?date={$day}")->assertStatus(401);

    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f] = readyOrder($w);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $delivery = Delivery::first();
    foreach (range(0, 11) as $i) {
        DriverLocation::create(['driver_id' => $driver->id, 'delivery_id' => $delivery->id, 'lat' => 25.28 + $i * 0.001, 'lng' => 51.52, 'recorded_at' => now()->addSeconds($i * 20)]);
    }
    // Fixes without a delivery, and fixes of another day, never show up.
    DriverLocation::create(['driver_id' => $driver->id, 'delivery_id' => null, 'lat' => 25.1, 'lng' => 51.1, 'recorded_at' => now()]);

    $this->postJson("/partner/{$token}/unlock", ['password' => 'Correct-Horse-9'])->assertOk();
    $trips = $this->getJson("/partner/{$token}/routes?date={$day}")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.trips');
    expect($trips)->toHaveCount(1)
        ->and($trips[0]['driver'])->toBe(explode(' ', $driver->name)[0])
        ->and($trips[0]['route'])->not->toBeEmpty()->and($trips[0]['route'][0])->toHaveKeys(['lat', 'lng', 'at'])
        ->and($trips[0]['order_number'])->not->toBeNull()
        ->and(json_encode($trips))->not->toContain($driver->name);
    $this->getJson("/partner/{$token}/routes?date=2001-01-01")->assertOk()->assertJsonPath('data.trips', []);
    $this->getJson("/partner/{$token}/routes?date=nope")->assertStatus(422);
});

it('hands the unlocked page the map configuration and the shop pins, and the fleet only first names', function () {
    $token = partnerOn();
    $w = commerceWorld();
    $w->a->update(['latitude' => 25.3221, 'longitude' => 51.5291]);
    $driver = driverFor($w->a);
    DriverProfile::where('user_id', $driver->id)->update(['last_lat' => 25.3, 'last_lng' => 51.5, 'last_seen_at' => now()]);
    $this->postJson("/partner/{$token}/unlock", ['password' => 'Correct-Horse-9'])->assertOk();
    $this->get("/partner/{$token}")->assertOk()->assertSee('id="map-config"', false)->assertSee('tile.openstreetmap.org', false)->assertSee($w->a->name_en, false);
    $fleet = $this->getJson("/partner/{$token}/fleet")->assertOk()->json('data.drivers');
    expect($fleet)->toHaveCount(1)->and($fleet[0]['name'])->toBe(explode(' ', $driver->name)[0])->and($fleet[0])->not->toHaveKeys(['phone', 'branches']);
});
