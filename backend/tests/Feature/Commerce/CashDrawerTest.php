<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\CashHandover;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\CashDrawerService;
use App\Domain\Payments\Services\PaymentService;
use App\Livewire\Admin\Finance\CashIndex;
use App\Livewire\Admin\Sales\DriverConsole;
use App\Support\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** One COD order (QAR 275.00 = lamp 250 + delivery 15 + COD fee 10) whose payment $collector collects. */
function collectCod(object $w, User $collector, string $tender = 'cash'): CashCollection
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertCreated();
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => false])->assertCreated()->json('data.order.id');
    $order = Order::with('fulfillments')->findOrFail($orderId);

    return app(PaymentService::class)->recordCollection(Payment::where('order_id', $order->id)->firstOrFail(), $order->fulfillments->first(), $collector, $tender, $order->grand_total);
}

function drawerCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (DomainException $e) {
        return $e->errorCode;
    }

    return null;
}

beforeEach(function () {
    $this->w = commerceWorld([5, 5]);
    $this->driver = staffWithRole('delivery_employee', $this->w->a);
    $this->cashier = staffWithRole('branch_manager', $this->w->a);
    $this->finance = staffWithRole('accountant');
    $this->drawer = app(CashDrawerService::class);
});

it('settles everything a collector holds when the count matches', function () {
    $c1 = collectCod($this->w, $this->driver);
    $c2 = collectCod($this->w, $this->driver);
    collectCod($this->w, $this->driver, 'card_terminal'); // card is not cash: never part of a hand-in

    $balance = $this->drawer->balancesFor($this->driver)[$this->w->a->id];
    expect($balance['count'])->toBe(2)->and($balance['amount']->toDecimal())->toBe('550.00');

    $h = $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '550.00');
    expect($h->status)->toBe('balanced')->and($h->variance)->toBe('0.00')->and($h->variance_status)->toBeNull()->and($h->collections_count)->toBe(2)
        ->and($h->expected_amount)->toBe('550.00')->and($h->number)->toStartWith('CH-');

    foreach ([$c1, $c2] as $c) {
        $c = $c->fresh();
        expect($c->handed_in_at)->not->toBeNull()->and($c->handed_in_to)->toBe($this->cashier->id)->and($c->cash_handover_id)->toBe($h->id);
    }
    expect($this->drawer->balancesFor($this->driver))->toBe([])
        ->and($this->drawer->drawerBalance($this->w->a->id)->toDecimal())->toBe('550.00')
        ->and(AuditLog::where('action', 'cash.handed_in')->where('actor_id', $this->cashier->id)->where('branch_id', $this->w->a->id)->exists())->toBeTrue();

    // Handing in again finds nothing; the card collection stays untouched.
    expect(drawerCode(fn () => $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '550.00')))->toBe('cash_nothing_to_hand_in')
        ->and(CashCollection::where('tender', 'card_terminal')->whereNull('cash_handover_id')->count())->toBe(1);
});

it('enforces dual control and branch authority on hand-ins', function () {
    collectCod($this->w, $this->driver);

    expect(drawerCode(fn () => $this->drawer->handIn($this->driver, $this->driver, $this->w->a->id, '275.00')))->toBe('cash_self_handover')
        ->and(drawerCode(fn () => $this->drawer->handIn(staffWithRole('branch_manager', $this->w->b), $this->driver, $this->w->a->id, '275.00')))->toBe('branch_forbidden')
        ->and(drawerCode(fn () => $this->drawer->handIn(staffWithRole('sales_employee', $this->w->a), $this->driver, $this->w->a->id, '275.00')))->toBe('branch_forbidden')
        // A colleague driver can collect (payments.create) but has no custody permission.
        ->and(drawerCode(fn () => $this->drawer->handIn(staffWithRole('delivery_employee', $this->w->a), $this->driver, $this->w->a->id, '275.00')))->toBe('branch_forbidden');

    // The database refuses self-receipt even if the service were bypassed.
    expect(fn () => DB::transaction(fn () => CashHandover::create(['number' => 'CH-X', 'branch_id' => $this->w->a->id, 'collector_id' => $this->driver->id, 'received_by' => $this->driver->id,
        'collections_count' => 1, 'expected_amount' => '1.00', 'counted_amount' => '1.00', 'variance' => '0.00', 'status' => 'balanced'])))->toThrow(QueryException::class);
    expect(CashCollection::whereNotNull('cash_handover_id')->count())->toBe(0);
});

it('records a variance, requires an explanation and lets a third person resolve it', function () {
    collectCod($this->w, $this->driver);
    collectCod($this->w, $this->driver);

    expect(drawerCode(fn () => $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '540.00')))->toBe('cash_variance_note_required')
        ->and(drawerCode(fn () => $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '540.00', 'x')))->toBe('cash_variance_note_required')
        ->and(CashHandover::count())->toBe(0); // nothing recorded by the refused attempts

    $h = $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '540.00', 'Driver paid QAR 10 toll from the cash');
    expect($h->status)->toBe('short')->and($h->variance)->toBe('-10.00')->and($h->variance_status)->toBe('open')->and($h->hasOpenVariance())->toBeTrue();

    expect(drawerCode(fn () => $this->drawer->resolveVariance($this->driver, $h, 'It was a toll')))->toBe('branch_forbidden') // delivery staff can't override
        ->and(drawerCode(fn () => $this->drawer->resolveVariance($this->cashier, $h, 'Counted again')))->toBe('branch_forbidden')
        ->and(drawerCode(fn () => $this->drawer->resolveVariance($this->finance, $h, '')))->toBe('cash_variance_note_required');

    // Someone who handled the cash can't review it even with override rights.
    $both = staffWithRole('accountant');
    collectCod($this->w, $this->driver);
    $h2 = $this->drawer->handIn($both, $this->driver, $this->w->a->id, '275.00');
    collectCod($this->w, $this->driver);
    $h3 = $this->drawer->handIn($both, $this->driver, $this->w->a->id, '300.00', 'Customer overpaid and kept the change')->fresh();
    expect($h3->status)->toBe('over')->and(drawerCode(fn () => $this->drawer->resolveVariance($both, $h3, 'I counted it')))->toBe('cash_review_needs_third_person');

    $resolved = $this->drawer->resolveVariance($this->finance, $h, 'Toll receipt QAR 10 attached');
    expect($resolved->variance_status)->toBe('resolved')->and($resolved->variance_resolved_by)->toBe($this->finance->id)->and($resolved->variance_resolution)->toBe('Toll receipt QAR 10 attached')
        ->and(drawerCode(fn () => $this->drawer->resolveVariance($this->finance, $resolved, 'again')))->toBe('cash_no_open_variance')
        ->and(drawerCode(fn () => $this->drawer->resolveVariance($this->finance, $h2, 'no variance')))->toBe('cash_no_open_variance')
        ->and(AuditLog::where('action', 'cash.variance_resolved')->count())->toBe(1);
});

it('hands in only the chosen collections and rejects ones that are gone', function () {
    $c1 = collectCod($this->w, $this->driver);
    $c2 = collectCod($this->w, $this->driver);

    $h = $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '275.00', collectionIds: [$c1->id]);
    expect($h->collections_count)->toBe(1)->and($c1->fresh()->cash_handover_id)->toBe($h->id)->and($c2->fresh()->cash_handover_id)->toBeNull();

    // c1 is settled now, so asking for it (or for it together with c2) is refused as a whole.
    expect(drawerCode(fn () => $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '550.00', collectionIds: [$c1->id, $c2->id])))->toBe('cash_collection_unavailable')
        ->and($c2->fresh()->cash_handover_id)->toBeNull();
});

it('banks handed-in cash under a bank reference, one branch at a time', function () {
    collectCod($this->w, $this->driver);
    $h1 = $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '275.00');
    collectCod($this->w, $this->driver);
    $h2 = $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '270.00', 'Short by QAR 5 — customer paid in coins, counted twice');
    expect($this->drawer->drawerBalance($this->w->a->id)->toDecimal())->toBe('545.00');

    expect(drawerCode(fn () => $this->drawer->deposit($this->cashier, [$h1->id, $h2->id], '  ')))->toBe('cash_deposit_invalid')
        ->and(drawerCode(fn () => $this->drawer->deposit($this->cashier, [], 'REF-1')))->toBe('cash_deposit_invalid')
        ->and(drawerCode(fn () => $this->drawer->deposit(staffWithRole('branch_manager', $this->w->b), [$h1->id], 'REF-1')))->toBe('branch_forbidden');

    $res = $this->drawer->deposit($this->cashier, [$h1->id, $h2->id], 'QNB-2026-0457'); // an open variance does not block banking the cash that exists
    expect($res['count'])->toBe(2)->and($res['amount']->toDecimal())->toBe('545.00')
        ->and($this->drawer->drawerBalance($this->w->a->id)->toDecimal())->toBe('0.00')
        ->and($h1->fresh()->deposit_reference)->toBe('QNB-2026-0457')->and($h1->fresh()->deposited_by)->toBe($this->cashier->id)
        ->and($h2->fresh()->hasOpenVariance())->toBeTrue()
        ->and(drawerCode(fn () => $this->drawer->deposit($this->cashier, [$h1->id], 'QNB-2')))->toBe('cash_already_deposited');

    // Hand-ins of different branches can't share one deposit.
    $driverB = staffWithRole('delivery_employee', $this->w->b);
    $cashierB = staffWithRole('branch_manager', $this->w->b);
    $hb = CashHandover::create(['number' => 'CH-B', 'branch_id' => $this->w->b->id, 'collector_id' => $driverB->id, 'received_by' => $cashierB->id, 'collections_count' => 1,
        'expected_amount' => '10.00', 'counted_amount' => '10.00', 'variance' => '0.00', 'status' => 'balanced']);
    $h3 = CashHandover::create(['number' => 'CH-A', 'branch_id' => $this->w->a->id, 'collector_id' => $this->driver->id, 'received_by' => $this->cashier->id, 'collections_count' => 1,
        'expected_amount' => '10.00', 'counted_amount' => '10.00', 'variance' => '0.00', 'status' => 'balanced']);
    expect(drawerCode(fn () => $this->drawer->deposit($this->finance, [$hb->id, $h3->id], 'MIXED')))->toBe('cash_deposit_invalid');
});

it('serves the cash drawer through the admin API with branch scoping', function () {
    collectCod($this->w, $this->driver);
    collectCod($this->w, $this->driver);

    actingAsStaff($this->cashier);
    $rows = $this->getJson('/api/v1/admin/cash/outstanding')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['collections'])->toBe(2)->and($rows[0]['amount']['amount'])->toBe('550.00')->and($rows[0]['collector']['id'])->toBe($this->driver->id);

    actingAsStaff(staffWithRole('branch_manager', $this->w->b));
    expect($this->getJson('/api/v1/admin/cash/outstanding')->assertOk()->json('data'))->toBe([]);

    actingAsStaff($this->cashier);
    $payload = ['collector_id' => $this->driver->id, 'branch_id' => $this->w->a->id, 'counted_amount' => '500.00'];
    $this->postJson('/api/v1/admin/cash/handovers', $payload)->assertStatus(422)->assertJsonPath('code', 'cash_variance_note_required');
    $id = $this->postJson('/api/v1/admin/cash/handovers', $payload + ['note' => 'Customer short-paid; collected the rest by card'])
        ->assertCreated()->assertJsonPath('data.status', 'short')->assertJsonPath('data.variance.amount', '-50.00')->json('data.id');
    $this->getJson('/api/v1/admin/cash/handovers?variance=open')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.collector', $this->driver->name);

    actingAsStaff($this->finance);
    $this->postJson("/api/v1/admin/cash/handovers/{$id}/resolve", ['resolution' => 'Card receipt attached'])->assertOk()->assertJsonPath('data.variance_status', 'resolved');

    actingAsStaff($this->cashier);
    $this->postJson('/api/v1/admin/cash/deposits', ['handover_ids' => [$id], 'reference' => 'QNB-77'])->assertCreated()->assertJsonPath('data.amount.amount', '500.00');
    $this->getJson('/api/v1/admin/cash/handovers?status=in_drawer')->assertOk()->assertJsonPath('meta.total', 0);

    actingAsStaff($this->driver);
    $this->postJson('/api/v1/admin/cash/deposits', ['handover_ids' => [$id], 'reference' => 'XX'])->assertForbidden();
    $this->postJson('/api/v1/admin/cash/handovers', $payload)->assertForbidden(); // collectors can't receive cash either
    actingAsCustomer();
    $this->getJson('/api/v1/admin/cash/outstanding')->assertForbidden();
});

it('runs the cash drawer screen: receive cash, review a variance and bank it', function () {
    collectCod($this->w, $this->driver);
    collectCod($this->w, $this->driver);

    $this->actingAs($this->cashier);
    $screen = Livewire::test(CashIndex::class)->set('tab', 'outstanding')
        ->assertViewHas('outstanding', fn ($o) => $o->count() === 1 && (int) $o->first()->n === 2)->assertSee($this->driver->name);

    // A count that differs needs a note; the refused attempt records nothing.
    $screen->call('openHandIn', $this->driver->id, $this->w->a->id)->assertViewHas('dialogExpected', fn ($m) => $m->toDecimal() === '550.00')
        ->set('form.counted', '540')->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect(CashHandover::count())->toBe(0);
    $screen->set('form.note', 'Driver paid QAR 10 toll')->call('confirmDialog')->assertDispatched('toast', type: 'success');
    $h = CashHandover::firstOrFail();
    expect($h->status)->toBe('short')->and($h->variance)->toBe('-10.00');

    // Dual control and branch authority on the screen.
    Livewire::actingAs(staffWithRole('branch_manager', $this->w->b))->test(CashIndex::class)->assertViewHas('handoverRows', null)
        ->call('openHandIn', $this->driver->id, $this->w->a->id)->assertForbidden();
    Livewire::actingAs($this->driver)->test(CashIndex::class)->assertForbidden();

    // The people who handled the cash can't review; finance can.
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'handovers')->assertSee('CH-')->call('openResolve', $h->id)->assertForbidden();
    Livewire::actingAs($this->finance)->test(CashIndex::class)->set('tab', 'handovers')->assertViewHas('openVariances', 1)
        ->call('openResolve', $h->id)->assertViewHas('dialogHandover', fn ($x) => $x->id === $h->id)
        ->set('form.resolution', 'Toll receipt attached')->call('confirmDialog')->assertDispatched('toast', type: 'success');
    expect($h->fresh()->variance_status)->toBe('resolved');

    // Banking: select hand-ins, then record the reference.
    Livewire::actingAs($this->cashier)->test(CashIndex::class)->set('tab', 'handovers')->set('selected', [$h->id])
        ->assertViewHas('selectedTotal', fn ($m) => $m->toDecimal() === '540.00')->assertViewHas('drawer', fn ($m) => $m->toDecimal() === '540.00')
        ->call('openDeposit')->set('form.reference', 'QNB-2026-1')->call('confirmDialog')->assertDispatched('toast', type: 'success')
        ->assertSet('selected', []);
    expect($h->fresh()->deposit_reference)->toBe('QNB-2026-1');
});

it('shows a driver the cash they still owe the branch', function () {
    collectCod($this->w, $this->driver);
    BranchEmployee::where('user_id', $this->driver->id)->update(['is_delivery_driver' => true]);

    Livewire::actingAs($this->driver)->test(DriverConsole::class)->assertViewHas('cashToHandIn', fn ($m) => $m->toDecimal() === '275.00')->assertSee('QAR 275.00');
    $this->drawer->handIn($this->cashier, $this->driver, $this->w->a->id, '275.00');
    Livewire::actingAs($this->driver)->test(DriverConsole::class)->assertViewHas('cashToHandIn', fn ($m) => $m->isZero());
});
