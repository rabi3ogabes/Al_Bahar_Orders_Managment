<?php

use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Operations\NeedsAttention;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

require_once __DIR__.'/../Commerce/CashShiftSupport.php';

/** "Needs attention": a drawer left open too long, and a finished day on which a branch took cash without any shift. */
beforeEach(function () {
    $this->today = qatar('2026-11-19 12:00');
    $this->travelTo($this->today);
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->mgrA = opsStaff('branch_manager', $this->a);
    $this->mgrB = opsStaff('branch_manager', $this->b);
    $this->finance = opsStaff('accountant');
    $this->driver = opsStaff('delivery_employee', $this->a);
    $this->feed = app(AttentionFeed::class);
});

/** Items of the shift kind the person sees. @return list<AttentionItem> */
function shiftItems($user, bool $mine = false): array
{
    Cache::flush();

    return app(AttentionFeed::class)->for($user, null, $mine)->items->where('category', 'cash_shift')->values()->all();
}

/** Cash received (a hand-in) at a branch at a Qatar moment, with no shift involved. */
function cashReceivedAt(string $when, $branch, $collector, $receiver, string $amount = '275.00'): void
{
    test()->travelTo(qatar($when));
    csCollect($branch, $collector, $amount, 'cod', now()->subMinutes(30));
    csHandIn($receiver, $collector, $branch, $amount);
    test()->travelTo(test()->today);
}

it('lists a shift that has been open longer than the limit: amber at first, red at one and a half times', function () {
    $shift = csOpen($this->mgrA, $this->a, 1);
    expect(shiftItems($this->mgrA))->toBe([]);                                                    // just opened: nothing waits

    $this->travel(15)->hours();
    expect(shiftItems($this->mgrA))->toBe([]);                                                    // under 16 hours (the default limit)

    $this->travel(2)->hours();                                                                    // 17 hours
    $items = shiftItems($this->mgrA);
    expect($items)->toHaveCount(1)->and($items[0]->state)->toBe('amber')->and($items[0]->category)->toBe('cash_shift')->and($items[0]->branchCode)->toBe('AAA')
        ->and($items[0]->subject)->toBe($this->mgrA->name)->and($items[0]->assignedTo)->toBe($this->mgrA->id)->and($items[0]->id)->toBe('cash_shift:'.$shift->id)
        ->and($items[0]->action['url'])->toBe(route('admin.cash.index', ['tab' => 'shifts']))->and($items[0]->label)->toBe('Cash shift still open')
        ->and($items[0]->detail)->toContain('17 h');

    $this->travel(8)->hours();                                                                    // 25 hours: past 1.5 x 16 = 24
    expect(shiftItems($this->mgrA)[0]->state)->toBe('red');

    csClose($this->mgrA, $shift->fresh(), 1);
    expect(shiftItems($this->mgrA))->toBe([]);                                                    // closing it clears the line
});

it('follows the limit set in Settings > Cash', function () {
    app(Settings::class)->set('cash.shift_max_open_hours', '6');
    csOpen($this->mgrA, $this->a, 1);
    $this->travel(5)->hours();
    expect(shiftItems($this->mgrA))->toBe([]);
    $this->travel(2)->hours();
    expect(shiftItems($this->mgrA))->toHaveCount(1)->and(shiftItems($this->mgrA)[0]->state)->toBe('amber');
    $this->travel(3)->hours();                                                                    // 10 hours: past 1.5 x 6 = 9
    expect(shiftItems($this->mgrA)[0]->state)->toBe('red');
});

it('shows each person only the shifts of the branches they may see, and "mine" only their own', function () {
    csOpen($this->mgrA, $this->a, 1);
    $other = opsStaff('branch_manager', $this->a);
    $this->travel(20)->hours();

    expect(shiftItems($this->mgrA))->toHaveCount(1)->and(shiftItems($this->mgrB))->toBe([])->and(shiftItems($this->finance))->toHaveCount(1)
        ->and(shiftItems($other))->toHaveCount(1)->and(shiftItems($other, true))->toBe([])->and(shiftItems($this->mgrA, true))->toHaveCount(1)
        ->and(shiftItems($this->driver))->toBe([]);                                                // no payments.view: not their business
});

it('flags a finished day with cash and no shift, only for a branch that uses shifts', function () {
    $at = fn (string $when) => $this->travelTo(qatar($when));

    // 18 Nov: branch A has never opened a shift, so cash that came in is not a gap in anything
    cashReceivedAt('2026-11-18 15:00', $this->a, $this->driver, $this->mgrA);
    expect(shiftItems($this->mgrA))->toBe([]);

    // 19 Nov: it starts using shifts. Cash received while the shift is open is covered by it.
    $at('2026-11-19 09:00');
    $s1 = csOpen($this->mgrA, $this->a, 1);
    $at('2026-11-19 15:00');
    csCollect($this->a, $this->driver, '90.00', 'cod', now()->subMinutes(10));
    csHandIn($this->mgrA, $this->driver, $this->a, '90.00');
    $at('2026-11-19 20:00');
    csClose($this->mgrA, $s1, 1, 90);
    $at('2026-11-20 12:00');
    expect(shiftItems($this->mgrA))->toBe([]);                                                    // the 19th is covered, the 18th was before shifts

    // 20 Nov and 21 Nov: cash came in and nobody opened the drawer
    cashReceivedAt('2026-11-20 15:00', $this->a, $this->driver, $this->mgrA, '300.00');
    $this->today = qatar('2026-11-21 12:00');
    $at('2026-11-21 12:00');
    $items = shiftItems($this->mgrA);
    expect($items)->toHaveCount(1)->and($items[0]->state)->toBe('amber')->and($items[0]->detail)->toContain('QAR 300.00');                 // yesterday: amber
    cashReceivedAt('2026-11-21 15:00', $this->a, $this->driver, $this->mgrA, '125.50');
    $at('2026-11-22 12:00');
    $items = shiftItems($this->mgrA);
    expect($items)->toHaveCount(2)->and(collect($items)->firstWhere('state', 'amber')->detail)->toContain('QAR 125.50')->and(collect($items)->firstWhere('state', 'red')->detail)->toContain('QAR 300.00')
        ->and($items[0]->label)->toBe('Cash received with no shift open')->and($items[0]->branchCode)->toBe('AAA')->and($items[0]->assignedTo)->toBeNull()
        ->and($items[0]->action['url'])->toBe(route('admin.cash.index', ['tab' => 'shifts']));
    expect(shiftItems($this->mgrB))->toBe([])->and(shiftItems($this->finance))->toHaveCount(2)->and(shiftItems($this->driver))->toBe([]);

    // Opening a shift today does not hide the days that went without one; a branch limited to another branch still sees nothing.
    csOpen($this->mgrA, $this->a, 1);
    expect(shiftItems($this->mgrA))->toHaveCount(2)->and(shiftItems($this->mgrB))->toBe([]);

    // Two days on, the 20th is out of the three-day window; only the 21st is still listed (and the drawer opened on the 22nd, still open).
    $at('2026-11-24 12:00');
    $gaps = collect(shiftItems($this->mgrA))->filter(fn ($i) => str_starts_with($i->id, 'cash_shift_day:'));
    expect($gaps)->toHaveCount(1)->and($gaps->first()->detail)->toContain('QAR 125.50');
});

it('counts the time of each hand-in: a shift that ran through midnight covers the cash it was open for, not the rest of the next day', function () {
    $at = fn (string $when) => $this->travelTo(qatar($when));
    $at('2026-11-18 12:00');
    $s = csOpen($this->mgrA, $this->a, 1);                                                        // opened on the 18th at noon ...
    $at('2026-11-18 23:00');
    csCollect($this->a, $this->driver, '75.00', 'cod', now()->subMinutes(10));
    csHandIn($this->mgrA, $this->driver, $this->a, '75.00');                                        // cash at 23:00, inside the shift
    $at('2026-11-19 03:00');
    csClose($this->mgrA, $s, 1, 75);                                                              // ... closed at 03:00 on the 19th
    $at('2026-11-19 22:00');
    cashReceivedAt('2026-11-19 22:00', $this->a, $this->driver, $this->mgrA);                       // cash on the 19th at 22:00, no shift
    $at('2026-11-20 12:00');
    $items = shiftItems($this->mgrA);
    expect($items)->toHaveCount(1)->and($items[0]->detail)->toContain('QAR 275.00');              // the 18th at 23:00 was inside the shift; the 19th at 22:00 was after it closed
});

it('names a cashier or a day, never a customer', function () {
    $s = csOpen($this->mgrA, $this->a, 1);
    csCollect($this->a, $this->driver, '80.00');                                                  // the order behind the cash has a customer name and phone
    $this->travel(20)->hours();
    $text = collect(shiftItems($this->mgrA))->map(fn ($i) => implode(' ', [$i->subject, $i->label, $i->detail, $i->note ?? '']))->implode(' ');
    expect($text)->not->toContain('Noor Al-Thani')->not->toContain('+97455123456')->and($s->id)->not->toBeEmpty();
});

it('shows the shift lines on the Needs attention page, in English and Arabic', function () {
    csOpen($this->mgrA, $this->a, 1);
    $this->travel(20)->hours();
    Cache::flush();

    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->assertSee('Cash shift still open')->assertSeeHtml('data-category="cash_shift"')->assertSee('Open cash shifts');
    app()->setLocale('ar');
    Livewire::actingAs($this->mgrA)->test(NeedsAttention::class)->assertSee('وردية الصندوق ما زالت مفتوحة')->assertSee('ورديات الصندوق');
});
