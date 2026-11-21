<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Payments\Services\CashDrawerService;
use App\Domain\Payments\Services\CashShiftService;

require_once __DIR__.'/../Commerce/CashShiftSupport.php';

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA', 'name_en' => 'Al Bahar Doha', 'name_ar' => 'البحر الدوحة']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->cashier = opsStaff('branch_manager', $this->a, ['name' => 'Hassan Cashier']);
    $this->driver = opsStaff('delivery_employee', $this->a, ['name' => 'Omar Driver']);
    $this->finance = opsStaff('accountant');
    $this->shifts = app(CashShiftService::class);

    // A shift with a driver's hand-in (customer data sits on the orders behind it), a counter hand-in, a bank deposit and a small variance.
    $this->shift = csOpen($this->cashier, $this->a, 5, '2.50');
    $this->col = csCollect($this->a, $this->driver, '250.00');
    csCollect($this->a, $this->driver, '435.50');
    $this->handin = csHandIn($this->cashier, $this->driver, $this->a, '685.50');
    $this->counter = opsStaff('pickup_employee', $this->a);
    csCollect($this->a, $this->counter, '60.00', 'pay_at_pickup');
    csHandIn($this->cashier, $this->counter, $this->a, '59.00', 'One coin short');
    app(CashDrawerService::class)->deposit($this->cashier, [$this->handin->id], 'QNB-0042');
    $this->travel(6)->hours();
    $this->closed = $this->shifts->close($this->cashier, $this->shift, ['500' => 0, '200' => 0, '100' => 0, '50' => 1, '10' => 6, '5' => 1, '1' => 0], '3.50', 'Cash taken to the safe');
});

it('prints the Z-report with every figure of the shift, numbered, and with no customer data', function () {
    $html = $this->actingAs($this->cashier)->get(route('admin.cash.shifts.report', $this->closed))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();

    // 502.50 float + 744.50 hand-ins (685.50 from the driver + 59.00 counted for QAR 60.00 at the counter) - 685.50 banked = 561.50 expected; counted 50 + 60 + 5 + 3.50 = 118.50
    expect($html)->toContain('Z-1')->toContain('ORIGINAL')->toContain('Al Bahar Doha')->toContain('(AAA)')->toContain('Hassan Cashier')->toContain('Omar Driver')
        ->toContain($this->handin->number)->toContain('QNB-0042')->toContain('Cash taken to the safe')
        ->toContain('QAR 502.50')                         // the float
        ->toContain('QAR 744.50')                         // hand-ins counted: 685.50 + 59.00
        ->toContain('QAR 685.50')                         // banked
        ->toContain('QAR 561.50')                         // expected
        ->toContain('QAR 118.50')                         // counted
        ->toContain('QAR -443.00')                        // variance
        ->toContain('From drivers (cash on delivery)')->toContain('From the pickup counter')->toContain('Differences found when counting hand-ins')
        ->toContain('Cashier')->toContain('Reviewed by')->toContain('QAR 500')->toContain('Staff names and amounts only');
    // aggregates and staff names only: the orders behind the cash have a customer and a phone, and none of it (nor an order number) is on the page
    expect($html)->not->toContain('Noor Al-Thani')->not->toContain('+97455123456')->not->toContain($this->col['number']);
});

it('marks every print after the first as a COPY and writes each one to the audit log', function () {
    $url = route('admin.cash.shifts.report', $this->closed);
    $first = $this->actingAs($this->cashier)->get($url)->assertOk()->getContent();
    expect($first)->toContain('ORIGINAL')->not->toContain('COPY')->not->toContain('class="mark"');

    $second = $this->actingAs($this->finance)->get($url)->assertOk()->getContent();
    expect($second)->toContain('COPY 1')->toContain('class="mark"')->not->toContain('ORIGINAL');
    $this->actingAs($this->cashier)->get($url)->assertOk()->assertSee('COPY 2');

    expect($this->closed->fresh()->print_count)->toBe(3)
        ->and(AuditLog::where('action', 'cash_shift.z_printed')->orderBy('created_at')->orderBy('id')->get()->map(fn ($l) => [$l->new_values['print'], $l->new_values['copy']])->all())->toBe([[1, false], [2, true], [3, true]]);
});

it('keeps the report to people who may see the branch, and to closed shifts', function () {
    $url = route('admin.cash.shifts.report', $this->closed);
    $this->get($url)->assertRedirect();                                                                    // not signed in
    $this->actingAs(opsStaff('branch_manager', $this->b))->get($url)->assertNotFound();                  // another branch: as if it did not exist
    $this->actingAs($this->driver)->get($url)->assertForbidden();                                         // no payments.view
    expect($this->closed->fresh()->print_count)->toBe(0);                                                  // none of those printed anything

    $open = csOpen(opsStaff('branch_manager', $this->b), $this->b, 1);
    $this->actingAs($this->finance)->get(route('admin.cash.shifts.report', $open))->assertNotFound();   // an open shift has no Z-report yet
    $this->actingAs($this->finance)->get($url)->assertOk();                                               // company-wide payments.view
});

it('prints in the chosen language and numbers the reports of each branch on their own', function () {
    $url = route('admin.cash.shifts.report', $this->closed);
    $this->actingAs($this->cashier)->get($url.'?lang=ar')->assertOk()->assertSee('dir="rtl"', false)->assertSee('تقرير Z')->assertSee('الأصل')->assertSee('البحر الدوحة')
        ->assertSee('أمين الصندوق')->assertSee('النقد المتوقع');
    $this->actingAs($this->cashier)->get($url.'?lang=en')->assertOk()->assertSee('dir="ltr"', false)->assertSee('COPY 1')->assertDontSee('تقرير Z');
    $this->actingAs($this->cashier)->get($url.'?lang=fr')->assertOk();                                      // an unknown language is ignored

    $next = csClose($this->cashier, csOpen($this->cashier, $this->a, 1), 1);
    $b = csClose(opsStaff('branch_manager', $this->b), csOpen(opsStaff('branch_manager', $this->b), $this->b, 1), 1);
    expect($next->zLabel())->toBe('Z-2')->and($b->zLabel())->toBe('Z-1');
    $this->actingAs($this->finance)->get(route('admin.cash.shifts.report', $next))->assertSee('Z-2');
});

it('shows the open review state and who reviewed a large difference on the report', function () {
    $s = csOpen($this->cashier, $this->a, 1);
    $c = csClose(opsStaff('branch_manager', $this->a), $s, 0, 10, '0', 'Taken to the safe');
    $this->actingAs($this->finance)->get(route('admin.cash.shifts.report', $c))->assertSee('A second person still has to review this difference.');
    $this->shifts->reviewVariance($this->finance, $c, 'Safe slip found, signed');
    $this->actingAs($this->finance)->get(route('admin.cash.shifts.report', $c))->assertSee('Safe slip found, signed')->assertDontSee('still has to review');
});
