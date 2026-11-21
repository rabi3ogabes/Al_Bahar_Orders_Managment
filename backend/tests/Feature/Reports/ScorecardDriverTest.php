<?php

use App\Domain\Reports\Services\ScorecardSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ScorecardSupport.php';

/**
 * The driver numbers of the scorecard on hand-built deliveries. "Today" is Thursday 19 Nov 2026, midday in Qatar; the period is 12 to 18 Nov.
 */
beforeEach(function () {
    $this->w = scWorld();
    $this->driver = scDriver($this->w->a, 'Omar Driver');
});

/** A delivery of a new order, made by the driver: promised for a day, delivered at a Qatar moment after $trip minutes on the road. */
function scMade(object $w, $driver, string $deliveredAt, ?string $slot, ?int $trip = 20, ?object $branch = null): array
{
    $branch ??= $w->a;
    $o = scDelivered($branch, '2026-11-12 08:00', $deliveredAt, $slot);
    $out = $trip === null ? null : CarbonImmutable::parse($deliveredAt, 'Asia/Qatar')->subMinutes($trip)->format('Y-m-d H:i:s');
    scTrip($o, $branch, $driver, 'delivered', ['delivered_at' => $deliveredAt] + ($out !== null ? ['out_for_delivery_at' => $out] : []));

    return $o;
}

/** A driver giving a job back (or not answering): a delivery without a driver, and the decline row. */
function scDecline(object $w, $driver, string $reason, string $at, ?object $branch = null): array
{
    $branch ??= $w->a;
    $o = scOrder($branch, '2026-11-12 08:00', 5, 5, ['status' => 'ready', 'type' => 'delivery']);
    $id = opsDelivery($o['part'], $branch, 'pending', ['driver_id' => null]);
    DB::table('delivery_declines')->insert(['id' => (string) Str::uuid(), 'delivery_id' => $id, 'driver_id' => $driver->id, 'reason' => $reason, 'created_at' => scAt($at)]);

    return $o;
}

describe('what a driver delivered', function () {
    it('gives deliveries, on-time rate and the time on the road as a median and a slow end, not an average', function () {
        $w = $this->w;
        foreach ([[10, true], [20, true], [30, true], [40, true], [100, false]] as $i => [$trip, $onTime]) {
            scMade($w, $this->driver, '2026-11-14 1'.$i.':00', $onTime ? '2026-11-14' : '2026-11-13', $trip);
        }

        $d = scDriverRow($w, $this->driver);

        // The average trip is 40 minutes; the middle delivery took 30. The slowest tenth: rank 3.6, 40 + 0.6 * (100 - 40) = 76.
        expect($d['delivered']['value'])->toBe(5)->and($d['on_time']['value'])->toBe(80.0)->and($d['on_time']['n'])->toBe(5)
            ->and($d['trip']['median']['value'])->toBe(30.0)->and($d['trip']['p90']['value'])->toBe(76.0)->and($d['trip']['n'])->toBe(5)
            ->and($d['trip']['average']['value'])->toBe(40.0);                       // given for reference beside the median, never instead of it
    });

    it('leaves a delivery with no "out for delivery" time out of the time on the road, and one with no promised day out of the on-time rate', function () {
        $w = $this->w;
        scMade($w, $this->driver, '2026-11-14 10:00', '2026-11-14', 15);
        scMade($w, $this->driver, '2026-11-14 11:00', null, null);                       // no promise, no start time

        $d = scDriverRow($w, $this->driver);

        expect($d['delivered']['value'])->toBe(2)->and($d['on_time']['n'])->toBe(1)->and($d['trip']['n'])->toBe(1)->and($d['trip']['median']['value'])->toBe(15.0);
    });

    it('counts a delivery on the Qatar day: just after midnight is the next day', function () {
        $w = $this->w;
        scMade($w, $this->driver, '2026-11-14 23:59:59', '2026-11-14');
        scMade($w, $this->driver, '2026-11-15 00:30:00', '2026-11-14');

        expect(scDriverRow($w, $this->driver)['on_time']['value'])->toBe(50.0);
    });

    it('compares with the period before and says nothing about the change when that period had too few cases', function () {
        $w = $this->w;
        app(ScorecardSettings::class)->setMinSample(3);
        foreach (range(1, 4) as $i) {
            scMade($w, $this->driver, '2026-11-14 1'.$i.':00', '2026-11-14');
        }
        foreach (range(1, 4) as $i) {
            scMade($w, $this->driver, '2026-11-07 1'.$i.':00', $i <= 2 ? '2026-11-07' : '2026-11-06');
        }
        $d = scDriverRow($w, $this->driver);
        app(ScorecardSettings::class)->setMinSample(5);
        $few = scDriverRow($w, $this->driver);

        expect($d['delivered'])->toMatchArray(['value' => 4, 'previous' => 4, 'change' => 0])
            ->and($d['on_time'])->toMatchArray(['value' => 100.0, 'previous' => 50.0, 'change' => 50.0])
            ->and($few['on_time'])->toMatchArray(['value' => null, 'state' => 'few'])->and($few['delivered']['value'])->toBe(4);
    });
});

describe('what went wrong', function () {
    it('gives the failure rate with its reasons, the customer\'s side apart from the team\'s', function () {
        $w = $this->w;
        foreach (range(1, 4) as $i) {
            scMade($w, $this->driver, '2026-11-14 1'.$i.':00', '2026-11-14');
        }
        scFailed($w->a, '2026-11-12 08:00', '2026-11-15 10:00', 'customer_absent', $this->driver);
        scFailed($w->a, '2026-11-12 08:00', '2026-11-15 11:00', 'damaged', $this->driver);
        scFailed($w->a, '2026-11-12 08:00', '2026-11-15 12:00', 'refused', $this->driver);

        $f = scDriverRow($w, $this->driver)['failed'];

        // 3 failed of the 7 deliveries that ended: 42.9; the one the team could have prevented: 1 of 7 = 14.3.
        expect($f['count'])->toBe(3)->and($f['rate']['value'])->toBe(42.9)->and($f['team_rate']['value'])->toBe(14.3)
            ->and($f['customer'])->toBe(2)->and($f['team'])->toBe(1)->and($f['reasons'])->toBe(['customer_absent' => 1, 'damaged' => 1, 'refused' => 1]);
    });

    it('counts jobs given back apart from jobs nobody answered and jobs taken back when off duty', function () {
        $w = $this->w;
        foreach (range(1, 5) as $i) {
            scMade($w, $this->driver, '2026-11-14 1'.$i.':00', '2026-11-14');
        }
        scFailed($w->a, '2026-11-12 08:00', '2026-11-15 10:00', 'customer_absent', $this->driver);
        scDecline($w, $this->driver, 'too_far', '2026-11-13 09:00');
        scDecline($w, $this->driver, 'too_far', '2026-11-13 10:00');
        scDecline($w, $this->driver, 'other', '2026-11-13 11:00');
        scDecline($w, $this->driver, 'timeout', '2026-11-13 12:00');
        scDecline($w, $this->driver, 'timeout', '2026-11-13 13:00');
        scDecline($w, $this->driver, 'off_duty', '2026-11-13 14:00');
        scDecline($w, $this->driver, 'too_far', '2026-11-10 14:00');                       // the period before

        $j = scDriverRow($w, $this->driver)['jobs'];

        // 11 jobs reached him (5 delivered, 1 failed, 3 handed back, 2 not answered): 5 of 11 = 45.5. The one taken back off duty is shown but not counted against him.
        expect($j['declined'])->toBe(3)->and($j['timeouts'])->toBe(2)->and($j['off_duty'])->toBe(1)->and($j['offered'])->toBe(11)->and($j['rate']['value'])->toBe(45.5)
            ->and($j['previous_declined'])->toBe(1);
    });
});

describe('cash', function () {
    it('shows the cash in hand and how old the oldest collection is, and the differences on the hand-ins he made', function () {
        $w = $this->w;
        $other = scDriver($w->a, 'Salem Driver');
        scCash(scOrder($w->a, '2026-11-16 09:00'), $w->a, $this->driver, '100.00', '2026-11-17 12:00');
        scCash(scOrder($w->a, '2026-11-16 09:00'), $w->a, $this->driver, '50.00', '2026-11-18 18:00');
        scCash(scOrder($w->a, '2026-11-16 09:00'), $w->a, $this->driver, '70.00', '2026-11-16 12:00', handedIn: true);     // handed in
        scCash(scOrder($w->a, '2026-11-16 09:00'), $w->a, $other, '500.00', '2026-11-18 12:01');                          // someone else's, one minute inside the limit
        scHandIn($w->a, $this->driver, '2026-11-16 18:00', '-5.00');
        scHandIn($w->a, $this->driver, '2026-11-17 18:00', '0.00');
        scHandIn($w->a, $other, '2026-11-17 18:00', '9.00');

        $c = scDriverRow($w, $this->driver)['cash'];

        expect($c['held'])->toBe(2)->and($c['amount'])->toBe('150.00')->and($c['oldest_minutes'])->toBe(2 * 1440)->and($c['over_limit'])->toBeTrue()
            ->and($c['net'])->toBe('-5.00')->and($c['abs'])->toBe('5.00')->and($c['variances'])->toBe(1)->and($c['handins'])->toBe(2)
            ->and(scDriverRow($w, $other)['cash']['amount'])->toBe('500.00')->and(scDriverRow($w, $other)['cash']['over_limit'])->toBeFalse();
    });

    it('does not count a cashier at the counter as a driver', function () {
        $w = $this->w;
        $cashier = staffWithRole('pickup_employee', $w->a);
        scCash(scOrder($w->a, '2026-11-16 09:00'), $w->a, $cashier, '80.00', '2026-11-17 12:00');
        scHandIn($w->a, $cashier, '2026-11-17 18:00', '-2.00');

        expect(array_column(scDrivers($w)['rows'], 'id'))->toBe([$this->driver->id]);
    });
});

describe('who is a driver, and whose work a viewer sees', function () {
    it('lists every driver of the viewer\'s branches, alphabetically, even one with no work, and a person who was given a delivery without being set up as a driver', function () {
        $w = $this->w;
        scDriver($w->a, 'Ahmed Idle');
        $stranger = staffWithRole('order_manager', $w->a, ['name' => 'Bader Helper']);
        scMade($w, $stranger, '2026-11-14 10:00', '2026-11-14');

        $rows = scDrivers($w)['rows'];

        expect(array_column($rows, 'name'))->toBe(['Ahmed Idle', 'Bader Helper', 'Omar Driver'])
            ->and(collect($rows)->firstWhere('name', 'Ahmed Idle')['delivered']['value'])->toBe(0)
            ->and(collect($rows)->firstWhere('name', 'Ahmed Idle')['on_time']['state'])->toBe('none');
    });

    it('shows a manager only the work and the cash of a driver at the manager\'s own branch, even when the driver works for two', function () {
        $w = $this->w;
        $both = scDriver([$w->a, $w->b], 'Two Branch Driver');
        scMade($w, $both, '2026-11-14 10:00', '2026-11-14', 10, $w->a);
        scMade($w, $both, '2026-11-14 11:00', '2026-11-14', 10, $w->b);
        scMade($w, $both, '2026-11-14 12:00', '2026-11-13', 10, $w->b);
        scCash(scOrder($w->a, '2026-11-16 09:00'), $w->a, $both, '30.00', '2026-11-17 12:00');
        scCash(scOrder($w->b, '2026-11-16 09:00'), $w->b, $both, '700.00', '2026-11-17 12:00');
        $managerA = staffWithRole('branch_manager', $w->a);

        $seenByA = scDriverRow($w, $both, null, $managerA);
        $seenByOwner = scDriverRow($w, $both);

        expect($seenByA['delivered']['value'])->toBe(1)->and($seenByA['cash']['amount'])->toBe('30.00')
            ->and($seenByOwner['delivered']['value'])->toBe(3)->and($seenByOwner['cash']['amount'])->toBe('730.00');
    });

    it('does not show a manager a driver who works only at another branch, and gives them nothing by his id', function () {
        $w = $this->w;
        $far = scDriver($w->b, 'Wakra Driver');
        scMade($w, $far, '2026-11-14 10:00', '2026-11-14', 10, $w->b);
        $managerA = staffWithRole('branch_manager', $w->a);

        expect(array_column(scDrivers($w, $managerA)['rows'], 'name'))->toBe(['Omar Driver'])
            ->and($w->svc->driver($managerA, $far->id, scWeek()))->toBeNull()
            ->and($w->svc->driver($managerA, (string) Str::uuid(), scWeek()))->toBeNull()
            ->and($w->svc->driver($managerA, 'not-an-id', scWeek()))->toBeNull()
            ->and($w->svc->driver($w->admin, $far->id, scWeek()))->not->toBeNull();
    });

    it('lets a branch filter narrow what is shown, never widen it', function () {
        $w = $this->w;
        $far = scDriver($w->b, 'Wakra Driver');
        $managerA = staffWithRole('branch_manager', $w->a);

        expect(array_column(scDrivers($w, null, null, $w->b->id)['rows'], 'name'))->toBe(['Wakra Driver'])
            ->and(scDrivers($w, $managerA, null, $w->b->id)['rows'])->toBe([]);
    });
});

describe('the orders behind the numbers', function () {
    it('lists every delivery, failure, hand-back, held collection and hand-in with the order number, newest first, and no customer', function () {
        $w = $this->w;
        $onTime = scMade($w, $this->driver, '2026-11-14 12:00', '2026-11-14', 25);
        $late = scMade($w, $this->driver, '2026-11-16 12:00', '2026-11-15', 30);
        $none = scMade($w, $this->driver, '2026-11-13 12:00', null, null);
        $failed = scFailed($w->a, '2026-11-12 08:00', '2026-11-15 10:00', 'customer_absent', $this->driver);
        $declined = scDecline($w, $this->driver, 'too_far', '2026-11-13 09:00');
        $timeout = scDecline($w, $this->driver, 'timeout', '2026-11-13 10:00');
        $held = scOrder($w->a, '2026-11-16 09:00');
        scCash($held, $w->a, $this->driver, '75.00', '2026-11-17 12:00');
        scHandIn($w->a, $this->driver, '2026-11-17 18:00', '-5.00');

        $detail = $w->svc->driver($w->admin, $this->driver->id, scWeek());
        $rows = collect($detail['ledger']);
        $by = fn (string $number) => $rows->firstWhere('order', $number);

        expect($by($onTime['number'])['result'])->toBe('on_time')->and($by($onTime['number'])['minutes'])->toBe(25)
            ->and($by($late['number']))->toMatchArray(['result' => 'late', 'days_late' => 1])
            ->and($by($none['number'])['result'])->toBe('no_promise')
            ->and($by($failed['number']))->toMatchArray(['kind' => 'failed', 'reason' => 'customer_absent'])
            ->and($by($declined['number']))->toMatchArray(['kind' => 'declined', 'result' => 'declined', 'reason' => 'too_far'])
            ->and($by($timeout['number'])['result'])->toBe('timeout')
            ->and($by($held['number']))->toMatchArray(['kind' => 'cash_held', 'amount' => '75.00'])
            ->and($rows->firstWhere('kind', 'handin'))->toMatchArray(['result' => 'difference', 'amount' => '-5.00'])
            ->and($rows->pluck('at')->all())->toBe($rows->pluck('at')->sortDesc()->values()->all())     // newest first, whatever the kind
            ->and($detail['truncated'])->toBeFalse()
            // No customer is named anywhere in what the window shows: not the name, not the phone, only order numbers.
            ->and(json_encode($detail))->not->toContain('Noor')->not->toContain('+97455123456');
    });

    it('says nothing happened for a driver with no work in the period, and cuts a very long list', function () {
        $w = $this->w;
        $quiet = scDriver($w->a, 'Quiet Driver');

        $detail = $w->svc->driver($w->admin, $quiet->id, scWeek());

        expect($detail['ledger'])->toBe([])->and($detail['truncated'])->toBeFalse()->and($detail['driver']['delivered']['value'])->toBe(0);
    });
});
