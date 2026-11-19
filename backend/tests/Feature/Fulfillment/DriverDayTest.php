<?php

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Fulfillment\Models\DriverProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Orders/OpsFixtures.php';
require_once __DIR__.'/../Operations/OpsSupport.php';

/** "My day": what the driver did today, the cash he still holds and how late it is. His own sign-in is all it needs. */
const DAY_URL = '/api/v1/admin/dispatch/driver/day';

/** An old job of the driver (an order of branch A that is already in some state). */
function dayJob(object $d, string $status, array $times = [], array $order = []): string
{
    $ids = opsOrder($d->w->a, $order, ['status' => 'delivered', 'type' => 'delivery']);

    return opsDelivery($ids['part'], $d->w->a, $status, ['driver_id' => $d->driver->id, 'cod_amount' => '50.00', 'assigned_at' => now()->subHours(3)] + $times);
}

function dayCash(object $d, string $amount, CarbonInterface $at, array $changes = []): string
{
    $ids = opsOrder($d->w->a);
    $id = (string) Str::uuid();
    DB::table('cash_collections')->insert(array_merge(['id' => $id, 'payment_id' => opsPayment($ids['order']), 'branch_id' => $d->w->a->id, 'collected_by' => $d->driver->id, 'context' => 'cod', 'tender' => 'cash',
        'amount_due' => $amount, 'amount_collected' => $amount, 'status' => 'collected', 'collected_at' => opsUtc($at), 'created_at' => opsUtc($at), 'updated_at' => opsUtc($at)], $changes));

    return $id;
}

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 15:00'));
    $this->d = opsAssignedDelivery();
    actingAsStaff($this->d->driver);
});

it('counts what he did today: done, failed, declined and still active', function () {
    $d = $this->d; // one assigned job: active
    dayJob($d, 'delivered', ['delivered_at' => now()->subHour()]);
    dayJob($d, 'delivered', ['delivered_at' => now()->subHours(2)]);
    dayJob($d, 'delivered', ['delivered_at' => qatar('2026-11-18 20:00')->utc()]);          // yesterday: not today
    dayJob($d, 'failed', ['failed_at' => now()->subMinutes(30)]);
    dayJob($d, 'returned', ['failed_at' => now()->subHours(1), 'returned_at' => now()]);      // failed today and handed back: still a failed job
    dayJob($d, 'accepted');
    $declined = dayJob($d, 'pending', ['driver_id' => null]);
    DeliveryDecline::create(['delivery_id' => $declined, 'driver_id' => $d->driver->id, 'reason' => 'too_far']);
    $timedOut = dayJob($d, 'pending', ['driver_id' => null]);
    DeliveryDecline::create(['delivery_id' => $timedOut, 'driver_id' => $d->driver->id, 'reason' => 'timeout']);

    $day = $this->getJson(DAY_URL)->assertOk()->json('data');

    expect($day['jobs'])->toBe(['done' => 2, 'failed' => 2, 'declined' => 1, 'active' => 3])->and($day['date'])->toBe('2026-11-19');
});

it('lists the day\'s jobs with their state, newest first, and the ones he handed back as declined', function () {
    $d = $this->d;
    $done = dayJob($d, 'delivered', ['delivered_at' => now()->subHours(2)], ['customer_name' => 'Hamad Al Kuwari']);
    $declined = dayJob($d, 'pending', ['driver_id' => null]);
    DeliveryDecline::create(['delivery_id' => $declined, 'driver_id' => $d->driver->id, 'reason' => 'vehicle']);
    dayJob($d, 'delivered', ['delivered_at' => qatar('2026-11-17 10:00')->utc()]);

    $list = collect($this->getJson(DAY_URL)->assertOk()->json('data.list'));

    expect($list->pluck('id')->all())->toContain($done, $declined, $d->delivery->id)->and($list)->toHaveCount(3);
    $row = $list->firstWhere('id', $done);
    expect($row)->toHaveKeys(['order_number', 'status', 'customer_name', 'address', 'cod_amount', 'at'])->and($row['status'])->toBe('delivered')
        ->and($list->firstWhere('id', $declined)['status'])->toBe('declined')->and($list->firstWhere('id', $d->delivery->id)['status'])->toBe('assigned');
});

it('shows the cash collected today and the cash he still holds, with the oldest collection', function () {
    $d = $this->d;
    dayCash($d, '250.00', now()->subHours(2));
    dayCash($d, '100.00', now()->subHour());
    dayCash($d, '80.00', qatar('2026-11-18 21:00')->utc());                                       // yesterday: held, not collected today
    dayCash($d, '999.00', now()->subHour(), ['handed_in_at' => now()->subMinutes(10)]);           // collected today, but already handed in: not in his pocket
    $oldest = qatar('2026-11-18 21:00')->utc()->toIso8601String();

    $cash = $this->getJson(DAY_URL)->assertOk()->json('data.cash');

    expect($cash['currency'])->toBe('QAR')->and($cash['collected_today'])->toBe('1349.00')->and($cash['held'])->toBe('430.00')->and($cash['held_count'])->toBe(3)
        ->and(CarbonImmutable::parse($cash['oldest_at'])->utc()->toIso8601String())->toBe($oldest)->and($cash['state'])->toBe('ok');
});

it('says the cash is getting late, then late, by the order clock\'s cash limits', function (int $hours, string $state) {
    dayCash($this->d, '100.00', now()->subHours($hours));

    expect($this->getJson(DAY_URL)->assertOk()->json('data.cash.state'))->toBe($state);
})->with([[2, 'ok'], [23, 'ok'], [25, 'amber'], [47, 'amber'], [49, 'red']]);

it('says there is nothing to hand in when he holds no cash', function () {
    $cash = $this->getJson(DAY_URL)->assertOk()->json('data.cash');

    expect($cash['held'])->toBe('0.00')->and($cash['held_count'])->toBe(0)->and($cash['oldest_at'])->toBeNull()->and($cash['state'])->toBe('ok')->and($cash['collected_today'])->toBe('0.00');
});

it('never counts card payments as cash in his pocket', function () {
    dayCash($this->d, '100.00', now()->subHours(30), ['tender' => 'card_terminal']);

    expect($this->getJson(DAY_URL)->assertOk()->json('data.cash.held'))->toBe('0.00');
});

it('tells when he went on duty, and nothing when he is off', function () {
    DriverProfile::where('user_id', $this->d->driver->id)->update(['on_duty' => true, 'on_duty_since' => qatar('2026-11-19 07:45')->utc()]);

    $day = $this->getJson(DAY_URL)->assertOk()->json('data');
    expect($day['on_duty'])->toBeTrue()->and($day['on_duty_since'])->toBe('2026-11-19T07:45:00+03:00');

    DriverProfile::where('user_id', $this->d->driver->id)->update(['on_duty' => false, 'on_duty_since' => null]);
    $off = $this->getJson(DAY_URL)->assertOk()->json('data');
    expect($off['on_duty'])->toBeFalse()->and($off['on_duty_since'])->toBeNull();
});

it('is quiet and friendly on a day with nothing yet', function () {
    $d = $this->d;
    Delivery::whereKey($d->delivery->id)->update(['driver_id' => null, 'status' => 'pending']);

    $day = $this->getJson(DAY_URL)->assertOk()->json('data');

    expect($day['jobs'])->toBe(['done' => 0, 'failed' => 0, 'declined' => 0, 'active' => 0])->and($day['list'])->toBe([]);
});

it('needs only his own sign-in: no payments right', function () {
    $permissions = DB::table('permissions')->join('role_permissions', 'permissions.id', '=', 'role_permissions.permission_id')
        ->join('user_roles', 'role_permissions.role_id', '=', 'user_roles.role_id')->where('user_roles.user_id', $this->d->driver->id)->pluck('permissions.key');

    expect($permissions)->not->toContain('payments.view');
    $this->getJson(DAY_URL)->assertOk();
});

it('shows nobody else\'s day', function () {
    $d = $this->d;
    $other = driverFor($d->w->a);
    dayJob($d, 'delivered', ['delivered_at' => now()->subHour()]);
    dayCash($d, '300.00', now()->subHour());

    actingAsStaff($other);
    $day = $this->getJson(DAY_URL)->assertOk()->json('data');

    expect($day['jobs'])->toBe(['done' => 0, 'failed' => 0, 'declined' => 0, 'active' => 0])->and($day['cash']['held'])->toBe('0.00')->and($day['list'])->toBe([]);
});

it('is for drivers only', function () {
    actingAsStaff($this->d->mgr);

    $this->getJson(DAY_URL)->assertForbidden()->assertJsonPath('code', 'driver_not_eligible');
});
