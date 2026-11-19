<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Events\DriverJobUpdated;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Orders/OpsFixtures.php';

/** A driver hands back a job he was given and has not accepted: it goes back to waiting, or to the next driver, and the managers know. */
function declineUrl(Delivery|string $d): string
{
    return '/api/v1/admin/dispatch/jobs/'.($d instanceof Delivery ? $d->id : $d).'/decline';
}

/** A branch that gives jobs out by itself, an assigned job and $others more drivers on duty. The session is the first driver. */
function autoJob(int $others = 1): object
{
    $d = opsAssignedDelivery();
    $d->w->a->update(['dispatch_mode' => 'auto']);
    $d->others = [];
    for ($i = 0; $i < $others; $i++) {
        $d->others[] = driverFor($d->w->a);
    }
    actingAsStaff($d->driver);

    return $d;
}

it('hands the job back: waiting again, nobody holds it, the history and the audit say why', function () {
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far', 'note' => 'I am in Al Khor'])->assertOk()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.declined', true);

    $fresh = $d->delivery->fresh();
    expect($fresh->status->value)->toBe('pending')->and($fresh->driver_id)->toBeNull()->and($fresh->assigned_at)->toBeNull();
    $row = DeliveryDecline::where('delivery_id', $fresh->id)->firstOrFail();
    expect($row->driver_id)->toBe($d->driver->id)->and($row->reason)->toBe('too_far')->and($row->note)->toBe('I am in Al Khor');
    expect(DB::table('delivery_status_history')->where('delivery_id', $fresh->id)->orderByDesc('id')->first())
        ->note->toBe('declined: too_far (I am in Al Khor)')->from_status->toBe('assigned')->to_status->toBe('pending')->actor_id->toBe($d->driver->id);
    $audit = AuditLog::where('action', 'delivery.declined')->where('auditable_id', $fresh->id)->firstOrFail();
    expect($audit->actor_id)->toBe($d->driver->id)->and($audit->metadata['reason'])->toBe('too_far');
    // The job is gone from his list.
    expect($this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data'))->toBe([]);
});

it('takes each of the four reasons', function (string $reason) {
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => $reason])->assertOk();

    expect(DeliveryDecline::where('delivery_id', $d->delivery->id)->value('reason'))->toBe($reason);
})->with(['too_far', 'vehicle', 'shift_ending', 'other']);

it('refuses a reason that is not one of them, and system reasons', function (string $reason) {
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => $reason])->assertStatus(422)->assertJsonValidationErrors('reason');

    expect($d->delivery->fresh()->status->value)->toBe('assigned');
})->with(['lazy', 'timeout', 'off_duty', '']);

it('tells the branch managers who handed it back and why, in their language, with no customer data', function () {
    $d = opsAssignedDelivery();
    $pushes = opsFakeFcm();
    opsPhone($d->mgr, 'mgr-phone', 'ar');
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'shift_ending'])->assertOk();

    $inbox = Notification::where('user_id', $d->mgr->id)->where('event', 'staff.order_reminder')->get();
    expect($inbox)->toHaveCount(1)->and($inbox->first()->title)->toBe('Order '.$d->order->number.' was handed back')
        ->and($inbox->first()->body)->toContain(explode(' ', $d->driver->name)[0])->toContain('his shift is ending')->toContain('Please assign another driver.')
        ->and($inbox->first()->body)->not->toContain((string) $d->order->customer_phone)->not->toContain((string) $d->order->customer_name);
    expect($pushes)->toHaveCount(1)->and($pushes[0][0])->toBe('mgr-phone')->and($pushes[0][1])->toContain($d->order->number)
        ->and($pushes[0][3])->toMatchArray(['kind' => 'driver_declined', 'order_id' => $d->order->id, 'event' => 'staff.order_reminder']);
});

it('says the same in Arabic to a manager whose phone is Arabic', function () {
    $d = opsAssignedDelivery();
    $pushes = opsFakeFcm();
    opsPhone($d->mgr, 'mgr-phone', 'ar');
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertOk();

    expect($pushes[0][1])->toBe('أُعيد الطلب '.$d->order->number)->and($pushes[0][2])->toContain('المسافة بعيدة');
});

it('is silent for the managers when the admin switched the order reminders off', function () {
    $d = opsAssignedDelivery();
    app(NotificationRules::class)->set('staff.order_reminder', false, [], $d->mgr);
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'other'])->assertOk();

    expect(Notification::where('event', 'staff.order_reminder')->count())->toBe(0)->and($d->delivery->fresh()->status->value)->toBe('pending');
});

it('leaves a manual branch to its managers: no automatic assignment', function () {
    $d = opsAssignedDelivery();
    $other = driverFor($d->w->a);
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'vehicle'])->assertOk();

    expect($d->delivery->fresh()->driver_id)->toBeNull()->and($other->fresh())->not->toBeNull();
});

it('gives the job to the next driver at once in a branch that gives jobs out by itself, never back to the one who declined', function () {
    $d = autoJob(others: 1);
    $pushes = opsFakeFcm();
    opsPhone($d->mgr, 'mgr-phone');

    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertOk()->assertJsonPath('data.declined', true);

    $fresh = $d->delivery->fresh();
    expect($fresh->status->value)->toBe('assigned')->and($fresh->driver_id)->toBe($d->others[0]->id);
    $body = Notification::where('user_id', $d->mgr->id)->where('event', 'staff.order_reminder')->value('body');
    expect($body)->toContain('It was given to '.explode(' ', $d->others[0]->name)[0]);
});

it('leaves the job waiting when nobody else is on duty, and never hands it back to the driver who declined, even after he goes off and on duty', function () {
    $d = autoJob(others: 0);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'other'])->assertOk();
    expect($d->delivery->fresh()->status->value)->toBe('pending');
    $body = Notification::where('user_id', $d->mgr->id)->where('event', 'staff.order_reminder')->value('body');
    expect($body)->toContain('No other driver is on duty');

    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => false])->assertOk();
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true])->assertOk();
    expect($d->delivery->fresh()->status->value)->toBe('pending')->and($d->delivery->fresh()->driver_id)->toBeNull();

    // A driver who has not declined it gets it the moment he goes on duty.
    $late = driverFor($d->w->a, onDuty: false);
    actingAsStaff($late);
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true])->assertOk();
    expect($d->delivery->fresh()->driver_id)->toBe($late->id);
});

it('does not offer it to a driver who is off duty or who already declined it', function () {
    $d = autoJob(others: 0);
    $off = driverFor($d->w->a, onDuty: false);
    $second = driverFor($d->w->a);
    $this->postJson(declineUrl($d->delivery), ['reason' => 'other'])->assertOk();
    expect($d->delivery->fresh()->driver_id)->toBe($second->id)->and($d->delivery->fresh()->driver_id)->not->toBe($off->id);

    actingAsStaff($second);
    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertOk();

    // The only drivers on duty are the two who declined: it waits.
    expect($d->delivery->fresh()->status->value)->toBe('pending')->and(DeliveryDecline::where('delivery_id', $d->delivery->id)->count())->toBe(2);
});

it('is safe to tap twice: one row, one history entry, one message to the managers', function () {
    $d = autoJob(others: 1);
    $call = fn () => $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertOk();

    $call();
    $call();

    expect(DeliveryDecline::where('delivery_id', $d->delivery->id)->count())->toBe(1)
        ->and(DB::table('delivery_status_history')->where('delivery_id', $d->delivery->id)->where('note', 'like', 'declined%')->count())->toBe(1)
        ->and(Notification::where('user_id', $d->mgr->id)->where('event', 'staff.order_reminder')->count())->toBe(1)
        ->and($d->delivery->fresh()->driver_id)->toBe($d->others[0]->id);
});

it('keeps one row when a manager hands him the same job again and he declines it again', function () {
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);
    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertOk();
    actingAsStaff($d->mgr);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$d->f->id}/assign", ['driver_id' => $d->driver->id])->assertOk()->assertJsonPath('data.status', 'assigned');
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'vehicle'])->assertOk();

    $rows = DeliveryDecline::where('delivery_id', $d->delivery->id)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->reason)->toBe('vehicle');
});

it('cannot hand back a job he has accepted: the branch has to take it back', function (string $status) {
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);
    foreach (['assigned' => [], 'accepted' => ['accept'], 'picked_up' => ['accept', 'pickup']][$status] as $step) {
        $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/{$step}")->assertOk();
    }

    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertStatus($status === 'assigned' ? 200 : 409);

    expect($d->delivery->fresh()->status->value)->toBe($status === 'assigned' ? 'pending' : $status);
})->with(['assigned', 'accepted', 'picked_up']);

it('cannot hand back another driver\'s job, and staff who are not drivers cannot decline at all', function () {
    $d = opsAssignedDelivery();

    actingAsStaff(driverFor($d->w->a));
    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertForbidden()->assertJsonPath('code', 'delivery_not_yours');

    actingAsStaff($d->mgr);
    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertForbidden()->assertJsonPath('code', 'driver_not_eligible');

    expect($d->delivery->fresh()->driver_id)->toBe($d->driver->id)->and(DeliveryDecline::count())->toBe(0);
});

it('answers a clear 409 for a job whose order was cancelled', function () {
    $d = opsAssignedDelivery();
    Delivery::whereKey($d->delivery->id)->update(['status' => 'cancelled']);
    actingAsStaff($d->driver);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertStatus(409)->assertJsonPath('code', 'delivery_cancelled');
});

it('tells the driver whose job was taken that it is gone (the live message of a reassignment)', function () {
    $d = autoJob(others: 1);
    Event::fake([DriverJobUpdated::class]);

    $this->postJson(declineUrl($d->delivery), ['reason' => 'too_far'])->assertOk();

    $heard = collect(Event::dispatched(DriverJobUpdated::class))->map(fn ($a) => $a[0])->keyBy('driverId');
    expect($heard[$d->driver->id]->broadcastWith())->toMatchArray(['assigned' => false, 'status' => 'unassigned'])
        ->and($heard[$d->others[0]->id]->broadcastWith())->toMatchArray(['assigned' => true, 'status' => 'assigned']);
});
