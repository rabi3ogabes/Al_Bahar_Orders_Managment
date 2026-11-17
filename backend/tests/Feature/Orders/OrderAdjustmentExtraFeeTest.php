<?php

require_once __DIR__.'/AdjustmentFixtures.php';

use App\Domain\Marketing\Models\OrderCoupon;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Services\InvoiceService;

/*
 * The extra delivery fee of a bulky product goes with the product (docs/api/ORDER_ADJUSTMENTS.md): when units of it are taken off an order that is
 * being prepared, the fee of those units comes off the delivery fee; a product that stays costs what it did; a fee never goes up.
 * Orders here are placed as today (no cash on delivery fee). A sofa is 1000, a lamp 250, the zone fee is 15.
 */

/** @return array<string, mixed> */
function xfeeOrder(array $o = []): object
{
    return adjustmentWorld($o + ['payment' => 'cod', 'legacy_cod_fee' => false]);
}

it('reduces a per-unit product: the fee follows the units, everywhere the order keeps it', function () {
    $a = xfeeOrder(['qty' => [3, 1], 'fees' => ['p1' => ['50.00', 'per_unit']]]);
    expect($a->order->delivery_fee_total)->toBe('165.00')->and($a->order->grand_total)->toBe('3415.00')->and($a->order->fee_breakdown['extra_delivery']['amount'])->toBe('150.00');

    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    $r->assertJsonPath('data.before.grand_total.amount', '3415.00')->assertJsonPath('data.after.grand_total.amount', '1315.00')->assertJsonPath('data.difference.amount', '-2100.00');

    $o = $a->order->fresh();
    expect($o->subtotal)->toBe('1250.00')->and($o->delivery_fee_total)->toBe('65.00')->and($o->grand_total)->toBe('1315.00')
        ->and($a->f->fresh()->delivery_fee)->toBe('65.00')
        ->and($o->fee_breakdown['extra_delivery']['amount'])->toBe('50.00')
        ->and($o->fee_breakdown['extra_delivery']['lines'][0])->toMatchArray(['quantity' => 1, 'amount' => '50.00', 'fee_each' => '50.00', 'mode' => 'per_unit'])
        ->and($o->fee_breakdown['delivery'][0])->toMatchArray(['fee' => '65.00', 'extra' => '50.00'])
        ->and($o->extraDeliveryFee()->toDecimal())->toBe('50.00');

    // What the customer reads: the totals and the bulky-item line follow.
    actingAsCustomer($a->customer);
    $detail = $this->getJson("/api/v1/orders/{$o->id}")->assertOk();
    expect($detail->json('data.totals.delivery_fee.amount'))->toBe('65.00')->and($detail->json('data.totals.extra_delivery_fee.amount'))->toBe('50.00')
        ->and($detail->json('data.delivery_extras.0.quantity'))->toBe(1)->and($detail->json('data.delivery_extras.0.amount.amount'))->toBe('50.00');

    // The next revision starts from what is stored now: 1 -> 0 sofas takes the last 50.
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertOk();
    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('15.00')->and($o->grand_total)->toBe('265.00')->and($o->extraDeliveryFee()->isZero())->toBeTrue()->and($o->deliveryExtras())->toBe([])
        ->and($o->fee_breakdown['extra_delivery']['lines'][0]['quantity'])->toBe(0);
});

it('keeps the fee of a per-line product while any unit stays, and takes it off when the product is gone', function () {
    $a = xfeeOrder(['qty' => [3, 1], 'fees' => ['p1' => ['50.00', 'per_line']]]);
    expect($a->order->delivery_fee_total)->toBe('65.00')->and($a->order->grand_total)->toBe('3315.00');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.after.grand_total.amount', '1315.00');
    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('65.00')->and($o->extraDeliveryFee()->toDecimal())->toBe('50.00')->and($o->deliveryExtras()[0]['quantity'])->toBe(1);

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']], 'damaged')->assertOk()->assertJsonPath('data.after.grand_total.amount', '265.00');
    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('15.00')->and($o->extraDeliveryFee()->isZero())->toBeTrue()->and($a->f->fresh()->delivery_fee)->toBe('15.00');
});

it('leaves the fee alone when a different product is reduced', function () {
    $a = xfeeOrder(['qty' => [2, 3], 'fees' => ['p1' => ['50.00', 'per_unit']]]); // 2000 + 750 + 15 + 100 = 2865

    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.after.grand_total.amount', '2365.00');
    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('115.00')->and($o->extraDeliveryFee()->toDecimal())->toBe('100.00');
});

it('shows the same numbers in the preview as the change makes', function () {
    $a = xfeeOrder(['qty' => [3, 1], 'fees' => ['p1' => ['50.00', 'per_unit']]]);
    $changes = [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]];

    $preview = postAdjust($a, $changes, 'out_of_stock', ['preview' => true])->assertOk();
    expect($preview->json('data.after.grand_total.amount'))->toBe('1315.00')->and($a->order->fresh()->grand_total)->toBe('3415.00')->and($a->order->fresh()->delivery_fee_total)->toBe('165.00');

    $applied = postAdjust($a, $changes)->assertOk();
    expect($applied->json('data.after'))->toBe($preview->json('data.after'))->and($applied->json('data.difference'))->toBe($preview->json('data.difference'))
        ->and($a->order->fresh()->grand_total)->toBe('1315.00');
});

it('takes the fee off a paid online order as part of the refund request', function () {
    $a = xfeeOrder(['qty' => [3, 0], 'payment' => 'sadad', 'fees' => ['p1' => ['50.00', 'per_unit']]]); // 3000 + 15 + 150 = 3165 paid

    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    expect($r->json('data.after.grand_total.amount'))->toBe('1065.00')->and($r->json('data.refund.amount.amount'))->toBe('2100.00')->and($r->json('data.refund.needs_approval'))->toBeTrue();
});

it('shrinks the delivery waiver of a free-delivery coupon with the fee, so the customer still pays nothing for delivery', function () {
    $a = xfeeOrder(['qty' => [3, 1], 'coupon' => ['free_delivery', '0'], 'fees' => ['p1' => ['50.00', 'per_unit']]]);
    expect($a->order->delivery_fee_total)->toBe('165.00')->and($a->order->discount_total)->toBe('165.00')->and($a->order->grand_total)->toBe('3250.00');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.after.grand_total.amount', '1250.00');
    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('65.00')->and($o->fee_breakdown['delivery_discount'])->toBe('65.00')->and($o->discount_total)->toBe('65.00')->and($o->grand_total)->toBe('1250.00')
        ->and(OrderCoupon::where('order_id', $o->id)->where('free_delivery', true)->value('discount_amount'))->toBe('65.00');
});

it('takes the fee of a replaced product off, but not when a variant of the same product takes its place', function () {
    $a = xfeeOrder(['qty' => [3, 0], 'fees' => ['p1' => ['50.00', 'per_unit']]]);
    [, $substitute] = substituteFor($a->w, '600.00');

    // Another product: the units (and their fee) are gone; the substitute costs no extra delivery fee (a replacement never costs more).
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $substitute->id, 'quantity' => 3]], 'not_found')->assertOk();
    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('15.00')->and($o->extraDeliveryFee()->isZero())->toBeTrue()->and($o->grand_total)->toBe('1815.00'); // 3 x 600 + 15
});

it('keeps the fee when a variant of the same product replaces the line', function () {
    $variants = [];
    $a = xfeeOrder(['qty' => [0, 0], 'prepare' => function (object $w) use (&$variants) {
        $product = App\Domain\Catalog\Models\Product::factory()->withVariants(2)->create(['price' => '400.00', 'extra_delivery_fee' => '30.00', 'extra_delivery_fee_mode' => 'per_unit']);
        $variants = $product->variants()->get()->all();
        foreach ($variants as $v) {
            app(App\Domain\Inventory\Services\InventoryLedger::class)->apply($w->a->id, $v->id, App\Domain\Inventory\Enums\MovementType::OpeningBalance, 10);
        }
        test()->postJson('/api/v1/cart/items', ['variant_id' => $variants[0]->id, 'quantity' => 2])->assertSuccessful();
    }]);
    $line = lineOf($a->order, $variants[0]);
    expect($a->order->delivery_fee_total)->toBe('75.00'); // 15 + 2 x 30

    postAdjust($a, [['order_item_id' => $line->id, 'action' => 'replace', 'variant_id' => $variants[1]->id, 'quantity' => 2]], 'quality')->assertOk();
    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('75.00')->and($o->extraDeliveryFee()->toDecimal())->toBe('60.00');
});

it('takes a whole emptied parcel off a split order with its own fees, and leaves the other branch part alone', function () {
    $a = xfeeOrder(['qty' => [2, 3], 'stockA' => [10, 0], 'stockB' => [0, 10], 'fees' => ['p1' => ['50.00', 'per_line'], 'p2' => ['20.00', 'per_unit']]]);
    expect($a->order->fulfillments()->count())->toBe(2);
    $parcel = $a->f; // the fixture works with the part of branch A (its manager is signed in and has it in preparation)
    expect($parcel->branch_id)->toBe($a->w->a->id);
    // A: 15 + 50 = 65 (2 sofas), B: 25 + 3 x 20 = 85 (3 lamps)
    expect($a->order->delivery_fee_total)->toBe('150.00')->and($parcel->delivery_fee)->toBe('65.00');

    $sofas = OrderItem::where('order_fulfillment_id', $parcel->id)->firstOrFail();
    postAdjust($a, [['order_item_id' => $sofas->id, 'action' => 'remove']])->assertOk()->assertJsonPath('data.fulfillment_cancelled', true)->assertJsonPath('data.order_cancelled', false);

    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('85.00')->and($parcel->fresh()->delivery_fee)->toBe('0.00')->and($o->grand_total)->toBe('835.00')
        ->and($o->extraDeliveryFee()->toDecimal())->toBe('60.00') // only the lamps' fee is left
        ->and(collect($o->fee_breakdown['delivery'])->firstWhere('branch_id', $a->w->a->id))->toMatchArray(['fee' => '0.00', 'extra' => '0.00'])
        ->and(collect($o->fee_breakdown['delivery'])->firstWhere('branch_id', $a->w->b->id)['fee'])->toBe('85.00');
});

it('cancels the order as before when everything is removed, fees included', function () {
    $a = xfeeOrder(['qty' => [2, 1], 'fees' => ['p1' => ['50.00', 'per_unit']]]);

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove'], ['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk()
        ->assertJsonPath('data.order_cancelled', true)->assertJsonPath('data.after.grand_total.amount', '0.00');
    expect($a->order->fresh()->status->value)->toBe('cancelled');
});

it('does not touch an order placed before the extra fee existed, with or without the old breakdown', function (bool $dropKey) {
    $a = xfeeOrder(['qty' => [2, 1]]);
    if ($dropKey) {
        $fee = $a->order->fee_breakdown;
        unset($fee['extra_delivery']);
        Order::whereKey($a->order->id)->update(['fee_breakdown' => json_encode($fee)]);
        $a->order = $a->order->fresh();
    }

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.after.grand_total.amount', '1265.00'); // 1250 + 15
    $o = $a->order->fresh();
    expect($o->delivery_fee_total)->toBe('15.00')->and($o->extraDeliveryFee()->isZero())->toBeTrue()->and($o->deliveryExtras())->toBe([]);
})->with([
    'a breakdown without extras' => [false],
    'no extra_delivery key at all' => [true],
]);

it('shows the new fee on the revised invoice', function () {
    $a = xfeeOrder(['qty' => [3, 1], 'fees' => ['p1' => ['50.00', 'per_unit']]]);
    $invoices = app(InvoiceService::class);
    expect($invoices->issue($a->order)->snapshot['extra_delivery_fee'])->toBe('150.00');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    $revised = $invoices->latest($a->order->fresh());
    expect($revised->revision)->toBe(2)->and($revised->snapshot['extra_delivery_fee'])->toBe('50.00')->and($revised->snapshot['order']['delivery_fee_total'])->toBe('65.00')
        ->and(view('documents.invoice', ['invoice' => $revised, 's' => $revised->snapshot, 'locale' => 'en'])->render())->toContain('extra delivery fee for bulky items');
});
