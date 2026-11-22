<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Reports\Services\Metrics\Cash;
use App\Domain\Reports\Services\Metrics\Claims;
use App\Domain\Reports\Services\Metrics\Deliveries;
use App\Domain\Reports\Services\Metrics\Orders;
use App\Domain\Reports\Services\Metrics\Speed;
use App\Domain\Reports\Services\SalesBasis;
use App\Domain\Reports\Services\ScorecardPeriod;
use App\Domain\Reports\Services\ScorecardSettings;
use App\Domain\Reports\Services\ScorecardStats;
use App\Domain\Reports\Services\StockLevels;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/NumbersSupport.php';

/*
 | The shared definitions of docs/guides/NUMBER_DEFINITIONS.md (App\Domain\Reports\Services\Metrics), one at a time, at the edges: nothing at all, one case,
 | the first and last second of a window, the Qatar midnight that is the evening before in UTC, and a list of branches that is empty or has one branch left out.
 */

beforeEach(function () {
    $this->w = scWorld();
    $this->world = (object) ['customer' => User::factory()->create(), 'admin' => $this->w->admin];       // what the order builders of the owner-numbers tests read
    [$this->from, $this->to] = BusinessTime::dateRange('2026-11-12', '2026-11-12');
    $this->ids = [$this->w->a->id, $this->w->b->id];
});

describe('nothing at all', function () {
    it('returns an empty answer, never an error, for no branches and for a window with no cases', function () {
        [$from, $to] = [$this->from, $this->to];

        expect(Orders::placed([], $from, $to))->toBe(['branches' => [], 'together' => 0])->and(Orders::cancelled([], $from, $to))->toBe([])->and(Orders::cancelledByReason([], $from, $to))->toBe([])
            ->and(Orders::reached([], $from, $to))->toBe([])->and(Orders::standing([], $from, $to))->toBe([])
            ->and(Deliveries::promises([], $from, $to))->toBe([])->and(Deliveries::failures([], $from, $to))->toBe([])
            ->and(Cash::handIns([], $from, $to))->toBe([])->and(Cash::held([]))->toBe([])->and(Cash::openVariances([]))->toBe([])
            ->and(Claims::opened([], $from, $to))->toBe([])->and(Claims::received([], $from, $to))->toBe([])->and(StockLevels::countsByBranch([]))->toBe([])
            ->and(app(Speed::class)->minutes([], $from, $to))->toBe([])
            ->and(Orders::placed($this->ids, $from, $to))->toBe(['branches' => [], 'together' => 0])->and(Deliveries::promises($this->ids, $from, $to))->toBe([])
            ->and(SalesBasis::byBranch(BranchScope::all(), $from, $to)['branches'])->toBe([])->and(SalesBasis::byBranch(BranchScope::all(), $from, $to)['together']['total']->toDecimal())->toBe('0.00')
            ->and(SalesBasis::byBranch(BranchScope::none(), $from, $to)['together']['orders'])->toBe(0);
    });

    it('has no average of nothing, and an average basket of nothing is nothing, not a division by zero', function () {
        expect(Speed::average([]))->toBeNull()->and(Speed::average([5.0]))->toBe(5)->and(Speed::average([5.0, 10.0]))->toBe(8)             // 7.5 is 8 in whole minutes
            ->and(SalesBasis::average(Money::zero(), 0)->toDecimal())->toBe('0.00')->and(SalesBasis::average(Money::of('100.00'), 3)->toDecimal())->toBe('33.33')
            ->and(SalesBasis::average(Money::of('100.00'), 1)->toDecimal())->toBe('100.00');
    });
});

describe('which orders are real', function () {
    it('leaves out an order waiting for payment and an online order that expired unpaid, and keeps a cancelled one that was confirmed', function () {
        $a = $this->w->a;
        scOrder($a, '2026-11-12 10:00');                                                                                                // a real order
        scOrder($a, '2026-11-12 10:05', null, null, [], ['status' => 'pending_payment', 'payment_method' => 'sadad', 'confirmed_at' => null]);
        scOrder($a, '2026-11-12 10:10', null, null, ['status' => 'cancelled', 'cancelled_at' => scAt('2026-11-12 10:30')], ['status' => 'cancelled', 'payment_method' => 'sadad', 'confirmed_at' => null]);
        scOrder($a, '2026-11-12 10:15', null, null, ['status' => 'cancelled', 'cancelled_at' => scAt('2026-11-12 10:30')], ['status' => 'cancelled']);                // cash on delivery, cancelled by someone: real
        scOrder($a, '2026-11-12 10:20', null, null, ['status' => 'cancelled', 'cancelled_at' => scAt('2026-11-12 10:30')], ['status' => 'cancelled', 'payment_method' => 'sadad']);    // paid online, then cancelled: real

        expect(Orders::placed([$a->id], $this->from, $this->to))->toBe(['branches' => [$a->id => 3], 'together' => 3])->and(Orders::cancelled([$a->id], $this->from, $this->to))->toBe([$a->id => 2]);
    });

    it('counts an order that two branches share once for the two together and once for each', function () {
        $o = nbSplit([[$this->w->a, '100.00', '0.00'], [$this->w->b, '50.00', '0.00']], '2026-11-12 11:00', [null, null], []);
        scOrder($this->w->a, '2026-11-12 12:00');

        $placed = Orders::placed($this->ids, $this->from, $this->to);

        expect($placed['branches'])->toEqual([$this->w->a->id => 2, $this->w->b->id => 1])->and($placed['together'])->toBe(2)->and($o['order'])->not->toBeEmpty();
        $sold = SalesBasis::byBranch(BranchScope::of($this->ids), $this->from, $this->to);
        expect($sold['together']['orders'])->toBe(2)->and($sold['branches'][$this->w->a->id]['orders'])->toBe(2)->and($sold['branches'][$this->w->b->id]['orders'])->toBe(1)
            ->and($sold['together']['total']->toDecimal())->toBe('250.00')->and($sold['branches'][$this->w->a->id]['total']->plus($sold['branches'][$this->w->b->id]['total'])->toDecimal())->toBe('250.00');
        // A branch the viewer does not see is not in the company's line either.
        $own = SalesBasis::byBranch(BranchScope::of([$this->w->b->id]), $this->from, $this->to);
        expect($own['together']['orders'])->toBe(1)->and($own['together']['total']->toDecimal())->toBe('50.00')->and(array_keys($own['branches']))->toBe([$this->w->b->id]);
    });
});

describe('the edges of a window and of a Qatar day', function () {
    it('counts a part delivered in the first and the last second of the day, and not the second before or after, once even when it was also collected', function () {
        $a = $this->w->a;
        $on = fn (string $at, array $part = []) => scOrder($a, '2026-11-10 09:00', 5, 5, ['status' => 'delivered', 'type' => 'delivery', 'delivered_at' => scAt($at)] + $part);
        $on('2026-11-12 00:00:00');            // the first second of the 12th in Qatar (21:00 UTC on the 11th)
        $on('2026-11-12 23:59:59');            // the last second
        $on('2026-11-11 23:59:59');            // the second before
        $on('2026-11-13 00:00:00');            // the second after
        scOrder($a, '2026-11-10 09:00', 5, 5, ['type' => 'pickup', 'status' => 'picked_up', 'picked_up_at' => scAt('2026-11-12 12:00')]);
        $both = $on('2026-11-12 12:00:00', ['picked_up_at' => scAt('2026-11-12 12:30')]);           // a part with both stamps is one part

        expect(Orders::reached([$a->id], $this->from, $this->to))->toBe([$a->id => 4])->and($both['part'])->not->toBeEmpty();
    });

    it('is on time when it arrived on the promised Qatar day, whatever the hour, and late from the first second of the next day', function () {
        $a = $this->w->a;
        $d = fn (string $at, ?string $slot) => scOrder($a, '2026-11-10 09:00', 5, 5, ['status' => 'delivered', 'type' => 'delivery', 'delivered_at' => scAt($at), 'slot_date' => $slot]);
        $d('2026-11-12 23:59:59', '2026-11-12');       // the last second of the promised day: on time
        $d('2026-11-12 08:00:00', '2026-11-13');       // a day early: on time
        $d('2026-11-12 00:00:00', '2026-11-11');       // the first second of the day after the promised one: late (it is still the 11th in UTC)
        $d('2026-11-12 00:30:00', '2026-11-11');       // 21:30 UTC on the 11th, which is the 12th in Qatar: late
        $d('2026-11-12 10:00:00', null);               // no promised day: delivered, never late

        $line = Deliveries::promises([$a->id], $this->from, $this->to)[$a->id];

        expect($line)->toBe(['delivered' => 5, 'promised' => 4, 'on_time' => 2, 'moved' => 0]);
    });

    it('counts the moved deliveries only when asked, and only the promised ones', function () {
        $a = $this->w->a;
        $driver = scDriver($a);
        $moved = scDelivered($a, '2026-11-10 09:00', '2026-11-12 15:00', '2026-11-12');
        $id = scTrip($moved, $a, $driver, 'delivered', ['delivered_at' => '2026-11-12 15:00']);
        DB::table('delivery_status_history')->insert(['delivery_id' => $id, 'from_status' => 'failed', 'to_status' => 'rescheduled', 'source' => 'staff', 'created_at' => scAt('2026-11-11 16:00')]);
        $unpromised = scDelivered($a, '2026-11-10 09:00', '2026-11-12 16:00', null);
        DB::table('delivery_status_history')->insert(['delivery_id' => scTrip($unpromised, $a, $driver, 'delivered', ['delivered_at' => '2026-11-12 16:00']), 'from_status' => 'failed', 'to_status' => 'rescheduled', 'source' => 'staff', 'created_at' => scAt('2026-11-11 16:00')]);

        expect(Deliveries::promises([$a->id], $this->from, $this->to)[$a->id]['moved'])->toBe(0)
            ->and(Deliveries::promises([$a->id], $this->from, $this->to, withMoved: true)[$a->id])->toBe(['delivered' => 2, 'promised' => 1, 'on_time' => 1, 'moved' => 1]);
    });
});

describe('failures, claims and refunds', function () {
    it('groups failures by branch or by driver, leaves a delivery nobody drove out of the drivers, and writes a missing reason as nothing', function () {
        $a = $this->w->a;
        $driver = scDriver($a);
        scFailed($a, '2026-11-12 08:00', '2026-11-12 12:00', 'damaged', $driver);
        scFailed($a, '2026-11-12 08:00', '2026-11-12 13:00', 'customer_absent', null);
        $none = scFailed($a, '2026-11-12 08:00', '2026-11-12 14:00', 'customer_absent', $driver);
        DB::table('deliveries')->where('order_fulfillment_id', $none['part'])->update(['failure_reason' => null]);

        expect(Deliveries::failures([$a->id], $this->from, $this->to))->toEqual([$a->id => ['damaged' => 1, 'customer_absent' => 1, '' => 1]])
            ->and(Deliveries::failures([$a->id], $this->from, $this->to, 'driver_id'))->toEqual([$driver->id => ['damaged' => 1, '' => 1]])
            ->and(Deliveries::failures([$a->id], $this->from, $this->to, 'driver_id', $driver->id))->toEqual([$driver->id => ['damaged' => 1, '' => 1]])
            ->and(Deliveries::failures([$this->w->b->id], $this->from, $this->to))->toBe([]);
    });

    it('opens a claim on one day and gets the goods back on another, each on its own day', function () {
        $a = $this->w->a;
        $o = scDelivered($a, '2026-11-09 09:00', '2026-11-10 12:00', '2026-11-10');
        nbClaim($o, $a, User::factory()->create(), '2026-11-12 10:00', '2026-11-14 10:00');
        [$thu, $fri] = [BusinessTime::dateRange('2026-11-12', '2026-11-12'), BusinessTime::dateRange('2026-11-14', '2026-11-14')];

        expect(Claims::opened([$a->id], ...$thu))->toBe([$a->id => ['damaged' => 1]])->and(Claims::received([$a->id], ...$thu))->toBe([])
            ->and(Claims::opened([$a->id], ...$fri))->toBe([])->and(Claims::received([$a->id], ...$fri))->toBe([$a->id => 1]);
    });

    it('divides a refund the way the sale was divided, leaves out the reasons the lines already explain, and can be narrowed to some orders', function () {
        $o = nbSplit([[$this->w->a, '300.00', '0.00'], [$this->w->b, '100.00', '0.00']], '2026-11-10 10:00', [null, null], []);
        nbRefund(['order' => $o['order']], '40.00', '2026-11-12 10:00', 'return');
        nbRefund(['order' => $o['order']], '20.00', '2026-11-12 11:00', 'order_adjustment');
        $scopeA = BranchScope::of([$this->w->a->id]);

        $all = SalesBasis::refunds($scopeA, $this->from, $this->to);
        $outside = SalesBasis::refunds($scopeA, $this->from, $this->to, ['order_cancelled', 'order_adjustment']);
        $none = SalesBasis::refunds($scopeA, $this->from, $this->to, [], DB::table('orders')->where('id', Str::uuid())->select('id'));

        // Branch A carries 3/4 of the order: 30.00 of the 40.00 return and 15.00 of the 20.00 adjustment.
        expect($all['amount']->toDecimal())->toBe('45.00')->and($all['count'])->toBe(2)->and($outside['amount']->toDecimal())->toBe('30.00')->and($outside['count'])->toBe(1)
            ->and($none['amount']->toDecimal())->toBe('0.00')->and(SalesBasis::refunds(BranchScope::none(), $this->from, $this->to)['count'])->toBe(0);
    });
});

describe('cash', function () {
    it('holds only cash that was taken, is worth something and is not handed in, and says how much of it is late only when asked', function () {
        $a = $this->w->a;
        $driver = scDriver($a);
        $held = scCash(scOrder($a, '2026-11-10 09:00'), $a, $driver, '100.00', '2026-11-10 12:00');
        $card = scCash(scOrder($a, '2026-11-10 09:00'), $a, $driver, '40.00', '2026-11-12 12:00');
        DB::table('cash_collections')->where('id', $card)->update(['tender' => 'card_terminal']);
        scCash(scOrder($a, '2026-11-10 09:00'), $a, $driver, '25.00', '2026-11-11 12:00', handedIn: true);
        $zero = scCash(scOrder($a, '2026-11-10 09:00'), $a, $driver, '0.00', '2026-11-11 13:00');
        scCash(scOrder($a, '2026-11-10 09:00'), $a, $driver, '10.00', '2026-11-12 18:00');

        $late = CarbonImmutable::parse('2026-11-11 00:00', 'Asia/Qatar')->utc();
        $plain = Cash::held([$a->id])[$a->id];
        $dated = Cash::held([$a->id], $late)[$a->id];
        $byDriver = Cash::held([$a->id], null, 'collected_by', [$driver->id]);

        expect($plain['held'])->toBe(2)->and($plain['amount'])->toBe('110.00')->and($plain['late'])->toBe(0)->and($plain['late_amount'])->toBe('0.00')
            ->and($dated['late'])->toBe(1)->and($dated['late_amount'])->toBe('100.00')->and(str_starts_with((string) $dated['oldest'], scAt('2026-11-10 12:00')))->toBeTrue()
            ->and($byDriver[$driver->id]['amount'])->toBe('110.00')->and(Cash::held([$a->id], null, 'collected_by', [(string) Str::uuid()]))->toBe([])
            ->and(Cash::heldQuery()->where('id', $held)->exists())->toBeTrue()->and(Cash::heldQuery()->where('id', $zero)->exists())->toBeFalse()->and(Cash::heldQuery()->where('id', $card)->exists())->toBeFalse();
    });

    it('adds up the hand-ins of a window by branch or by the person who handed the cash in, and only for the people asked about', function () {
        $a = $this->w->a;
        [$dina, $omar] = [scDriver($a, 'Dina'), scDriver($a, 'Omar')];
        scHandIn($a, $dina, '2026-11-12 10:00', '-5.00');
        scHandIn($a, $dina, '2026-11-12 11:00', '2.00');
        scHandIn($a, $omar, '2026-11-12 12:00', '0.00');
        scHandIn($a, $omar, '2026-11-13 00:00', '-9.00');                      // the first second of the next day

        expect(Cash::handIns([$a->id], $this->from, $this->to)[$a->id])->toBe(['handins' => 3, 'cash_net' => '-3.00', 'cash_abs' => '7.00', 'variances' => 2])
            ->and(Cash::handIns([$a->id], $this->from, $this->to, 'collector_id', [$dina->id]))->toEqual([$dina->id => ['handins' => 2, 'cash_net' => '-3.00', 'cash_abs' => '7.00', 'variances' => 2]])
            ->and(Cash::handIns([$a->id], $this->from, $this->to, 'branch_id; drop table users')[$a->id]['handins'])->toBe(3)              // a column name that is not on the list is the branch
            ->and(Cash::openVariances([$a->id]))->toBe([$a->id => 3])->and(Cash::openVariances(null))->toBe([$a->id => 3]);
    });
});

describe('time and stock', function () {
    it('reads the newest parts of a window up to the sample and says when it cut', function () {
        $a = $this->w->a;
        foreach ([10, 20, 30] as $i => $minutes) {
            scOrder($a, '2026-11-12 1'.$i.':00', $minutes, 5);
        }

        $all = app(Speed::class)->minutes([$a->id], $this->from, $this->to);
        $cut = app(Speed::class)->minutes([$a->id], $this->from, $this->to, cap: 2);

        expect($all[$a->id]['capped'])->toBeFalse()->and(collect($all[$a->id]['accept'])->sort()->values()->all())->toBe([10.0, 20.0, 30.0])
            ->and($cut[$a->id]['capped'])->toBeTrue()->and($cut[$a->id]['accept'])->toBe([30.0, 20.0]);                    // the newest two
    });

    it('counts stock by branch exactly as it counts it for the one branch', function () {
        [$v1, $v2] = onVariants(2);
        $ledger = app(InventoryLedger::class);
        $ledger->apply($this->w->a->id, $v1->id, MovementType::OpeningBalance, 2);
        $ledger->apply($this->w->a->id, $v1->id, MovementType::SaleDeduction, -2);
        $ledger->apply($this->w->b->id, $v2->id, MovementType::OpeningBalance, 2);
        DB::table('branch_inventory')->where('branch_id', $this->w->b->id)->update(['minimum_stock' => 5]);

        $byBranch = StockLevels::countsByBranch($this->ids);

        expect($byBranch)->toEqual([$this->w->a->id => ['low' => 0, 'out' => 1], $this->w->b->id => ['low' => 1, 'out' => 0]])
            ->and(StockLevels::counts(DB::table('branch_inventory as bi')->where('bi.branch_id', $this->w->a->id)))->toBe(['low' => 0, 'out' => 1]);
    });
});

describe('a driver with fewer cases than the minimum', function () {
    it('shows the count and no percentage, while the same cases give the owner pack its rate', function () {
        app(ScorecardSettings::class)->setMinSample(10);
        $a = $this->w->a;
        $driver = scDriver($a);
        foreach (range(10, 12) as $hour) {
            $o = scDelivered($a, '2026-11-12 08:00', '2026-11-12 '.$hour.':00', '2026-11-12');
            scTrip($o, $a, $driver, 'delivered', ['delivered_at' => '2026-11-12 '.$hour.':00', 'out_for_delivery_at' => '2026-11-12 '.($hour - 1).':30']);
        }
        $row = scDriverRow($this->w, $driver, ScorecardPeriod::between('2026-11-12', '2026-11-12'));

        expect($row['delivered']['value'])->toBe(3)->and($row['on_time']['state'])->toBe(ScorecardStats::FEW)->and($row['on_time']['value'])->toBeNull()->and($row['on_time']['promised'])->toBe(3)
            ->and(Deliveries::promises([$a->id], $this->from, $this->to)[$a->id]['on_time'])->toBe(3);
    });
});
