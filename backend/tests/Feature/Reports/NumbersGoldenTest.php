<?php

use Illuminate\Support\Arr;

require_once __DIR__.'/NumbersSupport.php';

/*
 | THE GOLDEN COMPARISON for "one definition per number" (docs/guides/NUMBER_DEFINITIONS.md).
 |
 | golden/numbers_before.json holds every figure of the daily close (four branches, ten days), the weekly owner pack (three weeks), the scorecard (branches and
 | drivers, at two minimum samples), the dashboard, the live board, customer value and offer returns for the ONE fixture of NumbersSupport.php, recorded with the
 | code of release 1.0.216, the code that ran before the figures were moved into one set of definitions (App\Domain\Reports\Services\Metrics).
 |
 | The test builds the same fixture, reads every screen with the new code, and asserts that the figures are the recorded ones, except the figures listed in
 | nbGoldenChanges(). Each of those was WRONG before: the company's line added up the orders of its branches, so an order shared by two branches was counted
 | twice. A figure that moves and is not in the list fails the test.
 */

/**
 * The only figures that moved, with the value they had in the recording and the value they have now.
 *
 * @return list<array{path: string, was: mixed, now: mixed}>
 */
function nbGoldenChanges(): array
{
    $changes = [
        // The weekly owner pack, "all branches": the order shared by Doha and Wakra on 11 Nov is one order, as the dashboard counts it (20 became 19; 3,790.00 / 19).
        ['owner_pack.2026-W46.total.this.orders', 20, 19],
        ['owner_pack.2026-W46.total.this.average', '189.50', '199.47'],
        ['owner_pack.2026-W47.total.prev.orders', 20, 19],
        ['owner_pack.2026-W47.total.prev.average', '189.50', '199.47'],
        // The pack of the week after: its own shared order (placed on 19 Nov) is one order (9 became 8; 1,210.00 / 8).
        ['owner_pack.2026-W47.total.this.orders', 9, 8],
        ['owner_pack.2026-W47.total.this.average', '134.44', '151.25'],
    ];
    // The scorecard's "all branches together" line, at both minimum samples: orders handled (23 became 22) and the two rates that are shares of it.
    foreach (['min1', 'min3'] as $min) {
        foreach ([
            ['orders.value', 23, 22], ['orders.change', 17, 16], ['orders.change_percent', 283.3, 266.7],
            ['cancel.rate.n', 23, 22], ['cancel.rate.value', 13.0, 13.6], ['cancel.rate.change', -3.7, -3.1],
            ['substitutions.rate.n', 23, 22],
        ] as [$path, $was, $now]) {
            $changes[] = ["scorecard.{$min}.branches.total.{$path}", $was, $now];
        }
    }

    return array_map(fn (array $c) => ['path' => $c[0], 'was' => $c[1], 'now' => $c[2]], $changes);
}

beforeEach(function () {
    $this->w = nbWorld();
    nbFill($this->w);
    $this->golden = json_decode((string) file_get_contents(__DIR__.'/golden/numbers_before.json'), true, 512, JSON_THROW_ON_ERROR);
});

it('keeps every figure of every screen exactly as release 1.0.216 had it, except the list of figures that were wrong', function () {
    $now = nbSnapshot($this->w);
    $expected = $this->golden;
    foreach (nbGoldenChanges() as $change) {
        expect(Arr::get($expected, $change['path']))->toBe($change['was'], 'the recording does not say '.$change['path'].' was '.json_encode($change['was']));
        Arr::set($expected, $change['path'], $change['now']);
    }

    expect($now)->toBe(nbSorted($expected));
});

it('is a recording of a rich world, not an empty one: every kind of case is in it', function () {
    $g = $this->golden;
    $doha = $g['daily_close']['DOH'];

    expect($g['daily_close'])->toHaveKeys(['DOH', 'WKR', 'HRS', 'EMP'])
        ->and($doha['2026-11-11']['orders']['cancelled'])->toBe(1)                                 // a cancelled real order
        ->and($doha['2026-11-11']['orders']['placed'])->toBe(3)                                    // not the order that expired unpaid, not the one waiting for payment
        ->and($doha['2026-11-16']['refunds'])->toBe(['amount' => '40.00', 'count' => 1])         // a refund paid the day after the week
        ->and($doha['2026-11-16']['orders']['returned'])->toBe(1)                                   // goods that came back after the claim was opened
        ->and($g['daily_close']['HRS']['2026-11-09']['speed']['accept_minutes'])->toBe(20)         // opening-hours minutes, not 740
        ->and($g['daily_close']['EMP']['2026-11-11']['sales']['orders'])->toBe(0)                   // a branch with nothing
        ->and($g['owner_pack']['2026-W46']['branches'][0]['this']['claims'])->toBe(1)
        ->and($g['scorecard']['min1']['branches']['rows'][1]['delivery']['moved'])->toBe(1)        // a delivery moved to a new slot
        ->and($g['scorecard']['min1']['drivers']['rows'])->toHaveCount(2)
        ->and($g['dashboard']['orders_today'])->toBe(7)
        ->and($g['live_board']['open'])->toBe(11);
});
