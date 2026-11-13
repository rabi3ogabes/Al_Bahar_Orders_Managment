<?php

use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Partners\Services\PartnerAccess;
use Illuminate\Support\Str;

/** Turns the partner page on and signs this test's browser in; returns the link token. */
function partnerSignedIn(): string
{
    $access = app(PartnerAccess::class);
    $access->setPassword('Correct-Horse-9');
    $access->setEnabled(true);
    test()->postJson("/partner/{$access->token()}/unlock", ['password' => 'Correct-Horse-9'])->assertOk();

    return $access->token();
}

it('keeps the live drivers behind the link and the password', function () {
    $access = app(PartnerAccess::class);
    $access->setPassword('Correct-Horse-9');
    $access->setEnabled(true);
    $token = $access->token();
    $d = deliveryAt('out_for_delivery');

    $this->getJson("/partner/{$token}/fleet")->assertUnauthorized();
    $this->getJson("/partner/{$token}/orders/{$d->orderId}")->assertUnauthorized();
    $this->getJson("/partner/{$token}x/fleet")->assertNotFound();
    $this->getJson('/partner/wrong/fleet')->assertNotFound();
    $this->postJson("/partner/{$token}/unlock", ['password' => 'Correct-Horse-9'])->assertOk();
    $this->getJson("/partner/{$token}/fleet")->assertOk();
    // Locking again closes the data.
    $this->postJson("/partner/{$token}/lock")->assertOk();
    $this->getJson("/partner/{$token}/fleet")->assertUnauthorized();
});

it('gives partners the drivers on duty with positions and their order, without private details', function () {
    $d = deliveryAt('out_for_delivery');
    $d->driver->update(['name' => 'Ahmed Al Thani', 'phone' => '+97455001122']);
    DriverProfile::where('user_id', $d->driver->id)->update(['last_lat' => 25.28, 'last_lng' => 51.52, 'last_heading' => 45, 'last_speed_kmh' => 28.5, 'last_accuracy_m' => 10,
        'last_battery' => 33, 'last_fix_at' => now()->subSeconds(15), 'last_seen_at' => now(), 'vehicle' => 'Motorbike']);
    $token = partnerSignedIn();

    $r = $this->getJson("/partner/{$token}/fleet")->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
    $data = $r->json('data');
    expect($data['stale_after_seconds'])->toBe(120)->and($data['at'])->toBeString()->and($data['drivers'])->toHaveCount(1);
    $row = $data['drivers'][0];
    expect($row)->toMatchArray(['id' => $d->driver->id, 'name' => 'Ahmed', 'vehicle' => 'Motorbike', 'on_duty' => true, 'lat' => 25.28, 'lng' => 51.52,
        'heading' => 45, 'speed_kmh' => 28.5, 'accuracy_m' => 10, 'stale' => false])
        ->and($row['age_seconds'])->toBeBetween(15, 20)->and($row['at'])->toBeString()
        ->and($row['job'])->toMatchArray(['status' => 'out_for_delivery', 'destination' => ['lat' => 25.3, 'lng' => 51.5]])
        ->and(array_keys($row))->not->toContain('phone', 'battery', 'branches', 'open_jobs');
    expect(json_encode($data))->not->toContain('+97455001122')->not->toContain('Al Thani');   // partners see the driver's first name only
});

it('gives partners an order’s route as a simplified polyline with stops, and the destination', function () {
    $d = deliveryAt('out_for_delivery');
    $rows = [];
    $t0 = now()->subMinutes(90);
    // 150 points of driving, four minutes standing at one spot, then 650 more points of driving.
    foreach (range(0, 799) as $i) {
        $rows[] = ['driver_id' => $d->driver->id, 'delivery_id' => $d->delivery->id, 'lat' => round(25.27 + sin($i / 10) * 0.005, 6), 'lng' => round(51.48 + $i * 0.00003, 6),
            'speed_kmh' => 25.0, 'heading' => 90, 'accuracy_m' => 5, 'recorded_at' => $t0->copy()->addSeconds($i * 6 + ($i >= 150 ? 300 : 0))];
    }
    foreach (range(0, 8) as $i) {
        $rows[] = ['driver_id' => $d->driver->id, 'delivery_id' => $d->delivery->id, 'lat' => 25.31, 'lng' => 51.45, 'speed_kmh' => 0.0, 'heading' => null, 'accuracy_m' => 5, 'recorded_at' => $t0->copy()->addSeconds(900 + $i * 30)];
    }
    DriverLocation::insert($rows);
    $token = partnerSignedIn();

    $o = $this->getJson("/partner/{$token}/orders/{$d->orderId}")->assertOk()->json('data');
    $delivery = $o['deliveries'][0];
    expect($o['destination'])->toBe(['lat' => 25.3, 'lng' => 51.5])
        ->and($delivery['id'])->toBe($d->delivery->id)->and($delivery['route'])->not->toBeEmpty()->and(count($delivery['route']))->toBeLessThanOrEqual(300)
        ->and($delivery['route'][0])->toHaveKeys(['lat', 'lng', 'at', 'speed_kmh', 'heading'])
        ->and($delivery['stops'])->not->toBeEmpty()->and($delivery['stops'][0])->toHaveKeys(['lat', 'lng', 'from', 'to', 'duration_s'])
        ->and($delivery['distance_m'])->toBeGreaterThan(1000)->and($delivery['duration_s'])->toBeGreaterThan(4000)
        ->and($delivery['started_at'])->toBeString()->and($delivery['ended_at'])->toBeString();
    expect(collect($delivery['stops'])->contains(fn ($s) => abs($s['lat'] - 25.31) < 0.001 && $s['duration_s'] >= 240))->toBeTrue();
});

it('throttles the live map to 60 requests a minute and the order detail to 90', function () {
    $d = deliveryAt('picked_up');
    $token = partnerSignedIn();
    foreach (range(1, 60) as $i) {
        $this->getJson("/partner/{$token}/fleet")->assertOk();
    }
    $this->getJson("/partner/{$token}/fleet")->assertStatus(429);
    foreach (range(1, 90) as $i) {
        $this->getJson("/partner/{$token}/orders/{$d->orderId}")->assertOk();
    }
    $this->getJson("/partner/{$token}/orders/{$d->orderId}")->assertStatus(429);
});

it('answers an unknown or malformed order id with 404', function () {
    $token = partnerSignedIn();
    $this->getJson("/partner/{$token}/orders/".Str::uuid())->assertNotFound();
    $this->getJson("/partner/{$token}/orders/not-a-uuid")->assertNotFound();
});
