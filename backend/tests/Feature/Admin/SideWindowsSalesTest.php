<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Sales\OrderIndex;
use App\Livewire\Admin\Sales\OrderShow;
use Database\Seeders\RolesAndPermissionsSeeder;
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
