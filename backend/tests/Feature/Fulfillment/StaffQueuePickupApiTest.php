<?php

use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;

/*
| The pickup counter steps of the branch staff app: a new pass (re-issue) and the manager's hand-over without the pass, over the API
| (the rules themselves are tested with the dashboard in tests/Feature/Orders/Ops*Test.php).
*/

/** The last four digits of the phone number on the order, what the customer is asked for at the counter. */
function staffLast4(Order $order): string
{
    return substr((string) preg_replace('/\D+/', '', (string) $order->customer_phone), -4);
}

/** A pay-at-pickup order of branch A (3 sofas + 1 lamp) with its pass issued; returns [order, part, manager, world]. */
function staffPickupWorld(): array
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 3]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $order = Order::with('fulfillments.pickup')->find(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
    $f = $order->fulfillments->first();
    $mgr = actingAsStaff(staffWithRole('branch_manager', $w->a));
    test()->postJson("/api/v1/admin/fulfillments/{$f->id}/pickup-ready")->assertOk();

    return [$order->fresh(), $f->fresh(), $mgr, $w];
}

it('re-issues a pass: the old one stops working at once and the new one is given for a full lifetime', function () {
    [$order, $f, , $w] = staffPickupWorld();
    $old = app(PickupService::class)->pass($f->pickup->fresh())['qr_payload'];

    $res = $this->postJson("/api/v1/admin/fulfillments/{$f->id}/pickup-reissue", ['reason' => 'Customer lost the phone'])->assertOk();

    expect($res->json('data.status'))->toBe('ready')->and($res->json('data.token_expires_at'))->not->toBeNull()->and($res->json('data.message'))->not->toBe('');
    $this->postJson('/api/v1/admin/pickup/verify', ['code' => $old, 'branch_id' => $w->a->id])->assertStatus(422)->assertJsonPath('data.result', 'invalid_token');
    $new = app(PickupService::class)->pass($f->pickup->fresh())['qr_payload'];
    expect($new)->not->toBe($old);
    $this->postJson('/api/v1/admin/pickup/verify', ['code' => $new, 'branch_id' => $w->a->id])->assertOk()->assertJsonPath('data.result', 'success');
});

it('refuses a re-issue to someone without pickup.edit, and for a part that is not ready', function () {
    [, $f, , $w] = staffPickupWorld();
    actingAsStaff(staffWithRole('delivery_employee', $w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/pickup-reissue")->assertForbidden();

    $f->forceFill(['status' => 'picked_up'])->save();
    $f->pickup->forceFill(['status' => 'collected'])->save();
    actingAsStaff(staffWithRole('branch_manager', $w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/pickup-reissue")->assertStatus(409)->assertJsonPath('code', 'pickup_not_ready');
});

it('lets a manager hand over without the pass: the order number, the last 4 digits of the phone and a reason, and the money first', function () {
    [$order, $f, , $w] = staffPickupWorld();
    $body = ['branch_id' => $w->a->id, 'order_number' => $order->number, 'phone_last4' => staffLast4($order), 'reason' => 'Pass expired, customer at the counter'];

    // Pay at pickup: the money is collected in the same step, as at the scanner.
    $this->postJson('/api/v1/admin/pickup/manual-handover', $body)->assertStatus(409)->assertJsonPath('code', 'pickup_payment_required');
    $done = $this->postJson('/api/v1/admin/pickup/manual-handover', $body + ['collection' => ['tender' => 'cash', 'amount' => $order->grand_total]])->assertOk();

    expect($done->json('data.status'))->toBe('collected')->and($f->fresh()->status->value)->toBe('picked_up')
        ->and(DB::table('audit_logs')->where('action', 'pickup.manual_handover')->count())->toBe(1);
});

it('refuses a wrong detail without saying which, writes nothing and locks after five tries', function () {
    [$order, $f, , $w] = staffPickupWorld();
    $wrong = staffLast4($order) === '0000' ? '1111' : '0000';
    $body = ['branch_id' => $w->a->id, 'order_number' => $order->number, 'phone_last4' => $wrong, 'reason' => 'Lost the pass'];

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/admin/pickup/manual-handover', $body)->assertStatus(422)->assertJsonPath('code', 'pickup_details_mismatch');
    }
    $this->postJson('/api/v1/admin/pickup/manual-handover', ['phone_last4' => staffLast4($order)] + $body)->assertStatus(429)->assertJsonPath('code', 'pickup_manual_locked');
    expect($f->fresh()->status->value)->toBe('pickup_ready');
});

it('asks for the reason and for exactly four digits', function () {
    [$order, , , $w] = staffPickupWorld();
    $body = ['branch_id' => $w->a->id, 'order_number' => $order->number, 'phone_last4' => staffLast4($order)];

    $this->postJson('/api/v1/admin/pickup/manual-handover', $body + ['reason' => ' '])->assertStatus(422)->assertJsonPath('code', 'pickup_reason_required');
    $this->postJson('/api/v1/admin/pickup/manual-handover', ['phone_last4' => '34'] + $body + ['reason' => 'Lost the pass'])->assertStatus(422)->assertJsonValidationErrors('phone_last4');
});

it('is for managers only, and waits while the customer\'s request to cancel is undecided', function () {
    [$order, , , $w] = staffPickupWorld();
    $body = ['branch_id' => $w->a->id, 'order_number' => $order->number, 'phone_last4' => staffLast4($order), 'reason' => 'Lost the pass', 'collection' => ['tender' => 'cash', 'amount' => $order->grand_total]];

    actingAsStaff(staffWithRole('delivery_employee', $w->a));
    $this->postJson('/api/v1/admin/pickup/manual-handover', $body)->assertForbidden();

    OrderCancellation::create(['order_id' => $order->id, 'reason_code' => 'changed_mind', 'reason' => 'Changed my mind', 'requested_by_type' => 'customer', 'status' => 'requested']);
    actingAsStaff(staffWithRole('branch_manager', $w->a));
    $this->postJson('/api/v1/admin/pickup/manual-handover', $body)->assertStatus(409)->assertJsonPath('code', 'cancellation_pending');
});
