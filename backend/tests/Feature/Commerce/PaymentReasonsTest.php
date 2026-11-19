<?php

use App\Domain\Branches\Models\Branch;

/* The payment methods of a quote say why one is not offered, with the numbers the customer needs (cash on delivery limits). */

beforeEach(function () {
    $this->w = commerceWorld([5, 5], [5, 5]);   // 5 of each product in each showroom
    config(['platform.payments.sadad.enabled' => false]);
});

function paymentOption(array $quote, string $code): array
{
    return collect($quote['payment_methods'])->firstWhere('code', $code);
}

/** Puts `$qty` of the QAR 1,000 sofa (or the QAR 250 cushion) in the bag and asks for a delivery quote. */
function quoteBasket(int $qty, string $product = 'v1', array $options = []): array
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => test()->w->{$product}->id, 'quantity' => $qty])->assertCreated();

    return test()->postJson('/api/v1/checkout/quote', $options + ['address_id' => test()->w->address->id])->assertOk()->json('data');
}

it('says cash on delivery is outside its limit and gives the highest amount it accepts', function () {
    Branch::query()->update(['cod_max_order' => '5000.00']);

    $cod = paymentOption(quoteBasket(5), 'cod');   // 5 x 1,000 + the delivery fee

    expect($cod['available'])->toBeFalse()->and($cod['reason'])->toBe('outside_cod_limits')
        ->and($cod['limits']['max']['amount'])->toBe('5000.00')->and($cod['limits']['max']['formatted'])->toBe('QAR 5,000.00')
        ->and($cod['limits']['min'])->toBeNull();
});

it('gives the lowest amount cash on delivery accepts when the basket is below it', function () {
    Branch::query()->update(['cod_min_order' => '300.00', 'cod_max_order' => null]);

    $cod = paymentOption(quoteBasket(1, 'v2'), 'cod');   // 250 + 15 delivery = 265

    expect($cod['reason'])->toBe('outside_cod_limits')->and($cod['limits']['min']['amount'])->toBe('300.00')->and($cod['limits']['max'])->toBeNull();
});

it('uses the strictest limits of every branch in a split order', function () {
    $this->w->a->update(['cod_max_order' => '9000.00', 'cod_min_order' => '100.00']);
    $this->w->b->update(['cod_max_order' => '5500.00', 'cod_min_order' => '200.00']);

    $q = quoteBasket(8);   // 5 from one showroom + 3 from the other: 8,000 + two delivery fees
    $cod = paymentOption($q, 'cod');

    expect($q['is_split'])->toBeTrue()->and($cod['reason'])->toBe('outside_cod_limits')
        ->and($cod['limits']['max']['amount'])->toBe('5500.00')->and($cod['limits']['min']['amount'])->toBe('200.00');
});

it('sends no limits while cash on delivery is offered, nor for the other reasons', function () {
    $q = quoteBasket(1);
    $cod = paymentOption($q, 'cod');
    expect($cod['available'])->toBeTrue()->and($cod['reason'])->toBeNull()->and($cod)->not->toHaveKey('limits');

    Branch::query()->update(['cod_enabled' => false]);
    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $this->w->address->id])->json('data');
    $cod = paymentOption($q, 'cod');
    expect($cod['reason'])->toBe('not_offered_in_area')->and($cod)->not->toHaveKey('limits');
});

it('names every other reason a method can be missing', function () {
    $q = quoteBasket(1);
    expect(paymentOption($q, 'sadad')['reason'])->toBe('temporarily_unavailable')
        ->and(paymentOption($q, 'pay_at_pickup')['reason'])->toBe('pickup_only');

    $pickup = $this->postJson('/api/v1/checkout/quote', ['fulfillment_type' => 'pickup', 'branch_id' => $this->w->a->id])->json('data');
    expect(paymentOption($pickup, 'cod')['reason'])->toBe('delivery_only')->and(paymentOption($pickup, 'pay_at_pickup')['available'])->toBeTrue();

    Branch::query()->update(['pay_at_pickup_enabled' => false]);
    $pickup = $this->postJson('/api/v1/checkout/quote', ['fulfillment_type' => 'pickup', 'branch_id' => $this->w->a->id])->json('data');
    expect(paymentOption($pickup, 'pay_at_pickup')['reason'])->toBe('not_offered_at_branch');
});
