<?php

use App\Domain\Reports\Services\ScorecardCauses;
use App\Domain\Reports\Services\ScorecardFormat;
use App\Domain\Reports\Services\ScorecardPeriod;
use App\Domain\Reports\Services\ScorecardStats;
use App\Support\DomainException;
use Carbon\CarbonImmutable;

/**
 * The arithmetic under every scorecard number, checked by hand: percentiles, rates, the minimum sample, the change against the period before, the
 * periods themselves, and who a failed delivery is the fault of.
 */
describe('the median and the slow end', function () {
    it('takes the middle order, not the average', function () {
        // One slow order moves an average a long way and a median not at all.
        expect(ScorecardStats::median([1, 2, 3, 100]))->toBe(2.5)->and(array_sum([1, 2, 3, 100]) / 4)->toBe(26.5)
            ->and(ScorecardStats::median([10, 10, 10, 10, 100]))->toBe(10.0)
            ->and(ScorecardStats::median([30, 10, 20]))->toBe(20.0);                         // in any order
    });

    it('is the middle of the two middle orders for an even number of them (PostgreSQL percentile_cont)', function () {
        expect(ScorecardStats::median([10, 20, 30, 40]))->toBe(25.0);
    });

    it('finds the slowest tenth by stepping between neighbours', function () {
        expect(ScorecardStats::p90([10, 10, 10, 10, 100]))->toBe(64.0)                          // rank 3.6: 10 + 0.6 * (100 - 10)
            ->and(ScorecardStats::p90([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]))->toBe(9.1);
    });

    it('gives the same number for ties, one reading, and nothing for no readings', function () {
        expect(ScorecardStats::median([5, 5, 5]))->toBe(5.0)->and(ScorecardStats::p90([5, 5, 5]))->toBe(5.0)
            ->and(ScorecardStats::median([7]))->toBe(7.0)->and(ScorecardStats::p90([7]))->toBe(7.0)
            ->and(ScorecardStats::median([]))->toBeNull()->and(ScorecardStats::p90([]))->toBeNull();
    });
});

describe('rates and the minimum sample', function () {
    it('is a share with one decimal, and nothing when there is nothing to divide by', function () {
        expect(ScorecardStats::rate(1, 3))->toBe(33.3)->and(ScorecardStats::rate(2, 3))->toBe(66.7)->and(ScorecardStats::rate(0, 5))->toBe(0.0)->and(ScorecardStats::rate(0, 0))->toBeNull();
    });

    it('shows a number only with enough cases: the boundary is the minimum itself', function () {
        expect(ScorecardStats::state(0, 10))->toBe('none')->and(ScorecardStats::state(1, 10))->toBe('few')->and(ScorecardStats::state(9, 10))->toBe('few')
            ->and(ScorecardStats::state(10, 10))->toBe('ok')->and(ScorecardStats::state(11, 10))->toBe('ok')
            ->and(ScorecardStats::state(1, 1))->toBe('ok');
    });

    it('hides the value of a number built from too few cases but keeps how many there are', function () {
        $few = ScorecardStats::metric(66.7, 3, 50.0, 40, 10);

        expect($few['value'])->toBeNull()->and($few['n'])->toBe(3)->and($few['state'])->toBe('few')->and($few['change'])->toBeNull()
            ->and($few['previous'])->toBe(50.0);
    });

    it('gives the change against the period before only when both periods had enough cases', function () {
        $both = ScorecardStats::metric(80.0, 20, 75.5, 15, 10);
        $beforeFew = ScorecardStats::metric(80.0, 20, 100.0, 4, 10);
        $beforeNone = ScorecardStats::metric(80.0, 20, null, 0, 10);

        expect($both['change'])->toBe(4.5)->and($both['state'])->toBe('ok')
            ->and($beforeFew['change'])->toBeNull()->and($beforeFew['previous'])->toBeNull()->and($beforeFew['previous_state'])->toBe('few')
            ->and($beforeNone['change'])->toBeNull()->and($beforeNone['previous_state'])->toBe('none');
    });

    it('counts a plain number as a fact: always shown, with its difference and percent', function () {
        expect(ScorecardStats::count(12, 10))->toBe(['value' => 12, 'previous' => 10, 'change' => 2, 'change_percent' => 20.0])
            ->and(ScorecardStats::count(0, 0))->toBe(['value' => 0, 'previous' => 0, 'change' => 0, 'change_percent' => null])
            ->and(ScorecardStats::count(3, 0)['change_percent'])->toBeNull();
    });

    it('names the direction of a change without judging it', function () {
        expect(ScorecardStats::direction(2.5))->toBe('up')->and(ScorecardStats::direction(-0.1))->toBe('down')->and(ScorecardStats::direction(0))->toBe('same')->and(ScorecardStats::direction(null))->toBeNull();
    });
});

describe('the period', function () {
    beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Qatar')));

    it('is made of complete days ending yesterday, and the period before it has the same length and touches it', function () {
        $p = ScorecardPeriod::last(7);

        expect([$p->from, $p->to, $p->days()])->toBe(['2026-11-12', '2026-11-18', 7])
            ->and([$p->previous()->from, $p->previous()->to, $p->previous()->days()])->toBe(['2026-11-05', '2026-11-11', 7])
            ->and($p->includesToday())->toBeFalse()->and($p->preset())->toBe(7)
            ->and(ScorecardPeriod::last(30)->previous()->days())->toBe(30)->and(ScorecardPeriod::last(90)->preset())->toBe(90);
    });

    it('lets a custom range end today and says so, and has no preset', function () {
        $p = ScorecardPeriod::between('2026-11-10', '2026-11-19');

        expect($p->days())->toBe(10)->and($p->includesToday())->toBeTrue()->and($p->preset())->toBeNull()->and($p->previous()->to)->toBe('2026-11-09');
    });

    it('takes a day in Qatar, not in UTC: just after midnight there is already tomorrow', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 01:00:00', 'Asia/Qatar'));          // 22:00 UTC on the 18th

        expect(ScorecardPeriod::last(1)->to)->toBe('2026-11-18')->and(ScorecardPeriod::between('2026-11-19', '2026-11-19')->days())->toBe(1);
    });

    it('accepts a year of exactly 366 days and refuses one day more', function () {
        $p = ScorecardPeriod::between('2025-11-19', '2026-11-19');

        expect($p->days())->toBe(366)->and(fn () => ScorecardPeriod::between('2025-11-18', '2026-11-19'))->toThrow(DomainException::class);
    });

    it('refuses a made-up day, a range that runs backwards and one that ends tomorrow', function (string $from, string $to) {
        expect(fn () => ScorecardPeriod::between($from, $to))->toThrow(DomainException::class);
    })->with([['2026-13-45', '2026-11-18'], ['yesterday', '2026-11-18'], ['2026-11-18', '2026-11-12'], ['2026-11-12', '2026-11-20'], ['', '']]);

    it('sizes the window in UTC from the Qatar days', function () {
        [$start, $end] = ScorecardPeriod::between('2026-11-12', '2026-11-18')->bounds();

        expect($start->format('Y-m-d H:i:s'))->toBe('2026-11-11 21:00:00')->and($end->format('Y-m-d H:i:s'))->toBe('2026-11-18 20:59:59');
    });
});

describe('who a failed delivery is the fault of', function () {
    it('puts a customer who was not home, a wrong address, a refusal and a locked gate on the customer side, damaged goods on the team side', function () {
        expect(array_map(ScorecardCauses::sideOf(...), ['customer_absent', 'customer_unreachable', 'wrong_address', 'refused', 'access_denied', 'damaged', 'other', null, 'whatever']))
            ->toBe(['customer', 'customer', 'customer', 'customer', 'customer', 'team', 'other', 'other', 'other']);
    });

    it('splits a count of failures by reason and by side, and reads an unknown reason as "other"', function () {
        $split = ScorecardCauses::split(['customer_absent' => 3, 'wrong_address' => 1, 'damaged' => 2, 'something typed by hand' => 4, 'other' => 1]);

        expect($split['customer'])->toBe(4)->and($split['team'])->toBe(2)->and($split['other'])->toBe(5)
            ->and($split['reasons'])->toBe(['customer_absent' => 3, 'wrong_address' => 1, 'damaged' => 2, 'other' => 5]);
    });
});

describe('how a number is written', function () {
    it('writes times as plain words in the dashboard language', function () {
        expect(ScorecardFormat::minutes(0.4))->toBe('Under 1 min')->and(ScorecardFormat::minutes(12))->toBe('12 min')->and(ScorecardFormat::minutes(125))->toBe('2 h 05 min')
            ->and(ScorecardFormat::percent(94.0))->toBe('94%')->and(ScorecardFormat::percent(94.5))->toBe('94.5%');
        app()->setLocale('ar');
        expect(ScorecardFormat::minutes(0.4))->toBe('أقل من دقيقة');
    });

    it('describes a change by its direction in words, so an arrow is never the only signal', function () {
        $up = ScorecardFormat::change('percent', 3.2);
        $down = ScorecardFormat::change('minutes', -5.0);
        $same = ScorecardFormat::change('percent', 0.0);

        expect($up['direction'])->toBe('up')->and($up['words'])->toBe('Up 3.2 pts')->and($down['direction'])->toBe('down')->and($down['words'])->toBe('Down 5 min')
            ->and($same['words'])->toBe('No change')->and($same['text'])->toBe('')->and(ScorecardFormat::change('count', null))->toBeNull();
    });
});
