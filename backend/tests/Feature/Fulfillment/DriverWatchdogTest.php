<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Settings\Services\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Orders/OpsFixtures.php';
require_once __DIR__.'/../Operations/OpsSupport.php';

/** dispatch:watchdog: a job nobody accepts goes to the next driver; a driver who went quiet is set off duty; what a driver is carrying is never touched. */
function runWatchdog(): array
{
    return app(DispatchService::class)->watchdog();
}

/** The job was given to its driver this many minutes ago. */
function givenMinutesAgo(Delivery $d, int $minutes): void
{
    DB::table('deliveries')->where('id', $d->id)->update(['assigned_at' => now()->subMinutes($minutes)]);
}

function managerMessages(object $d): Collection
{
    return Notification::where('user_id', $d->mgr->id)->where('event', 'staff.order_reminder')->orderBy('created_at')->get();
}

function autoWorld(int $others = 1): object
{
    $d = opsAssignedDelivery();
    $d->w->a->update(['dispatch_mode' => 'auto']);
    $d->others = [];
    for ($i = 0; $i < $others; $i++) {
        $d->others[] = driverFor($d->w->a);
    }

    return $d;
}

// ── A job nobody accepts ──────────────────────────────────────────────────────────────────────────────────────────────

it('takes a job back from a driver who did not accept it in five minutes and gives it to the next one', function () {
    $d = autoWorld(1);
    givenMinutesAgo($d->delivery, 6);

    $r = runWatchdog();

    $fresh = $d->delivery->fresh();
    expect($r)->toMatchArray(['released' => 1, 'reassigned' => 1, 'gave_up' => 0])->and($fresh->driver_id)->toBe($d->others[0]->id)->and($fresh->status->value)->toBe('assigned');
    expect(DeliveryDecline::where('delivery_id', $fresh->id)->where('driver_id', $d->driver->id)->value('reason'))->toBe('timeout')
        ->and(DB::table('delivery_status_history')->where('delivery_id', $fresh->id)->where('note', 'taken back: timeout')->count())->toBe(1)
        ->and(AuditLog::where('action', 'delivery.released')->where('auditable_id', $fresh->id)->count())->toBe(1);
});

it('leaves a job alone that was given less than five minutes ago, or that was accepted', function () {
    $d = autoWorld(1);
    givenMinutesAgo($d->delivery, 4);
    expect(runWatchdog()['released'])->toBe(0)->and($d->delivery->fresh()->driver_id)->toBe($d->driver->id);

    givenMinutesAgo($d->delivery, 90);
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/accept")->assertOk();
    givenMinutesAgo($d->delivery, 90);
    expect(runWatchdog()['released'])->toBe(0)->and($d->delivery->fresh()->driver_id)->toBe($d->driver->id)->and($d->delivery->fresh()->status->value)->toBe('accepted');
});

it('tells the driver who lost the job that it is no longer his', function () {
    $d = autoWorld(1);
    $pushes = opsFakeFcm();
    opsPhone($d->driver, 'silent-driver', 'ar');
    givenMinutesAgo($d->delivery, 6);

    runWatchdog();

    $toDriver = collect($pushes)->firstWhere(0, 'silent-driver');
    expect($toDriver)->not->toBeNull()->and($toDriver[3])->toMatchArray(['event' => 'dispatch.updated', 'job' => 'removed']);
});

it('stops after three drivers who did not answer: the job waits for a person and the managers are told', function () {
    $d = autoWorld(3);
    $order = [$d->driver, ...$d->others];
    $seen = [];

    for ($round = 1; $round <= 3; $round++) {
        $job = $d->delivery->fresh();
        $seen[] = $job->driver_id;
        givenMinutesAgo($job, 6);
        $r = runWatchdog();
        expect($r['released'])->toBe(1);
    }

    $fresh = $d->delivery->fresh();
    expect($fresh->status->value)->toBe('pending')->and($fresh->driver_id)->toBeNull()->and(collect($seen)->unique())->toHaveCount(3)
        ->and(DeliveryDecline::where('delivery_id', $fresh->id)->where('reason', 'timeout')->count())->toBe(3);
    $msg = managerMessages($d)->last();
    expect($msg->title)->toBe('No driver took order '.$d->order->number)->and($msg->body)->toContain('3 drivers');
    expect(runWatchdog()['released'])->toBe(0); // and it does not try a fourth time on its own
    expect(count($order))->toBe(4);
});

it('shows a job the system gave up on in Needs attention, saying the drivers did not answer', function () {
    $d = autoWorld(3);
    for ($round = 1; $round <= 3; $round++) {
        givenMinutesAgo($d->delivery->fresh(), 6);
        runWatchdog();
    }
    expect($d->delivery->fresh()->status->value)->toBe('pending');
    DB::table('order_fulfillments')->where('id', $d->delivery->order_fulfillment_id)->update(['ready_at' => now()->subMinutes(20)]);
    Cache::flush();

    $items = app(AttentionFeed::class)->for($d->mgr)->items;
    $line = $items->first(fn ($i) => $i->subject === $d->order->number);

    expect($line)->not->toBeNull()->and($line->label)->toBe('Driver has not accepted')->and($line->note)->toBe('No driver accepted it. The system tried 3 drivers one after the other.')
        ->and($line->action['label'])->toBe('Assign driver');
});

it('never offers the job to a driver who did not answer for it already', function () {
    $d = autoWorld(1);
    givenMinutesAgo($d->delivery, 6);
    runWatchdog(); // now with the second driver
    givenMinutesAgo($d->delivery->fresh(), 6);

    runWatchdog();

    // Only the two of them are on duty and both stayed silent: it waits.
    expect($d->delivery->fresh()->status->value)->toBe('pending');
});

it('follows the time in the settings, and zero switches it off', function () {
    $d = autoWorld(1);
    givenMinutesAgo($d->delivery, 6);
    app(Settings::class)->set('dispatch.accept_timeout_min', 10);
    expect(runWatchdog()['released'])->toBe(0);

    givenMinutesAgo($d->delivery, 11);
    app(Settings::class)->set('dispatch.accept_timeout_min', 0);
    expect(runWatchdog()['released'])->toBe(0)->and($d->delivery->fresh()->driver_id)->toBe($d->driver->id);

    app(Settings::class)->set('dispatch.accept_timeout_min', 10);
    expect(runWatchdog()['released'])->toBe(1);
});

it('follows the number of attempts in the settings', function () {
    $d = autoWorld(2);
    app(Settings::class)->set('dispatch.max_auto_attempts', 1);
    givenMinutesAgo($d->delivery, 6);

    $r = runWatchdog();

    expect($r)->toMatchArray(['released' => 1, 'gave_up' => 1, 'reassigned' => 0])->and($d->delivery->fresh()->status->value)->toBe('pending');
});

it('in a branch where a person chooses the driver, takes the job back and tells the managers instead of choosing', function () {
    $d = opsAssignedDelivery();
    $other = driverFor($d->w->a);
    givenMinutesAgo($d->delivery, 7);

    $r = runWatchdog();

    expect($r['released'])->toBe(1)->and($r['reassigned'])->toBe(0)->and($d->delivery->fresh()->status->value)->toBe('pending')
        ->and($other->fresh())->not->toBeNull();
    expect(managerMessages($d)->first()->title)->toBe('No driver took order '.$d->order->number);
});

it('is safe to run twice: one release, one message', function () {
    $d = opsAssignedDelivery();
    givenMinutesAgo($d->delivery, 7);

    runWatchdog();
    $second = runWatchdog();

    expect($second['released'])->toBe(0)->and(managerMessages($d))->toHaveCount(1)->and(DeliveryDecline::count())->toBe(1);
});

it('does not take a job back at the last second when the driver accepted it in between', function () {
    $d = autoWorld(1);
    $stale = $d->delivery->fresh(); // what the scan saw: assigned
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/accept")->assertOk();

    $taken = app(DeliveryService::class)->releaseUnaccepted($stale, 'timeout', now());

    expect($taken)->toBeNull()->and($d->delivery->fresh()->driver_id)->toBe($d->driver->id)->and(DeliveryDecline::count())->toBe(0);
});

it('does not take back a job that was given again since the scan', function () {
    $d = autoWorld(1);
    givenMinutesAgo($d->delivery, 1); // given to this driver a minute ago

    $taken = app(DeliveryService::class)->releaseUnaccepted($d->delivery->fresh(), 'timeout', now()->subMinutes(5));

    expect($taken)->toBeNull()->and($d->delivery->fresh()->driver_id)->toBe($d->driver->id);
});

it('leaves a cancelled job alone', function () {
    $d = autoWorld(1);
    givenMinutesAgo($d->delivery, 9);
    Delivery::whereKey($d->delivery->id)->update(['status' => 'cancelled']);

    expect(runWatchdog()['released'])->toBe(0);
});

// ── A driver who went quiet ───────────────────────────────────────────────────────────────────────────────────────────

function quietSince(object $driver, int $minutes): void
{
    DriverProfile::where('user_id', $driver->id)->update(['on_duty' => true, 'on_duty_since' => now()->subHours(2), 'last_seen_at' => now()->subMinutes($minutes)]);
}

it('sets a driver off duty after twenty minutes without a sign of life, and writes it down', function () {
    $d = opsAssignedDelivery();
    DeliveryDecline::query()->delete();
    Delivery::whereKey($d->delivery->id)->update(['status' => 'cancelled']);
    quietSince($d->driver, 21);

    $r = runWatchdog();

    $p = DriverProfile::find($d->driver->id);
    expect($r['off_duty'])->toBe(1)->and($p->on_duty)->toBeFalse()->and($p->on_duty_since)->toBeNull()
        ->and(AuditLog::where('action', 'driver.auto_off_duty')->count())->toBe(1);
    expect(managerMessages($d))->toHaveCount(0); // nothing was riding on him: no message
});

it('keeps a driver on duty who was heard from within the twenty minutes', function () {
    $d = opsAssignedDelivery();
    quietSince($d->driver, 19);

    expect(runWatchdog()['off_duty'])->toBe(0)->and(DriverProfile::find($d->driver->id)->on_duty)->toBeTrue();
});

it('follows the minutes in the settings', function () {
    $d = opsAssignedDelivery();
    quietSince($d->driver, 15);
    app(Settings::class)->set('dispatch.stale_duty_min', 10);

    expect(runWatchdog()['off_duty'])->toBe(1);
});

it('takes back the jobs a silent driver never accepted, but never touches what he is carrying, and tells the managers', function () {
    $d = autoWorld(1);
    $pushes = opsFakeFcm();
    opsPhone($d->mgr, 'mgr-phone');
    // He holds an accepted job and a picked-up one, and was just given a new one.
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/accept")->assertOk();
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/pickup")->assertOk();
    $other = opsExtraAssigned($d, $d->driver);
    quietSince($d->driver, 25);

    $r = runWatchdog();

    expect($r['off_duty'])->toBe(1)->and($r['quiet_with_jobs'])->toBe(1)
        ->and($d->delivery->fresh()->driver_id)->toBe($d->driver->id)->and($d->delivery->fresh()->status->value)->toBe('picked_up')  // carried: untouched
        ->and($other->fresh()->driver_id)->toBe($d->others[0]->id);                                                                       // not accepted: given on
    $msg = managerMessages($d)->last();
    expect($msg->title)->toContain('went offline')->and($msg->body)->toContain('still has 1 job');
    expect(collect($pushes)->pluck(0)->contains('mgr-phone'))->toBeTrue();
});

it('is safe to run twice', function () {
    $d = opsAssignedDelivery();
    quietSince($d->driver, 30);

    runWatchdog();
    $again = runWatchdog();

    expect($again['off_duty'])->toBe(0)->and(AuditLog::where('action', 'driver.auto_off_duty')->count())->toBe(1);
});

it('does not set him off duty if he switched himself off in the meantime', function () {
    $d = opsAssignedDelivery();
    quietSince($d->driver, 30);
    DriverProfile::where('user_id', $d->driver->id)->update(['on_duty' => false]);

    expect(runWatchdog()['off_duty'])->toBe(0)->and(AuditLog::where('action', 'driver.auto_off_duty')->count())->toBe(0);
});

// ── The command and its place in the schedule ─────────────────────────────────────────────────────────────────────────

it('runs as a command and says what it did', function () {
    $d = autoWorld(1);
    givenMinutesAgo($d->delivery, 6);

    $this->artisan('dispatch:watchdog')->expectsOutputToContain('Took back 1 job(s), gave 1 to another driver')->assertExitCode(0);
});

it('is scheduled every minute, never overlapping, on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'dispatch:watchdog'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->expiresAt)->toBe(10)->and($event->onOneServer)->toBeTrue();
});

/** A second assigned job for the same driver (a fresh order of the same branch). */
function opsExtraAssigned(object $d, $driver): Delivery
{
    $ids = opsOrder($d->w->a, [], ['status' => 'ready', 'type' => 'delivery']);

    return Delivery::findOrFail(opsDelivery($ids['part'], $d->w->a, 'assigned', ['driver_id' => $driver->id, 'assigned_at' => now()->subMinute()]));
}
