<?php

use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;

beforeEach(function () {
    $this->w = commerceWorld();
});

it('builds a server-authoritative quote from the cart and preferred branch', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 2])->assertCreated();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 1])->assertCreated();

    $q = $this->postJson('/api/v1/checkout/quote', ['fulfillment_type' => 'delivery', 'address_id' => $this->w->address->id, 'payment_method' => 'cod'])
        ->assertOk()->json('data');

    expect($q['can_place_order'])->toBeTrue()->and($q['is_split'])->toBeFalse()
        ->and($q['fulfillments'][0]['branch']['id'])->toBe($this->w->a->id) // zone priority
        ->and($q['totals']['subtotal']['amount'])->toBe('2250.00')
        ->and($q['totals']['delivery_fee']['amount'])->toBe('15.00')
        ->and($q['totals']['cod_fee']['amount'])->toBe('10.00')
        ->and($q['totals']['grand_total']['amount'])->toBe('2275.00')
        ->and(strlen($q['quote_hash']))->toBe(64);
});

it('ignores any client-supplied prices', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1, 'price' => '1.00', 'unit_price' => '1.00'])->assertCreated();
    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $this->w->address->id, 'payment_method' => 'cod', 'grand_total' => '1.00'])->json('data');
    expect($q['totals']['subtotal']['amount'])->toBe('1000.00');
});

it('places an order transactionally with reservations, snapshots and fulfillments', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 2]);
    $res = quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertCreated();

    $order = Order::with('items', 'fulfillments')->findOrFail($res->json('data.order.id'));
    expect($order->status->value)->toBe('confirmed')->and($order->grand_total)->toBe('2025.00')
        ->and($order->fulfillments)->toHaveCount(1)->and($order->items[0]->name_ar)->not->toBeEmpty()
        ->and($order->address_snapshot['zone_number'])->toBe('66')
        ->and(stockOf($this->w->a, $this->w->v1))->toBe(['on_hand' => 5, 'reserved' => 2])
        ->and(StockReservation::where('status', 'active')->count())->toBe(1)
        ->and($res->json('data.payment.action'))->toBe('none');
    $this->getJson('/api/v1/cart')->assertJsonCount(0, 'data.items'); // new empty cart
});

it('is idempotent: retrying with the same key returns the same order', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);
    $opts = ['address_id' => $this->w->address->id, 'payment_method' => 'cod'];
    $hash = $this->postJson('/api/v1/checkout/quote', $opts)->json('data.quote_hash');
    $key = 'retry-key-'.str_repeat('x', 10);

    $first = $this->postJson('/api/v1/checkout/orders', $opts + ['quote_hash' => $hash], ['Idempotency-Key' => $key])->assertCreated();
    $second = $this->postJson('/api/v1/checkout/orders', $opts + ['quote_hash' => $hash], ['Idempotency-Key' => $key])->assertOk();

    expect($second->json('data.order.id'))->toBe($first->json('data.order.id'))->and($second->json('data.replayed'))->toBeTrue()
        ->and(Order::count())->toBe(1)->and(stockOf($this->w->a, $this->w->v1)['reserved'])->toBe(1);
    $this->postJson('/api/v1/checkout/orders', $opts + ['quote_hash' => $hash])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
});

it('rejects placement with 409 and the new quote when the price changed after quoting', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);
    $opts = ['address_id' => $this->w->address->id, 'payment_method' => 'cod'];
    $hash = $this->postJson('/api/v1/checkout/quote', $opts)->json('data.quote_hash');
    ProductBranchSetting::create(['product_id' => $this->w->p1->id, 'branch_id' => $this->w->a->id, 'price' => '1100.00']);

    $res = $this->postJson('/api/v1/checkout/orders', $opts + ['quote_hash' => $hash], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('code', 'quote_changed');
    expect($res->json('context.quote.totals.subtotal.amount'))->toBe('1100.00')->and(Order::count())->toBe(0);
    $warnings = collect($res->json('context.quote.warnings'))->pluck('code');
    expect($warnings)->toContain('price_changed');
});

it('rolls back everything when stock is depleted before checkout', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 5]);
    $opts = ['address_id' => $this->w->address->id, 'payment_method' => 'cod', 'allow_split' => false];
    $hash = $this->postJson('/api/v1/checkout/quote', $opts)->json('data.quote_hash');
    // Another shopper takes 3 units at branch A meanwhile.
    app(ReservationService::class)->reserve([['branch_id' => $this->w->a->id, 'variant_id' => $this->w->v1->id, 'quantity' => 3, 'reference_type' => 'order_item', 'reference_id' => 'other']]);

    // Branch B can still serve all 5 → quote changes (different branch) → 409, nothing persisted.
    $this->postJson('/api/v1/checkout/orders', $opts + ['quote_hash' => $hash], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
    expect(Order::count())->toBe(0)->and(OrderItem::count())->toBe(0);
});

it('splits across branches only when needed and shows both delivery fees', function () {
    $w = $this->w;
    // A has 5 of v1, B has 5; ask for 8 → A 5 + B 3.
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 8]);
    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data');
    expect($q['is_split'])->toBeTrue()->and($q['fulfillments'])->toHaveCount(2)
        ->and(collect($q['fulfillments'])->pluck('delivery_fee.amount')->all())->toEqualCanonicalizing(['0.00', '25.00']) // A over free threshold
        ->and(collect($q['warnings'])->pluck('code'))->toContain('split_fulfillment');

    $res = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated();
    $order = Order::with('fulfillments.items')->find($res->json('data.order.id'));
    expect($order->fulfillments)->toHaveCount(2)
        ->and($order->fulfillments->flatMap->items->sum('quantity'))->toBe(8)
        ->and(stockOf($w->a, $w->v1)['reserved'] + stockOf($w->b, $w->v1)['reserved'])->toBe(8);

    // Without permission to split, the quote cannot be placed.
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 3]);
    $q2 = $this->postJson('/api/v1/checkout/quote', ['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => false])->json('data');
    expect($q2['can_place_order'])->toBeFalse()->and($q2['errors'][0]['code'])->toBe('insufficient_stock');
});

it('prefers the customer-selected branch when it can supply everything', function () {
    $this->putJson('/api/v1/cart', ['branch_id' => $this->w->b->id]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);
    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->json('data');
    expect($q['fulfillments'][0]['branch']['id'])->toBe($this->w->b->id)->and($q['fulfillments'][0]['allocation_reason'])->toBe('selected_branch');
});

it('keeps order snapshots unchanged after the product is edited', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $this->w->p1->update(['price' => '9999.00']);
    $this->w->p1->translations()->where('locale', 'en')->update(['name' => 'Renamed']);

    $item = OrderItem::where('order_id', $orderId)->first();
    expect($item->unit_final_price)->toBe('1000.00')->and($item->name_en)->not->toBe('Renamed');
    $this->getJson("/api/v1/orders/{$orderId}")->assertJsonPath('data.items.0.unit_price.amount', '1000.00');
});

it('enforces pickup branch, pay-at-pickup and payment method availability', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 1]);
    $q = $this->postJson('/api/v1/checkout/quote', ['fulfillment_type' => 'pickup', 'branch_id' => $this->w->b->id, 'payment_method' => 'cod'])->json('data');
    expect(collect($q['errors'])->pluck('code'))->toContain('payment_method_unavailable')
        ->and(collect($q['payment_methods'])->firstWhere('code', 'pay_at_pickup')['available'])->toBeTrue();

    $res = quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $this->w->b->id, 'payment_method' => 'pay_at_pickup'])->assertCreated();
    expect($res->json('data.order.fulfillments.0.type'))->toBe('pickup')->and($res->json('data.order.totals.delivery_fee.amount'))->toBe('0.00');
});

it('enforces zone minimum order amounts with a helpful shortfall', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 1]);
    BranchDeliveryZone::query()->update(['min_order_amount' => '300.00']);
    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->json('data');
    expect($q['errors'][0]['code'])->toBe('below_min_order')->and($q['errors'][0]['shortfall']['amount'])->toBe('50.00');
});

it('never exposes another customer\'s address or order', function () {
    $other = User::factory()->create();
    $foreign = $other->addresses()->create(['recipient_name' => 'X', 'recipient_phone' => '+97455000000', 'zone_number' => '66', 'street_number' => '1', 'building_number' => '1']);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1]);
    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $foreign->id, 'payment_method' => 'cod'])->json('data');
    expect($q['errors'][0]['code'])->toBe('address_required');
    $this->getJson('/api/v1/me/addresses/'.$foreign->id)->assertNotFound();
});
