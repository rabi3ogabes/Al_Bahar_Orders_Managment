<?php

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Fulfillment\Models\DriverProfile;

require_once __DIR__.'/../Orders/OpsFixtures.php';
require_once __DIR__.'/../Operations/OpsSupport.php';

/** Going off duty with jobs in hand is refused, with a clear sentence; going off duty with nothing in hand gives the unaccepted jobs to someone else. */
const DUTY_URL = '/api/v1/admin/dispatch/driver/duty';

/** The driver, signed in, with his one job walked to $status. */
function dutyDriver(string $status): object
{
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);
    $steps = ['assigned' => [], 'accepted' => ['accept'], 'picked_up' => ['accept', 'pickup'], 'out_for_delivery' => ['accept', 'pickup', 'out-for-delivery']][$status] ?? ['accept', 'pickup', 'out-for-delivery'];
    foreach ($steps as $step) {
        test()->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/{$step}")->assertOk();
    }
    if ($status === 'failed') {
        test()->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/fail", ['reason_code' => 'customer_absent'])->assertOk();
    }

    return $d;
}

it('refuses to go off duty while a job is accepted or on the road, and says how many', function (string $status) {
    $d = dutyDriver($status);

    $this->putJson(DUTY_URL, ['on_duty' => false])->assertStatus(409)->assertJsonPath('code', 'jobs_in_progress')->assertJsonPath('context.count', 1)
        ->assertJsonPath('message', 'You still have 1 job. Finish it or ask the branch to hand it back.');

    expect(DriverProfile::find($d->driver->id)->on_duty)->toBeTrue();
})->with(['accepted', 'picked_up', 'out_for_delivery', 'failed']);

it('counts the jobs in the sentence, in the language of the app', function () {
    $d = dutyDriver('accepted');
    $ids = opsOrder($d->w->a, [], ['status' => 'ready', 'type' => 'delivery']);
    opsDelivery($ids['part'], $d->w->a, 'picked_up', ['driver_id' => $d->driver->id, 'assigned_at' => now()]);

    $this->putJson(DUTY_URL, ['on_duty' => false])->assertStatus(409)->assertJsonPath('context.count', 2)
        ->assertJsonPath('message', 'You still have 2 jobs. Finish them or ask the branch to hand them back.');
    $this->putJson(DUTY_URL, ['on_duty' => false], ['Accept-Language' => 'ar'])->assertStatus(409)->assertJsonPath('message', 'ما زال عندك طلبان. أنهِهما أو اطلب من الفرع استرجاعهما.');
});

it('lets him go off duty when nothing is in his hands, and a second tap changes nothing', function () {
    $d = opsAssignedDelivery();
    Delivery::whereKey($d->delivery->id)->update(['status' => 'delivered', 'delivered_at' => now()]);
    actingAsStaff($d->driver);

    $this->putJson(DUTY_URL, ['on_duty' => false])->assertOk()->assertJsonPath('data.on_duty', false);
    $this->putJson(DUTY_URL, ['on_duty' => false])->assertOk()->assertJsonPath('data.on_duty', false);

    expect(DriverProfile::find($d->driver->id)->on_duty)->toBeFalse()->and(DriverProfile::find($d->driver->id)->on_duty_since)->toBeNull();
});

it('does not trap a driver who is already off duty with jobs still on him', function () {
    $d = dutyDriver('picked_up');
    DriverProfile::where('user_id', $d->driver->id)->update(['on_duty' => false]);

    $this->putJson(DUTY_URL, ['on_duty' => false])->assertOk()->assertJsonPath('data.on_duty', false);
});

it('gives a job he never accepted to another driver the moment he goes off duty', function () {
    $d = opsAssignedDelivery();
    $d->w->a->update(['dispatch_mode' => 'auto']);
    $other = driverFor($d->w->a);
    actingAsStaff($d->driver);

    $this->putJson(DUTY_URL, ['on_duty' => false])->assertOk();

    $fresh = $d->delivery->fresh();
    expect($fresh->driver_id)->toBe($other->id)->and($fresh->status->value)->toBe('assigned')
        ->and(DeliveryDecline::where('delivery_id', $fresh->id)->where('driver_id', $d->driver->id)->value('reason'))->toBe('off_duty');
});

it('leaves a job he never accepted waiting when the branch picks its drivers by hand', function () {
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);

    $this->putJson(DUTY_URL, ['on_duty' => false])->assertOk();

    expect($d->delivery->fresh()->status->value)->toBe('pending')->and($d->delivery->fresh()->driver_id)->toBeNull();
});

it('remembers when he went on duty, and a repeated "on" does not move it', function () {
    $d = opsAssignedDelivery();
    DriverProfile::where('user_id', $d->driver->id)->update(['on_duty' => false, 'on_duty_since' => null]);
    actingAsStaff($d->driver);

    $this->travelTo(qatar('2026-11-19 07:30'));
    $this->putJson(DUTY_URL, ['on_duty' => true])->assertOk();
    $since = DriverProfile::find($d->driver->id)->on_duty_since;
    $this->travelTo(qatar('2026-11-19 09:00'));
    $this->putJson(DUTY_URL, ['on_duty' => true])->assertOk();

    expect($since->toIso8601String())->toBe(qatar('2026-11-19 07:30')->utc()->toIso8601String())
        ->and(DriverProfile::find($d->driver->id)->on_duty_since->toIso8601String())->toBe($since->toIso8601String());
});

it('answers a staff member who is not a driver with a clear 403', function () {
    $d = opsAssignedDelivery();
    actingAsStaff($d->mgr);

    $this->putJson(DUTY_URL, ['on_duty' => false])->assertForbidden()->assertJsonPath('code', 'driver_not_eligible');
});

it('keeps him on duty, with one profile, after repeated refused taps', function () {
    $d = dutyDriver('accepted');

    $this->putJson(DUTY_URL, ['on_duty' => false])->assertStatus(409);
    $this->putJson(DUTY_URL, ['on_duty' => false])->assertStatus(409);

    expect(DriverProfile::where('user_id', $d->driver->id)->count())->toBe(1)->and(DriverProfile::find($d->driver->id)->on_duty)->toBeTrue();
});
