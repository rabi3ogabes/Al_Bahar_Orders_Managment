<?php

use App\Domain\Identity\Support\BranchScope;
use App\Domain\Reports\Services\DailyCloseNumbers;
use App\Domain\Reports\Services\Metrics\Assembly;
use App\Domain\Reports\Services\OwnerPackData;
use App\Domain\Reports\Services\OwnerPackPresenter;
use App\Domain\Reports\Services\ReportService;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/NumbersSupport.php';

/*
 | Assembly through the shared metrics layer (docs/guides/NUMBER_DEFINITIONS.md): the same branch and day read the same on the Assembly sales report, the daily
 | close and the weekly owner pack. The fixture's Doha order a14 (placed 12 Nov, 800.00 of goods and 50.00 of assembly) gets its per-unit fee here; the shared
 | fixture is left alone so the golden recording keeps meaning what it meant.
 */

beforeEach(function () {
    $this->w = nbWorld();
    $this->x = nbFill($this->w);
    DB::table('order_items')->where('order_id', $this->x['a14']['order'])->update(['assembly_fee' => '50.00', 'quantity' => 1]);
    $this->close = fn (string $branchId, string $day) => app(DailyCloseNumbers::class)->for($branchId, $day)['assembly'];
    $this->pack = fn () => collect(app(OwnerPackData::class)->for('2026-W46')['branches'])->keyBy('code');
});

it('gives the daily close, the owner pack and the sales report the same assembly for the same branch and day', function () {
    DB::table('order_fulfillments')->where('order_id', $this->x['a14']['order'])->update(['assembly_status' => 'not_done', 'assembly_reason' => 'no_time', 'assembly_answered_at' => now()]);
    [$from, $to] = BusinessTime::dateRange('2026-11-12', '2026-11-12');
    $report = app(ReportService::class)->run($this->w->admin, 'sales_assembly', '2026-11-12', '2026-11-12');
    $doha = collect($report['rows'])->firstWhere('branch', 'DOH');
    $close = ($this->close)($this->w->a->id, '2026-11-12');
    $pack = ($this->pack)()['DOH']['this'];

    expect($close)->toBe(['net' => '50.00', 'jobs' => 1, 'not_done' => 1])
        ->and($doha['net'])->toBe($close['net'])->and($doha['jobs'])->toBe($close['jobs'])
        ->and($pack['assembly_net'])->toBe($close['net'])->and($pack['assembly_jobs'])->toBe($close['jobs'])->and($pack['assembly_not_done'])->toBe($close['not_done'])
        ->and(Assembly::byBranch([$this->w->a->id], $from, $to)[$this->w->a->id]['net']->toDecimal())->toBe('50.00');
});

it('adds the days of the week up to the pack, and reads zero for a branch with nothing', function () {
    $days = array_map(fn (int $d) => sprintf('2026-11-%02d', $d), range(9, 15));
    $jobs = array_sum(array_map(fn (string $day) => ($this->close)($this->w->a->id, $day)['jobs'], $days));

    expect($jobs)->toBe(1)->and(($this->pack)()['DOH']['this']['assembly_jobs'])->toBe($jobs)
        ->and(($this->close)($this->w->d->id, '2026-11-12'))->toBe(['net' => '0.00', 'jobs' => 0, 'not_done' => 0])
        ->and(app(OwnerPackData::class)->for('2026-W46')['total']['this']['assembly_net'])->toBe('50.00');
});

it('counts a job as not done only when the driver said so, and not after the units came back', function () {
    $a = $this->w->a->id;
    [$from, $to] = BusinessTime::dateRange('2026-11-12', '2026-11-12');
    expect(Assembly::byBranch([$a], $from, $to)[$a]['not_done'])->toBe(0);              // pending: no answer yet

    DB::table('order_fulfillments')->where('order_id', $this->x['a14']['order'])->update(['assembly_status' => 'done']);
    expect(Assembly::byBranch([$a], $from, $to)[$a]['not_done'])->toBe(0);             // done

    DB::table('order_fulfillments')->where('order_id', $this->x['a14']['order'])->update(['assembly_status' => 'not_done']);
    expect(Assembly::byBranch([$a], $from, $to)[$a]['not_done'])->toBe(1);

    DB::table('order_items')->where('order_id', $this->x['a14']['order'])->update(['quantity_handed_over' => 1, 'quantity_returned' => 1]);   // the unit came back: no visit left, nothing to count
    $after = Assembly::byBranch([$a], $from, $to)[$a];
    expect($after['not_done'])->toBe(0)->and($after['jobs'])->toBe(0)->and($after['net']->toDecimal())->toBe('0.00');
});

it('never shows another branch\'s assembly to a user limited to one branch', function () {
    [$from, $to] = BusinessTime::dateRange('2026-11-12', '2026-11-12');

    expect(Assembly::totals(BranchScope::of([$this->w->b->id]), $from, $to)['net']->toDecimal())->toBe('0.00')
        ->and(Assembly::totals(BranchScope::of([$this->w->a->id]), $from, $to)['jobs'])->toBe(1);
});

it('puts assembly on the owner pack in English and Arabic without a customer or an order number', function () {
    $data = app(OwnerPackData::class)->for('2026-W46');
    $en = OwnerPackPresenter::present($data, 'en');
    $ar = OwnerPackPresenter::present($data, 'ar');

    expect($en['assembly']['title'])->toBe('Assembly')->and($ar['assembly']['title'])->toBe('التركيب')
        ->and($en['assembly']['rows'])->toHaveCount(count($data['branches']) + 1)
        ->and(collect($en['assembly']['rows'])->last()['cells'][1])->toBe('1')
        ->and(OwnerPackPresenter::text($en))->toContain('Assembly')
        ->and(OwnerPackPresenter::text($en))->not->toContain($this->x['a14']['number'] ?? 'no-such-number');
});
