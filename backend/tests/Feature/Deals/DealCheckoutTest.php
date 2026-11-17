<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Models\DealRedemption;
use App\Domain\Deals\Services\DealService;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Settings\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/DealFixtures.php';

beforeEach(function () {
    $this->w = commerceWorld();
});

it('gives the deal price to the units the per-order limit allows and the normal price to the rest', function () {
    $deal = makeDeal(); // 30 % off a 1000.00 sofa, at most 2 per order
    dealAddToCart($this->w->v1->id, 3);

    $q = dealQuote();

    $line = $q['lines'][0];
    expect($line['quantity'])->toBe(3)
        ->and($line['deal'])->toMatchArray(['deal_id' => $deal->id, 'name' => 'Weekend Furniture Drop', 'units_at_deal_price' => 2, 'units_at_regular_price' => 1, 'note' => 'limit_per_order'])
        ->and($line['deal']['savings']['amount'])->toBe('600.00')
        ->and($line['line_subtotal']['amount'])->toBe('3000.00')->and($line['discount']['amount'])->toBe('600.00')->and($line['line_total']['amount'])->toBe('2400.00')
        ->and($q['totals']['deal_savings']['amount'])->toBe('600.00')->and($q['totals']['discount']['amount'])->toBe('600.00')
        ->and($q['totals']['grand_total']['amount'])->toBe('2415.00') // 2400 + 15 delivery
        ->and($q['discounts'])->toHaveCount(1)
        ->and($q['discounts'][0])->toMatchArray(['source' => 'deal', 'deal_id' => $deal->id, 'label' => 'Weekend Furniture Drop', 'promotion_id' => null])
        ->and($q['discounts'][0]['amount']['amount'])->toBe('600.00');
});

it('sells every unit at the deal price while the limits allow it and says nothing when nothing was cut', function () {
    makeDeal(['limit_per_order' => 5]);
    dealAddToCart($this->w->v1->id, 2);

    $line = dealQuote()['lines'][0];

    expect($line['deal']['units_at_deal_price'])->toBe(2)->and($line['deal']['units_at_regular_price'])->toBe(0)->and($line['deal']['note'])->toBeNull();
});

it('has no deal on products outside any deal, and an empty "deal" key on their lines', function () {
    makeDeal();
    dealAddToCart($this->w->v2->id, 1);

    $q = dealQuote();

    expect($q['lines'][0]['deal'])->toBeNull()->and($q['totals']['deal_savings']['amount'])->toBe('0.00')->and($q['discounts'])->toBe([]);
});

it('never charges more than the normal price: a fixed deal price above the unit price gives no deal', function () {
    makeDeal(['discount_type' => 'fixed_price', 'discount_value' => '0', 'products' => [['product_id' => $this->w->p2->id, 'deal_price' => '300.00', 'quantity_total' => 5]]], []);
    dealAddToCart($this->w->v2->id, 1); // 250.00, cheaper than the 300.00 "deal"

    $q = dealQuote();

    expect($q['lines'][0]['deal'])->toBeNull()->and($q['totals']['grand_total']['amount'])->toBe('265.00');
});

it('caps the deal at the units the deal still has and says it ran out', function () {
    makeDeal(['limit_per_order' => 5], [$this->w->p1->id => 2]);
    dealAddToCart($this->w->v1->id, 3);

    $line = dealQuote()['lines'][0];

    expect($line['deal']['units_at_deal_price'])->toBe(2)->and($line['deal']['units_at_regular_price'])->toBe(1)->and($line['deal']['note'])->toBe('sold_out');
});

it('claims the units inside the order, snapshots the deal on the line and the order, and keeps the paid total equal to the quote', function () {
    $deal = makeDeal();
    dealAddToCart($this->w->v1->id, 3);
    $q = dealQuote();

    $res = placeDealOrder(hash: $q['quote_hash'])->assertCreated();

    $order = dealOrderOf($res);
    $item = $order->items->first();
    $row = DealProduct::where('deal_id', $deal->id)->first();
    expect($order->grand_total)->toBe($q['totals']['grand_total']['amount'])->and($order->discount_total)->toBe('600.00')
        ->and($item->deal_id)->toBe($deal->id)->and($item->deal_units)->toBe(2)->and($item->deal_unit_savings)->toBe('300.00')->and($item->deal_name_en)->toBe('Weekend Furniture Drop')
        ->and($row->quantity_sold)->toBe(2)
        ->and(DealRedemption::where('order_id', $order->id)->first()->only(['units', 'user_id']))->toBe(['units' => 2, 'user_id' => $this->w->customer->id])
        ->and($res->json('data.order.items.0.deal'))->toMatchArray(['deal_id' => $deal->id, 'units' => 2])
        ->and($res->json('data.order.discounts.0.source'))->toBe('deal')->and($res->json('data.order.totals.deal_savings.amount'))->toBe('600.00');
    expect($order->coupons)->toHaveCount(1)->and($order->coupons->first()->deal_id)->toBe($deal->id)->and($order->coupons->first()->promotion_id)->toBeNull();
});

it('holds the per-account limit over two orders', function () {
    makeDeal(['limit_per_order' => 5, 'limit_per_customer' => 3]);
    dealAddToCart($this->w->v1->id, 2);
    placeDealOrder()->assertCreated();

    dealAddToCart($this->w->v1->id, 2);
    $line = dealQuote()['lines'][0];
    expect($line['deal']['units_at_deal_price'])->toBe(1)->and($line['deal']['units_at_regular_price'])->toBe(1)->and($line['deal']['note'])->toBe('limit_per_customer');
    placeDealOrder()->assertCreated();

    dealAddToCart($this->w->v1->id, 1);
    $third = dealQuote()['lines'][0]['deal'];
    expect($third['units_at_deal_price'])->toBe(0)->and($third['note'])->toBe('limit_per_customer');
    expect(DealProduct::first()->quantity_sold)->toBe(3);
});

it('does not count another customer in a per-account limit', function () {
    makeDeal(['limit_per_order' => 5, 'limit_per_customer' => 2]);
    dealAddToCart($this->w->v1->id, 2);
    placeDealOrder()->assertCreated();

    $other = User::factory()->create();
    $other->addresses()->create(['recipient_name' => 'Sara', 'recipient_phone' => '+97455123457', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '12', 'is_default' => true]);
    actingAsCustomer($other);
    dealAddToCart($this->w->v1->id, 2);

    expect(dealQuote(['address_id' => $other->addresses()->first()->id])['lines'][0]['deal']['units_at_deal_price'])->toBe(2);
});

it('gives the last unit to one order only: the second placement gets deal_changed with the fresh quote', function () {
    $deal = makeDeal(['limit_per_order' => 5], [$this->w->p1->id => 1]);
    $second = User::factory()->create();
    $second->addresses()->create(['recipient_name' => 'Sara', 'recipient_phone' => '+97455123457', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '12', 'is_default' => true]);

    // Both customers see the same quote with the last unit at the deal price.
    dealAddToCart($this->w->v1->id, 1);
    $firstQuote = dealQuote();
    $firstOptions = dealOptions();
    actingAsCustomer($second);
    dealAddToCart($this->w->v1->id, 1);
    $opts = ['address_id' => $second->addresses()->first()->id, 'payment_method' => 'cod'];
    $secondQuote = dealQuote($opts);
    expect($firstQuote['lines'][0]['deal']['units_at_deal_price'])->toBe(1)->and($secondQuote['lines'][0]['deal']['units_at_deal_price'])->toBe(1);

    // The second one places first and takes the unit; the first customer then confirms the quote they saw.
    $this->postJson('/api/v1/checkout/orders', $opts + ['quote_hash' => $secondQuote['quote_hash']], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    actingAsCustomer($this->w->customer);
    $res = $this->postJson('/api/v1/checkout/orders', $firstOptions + ['quote_hash' => $firstQuote['quote_hash']], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('code', 'deal_changed');

    expect($res->json('context.deal_id'))->toBe($deal->id)->and($res->json('context.product_id'))->toBe($this->w->p1->id)
        ->and($res->json('context.quote.lines.0.deal.units_at_deal_price'))->toBe(0)->and($res->json('context.quote.lines.0.deal.note'))->toBe('sold_out')
        ->and($res->json('context.quote.totals.grand_total.amount'))->toBe('1015.00')->and($res->json('message'))->toContain('deal changed')
        ->and(DealProduct::first()->quantity_sold)->toBe(1)->and(Order::count())->toBe(1);

    // Confirming the fresh quote places the order at the normal price.
    $fresh = $res->json('context.quote.quote_hash');
    $this->postJson('/api/v1/checkout/orders', $firstOptions + ['quote_hash' => $fresh], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    expect(DealProduct::first()->quantity_sold)->toBe(1);
});

it('answers deal_changed, not a silent new price, when the deal ended while the customer was in checkout', function () {
    $deal = makeDeal();
    dealAddToCart($this->w->v1->id, 1);
    $q = dealQuote();
    expect($q['totals']['deal_savings']['amount'])->toBe('300.00');

    app(DealService::class)->end(dealAdmin(), $deal);

    $res = placeDealOrder(hash: $q['quote_hash'])->assertStatus(409)->assertJsonPath('code', 'deal_changed');
    expect($res->json('context.quote.totals.deal_savings.amount'))->toBe('0.00')->and($res->json('context.quote.lines.0.deal.note'))->toBe('ended')
        ->and($res->json('context.quote.lines.0.deal.units_at_deal_price'))->toBe(0)->and(Order::count())->toBe(0)->and(DealProduct::first()->quantity_sold)->toBe(0);
});

it('keeps saying quote_changed when something other than a deal changed', function () {
    makeDeal();
    dealAddToCart($this->w->v1->id, 1);
    $q = dealQuote();
    app(Settings::class)->set('checkout.service_fee', '5.00'); // a change the deal has nothing to do with

    placeDealOrder(hash: $q['quote_hash'])->assertStatus(409)->assertJsonPath('code', 'quote_changed');
});

it('releases the units when the order is cancelled, and a second cancel releases nothing more', function () {
    $deal = makeDeal(['limit_per_customer' => 2]);
    dealAddToCart($this->w->v1->id, 2);
    $order = dealOrderOf(placeDealOrder()->assertCreated());
    expect(DealProduct::first()->quantity_sold)->toBe(2);

    app(OrderService::class)->cancelOrder($order, null, 'customer_request', system: true);
    expect(DealProduct::first()->quantity_sold)->toBe(0)->and(DealRedemption::first()->released_at)->not->toBeNull()->and(DealRedemption::first()->units)->toBe(0);

    app(OrderService::class)->cancelOrder($order->fresh(), null, 'customer_request', system: true);
    expect(DealProduct::first()->quantity_sold)->toBe(0)
        ->and(app(DealService::class)->release($order->id))->toBe(0)->and(DealProduct::first()->quantity_sold)->toBe(0);

    // The customer has their allowance back.
    dealAddToCart($this->w->v1->id, 2);
    expect(dealQuote()['lines'][0]['deal']['units_at_deal_price'])->toBe(2);
});

it('releases the units of an unpaid online order that expired', function () {
    config(['platform.payments.sadad' => array_merge(config('platform.payments.sadad'), ['enabled' => true])]);
    makeDeal();
    dealAddToCart($this->w->v1->id, 2);
    $order = dealOrderOf(placeDealOrder(['payment_method' => 'sadad'])->assertCreated());
    expect(DealProduct::first()->quantity_sold)->toBe(2);

    $this->travel(2)->hours();
    app(PaymentService::class)->expireUnpaidOrders();

    expect($order->fresh()->status->value)->toBe('cancelled')->and(DealProduct::first()->quantity_sold)->toBe(0);
});

it('releases everything on a full refund', function () {
    makeDeal();
    dealAddToCart($this->w->v1->id, 2);
    $order = dealOrderOf(placeDealOrder()->assertCreated());

    expect(app(DealService::class)->release($order->id, everything: true))->toBe(2)
        ->and(DealProduct::first()->quantity_sold)->toBe(0)->and(app(DealService::class)->release($order->id, everything: true))->toBe(0);
});

it('does not discount deal units twice: an automatic promotion only sees the units without the deal price', function () {
    makeDeal(['limit_per_order' => 1]);
    Promotion::create(['name_en' => '10 off', 'name_ar' => 'خصم', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => false, 'is_active' => true,
        'priority' => 10, 'is_stackable' => false]);
    dealAddToCart($this->w->v1->id, 3);

    $q = dealQuote();

    // 1 unit at 700 (deal), 2 units at 1000 with 10 % off = 200.
    expect($q['totals']['deal_savings']['amount'])->toBe('300.00')->and($q['totals']['discount']['amount'])->toBe('500.00')
        ->and($q['lines'][0]['discount']['amount'])->toBe('500.00')
        ->and(collect($q['discounts'])->pluck('source')->all())->toBe(['deal', 'promotion']);

    // A basket of deal units only gets no promotion at all.
    $this->deleteJson('/api/v1/cart/items/'.$this->getJson('/api/v1/cart')->json('data.items.0.id'));
    dealAddToCart($this->w->v1->id, 1);
    $only = dealQuote();
    expect($only['totals']['discount']['amount'])->toBe('300.00')->and(collect($only['discounts'])->pluck('source')->all())->toBe(['deal']);
});

it('still lets a free-delivery code waive delivery on a basket of nothing but deal units', function () {
    makeDeal(['limit_per_order' => 5]);
    $promo = Promotion::create(['name_en' => 'Free delivery', 'name_ar' => 'توصيل مجاني', 'type' => 'free_delivery', 'value' => 0, 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true, 'priority' => 10]);
    Coupon::create(['promotion_id' => $promo->id, 'code' => 'FREESHIP', 'is_active' => true]);
    dealAddToCart($this->w->v1->id, 1);

    $q = dealQuote(['coupon_code' => 'FREESHIP']);

    expect($q['totals']['delivery_fee']['amount'])->toBe('15.00')->and($q['totals']['discount']['amount'])->toBe('315.00') // 300 deal + 15 delivery waived
        ->and($q['totals']['grand_total']['amount'])->toBe('700.00')->and(collect($q['warnings'])->pluck('code')->all())->not->toContain('coupon_not_eligible');
});

it('does not give deal prices to an order made by staff', function () {
    makeDeal();
    $svc = app(StaffOrderService::class);
    $staff = User::factory()->superAdmin()->create();
    $draft = $svc->create($staff, $this->w->a->id, $this->w->customer->id);
    $svc->add($staff, $draft, $this->w->v1->id, 2);
    $svc->setOptions($staff, $draft, ['fulfillment_type' => 'pickup', 'branch_id' => $this->w->a->id, 'payment_method' => 'pay_at_pickup']);

    $quote = $svc->quote($staff, $draft->fresh());

    expect($quote->lines()->first()->deal)->toBeNull()->and($quote->dealSavingsTotal()->minor)->toBe(0)->and($quote->subtotal()->minor)->toBe(200000);
});

it('refuses to let quantity_sold pass quantity_total at the database level', function () {
    $deal = makeDeal([], [$this->w->p1->id => 3]);
    $row = DealProduct::where('deal_id', $deal->id)->first();

    expect(fn () => DB::transaction(fn () => DB::update('UPDATE deal_products SET quantity_sold = 4 WHERE id = ?', [$row->id])))->toThrow(QueryException::class);
    DB::update('UPDATE deal_products SET quantity_sold = 3 WHERE id = ?', [$row->id]);
    expect($row->fresh()->remaining())->toBe(0);
});

it('returns a deal unit when an item adjustment removes a deal unit', function () {
    $deal = makeDeal(['limit_per_order' => 2, 'limit_per_customer' => 2]);
    dealAddToCart($this->w->v1->id, 2);
    $order = dealOrderOf(placeDealOrder()->assertCreated());
    $item = $order->items->first();
    $manager = actingAsStaff(staffWithRole('order_manager', $this->w->a));
    $f = $order->fulfillments()->first();
    foreach (['accepted', 'preparing'] as $s) {
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }
    expect(DealProduct::first()->quantity_sold)->toBe(2);

    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/adjustments", ['reason_code' => 'out_of_stock', 'changes' => [['order_item_id' => $item->id, 'action' => 'reduce', 'new_quantity' => 1]]])->assertOk();

    expect($item->fresh()->quantity)->toBe(1)->and($item->fresh()->deal_units)->toBe(1)->and(DealProduct::first()->quantity_sold)->toBe(1)
        ->and(DealRedemption::first()->units)->toBe(1)->and(DealRedemption::first()->released_at)->toBeNull();
});

it('gives every deal unit back when the whole line is taken off the order, and only the removed share when it shrinks', function () {
    makeDeal(['limit_per_order' => 4, 'limit_per_customer' => 4], [$this->w->p1->id => 10]);
    dealAddToCart($this->w->v1->id, 4);
    $order = dealOrderOf(placeDealOrder()->assertCreated());
    $item = $order->items->first();
    actingAsStaff(staffWithRole('order_manager', $this->w->a));
    $f = $order->fulfillments()->first();
    foreach (['accepted', 'preparing'] as $s) {
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }

    // 4 -> 3 units: a quarter of the line (and of its deal units) goes.
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/adjustments", ['reason_code' => 'out_of_stock', 'changes' => [['order_item_id' => $item->id, 'action' => 'reduce', 'new_quantity' => 3]]])->assertOk();
    expect($item->fresh()->deal_units)->toBe(3)->and(DealProduct::first()->quantity_sold)->toBe(3);

    // 3 -> 0: the rest goes back and the redemption is closed.
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/adjustments", ['reason_code' => 'out_of_stock', 'changes' => [['order_item_id' => $item->id, 'action' => 'remove']]])->assertOk();
    expect(DealProduct::first()->quantity_sold)->toBe(0)->and(DealRedemption::first()->released_at)->not->toBeNull()->and(DealRedemption::first()->units)->toBe(0);
});

it('writes an "updated" audit entry with what changed when a deal is edited', function () {
    $deal = makeDeal();

    makeDeal(['name_en' => 'Renamed', 'discount_value' => '40'], [$this->w->p1->id => 10], $deal);

    $log = AuditLog::where('action', 'deals.updated')->firstOrFail();
    expect($log->auditable_id)->toBe($deal->id)->and($log->new_values)->toMatchArray(['name_en' => 'Renamed', 'discount_value' => '40.00'])->and($log->old_values)->toMatchArray(['name_en' => 'Weekend Furniture Drop']);
});
