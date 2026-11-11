<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\PaymentService;
use App\Livewire\Admin\Finance\CashIndex;
use App\Livewire\Admin\Finance\PaymentIndex;
use App\Livewire\Admin\Finance\PaymentShow;
use App\Livewire\Admin\Finance\ReconciliationIndex;
use App\Livewire\Admin\Finance\RefundIndex;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->w = commerceWorld();
    $this->admin = User::factory()->superAdmin()->create();
    $this->accountant = staffWithRole('accountant');
    $this->accountant2 = staffWithRole('accountant');
    $this->mgrA = staffWithRole('branch_manager', $this->w->a);
    $this->mgrB = staffWithRole('branch_manager', $this->w->b);
});

/** A collected (paid) pay-at-pickup order at branch A. */
function paidPickupOrder(object $w): Order
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->json('data.order.id'));
    $staff = staffWithRole('pickup_employee', $w->a);
    $svc = app(PickupService::class);
    $svc->handover($svc->markReady($order->fulfillments->first(), $staff), $staff, [], 'Noor', collection: ['tender' => 'cash', 'amount' => $order->grand_total, 'receipt_reference' => 'R-1']);

    return $order->fresh();
}

const FINANCE_SCREENS = ['admin.payments.index', 'admin.cash.index', 'admin.refunds.index', 'admin.reconciliation.index'];

it('renders every finance screen for an authorized user', function (string $route) {
    $this->actingAs($this->admin)->get(route($route))->assertOk();
    $this->actingAs($this->accountant)->get(route($route))->assertOk();
})->with(FINANCE_SCREENS);

it('forbids finance screens to staff without the permission', function (string $route) {
    $this->actingAs(staffWithRole('pickup_employee', $this->w->a))->get(route($route))->assertForbidden();
})->with(FINANCE_SCREENS);

it('shows payment detail only to staff of the order’s branches', function () {
    $order = paidPickupOrder($this->w);
    $p = Payment::where('order_id', $order->id)->firstOrFail();
    $this->actingAs($this->accountant)->get(route('admin.payments.show', $p))->assertOk()->assertSee($order->number);
    $this->actingAs($this->mgrA)->get(route('admin.payments.show', $p))->assertOk();
    $this->actingAs($this->mgrB)->get(route('admin.payments.show', $p))->assertForbidden();

    $this->actingAs($this->mgrB);
    expect(Livewire::test(PaymentIndex::class)->viewData('payments')->total())->toBe(0)
        ->and(Livewire::test(CashIndex::class)->viewData('rows')->total())->toBe(0);
    $this->actingAs($this->mgrA);
    expect(Livewire::test(PaymentIndex::class)->viewData('payments')->total())->toBe(1)
        ->and(Livewire::test(CashIndex::class)->viewData('rows')->total())->toBe(1);
});

it('enforces refund dual control: the requester cannot approve their own request', function () {
    $order = paidPickupOrder($this->w);
    $p = Payment::where('order_id', $order->id)->firstOrFail();

    $this->actingAs($this->accountant);
    Livewire::test(PaymentShow::class, ['payment' => $p])->call('openRefund')->assertSet('refund.amount', $order->grand_total)
        ->set('refund.amount', '200.00')->set('refund.reason_code', 'goodwill')->call('requestRefund')->assertHasNoErrors();
    $refund = Refund::firstOrFail();
    expect($refund->status)->toBe('requested')->and($refund->method)->toBe('manual_cash'); // no refund API for offline payments

    // Same person: blocked by RefundService (approval segregation).
    Livewire::test(RefundIndex::class)->assertViewHas('rows', fn ($r) => $r->pluck('id')->contains($refund->id))
        ->call('approve', $refund->id)->assertDispatched('toast', type: 'error');
    expect($refund->fresh()->status)->toBe('requested');

    // A branch manager elsewhere can't see it at all.
    $this->actingAs($this->mgrB);
    expect(Livewire::test(RefundIndex::class)->viewData('rows')->total())->toBe(0);
    Livewire::test(RefundIndex::class)->call('approve', $refund->id)->assertForbidden();

    // A second accountant approves, then records the manual payout reference.
    $this->actingAs($this->accountant2);
    $c = Livewire::test(RefundIndex::class)->call('approve', $refund->id);
    expect($refund->fresh()->status)->toBe('approved')->and($refund->fresh()->approved_by)->toBe($this->accountant2->id);
    $c->call('open', 'process', $refund->id)->call('confirmDialog')->assertHasErrors('reference')
        ->set('reference', 'CASH-0042')->call('confirmDialog')->assertHasNoErrors();
    expect($refund->fresh()->status)->toBe('processed')->and($refund->fresh()->provider_reference)->toBe('CASH-0042')
        ->and($p->fresh()->refunded_amount)->toBe('200.00')->and($p->fresh()->status->value)->toBe('partially_refunded');
});

it('rejects refunds with a reason and caps the amount at what was captured', function () {
    $order = paidPickupOrder($this->w);
    $p = Payment::where('order_id', $order->id)->firstOrFail();
    $this->actingAs($this->accountant);
    Livewire::test(PaymentShow::class, ['payment' => $p])->call('openRefund')->set('refund.amount', '99999.00')->call('requestRefund')->assertDispatched('toast', type: 'error');
    expect(Refund::count())->toBe(0);
    Livewire::test(PaymentShow::class, ['payment' => $p])->call('openRefund')->call('requestRefund');
    $refund = Refund::firstOrFail();
    $this->actingAs($this->accountant2);
    Livewire::test(RefundIndex::class)->call('open', 'reject', $refund->id)->set('note', 'Duplicate of an earlier request')->call('confirmDialog');
    expect($refund->fresh()->status)->toBe('rejected');
});

it('records finance resolutions on flagged payments and only re-checks online payments', function () {
    $order = paidPickupOrder($this->w);
    $p = Payment::where('order_id', $order->id)->firstOrFail();
    $p->forceFill(['metadata' => ['late_capture' => ['amount' => '10.00', 'provider_reference' => 'X1']]])->save();

    $this->actingAs($this->accountant);
    expect(Livewire::test(PaymentIndex::class, ['filterFlag' => 'late_capture'])->viewData('payments')->pluck('id')->all())->toBe([$p->id]);
    Livewire::test(PaymentShow::class, ['payment' => $p])->assertViewHas('flagged', true)
        ->set('resolution', 'Refunded by bank transfer TT-778')->call('resolve')->assertHasNoErrors();
    expect($p->fresh()->metadata['review_resolution']['note'])->toBe('Refunded by bank transfer TT-778')
        ->and(AuditLog::where('action', 'payment.review_resolved')->exists())->toBeTrue();

    expect(fn () => app(PaymentService::class)->recheck($p, $this->accountant))->toThrow(DomainException::class);
    expect(fn () => app(PaymentService::class)->resolveReview($p, $this->mgrA, 'nope'))->toThrow(DomainException::class);
    $this->actingAs($this->mgrA);
    Livewire::test(PaymentShow::class, ['payment' => $p])->set('resolution', 'Trying without permission')->call('resolve')->assertForbidden();
});

it('lists SADAD reconciliation exceptions for finance', function () {
    $this->actingAs($this->accountant);
    Livewire::test(ReconciliationIndex::class)->assertViewHas('error', null)->assertViewHas('total', 0)->assertSee(__('finance_admin.recon_clean'));
    $this->actingAs(staffWithRole('pickup_employee', $this->w->a));
    Livewire::test(ReconciliationIndex::class)->assertForbidden();
});
