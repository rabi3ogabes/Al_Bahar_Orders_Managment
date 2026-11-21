<?php

use App\Domain\Branches\Services\BranchService;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Reports\Models\DailyClose;
use App\Domain\Reports\Services\OwnerPackData;
use App\Domain\Reports\Services\OwnerPackPresenter;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;

require_once __DIR__.'/OwnerNumbersSupport.php';

beforeEach(function () {
    $this->world = onWorld();                        // Thursday 19 Nov 2026: week 47 is running, week 46 (9 to 15 Nov) was the last one to finish
    $this->data = app(OwnerPackData::class);
    $this->at = fn (string $local) => CarbonImmutable::parse($local, 'Asia/Qatar')->utc();
    $this->pack = fn (string $week = '2026-W46') => $this->data->for($week);
    $this->line = fn (array $pack, string $code) => collect($pack['branches'])->firstWhere('code', $code);
});

describe('the weeks', function () {
    it('names the week that just finished, and the days of any week', function () {
        expect(OwnerPackData::lastCompletedWeek())->toBe('2026-W46')->and(OwnerPackData::bounds('2026-W46'))->toBe(['2026-11-09', '2026-11-15'])
            ->and(OwnerPackData::bounds('2026-W01'))->toBe(['2025-12-29', '2026-01-04']);                    // an ISO week can start in the year before
        $this->travelTo(CarbonImmutable::parse('2026-11-22 23:30', 'Asia/Qatar'));                           // Sunday night: week 47 is not over yet
        expect(OwnerPackData::lastCompletedWeek())->toBe('2026-W46');
        $this->travelTo(CarbonImmutable::parse('2026-11-23 00:30', 'Asia/Qatar'));
        expect(OwnerPackData::lastCompletedWeek())->toBe('2026-W47');
    });

    it('refuses a week that does not exist', function () {
        foreach (['2026-W54', '2026-W00', '2026-46', 'W46', '', '2026-W9'] as $bad) {
            expect(fn () => OwnerPackData::bounds($bad))->toThrow(DomainException::class);
        }
    });

    it('knows which years have 53 weeks', function () {
        expect(OwnerPackData::bounds('2026-W53'))->toBe(['2026-12-28', '2027-01-03'])->and(fn () => OwnerPackData::bounds('2025-W53'))->toThrow(DomainException::class);
    });

    it('works out a percent change, and none when there was nothing to compare with', function () {
        expect(OwnerPackData::change('150.00', '100.00'))->toBe(50.0)->and(OwnerPackData::change('50.00', '100.00'))->toBe(-50.0)->and(OwnerPackData::change(10, 0))->toBeNull();
    });
});

describe('the figures, week against week, branch by branch', function () {
    it('gives each branch its sales, orders and average basket against the week before, and the company the sum', function () {
        $w = $this->world;
        onOrder([[$w->a, '100.00']], ['placed_at' => ($this->at)('2026-11-10 12:00')]);                      // week 46
        onOrder([[$w->a, '300.00']], ['placed_at' => ($this->at)('2026-11-14 12:00')]);
        onOrder([[$w->b, '50.00']], ['placed_at' => ($this->at)('2026-11-11 12:00')]);
        onOrder([[$w->a, '200.00']], ['placed_at' => ($this->at)('2026-11-03 12:00')]);                      // week 45
        onOrder([[$w->a, '900.00']], ['placed_at' => ($this->at)('2026-11-12 12:00'), 'status' => 'cancelled', 'confirmed_at' => now()]);   // not a sale
        onOrder([[$w->a, '77.00']], ['placed_at' => ($this->at)('2026-11-16 12:00')]);                       // the running week: not in 46

        $pack = ($this->pack)();
        $doh = ($this->line)($pack, 'DOH');
        $wkr = ($this->line)($pack, 'WKR');

        expect([$doh['this']['sales'], $doh['this']['orders'], $doh['this']['average']])->toBe(['400.00', 2, '200.00'])
            ->and([$doh['prev']['sales'], $doh['prev']['orders']])->toBe(['200.00', 1])
            ->and([$wkr['this']['sales'], $wkr['prev']['sales']])->toBe(['50.00', '0.00'])
            ->and([$pack['total']['this']['sales'], $pack['total']['this']['orders'], $pack['total']['this']['average']])->toBe(['450.00', 3, '150.00'])
            ->and(OwnerPackData::change($doh['this']['sales'], $doh['prev']['sales']))->toBe(100.0)->and(OwnerPackData::change($wkr['this']['sales'], $wkr['prev']['sales']))->toBeNull();
    });

    it('is zeros, not errors, for a company with nothing in the week', function () {
        $pack = ($this->pack)();

        expect($pack['total']['this']['sales'])->toBe('0.00')->and($pack['total']['this']['orders'])->toBe(0)->and($pack['total']['this']['average'])->toBe('0.00')
            ->and($pack['total']['this']['on_time_rate'])->toBeNull()->and($pack['total']['this']['claims_rate'])->toBeNull()->and($pack['top_units'])->toBe([])->and($pack['top_revenue'])->toBe([]);
    });

    it('measures delivery performance as the share kept of the promised day, and claims per 100 delivered', function () {
        $w = $this->world;
        foreach ([['2026-11-10', '2026-11-10 14:00'], ['2026-11-11', '2026-11-12 10:00'], ['2026-11-12', '2026-11-11 09:00'], ['2026-11-13', '2026-11-13 23:00']] as [$promised, $delivered]) {
            $o = onOrder([[$w->a, '10.00', '0.00', 'delivered']], ['placed_at' => ($this->at)('2026-11-09 09:00')]);
            $o->fulfillments->first()->update(['slot_date' => $promised, 'delivered_at' => ($this->at)($delivered)]);
        }
        $collected = onOrder([[$w->a, '10.00', '0.00', 'picked_up']], ['placed_at' => ($this->at)('2026-11-09 09:00')]);       // collected, no promised day
        $collected->fulfillments->first()->update(['picked_up_at' => ($this->at)('2026-11-12 11:00')]);
        DB::table('return_requests')->insert(['id' => (string) Str::uuid(), 'number' => 'RR1', 'order_id' => $collected->id, 'user_id' => $w->customer->id, 'branch_id' => $w->a->id,
            'status' => 'requested', 'reason_code' => 'damaged', 'items' => '[]', 'created_at' => ($this->at)('2026-11-13 10:00'), 'updated_at' => now()]);

        $doh = ($this->line)(($this->pack)(), 'DOH')['this'];

        expect([$doh['reached'], $doh['promised'], $doh['on_time'], $doh['on_time_rate']])->toBe([5, 4, 3, 75.0])           // one of four was a day late
            ->and([$doh['claims'], $doh['claims_rate']])->toBe([1, 20.0]);
    });

    it('reports stock-outs now, the cash difference of the week, and the days nobody closed', function () {
        $w = $this->world;
        [$v] = onVariants(1);
        $ledger = app(InventoryLedger::class);
        $ledger->apply($w->a->id, $v->id, MovementType::OpeningBalance, 2);
        $ledger->apply($w->a->id, $v->id, MovementType::SaleDeduction, -2);
        DB::table('cash_handovers')->insert(['id' => (string) Str::uuid(), 'number' => 'CH1', 'branch_id' => $w->a->id, 'collector_id' => $w->admin->id, 'received_by' => $w->customer->id,
            'collections_count' => 1, 'expected_amount' => '100.00', 'counted_amount' => '90.00', 'variance' => '-10.00', 'status' => 'short', 'variance_status' => 'open',
            'created_at' => ($this->at)('2026-11-12 09:00'), 'updated_at' => now()]);
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'] as $day) {          // closed six of seven days
            DailyClose::create(['branch_id' => $w->a->id, 'business_date' => $day, 'closed_by' => $w->admin->id, 'closed_at' => now(), 'numbers' => [], 'flags' => []]);
        }

        $pack = ($this->pack)();
        $doh = ($this->line)($pack, 'DOH')['this'];
        $wkr = ($this->line)($pack, 'WKR')['this'];

        expect($doh['stock_out'])->toBe(1)->and($doh['cash_variance'])->toBe('-10.00')->and($doh['cash_variance_count'])->toBe(1)->and($doh['unclosed_days'])->toBe(1)
            ->and($wkr['unclosed_days'])->toBe(7)->and($pack['total']['this']['unclosed_days'])->toBe(8);
    });

    it('does not report a day the branch was shut and nothing happened on, but does when something did', function () {
        $w = $this->world;
        $hours = fn ($closedDay) => array_map(fn ($d) => $d === $closedDay ? ['day_of_week' => $d, 'is_closed' => true] : ['day_of_week' => $d, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '21:00'], range(0, 6));
        app(BranchService::class)->setWeeklyHours($w->a, $hours(5), audit: false);                 // shut on Fridays (13 Nov)
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-14', '2026-11-15'] as $day) {                       // the six open days are closed
            DailyClose::create(['branch_id' => $w->a->id, 'business_date' => $day, 'closed_by' => $w->admin->id, 'closed_at' => now(), 'numbers' => [], 'flags' => []]);
        }

        expect(($this->line)(($this->pack)(), 'DOH')['this']['unclosed_days'])->toBe(0);                                         // Friday: shut, no order, nothing to close

        onOrder([[$w->a, '20.00', '0.00', 'delivered']], ['placed_at' => ($this->at)('2026-11-13 11:00')]);                           // a phone order came in on the shut day
        expect(($this->line)(($this->pack)(), 'DOH')['this']['unclosed_days'])->toBe(1);
    });

    it('names the five best products by units and by revenue, with a steady order for ties, and never a customer', function () {
        $w = $this->world;
        $line = function (string $sku, string $name, int $qty, string $unit) use ($w) {
            $o = onOrder([[$w->a, '1.00']], ['placed_at' => ($this->at)('2026-11-10 12:00')]);
            OrderItem::where('order_id', $o->id)->update(['sku' => $sku, 'name_en' => $name, 'name_ar' => 'منتج '.$name, 'quantity' => $qty, 'unit_final_price' => $unit,
                'line_subtotal' => Money::of($unit)->times($qty)->toDecimal(), 'line_total' => Money::of($unit)->times($qty)->toDecimal()]);
        };
        $line('S1', 'Sofa', 1, '2500.00');
        $line('S2', 'Cushion', 30, '20.00');
        $line('S3', 'Table', 4, '500.00');
        $line('S4', 'Lamp', 30, '15.00');                                  // ties Cushion on units
        foreach (range(5, 8) as $n) {
            $line('S'.$n, 'Item '.$n, 2, '10.00');
        }

        $pack = ($this->pack)();

        expect(array_column($pack['top_units'], 'name_en'))->toBe(['Cushion', 'Lamp', 'Table', 'Item 5', 'Item 6'])                 // equal units: more revenue first, then name order of the key
            ->and(array_column($pack['top_revenue'], 'name_en'))->toBe(['Sofa', 'Table', 'Cushion', 'Lamp', 'Item 5'])
            ->and($pack['top_units'][0])->toBe(['name_en' => 'Cushion', 'name_ar' => 'منتج Cushion', 'units' => 30, 'revenue' => '600.00'])
            ->and(count($pack['top_units']))->toBe(5)->and(json_encode($pack))->not->toContain('Noor Ali');
    });

    it('lists what needs attention, only what exists, in the order an owner would act on it', function () {
        $w = $this->world;
        onOrder([[$w->a, '500.00']], ['placed_at' => ($this->at)('2026-11-03 12:00')]);                      // week 45: 500
        onOrder([[$w->a, '100.00', '0.00', 'pending']], ['placed_at' => ($this->at)('2026-11-10 12:00')]);   // week 46: 100 (down 80%) and nobody accepted it
        $keys = fn () => array_column(($this->pack)()['attention'], 'key');

        expect($keys())->toBe(['unclosed_days', 'never_accepted', 'sales_down']);
        $sales = collect(($this->pack)()['attention'])->firstWhere('key', 'sales_down');
        expect($sales)->toBe(['key' => 'sales_down', 'count' => 1, 'branches' => ['DOH']]);

        foreach ([$w->a, $w->b] as $b) {
            foreach (range(9, 15) as $d) {
                DailyClose::create(['branch_id' => $b->id, 'business_date' => '2026-11-'.str_pad((string) $d, 2, '0', STR_PAD_LEFT), 'closed_by' => $w->admin->id, 'closed_at' => now(), 'numbers' => [], 'flags' => []]);
            }
        }
        DB::table('order_fulfillments')->update(['status' => 'delivered']);
        expect($keys())->toBe(['sales_down']);
    });
});

describe('the words', function () {
    it('writes the change against the week before with an arrow and a signed number, and says whether it is good news', function () {
        $up = OwnerPackPresenter::cell('sales', ['sales' => '150.00'], ['sales' => '100.00'], 'en');
        $down = OwnerPackPresenter::cell('sales', ['sales' => '50.00'], ['sales' => '100.00'], 'en');
        $claimsUp = OwnerPackPresenter::cell('claims_rate', ['claims_rate' => 4.0], ['claims_rate' => 2.0], 'en');
        $onTimeUp = OwnerPackPresenter::cell('on_time_rate', ['on_time_rate' => 95.0], ['on_time_rate' => 90.0], 'en');
        $none = OwnerPackPresenter::cell('sales', ['sales' => '10.00'], ['sales' => '0.00'], 'en');

        expect([$up['value'], $up['change'], $up['tone']])->toBe(['QAR 150.00', '▲ +50.0%', 'good'])->and([$down['change'], $down['tone']])->toBe(['▼ -50.0%', 'bad'])
            ->and([$claimsUp['value'], $claimsUp['change'], $claimsUp['tone']])->toBe(['4%', '▲ +2.0 points', 'bad'])->and($onTimeUp['tone'])->toBe('good')
            ->and([$none['change'], $none['tone']])->toBe([null, 'none']);
    });

    it('builds the same pack in English and Arabic, and the plain text holds figures only', function () {
        $w = $this->world;
        onOrder([[$w->a, '250.00']], ['placed_at' => ($this->at)('2026-11-10 12:00')]);
        $data = ($this->pack)();

        $en = OwnerPackPresenter::present($data, 'en');
        $ar = OwnerPackPresenter::present($data, 'ar');
        $text = OwnerPackPresenter::text($en);

        expect($en['dir'])->toBe('ltr')->and($ar['dir'])->toBe('rtl')->and($en['title'])->toBe('Weekly owner pack')->and($ar['title'])->toBe('حزمة المالك الأسبوعية')
            ->and($en['rows'][0]['name'])->toBe('Doha')->and($en['headline'][0]['value'])->toBe('QAR 250.00')->and($ar['headline'][0]['value'])->toBe('250.00 ر.ق')
            ->and($text)->toContain('QAR 250.00')->toContain('Weekly owner pack')->and($ar['period'])->toContain('الأسبوع 46');
    });
});
