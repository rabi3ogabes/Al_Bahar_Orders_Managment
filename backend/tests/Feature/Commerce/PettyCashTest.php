<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Payments\Models\CashPayout;
use App\Domain\Payments\Services\CashPayoutService;
use App\Domain\Payments\Services\CashShiftService;
use App\Domain\Payments\Services\CashShiftSettings;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/CashShiftSupport.php';

// Petty-cash payouts from an open drawer (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md): the rules in CashPayoutService.

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->cashier = opsStaff('branch_manager', $this->a, ['name' => 'Hassan Cashier']);
    // The second person: holds the right to approve cash at the branch (the branch manager role does not).
    $this->approver = opsStaffWith($this->a, ['payments.view', 'payments.edit', 'payments.override']);
    $this->payouts = app(CashPayoutService::class);
    $this->shifts = app(CashShiftService::class);
    $this->shift = csOpen($this->cashier, $this->a, 5);                         // a float of QAR 500.00
    $this->pay = fn (string $amount = '25.00', string $reason = 'supplies', array $extra = []) => $this->payouts->record(
        $extra['by'] ?? $this->cashier, $extra['shift'] ?? $this->shift, $amount, $reason, $extra['note'] ?? null, $extra['approver'] ?? null, $extra['receipt'] ?? null, $extra['key'] ?? null
    );
    $this->expected = fn () => $this->shifts->figures($this->shift->fresh())['expected']->toDecimal();
});

describe('a payout lowers the cash the drawer should hold', function () {
    it('takes the amount off the expected cash and keeps it on the shift', function () {
        $p = ($this->pay)('25.50', 'transport', ['note' => 'Fuel for the van']);

        expect(($this->expected)())->toBe('474.50')
            ->and($p->amount)->toBe('25.50')->and($p->paid_by)->toBe($this->cashier->id)->and($p->cash_shift_id)->toBe($this->shift->id)->and($p->branch_id)->toBe($this->a->id)
            ->and($p->note)->toBe('Fuel for the van')->and($p->approved_by)->toBeNull()->and($p->isVoided())->toBeFalse();
        $f = $this->shifts->figures($this->shift->fresh());
        expect($f['payouts']->toDecimal())->toBe('25.50')->and($f['payouts_count'])->toBe(1);
    });

    it('is part of the closing: the count is compared with the lowered expectation, and the Z-report freezes the payouts', function () {
        ($this->pay)('40.00');
        ($this->pay)('10.00', 'cleaning');

        // 500 float - 50 paid out = 450.00 expected; the cashier counts exactly that
        $closed = csClose($this->cashier, $this->shift, 4, 50);

        expect($closed->expected_total)->toBe('450.00')->and($closed->variance)->toBe('0.00')->and($closed->summary['payouts'])->toBe(['amount' => '50.00', 'count' => 2]);
    });

    it('does not count a payout that was reversed', function () {
        $p = ($this->pay)('40.00');
        $this->payouts->void($this->cashier, $p, 'Typed the wrong amount');

        expect(($this->expected)())->toBe('500.00')->and($this->shifts->figures($this->shift->fresh())['payouts_count'])->toBe(0);
    });

    it('works with a drawer of exactly the amount (the drawer goes to zero) and refuses one fil more', function () {
        $ok = ($this->pay)('500.00', 'supplies', ['approver' => $this->approver->id]);

        expect($ok->amount)->toBe('500.00')->and(($this->expected)())->toBe('0.00')
            ->and(csCode(fn () => ($this->pay)('0.01')))->toBe('cash_payout_exceeds_drawer');
    });

    it('refuses a payout larger than the drawer and says how much it holds', function () {
        ($this->pay)('100.00');
        try {
            ($this->pay)('400.01');
            $this->fail('expected a refusal');
        } catch (DomainException $e) {
            expect($e->errorCode)->toBe('cash_payout_exceeds_drawer')->and($e->getMessage())->toContain('QAR 400.00')->and($e->getMessage())->toContain('QAR 400.01');
        }
        expect(CashPayout::count())->toBe(1);
    });
});

describe('what an amount and a reason must be', function () {
    it('refuses amounts that are not a positive number of at most two decimals', function (string $amount) {
        expect(csCode(fn () => ($this->pay)($amount)))->toBe('cash_payout_amount_invalid')->and(CashPayout::count())->toBe(0);
    })->with(['0', '0.00', '-5', '1.234', 'abc', '', '1e3', '12345678.00', '5,00']);

    it('accepts the smallest amount, a whole number and the longest valid one', function () {
        expect(($this->pay)('0.01')->amount)->toBe('0.01')->and(($this->pay)('7')->amount)->toBe('7.00');
    });

    it('needs a reason from the list, and words of its own when the reason is "Other"', function () {
        expect(csCode(fn () => ($this->pay)('5.00', 'gifts')))->toBe('cash_payout_reason_invalid')
            ->and(csCode(fn () => ($this->pay)('5.00', 'other')))->toBe('cash_payout_note_required')
            ->and(csCode(fn () => ($this->pay)('5.00', 'other', ['note' => 'ab'])))->toBe('cash_payout_note_required')
            ->and(csCode(fn () => ($this->pay)('5.00', 'supplies', ['note' => str_repeat('x', 301)])))->toBe('cash_payout_note_required')
            ->and(($this->pay)('5.00', 'other', ['note' => 'Tea for a visitor'])->note)->toBe('Tea for a visitor')
            ->and(($this->pay)('5.00', 'supplies', ['note' => '   '])->note)->toBeNull();
    });

    it('keeps Arabic and emoji notes exactly as typed', function () {
        $p = ($this->pay)('5.00', 'other', ['note' => 'شاي للضيوف ☕']);

        expect($p->fresh()->note)->toBe('شاي للضيوف ☕');
    });
});

describe('the approver above the limit', function () {
    it('needs nobody up to and including QAR 100, and a named second person above it', function () {
        expect(($this->pay)('100.00')->approved_by)->toBeNull()
            ->and(csCode(fn () => ($this->pay)('100.01')))->toBe('cash_payout_approver_required');

        $p = ($this->pay)('100.01', 'maintenance', ['approver' => $this->approver->id]);

        expect($p->approved_by)->toBe($this->approver->id)->and($this->payouts->needsApproval(Money::of('100.00')))->toBeFalse()->and($this->payouts->needsApproval(Money::of('100.01')))->toBeTrue();
    });

    it('follows the setting (Settings > Cash), strictly above', function () {
        app(Settings::class)->set('cash.payout_approval_above', '20.00');

        expect(($this->pay)('20.00')->approved_by)->toBeNull()->and(csCode(fn () => ($this->pay)('20.01')))->toBe('cash_payout_approver_required');

        app(Settings::class)->set('cash.payout_approval_above', 'nonsense');                 // a bad stored value falls back to the safe default
        expect(app(CashShiftSettings::class)->payoutApprovalAbove()->toDecimal())->toBe('100.00');
    });

    it('refuses an approver who is the cashier, has no right to approve, works at another branch or does not exist', function () {
        $nobody = opsStaffWith($this->a, ['payments.view', 'payments.edit']);                // may run a drawer, may not approve
        $otherBranch = opsStaffWith($this->b, ['payments.view', 'payments.edit', 'payments.override']);

        foreach ([$this->cashier->id, $nobody->id, $otherBranch->id, (string) Str::uuid(), 'not-an-id'] as $who) {
            expect(csCode(fn () => ($this->pay)('150.00', 'supplies', ['approver' => $who])))->toBe('cash_payout_approver_invalid');
        }
        expect(CashPayout::count())->toBe(0);
    });

    it('lists as approvers only the people who may approve at the branch, never the cashier', function () {
        $global = opsStaff('accountant');
        opsStaffWith($this->a, ['payments.view', 'payments.edit']);                          // cannot approve
        opsStaffWith($this->b, ['payments.override']);                                       // another branch

        $ids = $this->payouts->approvers($this->a->id, $this->cashier->id)->pluck('id')->all();

        expect($ids)->toContain($this->approver->id, $global->id)->and($ids)->toHaveCount(2)->and($this->payouts->approvers($this->a->id, $this->approver->id)->pluck('id')->all())->not->toContain($this->approver->id);
    });

    it('records an approver below the limit too when one is named, and still checks them', function () {
        expect(($this->pay)('30.00', 'supplies', ['approver' => $this->approver->id])->approved_by)->toBe($this->approver->id)
            ->and(csCode(fn () => ($this->pay)('30.00', 'supplies', ['approver' => $this->cashier->id])))->toBe('cash_payout_approver_invalid');
    });
});

describe('who may pay out, and when', function () {
    it('is closed to someone without the drawer right, to a cashier of another branch and to a closed shift', function () {
        $viewer = opsStaffWith($this->a, ['payments.view']);
        $other = opsStaff('branch_manager', $this->b);

        expect(csCode(fn () => ($this->pay)('5.00', 'supplies', ['by' => $viewer])))->toBe('branch_forbidden')
            ->and(csCode(fn () => ($this->pay)('5.00', 'supplies', ['by' => $other])))->toBe('branch_forbidden');
        csClose($this->cashier, $this->shift, 5);
        expect(csCode(fn () => ($this->pay)('5.00')))->toBe('cash_payout_shift_closed')->and(CashPayout::count())->toBe(0);
    });

    it('cannot slip a payout into a shift after it was closed (the shift row is locked first)', function () {
        $p = ($this->pay)('5.00');
        csClose($this->cashier, $this->shift, 4, 95);

        expect(csCode(fn () => ($this->pay)('5.00')))->toBe('cash_payout_shift_closed')
            ->and($this->shift->fresh()->summary['payouts']['count'])->toBe(1)
            ->and(csCode(fn () => $this->payouts->void($this->cashier, $p, 'Too late to change')))->toBe('cash_payout_shift_closed');
    });
});

describe('a double tap', function () {
    it('is one payout: the same request key answers with the payout that already exists', function () {
        $first = ($this->pay)('25.00', 'supplies', ['key' => 'window-1']);
        $second = ($this->pay)('25.00', 'supplies', ['key' => 'window-1']);

        expect($second->id)->toBe($first->id)->and(CashPayout::count())->toBe(1)->and(($this->expected)())->toBe('475.00');
    });

    it('does not let the same key be used by someone else or in another shift', function () {
        ($this->pay)('25.00', 'supplies', ['key' => 'window-1']);
        $other = opsStaff('branch_manager', $this->a);

        expect(csCode(fn () => ($this->pay)('25.00', 'supplies', ['key' => 'window-1', 'by' => $other])))->toBe('cash_payout_duplicate')->and(CashPayout::count())->toBe(1);
    });

    it('treats two different windows as two payouts', function () {
        ($this->pay)('25.00', 'supplies', ['key' => 'window-1']);
        ($this->pay)('25.00', 'supplies', ['key' => 'window-2']);

        expect(CashPayout::count())->toBe(2)->and(($this->expected)())->toBe('450.00');
    });
});

describe('reversing a payout', function () {
    it('puts the cash back, keeps the line with who, when and why, and writes it to the audit log', function () {
        $p = ($this->pay)('40.00');
        $v = $this->payouts->void($this->cashier, $p, 'Typed the wrong amount');

        expect($v->isVoided())->toBeTrue()->and($v->voided_by)->toBe($this->cashier->id)->and($v->void_reason)->toBe('Typed the wrong amount')->and(CashPayout::count())->toBe(1)
            ->and(($this->expected)())->toBe('500.00');
        $log = AuditLog::where('action', 'cash_shift.payout_voided')->firstOrFail();
        expect($log->new_values['reason'])->toBe('Typed the wrong amount')->and($log->actor_id)->toBe($this->cashier->id);
    });

    it('needs a reason of 3 to 300 characters', function () {
        $p = ($this->pay)('40.00');

        foreach (['', '  ', 'no', str_repeat('x', 301)] as $reason) {
            expect(csCode(fn () => $this->payouts->void($this->cashier, $p, $reason)))->toBe('cash_payout_void_reason_required');
        }
        expect($p->fresh()->isVoided())->toBeFalse();
    });

    it('answers a second identical tap with the same reversed payout, and refuses a different reason or person', function () {
        $p = ($this->pay)('40.00');
        $this->payouts->void($this->cashier, $p, 'Typed the wrong amount');

        expect($this->payouts->void($this->cashier, $p, 'Typed the wrong amount')->id)->toBe($p->id)->and(AuditLog::where('action', 'cash_shift.payout_voided')->count())->toBe(1)
            ->and(csCode(fn () => $this->payouts->void($this->cashier, $p, 'Another reason')))->toBe('cash_payout_already_voided');
    });

    it('lets only a person who may approve reverse a payout somebody approved', function () {
        $p = ($this->pay)('150.00', 'maintenance', ['approver' => $this->approver->id]);

        expect(csCode(fn () => $this->payouts->void($this->cashier, $p, 'Changed my mind')))->toBe('cash_payout_void_needs_approver')->and($p->fresh()->isVoided())->toBeFalse();
        $this->payouts->void($this->approver, $p, 'Approved by mistake');
        expect($p->fresh()->isVoided())->toBeTrue();
    });

    it('is closed to a person of another branch', function () {
        $p = ($this->pay)('40.00');

        expect(csCode(fn () => $this->payouts->void(opsStaff('branch_manager', $this->b), $p, 'Not mine to touch')))->toBe('branch_forbidden');
    });
});

describe('the receipt photo', function () {
    beforeEach(function () {
        Storage::fake('local');
        config(['platform.media.disk' => 'public']);                                          // receipts never go on the public disk
    });

    it('is kept on the private disk with the payout, and refused when it is not a photo', function () {
        $p = ($this->pay)('25.00', 'supplies', ['receipt' => UploadedFile::fake()->image('receipt.jpg', 400, 600)]);

        expect($p->receipt_path)->toStartWith('cash-payouts/'.$this->shift->id.'/'.$p->id)->and(Storage::disk('local')->exists($p->receipt_path))->toBeTrue()
            ->and(CashPayoutService::disk())->toBe('local');
        foreach ([UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'), UploadedFile::fake()->image('big.jpg')->size(6000)] as $bad) {
            expect(csCode(fn () => ($this->pay)('5.00', 'supplies', ['receipt' => $bad])))->toBe('cash_payout_receipt_invalid');
        }
        expect(CashPayout::count())->toBe(1);
    });

    it('leaves no photo behind when the payout is refused after the photo was read', function () {
        $before = count(Storage::disk('local')->allFiles());

        expect(csCode(fn () => ($this->pay)('900.00', 'supplies', ['receipt' => UploadedFile::fake()->image('r.png')])))->toBe('cash_payout_exceeds_drawer')
            ->and(count(Storage::disk('local')->allFiles()))->toBe($before);
    });

    it('opens for someone who may see the branch payments and answers 404 to everyone else', function () {
        $p = ($this->pay)('25.00', 'supplies', ['receipt' => UploadedFile::fake()->image('receipt.png')]);
        $plain = ($this->pay)('5.00');
        $url = route('admin.cash.payouts.receipt', $p);

        $this->actingAs($this->cashier)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(opsStaff('branch_manager', $this->b))->get($url)->assertNotFound();
        $this->actingAs(opsStaffWith($this->a, ['orders.view']))->get($url)->assertForbidden();
        $this->actingAs($this->cashier)->get(route('admin.cash.payouts.receipt', $plain))->assertNotFound();
    });
});

it('writes every payout to the audit log with the amount, the reason and the approver, and no customer data', function () {
    $p = ($this->pay)('150.00', 'maintenance', ['approver' => $this->approver->id, 'note' => 'Lock repaired']);

    $log = AuditLog::where('action', 'cash_shift.payout_recorded')->firstOrFail();

    expect($log->new_values)->toMatchArray(['amount' => '150.00', 'reason' => 'maintenance', 'note' => 'Lock repaired', 'approved_by' => $this->approver->id, 'drawer_after' => '350.00'])
        ->and($log->actor_id)->toBe($this->cashier->id)->and($log->branch_id)->toBe($this->a->id)->and($log->auditable_id)->toBe($p->id);
});
