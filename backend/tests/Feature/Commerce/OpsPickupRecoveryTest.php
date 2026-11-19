<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\PickupVerification;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Models\OrderItem;
use App\Livewire\Admin\Sales\OrderShow;
use App\Support\DomainException;
use Livewire\Livewire;

require_once __DIR__.'/../Orders/OpsFixtures.php';

/** A pickup order of branch A (3 sofas + 1 lamp), ready, its pass issued. Returns [order, part, world]; the counter staff of branch A are `pickup_employee`s. */
function opsReadyPickup(string $payment = 'pay_at_pickup'): array
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 3]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $order = Order::with('fulfillments.pickup')->find(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => $payment])->assertCreated()->json('data.order.id'));
    $f = $order->fulfillments->first();
    app(PickupService::class)->markReady($f, staffWithRole('pickup_employee', $w->a));

    return [$order->fresh(), $f->fresh(), $w];
}

function opsLast4(Order $order): string
{
    return substr(preg_replace('/\D+/', '', (string) $order->customer_phone), -4);
}

// ── Bug 8: re-issue the pass ─────────────────────────────────────────────────────────────────────────────────────────────

it('re-issues an expired pass: the old one stops working, the new one verifies, the customer is told, the audit log says who and why', function () {
    [$order, $f, $w] = opsReadyPickup();
    $counter = staffWithRole('pickup_employee', $w->a);
    $svc = app(PickupService::class);
    $old = $svc->pass($f->pickup->fresh())['qr_payload'];
    $told = Notification::where('user_id', $w->customer->id)->where('event', 'order.ready_for_pickup')->count();

    $this->travel(config('platform.pickup.token_ttl_hours') + 1)->hours();
    expect($svc->verify($old, $counter, $w->a->id)['result'])->toBe('expired');

    $r = $svc->reissue($f, $counter, 'customer came back after the weekend');
    $new = $svc->pass($r)['qr_payload'];
    expect($new)->not->toBe($old)->and($svc->verify($old, $counter, $w->a->id)['result'])->toBe('invalid_token')->and($svc->verify($new, $counter, $w->a->id)['result'])->toBe('success')
        ->and($r->token_expires_at->isFuture())->toBeTrue()->and($r->status)->toBe('ready');
    $audit = AuditLog::where('action', 'pickup.pass_reissued')->firstOrFail();
    expect($audit->actor_id)->toBe($counter->id)->and($audit->metadata['reason'])->toBe('customer came back after the weekend')->and($audit->metadata['was_expired'])->toBeTrue()
        ->and($audit->metadata['order'])->toBe($order->number);
    expect(Notification::where('user_id', $w->customer->id)->where('event', 'order.ready_for_pickup')->count())->toBe($told + 1);

    // The customer's own app asks for the pass and now gets the new one.
    actingAsCustomer($w->customer);
    $this->getJson("/api/v1/orders/{$order->id}/fulfillments/{$f->id}/pickup-pass")->assertOk()->assertJsonPath('data.qr_payload', $new);
});

it('never gives two passes the same token, even when re-issued within the same second', function () {
    [$order, $f, $w] = opsReadyPickup();
    $counter = staffWithRole('pickup_employee', $w->a);
    $svc = app(PickupService::class);
    $first = $svc->pass($f->pickup->fresh())['qr_payload'];
    $svc->reissue($f, $counter);
    $second = $svc->pass($f->pickup->fresh())['qr_payload'];
    $svc->reissue($f, $counter);
    $third = $svc->pass($f->pickup->fresh())['qr_payload'];
    expect(array_unique([$first, $second, $third]))->toHaveCount(3)->and($svc->verify($first, $counter, $w->a->id)['result'])->toBe('invalid_token')
        ->and($svc->verify($second, $counter, $w->a->id)['result'])->toBe('invalid_token')->and($svc->verify($third, $counter, $w->a->id)['result'])->toBe('success');
});

it('keeps a part that was handed over in pieces as it is: the quantities handed over and its status stay', function () {
    [$order, $f, $w] = opsReadyPickup();
    $counter = staffWithRole('pickup_employee', $w->a);
    $svc = app(PickupService::class);
    $item = $f->items()->where('product_variant_id', $w->v1->id)->first();
    $svc->handover($f->pickup->fresh(), $counter, [$item->id => 1], 'Noor', collection: ['tender' => 'cash', 'amount' => $order->grand_total]);

    $r = $svc->reissue($f->fresh(), $counter);
    expect($r->status)->toBe('partially_collected')->and($item->fresh()->quantity_handed_over)->toBe(1)->and($f->fresh()->status->value)->toBe('pickup_ready');
    $svc->handover($r, $counter, [], 'Noor'); // the rest can still be collected with the new pass
    expect($f->fresh()->status->value)->toBe('picked_up');
});

it('refuses to re-issue for the wrong people, another branch, or a part that is not waiting at the counter', function () {
    [$order, $f, $w] = opsReadyPickup();
    $svc = app(PickupService::class);
    expect(fn () => $svc->reissue($f, staffWithRole('sales_employee', $w->a)))->toThrow(DomainException::class)        // pickup.view only
        ->and(fn () => $svc->reissue($f, staffWithRole('pickup_employee', $w->b)))->toThrow(DomainException::class);   // another branch
    $before = $f->pickup->fresh()->token_hash;
    expect($f->pickup->fresh()->token_hash)->toBe($before);

    $counter = staffWithRole('pickup_employee', $w->a);
    $svc->handover($f->pickup->fresh(), $counter, [], 'Noor', collection: ['tender' => 'cash', 'amount' => $order->grand_total]);
    expect(fn () => $svc->reissue($f->fresh(), $counter))->toThrow(DomainException::class, __('errors.pickup_not_ready')); // already collected
});

it('shows "Re-issue pass" on the order page when the pass expired, and re-issues from it', function () {
    [$order, $f, $w] = opsReadyPickup();
    $counter = staffWithRole('pickup_employee', $w->a);
    $this->travel(config('platform.pickup.token_ttl_hours') + 1)->hours();

    $this->actingAs($counter);
    $page = Livewire::test(OrderShow::class, ['order' => $order])->assertSee(__('ops_integrity.ui.pass_expired'))->assertSee(__('ops_integrity.ui.reissue_pass'))
        ->assertViewHas('actions', fn (array $a) => $a[$f->id]['reissue'] === true && $a[$f->id]['manual'] === true);
    $page->call('reissuePass', $f->id)->assertHasNoErrors()->assertDispatched('toast', type: 'success');
    expect($f->pickup->fresh()->token_expires_at->isFuture())->toBeTrue();
    Livewire::test(OrderShow::class, ['order' => $order])->assertDontSee(__('ops_integrity.ui.pass_expired'));

    // Someone without the pickup right sees neither action and cannot call it.
    $this->actingAs(staffWithRole('sales_employee', $w->a));
    Livewire::test(OrderShow::class, ['order' => $order])->assertViewHas('actions', fn (array $a) => $a[$f->id]['reissue'] === false && $a[$f->id]['manual'] === false)
        ->call('reissuePass', $f->id)->assertForbidden();
});

// ── Bug 8: a manager hands over without the pass ──────────────────────────────────────────────────────────────────────────────

it('lets a manager hand over with the order number and the last four digits of the phone, even after the pass expired, with the reason on record', function () {
    [$order, $f, $w] = opsReadyPickup();
    $manager = staffWithRole('branch_manager', $w->a);
    $this->travel(config('platform.pickup.token_ttl_hours') + 1)->hours();
    expect(app(PickupService::class)->verify(app(PickupService::class)->pass($f->pickup->fresh())['qr_payload'], $manager, $w->a->id)['result'])->toBe('expired');

    $r = app(PickupService::class)->handoverByDetails(strtolower($order->number), ' '.opsLast4($order), $manager, $w->a->id, 'pass expired, customer at the counter', [], 'Noor', ['tender' => 'cash', 'amount' => $order->grand_total]);
    expect($r->status)->toBe('collected')->and($f->fresh()->status->value)->toBe('picked_up')->and($order->fresh()->status->value)->toBe('completed')->and($order->fresh()->payment_status->value)->toBe('paid')
        ->and(stockOf($w->a, $w->v1))->toBe(['on_hand' => 2, 'reserved' => 0]);
    $audit = AuditLog::where('action', 'pickup.manual_handover')->firstOrFail();
    expect($audit->actor_id)->toBe($manager->id)->and($audit->metadata['reason'])->toBe('pass expired, customer at the counter')->and($audit->metadata['pass_expired'])->toBeTrue()
        ->and($audit->metadata['checked'])->toBe('phone_last4')->and($audit->metadata['order'])->toBe($order->number)
        ->and(PickupVerification::where('result', 'manual')->where('verified_by', $manager->id)->where('collector_name', 'Noor')->count())->toBe(1);
});

it('can hand over part of the goods with the fallback, and the rest later', function () {
    [$order, $f, $w] = opsReadyPickup();
    $manager = staffWithRole('branch_manager', $w->a);
    $svc = app(PickupService::class);
    $sofas = $f->items()->where('product_variant_id', $w->v1->id)->first();
    $r = $svc->handoverByDetails($order->number, opsLast4($order), $manager, $w->a->id, 'phone is dead', [$sofas->id => 2], null, ['tender' => 'cash', 'amount' => $order->grand_total]);
    expect($r->status)->toBe('partially_collected')->and($sofas->fresh()->quantity_handed_over)->toBe(2)->and($f->fresh()->status->value)->toBe('pickup_ready');
    $r = $svc->handoverByDetails($order->number, opsLast4($order), $manager, $w->a->id, 'the rest', []);
    expect($r->status)->toBe('collected')->and($f->fresh()->status->value)->toBe('picked_up');
});

it('says only "details do not match" for a wrong ending, another branch\'s order, an unknown number or a missing phone, and records each try', function () {
    [$order, $f, $w] = opsReadyPickup();
    $manager = staffWithRole('branch_manager', $w->a);
    $svc = app(PickupService::class);
    $wrong = str_pad((string) (((int) opsLast4($order) + 1) % 10000), 4, '0', STR_PAD_LEFT);
    $msg = __('ops_integrity.errors.pickup_details_mismatch');

    expect(fn () => $svc->handoverByDetails($order->number, $wrong, $manager, $w->a->id, 'reason'))->toThrow(DomainException::class, $msg)
        ->and(fn () => $svc->handoverByDetails('AB0000000', opsLast4($order), $manager, $w->a->id, 'reason'))->toThrow(DomainException::class, $msg)
        ->and(fn () => $svc->handoverByDetails($order->number, '12', $manager, $w->a->id, 'reason'))->toThrow(DomainException::class, $msg);
    $order->forceFill(['customer_phone' => null])->save();
    expect(fn () => $svc->handoverByDetails($order->number, '0000', $manager, $w->a->id, 'reason'))->toThrow(DomainException::class, $msg);
    expect($f->fresh()->status->value)->toBe('pickup_ready')->and(AuditLog::where('action', 'pickup.manual_handover_failed')->count())->toBe(4)
        ->and(PickupVerification::where('result', 'manual_failed')->count())->toBe(4);

    // The manager of another branch cannot use it for this branch's order (not even with the right digits).
    $order->forceFill(['customer_phone' => '+97455123456'])->save();
    $other = staffWithRole('branch_manager', $w->b);
    expect(fn () => $svc->handoverByDetails($order->number, '3456', $other, $w->a->id, 'reason'))->toThrow(DomainException::class, __('errors.branch_forbidden'))
        ->and(fn () => $svc->handoverByDetails($order->number, '3456', $other, $w->b->id, 'reason'))->toThrow(DomainException::class, $msg);
});

it('locks after five wrong tries for an order, so the four digits cannot be guessed, and the right digits are refused until it unlocks', function () {
    [$order, $f, $w] = opsReadyPickup();
    $manager = staffWithRole('branch_manager', $w->a);
    $svc = app(PickupService::class);
    $right = opsLast4($order);
    $wrong = fn (int $n) => str_pad((string) ((((int) $right) + $n) % 10000), 4, '0', STR_PAD_LEFT);
    foreach ([1, 2, 3, 4, 5] as $n) {
        expect(fn () => $svc->handoverByDetails($order->number, $wrong($n), $manager, $w->a->id, 'reason'))->toThrow(DomainException::class, __('ops_integrity.errors.pickup_details_mismatch'));
    }
    expect(fn () => $svc->handoverByDetails($order->number, $right, $manager, $w->a->id, 'reason', [], null, ['tender' => 'cash', 'amount' => $order->grand_total]))
        ->toThrow(DomainException::class, __('ops_integrity.errors.pickup_manual_locked', ['minutes' => 15]));
    expect($f->fresh()->status->value)->toBe('pickup_ready');

    $this->travel(PickupService::MANUAL_LOCK_MINUTES + 1)->minutes();
    $svc->handoverByDetails($order->number, $right, $manager, $w->a->id, 'reason', [], null, ['tender' => 'cash', 'amount' => $order->grand_total]);
    expect($f->fresh()->status->value)->toBe('picked_up');
});

it('needs a reason, the pickup.approve right at the branch, and waits while a cancellation request is undecided', function () {
    [$order, $f, $w] = opsReadyPickup();
    $svc = app(PickupService::class);
    $manager = staffWithRole('branch_manager', $w->a);
    expect(fn () => $svc->handoverByDetails($order->number, opsLast4($order), $manager, $w->a->id, '  '))->toThrow(DomainException::class, __('ops_integrity.errors.pickup_reason_required'))
        ->and(fn () => $svc->handoverByDetails($order->number, opsLast4($order), staffWithRole('sales_employee', $w->a), $w->a->id, 'reason'))->toThrow(DomainException::class, __('errors.branch_forbidden'));

    OrderCancellation::create(['order_id' => $order->id, 'requested_by' => $w->customer->id, 'requested_by_type' => 'customer', 'reason_code' => 'changed_mind', 'status' => 'requested']);
    expect(fn () => $svc->handoverByDetails($order->number, opsLast4($order), $manager, $w->a->id, 'reason', [], null, ['tender' => 'cash', 'amount' => $order->grand_total]))
        ->toThrow(DomainException::class, __('ops_integrity.errors.cancellation_pending'));
    expect(OrderItem::where('order_id', $order->id)->sum('quantity_handed_over'))->toBe(0);
});

it('does the whole fallback from the order page: a dialog with the last four digits and a reason, then the part is picked up', function () {
    [$order, $f, $w] = opsReadyPickup();
    $this->actingAs(staffWithRole('branch_manager', $w->a));
    $page = Livewire::test(OrderShow::class, ['order' => $order])->assertSee(__('ops_integrity.ui.manual_handover'));

    $page->call('open', 'pickup_manual', $f->id)->assertSet('showDialog', true)->assertSet('form.collector', $order->customer_name)
        ->set('form.phone_last4', '12ab')->set('form.reason', 'x')->call('confirmDialog')->assertHasErrors(['form.phone_last4', 'form.reason']);
    $page->set('form.phone_last4', opsLast4($order) === '0000' ? '1111' : '0000')->set('form.reason', 'pass expired')->call('confirmDialog')->assertDispatched('toast', type: 'error');
    expect($f->fresh()->status->value)->toBe('pickup_ready');

    $page->set('form.phone_last4', opsLast4($order))->set('form.reason', 'pass expired')->call('confirmDialog')->assertHasNoErrors()->assertSet('showDialog', false);
    expect($f->fresh()->status->value)->toBe('picked_up')->and($order->fresh()->payment_status->value)->toBe('paid')->and(AuditLog::where('action', 'pickup.manual_handover')->count())->toBe(1);
});

it('does not offer the fallback for a pickup order that is still waiting for its online payment', function () {
    $w = commerceWorld();
    opsSadad();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $order = Order::with('fulfillments.pickup')->find(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'sadad'])->assertCreated()->json('data.order.id'));
    $this->actingAs(staffWithRole('branch_manager', $w->a));
    Livewire::test(OrderShow::class, ['order' => $order])->assertViewHas('actions', fn (array $a) => $a[$order->fulfillments->first()->id]['manual'] === false);
});
