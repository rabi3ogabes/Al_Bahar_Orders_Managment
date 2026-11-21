<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Services\CashDrawerService;
use App\Domain\Payments\Services\CashShiftService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../Feature/Commerce/CashShiftSupport.php';

uses(TestCase::class, DatabaseTruncation::class);

/* Real multi-process races on committed data: one drawer per branch, one close per shift, and a hand-in is either inside a shift's Z-report or after it. */

it('opens the drawer once when several people (or one person tapping twice) open it at the same moment', function () {
    $branch = opsBranch();
    $cashiers = collect(range(1, 6))->map(fn () => opsStaff('branch_manager', $branch))->all();

    $codes = race(6, fn (int $i) => (bool) app(CashShiftService::class)->open($cashiers[$i], $branch->id, csNotes(5)));
    expect(count(array_filter($codes, fn ($c) => $c === 0)))->toBe(1)->and(CashShift::where('branch_id', $branch->id)->where('status', 'open')->count())->toBe(1);

    // The same person with the same float, six times: all answers are the one shift.
    DB::table('cash_shifts')->delete();
    $same = $cashiers[0];
    $codes = race(6, fn () => (bool) app(CashShiftService::class)->open($same, $branch->id, csNotes(5)));
    expect($codes)->toBe([0, 0, 0, 0, 0, 0])->and(CashShift::count())->toBe(1);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('closes a shift exactly once when several people close it at the same moment, and numbers it once', function () {
    $branch = opsBranch();
    $opener = opsStaff('branch_manager', $branch);
    $closers = collect(range(1, 6))->map(fn () => opsStaff('branch_manager', $branch))->all();
    $shift = csOpen($opener, $branch, 5);

    // six different people, six different counts: one of them wins
    $codes = race(6, fn (int $i) => (bool) app(CashShiftService::class)->close($closers[$i], $shift, csNotes(5, $i), '0', 'Counted '.$i));
    $closed = CashShift::findOrFail($shift->id);
    expect(count(array_filter($codes, fn ($c) => $c === 0)))->toBe(1)->and($closed->status)->toBe('closed')->and($closed->z_number)->toBe(1)
        ->and(DB::table('cash_shift_counters')->where('branch_id', $branch->id)->value('last_number'))->toBe(1)
        ->and(AuditLog::where('action', 'cash_shift.closed')->count())->toBe(1);

    // one person tapping Close six times with the same count: every tap gets the one closed shift, no second number
    $s2 = csOpen($opener, $branch, 5);
    $codes = race(6, fn () => (bool) app(CashShiftService::class)->close($closers[0], $s2, csNotes(5)));
    expect($codes)->toBe([0, 0, 0, 0, 0, 0])->and(CashShift::findOrFail($s2->id)->z_number)->toBe(2)->and(DB::table('cash_shift_counters')->where('branch_id', $branch->id)->value('last_number'))->toBe(2);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('gives each branch its own Z numbers even when two branches close at the same time', function () {
    $a = opsBranch();
    $b = opsBranch();
    $cashA = opsStaff('branch_manager', $a);
    $cashB = opsStaff('branch_manager', $b);
    $sa = csOpen($cashA, $a, 1);
    $sb = csOpen($cashB, $b, 1);

    $codes = race(2, fn (int $i) => (bool) ($i === 0 ? app(CashShiftService::class)->close($cashA, $sa, csNotes(1)) : app(CashShiftService::class)->close($cashB, $sb, csNotes(1))));
    expect($codes)->toBe([0, 0])->and(CashShift::findOrFail($sa->id)->z_number)->toBe(1)->and(CashShift::findOrFail($sb->id)->z_number)->toBe(1);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('puts a hand-in either inside the closing shift (and its Z-report) or after it, never in between', function () {
    foreach (range(1, 4) as $round) {
        $branch = opsBranch();
        $cashier = opsStaff('branch_manager', $branch);
        $receiver = opsStaff('branch_manager', $branch);
        $driver = opsStaff('delivery_employee', $branch);
        $shift = csOpen($cashier, $branch, 1);
        csCollect($branch, $driver, '200.00');

        // child 0 closes counting the float only; child 1 receives 200.00 at the same moment
        $codes = race(2, fn (int $i) => (bool) ($i === 0
            ? app(CashShiftService::class)->close($cashier, $shift, csNotes(1), '0', 'Closing while a driver hands in')
            : app(CashDrawerService::class)->handIn($receiver, $driver, $branch->id, '200.00')));
        expect($codes[1])->toBe(0);                                                                // the hand-in always works

        $closed = CashShift::findOrFail($shift->id);
        $handover = CashHandover::where('branch_id', $branch->id)->firstOrFail();
        if ($handover->cash_shift_id === $shift->id) {
            // inside: the Z-report counted it (expected 300.00, the close needed the reason for the 200.00 difference only if it was missed)
            expect($closed->summary['received']['handins'])->toBe(1)->and($closed->summary['received']['total'])->toBe('200.00')->and($closed->expected_total)->toBe('300.00');
        } else {
            // after: the shift closed with only its float and the hand-in belongs to nobody yet (the next shift will claim it)
            expect($handover->cash_shift_id)->toBeNull()->and($closed->summary['received']['handins'])->toBe(0)->and($closed->expected_total)->toBe('100.00');
        }
        // either way the totals of the shift match the hand-ins that carry its id
        expect(CashHandover::where('cash_shift_id', $shift->id)->count())->toBe($closed->summary['received']['handins']);
    }
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('receives the same collections once when two cashiers hand them in during one shift', function () {
    $branch = opsBranch();
    $opener = opsStaff('branch_manager', $branch);
    $driver = opsStaff('delivery_employee', $branch);
    $cashiers = collect(range(1, 4))->map(fn () => opsStaff('branch_manager', $branch))->all();
    $shift = csOpen($opener, $branch, 1);
    $c1 = csCollect($branch, $driver, '120.00');
    $c2 = csCollect($branch, $driver, '80.00');

    $codes = race(4, fn (int $i) => (bool) app(CashDrawerService::class)->handIn($cashiers[$i], $driver, $branch->id, '200.00', null, [$c1['id'], $c2['id']]));

    expect(count(array_filter($codes, fn ($c) => $c === 0)))->toBe(1)->and(CashHandover::count())->toBe(1)->and(CashCollection::whereNull('cash_handover_id')->count())->toBe(0)
        ->and(app(CashShiftService::class)->figures(CashShift::findOrFail($shift->id))['expected']->toDecimal())->toBe('300.00');
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('keeps two hand-ins of different drivers in the same shift both counted', function () {
    $branch = opsBranch();
    $opener = opsStaff('branch_manager', $branch);
    $drivers = [opsStaff('delivery_employee', $branch), opsStaff('delivery_employee', $branch)];
    $receivers = [opsStaff('branch_manager', $branch), opsStaff('branch_manager', $branch)];
    $shift = csOpen($opener, $branch, 1);
    csCollect($branch, $drivers[0], '120.25');
    csCollect($branch, $drivers[1], '79.75');

    $codes = race(2, fn (int $i) => (bool) app(CashDrawerService::class)->handIn($receivers[$i], $drivers[$i], $branch->id, $i === 0 ? '120.25' : '79.75'));

    expect($codes)->toBe([0, 0])->and(CashHandover::where('cash_shift_id', $shift->id)->count())->toBe(2)
        ->and(app(CashShiftService::class)->figures(CashShift::findOrFail($shift->id))['expected']->toDecimal())->toBe('300.00');
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');
