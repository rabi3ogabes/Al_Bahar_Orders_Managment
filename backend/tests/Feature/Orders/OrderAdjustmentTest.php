<?php

require_once __DIR__.'/AdjustmentFixtures.php';

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Models\LoyaltyTransaction;
use App\Domain\Marketing\Models\OrderCoupon;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Orders\Models\OrderStatusHistory;
use App\Domain\Orders\Services\OrderAdjustmentService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\RefundService;
use App\Support\Money;
use Illuminate\Support\Str;

/** Money and lines: what an adjustment does to the order, exactly. Delivery 15 and the cash-on-delivery fee 10 stay whatever happens. */
it('reduces a line: totals follow the line, fees stay, and everything is recorded', function () {
    $a = adjustmentWorld();
    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]], 'out_of_stock', ['note' => 'Only one left on the shelf'])->assertOk();

    $r->assertJsonPath('data.applied', true)->assertJsonPath('data.preview', false)->assertJsonPath('data.revision.number', 1)
        ->assertJsonPath('data.before.grand_total.amount', '2275.00')->assertJsonPath('data.after.grand_total.amount', '1275.00')
        ->assertJsonPath('data.after.subtotal.amount', '1250.00')->assertJsonPath('data.difference.amount', '-1000.00')
        ->assertJsonPath('data.refund', null)->assertJsonPath('data.fulfillment_cancelled', false)->assertJsonPath('data.order_cancelled', false)->assertJsonPath('data.customer_notified', true)
        ->assertJsonPath('data.lines.0.action', 'reduce')->assertJsonPath('data.lines.0.from_quantity', 2)->assertJsonPath('data.lines.0.to_quantity', 1)->assertJsonPath('data.lines.0.replacement', null);

    $o = $a->order->fresh();
    expect($o->subtotal)->toBe('1250.00')->and($o->grand_total)->toBe('1275.00')->and($o->delivery_fee_total)->toBe('15.00')->and($o->service_fee_total)->toBe('10.00')
        ->and($o->adjustment_count)->toBe(1)->and($o->status->value)->toBe('processing')
        ->and($a->f->fresh()->subtotal)->toBe('1250.00');
    $l1 = $a->l1->fresh();
    expect($l1->quantity)->toBe(1)->and($l1->line_subtotal)->toBe('1000.00')->and($l1->line_total)->toBe('1000.00')->and($l1->original_quantity)->toBe(2)
        ->and($l1->adjustment_kind)->toBe('reduced')->and($l1->adjustment_reason)->toBe('out_of_stock')->and($a->l2->fresh()->quantity)->toBe(1);

    $rev = OrderRevision::where('order_id', $o->id)->firstOrFail();
    expect($rev->number)->toBe(1)->and($rev->reason_code)->toBe('out_of_stock')->and($rev->note)->toBe('Only one left on the shelf')->and($rev->actor_id)->toBe($a->mgr->id)
        ->and($rev->before['grand_total'])->toBe('2275.00')->and($rev->after['grand_total'])->toBe('1275.00')->and($rev->difference)->toBe('-1000.00')
        ->and($rev->lines)->toHaveCount(1)->and($rev->lines[0]->stock_corrected)->toBe(1);
    $history = OrderStatusHistory::where('order_id', $o->id)->where('kind', 'items_changed')->get();
    expect($history)->toHaveCount(1)->and($history[0]->is_customer_visible)->toBeTrue()->and($history[0]->to_status)->toBe('items_changed');
    expect(AuditLog::where('action', 'order.items_adjusted')->where('auditable_id', $o->id)->count())->toBe(1);
});

it('previews exactly what applying does, and writes nothing', function () {
    $a = adjustmentWorld();
    [$p, $v] = substituteFor($a->w);
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]];
    $snapshot = fn () => [DB::table('orders')->get()->toJson(), DB::table('order_items')->orderBy('id')->get()->toJson(), DB::table('order_fulfillments')->get()->toJson(),
        DB::table('stock_movements')->count(), DB::table('stock_reservations')->orderBy('id')->get()->toJson(), DB::table('branch_inventory')->orderBy('id')->get()->toJson(),
        DB::table('order_revisions')->count(), DB::table('order_status_history')->count(), DB::table('audit_logs')->count(), DB::table('notifications')->count(),
        DB::table('notification_logs')->count(), DB::table('refunds')->count(), DB::table('payments')->get()->toJson()];

    $before = $snapshot();
    $preview = postAdjust($a, $changes, 'quality', ['preview' => true])->assertOk()->assertJsonPath('data.preview', true)->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.customer_notified', false)->assertJsonPath('data.revision.id', null)->assertJsonPath('data.revision.number', 1)->json('data');
    expect($snapshot())->toBe($before);

    $applied = postAdjust($a, $changes, 'quality')->assertOk()->json('data');
    foreach (['lines', 'before', 'after', 'difference', 'refund', 'fulfillment_cancelled', 'order_cancelled'] as $key) {
        expect($applied[$key])->toEqual($preview[$key]);
    }
    expect($applied['applied'])->toBeTrue();
});

it('works the totals out per line with a percentage coupon, so discount follows what is left', function () {
    $a = adjustmentWorld(['coupon' => ['percentage', '10']]); // discount 225 = 200 on the sofas + 25 on the lamp
    expect($a->order->discount_total)->toBe('225.00')->and($a->order->grand_total)->toBe('2050.00')->and($a->l1->discount_amount)->toBe('200.00');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()
        ->assertJsonPath('data.after.discount_total.amount', '125.00')->assertJsonPath('data.after.grand_total.amount', '1150.00')->assertJsonPath('data.difference.amount', '-900.00');

    $o = $a->order->fresh();
    expect($a->l1->fresh()->discount_amount)->toBe('100.00')->and($a->l1->fresh()->line_total)->toBe('900.00')
        ->and(OrderItem::where('order_id', $o->id)->sum('discount_amount'))->toEqual(125)
        ->and(OrderCoupon::where('order_id', $o->id)->first()->discount_amount)->toBe('125.00')
        ->and(CouponUsage::where('order_id', $o->id)->first()->discount_amount)->toBe('125.00')
        // the order's own books agree: goods − discount + fees = total
        ->and(Money::of($o->subtotal)->minus(Money::of($o->discount_total))->plus(Money::of($o->delivery_fee_total))->plus(Money::of($o->service_fee_total))->plus(Money::of($o->tax_total))->toDecimal())->toBe($o->grand_total);
});

it('works the totals out per line with a fixed coupon and never lets one line carry more discount than it costs', function () {
    $a = adjustmentWorld(['coupon' => ['fixed', '300'], 'qty' => [1, 1]]); // 1000 + 250, 300 off shared 240/60
    expect($a->order->discount_total)->toBe('300.00');
    $lamp = (float) $a->l2->discount_amount;

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertOk();
    $o = $a->order->fresh();
    // only the lamp is left, with the share of the fixed discount it carried (the rest went with the sofa)
    expect((float) $o->discount_total)->toBe($lamp)->and($o->subtotal)->toBe('250.00')->and((float) $a->l2->fresh()->discount_amount)->toBeLessThanOrEqual(250.0)
        ->and((float) $o->grand_total)->toBe(250.0 - $lamp + 15 + 10);
});

it('works tax out again line by line at the line\'s own rate', function () {
    $a = adjustmentWorld(['tax' => '5']); // 2250 × 5% = 112.50 → total 2387.50
    expect($a->order->tax_total)->toBe('112.50')->and($a->order->grand_total)->toBe('2387.50');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.after.tax_total.amount', '62.50')->assertJsonPath('data.after.grand_total.amount', '1337.50');
    $o = $a->order->fresh();
    expect($o->tax_total)->toBe('62.50')->and($a->l1->fresh()->tax_amount)->toBe('50.00')->and($o->tax_snapshot['total'])->toBe('62.50')
        ->and(OrderItem::where('order_id', $o->id)->sum('tax_amount'))->toEqual(62.5);
});

it('prices a replacement at the lower of its price and the original price, whatever it costs now', function (string $price, string $expectedUnit, string $expectedTotal) {
    $a = adjustmentWorld(['qty' => [2, 0]]); // two sofas at 1000, delivery 15, cod 10 = 2025
    [, $v] = substituteFor($a->w, $price);

    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 2]], 'not_found')->assertOk();
    $r->assertJsonPath('data.lines.0.replacement.unit_price.amount', $expectedUnit)->assertJsonPath('data.after.grand_total.amount', $expectedTotal);

    $new = OrderItem::where('replaces_order_item_id', $a->l1->id)->firstOrFail();
    expect($new->unit_final_price)->toBe($expectedUnit)->and($new->quantity)->toBe(2)->and($new->adjustment_kind)->toBe('replacement')->and($new->original_quantity)->toBe(2)
        ->and($new->price_source)->toBe('adjustment')->and($new->product_variant_id)->toBe($v->id)->and($new->order_fulfillment_id)->toBe($a->f->id)
        ->and($a->l1->fresh()->quantity)->toBe(0)->and($a->l1->fresh()->adjustment_kind)->toBe('replaced')->and($a->l1->fresh()->line_total)->toBe('0.00');
    expect((float) $r->json('data.after.grand_total.amount'))->toBeLessThanOrEqual(2025.0);
})->with([
    'cheaper: its own price' => ['600.00', '600.00', '1225.00'],
    'equal price' => ['1000.00', '1000.00', '2025.00'],
    'dearer: capped at the original price' => ['1500.00', '1000.00', '2025.00'],
]);

it('lets a replacement take fewer units than the line it replaces, never more', function () {
    $a = adjustmentWorld(['qty' => [2, 0]]);
    [, $v] = substituteFor($a->w, '600.00');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 3]])->assertStatus(422)->assertJsonPath('code', 'quantity_exceeds')->assertJsonPath('context.index', 0);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 0]])->assertStatus(422)->assertJsonPath('code', 'quantity_exceeds');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]])->assertOk()->assertJsonPath('data.after.grand_total.amount', '625.00');
});

it('applies every kind of change together in one revision', function () {
    $a = adjustmentWorld(['qty' => [3, 2]]); // 3000 + 500 + 15 + 10 = 3525
    [, $v] = substituteFor($a->w, '100.00');
    $r = postAdjust($a, [
        ['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2],
        ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 2],
    ], 'damaged', ['note' => 'Two lamps broke'])->assertOk();

    $r->assertJsonPath('data.revision.number', 1)->assertJsonPath('data.after.subtotal.amount', '2200.00')->assertJsonPath('data.after.grand_total.amount', '2225.00')
        ->assertJsonCount(2, 'data.lines')->assertJsonPath('data.lines.1.replacement.quantity', 2);
    expect(OrderRevision::count())->toBe(1)->and(DB::table('order_revision_lines')->count())->toBe(2)
        ->and(OrderStatusHistory::where('kind', 'items_changed')->count())->toBe(1)
        ->and(DB::table('notifications')->where('event', 'order.items_changed')->count())->toBe(1);
});

it('treats reducing to zero as removing the line', function () {
    $a = adjustmentWorld();
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'reduce', 'new_quantity' => 0]])->assertOk()->assertJsonPath('data.lines.0.action', 'remove')->assertJsonPath('data.after.grand_total.amount', '2025.00');
    expect($a->l2->fresh()->quantity)->toBe(0)->and($a->l2->fresh()->adjustment_kind)->toBe('removed')->and($a->l2->fresh()->original_quantity)->toBe(1);
});

it('numbers revisions per order and keeps every one', function () {
    $a = adjustmentWorld(['qty' => [4, 1]]);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 3]])->assertOk()->assertJsonPath('data.revision.number', 1);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2]], 'damaged')->assertOk()->assertJsonPath('data.revision.number', 2)
        ->assertJsonPath('data.before.grand_total.amount', '3275.00')->assertJsonPath('data.after.grand_total.amount', '2275.00');
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']], 'other')->assertOk()->assertJsonPath('data.revision.number', 3);
    expect(OrderRevision::where('order_id', $a->order->id)->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])->and($a->order->fresh()->adjustment_count)->toBe(3);
    // the line remembers what was ordered, not what it was the time before
    expect($a->l1->fresh()->original_quantity)->toBe(4)->and($a->l1->fresh()->adjustment_reason)->toBe('damaged');
});

it('never lets the total go below zero when loyalty points can no longer be absorbed, and hands the unused points back', function () {
    $a = adjustmentWorld(['qty' => [0, 4], 'loyalty' => 6000]); // 4 lamps 1000, 30% = 300 off by 6000 points, + 15 delivery + 10 cod = 725
    expect($a->order->loyalty_discount)->toBe('300.00')->and($a->order->grand_total)->toBe('725.00')->and($a->order->loyalty_points_redeemed)->toBe(6000);
    $balance = app(LoyaltyService::class)->account($a->customer)->fresh()->balance;

    $r = postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    // 250 + 15 = 265 payable before points: 5300 points (265.00) stay, 700 go back; only the cash-on-delivery fee is left to pay
    $r->assertJsonPath('data.after.grand_total.amount', '10.00')->assertJsonPath('data.loyalty_points_returned', 700);
    $o = $a->order->fresh();
    expect($o->loyalty_discount)->toBe('265.00')->and($o->loyalty_points_redeemed)->toBe(5300)->and((float) $o->grand_total)->toBeGreaterThanOrEqual(0)
        ->and(app(LoyaltyService::class)->account($a->customer)->fresh()->balance)->toBe($balance + 700)
        ->and(LoyaltyTransaction::where('order_id', $o->id)->where('type', 'adjust')->sum('points'))->toBe(700);
});

it('returns only the redeemed points still on the order when it is cancelled after points were handed back', function () {
    $a = adjustmentWorld(['qty' => [0, 4], 'loyalty' => 6000]);
    $loyalty = app(LoyaltyService::class);
    $start = $loyalty->account($a->customer)->fresh()->balance; // after redeeming 6000
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk(); // 700 back
    expect($loyalty->account($a->customer)->fresh()->balance)->toBe($start + 700);

    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk()->assertJsonPath('data.order_cancelled', true);
    // all 6000 redeemed points are home exactly once: 700 earlier + 5300 on the cancellation
    expect($loyalty->account($a->customer)->fresh()->balance)->toBe($start + 6000);
});

it('keeps the order books consistent after any adjustment', function () {
    $a = adjustmentWorld(['coupon' => ['percentage', '15'], 'tax' => '5', 'qty' => [3, 3]]);
    [, $v] = substituteFor($a->w, '400.00');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 2]])->assertOk();

    $o = $a->order->fresh();
    $lines = OrderItem::where('order_id', $o->id)->get();
    $sum = fn (string $f) => $lines->reduce(fn (Money $c, $i) => $c->plus(Money::of($i->{$f})), Money::zero());
    expect($sum('line_subtotal')->toDecimal())->toBe($o->subtotal)->and($sum('discount_amount')->toDecimal())->toBe($o->discount_total)->and($sum('tax_amount')->toDecimal())->toBe($o->tax_total)
        ->and($lines->every(fn ($i) => Money::of($i->line_total)->equals(Money::of($i->line_subtotal)->minus(Money::of($i->discount_amount))->plus(Money::of($i->tax_amount)))))->toBeTrue()
        ->and(Money::of($o->subtotal)->minus(Money::of($o->discount_total))->plus(Money::of($o->delivery_fee_total))->plus(Money::of($o->service_fee_total))->plus(Money::of($o->tax_total))->toDecimal())->toBe($o->grand_total)
        ->and($a->f->fresh()->subtotal)->toBe($o->subtotal);
});

// ─── Stock ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('releases the reservation and corrects on-hand only when the item was out of stock or not found', function (string $reason, bool $corrects) {
    $a = adjustmentWorld(['qty' => [3, 0]]); // 3 reserved of 10
    expect(stockOf($a->w->a, $a->w->v1))->toBe(['on_hand' => 10, 'reserved' => 3]);

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]], $reason)->assertOk();
    expect(stockOf($a->w->a, $a->w->v1))->toBe(['on_hand' => $corrects ? 8 : 10, 'reserved' => 1]);
    $res = StockReservation::where('reference_type', 'order_item')->where('reference_id', $a->l1->id)->first();
    expect($res->quantity)->toBe(1)->and($res->status)->toBe('active');

    $rev = OrderRevision::first();
    $movements = DB::table('stock_movements')->where('reference_type', 'order_revision')->get();
    expect($movements->count())->toBe($corrects ? 1 : 0);
    if ($corrects) {
        expect($movements[0]->type)->toBe('correction')->and($movements[0]->quantity)->toBe(-2)->and($movements[0]->reference_id)->toBe($rev->id)->and($movements[0]->actor_id)->toBe($a->mgr->id);
    }
    expect($rev->lines[0]->stock_corrected)->toBe($corrects ? 2 : 0)
        ->and(DB::table('stock_movements')->where('type', 'reservation_release')->where('reserved_delta', -2)->count())->toBe(1);
})->with([['out_of_stock', true], ['not_found', true], ['damaged', false], ['quality', false], ['other', false]]);

it('releases a removed line fully and keeps the books balanced', function () {
    $a = adjustmentWorld(['qty' => [2, 1]]);
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']], 'damaged')->assertOk();
    expect(stockOf($a->w->a, $a->w->v2))->toBe(['on_hand' => 10, 'reserved' => 0])->and(StockReservation::where('reference_id', $a->l2->id)->value('status'))->toBe('released');
    expect(stockOf($a->w->a, $a->w->v1))->toBe(['on_hand' => 10, 'reserved' => 2]);
});

it('reserves the replacement at the same branch and refuses one that is not in stock', function () {
    $a = adjustmentWorld(['qty' => [2, 0]]);
    [, $v] = substituteFor($a->w, '600.00', 5);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 2]], 'out_of_stock')->assertOk();
    $new = OrderItem::where('replaces_order_item_id', $a->l1->id)->first();
    expect(stockOf($a->w->a, $v))->toBe(['on_hand' => 5, 'reserved' => 2])->and(StockReservation::where('reference_id', $new->id)->first()->branch_id)->toBe($a->w->a->id)
        ->and(stockOf($a->w->a, $a->w->v1))->toBe(['on_hand' => 8, 'reserved' => 0]); // released, and corrected: out of stock

});

it('refuses a replacement the branch has too few of or does not stock, and changes nothing', function () {
    $b = adjustmentWorld(['qty' => [3, 0]]);
    [, $few] = substituteFor($b->w, '600.00', 2);
    postAdjust($b, [['order_item_id' => $b->l1->id, 'action' => 'replace', 'variant_id' => $few->id, 'quantity' => 3]])->assertStatus(422)->assertJsonPath('code', 'substitute_unavailable');
    expect(stockOf($b->w->a, $b->w->v1))->toBe(['on_hand' => 10, 'reserved' => 3])->and(OrderRevision::where('order_id', $b->order->id)->count())->toBe(0)
        ->and($b->l1->fresh()->quantity)->toBe(3);
    // not stocked at this branch at all, an unknown variant, the item itself
    [, $none] = substituteFor($b->w, '600.00', 0);
    foreach ([$none->id, (string) Str::uuid(), $b->w->v1->id] as $variant) {
        postAdjust($b, [['order_item_id' => $b->l1->id, 'action' => 'replace', 'variant_id' => $variant, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'substitute_unavailable');
    }
});

it('counts the stock a replacement needs across all the changes of one request', function () {
    $a = adjustmentWorld(['qty' => [2, 1]]);
    [, $v] = substituteFor($a->w, '100.00', 2); // 2 units for a sofa line (2) and a lamp line (1): 3 needed
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 2], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]])
        ->assertStatus(422)->assertJsonPath('code', 'substitute_unavailable')->assertJsonPath('context.index', 1);
});

// ─── Statuses ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('allows adjusting while the branch has accepted or is preparing, and only then', function () {
    $a = adjustmentWorld(['status' => 'accepted']);
    $this->getJson(adjustUrl($a, 'adjustments/context'))->assertOk()->assertJsonPath('data.can_adjust', true)->assertJsonPath('data.blocked_reason', null);
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk();

    foreach (['ready'] as $s) {
        $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'preparing'])->assertOk();
        $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => $s])->assertOk();
    }
    $this->getJson(adjustUrl($a, 'adjustments/context'))->assertOk()->assertJsonPath('data.can_adjust', false)->assertJsonPath('data.blocked_reason', 'status');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertStatus(409)->assertJsonPath('code', 'adjustment_not_allowed');
    expect($a->l1->fresh()->quantity)->toBe(2);
});

it('refuses a pending (not yet accepted) parcel, a cancelled one and a handed-over one', function (string $to) {
    $a = adjustmentWorld(['status' => 'accepted']);
    $a->f->forceFill(['status' => $to])->save();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertStatus(409)->assertJsonPath('code', 'adjustment_not_allowed');
    $this->getJson(adjustUrl($a, 'adjustments/context'))->assertOk()->assertJsonPath('data.can_adjust', false);
})->with(['pending', 'ready', 'dispatched', 'out_for_delivery', 'delivered', 'cancelled', 'returned']);

it('refuses while the customer has asked to cancel and staff have not decided', function () {
    $a = adjustmentWorld();
    $a->order->forceFill(['status' => 'cancellation_requested'])->save();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertStatus(409)->assertJsonPath('code', 'adjustment_not_allowed');
});

// ─── Who may do it ──────────────────────────────────────────────────────────────────────────────────────────────────────

it('is for staff with orders.edit on that branch only', function () {
    $a = adjustmentWorld();
    $change = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]];

    // a customer token never reaches staff routes
    actingAsCustomer($a->customer);
    postAdjust($a, $change)->assertForbidden();
    $this->getJson(adjustUrl($a, 'adjustments/context'))->assertForbidden();
    $this->getJson(adjustUrl($a, 'substitutes'))->assertForbidden();

    // an auditor can read orders but not change them
    actingAsStaff(staffWithRole('auditor'));
    postAdjust($a, $change)->assertForbidden();
    // a manager of ANOTHER branch: same role, wrong branch
    actingAsStaff(staffWithRole('order_manager', $a->w->b));
    postAdjust($a, $change)->assertForbidden()->assertJsonPath('code', 'branch_forbidden');
    $this->getJson(adjustUrl($a, 'adjustments/context'))->assertForbidden();
    $this->getJson(adjustUrl($a, 'substitutes'))->assertForbidden();
    // nobody signed in
    app('auth')->forgetGuards();
    expect(OrderRevision::count())->toBe(0)->and($a->l1->fresh()->quantity)->toBe(2);

    // the branch's own manager and a super admin may
    actingAsStaff($a->mgr);
    postAdjust($a, $change)->assertOk();
    actingAsStaff(staffWithRole('super_admin'));
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk();
});

it('refuses an order item that is not in this branch\'s parcel, a made-up one, and one from another order', function () {
    $a = adjustmentWorld(['stockA' => [2, 0], 'stockB' => [0, 5]]); // splits: sofas from A, lamps from B
    $order = $a->order->fresh();
    expect($order->fulfillments)->toHaveCount(2);
    $lampsLine = OrderItem::where('order_id', $order->id)->where('product_variant_id', $a->w->v2->id)->first();
    $mine = OrderItem::where('order_id', $order->id)->where('product_variant_id', $a->w->v1->id)->first();
    $mineFulfillment = $order->fulfillments->firstWhere('id', $mine->order_fulfillment_id);
    $a->f = $mineFulfillment;
    actingAsStaff(staffWithRole('super_admin'));

    foreach ([$lampsLine->id, (string) Str::uuid()] as $id) {
        postAdjust($a, [['order_item_id' => $id, 'action' => 'remove']])->assertStatus(422)->assertJsonPath('code', 'item_not_in_fulfillment')->assertJsonPath('context.order_item_id', $id);
    }
    expect($lampsLine->fresh()->quantity)->toBe(1);
});

// ─── Validation ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('validates the request: the shape, the reason and the quantities, with stable codes', function () {
    $a = adjustmentWorld();
    $id = $a->l1->id;
    $codes = [
        'duplicate_item' => [[['order_item_id' => $id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $id, 'action' => 'remove']]],
        'quantity_exceeds' => [[['order_item_id' => $id, 'action' => 'reduce', 'new_quantity' => 3]]],
        'quantity_exceeds ' => [[['order_item_id' => $id, 'action' => 'reduce', 'new_quantity' => -1]]],
        'no_change' => [[['order_item_id' => $id, 'action' => 'reduce', 'new_quantity' => 2]]],
    ];
    foreach ($codes as $code => [$changes]) {
        postAdjust($a, $changes)->assertStatus(422)->assertJsonPath('code', trim($code));
    }
    foreach ([['changes' => []], ['changes' => [['order_item_id' => 'nope', 'action' => 'remove']]], ['changes' => [['order_item_id' => $id, 'action' => 'explode']]],
        ['changes' => [['order_item_id' => $id, 'action' => 'reduce']]], ['changes' => [['order_item_id' => $id, 'action' => 'replace', 'quantity' => 1]]],
        ['changes' => [['order_item_id' => $id, 'action' => 'remove', 'surprise' => 1]]], ['changes' => array_fill(0, 51, ['order_item_id' => $id, 'action' => 'remove'])],
        ['changes' => [['order_item_id' => $id, 'action' => 'remove']], 'reason_code' => 'because'], ['changes' => [['order_item_id' => $id, 'action' => 'remove']], 'reason_code' => 'other', 'note' => str_repeat('x', 501)],
        ['reason_code' => 'other']] as $body) {
        $this->postJson(adjustUrl($a), $body + ['reason_code' => 'other'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }
    expect(OrderRevision::count())->toBe(0);
});

it('accepts an Arabic or emoji note, up to 500 characters, and shows it to the customer', function () {
    $a = adjustmentWorld();
    $note = 'بقيت قطعة واحدة فقط على الرف 🛋️';
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]], 'out_of_stock', ['note' => $note])->assertOk();
    expect(OrderRevision::first()->note)->toBe($note);
});

// ─── Repeats and races ──────────────────────────────────────────────────────────────────────────────────────────────────

/** Everything that moves when an adjustment is applied: if none of it moves, nothing was written. */
function adjustmentFootprint(object $a): array
{
    return [DB::table('stock_movements')->count(), DB::table('stock_reservations')->orderBy('id')->get()->toJson(), DB::table('branch_inventory')->orderBy('id')->get()->toJson(),
        DB::table('refunds')->count(), DB::table('notifications')->count(), DB::table('notification_logs')->count(), DB::table('order_revisions')->count(),
        DB::table('order_revision_lines')->count(), DB::table('order_status_history')->count(), DB::table('audit_logs')->count(), DB::table('order_invoices')->count(),
        DB::table('orders')->where('id', $a->order->id)->get()->toJson(), DB::table('order_items')->orderBy('id')->get()->toJson(), DB::table('payments')->get()->toJson()];
}

it('answers an identical repeat of an applied request with the original result, writing and telling nobody again', function () {
    $a = adjustmentWorld(['payment' => 'sadad']);
    [, $v] = substituteFor($a->w, '100.00');
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]];
    $first = postAdjust($a, $changes, 'out_of_stock', ['note' => 'Only one left'])->assertOk()->assertJsonPath('data.replayed', false)->json('data');
    $footprint = adjustmentFootprint($a);
    $movements = DB::table('stock_movements')->count();

    // the app never saw the answer and taps again (even with the changes listed in another order and the note spelt with spaces)
    $second = postAdjust($a, array_reverse($changes), 'out_of_stock', ['note' => '  Only one left '])->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.preview', false)->assertJsonPath('data.revision.number', 1)->json('data');

    expect($second['revision'])->toBe($first['revision'])->and($second['revision']['id'])->not->toBeNull();
    foreach (['lines', 'before', 'after', 'difference', 'refund', 'fulfillment_cancelled', 'order_cancelled', 'customer_notified', 'invoice_revision', 'loyalty_points_returned'] as $key) {
        expect($second[$key])->toEqual($first[$key]); // the very same answer
    }
    expect(adjustmentFootprint($a))->toBe($footprint)                       // nothing written: no revision, line, history, audit, refund, stock movement, notification, invoice
        ->and(DB::table('stock_movements')->count())->toBe($movements)->and(OrderRevision::count())->toBe(1)->and(Refund::count())->toBe(1)
        ->and(DB::table('notifications')->where('event', 'order.items_changed')->count())->toBe(1);

    // and again, as often as it takes
    postAdjust($a, $changes, 'out_of_stock', ['note' => 'Only one left'])->assertOk()->assertJsonPath('data.replayed', true);
    expect(adjustmentFootprint($a))->toBe($footprint);
});

it('stores a hash of the normalised request on the revision and nothing else about it', function () {
    $a = adjustmentWorld();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]], 'damaged', ['note' => 'x'])->assertOk();
    $hash = OrderRevision::first()->request_hash;
    expect($hash)->toMatch('/^[0-9a-f]{64}$/');
    // another request, another hash
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']], 'damaged', ['note' => 'x'])->assertOk();
    expect(OrderRevision::orderBy('number')->get()->pluck('request_hash')->unique())->toHaveCount(2);
});

it('answers a replay with the original even after the parcel moved on to ready, while the order is as the revision left it', function () {
    $a = adjustmentWorld();
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]];
    $first = postAdjust($a, $changes)->assertOk()->json('data');
    $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'ready'])->assertOk();
    postAdjust($a, $changes)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.revision.id', $first['revision']['id']);
    // a request that is NOT a repeat still meets the status rule
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertStatus(409)->assertJsonPath('code', 'adjustment_not_allowed');
});

it('does not replay for another person, other changes, another reason or note, or once the window has passed', function () {
    $a = adjustmentWorld();
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]];
    postAdjust($a, $changes, 'out_of_stock', ['note' => 'n'])->assertOk();
    $footprint = adjustmentFootprint($a);

    // a colleague of the same branch sending the same thing: it is already in place, they are told so
    actingAsStaff(staffWithRole('order_manager', $a->w->a));
    postAdjust($a, $changes, 'out_of_stock', ['note' => 'n'])->assertStatus(422)->assertJsonPath('code', 'no_change');
    actingAsStaff($a->mgr);
    // the same changes with another reason, or another note: not the same request
    postAdjust($a, $changes, 'damaged', ['note' => 'n'])->assertStatus(422)->assertJsonPath('code', 'no_change');
    postAdjust($a, $changes, 'out_of_stock', ['note' => 'other'])->assertStatus(422)->assertJsonPath('code', 'no_change');
    postAdjust($a, $changes)->assertStatus(422)->assertJsonPath('code', 'no_change');
    // the window
    $this->travel(OrderAdjustmentService::REPLAY_WINDOW_MINUTES + 1)->minutes();
    postAdjust($a, $changes, 'out_of_stock', ['note' => 'n'])->assertStatus(422)->assertJsonPath('code', 'no_change');
    $this->travelBack();
    expect(adjustmentFootprint($a))->toBe($footprint);
});

it('does not replay once the order has moved on since: a stale repeat meets the normal rules', function () {
    $a = adjustmentWorld(['qty' => [3, 1]]);
    $first = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2]];
    postAdjust($a, $first)->assertOk();
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']], 'damaged')->assertOk(); // revision 2
    postAdjust($a, $first)->assertStatus(422)->assertJsonPath('code', 'no_change'); // not the latest revision any more
    expect(OrderRevision::count())->toBe(2);

    // a change to the money made some other way also ends the replay
    $b = OrderRevision::where('number', 2)->first();
    $a->order->forceFill(['grand_total' => '1.00'])->save();
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']], 'damaged')->assertStatus(422)->assertJsonPath('code', 'no_change');
    expect($b->number)->toBe(2);
});

it('replays a request that cancelled the order and answers with its refund', function () {
    $a = adjustmentWorld(['payment' => 'sadad']);
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'remove'], ['order_item_id' => $a->l2->id, 'action' => 'remove']];
    $first = postAdjust($a, $changes, 'quality')->assertOk()->assertJsonPath('data.order_cancelled', true)->json('data');
    $footprint = adjustmentFootprint($a);
    $second = postAdjust($a, $changes, 'quality')->assertOk()->assertJsonPath('data.replayed', true)->json('data');
    expect($second['order_cancelled'])->toBeTrue()->and($second['refund'])->toEqual($first['refund'])->and($second['refund']['amount']['amount'])->toBe('2265.00')->and(adjustmentFootprint($a))->toBe($footprint);
});

it('never replays a preview, and a preview after an apply still says what would change now', function () {
    $a = adjustmentWorld();
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]];
    postAdjust($a, $changes)->assertOk();
    postAdjust($a, $changes, 'out_of_stock', ['preview' => true])->assertStatus(422)->assertJsonPath('code', 'no_change');
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']], 'out_of_stock', ['preview' => true])->assertOk()->assertJsonPath('data.preview', true)->assertJsonPath('data.replayed', false);
});

it('answers another request of the same order that is not a repeat with the normal rules (a different line after a replay)', function () {
    $a = adjustmentWorld(['qty' => [3, 1]]);
    $c1 = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2]];
    postAdjust($a, $c1)->assertOk();
    postAdjust($a, $c1)->assertOk()->assertJsonPath('data.replayed', true);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.replayed', false)->assertJsonPath('data.revision.number', 2);
    expect(OrderRevision::count())->toBe(2);
});

it('still refuses a repeat that is not the same request, and the removed line again, with no_change', function () {
    $a = adjustmentWorld(['payment' => 'sadad']);
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'remove']];
    postAdjust($a, $changes)->assertOk();
    $footprint = adjustmentFootprint($a);
    postAdjust($a, [$changes[1]])->assertStatus(422)->assertJsonPath('code', 'no_change'); // the removed line alone: a different request
    expect(adjustmentFootprint($a))->toBe($footprint)->and(OrderRevision::count())->toBe(1);
});

// ─── Money already paid, and cash still to collect ──────────────────────────────────────────────────────────────────────

it('asks for a refund of the difference on a paid online order, for finance to approve, and moves no money', function () {
    $a = adjustmentWorld(['payment' => 'sadad']);
    $payment = Payment::where('order_id', $a->order->id)->first();
    expect($payment->status->value)->toBe('paid')->and($payment->captured_amount)->toBe('2265.00'); // no cash-on-delivery fee online: 2250 + 15

    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()
        ->assertJsonPath('data.refund.amount.amount', '1000.00')->assertJsonPath('data.refund.needs_approval', true)->assertJsonPath('data.after.grand_total.amount', '1265.00');

    $refund = Refund::where('order_id', $a->order->id)->firstOrFail();
    expect($refund->status)->toBe('requested')->and($refund->amount)->toBe('1000.00')->and($refund->reason_code)->toBe('order_adjustment')->and($refund->requested_by)->toBe($a->mgr->id)
        ->and($refund->payment_id)->toBe($payment->id)->and($refund->method)->toBe('manual_bank_transfer')
        ->and($payment->fresh()->refunded_amount)->toBe('0.00')->and($a->order->fresh()->refunded_total)->toBe('0.00')->and($a->order->fresh()->paid_total)->toBe('2265.00')
        ->and(OrderRevision::first()->refund_id)->toBe($refund->id)->and(OrderRevision::first()->refund_amount)->toBe('1000.00');

    // a second revision asks only for what is still owed, on top of the refund already asked for
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk()->assertJsonPath('data.refund.amount.amount', '250.00')->assertJsonPath('data.after.grand_total.amount', '1015.00');
    expect(Refund::where('order_id', $a->order->id)->sum('amount'))->toEqual(1250);

    // finance approves (someone other than the requester): only then does money move
    $finance = staffWithRole('super_admin');
    actingAsStaff($finance);
    app(RefundService::class)->approve($refund->fresh(), $finance);
    app(RefundService::class)->markProcessed($refund->fresh(), $finance, 'BANK-1');
    expect($a->order->fresh()->refunded_total)->toBe('1000.00')->and($payment->fresh()->status->value)->toBe('partially_refunded');
});

it('asks for no refund when the order is not paid yet: the amount to collect drops instead', function () {
    $a = adjustmentWorld(); // cash on delivery
    $payment = Payment::where('order_id', $a->order->id)->first();
    expect($payment->amount)->toBe('2275.00');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.refund', null);
    expect(Refund::count())->toBe(0)->and($payment->fresh()->amount)->toBe('1275.00')->and($payment->fresh()->status->value)->toBe('pending');

    // the delivery's cash to collect follows
    $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'ready'])->assertOk();
});

it('moves the cash the driver collects to the new total', function () {
    $a = adjustmentWorld();
    $delivery = Delivery::create(['order_fulfillment_id' => $a->f->id, 'branch_id' => $a->f->branch_id, 'mode' => 'internal', 'status' => 'pending',
        'tracking_number' => 'ABDTEST1', 'quoted_fee' => '15.00', 'cod_amount' => '2275.00', 'requires_proof' => true]);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    expect($delivery->fresh()->cod_amount)->toBe('1275.00');
});

// ─── Whole parcel and whole order ───────────────────────────────────────────────────────────────────────────────────────

it('cancels the branch\'s part when every item of it is removed, and the delivery fee of that part with it', function () {
    $a = adjustmentWorld(['stockA' => [10, 0], 'stockB' => [0, 10]]); // sofas from A, lamps from B: two parcels, two delivery fees
    $order = $a->order->fresh();
    expect($order->fulfillments)->toHaveCount(2)->and($order->delivery_fee_total)->toBe('40.00'); // 15 + 25
    $mine = $order->fulfillments->firstWhere('branch_id', $a->w->a->id);
    $a->f = $mine;
    $sofas = OrderItem::where('order_fulfillment_id', $mine->id)->first();
    $other = $order->fulfillments->firstWhere('branch_id', $a->w->b->id);

    postAdjust($a, [['order_item_id' => $sofas->id, 'action' => 'remove']])->assertOk()
        ->assertJsonPath('data.fulfillment_cancelled', true)->assertJsonPath('data.order_cancelled', false);
    $order = $order->fresh();
    expect($mine->fresh()->status->value)->toBe('cancelled')->and($mine->fresh()->delivery_fee)->toBe('0.00')->and($other->fresh()->status->value)->not->toBe('cancelled')
        ->and($order->status->value)->not->toBe('cancelled')->and($order->delivery_fee_total)->toBe('25.00')->and($order->subtotal)->toBe('250.00')
        ->and($order->grand_total)->toBe('285.00') // 250 + 25 + 10
        ->and(collect($order->fee_breakdown['delivery'])->firstWhere('branch_id', $a->w->a->id)['fee'])->toBe('0.00')
        ->and(stockOf($a->w->a, $a->w->v1))->toBe(['on_hand' => 8, 'reserved' => 0]); // released, and out of stock so the count is corrected
});

it('cancels the whole order, with its normal cancellation, when nothing is left of it', function () {
    $a = adjustmentWorld(['coupon' => ['percentage', '10']]);
    $usage = CouponUsage::where('order_id', $a->order->id)->first();
    expect($usage->status)->toBe('applied');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove'], ['order_item_id' => $a->l2->id, 'action' => 'remove']], 'out_of_stock')->assertOk()
        ->assertJsonPath('data.fulfillment_cancelled', true)->assertJsonPath('data.order_cancelled', true)->assertJsonPath('data.after.grand_total.amount', '0.00')
        ->assertJsonPath('data.difference.amount', '-2050.00');
    $order = $a->order->fresh();
    expect($order->status->value)->toBe('cancelled')->and($a->f->fresh()->status->value)->toBe('cancelled')->and($order->cancelled_at)->not->toBeNull()
        ->and(stockOf($a->w->a, $a->w->v1))->toBe(['on_hand' => 8, 'reserved' => 0])->and(stockOf($a->w->a, $a->w->v2))->toBe(['on_hand' => 9, 'reserved' => 0])
        ->and($usage->fresh()->status)->toBe('reversed')
        ->and(OrderRevision::first()->order_cancelled)->toBeTrue()->and(OrderRevision::first()->fulfillment_cancelled)->toBeTrue()
        ->and($order->adjustment_count)->toBe(1);
});

it('refunds a paid online order in full through its normal cancellation when everything is removed, once', function () {
    $a = adjustmentWorld(['payment' => 'sadad']);
    // an earlier shortage already asked for part of it back
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    expect(Refund::sum('amount'))->toEqual(1000);

    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove'], ['order_item_id' => $a->l2->id, 'action' => 'remove']], 'quality')->assertOk()->assertJsonPath('data.order_cancelled', true);
    $r->assertJsonPath('data.refund.amount.amount', '1265.00'); // what is left of the 2265 paid
    expect(Refund::where('order_id', $a->order->id)->sum('amount'))->toEqual(2265)->and(Refund::where('order_id', $a->order->id)->count())->toBe(2)
        ->and($a->order->fresh()->status->value)->toBe('cancelled');
});

// ─── Contract reads ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('shows the staff app the parcel, the reasons and what they can still do', function () {
    $a = adjustmentWorld();
    $r = $this->getJson(adjustUrl($a, 'adjustments/context'))->assertOk();
    $r->assertJsonPath('data.reasons', ['out_of_stock', 'not_found', 'damaged', 'quality', 'other'])->assertJsonPath('data.totals.grand_total.amount', '2275.00')
        ->assertJsonPath('data.totals.payment_method', 'cod')->assertJsonPath('data.totals.payment_status', 'pending')->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.adjustment', null);
    $line = collect($r->json('data.items'))->firstWhere('order_item_id', $a->l1->id);
    expect($line)->toMatchArray(['sku' => $a->l1->sku, 'quantity' => 2, 'variant_id' => $a->w->v1->id, 'product_id' => $a->w->p1->id])
        ->and($line['unit_price']['amount'])->toBe('1000.00')->and($line['line_total']['amount'])->toBe('2000.00');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk();
    $after = $this->getJson(adjustUrl($a, 'adjustments/context'))->assertOk()->json('data'); // removed lines are gone from the list; changed ones say so
    expect($after['items'])->toHaveCount(1)->and($after['items'][0]['adjustment'])->toBe(['kind' => 'reduced', 'original_quantity' => 2, 'reason_code' => 'out_of_stock']);
});

it('lists the substitutes the branch really has, searchable, without what the parcel already holds', function () {
    $a = adjustmentWorld();
    [$p, $v] = substituteFor($a->w, '600.00', 4);
    $p->update(['status' => 'active']);
    [$p2, $v2] = substituteFor($a->w, '300.00', 0); // no stock
    $reserved = substituteFor($a->w, '200.00', 1)[1];
    app(ReservationService::class)->reserve([['branch_id' => $a->w->a->id, 'variant_id' => $reserved->id, 'quantity' => 1, 'reference_type' => 'test', 'reference_id' => 'x']]);

    $rows = $this->getJson(adjustUrl($a, 'substitutes'))->assertOk()->json('data');
    $ids = collect($rows)->pluck('variant_id');
    expect($ids)->toContain($v->id)->not->toContain($v2->id)->not->toContain($a->w->v1->id)->not->toContain($a->w->v2->id)->not->toContain($reserved->id);
    $row = collect($rows)->firstWhere('variant_id', $v->id);
    expect($row)->toMatchArray(['product_id' => $p->id, 'sku' => $v->sku, 'available' => 4])->and($row['unit_price']['amount'])->toBe('600.00');

    $name = $p->trans('name');
    $found = $this->getJson(adjustUrl($a, 'substitutes').'?search='.urlencode($name).'&limit=5')->assertOk()->json('data');
    expect(collect($found)->pluck('variant_id'))->toContain($v->id)->and(count($found))->toBeLessThanOrEqual(5);
    $this->getJson(adjustUrl($a, 'substitutes').'?search=zzzzqqq')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(adjustUrl($a, 'substitutes').'?limit=500')->assertStatus(422);
});
