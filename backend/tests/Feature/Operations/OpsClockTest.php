<?php

use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Operations\Services\BranchSchedules;
use App\Domain\Operations\Services\OpsClock;
use App\Domain\Operations\Support\OpsAge;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Settings\Services\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

require_once __DIR__.'/OpsSupport.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Thursday 19 Nov 2026. Default hours: every day 10:00-22:00, Friday 14:00-22:00.
});

// ── Business minutes ─────────────────────────────────────────────────────────────────────────────────────────────

it('counts only the minutes the branch was open', function (string $from, string $to, int $counted, int $wall, bool $paused) {
    $branch = opsBranch();
    $m = app(BranchSchedules::class)->minutes($branch->id, qatar($from), qatar($to));
    expect($m)->toBe(['counted' => $counted, 'wall' => $wall, 'paused' => $paused]);
})->with([
    'inside the hours' => ['2026-11-19 10:00', '2026-11-19 10:30', 30, 30, false],
    'before opening: nothing counts' => ['2026-11-19 08:00', '2026-11-19 09:30', 0, 90, true],
    'across the opening time' => ['2026-11-19 09:45', '2026-11-19 10:15', 15, 30, false],
    'placed 23:00, looked at 08:00: the whole night is skipped' => ['2026-11-18 23:00', '2026-11-19 08:00', 0, 540, true],
    'placed 21:50, looked at 10:20 next day' => ['2026-11-18 21:50', '2026-11-19 10:20', 30, 750, false],
    'across a Friday that opens at 14:00' => ['2026-11-19 21:00', '2026-11-20 14:30', 90, 1050, false],
    'across a whole day of hours' => ['2026-11-19 10:00', '2026-11-20 14:00', 720, 1680, false],
    'a start in the future counts as zero' => ['2026-11-19 12:00', '2026-11-19 11:00', 0, 0, false],
]);

it('works in the time zone of Qatar when the times arrive in UTC', function () {
    $branch = opsBranch();
    // 20:30 UTC is 23:30 in Doha; 05:30 UTC is 08:30 the next morning: the shop was shut for all of it.
    $m = app(BranchSchedules::class)->minutes($branch->id, CarbonImmutable::parse('2026-11-18 20:30', 'UTC'), CarbonImmutable::parse('2026-11-19 05:30', 'UTC'));
    expect($m['counted'])->toBe(0)->and($m['wall'])->toBe(540);
    // 06:30 UTC is 09:30 in Doha: still before opening. 08:00 UTC is 11:00: one hour after it opened.
    expect(app(BranchSchedules::class)->minutes($branch->id, CarbonImmutable::parse('2026-11-18 20:30', 'UTC'), CarbonImmutable::parse('2026-11-19 08:00', 'UTC'))['counted'])->toBe(60);
});

it('skips a closed day, a break, a holiday and follows overnight hours', function () {
    $hours = [];
    foreach (range(0, 6) as $dow) {
        $hours[$dow] = ['is_closed' => false, 'opens_at' => '10:00', 'closes_at' => '22:00', 'break_starts_at' => '14:00', 'break_ends_at' => '15:00'];
    }
    $hours[6] = ['is_closed' => true];                                                                 // Saturday closed
    $hours[0] = ['is_closed' => false, 'opens_at' => '18:00', 'closes_at' => '02:00'];                 // Sunday: overnight, into Monday 02:00
    $branch = opsBranch($hours);
    $s = app(BranchSchedules::class);

    // Break 14:00-15:00: 13:30 -> 15:30 counts 30 + 30.
    expect($s->minutes($branch->id, qatar('2026-11-19 13:30'), qatar('2026-11-19 15:30'))['counted'])->toBe(60);
    // Friday 21:00 -> Sunday 19:00: Friday's last hour (60), Saturday closed, Sunday opens at 18:00 (60).
    expect($s->minutes($branch->id, qatar('2026-11-20 21:00'), qatar('2026-11-22 19:00'))['counted'])->toBe(120);
    // Overnight: Sunday's hours run until Monday 02:00, so 01:00 -> 01:30 on Monday counts.
    expect($s->minutes($branch->id, qatar('2026-11-23 01:00'), qatar('2026-11-23 01:30'))['counted'])->toBe(30)
        ->and($s->minutes($branch->id, qatar('2026-11-23 03:00'), qatar('2026-11-23 03:30'))['counted'])->toBe(0);

    // A holiday takes its hours off the clock: closed 10:00-12:00 on Thursday.
    BranchHoliday::create(['branch_id' => $branch->id, 'type' => 'holiday', 'name_en' => 'Event', 'name_ar' => 'حدث', 'starts_at' => qatar('2026-11-19 10:00')->utc(), 'ends_at' => qatar('2026-11-19 12:00')->utc()]);
    app()->forgetScopedInstances();
    $s = app(BranchSchedules::class);
    expect($s->minutes($branch->id, qatar('2026-11-19 09:00'), qatar('2026-11-19 13:00'))['counted'])->toBe(60)
        ->and($s->isOpen($branch->id, qatar('2026-11-19 11:00')))->toBeFalse()
        ->and($s->isOpen($branch->id, qatar('2026-11-19 12:30')))->toBeTrue();
});

it('falls back to plain minutes when a branch has no usable hours', function () {
    $none = opsBranch([]);                                                       // no rows at all
    $shut = opsBranch(array_fill(0, 7, ['is_closed' => true]));                  // every day marked closed
    $s = app(BranchSchedules::class);
    foreach ([$none, $shut] as $b) {
        expect($s->hasSchedule($b->id))->toBeFalse()
            ->and($s->minutes($b->id, qatar('2026-11-18 23:00'), qatar('2026-11-19 08:00')))->toBe(['counted' => 540, 'wall' => 540, 'paused' => false])
            ->and($s->isOpen($b->id, qatar('2026-11-19 03:00')))->toBeTrue();
    }
    expect($s->minutes(null, qatar('2026-11-19 10:00'), qatar('2026-11-19 10:20'))['counted'])->toBe(20)  // no branch at all
        ->and($s->minutes((string) Str::uuid(), qatar('2026-11-19 10:00'), qatar('2026-11-19 10:20'))['counted'])->toBe(20); // unknown branch
});

it('walks only the last fourteen days', function () {
    $branch = opsBranch();
    $m = app(BranchSchedules::class)->minutes($branch->id, qatar('2026-10-01 10:00'), qatar('2026-11-19 12:00'));
    // 14 days back from 12:00 on the 19th is 12:00 on 5 Nov: 13 whole open days of 12 h (Fridays 8 h) + the first and last partial ones; far less than 49 days of hours.
    expect($m['counted'])->toBeLessThan(14 * 12 * 60 + 1)->and($m['counted'])->toBeGreaterThan(10 * 12 * 60)->and($m['wall'])->toBe(49 * 1440 + 120);
});

it('loads the hours of many branches in two queries, not one per row', function () {
    $branches = collect(range(1, 6))->map(fn () => opsBranch());
    $s = app(BranchSchedules::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $s->preload($branches->pluck('id')->all());
    foreach ($branches as $b) {
        $s->minutes($b->id, qatar('2026-11-19 09:00'), qatar('2026-11-19 12:00'));
        $s->isOpen($b->id, qatar('2026-11-19 12:00'));
    }
    expect(DB::getQueryLog())->toHaveCount(3); // branches, their hours, their holidays
    DB::disableQueryLog();
});

// ── The limits ───────────────────────────────────────────────────────────────────────────────────────────────────

it('turns amber and red exactly at the limits of every kind', function (string $kind, int $minutes, string $state) {
    $branch = opsBranch([]); // wall-clock branch: the table below is about the limits, the tests above are about opening hours
    $now = qatar('2026-11-19 12:00');
    $age = app(OpsClock::class)->evaluate($kind, $now->subMinutes($minutes), $branch->id, $now);
    expect($age->state)->toBe($state)->and($age->ageMinutes)->toBe($minutes)->and($age->kind)->toBe($kind);
})->with([
    'new: 4 min' => [OpsClock::PENDING, 4, 'ok'], 'new: 5 min' => [OpsClock::PENDING, 5, 'amber'], 'new: 14 min' => [OpsClock::PENDING, 14, 'amber'], 'new: 15 min' => [OpsClock::PENDING, 15, 'red'],
    'preparing: 44' => [OpsClock::PREPARING, 44, 'ok'], 'preparing: 45' => [OpsClock::PREPARING, 45, 'amber'], 'preparing: 89' => [OpsClock::PREPARING, 89, 'amber'], 'preparing: 90' => [OpsClock::PREPARING, 90, 'red'],
    'ready: 29' => [OpsClock::READY, 29, 'ok'], 'ready: 30' => [OpsClock::READY, 30, 'amber'], 'ready: 59' => [OpsClock::READY, 59, 'amber'], 'ready: 60' => [OpsClock::READY, 60, 'red'],
    'driver: 4' => [OpsClock::DRIVER, 4, 'ok'], 'driver: 5' => [OpsClock::DRIVER, 5, 'amber'], 'driver: 9' => [OpsClock::DRIVER, 9, 'amber'], 'driver: 10' => [OpsClock::DRIVER, 10, 'red'],
    'pickup: 1 d 23 h 59' => [OpsClock::PICKUP, 2879, 'ok'], 'pickup: 2 d' => [OpsClock::PICKUP, 2880, 'amber'], 'pickup: 3 d 23 h 59' => [OpsClock::PICKUP, 5759, 'amber'], 'pickup: 4 d' => [OpsClock::PICKUP, 5760, 'red'],
    'cash: 23 h 59' => [OpsClock::CASH, 1439, 'ok'], 'cash: 24 h' => [OpsClock::CASH, 1440, 'amber'], 'cash: 47 h 59' => [OpsClock::CASH, 2879, 'amber'], 'cash: 48 h' => [OpsClock::CASH, 2880, 'red'],
]);

it('counts opening hours for branch steps but real time for pickups and cash', function () {
    $branch = opsBranch();
    $clock = app(OpsClock::class);
    $placed = qatar('2026-11-18 21:55');
    $now = qatar('2026-11-19 10:05');       // the shop was open 5 + 5 minutes in between, shut for 12 hours
    $step = $clock->evaluate(OpsClock::PENDING, $placed, $branch->id, $now);
    expect($step->ageMinutes)->toBe(10)->and($step->wallMinutes)->toBe(730)->and($step->state)->toBe('amber');
    $pickup = $clock->evaluate(OpsClock::PICKUP, $placed->subDays(2), $branch->id, $now);
    expect($pickup->ageMinutes)->toBe($pickup->wallMinutes)->and($pickup->state)->toBe('amber');
});

it('is not late at 08:00 because the shop was shut all night', function () {
    $branch = opsBranch();
    $clock = app(OpsClock::class);
    $age = $clock->evaluate(OpsClock::PENDING, qatar('2026-11-18 23:00'), $branch->id, qatar('2026-11-19 08:00'));
    expect($age->state)->toBe('ok')->and($age->ageMinutes)->toBe(0)->and($age->paused)->toBeTrue()->and($age->label)->toBe(__('ops_live.age.just_now'));
    // …and it turns amber only after five minutes of opening hours.
    expect($clock->evaluate(OpsClock::PENDING, qatar('2026-11-18 23:00'), $branch->id, qatar('2026-11-19 10:05'))->state)->toBe('amber')
        ->and($clock->evaluate(OpsClock::PENDING, qatar('2026-11-18 23:00'), $branch->id, qatar('2026-11-19 10:04'))->state)->toBe('ok');
});

it('reads the limits from the settings once per request and falls back to the defaults for bad values', function () {
    $settings = app(Settings::class);
    $settings->set('ops_clock.pending_amber_minutes', 10);
    $settings->set('ops_clock.pending_red_minutes', 20);
    $settings->set('ops_clock.preparing_amber_minutes', 'abc');      // not a number: the default (45) is used
    $settings->set('ops_clock.ready_amber_minutes', 50);              // amber above red: red is lifted to it, never before it
    $settings->set('ops_clock.ready_red_minutes', 40);
    $settings->set('ops_clock.cash_amber_hours', 12);
    $clock = app(OpsClock::class);
    expect($clock->limits(OpsClock::PENDING))->toBe(['amber' => 10, 'red' => 20])
        ->and($clock->limits(OpsClock::PREPARING)['amber'])->toBe(45)
        ->and($clock->limits(OpsClock::READY))->toBe(['amber' => 50, 'red' => 50])
        ->and($clock->limits(OpsClock::CASH))->toBe(['amber' => 12 * 60, 'red' => 48 * 60])
        ->and($clock->limits(OpsClock::PICKUP))->toBe(['amber' => 2 * 1440, 'red' => 4 * 1440]);

    // Read once: a change made after the first read is not seen by this request's clock, but is by the next request.
    $settings->set('ops_clock.pending_amber_minutes', 3);
    expect($clock->limits(OpsClock::PENDING)['amber'])->toBe(10);
    app()->forgetScopedInstances();
    expect(app(OpsClock::class)->limits(OpsClock::PENDING)['amber'])->toBe(3);
});

it('writes a wait as short plain text in both languages', function (int $minutes, string $en, string $ar) {
    foreach (['en' => $en, 'ar' => $ar] as $locale => $expected) {
        app()->setLocale($locale);
        expect(OpsClock::label($minutes))->toBe($expected);
    }
})->with([
    [0, 'Just now', 'الآن'], [5, '5 min', '5 د'], [65, '1 h 05 min', '1 س 05 د'], [120, '2 h', '2 س'], [1500, '1 d 1 h', '1 ي 1 س'], [2880, '2 d', '2 ي'],
]);

// ── What a branch part is waiting for ────────────────────────────────────────────────────────────────────────────

function opsPart(string $partId): OrderFulfillment
{
    return OrderFulfillment::with(['order', 'deliveries'])->findOrFail($partId);
}

it('knows what each branch part waits for', function () {
    $branch = opsBranch([]);
    $now = qatar('2026-11-19 12:00');
    $clock = app(OpsClock::class);
    $at = fn (int $minutes) => $now->subMinutes($minutes);
    $kind = fn (array $ids) => $clock->forFulfillment(opsPart($ids['part']), $now);

    // a new order: waits to be accepted; an unpaid online order does not wait for anybody yet
    $new = opsOrder($branch, [], ['status' => 'pending'], $at(7));
    expect($kind($new))->toMatchObject(['kind' => OpsClock::PENDING, 'state' => 'amber', 'ageMinutes' => 7]);
    $unpaid = opsOrder($branch, ['status' => 'pending_payment', 'payment_method' => 'sadad'], ['status' => 'pending'], $at(60));
    expect($kind($unpaid))->toBeNull();
    // …and when it was paid 2 minutes ago, the clock starts then, not when it was placed
    $paid = opsOrder($branch, ['payment_method' => 'sadad', 'confirmed_at' => opsUtc($at(2))], ['status' => 'pending'], $at(60));
    expect($kind($paid))->toMatchObject(['kind' => OpsClock::PENDING, 'state' => 'ok', 'ageMinutes' => 2]);

    // accepted or preparing: waits to be ready (from accepted_at, else from the last change)
    $accepted = opsOrder($branch, [], ['status' => 'accepted', 'accepted_at' => opsUtc($at(50))], $at(60));
    expect($kind($accepted))->toMatchObject(['kind' => OpsClock::PREPARING, 'state' => 'amber']);
    $preparing = opsOrder($branch, [], ['status' => 'preparing', 'updated_at' => opsUtc($at(100))], $at(120));
    expect($kind($preparing))->toMatchObject(['kind' => OpsClock::PREPARING, 'state' => 'red']);

    // ready delivery: waits for a driver; with a driver named it waits for the driver to answer; once accepted nobody waits
    $ready = opsOrder($branch, [], ['status' => 'ready', 'ready_at' => opsUtc($at(35))], $at(120));
    expect($kind($ready))->toMatchObject(['kind' => OpsClock::READY, 'state' => 'amber']);
    $assigned = opsOrder($branch, [], ['status' => 'ready', 'ready_at' => opsUtc($at(35))], $at(120));
    opsDelivery($assigned['part'], $branch, 'assigned', ['assigned_at' => opsUtc($at(11))]);
    expect($kind($assigned))->toMatchObject(['kind' => OpsClock::DRIVER, 'state' => 'red', 'ageMinutes' => 11]);
    $taken = opsOrder($branch, [], ['status' => 'ready', 'ready_at' => opsUtc($at(35))], $at(120));
    opsDelivery($taken['part'], $branch, 'accepted');
    expect($kind($taken))->toBeNull();
    // a pickup is never "ready for a driver"
    $pickReady = opsOrder($branch, ['fulfillment_type' => 'pickup'], ['status' => 'ready', 'type' => 'pickup', 'ready_at' => opsUtc($at(300))], $at(400));
    expect($kind($pickReady))->toBeNull();

    // pickup waiting for the customer: in days
    $pickup = opsOrder($branch, ['fulfillment_type' => 'pickup'], ['status' => 'pickup_ready', 'type' => 'pickup', 'ready_at' => opsUtc($now->subDays(3))], $now->subDays(4));
    expect($kind($pickup))->toMatchObject(['kind' => OpsClock::PICKUP, 'state' => 'amber']);

    // a failed delivery waits for a new plan; one that was rescheduled does not
    $failed = opsOrder($branch, [], ['status' => 'failed'], $at(300));
    opsDelivery($failed['part'], $branch, 'failed', ['failed_at' => opsUtc($at(70))]);
    expect($kind($failed))->toMatchObject(['kind' => OpsClock::READY, 'state' => 'red']);
    $again = opsOrder($branch, [], ['status' => 'failed'], $at(300));
    opsDelivery($again['part'], $branch, 'failed', ['failed_at' => opsUtc($at(70)), 'created_at' => opsUtc($at(200))]);
    opsDelivery($again['part'], $branch, 'rescheduled', ['created_at' => opsUtc($at(10))]);
    expect($kind($again))->toBeNull();

    // finished work is not on the clock
    foreach (['dispatched', 'out_for_delivery', 'delivered', 'cancelled', 'returned', 'picked_up'] as $status) {
        expect($kind(opsOrder($branch, [], ['status' => $status], $at(500))))->toBeNull();
    }
});

it('has a shape and a word for every state, not a colour alone', function () {
    foreach (['ok', 'amber', 'red'] as $state) {
        $html = Blade::render('<x-admin.ops.age-chip :state="$state" label="12 min" />', ['state' => $state]);
        expect($html)->toContain('12 min')->toContain('sr-only')->toContain(__('ops_live.state.'.$state))->toContain('data-age-state="'.$state.'"');
    }
    expect(Blade::render('<x-admin.ops.age-chip state="red" label="3 min" />'))->toContain('<svg')
        ->and(Blade::render('<x-admin.ops.age-chip state="ok" label="3 min" />'))->not->toContain('<svg');
    // an unknown state is shown as calm, never as an error page
    expect(Blade::render('<x-admin.ops.age-chip state="weird" label="3 min" />'))->toContain('data-age-state="ok"');
    expect((new OpsAge('pending', 'red', 20, 20, '20 min', CarbonImmutable::now()))->rank())->toBeGreaterThan((new OpsAge('pending', 'amber', 6, 6, '6 min', CarbonImmutable::now()))->rank());
});
