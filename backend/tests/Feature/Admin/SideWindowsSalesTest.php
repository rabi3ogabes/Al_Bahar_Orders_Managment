<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Services\OrderService;
use App\Livewire\Admin\Sales\CustomerIndex;
use App\Livewire\Admin\Sales\CustomerShow;
use App\Livewire\Admin\Sales\DeliveryIndex;
use App\Livewire\Admin\Sales\DriverConsole;
use App\Livewire\Admin\Sales\FleetMap;
use App\Livewire\Admin\Sales\OrderIndex;
use App\Livewire\Admin\Sales\OrderShow;
use App\Livewire\Admin\Sales\PickupIndex;
use App\Livewire\Admin\Sales\ReturnIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->w = commerceWorld();
    $this->admin = User::factory()->superAdmin()->create();
    $this->mgrA = staffWithRole('branch_manager', $this->w->a);
    $this->mgrB = staffWithRole('branch_manager', $this->w->b);
});

function windowOrder(object $w): Order
{
    actingAsCustomer($w->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);

    return Order::with('fulfillments')->findOrFail(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id'));
}

describe('orders side window', function () {
    it('opens from the ?view= deep link and shows the order without leaving the list', function () {
        $order = windowOrder($this->w);
        $this->actingAs($this->mgrA);
        Livewire::withQueryParams(['view' => $order->id])->test(OrderIndex::class)
            ->assertSet('showOrder', true)->assertSet('orderPeekId', $order->id)->assertSet('viewId', $order->id)
            ->assertSee($order->number)->assertSee(__('drawer_sales.open_full'));
        $this->actingAs($this->mgrA)->get(route('admin.orders.index', ['view' => $order->id]))->assertOk()->assertSee($order->number);
    });

    it('opens on click, keeps the list filters and clears view on close', function () {
        $order = windowOrder($this->w);
        $this->actingAs($this->mgrA);
        Livewire::test(OrderIndex::class)->set('search', $order->number)->set('tab', 'all')
            ->call('peekOrder', $order->id)->assertSet('showOrder', true)->assertSet('viewId', $order->id)->assertSet('search', $order->number)
            ->call('closeOrderPeek')->assertSet('showOrder', false)->assertSet('viewId', '')->assertSet('search', $order->number);
    });

    it('opens nothing for another branch, a made-up id or a non-uuid', function () {
        $order = windowOrder($this->w);
        $this->actingAs($this->mgrB);
        Livewire::withQueryParams(['view' => $order->id])->test(OrderIndex::class)->assertSet('showOrder', false)->assertSet('viewId', '')->assertDontSee($order->number);
        Livewire::test(OrderIndex::class)->call('peekOrder', $order->id)->assertSet('showOrder', false)->assertSet('viewId', '')->assertSet('orderPeekId', '');
        Livewire::withQueryParams(['view' => '123'])->test(OrderIndex::class)->assertSet('showOrder', false);
        Livewire::test(OrderIndex::class)->call('peekOrder', '00000000-0000-7000-8000-000000000000')->assertSet('showOrder', false);
    });

    it('shows the same order data and actions as the page, in compact mode', function () {
        $order = windowOrder($this->w);
        $f = $order->fulfillments->first();
        $this->actingAs($this->mgrA);
        Livewire::test(OrderShow::class, ['order' => $order, 'drawer' => true])->assertOk()->assertSee($order->customer_name)
            ->assertViewHas('routeCards', [])
            ->assertViewHas('actions', fn ($a) => collect($a[$f->id]['steps'])->pluck('to')->filter()->values()->all() === ['accepted', 'preparing']);
        // Another branch's manager gets the same 403 as the page.
        $this->actingAs($this->mgrB);
        Livewire::test(OrderShow::class, ['order' => $order, 'drawer' => true])->assertForbidden();
    });

    it('keeps the audit trail and tells the list to refresh when an action runs inside the window', function () {
        $order = windowOrder($this->w);
        $f = $order->fulfillments->first();
        $this->actingAs($this->mgrA);
        Livewire::test(OrderShow::class, ['order' => $order, 'drawer' => true])->call('advance', $f->id, 'accepted')->assertDispatched('record-changed')->assertDispatched('toast');
        expect($f->fresh()->status->value)->toBe('accepted')
            ->and(AuditLog::where('action', 'order.fulfillment_accepted')->where('actor_id', $this->mgrA->id)->exists())->toBeTrue();
        // The page itself does not emit the list event.
        Livewire::test(OrderShow::class, ['order' => $order])->call('advance', $f->id, 'preparing')->assertNotDispatched('record-changed');
    });

    it('cancels from the window with a reason through the modal and audits it', function () {
        $order = windowOrder($this->w);
        $this->actingAs($this->mgrA);
        Livewire::test(OrderShow::class, ['order' => $order, 'drawer' => true])->call('open', 'cancel_order')->assertSet('showDialog', true)
            ->set('form.reason', 'Customer called to cancel')->call('confirmDialog')->assertHasNoErrors()->assertSet('showDialog', false)->assertDispatched('record-changed');
        expect($order->fresh()->status->value)->toBe('cancelled')->and(AuditLog::where('action', 'order.cancelled')->exists())->toBeTrue();
    });

    it('does not let a read-only role act inside the window', function () {
        $order = windowOrder($this->w);
        $f = $order->fulfillments->first();
        $auditor = staffWithRole('auditor');
        $this->actingAs($auditor);
        Livewire::test(OrderShow::class, ['order' => $order, 'drawer' => true])->assertOk()->call('advance', $f->id, 'accepted')->assertForbidden();
        expect($f->fresh()->status->value)->not->toBe('accepted');
    });
});

describe('customers side window', function () {
    it('opens from ?view=, shows the customer and clears view on close', function () {
        windowOrder($this->w);
        $this->actingAs($this->mgrA);
        $c = Livewire::withQueryParams(['view' => $this->w->customer->id])->test(CustomerIndex::class)
            ->assertSet('showView', true)->assertSet('shownId', $this->w->customer->id)->assertSee($this->w->customer->name)->assertSee(__('drawer_sales.open_full'));
        $c->call('closeView')->assertSet('showView', false)->assertSet('viewId', '');
        $c->call('openView', $this->w->customer->id)->assertSet('showView', true)->assertSet('viewId', $this->w->customer->id);
    });

    it('opens nothing for a customer outside the viewer’s branches, a non-customer or a bad id', function () {
        windowOrder($this->w);
        $this->actingAs($this->mgrB);
        Livewire::withQueryParams(['view' => $this->w->customer->id])->test(CustomerIndex::class)->assertSet('showView', false)->assertSet('viewId', '')->assertDontSee($this->w->customer->name);
        Livewire::test(CustomerIndex::class)->call('openView', $this->w->customer->id)->assertSet('showView', false)->assertDispatched('toast', type: 'error');
        $this->actingAs($this->admin);
        Livewire::test(CustomerIndex::class)->call('openView', $this->mgrA->id)->assertSet('showView', false); // staff, not a customer
        Livewire::test(CustomerIndex::class)->call('openView', 'not-a-uuid')->assertSet('showView', false);
        Livewire::test(CustomerShow::class, ['customer' => $this->w->customer, 'drawer' => true])->assertOk();
        $this->actingAs($this->mgrB);
        Livewire::test(CustomerShow::class, ['customer' => $this->w->customer, 'drawer' => true])->assertForbidden();
    });

    it('keeps personal data masked for a viewer without customers.edit, exactly as the page does', function () {
        windowOrder($this->w);
        $auditor = staffWithRole('auditor');
        $this->actingAs($auditor);
        $phone = (string) $this->w->customer->phone;
        $page = Livewire::test(CustomerShow::class, ['customer' => $this->w->customer])->html();
        $window = Livewire::test(CustomerShow::class, ['customer' => $this->w->customer, 'drawer' => true])->html();
        expect($page)->not->toContain($phone)->and($window)->not->toContain($phone);
        $this->actingAs($this->admin);
        expect(Livewire::test(CustomerShow::class, ['customer' => $this->w->customer, 'drawer' => true])->html())->toContain($phone);
    });

    it('suspends from the window with a reason, audits it and tells the list to refresh', function () {
        windowOrder($this->w);
        $this->actingAs($this->admin);
        Livewire::test(CustomerShow::class, ['customer' => $this->w->customer, 'drawer' => true])->call('askStatus', 'suspended')->assertSet('showStatus', true)
            ->call('changeStatus')->assertHasErrors('statusReason')
            ->set('statusReason', 'Repeated chargebacks')->call('changeStatus')->assertHasNoErrors()->assertSet('showStatus', false)->assertDispatched('record-changed');
        expect($this->w->customer->fresh()->status->value)->toBe('suspended')->and(AuditLog::where('action', 'customer.status_changed')->exists())->toBeTrue();
    });

    it('shows a short recent-orders list in the window, with no pages in the address bar', function () {
        windowOrder($this->w);
        $this->actingAs($this->mgrA);
        Livewire::test(CustomerShow::class, ['customer' => $this->w->customer, 'drawer' => true])->assertViewHas('orders', fn ($o) => $o instanceof Collection && $o->count() === 1)
            ->set('pane', 'loyalty')->assertViewHas('ledger', fn ($l) => $l instanceof Collection)
            ->assertViewHas('tabModel', 'pane');
    });

    it('opens an order on top of the customer window when the window asks for it', function () {
        $order = windowOrder($this->w);
        $this->actingAs($this->mgrA);
        Livewire::withQueryParams(['view' => $this->w->customer->id])->test(CustomerIndex::class)->dispatch('peek-order', id: $order->id)
            ->assertSet('showOrder', true)->assertSet('orderPeekId', $order->id)->assertSet('showView', true);
        $this->actingAs($this->mgrB);
        Livewire::test(CustomerIndex::class)->dispatch('peek-order', id: $order->id)->assertSet('showOrder', false);
    });
});

describe('order windows from the other sales lists', function () {
    it('opens an order from the dispatch board, the pickup counter and the fleet map for its branch only', function (string $component) {
        $order = windowOrder($this->w);
        $this->actingAs($this->mgrA);
        Livewire::test($component)->call('peekOrder', $order->id)->assertSet('showOrder', true)->assertSet('orderPeekId', $order->id);
        $this->actingAs($this->mgrB);
        Livewire::test($component)->call('peekOrder', $order->id)->assertSet('showOrder', false)->assertSet('orderPeekId', '')->assertDispatched('toast', type: 'error');
    })->with(['deliveries' => DeliveryIndex::class, 'pickup' => PickupIndex::class, 'fleet' => FleetMap::class]);

    it('closes the order window without touching the list', function () {
        $order = windowOrder($this->w);
        $this->actingAs($this->mgrA);
        Livewire::test(DeliveryIndex::class)->set('search', 'x')->call('peekOrder', $order->id)->call('closeOrderPeek')->assertSet('showOrder', false)->assertSet('search', 'x');
    });
});

describe('returns side window', function () {
    function windowReturn(object $w): ReturnRequest
    {
        $order = windowOrder($w);
        $f = $order->fulfillments->first();
        $item = $f->items()->first();

        return ReturnRequest::create(['number' => 'RT-WIN-1', 'order_id' => $order->id, 'user_id' => $order->user_id, 'branch_id' => $f->branch_id, 'status' => 'requested',
            'reason_code' => 'damaged', 'reason' => 'Scratched', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]]]);
    }

    it('opens from ?view=, keeps the order link and clears view on close', function () {
        $rr = windowReturn($this->w);
        $this->actingAs($this->mgrA);
        $c = Livewire::withQueryParams(['view' => $rr->id])->test(ReturnIndex::class)->assertSet('showDetail', true)->assertSet('selectedId', $rr->id)->assertSee('RT-WIN-1');
        $c->call('peekOrder', $rr->order_id)->assertSet('showOrder', true)->assertSet('showDetail', true);
        $c->call('closeDetail')->assertSet('showDetail', false)->assertSet('viewId', '');
    });

    it('opens nothing for another branch or a bad id, and a click on another branch’s return is forbidden', function () {
        $rr = windowReturn($this->w);
        $this->actingAs($this->mgrB);
        Livewire::withQueryParams(['view' => $rr->id])->test(ReturnIndex::class)->assertSet('showDetail', false)->assertSet('viewId', '')->assertDontSee('RT-WIN-1');
        Livewire::withQueryParams(['view' => 'nope'])->test(ReturnIndex::class)->assertSet('showDetail', false);
        Livewire::test(ReturnIndex::class)->call('select', $rr->id)->assertForbidden();
    });

    it('decides inside the window with the existing audit entry', function () {
        $rr = windowReturn($this->w);
        $this->actingAs($this->mgrA);
        Livewire::withQueryParams(['view' => $rr->id])->test(ReturnIndex::class)->call('decide', true)->assertHasNoErrors();
        expect($rr->fresh()->status)->toBe('approved')->and(AuditLog::where('action', 'return.approved')->exists())->toBeTrue();
        $this->actingAs(staffWithRole('auditor'));
        Livewire::withQueryParams(['view' => $rr->id])->test(ReturnIndex::class)->assertSet('showDetail', true)->call('receive')->assertForbidden();
    });
});

describe('driver step sheet', function () {
    it('is the shared window: opening sets it, closing any way clears the step', function () {
        $order = windowOrder($this->w);
        $f = $order->fulfillments->first();
        $driver = staffWithRole('delivery_employee', $this->w->a);
        BranchEmployee::where('user_id', $driver->id)->update(['is_delivery_driver' => true]);
        $this->actingAs($this->admin);
        $svc = app(DeliveryService::class);
        $orders = app(OrderService::class);
        foreach (['accepted', 'preparing', 'ready'] as $to) {
            $orders->transition($f->fresh(), FulfillmentStatus::from($to), $this->admin);
        }
        $d = $svc->assignDriver($svc->create($f->fresh(), $this->admin, 'internal', null, null), $driver, $this->admin);
        $this->actingAs($driver);
        Livewire::test(DriverConsole::class)->call('openSheet', $d->id, 'deliver')->assertSet('showSheet', true)->assertSet('sheet', 'deliver')
            ->call('closeSheet')->assertSet('showSheet', false)->assertSet('sheet', null)->assertSet('activeId', null);
    });
});
