<?php

use App\Domain\Checkout\Support\Assembly;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Services\InvoiceService;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Orders\Services\ParcelShares;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reports\Services\SalesBasis;
use App\Domain\Settings\Services\Settings;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../Orders/AdjustmentFixtures.php';

/*
 * Paid assembly and installation (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). A sofa costs 1000, a lamp 250, the zone fee is 15.
 * The sofas carry an assembly fee of 100 per unit; three sofas with assembly are 300 on top of the goods.
 */

/** An order of three sofas WITH assembly (100 each) and one lamp, as placed today: 3000 + 250 + 15 delivery + 300 assembly = 3565. */
function assemblyOrder(array $o = []): object
{
    $fee = $o['fee'] ?? '100.00';
    $units = $o['units'] ?? 3;

    return adjustmentWorld($o + ['payment' => 'cod', 'legacy_cod_fee' => false, 'qty' => [0, 1], 'prepare' => function (object $w) use ($fee, $units) {
        $w->p1->update(['assembly_fee' => $fee]);
        test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => $units, 'assembly' => true])->assertCreated();
    }]);
}

function assemblyQuote(object $w, array $extra = []): array
{
    return test()->postJson('/api/v1/checkout/quote', ['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => false] + $extra)->assertOk()->json('data');
}

it('offers assembly on the product only when it has a fee and the dashboard switch is on', function () {
    $w = commerceWorld();
    $this->app['auth']->forgetGuards();

    expect($this->getJson("/api/v1/products/{$w->p1->id}")->json('data.assembly_fee'))->toBeNull();

    $w->p1->update(['assembly_fee' => '75.00', 'lead_time_days' => 2]);
    $detail = $this->getJson("/api/v1/products/{$w->p1->id}")->assertOk()->json('data');
    expect($detail['assembly_fee'])->toBe('75.00')->and($detail['assembly_service_available'])->toBeTrue()->and($detail['lead_time_days'])->toBe(2);
    $card = collect($this->getJson('/api/v1/products')->json('data'))->firstWhere('id', $w->p1->id);
    expect($card['assembly_fee'])->toBe('75.00');

    app(Settings::class)->set('checkout.assembly_enabled', false);
    expect($this->getJson("/api/v1/products/{$w->p1->id}")->json('data.assembly_fee'))->toBeNull();

    // A zero fee is "not offered", not "free assembly".
    app(Settings::class)->set('checkout.assembly_enabled', true);
    $w->p1->update(['assembly_fee' => '0.00']);
    expect($this->getJson("/api/v1/products/{$w->p1->id}")->json('data.assembly_fee'))->toBeNull();
});

it('lets a customer add assembly to a line, change it and take it off, but only for products that offer it', function () {
    $w = commerceWorld();
    $w->p1->update(['assembly_fee' => '100.00']);

    $cart = $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2, 'assembly' => true])->assertCreated()->json('data');
    $line = $cart['items'][0];
    expect($line['assembly'])->toMatchArray(['selected' => true])->and($line['assembly']['fee_each']['amount'])->toBe('100.00')->and($line['assembly']['amount']['amount'])->toBe('200.00')
        ->and($cart['assembly_total']['amount'])->toBe('200.00')->and($cart['subtotal']['amount'])->toBe('2000.00');

    // The quantity changes: the assembly follows it (the server works it out, the client sends no money).
    $cart = $this->patchJson("/api/v1/cart/items/{$line['id']}", ['quantity' => 3])->assertOk()->json('data');
    expect($cart['assembly_total']['amount'])->toBe('300.00');

    // Take it off and put it back without touching the quantity.
    $cart = $this->patchJson("/api/v1/cart/items/{$line['id']}", ['assembly' => false])->assertOk()->json('data');
    expect($cart['items'][0]['assembly']['selected'])->toBeFalse()->and($cart['assembly_total']['amount'])->toBe('0.00')->and($cart['items'][0]['quantity'])->toBe(3);
    $cart = $this->patchJson("/api/v1/cart/items/{$line['id']}", ['assembly' => true, 'quantity' => 1])->assertOk()->json('data');
    expect($cart['assembly_total']['amount'])->toBe('100.00');

    // A line that is added again keeps its choice; asking explicitly changes it.
    $cart = $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertCreated()->json('data');
    expect($cart['items'][0]['assembly']['selected'])->toBeTrue()->and($cart['items'][0]['quantity'])->toBe(2);

    // A product without a fee: the choice is refused and nothing is stored.
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1, 'assembly' => true])->assertStatus(422)->assertJsonPath('code', 'assembly_not_offered');
    $lamp = $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertCreated()->json('data.items');
    $lampId = collect($lamp)->firstWhere('variant_id', $w->v2->id)['id'];
    expect(collect($lamp)->firstWhere('variant_id', $w->v2->id)['assembly'])->toBeNull();
    $this->patchJson("/api/v1/cart/items/{$lampId}", ['assembly' => true])->assertStatus(422)->assertJsonPath('code', 'assembly_not_offered');

    // At least one of the two fields; garbage is refused.
    $this->patchJson("/api/v1/cart/items/{$line['id']}", [])->assertStatus(422);
    $this->patchJson("/api/v1/cart/items/{$line['id']}", ['assembly' => 'maybe'])->assertStatus(422);
});

it('keeps one customer from changing the assembly of another customer\'s line', function () {
    $w = commerceWorld();
    $w->p1->update(['assembly_fee' => '100.00']);
    $line = $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertCreated()->json('data.items.0');

    actingAsCustomer();
    $this->patchJson("/api/v1/cart/items/{$line['id']}", ['assembly' => true])->assertNotFound();
});

it('charges assembly in the quote: totals, line, hash, and only on delivery', function () {
    $w = commerceWorld();
    $w->p1->update(['assembly_fee' => '33.33']); // an awkward fee: 3 units are 99.99, no rounding anywhere
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 3])->assertCreated();
    $plain = assemblyQuote($w);

    $item = collect($this->getJson('/api/v1/cart')->json('data.items'))->first();
    $this->patchJson("/api/v1/cart/items/{$item['id']}", ['assembly' => true])->assertOk();
    $q = assemblyQuote($w);

    expect($plain['totals']['assembly_fee']['amount'])->toBe('0.00')->and($plain['lines'][0]['assembly'])->toBeNull()
        ->and($q['totals']['assembly_fee']['amount'])->toBe('99.99')->and($q['fulfillments'][0]['assembly_fee']['amount'])->toBe('99.99')
        ->and($q['lines'][0]['assembly'])->toMatchArray(['fee_each' => ['amount' => '33.33', 'currency' => 'QAR', 'formatted' => 'QAR 33.33'], 'amount' => ['amount' => '99.99', 'currency' => 'QAR', 'formatted' => 'QAR 99.99']])
        ->and(Money::of($q['totals']['grand_total']['amount'])->minus(Money::of($plain['totals']['grand_total']['amount']))->toDecimal())->toBe('99.99')
        ->and($q['quote_hash'])->not->toBe($plain['quote_hash']);

    // The same cart quoted twice has the same hash; the goods total is untouched.
    expect(assemblyQuote($w)['quote_hash'])->toBe($q['quote_hash'])->and($q['totals']['subtotal']['amount'])->toBe('3000.00');

    // Pickup: done at the door, never at a pickup. Nothing is charged, nothing is warned, the tick is kept for when they switch back.
    $pickup = $this->postJson('/api/v1/checkout/quote', ['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertOk()->json('data');
    expect($pickup['totals']['assembly_fee']['amount'])->toBe('0.00')->and(collect($pickup['warnings'])->pluck('code'))->not->toContain('assembly_unavailable')
        ->and($this->getJson('/api/v1/cart')->json('data.items.0.assembly.selected'))->toBeTrue();
});

it('never discounts assembly: coupons, free delivery and tax leave it exactly as priced', function () {
    $a = assemblyOrder(['coupon' => ['percentage', '10'], 'tax' => '5']);
    $o = $a->order;

    // Goods 3250, 10% off = 325 (goods only), tax 5% of 2925 = 146.25, delivery 15, assembly 300 (no discount, no tax): 3386.25.
    expect($o->subtotal)->toBe('3250.00')->and($o->discount_total)->toBe('325.00')->and($o->tax_total)->toBe('146.25')->and($o->assembly_fee_total)->toBe('300.00')
        ->and($o->grand_total)->toBe('3386.25');
});

it('keeps assembly when a free-delivery code waives the delivery fee', function () {
    $free = assemblyOrder(['coupon' => ['free_delivery', '0']]);

    expect($free->order->delivery_fee_total)->toBe('15.00')->and($free->order->assembly_fee_total)->toBe('300.00')->and($free->order->grand_total)->toBe('3550.00');
});

it('places the order with assembly on its lines and the order, in the payment and the order JSON', function () {
    $a = assemblyOrder();
    $o = $a->order;

    expect($o->assembly_fee_total)->toBe('300.00')->and($o->subtotal)->toBe('3250.00')->and($o->delivery_fee_total)->toBe('15.00')->and($o->grand_total)->toBe('3565.00')
        ->and($a->l1->assembly_fee)->toBe('100.00')->and($a->l1->assembly_total)->toBe('300.00')->and($a->l1->line_total)->toBe('3000.00')
        ->and($a->l2->assembly_fee)->toBeNull()->and($a->l2->assembly_total)->toBe('0.00')
        ->and(Payment::where('order_id', $o->id)->value('amount'))->toBe('3565.00');

    actingAsCustomer($a->customer);
    $detail = $this->getJson("/api/v1/orders/{$o->id}")->assertOk()->json('data');
    $sofa = collect($detail['items'])->firstWhere('id', $a->l1->id);
    expect($detail['totals']['assembly_fee']['amount'])->toBe('300.00')->and($detail['totals']['grand_total']['amount'])->toBe('3565.00')
        ->and($sofa['assembly']['fee_each']['amount'])->toBe('100.00')->and($sofa['assembly']['amount']['amount'])->toBe('300.00')
        ->and(collect($detail['items'])->firstWhere('id', $a->l2->id)['assembly'])->toBeNull();

    // The cart was converted: nothing is left to charge twice.
    expect($this->getJson('/api/v1/cart')->json('data.assembly_total.amount'))->toBe('0.00');
});

it('freezes the fee per unit on the order: a later price change never touches it', function () {
    $a = assemblyOrder();
    $a->w->p1->update(['assembly_fee' => '500.00']);

    expect($a->l1->fresh()->assembly_fee)->toBe('100.00')->and($a->order->fresh()->assembly_fee_total)->toBe('300.00')->and(Assembly::placedFee($a->l1->fresh())->toDecimal())->toBe('100.00');
});

it('shows assembly on the invoice, in both the items and the totals', function () {
    $a = assemblyOrder();
    $invoice = app(InvoiceService::class)->issue($a->order);
    $s = $invoice->snapshot;

    expect($s['order']['assembly_fee_total'])->toBe('300.00')->and(collect($s['items'])->firstWhere('sku', $a->l1->sku)['assembly_total'])->toBe('300.00');

    $html = view('documents.invoice', ['invoice' => $invoice, 's' => $s, 'locale' => 'en'])->render();
    expect($html)->toContain('Assembly and installation')->and($html)->toContain('300.00')->and($html)->toContain('3,565.00');

    // An invoice issued before the feature (no assembly keys in its snapshot) still renders.
    $old = $s;
    unset($old['order']['assembly_fee_total']);
    $old['items'] = array_map(function (array $i) {
        unset($i['assembly_fee'], $i['assembly_total']);

        return $i;
    }, $old['items']);
    expect(view('documents.invoice', ['invoice' => $invoice, 's' => $old, 'locale' => 'ar'])->render())->not->toContain('Assembly and installation');
});

it('takes the assembly fee off with the units a branch reduces, removes or swaps out, and never raises it', function () {
    $a = assemblyOrder(['payment' => 'sadad']); // paid online: the refund shows what comes back

    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    // 2 sofas gone: 2000 goods + 200 assembly come off 3565.
    $r->assertJsonPath('data.before.grand_total.amount', '3565.00')->assertJsonPath('data.after.grand_total.amount', '1365.00')->assertJsonPath('data.difference.amount', '-2200.00')
        ->assertJsonPath('data.refund.amount.amount', '2200.00');
    $o = $a->order->fresh();
    expect($o->assembly_fee_total)->toBe('100.00')->and($a->l1->fresh()->assembly_total)->toBe('100.00')->and($a->l1->fresh()->assembly_fee)->toBe('100.00')->and($o->grand_total)->toBe('1365.00')
        ->and(Refund::where('order_id', $o->id)->sum('amount'))->toEqual(2200);

    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertOk();
    $o = $a->order->fresh();
    expect($o->assembly_fee_total)->toBe('0.00')->and($a->l1->fresh()->assembly_total)->toBe('0.00')->and($o->grand_total)->toBe('265.00');

    // What the customer reads follows.
    actingAsCustomer($a->customer);
    expect($this->getJson("/api/v1/orders/{$o->id}")->json('data.totals.assembly_fee.amount'))->toBe('0.00');
});

it('gives a substitute no assembly: the assembly of the item it replaces is refunded', function () {
    $a = assemblyOrder(['payment' => 'sadad']);
    [, $variant] = substituteFor($a->w, '600.00');

    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $variant->id, 'quantity' => 3]])->assertOk();

    // 3 sofas (3000 + 300 assembly) become 3 substitutes at 600 = 1800: -1200 goods and -300 assembly.
    $r->assertJsonPath('data.difference.amount', '-1500.00')->assertJsonPath('data.after.grand_total.amount', '2065.00');
    $o = $a->order->fresh();
    $sub = OrderItem::where('order_id', $o->id)->where('replaces_order_item_id', $a->l1->id)->firstOrFail();
    expect($o->assembly_fee_total)->toBe('0.00')->and($sub->assembly_fee)->toBeNull()->and($sub->assembly_total)->toBe('0.00')->and($a->l1->fresh()->assembly_total)->toBe('0.00');
});

it('refunds the assembly with the rest when the whole order is cancelled', function () {
    $a = assemblyOrder(['payment' => 'sadad']);

    app(OrderService::class)->cancelOrder($a->order, $a->mgr, 'customer_request');

    expect(Refund::where('order_id', $a->order->id)->sum('amount'))->toEqual(3565);
});

it('divides money between the parts of a split order with each part\'s assembly in its own weight', function () {
    $a = adjustmentWorld(['payment' => 'sadad', 'stockA' => [5, 0], 'stockB' => [0, 5], 'qty' => [0, 1], 'prepare' => function (object $w) {
        $w->p1->update(['assembly_fee' => '100.00']);
        test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1, 'assembly' => true])->assertCreated();
    }]);
    $order = $a->order->fresh('fulfillments');
    $fa = $order->fulfillments->firstWhere('branch_id', $a->w->a->id);
    $fb = $order->fulfillments->firstWhere('branch_id', $a->w->b->id);

    // A: the sofa 1000 + 100 assembly + 15 delivery; B: the lamp 250 + 25 delivery. 1115 + 275 = 1390 = the order total.
    expect($order->grand_total)->toBe('1390.00')->and($order->assembly_fee_total)->toBe('100.00');
    $shares = ParcelShares::split(Money::of($order->grand_total), $order->fulfillments);
    expect($shares[$fa->id]->toDecimal())->toBe('1115.00')->and($shares[$fb->id]->toDecimal())->toBe('275.00');

    // The report basis divides the same way, to the cent.
    $sales = SalesBasis::orderShares()->where('s.order_id', $order->id)->pluck('share', 'branch_id')->map(fn ($v) => (string) $v);
    expect($sales[$a->w->a->id])->toBe('1115.00')->and($sales[$a->w->b->id])->toBe('275.00');

    // Cancelling part A asks finance to refund A's part: the goods, its delivery AND its assembly.
    $this->postJson("/api/v1/admin/fulfillments/{$fa->id}/transition", ['status' => 'cancelled', 'reason' => 'customer called'])->assertOk();
    expect(Refund::where('order_id', $order->id)->sum('amount'))->toEqual(1115);
});

it('splits cash on delivery between drivers with the assembly inside the part it belongs to', function () {
    $a = adjustmentWorld(['payment' => 'cod', 'legacy_cod_fee' => false, 'stockA' => [5, 0], 'stockB' => [0, 5], 'qty' => [0, 1], 'prepare' => function (object $w) {
        $w->p1->update(['assembly_fee' => '100.00']);
        test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1, 'assembly' => true])->assertCreated();
    }]);
    $order = $a->order->fresh('fulfillments');
    $shares = ParcelShares::split(Money::of($order->grand_total), $order->fulfillments);

    expect(array_reduce($shares, fn (Money $c, Money $m) => $c->plus($m), Money::zero())->toDecimal())->toBe($order->grand_total)
        ->and($shares[$order->fulfillments->firstWhere('branch_id', $a->w->a->id)->id]->toDecimal())->toBe('1115.00');
});

it('works out what goes back with returned units: the fee per unit as placed times the units, never more than the line owes', function () {
    $a = assemblyOrder();
    $line = $a->l1->fresh();

    expect(Assembly::refundShare($line, 1)->toDecimal())->toBe('100.00')->and(Assembly::refundShare($line, 2)->toDecimal())->toBe('200.00')
        ->and(Assembly::refundShare($line, 3)->toDecimal())->toBe('300.00')->and(Assembly::refundShare($line, 99)->toDecimal())->toBe('300.00')
        ->and(Assembly::refundShare($line, 0)->isZero())->toBeTrue()
        ->and(Assembly::refundShare($a->l2->fresh(), 1)->isZero())->toBeTrue();

    // An awkward fee has no rounding to argue about: 3 x 33.33 = 99.99 and a third of it is exactly 33.33.
    expect(Assembly::lineTotal(Money::of('33.33'), 3)->toDecimal())->toBe('99.99')->and(Assembly::lineTotal(Money::of('33.33'), 1)->toDecimal())->toBe('33.33')
        ->and(Assembly::lineTotal(null, 5)->isZero())->toBeTrue()->and(Assembly::lineTotal(Money::of('10.00'), 0)->isZero())->toBeTrue();
});

it('does not charge assembly that was switched off or removed, and tells the customer', function () {
    $w = commerceWorld();
    $w->p1->update(['assembly_fee' => '100.00']);
    $line = $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2, 'assembly' => true])->assertCreated()->json('data.items.0');
    expect(assemblyQuote($w)['totals']['assembly_fee']['amount'])->toBe('200.00');

    app(Settings::class)->set('checkout.assembly_enabled', false);
    $q = assemblyQuote($w);
    $cart = $this->getJson('/api/v1/cart')->json('data');
    expect($q['totals']['assembly_fee']['amount'])->toBe('0.00')->and(collect($q['warnings'])->pluck('code'))->toContain('assembly_unavailable')
        ->and($cart['assembly_total']['amount'])->toBe('0.00')->and($cart['items'][0]['assembly'])->toBeNull()->and($cart['warnings'])->not->toBe([]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1, 'assembly' => true])->assertStatus(422);
    // Taking the tick off is always allowed.
    $this->patchJson("/api/v1/cart/items/{$line['id']}", ['assembly' => false])->assertOk();
    app(Settings::class)->set('checkout.assembly_enabled', true);
    expect(assemblyQuote($w)['totals']['assembly_fee']['amount'])->toBe('0.00');

    // The fee is removed from the product after the customer ticked it.
    $this->patchJson("/api/v1/cart/items/{$line['id']}", ['assembly' => true])->assertOk();
    $w->p1->update(['assembly_fee' => null]);
    expect(assemblyQuote($w)['totals']['assembly_fee']['amount'])->toBe('0.00');
});

it('refuses to place an order when the assembly price changed after the customer confirmed the total', function () {
    $w = commerceWorld();
    $w->p1->update(['assembly_fee' => '100.00']);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1, 'assembly' => true])->assertCreated();
    $options = ['address_id' => $w->address->id, 'payment_method' => 'cod'];
    $quote = $this->postJson('/api/v1/checkout/quote', $options)->assertOk()->json('data');

    $w->p1->update(['assembly_fee' => '150.00']);
    Cache::flush();

    $this->postJson('/api/v1/checkout/orders', $options + ['quote_hash' => $quote['quote_hash']], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('code', 'quote_changed')->assertJsonPath('context.quote.totals.assembly_fee.amount', '150.00');
    expect(Order::count())->toBe(0);
});
