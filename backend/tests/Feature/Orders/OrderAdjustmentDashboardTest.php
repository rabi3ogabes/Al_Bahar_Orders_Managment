<?php

require_once __DIR__.'/AdjustmentFixtures.php';

use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Payments\Models\Refund;
use App\Livewire\Admin\Sales\OrderAdjust;
use App\Livewire\Admin\Sales\OrderShow;
use Livewire\Livewire;

/** The dashboard's "Adjust items": the same service as the staff app, so the same rules, the same messages, the same refund. */

function adjustWindow(object $a, $user = null)
{
    return Livewire::actingAs($user ?? $a->mgr)->test(OrderAdjust::class, ['fulfillmentId' => $a->f->id]);
}

it('offers "Adjust items" on the order page to the branch that is preparing it, and to nobody else', function () {
    $a = adjustmentWorld();
    Livewire::actingAs($a->mgr)->test(OrderShow::class, ['order' => $a->order])->assertSee(__('adjust_admin.button'))->assertSeeLivewire(OrderAdjust::class);
    Livewire::actingAs(staffWithRole('super_admin'))->test(OrderShow::class, ['order' => $a->order])->assertSee(__('adjust_admin.button'));
    // an auditor sees orders but cannot change them; another branch's manager does not even see the order
    Livewire::actingAs(staffWithRole('auditor'))->test(OrderShow::class, ['order' => $a->order])->assertDontSee(__('adjust_admin.button'));
    Livewire::actingAs(staffWithRole('order_manager', $a->w->b))->test(OrderShow::class, ['order' => $a->order])->assertForbidden();

    // not once the parcel is ready
    actingAsStaff($a->mgr);
    $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'ready'])->assertOk();
    Livewire::actingAs($a->mgr)->test(OrderShow::class, ['order' => $a->order->fresh()])->assertDontSee(__('adjust_admin.button'));
});

it('reduces, replaces and removes from the window, shows the new total before confirming, then does exactly what the API does', function () {
    $a = adjustmentWorld(['qty' => [3, 1]]);
    [$p, $v] = substituteFor($a->w, '600.00');
    $w = adjustWindow($a)->call('open')->assertSet('show', true)->assertSee($a->l1->name_en)->assertSee($a->l2->name_en)->assertSee(__('adjust_admin.act_keep'));

    // nothing chosen yet: nothing to confirm
    $w->call('confirm')->assertHasErrors('lines');

    $w->call('choose', $a->l1->id, 'reduce')->assertSet("lines.{$a->l1->id}.action", 'reduce')->assertSet("lines.{$a->l1->id}.to", 2)
        ->call('step', $a->l1->id, -1, 3)->assertSet("lines.{$a->l1->id}.to", 1)
        ->call('step', $a->l1->id, -1, 3)->assertSet("lines.{$a->l1->id}.to", 1) // never below one: that is "remove"
        ->assertSee('QAR 1,000.00')->assertSee('QAR 3,275.00'); // the preview: total now 3275, a sofa less ...
    $w->call('choose', $a->l2->id, 'replace')->assertSet('searching', $a->l2->id)->set('search', $p->trans('name'))->assertSee($p->trans('name'))->assertSee(__('adjust_admin.in_stock', ['n' => 10]));
    // a replacement that was not chosen yet cannot be confirmed
    $w->call('confirm')->assertHasErrors('lines');
    $w->call('pick', $a->l2->id, $v->id)->assertSet("lines.{$a->l2->id}.variant", $v->id)->assertSet('searching', null)->assertSee('QAR 250.00'); // never more than the lamp's own 250
    $w->set('reason', 'not_found')->set('note', 'Check the back store')->call('confirm')->assertHasNoErrors()->assertSet('show', false)->assertDispatched('order-adjusted');

    $o = $a->order->fresh();
    $rev = OrderRevision::where('order_id', $o->id)->sole();
    expect($o->grand_total)->toBe('1275.00')->and($rev->reason_code)->toBe('not_found')->and($rev->note)->toBe('Check the back store')->and($rev->actor_id)->toBe($a->mgr->id)
        ->and($a->l1->fresh()->quantity)->toBe(1)->and($a->l2->fresh()->quantity)->toBe(0)->and(OrderItem::where('replaces_order_item_id', $a->l2->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('event', 'order.items_changed')->count())->toBe(1);
});

it('removes a line from the window and lets staff go back to keeping it before confirming', function () {
    $a = adjustmentWorld();
    $w = adjustWindow($a)->call('open')->call('choose', $a->l2->id, 'remove')->assertSee(__('adjust_admin.will_remove'))->assertSee('QAR 2,025.00')
        ->call('choose', $a->l2->id, 'keep')->assertDontSee(__('adjust_admin.will_remove'))->call('confirm')->assertHasErrors('lines');
    expect($a->l2->fresh()->quantity)->toBe(1);
    $w->call('choose', $a->l2->id, 'remove')->call('confirm')->assertHasNoErrors();
    expect($a->l2->fresh()->quantity)->toBe(0);
});

it('warns when nothing will be left, and what the refund is', function () {
    $a = adjustmentWorld(['payment' => 'sadad']);
    $w = adjustWindow($a)->call('open')->call('choose', $a->l1->id, 'remove')->assertDontSee(__('adjust_admin.cancels_order'))->assertSee(__('adjust_admin.refund_notice', ['amount' => 'QAR 2,000.00']))
        ->call('choose', $a->l2->id, 'remove')->assertSee(__('adjust_admin.cancels_order'));
    $w->call('confirm')->assertHasNoErrors();
    expect($a->order->fresh()->status->value)->toBe('cancelled')->and(Refund::where('order_id', $a->order->id)->sum('amount'))->toEqual(2265);
});

it('refuses a user without orders.edit on that branch, whatever the screen says', function () {
    $a = adjustmentWorld();
    adjustWindow($a, staffWithRole('auditor'))->call('open')->assertForbidden();
    adjustWindow($a, staffWithRole('order_manager', $a->w->b))->call('open')->assertForbidden();
    expect(OrderRevision::count())->toBe(0);
});

it('tells staff when the order moved on while the window was open, and shows the order as it is now', function () {
    $a = adjustmentWorld();
    $w = adjustWindow($a)->call('open')->call('choose', $a->l1->id, 'reduce');
    actingAsStaff($a->mgr);
    $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'ready'])->assertOk(); // another screen got there first
    $w->call('confirm')->assertSet('show', false)->assertDispatched('toast')->assertDispatched('order-adjusted');
    expect($a->l1->fresh()->quantity)->toBe(2)->and(OrderRevision::count())->toBe(0);

    // opening it now says why not
    adjustWindow($a)->call('open')->assertSet('show', false)->assertDispatched('toast');
});

it('repeating the same confirm does nothing twice', function () {
    $a = adjustmentWorld();
    $w = adjustWindow($a)->call('open')->call('choose', $a->l1->id, 'reduce')->call('confirm')->assertHasNoErrors();
    $w->call('confirm'); // the window kept its choices on a stale tab: the service refuses the repeat
    expect(OrderRevision::count())->toBe(1)->and($a->l1->fresh()->quantity)->toBe(1);
});

it('shows the history of item changes on the order page: who, when, which branch, why, what, the money and the refund', function (string $locale) {
    $a = adjustmentWorld(['payment' => 'sadad']);
    [$p, $v] = substituteFor($a->w, '600.00');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]], 'out_of_stock',
        ['note' => 'Only one left on the shelf'])->assertOk();
    $refund = Refund::first();

    app()->setLocale($locale); // (the API call above reset it)
    $page = Livewire::actingAs($a->mgr)->test(OrderShow::class, ['order' => $a->order->fresh()]);
    $page->assertSee(__('adjust_admin.history_title'))->assertSee(__('adjust_admin.revision_n', ['n' => 1]))->assertSee($a->mgr->name)->assertSee('Only one left on the shelf')
        ->assertSee(__('adjustments.reason.out_of_stock'))->assertSee($a->w->a->localized('name'))->assertSee($refund->number)->assertSee(__('status.requested'))
        ->assertSee(__('adjust_admin.badge_reduced', ['n' => 2]))->assertSee(__('adjust_admin.badge_replaced'))->assertSee(__('adjust_admin.badge_replacement'))
        ->assertSee(__('adjust_admin.was_total'))->assertSee(__('sales_admin.hist_items_changed', ['branch' => $a->w->a->code, 'n' => 1]))
        ->assertSee(trans('adjustments.line.reduce', ['name' => $a->l1->name($locale), 'from' => 2, 'to' => 1]));
    // inside a side window too
    app()->setLocale($locale);
    Livewire::actingAs($a->mgr)->test(OrderShow::class, ['order' => $a->order->fresh(), 'drawer' => true])->assertSee(__('adjust_admin.history_title'))->assertSee(__('adjust_admin.badge_replacement'));
})->with(['en', 'ar']);

it('does not show a history section on an order nobody adjusted', function () {
    $a = adjustmentWorld();
    Livewire::actingAs($a->mgr)->test(OrderShow::class, ['order' => $a->order])->assertDontSee(__('adjust_admin.history_title'));
});

it('renders the window in Arabic with right-to-left friendly markup and both languages\' labels', function () {
    $a = adjustmentWorld();
    app()->setLocale('ar');
    adjustWindow($a)->call('open')->assertSee(__('adjust_admin.act_replace'))->assertSee(__('adjust_admin.act_fewer'))->assertSee(__('adjustments.reason.not_found'))->assertSee('نفد من المخزون');
});
