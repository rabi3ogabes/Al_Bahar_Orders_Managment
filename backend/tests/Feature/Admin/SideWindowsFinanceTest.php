<?php

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Livewire\Admin\Auctions\AuctionIndex;
use App\Livewire\Admin\Finance\CashIndex;
use App\Livewire\Admin\Finance\PaymentIndex;
use App\Livewire\Admin\Finance\PaymentShow;
use App\Livewire\Admin\Finance\ReconciliationIndex;
use App\Livewire\Admin\Finance\RefundIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->w = commerceWorld();
    $this->admin = User::factory()->superAdmin()->create();
    $this->accountant = staffWithRole('accountant');
    $this->mgrA = staffWithRole('branch_manager', $this->w->a);
    $this->mgrB = staffWithRole('branch_manager', $this->w->b);
});

/** A collected (paid) pay-at-pickup order at branch A. */
function windowPaidOrder(object $w): Order
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->json('data.order.id'));
    $staff = staffWithRole('pickup_employee', $w->a);
    $svc = app(PickupService::class);
    $svc->handover($svc->markReady($order->fulfillments->first(), $staff), $staff, [], 'Noor', collection: ['tender' => 'cash', 'amount' => $order->grand_total, 'receipt_reference' => 'R-1']);

    return $order->fresh();
}

describe('payments side window', function () {
    it('opens from ?view=, shows the payment and clears view on close', function () {
        $order = windowPaidOrder($this->w);
        $p = Payment::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->accountant);
        $c = Livewire::withQueryParams(['view' => $p->id])->test(PaymentIndex::class)->assertSet('showView', true)->assertSet('shownId', $p->id)->assertSee($order->number)->assertSee(__('drawer_sales.open_full'));
        $c->call('closeView')->assertSet('showView', false)->assertSet('viewId', '');
        $c->call('openView', $p->id)->assertSet('showView', true);
        // The order opens on top of the payment window.
        $c->call('peekOrder', $order->id)->assertSet('showOrder', true)->assertSet('showView', true);
    });

    it('opens nothing for another branch, a bad id or a missing permission', function () {
        $order = windowPaidOrder($this->w);
        $p = Payment::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->mgrB);
        Livewire::withQueryParams(['view' => $p->id])->test(PaymentIndex::class)->assertSet('showView', false)->assertSet('viewId', '');
        Livewire::test(PaymentIndex::class)->call('openView', $p->id)->assertSet('showView', false)->assertDispatched('toast', type: 'error');
        Livewire::test(PaymentShow::class, ['payment' => $p, 'drawer' => true])->assertForbidden();
        $this->actingAs($this->accountant);
        Livewire::test(PaymentIndex::class)->call('openView', 'nope')->assertSet('showView', false);
        $this->actingAs(staffWithRole('pickup_employee', $this->w->a));
        Livewire::test(PaymentIndex::class)->assertForbidden();
    });

    it('requests a refund from inside the window with the same audit trail, and refreshes the list', function () {
        $order = windowPaidOrder($this->w);
        $p = Payment::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->accountant);
        Livewire::test(PaymentShow::class, ['payment' => $p, 'drawer' => true])->call('openRefund')->set('refund.amount', '100.00')->set('refund.reason_code', 'goodwill')
            ->call('requestRefund')->assertHasNoErrors()->assertSet('showRefund', false)->assertDispatched('record-changed');
        expect(Refund::count())->toBe(1)->and(AuditLog::where('action', 'refund.requested')->exists())->toBeTrue();
        // The page does not emit the list event.
        Livewire::test(PaymentShow::class, ['payment' => $p])->call('openRefund')->set('refund.amount', '50.00')->call('requestRefund')->assertNotDispatched('record-changed');
    });

    it('resolves a flagged payment inside the window only with the override permission', function () {
        $order = windowPaidOrder($this->w);
        $p = Payment::where('order_id', $order->id)->firstOrFail();
        $p->forceFill(['metadata' => ['late_capture' => ['amount' => '10.00', 'provider_reference' => 'X1']]])->save();
        $this->actingAs($this->accountant);
        Livewire::test(PaymentShow::class, ['payment' => $p, 'drawer' => true])->assertViewHas('flagged', true)->set('resolution', 'Settled by bank transfer TT-9')->call('resolve')->assertHasNoErrors()->assertDispatched('record-changed');
        $this->actingAs($this->mgrA);
        Livewire::test(PaymentShow::class, ['payment' => $p, 'drawer' => true])->set('resolution', 'No right to do this')->call('resolve')->assertForbidden();
    });

    it('opens the payment from the reconciliation list too', function () {
        $order = windowPaidOrder($this->w);
        $p = Payment::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->accountant);
        Livewire::withQueryParams(['view' => $p->id])->test(ReconciliationIndex::class)->assertSet('showView', true)->assertSet('shownId', $p->id);
        $this->actingAs($this->mgrB);
        Livewire::withQueryParams(['view' => $p->id])->test(ReconciliationIndex::class)->assertSet('showView', false)->assertSet('viewId', '');
    });
});

describe('order windows from refunds and cash', function () {
    it('opens the order of a refund or a cash collection for staff of its branch only', function (string $component) {
        $order = windowPaidOrder($this->w);
        $this->actingAs($this->mgrA);
        Livewire::test($component)->call('peekOrder', $order->id)->assertSet('showOrder', true)->assertSet('orderPeekId', $order->id);
        $this->actingAs($this->mgrB);
        Livewire::test($component)->call('peekOrder', $order->id)->assertSet('showOrder', false)->assertSet('orderPeekId', '');
    })->with(['refunds' => RefundIndex::class, 'cash' => CashIndex::class]);
});

describe('auction quick view', function () {
    function windowAuction(): Auction
    {
        $manager = staffWithRole('auction_manager');
        $svc = app(AuctionService::class);
        $a = $svc->save($manager, ['type' => 'scheduled', 'translations' => ['en' => ['title' => 'Window Sale'], 'ar' => ['title' => 'مزاد النافذة']], 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $svc->addLot($manager, $a, ['title_en' => 'Brass Lantern', 'title_ar' => 'فانوس', 'starting_price' => '50', 'min_increment' => '5', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

        return $a->fresh();
    }

    it('opens from ?view= with its lots and keeps the editor and console links', function () {
        $a = windowAuction();
        $this->actingAs(staffWithRole('auction_manager'));
        Livewire::withQueryParams(['view' => $a->id])->test(AuctionIndex::class)->assertSet('showView', true)->assertSet('shownId', $a->id)
            ->assertSee('Window Sale')->assertSee('Brass Lantern')->assertSee(route('admin.auctions.edit', $a))->assertSee(__('drawer_sales.edit_auction'))
            ->call('closeView')->assertSet('showView', false)->assertSet('viewId', '');
    });

    it('opens nothing for a bad id and is closed to staff without auctions.view', function () {
        $a = windowAuction();
        $this->actingAs(staffWithRole('auction_manager'));
        Livewire::test(AuctionIndex::class)->call('openView', 'nope')->assertSet('showView', false)->assertDispatched('toast', type: 'error');
        Livewire::test(AuctionIndex::class)->call('openView', '00000000-0000-7000-8000-000000000000')->assertSet('showView', false);
        $this->actingAs(staffWithRole('pickup_employee', $this->w->a));
        Livewire::test(AuctionIndex::class)->assertForbidden();
        $this->actingAs(staffWithRole('pickup_employee', $this->w->a))->get(route('admin.auctions.index', ['view' => $a->id]))->assertForbidden();
    });

    it('publishes from the quick view with the editor’s permission and audit trail', function () {
        $a = windowAuction();
        $this->actingAs(staffWithRole('auction_manager'));
        Livewire::test(AuctionIndex::class)->call('openView', $a->id)->call('publish', $a->id)->assertDispatched('toast', type: 'success');
        expect($a->fresh()->status)->toBe('scheduled')->and(AuditLog::where('action', 'auction.published')->exists())->toBeTrue();
        // A read-only role can open the quick view but not publish.
        $b = windowAuction();
        $this->actingAs(staffWithRole('auditor'));
        Livewire::test(AuctionIndex::class)->call('openView', $b->id)->assertSet('showView', true)->call('publish', $b->id)->assertForbidden();
        expect($b->fresh()->status)->toBe('draft');
    });
});
