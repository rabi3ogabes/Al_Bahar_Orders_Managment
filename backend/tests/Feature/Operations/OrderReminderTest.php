<?php

use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Operations\Services\OrderAlerts;
use App\Domain\Settings\Services\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;

require_once __DIR__.'/OpsSupport.php';
require_once __DIR__.'/../Commerce/LiveOrderHelpers.php';

/** orders:remind-unaccepted: one reminder per threshold crossed, to the managers and the order team, quiet while the shop is shut. */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    lopFcm();
    $this->travelTo(qatar('2026-11-19 12:00'));          // Thursday noon: open
    $this->a = opsBranch();
    $this->b = opsBranch();
    $this->rules = app(NotificationRules::class);
    $this->mgr = opsStaff('branch_manager', $this->a);
    $this->desk = opsStaff('order_manager', $this->a);
    $this->sales = opsStaffWith($this->a, ['orders.view']);                       // can see, cannot accept
    $this->driver = opsStaffWith($this->a, ['deliveries.view', 'deliveries.edit']);
    $this->other = opsStaff('branch_manager', $this->b);
    foreach (['mgr', 'desk', 'sales', 'driver', 'other'] as $who) {
        UserDevice::create(['user_id' => $this->{$who}->id, 'platform' => 'android', 'push_token' => 'tok-'.$who, 'locale' => 'en']);
    }
});

function opsReminded(string $event = 'staff.order_reminder'): array
{
    return collect(lopSent(all: true))->filter(fn (array $m) => ($m['data']['event'] ?? null) === $event)->pluck('token')->sort()->values()->all();
}

function opsRun(): array
{
    return app(OrderAlerts::class)->remindUnaccepted();
}

it('reminds the managers and the order team once when a new order turns amber, and again once when it turns red', function () {
    $o = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(6));                  // amber: waiting 6 min (limit 5)
    expect(opsRun())->toBe(['checked' => 1, 'reminded' => 1, 'people' => 2])
        ->and(opsReminded())->toBe(['tok-desk', 'tok-mgr']);                          // orders.approve (manager) + orders.edit (desk); not the viewer, the driver or another branch
    $entry = Notification::where('user_id', $this->desk->id)->where('event', 'staff.order_reminder')->firstOrFail();
    expect($entry->title)->toBe('Order '.$o['number'].' is waiting')->and($entry->body)->toBe('Waiting to be accepted for 6 min. Please accept it or call the customer.');

    expect(opsRun()['reminded'])->toBe(0)->and(count(opsReminded()))->toBe(2);        // the next minute: nothing new for the same crossing

    $this->travel(10)->minutes();                                                      // 16 min: red (limit 15)
    expect(opsRun()['reminded'])->toBe(1)->and(opsReminded())->toBe(['tok-desk', 'tok-desk', 'tok-mgr', 'tok-mgr']);
    $red = Notification::where('user_id', $this->mgr->id)->where('event', 'staff.order_reminder')->latest('created_at')->get()->last();
    expect(DB::table('ops_reminders')->where('fulfillment_id', $o['part'])->pluck('level')->sort()->values()->all())->toBe(['amber', 'red']);

    $this->travel(30)->minutes();
    expect(opsRun()['reminded'])->toBe(0);                                             // two reminders at most per part
});

it('sends only the red reminder to a part that is already red, and never the amber one afterwards', function () {
    $o = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(20));
    expect(opsRun()['reminded'])->toBe(1);
    $texts = Notification::where('event', 'staff.order_reminder')->pluck('title')->unique()->all();
    expect($texts)->toBe(['Order '.$o['number'].' is late'])->and(DB::table('ops_reminders')->where('fulfillment_id', $o['part'])->pluck('level')->sort()->values()->all())->toBe(['amber', 'red']);
    expect(opsRun()['reminded'])->toBe(0);
});

it('does not remind about an order that is accepted in time, not late yet, unpaid, cancelled or finished', function () {
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(3));                                                                        // not amber yet
    opsOrder($this->a, [], ['status' => 'accepted', 'accepted_at' => opsUtc(opsAgo(2))], opsAgo(30));                                  // accepted
    opsOrder($this->a, ['status' => 'pending_payment', 'payment_method' => 'sadad'], ['status' => 'pending'], opsAgo(60));             // unpaid online order
    opsOrder($this->a, [], ['status' => 'cancelled'], opsAgo(60));
    opsOrder($this->a, [], ['status' => 'delivered'], opsAgo(60));
    expect(opsRun())->toMatchArray(['reminded' => 0])->and(opsReminded())->toBe([])->and(Notification::where('event', 'staff.order_reminder')->count())->toBe(0);
});

it('starts the wait of an online order when it was paid, not when it was placed', function () {
    opsOrder($this->a, ['payment_method' => 'sadad', 'confirmed_at' => opsUtc(opsAgo(2))], ['status' => 'pending'], opsAgo(120));
    expect(opsRun()['reminded'])->toBe(0);
    $this->travel(4)->minutes();
    expect(opsRun()['reminded'])->toBe(1);
});

it('stays quiet while the branch is closed and counts only opening hours', function () {
    // Placed at 21:55, shop closes at 22:00. At 23:30 it has waited 5 shop minutes: amber, but nobody is woken for it.
    opsOrder($this->a, [], ['status' => 'pending'], qatar('2026-11-19 21:55'));
    $this->travelTo(qatar('2026-11-19 23:30'));
    expect(opsRun())->toBe(['checked' => 1, 'reminded' => 0, 'people' => 0])->and(opsReminded())->toBe([]);
    // The shop opens at 10:00 and the order has already used 5 minutes of the first day: it is amber the moment the shop is open again.
    $this->travelTo(qatar('2026-11-20 14:01'));                                        // Friday opens at 14:00
    expect(opsRun()['reminded'])->toBe(1);
    // At 08:00, before opening, an order placed at 23:00 is simply not late and is not reminded.
    $this->travelTo(qatar('2026-11-23 08:00'));
    opsOrder($this->a, [], ['status' => 'pending'], qatar('2026-11-22 23:00'));
    expect(opsRun()['reminded'])->toBe(0);
});

it('reminds only the team of the branch that has the order', function () {
    opsOrder($this->b, [], ['status' => 'pending'], opsAgo(9));
    expect(opsRun()['reminded'])->toBe(1)->and(opsReminded())->toBe(['tok-other']);
});

it('follows the admin\'s controls for the reminder, and a switched-off reminder is not sent late', function () {
    $this->rules->set('staff.order_reminder', true, ['push' => false]);
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(6));
    opsRun();
    expect(opsReminded())->toBe([])->and(Notification::where('event', 'staff.order_reminder')->count())->toBe(2);
    $this->rules->set('staff.order_reminder', false);
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(7));
    opsRun();
    expect(Notification::where('event', 'staff.order_reminder')->count())->toBe(2);
    $this->rules->reset('staff.order_reminder');
    expect(opsRun()['reminded'])->toBe(0);                                              // that crossing was used; the next one (red) will be sent
    $this->travel(10)->minutes();
    expect(opsRun()['reminded'])->toBeGreaterThan(0);
});

it('is safe when two runs overlap: only the one that claimed the reminder sends it', function () {
    $o = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(6));
    DB::table('ops_reminders')->insert(['fulfillment_id' => $o['part'], 'level' => 'amber', 'sent_at' => now()]);   // the other run got there first
    expect(opsRun()['reminded'])->toBe(0)->and(Notification::where('event', 'staff.order_reminder')->count())->toBe(0);
});

it('has a command that reports what it did and is registered nowhere else', function () {
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(6));
    $this->artisan('orders:remind-unaccepted')->expectsOutputToContain('Checked 1 waiting order part(s); sent 1 reminder(s) to 2 person(s).')->assertExitCode(0);
    $this->artisan('orders:remind-unaccepted')->expectsOutputToContain('sent 0 reminder(s)')->assertExitCode(0);
    expect(collect(Schedule::events())->filter(fn ($e) => str_contains((string) $e->command, 'orders:remind-unaccepted'))->count())->toBeLessThanOrEqual(1);
});

it('uses the limits typed in the settings', function () {
    app(Settings::class)->set('ops_clock.pending_amber_minutes', 20);
    app(Settings::class)->set('ops_clock.pending_red_minutes', 40);
    app()->forgetScopedInstances();
    opsOrder($this->a, [], ['status' => 'pending'], opsAgo(10));
    expect(opsRun()['reminded'])->toBe(0);
    $this->travel(11)->minutes();
    expect(opsRun()['reminded'])->toBe(1);
});

it('keeps going when one reminder cannot be sent, and sends it on the next run', function () {
    $first = opsOrder($this->a, [], ['status' => 'pending'], opsAgo(8));
    $second = opsOrder($this->b, [], ['status' => 'pending'], opsAgo(9));
    $calls = 0;
    Event::listen('eloquent.creating: '.Notification::class, function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('inbox down');
        }
    });
    $report = opsRun();
    expect($report['checked'])->toBe(2)->and($report['reminded'])->toBe(1);                    // one part failed, the other was reminded
    expect(DB::table('ops_reminders')->count())->toBe(1);                                      // the failed one gave its claim back
    Event::forget('eloquent.creating: '.Notification::class);
    expect(opsRun()['reminded'])->toBe(1)->and(DB::table('ops_reminders')->count())->toBe(2);
});
