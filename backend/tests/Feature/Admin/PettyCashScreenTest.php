<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\CashPayout;
use App\Domain\Payments\Services\CashPayoutService;
use App\Domain\Payments\Services\CashShiftService;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Finance\CashIndex;
use App\Livewire\Admin\Organization\SettingsIndex;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Commerce/CashShiftSupport.php';

// The "Pay out cash" window of the cash page (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md).

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->cashier = opsStaff('branch_manager', $this->a, ['name' => 'Hassan Cashier']);
    $this->approver = opsStaffWith($this->a, ['payments.view', 'payments.edit', 'payments.override']);
    $this->approver->forceFill(['name' => 'Mariam Approver'])->save();
    $this->shift = csOpen($this->cashier, $this->a, 5);
    $this->screen = fn () => Livewire::actingAs($this->cashier)->test(CashIndex::class);
});

/** Types a payout into the window. */
function pcType($screen, string $amount, string $reason = 'supplies', string $note = '', string $approver = '')
{
    return $screen->set('payout.amount', $amount)->set('payout.reason', $reason)->set('payout.note', $note)->set('payout.approver', $approver);
}

it('puts a "Pay out cash" button and the payouts line on the open drawer, and the figure moves when something is paid out', function () {
    $screen = ($this->screen)();
    $screen->assertSeeHtml('data-action="pay-out"')->assertSee('Pay out cash')->assertSeeHtml('data-payouts-now')->assertSee('QAR 500.00');

    app(CashPayoutService::class)->record($this->cashier, $this->shift, '30.00', 'supplies');

    ($this->screen)()->assertSee('QAR 470.00')->assertSee('− QAR 30.00');
});

it('has no button for a shut drawer, or for someone who may only look', function () {
    Livewire::actingAs(opsStaff('branch_manager', $this->b))->test(CashIndex::class)->assertDontSeeHtml('data-action="pay-out"');
    Livewire::actingAs(opsStaffWith($this->a, ['payments.view']))->test(CashIndex::class)->assertSeeHtml('data-shift-state="open"')->assertDontSeeHtml('data-action="pay-out"')
        ->call('openPayoutDialog', $this->shift->id)->assertForbidden();
});

it('opens the window on the cash the drawer should hold, and pays out from it', function () {
    $screen = ($this->screen)()->call('openPayoutDialog', $this->shift->id)
        ->assertSet('dialog', 'payout')->assertSet('showDialog', true)->assertSee('Pay out cash')->assertSeeHtml('data-drawer-now')->assertSee('QAR 500.00')
        ->assertSee('Supplies')->assertSee('Transport and fuel')->assertSee('Nothing has been paid out in this shift yet.');

    pcType($screen, '25.50', 'transport', 'Fuel')->call('confirmDialog')->assertHasNoErrors()->assertDispatched('toast', type: 'success')->assertSet('showDialog', true)
        ->assertSet('payout.amount', '')->assertSee('QAR 474.50')->assertSee('Fuel')->assertSee('Paid by Hassan Cashier');

    $p = CashPayout::firstOrFail();
    expect($p->amount)->toBe('25.50')->and($p->reason_code)->toBe('transport')->and($p->paid_by)->toBe($this->cashier->id);
});

it('keeps the window open on the list so a second receipt is one more entry, and one tap twice is one payout', function () {
    $screen = ($this->screen)()->call('openPayoutDialog', $this->shift->id);
    $key = $screen->get('payout.key');
    pcType($screen, '10.00')->call('confirmDialog');
    $screen->call('confirmDialog');                                                           // the same tap again: the form is empty now
    expect(CashPayout::count())->toBe(1);

    // a second submit of the very same window (the browser sent the request twice) is the same payout
    $twin = ($this->screen)()->call('openPayoutDialog', $this->shift->id);
    pcType($twin, '10.00')->set('payout.key', $key)->call('confirmDialog');
    expect(CashPayout::count())->toBe(1);

    pcType($screen, '12.00', 'cleaning')->call('confirmDialog');
    expect(CashPayout::count())->toBe(2);
});

it('shows what is wrong in plain words: no reason, a wrong amount, "Other" without words, more than the drawer holds', function () {
    $screen = ($this->screen)()->call('openPayoutDialog', $this->shift->id);

    pcType($screen, '', '')->call('confirmDialog')->assertHasErrors(['payout.amount', 'payout.reason']);
    pcType($screen, '12.345')->call('confirmDialog')->assertHasErrors(['payout.amount']);
    pcType($screen, '12', 'other')->call('confirmDialog')->assertDispatched('toast', type: 'error');
    pcType($screen, '600')->assertSee('The drawer only holds QAR 500.00')->assertSeeHtml('data-payout-too-big');
    $screen->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect(CashPayout::count())->toBe(0);
});

it('asks for the approver as soon as the amount is above the limit, and lists only people who may approve', function () {
    $screen = ($this->screen)()->call('openPayoutDialog', $this->shift->id);

    pcType($screen, '100.00')->assertDontSeeHtml('data-payout-approval');
    pcType($screen, '100.01')->assertSeeHtml('data-payout-approval')->assertSee('This is above QAR 100.00')->assertSee('Mariam Approver')->assertDontSee('Hassan Cashier</option>', false);
    $screen->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect(CashPayout::count())->toBe(0);

    pcType($screen, '100.01', 'maintenance', '', $this->approver->id)->call('confirmDialog')->assertDispatched('toast', type: 'success')->assertSee('Approved by Mariam Approver');
    expect(CashPayout::firstOrFail()->approved_by)->toBe($this->approver->id);
});

it('says so plainly when nobody at the branch may approve', function () {
    $this->approver->delete();

    pcType(($this->screen)()->call('openPayoutDialog', $this->shift->id), '150')->assertSee('Nobody else at this branch can approve cash yet');
});

it('reverses a payout inline with a reason, and the cash goes back', function () {
    $p = app(CashPayoutService::class)->record($this->cashier, $this->shift, '40.00', 'supplies');
    $screen = ($this->screen)()->call('openPayoutDialog', $this->shift->id)->assertSee('QAR 460.00');

    $screen->call('startVoid', $p->id)->assertSeeHtml('data-void-form')->set('voidReason', 'no')->call('voidPayout')->assertHasErrors('voidReason');
    $screen->set('voidReason', 'Typed the wrong amount')->call('voidPayout')->assertDispatched('toast', type: 'success')->assertDontSeeHtml('data-void-form')
        ->assertSee('Reversed')->assertSee('Typed the wrong amount')->assertSee('QAR 500.00');

    expect($p->fresh()->isVoided())->toBeTrue()->and(app(CashShiftService::class)->figures($this->shift->fresh())['expected']->toDecimal())->toBe('500.00');
    $screen->call('voidPayout')->assertNotDispatched('toast', type: 'error');                // a second tap: nothing is being reversed any more, and nothing happens
    expect(AuditLog::where('action', 'cash_shift.payout_voided')->count())->toBe(1);
});

it('hides the reverse button of an approved payout from the cashier, and shows it to the approver', function () {
    $p = app(CashPayoutService::class)->record($this->cashier, $this->shift, '150.00', 'maintenance', null, $this->approver->id);

    ($this->screen)()->call('openPayoutDialog', $this->shift->id)->assertSeeHtml('data-payout="'.$p->id.'"')->assertDontSeeHtml('data-action="void-payout"');
    Livewire::actingAs($this->approver)->test(CashIndex::class)->call('openPayoutDialog', $this->shift->id)->assertSeeHtml('data-action="void-payout"');
});

it('refuses the window of a shift at another branch, and a payout of another branch to reverse', function () {
    $manager = opsStaff('branch_manager', $this->b);
    Livewire::actingAs($manager)->test(CashIndex::class)->call('openPayoutDialog', $this->shift->id)->assertForbidden();
    $p = app(CashPayoutService::class)->record($this->cashier, $this->shift, '40.00', 'supplies');
    Livewire::actingAs($manager)->test(CashIndex::class)->call('startVoid', $p->id)->assertForbidden();
    expect($p->fresh()->isVoided())->toBeFalse();
});

it('says the shift is gone once it is closed', function () {
    $screen = ($this->screen)()->call('openPayoutDialog', $this->shift->id);
    csClose($this->cashier, $this->shift, 5);

    $screen->call('cancelVoid')->assertSeeHtml('data-payout-gone')->assertSee('closed or no longer available');
    ($this->screen)()->call('openPayoutDialog', $this->shift->id)->assertDispatched('toast', type: 'error')->assertSet('showDialog', false);
});

it('keeps a receipt photo with the payout and links it, and refuses something that is not a photo', function () {
    Storage::fake('local');
    config(['platform.media.disk' => 'public']);
    $screen = ($this->screen)()->call('openPayoutDialog', $this->shift->id);

    pcType($screen, '12.00')->set('receipt', UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'))->call('confirmDialog')->assertHasErrors('receipt');
    expect(CashPayout::count())->toBe(0);
    $screen->set('receipt', UploadedFile::fake()->image('receipt.jpg', 300, 400))->call('confirmDialog')->assertHasNoErrors()->assertSee('Receipt');

    $p = CashPayout::firstOrFail();
    expect($p->receipt_path)->not->toBeNull()->and(Storage::disk('local')->exists($p->receipt_path))->toBeTrue();
    $screen->assertSeeHtml(route('admin.cash.payouts.receipt', $p));
});

it('speaks Arabic: reasons, hints and the list', function () {
    app()->setLocale('ar');
    $screen = ($this->screen)()->call('openPayoutDialog', $this->shift->id)->assertSee('صرف نقد')->assertSee('مستلزمات')->assertSee('نقل ووقود')->assertSee('لم يُصرف شيء في هذه الوردية بعد.');
    pcType($screen, '150')->assertSee(__('petty_cash.needs_approver', ['amount' => '100.00 ر.ق'], 'ar'));
    pcType($screen, '12', 'cleaning', '', '')->call('confirmDialog')->assertSee('نظافة')->assertSee('صرفه Hassan Cashier');
});

it('adds the payouts to the close-shift window and keeps the numbers adding up', function () {
    app(CashPayoutService::class)->record($this->cashier, $this->shift, '40.00', 'supplies');

    ($this->screen)()->call('closeShiftDialog', $this->shift->id)->assertSee('1 petty-cash payout')->assertSee('QAR 460.00')
        ->assertViewHas('shiftDialog', fn ($d) => $d['figures']['expected']->toDecimal() === '460.00');
});

it('has its limit in Settings > Cash, with a help line, and the window uses it', function () {
    $admin = User::factory()->superAdmin()->create();
    $page = Livewire::actingAs($admin)->test(SettingsIndex::class)->set('tab', 'cash')->assertSet('values.cash.cash__payout_approval_above', '100.00')->assertSee('A petty-cash payout needs an approver above');
    $page->set('values.cash.cash__payout_approval_above', '20')->call('save')->assertHasNoErrors();
    $page->set('values.cash.cash__payout_approval_above', '-1')->call('save')->assertHasErrors('values.cash.cash__payout_approval_above');

    expect(app(Settings::class)->get('cash.payout_approval_above'))->toBe('20');
    pcType(($this->screen)()->call('openPayoutDialog', $this->shift->id), '25')->assertSeeHtml('data-payout-approval')->assertSee('This is above QAR 20.00');
});
