<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Operations\Services\LiveBoard;
use App\Domain\Reports\Services\CustomerValue;
use App\Domain\Reports\Services\DailyCloseNumbers;
use App\Domain\Reports\Services\DailyCloseService;
use App\Domain\Reports\Services\DashboardMetrics;
use App\Domain\Reports\Services\Metrics\Orders;
use App\Domain\Reports\Services\Metrics\Speed;
use App\Domain\Reports\Services\OfferReturn;
use App\Domain\Reports\Services\OwnerPackData;
use App\Domain\Reports\Services\ReportService;
use App\Domain\Reports\Services\SalesBasis;
use App\Domain\Reports\Services\ScorecardPeriod;
use App\Domain\Reports\Services\ScorecardSettings;
use App\Domain\Reports\Services\ScorecardStats;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/NumbersSupport.php';

/*
 | THE AGREEMENT TEST for "one definition per number" (docs/guides/NUMBER_DEFINITIONS.md).
 |
 | One fixture (NumbersSupport.php: four branches, two drivers, late, failed, cancelled, unpaid, cash-on-delivery, refunded, returned, split and assembly orders),
 | read through every screen that shows a number: the daily close, the weekly owner pack, the scorecard (branches and drivers), the dashboard, the live board, customer
 | value, offer returns and the sales report. For the same branch and the same period, the same NAMED number must be identical on every screen that shows it.
 |
 | Two kinds of assertion, because two things can go wrong. A screen can disagree with another screen (each test compares them), and ALL the screens can be wrong
 | together (so the figures a person can work out by hand from the fixture are written next to the comparison: "Doha delivered 9 with a promised day, 7 on time").
 | The week is ISO week 46, Monday 9 to Sunday 15 November 2026, in Qatar time.
 */

/** Writes the one fixture into the world before each test of the block it is called in (a block that needs a different world does not call it). */
function nbFixture(): void
{
    beforeEach(function () {
        $this->x = nbFill($this->w);
    });
}

beforeEach(function () {
    $this->w = nbWorld();
    $this->days = array_map(fn (int $d) => sprintf('2026-11-%02d', $d), range(9, 15));
    $this->week = ScorecardPeriod::between('2026-11-09', '2026-11-15');
    $this->close = fn (Branch $b, string $day) => app(DailyCloseNumbers::class)->for($b->id, $day);
    // Every figure of the daily close of a branch for each day of the week, as lists, so a test can add a day up.
    $this->days_of = fn (Branch $b) => array_map(fn (string $day) => ($this->close)($b, $day), $this->days);
    $this->sum = fn (array $values) => array_reduce($values, fn (Money $carry, $v) => $carry->plus(Money::of((string) $v)), Money::zero())->toDecimal();
    $this->pack = fn () => collect(app(OwnerPackData::class)->for('2026-W46')['branches'])->keyBy('code');
    $this->card = fn (?ScorecardPeriod $period = null) => collect(scBranches($this->w, null, $period ?? $this->week)['rows'])->keyBy('code');
    $this->branches = fn () => ['DOH' => $this->w->a, 'WKR' => $this->w->b, 'HRS' => $this->w->c, 'EMP' => $this->w->d];
});

describe('sales and orders', function () {
    nbFixture();

    it('gives a branch the same week on the owner pack as the sum of its days on the daily close', function () {
        $pack = ($this->pack)();
        foreach (($this->branches)() as $code => $branch) {
            $days = ($this->days_of)($branch);
            $sold = ($this->sum)(array_map(fn (array $d) => $d['sales']['total'], $days));
            $orders = array_sum(array_map(fn (array $d) => $d['sales']['orders'], $days));
            $mix = ($this->sum)(array_merge(...array_map(fn (array $d) => array_column($d['payment_mix'], 'amount'), $days)));

            expect($pack[$code]['this']['sales'])->toBe($sold)->and($pack[$code]['this']['orders'])->toBe($orders)->and($mix)->toBe($sold)
                ->and($pack[$code]['this']['average'])->toBe(SalesBasis::average(Money::of($sold), $orders)->toDecimal());
        }
        // Worked out by hand: Doha sold 2,865.00 in 12 orders (the share of the order it shares with Wakra is 1,015.00), Wakra 725.00 in 6, the branch with hours 200.00 in 2, the empty one nothing.
        expect([$pack['DOH']['this']['sales'], $pack['DOH']['this']['orders'], $pack['WKR']['this']['sales'], $pack['WKR']['this']['orders'], $pack['HRS']['this']['sales'], $pack['EMP']['this']['sales']])
            ->toBe(['2865.00', 12, '725.00', 6, '200.00', '0.00']);
    });

    it('counts an order that two branches share once in the company\'s line, on the pack, the sales report and the dashboard\'s own definition', function () {
        $pack = app(OwnerPackData::class)->for('2026-W46');
        [$start, $end] = BusinessTime::dateRange('2026-11-09', '2026-11-15');
        $dashboard = SalesBasis::totals(BranchScope::all(), $start, $end);
        $report = collect(app(ReportService::class)->run($this->w->admin, 'sales_summary', '2026-11-09', '2026-11-15')['rows'])->pluck('value', 'metric');
        $branchOrders = array_sum(array_column(array_column($pack['branches'], 'this'), 'orders'));

        expect($pack['total']['this']['orders'])->toBe(19)->and($dashboard['orders'])->toBe(19)->and($report['orders'])->toBe(19)
            ->and($branchOrders)->toBe(20)                                                                       // the branches' own counts add up to one more: the shared order is one of each
            ->and($pack['total']['this']['sales'])->toBe('3790.00')->and($dashboard['total']->toDecimal())->toBe('3790.00')->and($report['booked_sales'])->toBe('3790.00')
            ->and($pack['total']['this']['average'])->toBe(SalesBasis::average($dashboard['total'], 19)->toDecimal())->and($pack['total']['this']['average'])->toBe('199.47');
    });

    it('draws the dashboard\'s 14-day chart and today\'s tiles from the same days as the daily close', function () {
        $m = app(DashboardMetrics::class)->for($this->w->admin);
        $branches = ($this->branches)();
        foreach ($m['chart']['labels'] as $i => $label) {
            $day = BusinessTime::now()->subDays(13 - $i)->toDateString();
            $closed = Money::zero();
            foreach ($branches as $branch) {
                $closed = $closed->plus(Money::of(($this->close)($branch, $day)['sales']['total']));
            }
            [$start, $end] = BusinessTime::dateRange($day, $day);

            expect(Money::of((string) $m['chart']['totals'][$i])->toDecimal())->toBe($closed->toDecimal())
                ->and($m['chart']['orders'][$i])->toBe(SalesBasis::totals(BranchScope::all(), $start, $end)['orders']);
        }
        $today = ($this->sum)(array_map(fn (Branch $b) => ($this->close)($b, '2026-11-19')['sales']['total'], array_values($branches)));

        expect($m['sales_today'])->toBe(Money::of($today)->format())->and(Money::of($today)->toDecimal())->toBe('1110.00')->and($m['orders_today'])->toBe(7);
    });

    it('agrees with an independent count of the orders of a day: placed that Qatar day, not cancelled, not waiting for payment', function () {
        foreach ($this->days as $day) {
            $oracle = DB::table('orders')->whereNotIn('status', ['cancelled', 'pending_payment'])->whereRaw("(placed_at at time zone 'Asia/Qatar')::date = ?", [$day])
                ->selectRaw('coalesce(sum(grand_total), 0)::numeric(14,2) as total, count(*) as orders')->first();
            $screens = array_map(fn (Branch $b) => ($this->close)($b, $day)['sales'], array_values(($this->branches)()));

            expect(Money::of(($this->sum)(array_column($screens, 'total')))->toDecimal())->toBe(Money::of((string) $oracle->total)->toDecimal());
            [$start, $end] = BusinessTime::dateRange($day, $day);
            expect(SalesBasis::totals(BranchScope::all(), $start, $end)['orders'])->toBe((int) $oracle->orders);
        }
    });

    it('puts an order placed after midnight on the Qatar day it was placed, on every screen (21:30 UTC is already the next day)', function () {
        $a = $this->w->a;
        // a3b was placed on Wednesday 11 Nov at 00:30 in Qatar, which is 21:30 UTC on Tuesday the 10th; a3 on the 10th at 23:59.
        $tuesday = ($this->close)($a, '2026-11-10');
        $wednesday = ($this->close)($a, '2026-11-11');
        [$start, $end] = BusinessTime::dateRange('2026-11-11', '2026-11-11');

        expect($tuesday['orders']['placed'])->toBe(2)->and($tuesday['sales']['total'])->toBe('200.00')               // a2 at 11:00 and a3 at 23:59
            ->and($wednesday['orders']['placed'])->toBe(3)->and($wednesday['sales']['total'])->toBe('1115.00')      // a3b, the cancelled a6, and the Doha part of the shared order
            ->and(SalesBasis::perDay(BranchScope::of([$a->id]), $start->subDay(), $end)->get('2026-11-11')->total)->toBe('1115.00');
    });
});

describe('on-time delivery', function () {
    nbFixture();

    it('is the same share on the owner pack and the scorecard for every branch, and is worked out by the Qatar day', function () {
        $pack = ($this->pack)();
        $card = ($this->card)();

        foreach (['DOH', 'WKR', 'HRS'] as $code) {
            expect($card[$code]['delivery']['on_time']['value'])->toBe($pack[$code]['this']['on_time_rate'])
                ->and($card[$code]['delivery']['promised'])->toBe($pack[$code]['this']['promised'])->and($card[$code]['delivery']['on_time_count'])->toBe($pack[$code]['this']['on_time']);
        }
        // By hand: Doha had 9 deliveries with a promised day and 7 reached the customer on that day or before (a2 was a day late; a3 was delivered at 00:05 on the 11th for the 10th).
        expect([$card['DOH']['delivery']['promised'], $card['DOH']['delivery']['on_time_count'], $card['DOH']['delivery']['on_time']['value']])->toBe([9, 7, 77.8])
            ->and([$card['WKR']['delivery']['promised'], $card['WKR']['delivery']['on_time_count']])->toBe([4, 3])          // b2 had no promised day; the moved b3 was on time for its LAST slot
            ->and($card['WKR']['delivery']['no_promise'])->toBe(1)->and($card['WKR']['delivery']['moved'])->toBe(1)
            ->and($card['EMP']['delivery']['on_time']['value'])->toBeNull()->and($pack['EMP']['this']['on_time_rate'])->toBeNull();
    });

    it('gives the drivers the same rule: what the two drivers delivered with a promise, plus the deliveries nobody drove, is what the branches delivered with one', function () {
        $drivers = scDrivers($this->w, null, $this->week)['rows'];
        $card = ($this->card)();
        $driverless = DB::table('order_fulfillments as f')->where('f.type', 'delivery')->whereBetween('f.delivered_at', BusinessTime::dateRange('2026-11-09', '2026-11-15'))->whereNotNull('f.slot_date')
            ->whereNotExists(fn ($q) => $q->from('deliveries as d')->whereColumn('d.order_fulfillment_id', 'f.id')->whereNotNull('d.driver_id'))
            ->selectRaw('count(*) as promised, count(*) filter (where (f.delivered_at at time zone ?)::date <= f.slot_date) as on_time', [BusinessTime::zone()])->first();
        $promised = array_sum(array_map(fn (array $d) => $d['on_time']['promised'], $drivers));
        $onTime = array_sum(array_map(fn (array $d) => (int) round($d['on_time']['value'] * $d['on_time']['promised'] / 100), $drivers));

        expect($promised + $driverless->promised)->toBe(array_sum(array_map(fn (string $c) => $card[$c]['delivery']['promised'], ['DOH', 'WKR', 'HRS'])))
            ->and($onTime + $driverless->on_time)->toBe(array_sum(array_map(fn (string $c) => $card[$c]['delivery']['on_time_count'], ['DOH', 'WKR', 'HRS'])));
    });
});

describe('failed deliveries', function () {
    nbFixture();

    it('counts the same failures on the daily close, the pack, the scorecard and the drivers, with their reasons', function () {
        $pack = ($this->pack)();
        $card = ($this->card)();
        $closes = array_sum(array_map(fn (array $d) => $d['failed_deliveries'], ($this->days_of)($this->w->a)));
        $drivers = collect(scDrivers($this->w, null, $this->week)['rows'])->keyBy('name');

        expect($closes)->toBe(2)->and($pack['DOH']['this']['failed_deliveries'])->toBe(2)->and($card['DOH']['failed']['count'])->toBe(2)
            ->and($card['DOH']['failed']['reasons'])->toBe(['customer_absent' => 1, 'damaged' => 1])
            ->and($drivers['Dina Driver']['failed']['reasons'])->toBe(['customer_absent' => 1])->and($drivers['Omar Driver']['failed']['reasons'])->toBe(['damaged' => 1])
            ->and($card['DOH']['failed']['team'])->toBe(1)->and($card['DOH']['failed']['customer'])->toBe(1)
            ->and($card['WKR']['failed']['count'])->toBe(0)->and($pack['WKR']['this']['failed_deliveries'])->toBe(0);
    });
});

describe('cash', function () {
    nbFixture();

    it('shows the same difference found when counting on the daily close, the pack, the scorecard and the driver, whatever the name', function () {
        $pack = ($this->pack)();
        $card = ($this->card)();
        $drivers = collect(scDrivers($this->w, null, $this->week)['rows'])->keyBy('name');

        foreach (['DOH' => ['-3.00', 2, 3], 'WKR' => ['4.50', 1, 1], 'HRS' => ['0.00', 0, 0]] as $code => [$net, $notExact, $handIns]) {
            $days = ($this->days_of)(($this->branches)()[$code]);
            expect(($this->sum)(array_map(fn (array $d) => $d['cash']['variance'], $days)))->toBe($net)->and(array_sum(array_map(fn (array $d) => $d['cash']['variance_count'], $days)))->toBe($notExact)
                ->and($pack[$code]['this']['cash_variance'])->toBe($net)->and($pack[$code]['this']['cash_variance_count'])->toBe($notExact)
                ->and($card[$code]['cash']['net'])->toBe($net)->and($card[$code]['cash']['variances'])->toBe($notExact)->and($card[$code]['cash']['handins'])->toBe($handIns);
        }
        // The two drivers' hand-ins add up to the branches': Dina -5.00 and +2.00 at Doha, Omar 0.00 at Doha and +4.50 at Wakra.
        expect($drivers['Dina Driver']['cash']['net'])->toBe('-3.00')->and($drivers['Omar Driver']['cash']['net'])->toBe('4.50')
            ->and($card['DOH']['cash']['abs'])->toBe('7.00');
    });

    it('shows the same unexplained differences and the same cash still held, wherever it is asked', function () {
        $pack = app(OwnerPackData::class)->for('2026-W46');
        $card = ($this->card)();
        $drivers = collect(scDrivers($this->w, null, $this->week)['rows'])->keyBy('name');
        $day = ($this->close)($this->w->a, '2026-11-12');

        // Open differences are of ANY date: -5.00 and +3.00 at Doha (the +2.00 was explained), +4.50 at Wakra.
        expect($day['cash']['open_variances'])->toBe(2)->and($card['DOH']['cash']['open_variances'])->toBe(2)->and(($this->close)($this->w->b, '2026-11-12')['cash']['open_variances'])->toBe(1)
            ->and(collect($pack['attention'])->firstWhere('key', 'open_variances')['count'])->toBe(3)
            // Cash held: 100.00 (9 Nov), 100.00 (13 Nov) and 80.00 (today) at Doha, all with the same driver, whose card collection is not cash.
            ->and($day['cash']['still_out_total'])->toBe('280.00')->and($pack['branches'][0]['this']['cash_with_collectors'])->toBe('280.00')->and($card['DOH']['cash']['held_amount'])->toBe('280.00')
            ->and($drivers['Dina Driver']['cash']['amount'])->toBe('280.00')->and($drivers['Omar Driver']['cash']['amount'])->toBe('0.00')
            ->and($card['DOH']['cash']['held'])->toBe(3)->and($card['WKR']['cash']['held'])->toBe(0);
    });

    it('says why "Collected today" on the dashboard differs from the daily close\'s cash: the dashboard counts the card terminal, the close does not', function () {
        $m = app(DashboardMetrics::class)->for($this->w->admin);
        $cashOnly = array_sum(array_map(fn (Branch $b) => (float) ($this->close)($b, '2026-11-19')['cash']['collected'], [$this->w->a, $this->w->b]));
        $cards = (float) DB::table('cash_collections')->where('tender', 'card_terminal')->whereBetween('collected_at', BusinessTime::dateRange('2026-11-19', '2026-11-19'))->sum('amount_collected');

        expect($cashOnly)->toBe(80.0)->and($cards)->toBe(120.0)
            ->and($m['collected_cash'])->toBe(Money::of(number_format($cashOnly + $cards, 2, '.', ''))->format())      // "Cash and card" 200.00 = cash 80.00 + card 120.00
            ->and($m['collected_online'])->toBe(Money::of('250.00')->format())->and($m['collected_today'])->toBe(Money::of('450.00')->format());
    });
});

describe('orders handled, cancelled, delivered', function () {
    nbFixture();

    it('counts the same orders on the scorecard as the days of the daily close add up to', function () {
        $card = ($this->card)();
        foreach (['DOH' => [13, 1, 10], 'WKR' => [8, 2, 6], 'HRS' => [2, 0, 2], 'EMP' => [0, 0, 0]] as $code => [$placed, $cancelled, $reached]) {
            $days = ($this->days_of)(($this->branches)()[$code]);

            expect(array_sum(array_map(fn (array $d) => $d['orders']['placed'], $days)))->toBe($placed)->and($card[$code]['orders']['value'])->toBe($placed)
                ->and(array_sum(array_map(fn (array $d) => $d['orders']['cancelled'], $days)))->toBe($cancelled)->and($card[$code]['cancel']['count'])->toBe($cancelled)
                ->and(array_sum(array_map(fn (array $d) => $d['orders']['delivered'], $days)))->toBe($reached);
        }
    });

    it('is one order for the company when two branches share it: the scorecard\'s "all branches together" does not add its branches up', function () {
        $together = scBranches($this->w)['total'];
        $card = scBranches($this->w, null, $this->week);
        $each = array_sum(array_column(array_column($card['rows'], 'orders'), 'value'));
        [$start, $end] = $this->week->bounds();

        expect($card['total']['orders']['value'])->toBe($each - 1)->and($card['total']['orders']['value'])->toBe(Orders::placed(array_column($card['rows'], 'id'), $start, $end)['together'])
            ->and($together)->not->toBeNull();
    });

    it('shows a one-day scorecard exactly as the daily close of that day: orders, cancellations, failures, cash and time to accept', function () {
        $day = ScorecardPeriod::between('2026-11-09', '2026-11-09');
        $card = ($this->card)($day);
        foreach (['DOH', 'WKR', 'HRS'] as $code) {
            $close = ($this->close)(($this->branches)()[$code], '2026-11-09');

            expect($card[$code]['orders']['value'])->toBe($close['orders']['placed'])->and($card[$code]['cancel']['count'])->toBe($close['orders']['cancelled'])
                ->and($card[$code]['failed']['count'])->toBe($close['failed_deliveries'])->and($card[$code]['cash']['net'])->toBe($close['cash']['variance'])
                ->and($card[$code]['cash']['held_amount'])->toBe($close['cash']['still_out_total'])->and($card[$code]['cash']['open_variances'])->toBe($close['cash']['open_variances'])
                ->and($card[$code]['accept']['n'])->toBe($close['speed']['accepted'])->and($card[$code]['prepare']['n'])->toBe($close['speed']['prepared']);
        }
    });
});

describe('time to accept and prepare', function () {
    nbFixture();

    it('is built from the same readings on the daily close (their average) and the scorecard (their middle and slow end), in opening-hours minutes', function () {
        $hours = $this->w->c;                                                                         // opens 10:00, shuts 22:00
        $readings = app(Speed::class)->minutes([$this->w->a->id, $hours->id], ...BusinessTime::dateRange('2026-11-09', '2026-11-09'));
        $card = ($this->card)(ScorecardPeriod::between('2026-11-09', '2026-11-09'));
        $close = ($this->close)($this->w->a, '2026-11-09');

        // Doha, Monday: a1 was accepted after 5 minutes and a9 (the pickup) after 10; plain clock minutes, the branch has no hours.
        expect(collect($readings[$this->w->a->id]['accept'])->sort()->values()->all())->toBe([5.0, 10.0])->and($close['speed']['accept_minutes'])->toBe(Speed::average($readings[$this->w->a->id]['accept']))->and($close['speed']['accept_minutes'])->toBe(8)
            ->and($card['DOH']['accept']['median']['value'])->toBe(ScorecardStats::median($readings[$this->w->a->id]['accept']))->and($card['DOH']['accept']['median']['value'])->toBe(7.5)
            // The branch with hours: ordered at 21:50 on Monday, accepted at 10:10 on Tuesday = 10 + 10 opening minutes, not the 740 on the wall clock.
            ->and($readings[$hours->id]['accept'])->toBe([20.0])->and(($this->close)($hours, '2026-11-09')['speed']['accept_minutes'])->toBe(20)->and($card['HRS']['accept']['median']['value'])->toBe(20.0);
    });

    it('measures the week on the scorecard from the same parts the days of the daily close measure', function () {
        $card = ($this->card)();
        foreach (($this->branches)() as $code => $branch) {
            $days = ($this->days_of)($branch);

            expect($card[$code]['accept']['n'])->toBe(array_sum(array_map(fn (array $d) => $d['speed']['accepted'], $days)))
                ->and($card[$code]['prepare']['n'])->toBe(array_sum(array_map(fn (array $d) => $d['speed']['prepared'], $days)));
        }
    });
});

describe('refunds and claims', function () {
    nbFixture();

    it('divides a refund between branches the same way on the daily close and the sales report, which counts only the refunds the lines do not already explain', function () {
        $report = fn (?string $branch) => collect(app(ReportService::class)->run($this->w->admin, 'sales_summary', '2026-11-09', '2026-11-15', $branch)['rows'])->pluck('value', 'metric');
        $paid = fn (Branch $b) => ($this->sum)(array_map(fn (array $d) => $d['refunds']['amount'], ($this->days_of)($b)));
        $inLines = fn (Branch $b) => (float) DB::table('refunds as r')->where('r.status', 'processed')->whereIn('r.reason_code', ReportService::REFUNDS_ALREADY_IN_LINES)
            ->whereBetween('r.processed_at', BusinessTime::dateRange('2026-11-09', '2026-11-15'))->whereIn('r.order_id', DB::table('order_fulfillments')->where('branch_id', $b->id)->select('order_id'))->sum('r.amount');

        // Doha: 30.00 paid back for a return (a11). Wakra: 100.00 paid back for an order cancelled after it was paid online, money the order lines have already dropped.
        expect($paid($this->w->a))->toBe('30.00')->and($report($this->w->a->id)['refunds'])->toBe('30.00')
            ->and($paid($this->w->b))->toBe('100.00')->and($report($this->w->b->id)['refunds'])->toBe('0.00')
            ->and((float) $paid($this->w->b) - $inLines($this->w->b))->toBe((float) $report($this->w->b->id)['refunds'])
            ->and($report(null)['refunds'])->toBe('30.00');
    });

    it('pays a refund in the period it was paid, not the period of the order (a refund the day after the week is next week\'s)', function () {
        $sunday = ($this->close)($this->w->a, '2026-11-15');
        $monday = ($this->close)($this->w->a, '2026-11-16');
        $report = collect(app(ReportService::class)->run($this->w->admin, 'sales_summary', '2026-11-16', '2026-11-22', $this->w->a->id)['rows'])->pluck('value', 'metric');

        // a12 was placed and delivered on Sunday 15th (its sale is in week 46) and refunded 40.00 on Monday 16th.
        expect($sunday['sales']['total'])->toBe('100.00')->and($sunday['refunds'])->toBe(['count' => 0, 'amount' => '0.00'])
            ->and($monday['refunds'])->toBe(['count' => 1, 'amount' => '40.00'])->and($report['refunds'])->toBe('40.00');
    });

    it('opens a claim in one period and receives its goods in another: the claim counts on the pack and the scorecard, the goods on the daily close', function () {
        $pack = ($this->pack)();
        $card = ($this->card)();
        $opened = array_sum(array_map(fn (array $d) => $d['orders']['returned'], ($this->days_of)($this->w->a)));
        $received = ($this->close)($this->w->a, '2026-11-16')['orders']['returned'];

        expect($pack['DOH']['this']['claims'])->toBe(1)->and($card['DOH']['claims']['count'])->toBe(1)->and($opened)->toBe(0)->and($received)->toBe(1)
            // The rate is claims per 100 parts that reached the customer: 1 of 10 at Doha, on both.
            ->and($pack['DOH']['this']['claims_rate'])->toBe(10.0)->and($card['DOH']['claims']['rate']['value'])->toBe(10.0)
            ->and($pack['EMP']['this']['claims_rate'])->toBeNull()->and($card['EMP']['claims']['rate']['value'])->toBeNull();
    });
});

describe('stock and the live board', function () {
    nbFixture();

    it('says the same about stock on the daily close, the pack and the dashboard', function () {
        $m = app(DashboardMetrics::class)->for($this->w->admin);
        $close = ($this->close)($this->w->a, '2026-11-19');
        $pack = ($this->pack)();

        expect([$close['stock']['out'], $close['stock']['low']])->toBe([1, 1])->and($pack['DOH']['this']['stock_out'])->toBe(1)->and($m['out_of_stock'])->toBe(1)->and($m['low_stock'])->toBe(1)
            ->and(($this->close)($this->w->b, '2026-11-19')['stock'])->toBe(['out' => 0, 'low' => 0]);
    });

    it('lists as open the same parts on the live board, the dashboard and the daily close (one list of open states)', function () {
        $board = app(LiveBoard::class)->for($this->w->admin, null, LiveBoard::LANES);
        $m = app(DashboardMetrics::class)->for($this->w->admin);
        $from = collect($board->lanes)->flatMap(fn (array $lane) => $lane['cards'])->countBy(fn ($card) => $card->from);
        $openByTheClose = 0;
        $neverAccepted = 0;
        for ($day = BusinessTime::now()->subDays(20); $day->toDateString() <= '2026-11-19'; $day = $day->addDay()) {
            foreach (($this->branches)() as $branch) {
                $close = ($this->close)($branch, $day->toDateString());
                $openByTheClose += $close['open']['open'];
                $neverAccepted += $close['open']['never_accepted'];
            }
        }

        expect(Orders::OPEN_STATUSES)->toBe(LiveBoard::OPEN)
            ->and($board->open)->toBe($openByTheClose)                                                       // every open part was placed on a day the close can look at
            ->and($from->get('new', 0) + $from->get('preparing', 0))->toBe($m['pending_fulfillments'])        // "to prepare" on the dashboard is the board's new and preparing, whichever lane a late one moved to
            ->and($from->get('pickup', 0))->toBe($m['ready_for_pickup'])
            ->and($from->get('new', 0))->toBe($neverAccepted);
    });
});

describe('customers and offers', function () {
    nbFixture();

    it('gives a customer the same lifetime spend as the sales of their orders, and a branch the part of it that is the branch\'s, adding up to the whole', function () {
        $value = app(CustomerValue::class);
        $value->refresh();
        $all = $value->lifetimeOf($this->w->admin, $this->w->cust1->id)['spend'];
        $own = fn (Branch $b) => $value->lifetime(BranchScope::of([$b->id]), [$this->w->cust1->id])[$this->w->cust1->id]['spend'] ?? '0.00';
        $rollup = DB::table('customer_value_rollups')->where('user_id', $this->w->cust1->id)->where('scope', 'all')->value('spend');
        $oracle = DB::table('orders')->where('user_id', $this->w->cust1->id)->whereNotIn('status', ['cancelled', 'pending_payment'])->sum('grand_total');

        // a11 100.00 + a12 100.00 + a14 850.00 (800.00 of goods and 50.00 of assembly) + the shared order 1,290.00 = 2,340.00; Doha's part of it 2,065.00, Wakra's 275.00.
        expect($all)->toBe('2340.00')->and(Money::of((string) $rollup)->toDecimal())->toBe('2340.00')->and(Money::of((string) $oracle)->toDecimal())->toBe('2340.00')
            ->and($own($this->w->a))->toBe('2065.00')->and($own($this->w->b))->toBe('275.00')->and(Money::of($own($this->w->a))->plus(Money::of($own($this->w->b)))->toDecimal())->toBe($all);
    });

    it('books an offer\'s orders at what the dashboard books them at, and leaves out an order that was cancelled', function () {
        $offers = app(OfferReturn::class)->report('2026-11-09', '2026-11-15');
        $coupon = collect($offers['offers'])->firstWhere('label_en', 'NUMBERS10');
        [$start, $end] = BusinessTime::dateRange('2026-11-12', '2026-11-12');
        $assemblyOrder = SalesBasis::orderShares($start, $end)->where('s.order_id', $this->x['a14']['order'])->sum('s.share');

        // The 10.00 coupon on the cancelled order a6 is no sale and no return; the 85.00 on a14 (850.00 booked, 800.00 of goods and 50.00 of assembly) is.
        expect($coupon['orders'])->toBe(1)->and($coupon['booked'])->toBe('850.00')->and(Money::of((string) $assemblyOrder)->toDecimal())->toBe('850.00')
            ->and($coupon['discount'])->toBe('85.00')->and($offers['overall']['booked'])->toBe('850.00')->and($offers['overall']['discount'])->toBe('85.00');
    });
});

describe('the minimum sample and a branch with nothing', function () {
    nbFixture();

    it('leaves the number underneath the same when the scorecard says "not enough data yet": the cases stay counted', function () {
        app(ScorecardSettings::class)->setMinSample(10);
        $card = ($this->card)();
        $pack = ($this->pack)();

        // 9 deliveries with a promised day is fewer than the minimum of 10: the scorecard shows no percentage, the pack (which has no minimum) shows 77.8, and both count 7 of 9.
        expect($card['DOH']['delivery']['on_time']['state'])->toBe(ScorecardStats::FEW)->and($card['DOH']['delivery']['on_time']['value'])->toBeNull()->and($card['DOH']['delivery']['on_time']['n'])->toBe(9)
            ->and($card['DOH']['delivery']['promised'])->toBe(9)->and($card['DOH']['delivery']['on_time_count'])->toBe(7)
            ->and($pack['DOH']['this']['on_time_rate'])->toBe(77.8)->and($pack['DOH']['this']['promised'])->toBe(9)->and($pack['DOH']['this']['on_time'])->toBe(7)
            ->and($card['DOH']['orders']['value'])->toBe(13);                                                // a count is a fact: always shown
    });

    it('is a branch with nothing on every screen: zeros, no average, no rate, and nothing invented', function () {
        $empty = $this->w->d;
        $close = ($this->close)($empty, '2026-11-11');
        $card = ($this->card)()['EMP'];
        $pack = ($this->pack)()['EMP']['this'];

        expect($close['sales'])->toBe(['total' => '0.00', 'orders' => 0, 'average' => '0.00'])->and($close['speed']['accept_minutes'])->toBeNull()->and($close['orders'])->toBe(['placed' => 0, 'delivered' => 0, 'cancelled' => 0, 'returned' => 0])
            ->and($pack['sales'])->toBe('0.00')->and($pack['on_time_rate'])->toBeNull()->and($pack['claims_rate'])->toBeNull()
            ->and($card['orders']['value'])->toBe(0)->and($card['accept']['median']['state'])->toBe(ScorecardStats::NONE)->and($card['delivery']['on_time']['state'])->toBe(ScorecardStats::NONE)
            ->and($card['cash']['held_amount'])->toBe('0.00');
    });

});

describe('a world with one order', function () {
    it('is the same single order on every screen: a day, a week and a scorecard period each say 1', function () {
        $one = nbDelivered($this->w->a, '2026-11-10 10:00', '2026-11-10 14:00', '2026-11-10', null, '250.00');

        $close = ($this->close)($this->w->a, '2026-11-10');
        $pack = ($this->pack)()['DOH'];
        $card = ($this->card)()['DOH'];

        expect($close['sales'])->toBe(['total' => '250.00', 'orders' => 1, 'average' => '250.00'])->and($close['orders']['placed'])->toBe(1)->and($close['orders']['delivered'])->toBe(1)
            ->and($pack['this']['sales'])->toBe('250.00')->and($pack['this']['orders'])->toBe(1)->and($pack['this']['average'])->toBe('250.00')->and($pack['this']['on_time_rate'])->toBe(100.0)
            ->and($card['orders']['value'])->toBe(1)->and($card['delivery']['promised'])->toBe(1)->and($card['delivery']['on_time_count'])->toBe(1)
            ->and($card['accept']['n'])->toBe(1)->and($card['accept']['median']['value'])->toBe(5.0)->and($close['speed']['accept_minutes'])->toBe(5)
            ->and($one['order'])->not->toBeEmpty();
    });
});

describe('the same board of the daily close', function () {
    nbFixture();

    it('counts the day\'s orders once for the shown branches together, as the dashboard does, while no shown branch is closed', function () {
        $service = app(DailyCloseService::class);
        $board = $service->board($this->w->admin, '2026-11-11');
        $perBranch = array_sum(array_map(fn (array $b) => $b['numbers']['sales']['orders'], $board));
        [$start, $end] = BusinessTime::dateRange('2026-11-11', '2026-11-11');

        // 11 Nov: Doha 2 orders, Wakra 2, the branch with hours 1, one of them the shared order: 4 orders for the company, though the branches' own counts add up to 5.
        expect($perBranch)->toBe(5)->and(DailyCloseService::ordersOf($board, '2026-11-11'))->toBe(4)->and(SalesBasis::totals(BranchScope::all(), $start, $end)['orders'])->toBe(4);
    });
});

describe('who sees which branches', function () {
    nbFixture();

    /** A branch manager at each of the branches given. */
    $manager = function (Branch ...$branches) {
        $user = staffWithRole('branch_manager', $branches[0]);
        foreach (array_slice($branches, 1) as $branch) {
            BranchEmployee::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'is_active' => true]);
            UserRole::create(['user_id' => $user->id, 'role_id' => Role::where('key', 'branch_manager')->value('id'), 'branch_id' => $branch->id]);
        }

        return $user;
    };

    it('gives a manager of two branches a company line of those two only, with the order they share counted once', function () use ($manager) {
        $card = scBranches($this->w, $manager($this->w->a, $this->w->b), $this->week);

        // Doha handled 13 orders and Wakra 8, one of them the same order: 20 together. The branch with hours is not theirs and is in nothing.
        expect(collect($card['rows'])->pluck('code')->all())->toBe(['DOH', 'WKR'])->and($card['total']['orders']['value'])->toBe(20)
            ->and($card['total']['delivery']['promised'])->toBe(13)->and($card['total']['delivery']['on_time_count'])->toBe(10);
    });

    it('gives a manager of one branch that branch only, its part of the shared order included, and no company line', function () use ($manager) {
        $card = scBranches($this->w, $manager($this->w->b), $this->week);

        expect(collect($card['rows'])->pluck('code')->all())->toBe(['WKR'])->and($card['total'])->toBeNull()->and($card['rows'][0]['orders']['value'])->toBe(8)
            ->and($card['rows'][0]['delivery']['promised'])->toBe(4);
    });

    it('shows the daily close of a manager only the branches they run, and counts a shared order once for those', function () use ($manager) {
        $board = app(DailyCloseService::class)->board($manager($this->w->a, $this->w->b), '2026-11-11');

        // 11 Nov: Doha 2 orders, Wakra 2, one shared: 3 for the two of them (the branch with hours and its order are not shown).
        expect(array_map(fn (array $b) => $b['branch']->code, $board))->toBe(['DOH', 'WKR'])->and(DailyCloseService::ordersOf($board, '2026-11-11'))->toBe(3);
    });

    it('shows a manager nothing of a branch that is not theirs: the drivers of another branch, and no branch filter that widens it', function () use ($manager) {
        $viewer = $manager($this->w->c);                                                                   // only the branch with hours
        $branches = scBranches($this->w, $viewer, $this->week, $this->w->a->id);                        // asks for Doha: narrowed to nothing, never widened
        $drivers = scDrivers($this->w, $viewer, $this->week);

        expect($branches['rows'])->toBe([])->and($drivers['rows'])->toBe([])->and(scBranches($this->w, $viewer, $this->week)['rows'][0]['orders']['value'])->toBe(2);
    });
});
