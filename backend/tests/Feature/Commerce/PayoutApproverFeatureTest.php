<?php

use App\Domain\Branches\Services\BranchEmployeeService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchFeatureCatalog;
use App\Domain\Payments\Services\CashPayoutService;
use Database\Seeders\RolesAndPermissionsSeeder;

require_once __DIR__.'/CashShiftSupport.php';

// "Approve petty-cash payouts above the limit" in the Branch employees checklist (docs/guides/BRANCH_EMPLOYEES.md): the narrow right payouts.approve.

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->super = User::factory()->superAdmin()->create();
    $this->cashier = opsStaff('branch_manager', $this->a, ['name' => 'Hassan Cashier']);
    $this->payouts = app(CashPayoutService::class);
    $this->shift = csOpen($this->cashier, $this->a, 5);
    $this->employee = fn ($branch, array $features) => app(BranchEmployeeService::class)->create($this->super, $branch, [
        'name' => 'Branch Manager', 'email' => 'bm'.random_int(10000, 99999).'@albahar.qa', 'phone' => null, 'locale' => 'en', 'job_title' => null, 'password' => 'TempPassw0rdXyz',
    ], $features);
    $this->pay = fn (?string $approver) => $this->payouts->record($this->cashier, $this->shift, '150.00', 'supplies', null, $approver, null, null);
});

it('offers the feature in the cash group, in English and Arabic, and ticking it also ticks what it needs', function () {
    expect(BranchFeatureCatalog::groups()['cash'])->toContain('cash_payout_approve')
        ->and(BranchFeatureCatalog::withNeeds(['cash_payout_approve']))->toBe(['cash_view', 'cash_payout_approve'])
        ->and(BranchFeatureCatalog::permissionKeys(['cash_payout_approve']))->toBe(['payouts.approve'])
        ->and(__('branch_access.cash_payout_approve', [], 'en'))->toBe('Approve petty-cash payouts above the limit')
        ->and(__('branch_access.cash_payout_approve', [], 'ar'))->not->toBe('branch_access.cash_payout_approve')
        ->and(__('branch_access.cash_payout_approve_hint', [], 'ar'))->not->toBe('branch_access.cash_payout_approve_hint');
});

it('lets a branch manager who was given the feature approve a payout above the limit at their branch', function () {
    $manager = ($this->employee)($this->a, ['cash_view', 'cash_payout_approve']);

    expect($this->payouts->approvers($this->a->id, $this->cashier->id)->pluck('id')->all())->toContain($manager->id)
        ->and(($this->pay)($manager->id)->approved_by)->toBe($manager->id);
});

it('does not let them approve at another branch', function () {
    $manager = ($this->employee)($this->b, ['cash_view', 'cash_payout_approve']);

    expect($this->payouts->approvers($this->a->id, $this->cashier->id)->pluck('id')->all())->not->toContain($manager->id)
        ->and(csCode(fn () => ($this->pay)($manager->id)))->toBe('cash_payout_approver_invalid');
});

it('does not let them approve without the feature', function () {
    $manager = ($this->employee)($this->a, ['cash_view', 'cash_collect', 'cash_handover']);

    expect(csCode(fn () => ($this->pay)($manager->id)))->toBe('cash_payout_approver_invalid');
});

it('gives nothing else: the feature does not open the wider payment powers', function () {
    $manager = ($this->employee)($this->a, ['cash_view', 'cash_payout_approve']);
    $access = app(AccessControl::class);

    expect($access->allows($manager, 'payouts.approve', $this->a->id))->toBeTrue()
        ->and($access->allows($manager, 'payments.override', $this->a->id))->toBeFalse()
        ->and($access->allows($manager, 'payments.override'))->toBeFalse();
});

it('keeps payments.override working as an approver', function () {
    $legacy = opsStaffWith($this->a, ['payments.view', 'payments.edit', 'payments.override']);

    expect(($this->pay)($legacy->id)->approved_by)->toBe($legacy->id);
});

it('lets the feature holder reverse a payout somebody approved, and the cashier alone not', function () {
    $manager = ($this->employee)($this->a, ['cash_view', 'cash_collect', 'cash_handover', 'cash_payout_approve']);
    $payout = ($this->pay)($manager->id);

    expect(csCode(fn () => $this->payouts->void($this->cashier, $payout, 'Changed my mind')))->toBe('cash_payout_void_needs_approver');
    $this->payouts->void($manager, $payout, 'Approved by mistake');
    expect($payout->fresh()->isVoided())->toBeTrue();
});
