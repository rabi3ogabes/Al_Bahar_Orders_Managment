<?php

use App\Domain\Identity\Models\User;
use App\Domain\Operations\Services\BranchSchedules;
use App\Domain\Reports\Services\DailyCloseNumbers;
use App\Domain\Reports\Services\OwnerPackData;
use App\Domain\Reports\Services\ScorecardBranchNumbers;
use App\Domain\Reports\Services\ScorecardPeriod;
use App\Domain\Reports\Services\ScorecardService;
use App\Domain\Reports\Services\ScorecardSettings;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ScorecardSupport.php';

/**
 * The branch numbers of the scorecard on hand-built orders: every expected figure below is worked out by hand in the comment next to it. "Today" is
 * Thursday 19 Nov 2026, midday in Qatar; the period is the week of 12 to 18 Nov and the one before it 5 to 11 Nov.
 */
beforeEach(function () {
    $this->w = scWorld();
});

describe('orders handled', function () {
    it('counts the orders placed in the period at this branch, and the period before, and nothing else', function () {
        $w = $this->w;
        foreach (['2026-11-12 00:00', '2026-11-15 10:00', '2026-11-18 23:59'] as $at) {
            scOrder($w->a, $at);                                                                     // three in the period (both edges included)
        }
        scOrder($w->a, '2026-11-11 23:59');                                                          // the evening before the period: the period before
        scOrder($w->a, '2026-11-19 09:00');                                                          // today: neither period
        scOrder($w->b, '2026-11-15 10:00');                                                          // another branch
        scOrder($w->a, '2026-11-15 11:00', null, null, [], ['status' => 'pending_payment', 'payment_method' => 'sadad']);          // waiting for online payment: not an order
        scOrder($w->a, '2026-11-15 12:00', null, null, [], ['status' => 'cancelled', 'payment_method' => 'sadad', 'confirmed_at' => null]); // an online order that expired unpaid

        $a = scBranch($w, $w->a);

        expect($a['orders'])->toBe(['value' => 3, 'previous' => 1, 'change' => 2, 'change_percent' => 200.0])
            ->and(scBranch($w, $w->b)['orders']['value'])->toBe(1);
    });

    it('is zero, with every other figure saying there is nothing to measure, for a branch that had nothing', function () {
        $a = scBranch($this->w, $this->w->a);

        expect($a['orders']['value'])->toBe(0)->and($a['orders']['change_percent'])->toBeNull()
            ->and($a['accept']['median']['state'])->toBe('none')->and($a['accept']['median']['value'])->toBeNull()
            ->and($a['delivery']['on_time']['state'])->toBe('none')->and($a['failed']['rate']['state'])->toBe('none')->and($a['cancel']['rate']['state'])->toBe('none')
            ->and($a['cash'])->toMatchArray(['net' => '0.00', 'abs' => '0.00', 'variances' => 0, 'handins' => 0, 'open_variances' => 0, 'held' => 0, 'late' => 0]);
    });

    it('lists every branch the viewer may see, and all of them together as one line made from the sums', function () {
        $w = $this->w;
        scOrder($w->a, '2026-11-13 10:00', 10, 20);
        scOrder($w->b, '2026-11-13 10:00', 30, 40);
        scOrder($w->b, '2026-11-14 10:00', 50, 60);
        $all = scBranches($w);

        expect(array_column($all['rows'], 'code'))->toBe(['DOH', 'WKR'])
            ->and($all['total']['orders']['value'])->toBe(3)
            ->and($all['total']['accept']['median']['value'])->toBe(30.0)          // the median of 10, 30, 50 over all the orders, not an average of the branches' medians
            ->and($all['total']['accept']['n'])->toBe(3);
    });
});

describe('time to accept and to prepare', function () {
    it('gives the median and the slow end, which one odd order cannot drag along like an average', function () {
        $w = $this->w;
        foreach ([1, 2, 3, 4, 100] as $i => $minutes) {
            scOrder($w->a, '2026-11-13 1'.$i.':00', $minutes, 10);
        }

        $a = scBranch($w, $w->a);

        // The average would be 22 minutes; the middle order took 3. The slowest tenth: rank 3.6, 4 + 0.6 * (100 - 4) = 61.6.
        expect($a['accept']['median']['value'])->toBe(3.0)->and($a['accept']['p90']['value'])->toBe(61.6)->and($a['accept']['n'])->toBe(5)
            ->and($a['prepare']['median']['value'])->toBe(10.0)->and($a['prepare']['p90']['value'])->toBe(10.0);
    });

    it('is the same number for ties, and for one single order', function () {
        $w = $this->w;
        foreach (['10:00', '11:00', '12:00', '13:00'] as $t) {
            scOrder($w->a, '2026-11-13 '.$t, 7, 7);
        }
        scOrder($w->b, '2026-11-13 10:00', 9, 21);

        expect(scBranch($w, $w->a)['accept'])->toMatchArray(['n' => 4])
            ->and(scBranch($w, $w->a)['accept']['median']['value'])->toBe(7.0)->and(scBranch($w, $w->a)['accept']['p90']['value'])->toBe(7.0)
            ->and(scBranch($w, $w->b)['accept']['median']['value'])->toBe(9.0)->and(scBranch($w, $w->b)['accept']['p90']['value'])->toBe(9.0)
            ->and(scBranch($w, $w->b)['prepare']['median']['value'])->toBe(21.0);
    });

    it('leaves out an order nobody accepted and an order that is not ready yet, and counts the rest', function () {
        $w = $this->w;
        scOrder($w->a, '2026-11-13 10:00');                                                           // never accepted
        scOrder($w->a, '2026-11-13 11:00', 10);                                                       // accepted, not ready
        scOrder($w->a, '2026-11-13 12:00', 20, 30);                                                   // accepted and ready

        $a = scBranch($w, $w->a);

        expect($a['accept']['n'])->toBe(2)->and($a['accept']['median']['value'])->toBe(15.0)->and($a['prepare']['n'])->toBe(1)->and($a['prepare']['median']['value'])->toBe(30.0);
    });

    it('reads only the newest orders of a very long window, and says so', function () {
        $w = $this->w;
        $this->instance(ScorecardBranchNumbers::class, new class(app(BranchSchedules::class)) extends ScorecardBranchNumbers
        {
            protected int $timingCap = 3;
        });
        foreach ([[10, '2026-11-13'], [20, '2026-11-14'], [30, '2026-11-15'], [40, '2026-11-16'], [50, '2026-11-17']] as [$minutes, $day]) {
            scOrder($w->a, $day.' 10:00', $minutes, 5);
        }
        $a = app(ScorecardService::class)->branches($w->admin, scWeek())['rows'][0];

        // The newest three (the 15th, 16th and 17th) were read; the two oldest were not. The count of orders handled still covers all five.
        expect($a['accept']['capped'])->toBeTrue()->and($a['accept']['n'])->toBe(3)->and($a['accept']['median']['value'])->toBe(40.0)->and($a['orders']['value'])->toBe(5);
    });

    it('counts from the order\'s confirmation when the online payment came after the part was created', function () {
        $w = $this->w;
        $o = scOrder($w->a, '2026-11-13 10:00', 35, 10, [], ['confirmed_at' => scAt('2026-11-13 10:30')]);     // created 10:00, paid 10:30, accepted 10:35

        expect(scBranch($w, $w->a)['accept']['median']['value'])->toBe(5.0);
    });

    it('counts opening-hours minutes: a night the branch was shut is not slowness (the order clock\'s own rule)', function () {
        $w = $this->w;
        $shop = opsBranch(null, ['code' => 'HRS', 'name_en' => 'Hours']);                             // open 10:00 to 22:00 (Friday from 14:00)
        scOrder($shop, '2026-11-16 21:50', 0, null, ['accepted_at' => scAt('2026-11-17 10:10'), 'status' => 'accepted']);     // Monday 21:50, accepted Tuesday 10:10
        $period = ScorecardPeriod::between('2026-11-16', '2026-11-16');

        $line = collect(scBranches($w, null, $period)['rows'])->firstWhere('code', 'HRS');

        // 10 minutes before closing and 10 after opening: 20 minutes of work time, not the 740 minutes on the wall clock.
        expect($line['accept']['median']['value'])->toBe(20.0)
            ->and(app(DailyCloseNumbers::class)->for($shop->id, '2026-11-16')['speed']['accept_minutes'])->toBe(20);        // the daily close says the same
    });
});

describe('on-time delivery', function () {
    it('counts a delivery on time when it arrived on the promised day or earlier, by the Qatar day', function () {
        $w = $this->w;
        scDelivered($w->a, '2026-11-12 09:00', '2026-11-14 23:59:59', '2026-11-14');                  // the last second of the promised day: on time
        scDelivered($w->a, '2026-11-12 09:00', '2026-11-13 10:00', '2026-11-14');                     // a day early: on time
        scDelivered($w->a, '2026-11-12 09:00', '2026-11-15 00:00:00', '2026-11-14');                  // the first second of the next day: late
        scDelivered($w->a, '2026-11-12 09:00', '2026-11-15 00:30:00', '2026-11-14');                  // 21:30 UTC on the 14th, but already the 15th in Qatar: late
        scDelivered($w->a, '2026-11-12 09:00', '2026-11-16 12:00', null);                             // no promised day: delivered, but nothing to be late for
        scDelivered($w->a, '2026-11-10 09:00', '2026-11-11 23:59:59', '2026-11-11');                  // delivered the evening before the period: the period before
        scDelivered($w->a, '2026-11-18 09:00', '2026-11-19 09:00', '2026-11-19');                     // delivered today: neither

        $d = scBranch($w, $w->a)['delivery'];

        expect($d['delivered'])->toBe(5)->and($d['promised'])->toBe(4)->and($d['on_time_count'])->toBe(2)->and($d['no_promise'])->toBe(1)
            ->and($d['on_time']['value'])->toBe(50.0)->and($d['on_time']['n'])->toBe(4)
            ->and($d['on_time']['previous'])->toBe(100.0)->and($d['on_time']['change'])->toBe(-50.0);
    });

    it('measures a delivery that was moved to another slot against the last slot, and counts how many were moved', function () {
        $w = $this->w;
        $driver = scDriver($w->a);
        $moved = scDelivered($w->a, '2026-11-12 09:00', '2026-11-17 15:00', '2026-11-17');            // first promised for the 14th, moved to the 17th, delivered on the 17th
        $id = scTrip($moved, $w->a, $driver, 'delivered', ['delivered_at' => '2026-11-17 15:00']);
        DB::table('delivery_status_history')->insert(['delivery_id' => $id, 'from_status' => 'failed', 'to_status' => 'rescheduled', 'source' => 'staff', 'created_at' => scAt('2026-11-14 16:00')]);
        scDelivered($w->a, '2026-11-12 09:00', '2026-11-16 15:00', '2026-11-16');                     // never moved

        $d = scBranch($w, $w->a)['delivery'];

        expect($d['on_time_count'])->toBe(2)->and($d['moved'])->toBe(1)->and($d['on_time']['value'])->toBe(100.0);
    });

    it('agrees with the weekly owner pack on the same days', function () {
        $w = $this->w;
        scDelivered($w->a, '2026-11-09 09:00', '2026-11-10 12:00', '2026-11-10');
        scDelivered($w->a, '2026-11-09 09:00', '2026-11-12 12:00', '2026-11-11');
        scDelivered($w->a, '2026-11-09 09:00', '2026-11-13 12:00', '2026-11-13');
        scDelivered($w->b, '2026-11-09 09:00', '2026-11-14 12:00', '2026-11-13');
        $pack = collect(app(OwnerPackData::class)->for('2026-W46')['branches'])->keyBy('id');
        $card = scBranches($w, null, ScorecardPeriod::between('2026-11-09', '2026-11-15'));
        $card = collect($card['rows'])->keyBy('id');

        foreach ([$w->a, $w->b] as $branch) {
            expect($card[$branch->id]['delivery']['on_time']['value'])->toBe($pack[$branch->id]['this']['on_time_rate'])
                ->and($card[$branch->id]['delivery']['promised'])->toBe($pack[$branch->id]['this']['promised']);
        }
        expect($card[$w->a->id]['delivery']['on_time']['value'])->toBe(66.7)->and($card[$w->b->id]['delivery']['on_time']['value'])->toBe(0.0);
    });
});

describe('pickups', function () {
    it('counts a pickup in time when it was collected within the life of the pass (72 hours), and a pass that ran out as not in time', function () {
        $w = $this->w;
        $pickup = fn (string $ready, ?string $collected, string $status = 'picked_up') => scOrder($w->a, '2026-11-12 08:00', 5, 5,
            ['type' => 'pickup', 'status' => $status, 'ready_at' => scAt($ready), 'picked_up_at' => $collected ? scAt($collected) : null]);
        $pickup('2026-11-13 10:00', '2026-11-14 10:00');                 // after 24 hours: in time
        $pickup('2026-11-13 10:00', '2026-11-16 10:00');                 // after exactly 72 hours: in time
        $pickup('2026-11-13 10:00', '2026-11-16 10:01');                 // one minute too late: collected, not in time
        $pickup('2026-11-12 12:00', null, 'pickup_ready');               // never collected, and the pass ran out on the 15th, inside the period
        $pickup('2026-11-17 12:00', null, 'pickup_ready');               // still has until the 20th

        $p = scBranch($w, $w->a)['pickup'];

        expect($p['collected'])->toBe(3)->and($p['in_time_count'])->toBe(2)->and($p['expired'])->toBe(1)->and($p['in_time']['n'])->toBe(4)
            ->and($p['in_time']['value'])->toBe(50.0)->and($p['ttl_hours'])->toBe(72);
    });
});

describe('cancellations, failures, claims and replacements', function () {
    it('gives the share of orders cancelled and the reasons written when they were cancelled', function () {
        $w = $this->w;
        scCancelled($w->a, '2026-11-13 10:00', '2026-11-13 11:00', 'out_of_stock');
        scCancelled($w->a, '2026-11-13 10:00', '2026-11-13 11:00', 'changed_mind');
        scCancelled($w->a, '2026-11-13 10:00', '2026-11-14 11:00', 'typed by hand');                      // not one of the known reasons: "other"
        foreach (range(1, 7) as $i) {
            scOrder($w->a, '2026-11-14 1'.$i.':00');
        }
        scOrder($w->a, '2026-11-15 12:00', null, null, ['status' => 'cancelled', 'cancelled_at' => scAt('2026-11-15 13:00')], ['status' => 'cancelled', 'payment_method' => 'sadad', 'confirmed_at' => null]); // expired unpaid: no order

        $c = scBranch($w, $w->a)['cancel'];

        expect($c['count'])->toBe(3)->and($c['rate']['value'])->toBe(30.0)->and($c['rate']['n'])->toBe(10)->and($c['reasons'])->toBe(['changed_mind' => 1, 'other' => 1, 'out_of_stock' => 1]);
    });

    it('shows a failed delivery by its reason, apart for the customer\'s side and the team\'s, and the same total as the daily close', function () {
        $w = $this->w;
        scFailed($w->a, '2026-11-13 09:00', '2026-11-13 15:00', 'customer_absent');
        scFailed($w->a, '2026-11-13 09:00', '2026-11-14 15:00', 'customer_absent');
        scFailed($w->a, '2026-11-13 09:00', '2026-11-15 15:00', 'wrong_address');
        scFailed($w->a, '2026-11-13 09:00', '2026-11-16 15:00', 'damaged');
        scFailed($w->a, '2026-11-13 09:00', '2026-11-17 15:00', 'other');
        foreach (range(1, 5) as $i) {
            scDelivered($w->a, '2026-11-13 09:00', '2026-11-14 1'.$i.':00', '2026-11-14');
        }

        $f = scBranch($w, $w->a)['failed'];
        $closes = collect(range(12, 18))->sum(fn ($day) => app(DailyCloseNumbers::class)->for($w->a->id, '2026-11-'.$day)['failed_deliveries']);

        // 5 failed of the 10 deliveries that ended; the team could have prevented only the damaged one: 1 of 10.
        expect($f['count'])->toBe(5)->and($f['rate']['value'])->toBe(50.0)->and($f['rate']['n'])->toBe(10)->and($f['team_rate']['value'])->toBe(10.0)
            ->and($f['customer'])->toBe(3)->and($f['team'])->toBe(1)->and($f['other'])->toBe(1)
            ->and($f['reasons'])->toBe(['customer_absent' => 2, 'damaged' => 1, 'other' => 1, 'wrong_address' => 1])
            ->and($closes)->toBe(5);
    });

    it('counts return claims per order that reached the customer, with the reasons, and the orders that needed a replacement', function () {
        $w = $this->w;
        foreach (range(1, 4) as $i) {
            $o = scDelivered($w->a, '2026-11-13 09:00', '2026-11-14 1'.$i.':00', '2026-11-14');
            if ($i <= 2) {
                DB::table('return_requests')->insert(['id' => (string) Str::uuid(), 'number' => 'RR'.$i, 'order_id' => $o['order'], 'user_id' => User::factory()->create()->id, 'branch_id' => $w->a->id,
                    'status' => 'requested', 'reason_code' => $i === 1 ? 'damaged' : 'wrong_item', 'items' => '[]', 'created_at' => scAt('2026-11-15 10:00'), 'updated_at' => scAt('2026-11-15 10:00')]);
            }
        }
        $first = scOrder($w->a, '2026-11-14 10:00');
        $replacement = (string) Str::uuid();
        $itemId = DB::table('order_items')->where('order_fulfillment_id', $first['part'])->value('id');
        DB::table('order_items')->insert(['id' => $replacement, 'order_id' => $first['order'], 'order_fulfillment_id' => $first['part'], 'branch_id' => $w->a->id, 'sku' => 'SKU-R', 'name_en' => 'Sofa B',
            'name_ar' => 'أريكة ب', 'unit_price' => '90.00', 'unit_final_price' => '90.00', 'quantity' => 1, 'line_subtotal' => '90.00', 'line_total' => '90.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_substitution_requests')->insert(['id' => (string) Str::uuid(), 'order_id' => $first['order'], 'order_fulfillment_id' => $first['part'], 'branch_id' => $w->a->id,
            'original_order_item_id' => $itemId, 'replacement_order_item_id' => $replacement, 'status' => 'accepted', 'fallback' => 'remove', 'response_minutes' => 30,
            'asked_at' => scAt('2026-11-14 11:00'), 'deadline_at' => scAt('2026-11-14 11:30'), 'original_total' => '100.00', 'replacement_total' => '90.00', 'created_at' => now(), 'updated_at' => now()]);

        $b = scBranch($w, $w->a);

        // 4 delivered, 2 claims: 50.0. 5 orders placed, 1 needed a replacement: 20.0.
        expect($b['claims']['count'])->toBe(2)->and($b['claims']['rate']['value'])->toBe(50.0)->and($b['claims']['reasons'])->toBe(['damaged' => 1, 'wrong_item' => 1])
            ->and($b['substitutions']['count'])->toBe(1)->and($b['substitutions']['rate']['value'])->toBe(20.0);
    });
});

describe('cash', function () {
    it('adds up the differences cashiers found, net and ignoring the sign, and how many hand-ins were not exact', function () {
        $w = $this->w;
        $driver = scDriver($w->a);
        scHandIn($w->a, $driver, '2026-11-13 18:00', '-5.00');
        scHandIn($w->a, $driver, '2026-11-14 18:00', '2.00');
        scHandIn($w->a, $driver, '2026-11-15 18:00', '0.00');
        scHandIn($w->a, $driver, '2026-11-19 09:00', '-50.00');               // today: not in the period
        scHandIn($w->a, $driver, '2026-11-08 18:00', '-1.00');                // the period before
        scHandIn($w->b, scDriver($w->b, 'Salem'), '2026-11-13 18:00', '-9.00');            // another branch

        $cash = scBranch($w, $w->a)['cash'];
        $close = app(DailyCloseNumbers::class)->for($w->a->id, '2026-11-13')['cash'];

        expect($cash['net'])->toBe('-3.00')->and($cash['abs'])->toBe('7.00')->and($cash['variances'])->toBe(2)->and($cash['handins'])->toBe(3)
            ->and($cash['previous_net'])->toBe('-1.00')->and($cash['net_change'])->toBe('-2.00')->and($cash['previous_handins'])->toBe(1)
            ->and($cash['open_variances'])->toBe(4)                            // every difference of any date nobody has explained: -5, +2, -50, -1
            ->and($close['variance'])->toBe('-5.00');                          // the daily close reads the same hand-in the same way
    });

    it('lists the cash the people who took it still hold, and how much is past the order clock\'s cash limit (24 hours)', function () {
        $w = $this->w;
        $driver = scDriver($w->a);
        scCash(scOrder($w->a, '2026-11-17 09:00'), $w->a, $driver, '100.00', '2026-11-18 12:00');          // exactly 24 hours ago: at the limit, so late
        scCash(scOrder($w->a, '2026-11-17 09:00'), $w->a, $driver, '60.00', '2026-11-18 12:01');           // one minute inside the limit
        scCash(scOrder($w->a, '2026-11-17 09:00'), $w->a, $driver, '40.00', '2026-11-16 12:00');           // two days old
        scCash(scOrder($w->a, '2026-11-17 09:00'), $w->a, $driver, '999.00', '2026-11-16 12:00', handedIn: true);   // handed in: not held

        $cash = scBranch($w, $w->a)['cash'];

        expect($cash['held'])->toBe(3)->and($cash['held_amount'])->toBe('200.00')->and($cash['late'])->toBe(2)->and($cash['late_amount'])->toBe('140.00')
            ->and($cash['oldest_minutes'])->toBe(3 * 1440)->and($cash['limit_hours'])->toBe(24);
    });
});

describe('the previous period and the minimum sample', function () {
    it('compares every figure with the period of the same length before it', function () {
        $w = $this->w;
        foreach (range(1, 8) as $i) {
            scDelivered($w->a, '2026-11-13 09:00', '2026-11-14 1'.($i % 10).':00', $i <= 6 ? '2026-11-14' : '2026-11-13');     // 6 of 8 on time = 75.0
        }
        foreach (range(1, 4) as $i) {
            scDelivered($w->a, '2026-11-06 09:00', '2026-11-07 1'.$i.':00', $i <= 1 ? '2026-11-07' : '2026-11-06');           // 1 of 4 on time = 25.0
        }

        $d = scBranch($w, $w->a)['delivery']['on_time'];

        expect($d['value'])->toBe(75.0)->and($d['previous'])->toBe(25.0)->and($d['change'])->toBe(50.0);
    });

    it('shows "not enough data yet" for a number built from fewer cases than the minimum, and the number from the minimum on', function () {
        $w = $this->w;
        app(ScorecardSettings::class)->setMinSample(10);
        foreach (range(1, 9) as $i) {
            scDelivered($w->a, '2026-11-13 09:00', '2026-11-14 1'.($i % 10).':00', '2026-11-14');
        }
        $nine = scBranch($w, $w->a);
        scDelivered($w->a, '2026-11-13 09:00', '2026-11-14 18:00', '2026-11-14');
        $ten = scBranch($w, $w->a);

        expect($nine['delivery']['on_time'])->toMatchArray(['value' => null, 'state' => 'few', 'n' => 9])
            ->and($nine['accept']['median'])->toMatchArray(['value' => null, 'state' => 'few', 'n' => 9])     // times follow the same rule
            ->and($nine['delivery']['delivered'])->toBe(9)                                                     // counts are always shown
            ->and($nine['orders']['value'])->toBe(9)
            ->and($ten['delivery']['on_time'])->toMatchArray(['value' => 100.0, 'state' => 'ok', 'n' => 10]);
    });

    it('gives no change when the period before had too few cases, even though this one has enough', function () {
        $w = $this->w;
        app(ScorecardSettings::class)->setMinSample(5);
        foreach (range(1, 6) as $i) {
            scDelivered($w->a, '2026-11-13 09:00', '2026-11-14 1'.$i.':00', '2026-11-14');
        }
        foreach (range(1, 3) as $i) {
            scDelivered($w->a, '2026-11-06 09:00', '2026-11-07 1'.$i.':00', '2026-11-07');
        }

        $d = scBranch($w, $w->a)['delivery']['on_time'];

        expect($d['state'])->toBe('ok')->and($d['value'])->toBe(100.0)->and($d['change'])->toBeNull()->and($d['previous_state'])->toBe('few')->and($d['previous'])->toBeNull();
    });

    it('lets the owner change the minimum, within its range, and falls back to the default for a value nobody can use', function () {
        $settings = app(ScorecardSettings::class);

        expect($settings->minSample())->toBe(1);                       // set by the test world
        $settings->setMinSample(25);
        expect($settings->minSample())->toBe(25);
        $settings->setMinSample(0);
        expect($settings->minSample())->toBe(ScorecardSettings::MIN);
        $settings->setMinSample(5000);
        expect($settings->minSample())->toBe(ScorecardSettings::MAX);
        app(Settings::class)->set(ScorecardSettings::KEY, 'garbage');
        expect($settings->minSample())->toBe(ScorecardSettings::DEFAULT_MIN_SAMPLE);
    });
});

describe('agreeing with the daily close', function () {
    it('counts the same orders, cancellations and cash as the daily close does for the same days', function () {
        $w = $this->w;
        $driver = scDriver($w->a);
        foreach (['2026-11-13', '2026-11-14', '2026-11-14'] as $day) {
            scOrder($w->a, $day.' 10:00', 4, 6);
        }
        scCancelled($w->a, '2026-11-15 10:00', '2026-11-15 11:00', 'changed_mind');
        scOrder($w->a, '2026-11-16 09:00', null, null, [], ['status' => 'pending_payment', 'payment_method' => 'sadad']);
        scHandIn($w->a, $driver, '2026-11-14 20:00', '-4.00');
        scHandIn($w->a, $driver, '2026-11-16 20:00', '6.00');

        $closes = collect(range(12, 18))->map(fn ($day) => app(DailyCloseNumbers::class)->for($w->a->id, '2026-11-'.$day));
        $a = scBranch($w, $w->a);

        expect($a['orders']['value'])->toBe($closes->sum('orders.placed'))->and($a['cancel']['count'])->toBe($closes->sum('orders.cancelled'))
            ->and($a['cash']['net'])->toBe('2.00')->and($a['cash']['variances'])->toBe($closes->sum('cash.variance_count'))
            ->and($closes->sum('speed.accepted'))->toBe($a['accept']['n']);
    });
});
