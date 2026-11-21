<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\CashDrawerService;
use App\Domain\Payments\Services\CashShiftService;
use App\Domain\Payments\Services\CashShiftSettings;
use App\Domain\Payments\Services\RefundService;
use App\Domain\Settings\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/CashShiftSupport.php';

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));      // Qatar midday: no quiet hours, a plain business day
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->cashier = opsStaff('branch_manager', $this->a);
    $this->driver = opsStaff('delivery_employee', $this->a);
    $this->finance = opsStaff('accountant');
    $this->shifts = app(CashShiftService::class);
});

it('adds up a count to the dirham and refuses anything that is not a real count', function () {
    $count = $this->shifts->count(['500' => 2, '200' => 1, '100' => 0, '50' => 3, '10' => 0, '5' => 0, '1' => 7], '3.75');
    expect($count['total']->toDecimal())->toBe('1360.75')->and($count['coins']->toDecimal())->toBe('3.75')->and($count['notes'])->toBe(['500' => 2, '200' => 1, '100' => 0, '50' => 3, '10' => 0, '5' => 0, '1' => 7]);
    expect($this->shifts->count(['500' => '', '1' => '4'], '')['total']->toDecimal())->toBe('4.00')   // typed in a form: strings, blanks
        ->and($this->shifts->count([], null)['total']->toDecimal())->toBe('0.00');

    foreach ([[['100' => -1], '0'], [['100' => 1.5], '0'], [['100' => 'two'], '0'], [['25' => 3], '0'], [['100' => 1], '1.234'], [['100' => 1], '-5'], [['100' => 1], 'abc'], [['100' => 100001], '0']] as [$notes, $coins]) {
        expect(csCode(fn () => $this->shifts->count($notes, $coins)))->toBe('cash_shift_count_invalid');
    }
});

it('opens a shift, records it and keeps one open drawer per branch', function () {
    $shift = csOpen($this->cashier, $this->a, 5, '12.50');
    expect($shift->status)->toBe('open')->and($shift->opening_total)->toBe('512.50')->and($shift->opened_by)->toBe($this->cashier->id)
        ->and($shift->z_number)->toBeNull()->and($shift->variance_status)->toBeNull()
        ->and(AuditLog::where('action', 'cash_shift.opened')->where('branch_id', $this->a->id)->count())->toBe(1);

    // A double tap (same person, same float) is the same shift and writes nothing twice.
    expect(csOpen($this->cashier, $this->a, 5, '12.50')->id)->toBe($shift->id)->and(CashShift::count())->toBe(1)
        ->and(AuditLog::where('action', 'cash_shift.opened')->count())->toBe(1);

    // Someone else, or the same person with another float, is told the drawer is taken.
    $other = opsStaff('branch_manager', $this->a);
    expect(csCode(fn () => csOpen($other, $this->a, 5, '12.50')))->toBe('cash_shift_already_open')
        ->and(csCode(fn () => csOpen($this->cashier, $this->a, 4)))->toBe('cash_shift_already_open')
        ->and(CashShift::count())->toBe(1);

    // The database says the same even if the service were bypassed.
    expect(fn () => DB::transaction(fn () => CashShift::create(['branch_id' => $this->a->id, 'status' => 'open', 'opened_by' => $other->id, 'opened_at' => now(),
        'opening_notes' => [], 'opening_total' => '0.00'])))->toThrow(QueryException::class);

    // Another branch has its own drawer.
    expect(csOpen(opsStaff('branch_manager', $this->b), $this->b)->branch_id)->toBe($this->b->id);
});

it('lets only people who may receive cash at the branch open or close its drawer', function () {
    foreach ([opsStaff('sales_employee', $this->a), opsStaff('branch_manager', $this->b), $this->driver, opsStaff('auditor')] as $nobody) {
        expect(csCode(fn () => csOpen($nobody, $this->a)))->toBe('branch_forbidden');
    }
    $shift = csOpen($this->cashier, $this->a);
    foreach ([opsStaff('sales_employee', $this->a), opsStaff('branch_manager', $this->b), $this->driver] as $nobody) {
        expect(csCode(fn () => csClose($nobody, $shift, 5)))->toBe('branch_forbidden');
    }
    expect($shift->fresh()->status)->toBe('open');
    // An accountant (company-wide payments.edit) and a colleague manager of the branch may close it.
    expect(csClose(opsStaff('branch_manager', $this->a), $shift, 5)->status)->toBe('closed');
});

it('works out the expected cash to the dirham and closes balanced (a driver with 15 collections, QAR 3,935.50)', function () {
    $shift = csOpen($this->cashier, $this->a, 5);                                          // float 500.00
    $driver = $this->driver;
    $amounts = array_merge(array_fill(0, 14, '250.00'), ['435.50']);                       // 14 x 250.00 + 435.50 = 3,935.50
    foreach ($amounts as $amount) {
        csCollect($this->a, $driver, $amount);
    }
    $this->travel(30)->minutes();
    $big = csHandIn($this->cashier, $driver, $this->a, '3935.50');
    expect($big->cash_shift_id)->toBe($shift->id)->and($big->collections_count)->toBe(15)->and($big->status)->toBe('balanced');

    // Cash taken at the counter for a pickup order, handed in by the pickup employee, and a driver who is short by QAR 0.50.
    $counter = opsStaff('pickup_employee', $this->a);
    csCollect($this->a, $counter, '120.25', 'pay_at_pickup');
    $c = csHandIn($this->cashier, $counter, $this->a, '120.25');
    $driver2 = opsStaff('delivery_employee', $this->a);
    csCollect($this->a, $driver2, '100.00');
    $short = csHandIn($this->cashier, $driver2, $this->a, '99.50', 'Customer paid in coins, QAR 0.50 missing');
    expect($short->variance)->toBe('-0.50')->and($c->cash_shift_id)->toBe($shift->id)->and($short->cash_shift_id)->toBe($shift->id);

    // Bank the big hand-in during the shift, and pay a QAR 20.00 cash refund out of the drawer.
    $this->shifts = app(CashShiftService::class);
    app(CashDrawerService::class)->deposit($this->cashier, [$big->id], 'QNB-2026-1190');
    expect($big->fresh()->deposit_shift_id)->toBe($shift->id);
    $order = opsOrder($this->a, [], ['status' => 'delivered']);
    DB::table('orders')->where('id', $order['order'])->update(['payment_status' => 'paid', 'paid_total' => '100.00']);
    $paymentId = (string) Str::uuid();
    DB::table('payments')->insert(['id' => $paymentId, 'payable_type' => 'order', 'payable_id' => $order['order'], 'order_id' => $order['order'], 'method' => 'cod', 'provider' => 'offline',
        'status' => 'paid', 'amount' => '100.00', 'captured_amount' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
    $refundId = (string) Str::uuid();
    DB::table('refunds')->insert(['id' => $refundId, 'number' => 'RF-CS-1', 'payment_id' => $paymentId, 'order_id' => $order['order'], 'amount' => '20.00', 'reason_code' => 'customer_request',
        'method' => 'manual_cash', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
    app(RefundService::class)->markProcessed(Refund::findOrFail($refundId), $this->finance, 'CASH-OUT-1');
    expect(Refund::findOrFail($refundId)->cash_shift_id)->toBe($shift->id);

    // Right now: 500.00 + (3,935.50 + 120.25 + 99.50) - 3,935.50 - 20.00 = 699.75
    $f = $this->shifts->figures($shift->fresh());
    expect($f['expected']->toDecimal())->toBe('699.75')->and($f['received']->toDecimal())->toBe('4155.25')->and($f['received_cod']->toDecimal())->toBe('4035.50')
        ->and($f['received_counter']->toDecimal())->toBe('120.25')->and($f['received_difference']->toDecimal())->toBe('-0.50')->and($f['handins'])->toBe(3)->and($f['collections'])->toBe(17)
        ->and($f['deposits']->toDecimal())->toBe('3935.50')->and($f['deposits_count'])->toBe(1)->and($f['refunds']->toDecimal())->toBe('20.00')->and($f['refunds_count'])->toBe(1);

    $this->travel(8)->hours();
    $count = ['500' => 0, '200' => 0, '100' => 6, '50' => 1, '10' => 4, '5' => 1, '1' => 4];     // 600 + 50 + 40 + 5 + 4 + coins 0.75 = 699.75
    $closed = $this->shifts->close($this->cashier, $shift->fresh(), $count, '0.75');
    expect($closed->status)->toBe('closed')->and($closed->closing_total)->toBe('699.75')->and($closed->expected_total)->toBe('699.75')->and($closed->variance)->toBe('0.00')
        ->and($closed->variance_status)->toBeNull()->and($closed->z_number)->toBe(1)->and($closed->zLabel())->toBe('Z-1')->and($closed->closed_by)->toBe($this->cashier->id)
        ->and($closed->closing_notes)->toEqual(array_map('intval', $count))->and($closed->closing_coins)->toBe('0.75')
        ->and($closed->summary['float'])->toBe('500.00')->and($closed->summary['received'])->toBe(['total' => '4155.25', 'cod' => '4035.50', 'counter' => '120.25', 'difference' => '-0.50', 'handins' => 3, 'collections' => 17])
        ->and($closed->summary['deposits'])->toBe(['amount' => '3935.50', 'count' => 1])->and($closed->summary['refunds'])->toBe(['amount' => '20.00', 'count' => 1])
        ->and($closed->summary['expected'])->toBe('699.75');
    $log = AuditLog::where('action', 'cash_shift.closed')->firstOrFail();
    expect($log->new_values['z'])->toBe('Z-1')->and($log->new_values['needs_review'])->toBeFalse()->and($log->new_values['notes'])->toEqual(array_map('intval', $count))->and($log->branch_id)->toBe($this->a->id);
});

it('asks for a reason above the small limit and for a second person above the big one', function () {
    $close = function (int $hundreds, int $ones, string $coins, ?string $note = null) {
        $shift = csOpen($this->cashier, $this->a, 1);                                      // float 100.00, nothing else moves
        $res = csCode(fn () => csClose($this->cashier, $shift, $hundreds, $ones, $coins, $note));
        if ($res !== null) {
            $shift->delete();                                                              // keep the branch free for the next case

            return [$res, null];
        }

        return [null, $shift->fresh()];
    };

    // exactly at the small limit (QAR 1.00): no reason needed, nothing to review
    [$err, $s] = $close(1, 1, '0');
    expect($err)->toBeNull()->and($s->variance)->toBe('1.00')->and($s->variance_status)->toBeNull()->and($s->variance_note)->toBeNull();
    // one dirham above it: a reason is needed (same message as a hand-in), and a short one does not count
    expect($close(1, 1, '0.01')[0])->toBe('cash_variance_note_required')->and($close(1, 1, '0.01', 'x')[0])->toBe('cash_variance_note_required')->and($close(1, 1, '0.01', '  ')[0])->toBe('cash_variance_note_required');
    [$err, $s] = $close(1, 1, '0.01', 'Customer paid in coins');
    expect($err)->toBeNull()->and($s->variance)->toBe('1.01')->and($s->variance_note)->toBe('Customer paid in coins')->and($s->variance_status)->toBeNull();
    // short by the same amount needs the same reason; a negative variance is recorded as negative
    [$err, $s] = $close(0, 98, '0.99', 'Tea for the team');
    expect($err)->toBeNull()->and($s->variance)->toBe('-1.01')->and($s->variance_status)->toBeNull();
    // exactly at the big limit (QAR 50.00): a reason, but no second person yet
    [$err, $s] = $close(1, 50, '0', 'Counted twice');
    expect($err)->toBeNull()->and($s->variance)->toBe('50.00')->and($s->variance_status)->toBeNull();
    // above the big limit, over or short: a second person must look at it
    [$err, $s] = $close(1, 50, '0.01', 'Counted twice');
    expect($err)->toBeNull()->and($s->variance)->toBe('50.01')->and($s->variance_status)->toBe('open')->and($s->variance_resolved_by)->toBeNull();
    [$err, $s] = $close(0, 49, '0.99', 'Cash taken by the owner');
    expect($err)->toBeNull()->and($s->variance)->toBe('-50.01')->and($s->variance_status)->toBe('open');
    expect(AuditLog::where('action', 'cash_shift.closed')->where('new_values->needs_review', true)->count())->toBe(2);
});

it('takes the limits from the settings', function () {
    $settings = app(Settings::class);
    $settings->set('cash.shift_note_above', '0.00');          // every difference needs a reason
    $settings->set('cash.shift_review_above', '5.00');
    $shift = csOpen($this->cashier, $this->a, 1);
    expect(csCode(fn () => csClose($this->cashier, $shift, 1, 0, '0.01')))->toBe('cash_variance_note_required');
    $closed = csClose($this->cashier, $shift, 1, 6, '0', 'Six dirham more than expected');
    expect($closed->variance)->toBe('6.00')->and($closed->variance_status)->toBe('open');
    // a review limit below the reason limit is never lower than it
    $settings->set('cash.shift_note_above', '20.00');
    $settings->set('cash.shift_review_above', '5.00');
    expect(app(CashShiftSettings::class)->reviewAbove()->toDecimal())->toBe('20.00');
});

it('has a second person review a large difference, never someone who handled the drawer', function () {
    $shift = csOpen($this->cashier, $this->a, 1);
    $closer = opsStaff('branch_manager', $this->a);
    $closed = csClose($closer, $shift, 0, 20, '0', 'Safe deposit not recorded');          // 20.00 against 100.00 expected: short by 80.00
    expect($closed->variance)->toBe('-80.00')->and($closed->variance_status)->toBe('open');

    // not the opener, not the closer, not someone without the override right, not a short answer
    expect(csCode(fn () => $this->shifts->reviewVariance($this->cashier, $closed, 'Looks right to me')))->toBe('branch_forbidden')        // a branch manager has no override right
        ->and(csCode(fn () => $this->shifts->reviewVariance(opsStaff('accountant'), $closed, 'x')))->toBe('cash_variance_note_required')
        ->and(csCode(fn () => $this->shifts->reviewVariance(opsStaff('branch_manager', $this->b), $closed, 'Looks right to me')))->toBe('branch_forbidden');
    // somebody who handled the drawer cannot sign it off even with the override right: not the opener, not the closer
    $openerWithOverride = opsStaffWith($this->a, ['payments.view', 'payments.edit', 'payments.override']);
    $s2 = csOpen($openerWithOverride, $this->a, 1);
    $c2 = csClose($openerWithOverride, $s2, 0, 20, '0', 'Safe deposit not recorded');
    expect(csCode(fn () => $this->shifts->reviewVariance($openerWithOverride, $c2, 'I counted it myself')))->toBe('cash_review_needs_third_person');
    $s3 = csOpen($this->cashier, $this->a, 1);
    $closerWithOverride = opsStaffWith($this->a, ['payments.view', 'payments.edit', 'payments.override']);
    $c3 = csClose($closerWithOverride, $s3, 0, 20, '0', 'Safe deposit not recorded');
    expect(csCode(fn () => $this->shifts->reviewVariance($closerWithOverride, $c3, 'I closed it myself')))->toBe('cash_review_needs_third_person');

    $reviewed = $this->shifts->reviewVariance($this->finance, $closed, 'Safe deposit slip QAR 80 found');
    expect($reviewed->variance_status)->toBe('resolved')->and($reviewed->variance_resolved_by)->toBe($this->finance->id)->and($reviewed->variance_resolution)->toBe('Safe deposit slip QAR 80 found')
        ->and(csCode(fn () => $this->shifts->reviewVariance($this->finance, $closed, 'again')))->toBe('cash_no_open_variance')
        ->and(AuditLog::where('action', 'cash_shift.variance_reviewed')->where('actor_id', $this->finance->id)->count())->toBe(1);

    // A small difference never needs (or accepts) a review.
    $s4 = csOpen($this->cashier, $this->a, 1);
    $c4 = csClose($this->cashier, $s4, 1, 0, '0.50');
    expect(csCode(fn () => $this->shifts->reviewVariance($this->finance, $c4, 'nothing to review')))->toBe('cash_no_open_variance');
});

it('cannot be closed twice: the same count answers with the closed shift, any other is refused', function () {
    $shift = csOpen($this->cashier, $this->a, 5);
    $closed = csClose($this->cashier, $shift, 5);
    expect($closed->z_number)->toBe(1);

    $again = csClose($this->cashier, $shift->fresh(), 5);                                   // a double tap: nothing new
    expect($again->id)->toBe($closed->id)->and($again->z_number)->toBe(1)->and(AuditLog::where('action', 'cash_shift.closed')->count())->toBe(1)
        ->and(DB::table('cash_shift_counters')->where('branch_id', $this->a->id)->value('last_number'))->toBe(1);
    expect(csCode(fn () => csClose($this->cashier, $shift->fresh(), 4)))->toBe('cash_shift_already_closed')                  // another count
        ->and(csCode(fn () => csClose(opsStaff('branch_manager', $this->a), $shift->fresh(), 5)))->toBe('cash_shift_already_closed')  // another person
        ->and($shift->fresh()->closing_total)->toBe('500.00');
});

it('numbers the Z-reports of each branch in order and never reuses a number', function () {
    $cashierB = opsStaff('branch_manager', $this->b);
    $numbers = [];
    foreach ([1, 2, 3] as $i) {
        $s = csOpen($this->cashier, $this->a, 1);
        $numbers[] = csClose($this->cashier, $s, 1)->zLabel();
        $this->travel(1)->hours();
    }
    $sb = csOpen($cashierB, $this->b, 1);
    expect($numbers)->toBe(['Z-1', 'Z-2', 'Z-3'])->and(csClose($cashierB, $sb, 1)->zLabel())->toBe('Z-1');

    // A close that fails (a missing reason) does not use a number: the next one is still the next one.
    $s = csOpen($this->cashier, $this->a, 1);
    expect(csCode(fn () => csClose($this->cashier, $s, 3)))->toBe('cash_variance_note_required')
        ->and(csClose($this->cashier, $s, 1)->zLabel())->toBe('Z-4');
    // The database refuses a repeated number for a branch.
    expect(fn () => DB::table('cash_shifts')->where('id', $s->id)->update(['z_number' => 1]))->toThrow(QueryException::class);
});

it('accepts cash while the drawer is shut and adds it to the next shift (policy attach)', function () {
    $outsideFirst = csCollect($this->a, $this->driver, '300.00');
    $h0 = csHandIn($this->cashier, $this->driver, $this->a, '300.00');
    expect($h0->cash_shift_id)->toBeNull();                                                // before any shift existed

    $this->travel(1)->hours();
    $s1 = csOpen($this->cashier, $this->a, 1);                                             // the very first shift claims nothing from before shifts existed
    expect($h0->fresh()->cash_shift_id)->toBeNull();
    $this->travel(1)->hours();
    csCollect($this->a, $this->driver, '200.00');
    $in = csHandIn($this->cashier, $this->driver, $this->a, '200.00');
    expect($in->cash_shift_id)->toBe($s1->id);
    $this->travel(1)->hours();
    $c1 = csClose($this->cashier, $s1, 3);                                                 // 100.00 float + 200.00 received
    expect($c1->variance)->toBe('0.00');

    $this->travel(1)->hours();
    csCollect($this->a, $this->driver, '150.00');
    $gap = csHandIn($this->cashier, $this->driver, $this->a, '150.00');                    // nobody has the drawer open
    expect($gap->cash_shift_id)->toBeNull();

    $this->travel(1)->hours();
    $s2 = csOpen($this->cashier, $this->a, 3);                                             // float 300.00, counted WITHOUT the 150.00 in the gap
    expect($gap->fresh()->cash_shift_id)->toBe($s2->id)->and($h0->fresh()->cash_shift_id)->toBeNull()->and($in->fresh()->cash_shift_id)->toBe($s1->id)
        ->and(AuditLog::where('action', 'cash_shift.opened')->where('branch_id', $this->a->id)->orderByDesc('created_at')->first()->new_values['claimed_handins'])->toBe(1);
    expect($this->shifts->figures($s2)['expected']->toDecimal())->toBe('450.00');
    $this->travel(1)->hours();
    expect(csClose($this->cashier, $s2->fresh(), 4, 50)->variance)->toBe('0.00');
});

it('refuses cash while the drawer is shut when the policy says so, and says why', function () {
    app(Settings::class)->set('cash.shift_policy', 'block');
    csCollect($this->a, $this->driver, '275.00');
    csCollect($this->a, $this->driver, '25.00');

    expect(csCode(fn () => csHandIn($this->cashier, $this->driver, $this->a, '300.00')))->toBe('cash_shift_required')
        ->and(CashHandover::count())->toBe(0)->and(CashCollection::whereNull('cash_handover_id')->count())->toBe(2);        // nothing was received

    $shift = csOpen($this->cashier, $this->a, 1);
    $h = csHandIn($this->cashier, $this->driver, $this->a, '300.00');
    expect($h->cash_shift_id)->toBe($shift->id);

    // Banking is never blocked: the cash is leaving the drawer. Shut drawer: the deposit belongs to no shift (the cash had left before the next count).
    csClose($this->cashier, $shift, 4);
    $dep = app(CashDrawerService::class)->deposit($this->cashier, [$h->id], 'BANK-1');
    expect($dep['count'])->toBe(1)->and($h->fresh()->deposit_shift_id)->toBeNull();
    // the message a cashier reads
    expect(__('errors.cash_shift_required'))->toContain('Open a cash shift first');
});

it('hands in only the selected collections and compares the count with exactly those', function () {
    $c = collect(['250.00', '250.00', '435.50', '100.00'])->map(fn ($amount) => csCollect($this->a, $this->driver, $amount, 'cod', now()->subHours(5)));
    $ids = $c->pluck('id');
    $before = AuditLog::where('action', 'cash.handed_in')->count();

    // a subset: 250.00 + 435.50 = 685.50 is what is compared with the count
    $h = csHandIn($this->cashier, $this->driver, $this->a, '685.50', null, [$ids[0], $ids[2]]);
    expect($h->expected_amount)->toBe('685.50')->and($h->collections_count)->toBe(2)->and($h->status)->toBe('balanced')
        ->and(CashCollection::whereIn('id', [$ids[0], $ids[2]])->pluck('cash_handover_id')->unique()->all())->toBe([$h->id])
        ->and(CashCollection::whereIn('id', [$ids[1], $ids[3]])->whereNull('cash_handover_id')->whereNull('handed_in_at')->count())->toBe(2)     // the others stay outstanding
        ->and($this->driver->id)->toBe(CashCollection::find($ids[1])->collected_by)
        ->and(AuditLog::where('action', 'cash.handed_in')->count())->toBe($before + 1);

    // the variance of the receipt is about the selected ones only, and needs its own note
    expect(csCode(fn () => csHandIn($this->cashier, $this->driver, $this->a, '349.00', null, [$ids[1], $ids[3]])))->toBe('cash_variance_note_required');
    $v = csHandIn($this->cashier, $this->driver, $this->a, '349.00', 'One 1-riyal note was a torn one', [$ids[1], $ids[3]]);
    expect($v->expected_amount)->toBe('350.00')->and($v->variance)->toBe('-1.00')->and($v->variance_status)->toBe('open')
        ->and(app(CashDrawerService::class)->openCollections($this->driver)->count())->toBe(0);
});

it('refuses a hand-in with nothing selected, with a collection already handed in, or with someone else\'s collection', function () {
    $mine = csCollect($this->a, $this->driver, '250.00');
    $mine2 = csCollect($this->a, $this->driver, '100.00');
    $otherDriver = opsStaff('delivery_employee', $this->a);
    $theirs = csCollect($this->a, $otherDriver, '80.00');
    $otherBranch = csCollect($this->b, opsStaff('delivery_employee', $this->b), '60.00');
    $driverAtB = $this->driver;                                                           // the same driver also works for branch B
    BranchEmployee::create(['branch_id' => $this->b->id, 'user_id' => $driverAtB->id, 'is_active' => true]);
    $mineAtB = csCollect($this->b, $driverAtB, '45.00');

    expect(csCode(fn () => csHandIn($this->cashier, $this->driver, $this->a, '0.00', null, [])))->toBe('cash_nothing_to_hand_in')                 // none
        ->and(csCode(fn () => csHandIn($this->cashier, $this->driver, $this->a, '330.00', null, [$mine['id'], $theirs['id']])))->toBe('cash_collection_unavailable')   // another driver's
        ->and(csCode(fn () => csHandIn($this->cashier, $this->driver, $this->a, '295.00', null, [$mine['id'], $mineAtB['id']])))->toBe('cash_collection_unavailable')  // another branch's
        ->and(csCode(fn () => csHandIn($this->cashier, $this->driver, $this->a, '60.00', null, [$otherBranch['id']])))->toBe('cash_collection_unavailable')
        ->and(csCode(fn () => csHandIn($this->cashier, $this->driver, $this->a, '250.00', null, [$mine['id'], (string) Str::uuid()])))->toBe('cash_collection_unavailable')
        ->and(CashHandover::count())->toBe(0);                                              // every refusal recorded nothing

    csHandIn($this->cashier, $this->driver, $this->a, '250.00', null, [$mine['id']]);
    expect(csCode(fn () => csHandIn($this->cashier, $this->driver, $this->a, '350.00', null, [$mine['id'], $mine2['id']])))->toBe('cash_collection_unavailable')    // one is already handed in
        ->and(CashCollection::find($mine2['id'])->handed_in_at)->toBeNull();

    // the same collection ticked twice counts once
    $h = csHandIn($this->cashier, $this->driver, $this->a, '100.00', null, [$mine2['id'], $mine2['id']]);
    expect($h->collections_count)->toBe(1)->and($h->expected_amount)->toBe('100.00');
});

it('keeps the whole-balance hand-in working next to the new one', function () {
    csCollect($this->a, $this->driver, '250.00');
    csCollect($this->a, $this->driver, '435.50');
    $h = csHandIn($this->cashier, $this->driver, $this->a, '685.50');
    expect($h->collections_count)->toBe(2)->and($h->expected_amount)->toBe('685.50')->and($h->cash_shift_id)->toBeNull();
});

it('serves the selected-collections hand-in and the shift rule through the staff API unchanged for the apps', function () {
    $c1 = csCollect($this->a, $this->driver, '250.00');
    $c2 = csCollect($this->a, $this->driver, '100.00');
    $theirs = csCollect($this->a, opsStaff('delivery_employee', $this->a), '80.00');
    actingAsStaff($this->cashier);
    $payload = ['collector_id' => $this->driver->id, 'branch_id' => $this->a->id];

    $this->postJson('/api/v1/admin/cash/handovers', $payload + ['counted_amount' => '250.00', 'collection_ids' => [$c1['id'], $theirs['id']]])->assertStatus(409)->assertJsonPath('code', 'cash_collection_unavailable');
    $this->postJson('/api/v1/admin/cash/handovers', $payload + ['counted_amount' => '250.00', 'collection_ids' => []])->assertStatus(422);
    $this->postJson('/api/v1/admin/cash/handovers', $payload + ['counted_amount' => '250.00', 'collection_ids' => [$c1['id']]])->assertCreated()
        ->assertJsonPath('data.collections', 1)->assertJsonPath('data.expected.amount', '250.00')->assertJsonPath('data.status', 'balanced');
    expect(CashCollection::find($c2['id'])->handed_in_at)->toBeNull();

    // the "block" policy answers the apps with a plain 422 until a shift is open, then lets the cash in
    app(Settings::class)->set('cash.shift_policy', 'block');
    $this->postJson('/api/v1/admin/cash/handovers', $payload + ['counted_amount' => '100.00'])->assertStatus(422)->assertJsonPath('code', 'cash_shift_required');
    csOpen($this->cashier, $this->a, 1);
    $this->postJson('/api/v1/admin/cash/handovers', $payload + ['counted_amount' => '100.00'])->assertCreated();
    expect(CashHandover::whereNotNull('cash_shift_id')->count())->toBe(1);
});
