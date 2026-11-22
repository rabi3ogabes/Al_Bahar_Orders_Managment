<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Orders\Printing\PrintText;
use App\Domain\Payments\Services\CashPayoutService;
use App\Domain\Payments\Services\CashShiftService;
use App\Livewire\Admin\Finance\CashIndex;

require_once __DIR__.'/../Commerce/CashShiftSupport.php';

// The Z-report as a PDF file and the petty-cash block of the printed Z-report (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md). PDFs are read back with pdftotext.

/** The text of a PDF response, as a person reads it. */
function zText(string $bytes): string
{
    $file = tempnam(sys_get_temp_dir(), 'zrep').'.pdf';
    file_put_contents($file, $bytes);
    $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    return $text;
}

/** Does the PDF text hold this Arabic text? pdftotext may return a run in either order and adds direction marks and spaces, so letters are compared without them. */
function zHas(string $text, string $arabic): bool
{
    $clean = fn (string $v): string => (string) preg_replace('/[\s\x{202A}-\x{202E}\x{200E}\x{200F}]+/u', '', $v);
    $haystack = $clean($text);
    $needle = $clean(PrintText::shape($arabic));

    return str_contains($haystack, $needle) || str_contains($haystack, implode('', array_reverse(mb_str_split($needle))));
}

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA', 'name_en' => 'Al Bahar Doha', 'name_ar' => 'البحر الدوحة']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->cashier = opsStaff('branch_manager', $this->a, ['name' => 'Hassan Cashier']);
    $this->driver = opsStaff('delivery_employee', $this->a, ['name' => 'Omar Driver']);
    $this->approver = opsStaffWith($this->a, ['payments.view', 'payments.edit', 'payments.override']);
    $this->approver->forceFill(['name' => 'Mariam Approver'])->save();
    $this->finance = opsStaff('accountant');
    $this->payouts = app(CashPayoutService::class);

    $shift = csOpen($this->cashier, $this->a, 5);
    $this->col = csCollect($this->a, $this->driver, '250.00');
    csHandIn($this->cashier, $this->driver, $this->a, '250.00');
    $this->p1 = $this->payouts->record($this->cashier, $shift, '25.50', 'transport', 'Fuel for the van');
    $this->p2 = $this->payouts->record($this->cashier, $shift, '150.00', 'maintenance', 'Lock repaired', $this->approver->id);
    $wrong = $this->payouts->record($this->cashier, $shift, '9.00', 'other', 'Typed twice');
    $this->payouts->void($this->cashier, $wrong, 'Entered by mistake');
    $this->travel(6)->hours();
    // 500 float + 250 hand-in - 25.50 - 150.00 = 574.50 expected; the cashier counts 574.50
    $this->closed = app(CashShiftService::class)->close($this->cashier, $shift, ['500' => 1, '200' => 0, '100' => 0, '50' => 1, '10' => 2, '5' => 0, '1' => 4], '0.50');
});

describe('the printed Z-report', function () {
    it('shows the petty cash as its own block and in the cash working, with the reversed line marked and not counted', function () {
        $html = $this->actingAs($this->cashier)->get(route('admin.cash.shifts.report', $this->closed))->assertOk()->getContent();

        expect($this->closed->expected_total)->toBe('574.50')->and($this->closed->variance)->toBe('0.00')
            ->and($html)->toContain('Petty cash paid out')->toContain('Fuel for the van')->toContain('Lock repaired')->toContain('Transport and fuel')->toContain('Small repair')
            ->toContain('Mariam Approver')->toContain('REVERSED')->toContain('Entered by mistake')->toContain('QAR 25.50')->toContain('QAR 150.00')
            ->toContain('2 petty-cash payouts')->toContain('QAR 175.50')->toContain('QAR 574.50')
            ->toContain('− petty cash paid out')->toContain('Hassan Cashier')
            ->and($html)->not->toContain('Noor Al-Thani')->not->toContain($this->col['number']);
    });

    it('says "None." for a shift without payouts and still adds up', function () {
        $shift = csOpen(opsStaff('branch_manager', $this->b), $this->b, 1);
        $closed = csClose($shift->opener, $shift, 1);

        $html = $this->actingAs($this->finance)->get(route('admin.cash.shifts.report', $closed))->assertOk()->getContent();

        expect($html)->toContain('Petty cash paid out')->toContain('None.')->not->toContain('data-payouts');
    });

    it('is Arabic when asked, with the petty cash words in Arabic', function () {
        $html = $this->actingAs($this->cashier)->get(route('admin.cash.shifts.report', $this->closed).'?lang=ar')->assertOk()->getContent();

        expect($html)->toContain('dir="rtl"')->toContain('المصروفات النثرية')->toContain('مَعكوس')->toContain('نقل ووقود');
    });
});

describe('the PDF download', function () {
    it('is a PDF file with every figure, the petty cash, staff names only, an ORIGINAL stamp and a file name', function () {
        $res = $this->actingAs($this->cashier)->get(route('admin.cash.shifts.report.pdf', $this->closed))->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        expect($res->headers->get('Content-Disposition'))->toBe('attachment; filename="z-report-Z-1-AAA.pdf"')->and(str_starts_with($res->getContent(), '%PDF'))->toBeTrue();

        $text = zText($res->getContent());
        expect($text)->toContain('Z-report')->toContain('Z-1')->toContain('ORIGINAL')->toContain('Al Bahar Doha')->toContain('Hassan Cashier')->toContain('Omar Driver')
            ->toContain('QAR 500.00')->toContain('QAR 250.00')->toContain('QAR 574.50')->toContain('0.00')
            ->toContain('Petty cash paid out')->toContain('Fuel for the van')->toContain('Lock repaired')->toContain('Mariam Approver')->toContain('Typed twice')->toContain('REVERSED')
            ->toContain('QAR 175.50')->toContain('Staff names and amounts only')->toContain('Downloaded by Hassan Cashier')
            ->not->toContain('Noor Al-Thani')->not->toContain('+97455123456')->not->toContain($this->col['number']);
    });

    it('keeps the ORIGINAL / COPY rule across print and PDF: the first is the original, every later one a copy, each audited', function () {
        $pdf = route('admin.cash.shifts.report.pdf', $this->closed);

        $first = zText($this->actingAs($this->cashier)->get($pdf)->assertOk()->getContent());
        $second = zText($this->actingAs($this->finance)->get($pdf)->assertOk()->getContent());
        $print = $this->actingAs($this->cashier)->get(route('admin.cash.shifts.report', $this->closed))->assertOk()->getContent();

        expect($first)->toContain('ORIGINAL')->not->toContain('COPY')
            ->and($second)->toContain('COPY 1')->not->toContain('ORIGINAL')
            ->and($print)->toContain('COPY 2')
            ->and($this->closed->fresh()->print_count)->toBe(3)
            ->and(AuditLog::where('action', 'cash_shift.z_printed')->orderBy('created_at')->orderBy('id')->get()->map(fn ($l) => [$l->new_values['print'], $l->new_values['copy']])->all())->toBe([[1, false], [2, true], [3, true]]);
    });

    it('can be made in Arabic: the Arabic words are in the file, joined and in visual order', function () {
        $text = zText($this->actingAs($this->cashier)->get(route('admin.cash.shifts.report.pdf', $this->closed).'?lang=ar')->assertOk()->getContent());

        expect(zHas($text, __('cash_shift.z.title', [], 'ar')))->toBeTrue()->and(zHas($text, __('petty_cash.reasons.maintenance', [], 'ar')))->toBeTrue()
            ->and(zHas($text, __('petty_cash.z.s_payouts', [], 'ar')))->toBeTrue()->and(zHas($text, __('cash_shift.z.original', [], 'en')))->toBeTrue()
            ->and($text)->toContain('Z-report')->toContain('QAR 574.50');
    });

    it('shows Arabic staff names and notes joined, not as loose letters', function () {
        $this->cashier->forceFill(['name' => 'حسن الصراف'])->save();
        $text = zText($this->actingAs($this->cashier)->get(route('admin.cash.shifts.report.pdf', $this->closed))->assertOk()->getContent());

        expect(zHas($text, 'حسن الصراف'))->toBeTrue()->and($text)->not->toContain('حسن');
    });

    it('answers 404 for another branch, for an open shift and for someone who may not see payments, and counts nothing', function () {
        $open = csOpen(opsStaff('branch_manager', $this->b), $this->b, 1);
        $before = $this->closed->fresh()->print_count;

        $this->actingAs(opsStaff('branch_manager', $this->b))->get(route('admin.cash.shifts.report.pdf', $this->closed))->assertNotFound();
        $this->actingAs($this->finance)->get(route('admin.cash.shifts.report.pdf', $open))->assertNotFound();
        $this->actingAs(opsStaffWith($this->a, ['orders.view']))->get(route('admin.cash.shifts.report.pdf', $this->closed))->assertForbidden();

        expect($this->closed->fresh()->print_count)->toBe($before);
    });

    it('is offered next to the Z-report in the Shifts tab and in the window shown after closing', function () {
        Livewire\Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'shifts')->assertSeeHtml(route('admin.cash.shifts.report.pdf', $this->closed))
            ->assertSeeHtml('data-action="z-pdf"')->assertSee('PDF');
    });
});
