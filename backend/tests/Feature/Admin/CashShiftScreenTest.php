<?php

use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Services\CashShiftService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Finance\CashIndex;
use Livewire\Livewire;

require_once __DIR__.'/../Commerce/CashShiftSupport.php';

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->cashier = opsStaff('branch_manager', $this->a);
    $this->driver = opsStaff('delivery_employee', $this->a);
    $this->finance = opsStaff('accountant');
    $this->shifts = app(CashShiftService::class);
});

/** Types a count into the open / close window: 100-riyal notes, 1-riyal notes and the coins. */
function csType($screen, int $hundreds, int $ones = 0, string $coins = '')
{
    return $screen->set('count.notes.100', (string) $hundreds)->set('count.notes.1', (string) $ones)->set('count.coins', $coins);
}

it('shows each branch its own drawer on top of the cash page, with the one button that fits', function () {
    $shift = csOpen($this->cashier, $this->a, 5);
    csCollect($this->a, $this->driver, '250.00');
    csHandIn($this->cashier, $this->driver, $this->a, '250.00');

    Livewire::actingAs($this->cashier)->test(CashIndex::class)
        ->assertViewHas('shiftBranches', fn ($b) => $b->pluck('code')->all() === ['AAA'])
        ->assertViewHas('shiftFigures', fn ($f) => $f[$shift->id]['expected']->toDecimal() === '750.00')
        ->assertSeeHtml('data-shift-state="open"')->assertSeeHtml('data-action="close-shift"')->assertDontSeeHtml('data-action="open-shift"')
        ->assertSee('QAR 750.00')->assertSee('Cash shift');

    // The other branch's manager sees only their own (shut) drawer, and the open button.
    Livewire::actingAs(opsStaff('branch_manager', $this->b))->test(CashIndex::class)
        ->assertViewHas('shiftBranches', fn ($b) => $b->pluck('code')->all() === ['BBB'])->assertSeeHtml('data-shift-state="shut"')->assertSeeHtml('data-action="open-shift"')
        ->assertDontSee('QAR 750.00');

    // A finance user with company-wide rights sees both; a branch filter narrows the panel.
    Livewire::actingAs($this->finance)->test(CashIndex::class)->assertViewHas('shiftBranches', fn ($b) => $b->pluck('code')->sort()->values()->all() === ['AAA', 'BBB'])
        ->set('branch', $this->b->id)->assertViewHas('shiftBranches', fn ($b) => $b->pluck('code')->all() === ['BBB']);

    // Someone who may only look (no payments.edit) sees the drawer but no button.
    $viewer = opsStaffWith($this->a, ['payments.view']);
    Livewire::actingAs($viewer)->test(CashIndex::class)->assertSeeHtml('data-shift-state="open"')->assertDontSeeHtml('data-action="close-shift"')
        ->call('openShiftDialog', $this->a->id)->assertForbidden();
});

it('opens a shift from the window, with the count added up as it is typed', function () {
    $screen = Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('openShiftDialog', $this->a->id)
        ->assertSet('dialog', 'shift_open')->assertSet('showDialog', true)->assertViewHas('shiftDialog', fn ($d) => $d['count']['total']->toDecimal() === '0.00' && $d['previous'] === null);

    csType($screen, 5, 12, '3.75')->assertViewHas('shiftDialog', fn ($d) => $d['count']['total']->toDecimal() === '515.75')->assertSee('QAR 515.75');
    $screen->call('confirmDialog')->assertDispatched('toast', type: 'success')->assertSet('showDialog', false);

    $shift = CashShift::firstOrFail();
    expect($shift->opening_total)->toBe('515.75')->and($shift->opened_by)->toBe($this->cashier->id)->and($shift->status)->toBe('open');

    // A second tap on the same window (a new window with the same count) does not open a second drawer.
    csType(Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('openShiftDialog', $this->a->id), 5, 12, '3.75')->call('confirmDialog')->assertDispatched('toast', type: 'success');
    expect(CashShift::count())->toBe(1);
    // Somebody else gets the plain message.
    csType(Livewire::actingAs(opsStaff('branch_manager', $this->a))->test(CashIndex::class)->call('openShiftDialog', $this->a->id), 5)->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect(CashShift::count())->toBe(1);
});

it('refuses a window for a branch the person has no right to, and a count that is not a number', function () {
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('openShiftDialog', $this->b->id)->assertForbidden();
    $screen = Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('openShiftDialog', $this->a->id);
    $screen->set('count.notes.100', '-2')->call('confirmDialog')->assertHasErrors('count.notes.100');
    $screen->set('count.notes.100', '2')->set('count.coins', '1.234')->call('confirmDialog')->assertHasErrors('count.coins');
    expect(CashShift::count())->toBe(0);

    // The window of a shift of another branch, or one that is already closed, is not available either.
    $shift = csOpen($this->cashier, $this->a, 1);
    $managerB = opsStaff('branch_manager', $this->b);
    Livewire::actingAs($managerB)->test(CashIndex::class)->call('closeShiftDialog', $shift->id)->assertForbidden();
    Livewire::actingAs($managerB)->test(CashIndex::class)->call('reviewShiftDialog', $shift->id)->assertForbidden();
    csClose($this->cashier, $shift, 1);
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('closeShiftDialog', $shift->id)->assertDispatched('toast', type: 'error')->assertSet('showDialog', false);
});

it('closes the shift from the window: expected breakdown, variance as you type, a reason when needed, then the Z-report', function () {
    $shift = csOpen($this->cashier, $this->a, 5);
    csCollect($this->a, $this->driver, '250.00');
    csCollect($this->a, opsStaff('pickup_employee', $this->a), '60.00', 'pay_at_pickup');
    csHandIn($this->cashier, $this->driver, $this->a, '250.00');
    csHandIn($this->cashier, CashCollection::where('context', 'pay_at_pickup')->first()->collector, $this->a, '60.00');

    $screen = Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('closeShiftDialog', $shift->id)->assertSet('dialog', 'shift_close')
        ->assertViewHas('shiftDialog', fn ($d) => $d['figures']['expected']->toDecimal() === '810.00' && $d['variance'] === null)
        ->assertSeeHtml('data-expected-breakdown')->assertSee('QAR 810.00')->assertSee('From drivers (cash on delivery)')->assertSee('From the pickup counter');

    // Short by QAR 12.00: a reason is needed, no second person yet. The refused try records nothing.
    csType($screen, 7, 98)->assertViewHas('shiftDialog', fn ($d) => $d['variance']->toDecimal() === '-12.00' && $d['needsNote'] && ! $d['needsReview'])->assertSee('-12.00');
    $screen->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect($shift->fresh()->status)->toBe('open');
    $screen->set('form.note', 'Tea and sugar for the staff room')->call('confirmDialog')->assertSet('dialog', 'shift_done')->assertSet('showDialog', true)
        ->assertSee('Z-1')->assertSee(route('admin.cash.shifts.report', $shift->id), false)->assertDispatched('toast', type: 'success');

    $closed = $shift->fresh();
    expect($closed->status)->toBe('closed')->and($closed->variance)->toBe('-12.00')->and($closed->variance_status)->toBeNull()->and($closed->variance_note)->toBe('Tea and sugar for the staff room');
    // a big difference says a second person is needed, before and after
    $s2 = csOpen($this->cashier, $this->a, 1);
    csType(Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('closeShiftDialog', $s2->id), 0, 10)
        ->assertViewHas('shiftDialog', fn ($d) => $d['needsReview'])->assertSee('also needs a second person')
        ->set('form.note', 'Taken to the safe, slip missing')->call('confirmDialog')->assertSet('dialog', 'shift_done')->assertSee('second person has to review');
    expect($s2->fresh()->variance_status)->toBe('open');
});

it('refuses to close the drawer with every box left empty', function () {
    $shift = csOpen($this->cashier, $this->a, 5);
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('closeShiftDialog', $shift->id)->set('form.note', 'Nothing counted yet')->call('confirmDialog')->assertHasErrors('count')
        ->assertSee('Count the drawer first');
    expect($shift->fresh()->status)->toBe('open');
    // a typed zero is a count
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('closeShiftDialog', $shift->id)->set('count.notes.100', '0')->set('form.note', 'The drawer is empty: cash went to the bank')
        ->assertViewHas('shiftDialog', fn ($d) => $d['variance']->toDecimal() === '-500.00')->call('confirmDialog')->assertSet('dialog', 'shift_done');
});

it('lets only a second person with the override right review a large difference', function () {
    $shift = csOpen($this->cashier, $this->a, 1);
    $closed = csClose($this->cashier, $shift, 0, 10, '0', 'Taken to the safe');

    Livewire::actingAs($this->cashier)->test(CashIndex::class)->call('reviewShiftDialog', $closed->id)->assertForbidden();                    // no override right
    Livewire::actingAs($this->finance)->test(CashIndex::class)->set('tab', 'shifts')->assertViewHas('shiftReviews', 1)->call('reviewShiftDialog', $closed->id)
        ->assertSet('dialog', 'shift_review')->assertSee('Taken to the safe')->set('form.resolution', 'x')->call('confirmDialog')->assertHasErrors('form.resolution')
        ->set('form.resolution', 'Safe slip QAR 90 found')->call('confirmDialog')->assertDispatched('toast', type: 'success')->assertSet('showDialog', false);
    expect($closed->fresh()->variance_status)->toBe('resolved');
    Livewire::actingAs($this->finance)->test(CashIndex::class)->set('tab', 'shifts')->assertViewHas('shiftReviews', 0)->assertSee('Safe slip QAR 90 found');
});

it('lists the shifts of the branches the person may see, with filters', function () {
    $s1 = csOpen($this->cashier, $this->a, 5);
    $c1 = csClose($this->cashier, $s1, 5);
    $this->travel(1)->hours();
    $cashierB = opsStaff('branch_manager', $this->b);
    $sb = csOpen($cashierB, $this->b, 2);
    $this->travel(1)->hours();
    $s2 = csOpen($this->cashier, $this->a, 3);                                  // still open

    $rows = fn ($screen) => $screen->viewData('shiftHistory')->getCollection()->pluck('id')->all();
    $mgrA = Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'shifts');
    expect($rows($mgrA))->toBe([$s2->id, $c1->id])                                // newest first, branch B's never shown
        ->and($rows(Livewire::actingAs($cashierB)->test(CashIndex::class)->set('tab', 'shifts')))->toBe([$sb->id]);
    $mgrA->assertSee('Z-1')->assertSee('Open');

    $all = Livewire::actingAs($this->finance)->test(CashIndex::class)->set('tab', 'shifts');
    expect($rows($all))->toBe([$s2->id, $sb->id, $c1->id]);
    $all->set('filterShift', 'open');
    expect($rows($all))->toBe([$s2->id, $sb->id]);
    $all->set('filterShift', 'closed');
    expect($rows($all))->toBe([$c1->id]);
    $all->set('filterShift', '')->set('branch', $this->b->id);
    expect($rows($all))->toBe([$sb->id]);

    // an open shift older than the date range is still listed; an old closed one is not
    $this->travel(30)->days();
    $far = Livewire::actingAs($this->finance)->test(CashIndex::class)->set('tab', 'shifts')->set('branch', '');
    expect($rows($far))->toBe([$s2->id, $sb->id]);
    Livewire::actingAs($this->finance)->test(CashIndex::class)->set('tab', 'shifts')->set('filterFrom', '2026-11-01')->assertViewHas('shiftHistory', fn ($p) => $p->total() === 3);
});

it('shows an empty shifts tab in plain words', function () {
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'shifts')->assertSee('No shifts in this period');
});

it('hands in the collections that were ticked: oldest first, order numbers, a running total, select all and none', function () {
    $amounts = array_merge(array_fill(0, 14, '250.00'), ['435.50']);                           // one driver, 15 collections, QAR 3,935.50
    $made = [];
    foreach ($amounts as $i => $amount) {
        $made[] = csCollect($this->a, $this->driver, $amount, 'cod', now()->subHours(40 - $i));
    }
    $newest = array_pop($made);
    $made[] = $newest;

    $screen = Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'outstanding')->call('openHandIn', $this->driver->id, $this->a->id)
        ->assertViewHas('handinCollections', fn ($c) => $c->count() === 15 && $c->first()->id === $made[0]['id'] && $c->last()->id === $made[14]['id'])
        ->assertViewHas('dialogExpected', fn ($m) => $m->toDecimal() === '3935.50')->assertSet('pick', fn ($p) => count($p) === 15)
        ->assertSee($made[0]['number'])->assertSee($made[14]['number'])->assertSee('15 of 15 selected');

    // untick two: the total follows, and the count is compared with exactly those
    $keep = array_slice(array_column($made, 'id'), 0, 13);
    $screen->set('pick', $keep)->assertViewHas('dialogExpected', fn ($m) => $m->toDecimal() === '3250.00')->assertSee('13 of 15 selected')->assertSee('Select all');
    $screen->call('clearCollections')->assertViewHas('dialogExpected', fn ($m) => $m->isZero())->set('form.counted', '0')->call('confirmDialog')->assertHasErrors('pick');
    expect(CashHandover::count())->toBe(0);
    $screen->call('selectAllCollections')->assertViewHas('dialogExpected', fn ($m) => $m->toDecimal() === '3935.50')->assertSee('Clear');
    $screen->set('pick', $keep)->set('form.counted', '3250')->call('confirmDialog')->assertDispatched('toast', type: 'success')->assertSet('showDialog', false);

    $h = CashHandover::firstOrFail();
    expect($h->collections_count)->toBe(13)->and($h->expected_amount)->toBe('3250.00')->and($h->status)->toBe('balanced')
        ->and(CashCollection::whereNull('cash_handover_id')->count())->toBe(2);                                                    // the two left out are still outstanding

    // what is still held shows at once, and a second round takes it
    $again = Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'outstanding')->call('openHandIn', $this->driver->id, $this->a->id)
        ->assertViewHas('dialogExpected', fn ($m) => $m->toDecimal() === '685.50')->set('form.counted', '685.50')->call('confirmDialog')->assertDispatched('toast', type: 'success');
    expect(CashHandover::count())->toBe(2)->and(CashCollection::whereNull('cash_handover_id')->count())->toBe(0);
});

it('says so when a collection was taken by someone else while the window was open', function () {
    $c1 = csCollect($this->a, $this->driver, '100.00');
    $c2 = csCollect($this->a, $this->driver, '50.00');
    $screen = Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'outstanding')->call('openHandIn', $this->driver->id, $this->a->id);
    csHandIn(opsStaff('branch_manager', $this->a), $this->driver, $this->a, '100.00', null, [$c1['id']]);                           // a colleague received the first one meanwhile

    $screen->set('form.counted', '150')->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect(CashHandover::count())->toBe(1)->and(CashCollection::find($c2['id'])->handed_in_at)->toBeNull();                        // nothing was half received
});

it('keeps a driver from receiving their own cash, and branch managers from other branches out', function () {
    csCollect($this->a, $this->driver, '100.00');
    Livewire::actingAs(opsStaff('branch_manager', $this->b))->test(CashIndex::class)->call('openHandIn', $this->driver->id, $this->a->id)->assertForbidden();
    Livewire::actingAs($this->driver)->test(CashIndex::class)->assertForbidden();
});

it('speaks Arabic on the cash page, in the windows and on the Z-report', function () {
    $shift = csOpen($this->cashier, $this->a, 5);
    $this->cashier->update(['locale' => 'ar']);
    app()->setLocale('ar');

    $screen = Livewire::actingAs($this->cashier)->test(CashIndex::class);
    $screen->assertSee('وردية الصندوق')->assertSee('إغلاق الوردية')->assertSee('النقد الذي يجب أن يكون في الصندوق الآن')->assertSee('الرصيد الافتتاحي');
    $screen->call('closeShiftDialog', $shift->id)->assertSee('النقد المتوقع')->assertSee('عُدّ الصندوق الآن')->assertSee('500 ر.ق')->assertSee('سبب الفرق');
    $screen->set('tab', 'shifts')->assertSee('الورديات')->assertSee('مفتوحة');

    csClose($this->cashier, $shift, 5);
    $this->actingAs($this->cashier)->get(route('admin.cash.shifts.report', ['shift' => $shift->id, 'lang' => 'ar']))->assertOk()
        ->assertSee('dir="rtl"', false)->assertSee('تقرير Z')->assertSee('الأصل')->assertSee('النقد المتوقع')->assertSee('أمين الصندوق');
});

it('warns in the hand-in window when the branch takes cash only with a shift open, and offers to open one', function () {
    app(Settings::class)->set('cash.shift_policy', 'block');
    csCollect($this->a, $this->driver, '100.00');

    $screen = Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'outstanding')->call('openHandIn', $this->driver->id, $this->a->id)
        ->assertViewHas('handinBlocked', true)->assertSeeHtml('data-handin-blocked')->assertSee('only accepts cash while a shift is open')
        ->assertSee('Cash cannot be received until a shift is open.');
    $screen->set('form.counted', '100')->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect(CashHandover::count())->toBe(0);
    $screen->call('openShiftDialog', $this->a->id)->assertSet('dialog', 'shift_open');

    csOpen($this->cashier, $this->a, 1);
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'outstanding')->call('openHandIn', $this->driver->id, $this->a->id)
        ->assertViewHas('handinBlocked', false)->assertDontSeeHtml('data-handin-blocked')->set('form.counted', '100')->call('confirmDialog')->assertDispatched('toast', type: 'success');
    expect(CashHandover::count())->toBe(1);
});

it('shows which collections a hand-in settled, with their order numbers, to people who may see the branch', function () {
    $c1 = csCollect($this->a, $this->driver, '250.00', 'cod', now()->subHours(3));
    $c2 = csCollect($this->a, $this->driver, '435.50', 'cod', now()->subHours(2));
    $c3 = csCollect($this->a, $this->driver, '99.00', 'cod', now()->subHour());
    $h = csHandIn($this->cashier, $this->driver, $this->a, '685.00', 'One coin was missing', [$c1['id'], $c2['id']]);

    Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'handovers')->assertSee('2 collections')->call('openReceipt', $h->id)->assertSet('dialog', 'receipt')->assertSet('showDialog', true)
        ->assertViewHas('receiptRows', fn ($r) => $r->pluck('id')->all() === [$c1['id'], $c2['id']])->assertSeeHtml('data-receipt')
        ->assertSee($c1['number'])->assertSee($c2['number'])->assertDontSee($c3['number'])->assertSee('QAR 250.00')->assertSee('QAR 435.50')->assertSee('One coin was missing')->assertSee($h->number);
    Livewire::actingAs(opsStaff('branch_manager', $this->b))->test(CashIndex::class)->call('openReceipt', $h->id)->assertForbidden();
    Livewire::actingAs($this->finance)->test(CashIndex::class)->call('openReceipt', $h->id)->assertSee($c1['number']);

    app()->setLocale('ar');
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'handovers')->call('openReceipt', $h->id)->assertSee('التسليم '.$h->number)->assertSee('تحصيلان');
});
