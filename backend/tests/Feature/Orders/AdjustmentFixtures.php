<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Settings\Services\Settings;

/**
 * An order of branch A that is being prepared: two sofas at 1000 (line 1) and a lamp at 250 (line 2) (cash on delivery: 2000 + 250 + 15 delivery +
 * 10 cash-on-delivery fee = 2275). A branch manager of branch A is signed in. Options: payment 'cod'|'sadad' (paid), status 'accepted'|'preparing',
 * tax percent, coupon [type, value], loyalty points redeemed, qty [sofas, lamps], stockA, stockB, locale.
 *
 * @param  array<string, mixed>  $o
 */
function adjustmentWorld(array $o = []): object
{
    $payment = $o['payment'] ?? 'cod';
    if ($payment === 'sadad') {
        config(['platform.payments.sadad' => array_merge(config('platform.payments.sadad'), [
            'enabled' => true, 'merchant_id' => '1234567', 'secret_key' => 'test-secret', 'website' => 'albahar.qa', 'checkout_url' => 'https://sandbox.sadad.example/webpurchase',
            'status_url' => null, 'capabilities' => ['hosted_checkout' => true, 'status_api' => false, 'refund_api' => false, 'void' => false, 'authorize_capture' => false],
        ])]);
    }
    $w = commerceWorld($o['stockA'] ?? [10, 10], $o['stockB'] ?? [10, 10]);
    if (isset($o['tax'])) {
        app(Settings::class)->set('checkout.tax_rate_percent', (string) $o['tax']);
    }
    if (isset($o['locale'])) {
        $w->customer->forceFill(['locale' => $o['locale']])->save();
    }
    [$q1, $q2] = $o['qty'] ?? [2, 1];
    if ($q1 > 0) {
        test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => $q1])->assertSuccessful();
    }
    if ($q2 > 0) {
        test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => $q2])->assertSuccessful();
    }
    $opts = ['address_id' => $w->address->id, 'payment_method' => $payment];
    if (isset($o['coupon'])) {
        $promo = Promotion::create(['name_en' => 'Deal', 'name_ar' => 'عرض', 'type' => $o['coupon'][0], 'value' => $o['coupon'][1], 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
        Coupon::create(['promotion_id' => $promo->id, 'code' => 'ADJ']);
        $opts['coupon_code'] = 'ADJ';
    }
    if (isset($o['loyalty'])) {
        app(LoyaltyService::class)->award($w->customer, 100000, 'adjust', 'test points');
        $opts['loyalty_points'] = $o['loyalty'];
    }
    $order = Order::find(quoteAndPlace($opts)->assertCreated()->json('data.order.id'));
    if ($payment === 'sadad') {
        app(PaymentService::class)->markPaid(Payment::where('order_id', $order->id)->firstOrFail());
    }
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    $f = $order->fresh()->fulfillments->first();
    foreach (['accepted', 'preparing'] as $s) {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
        if ($s === ($o['status'] ?? 'preparing')) {
            break;
        }
    }
    $order = $order->fresh();

    return (object) ['w' => $w, 'order' => $order, 'f' => $f->fresh(), 'mgr' => $mgr, 'l1' => lineOf($order, $w->v1), 'l2' => lineOf($order, $w->v2), 'customer' => $w->customer];
}

function lineOf(Order $order, $variant): ?OrderItem
{
    return OrderItem::where('order_id', $order->id)->where('product_variant_id', $variant->id)->first();
}

/** A product the branch stocks: price 600 unless given, 10 units on hand at branch A. @return array{0: Product, 1: \App\Domain\Catalog\Models\ProductVariant} */
function substituteFor(object $w, string $price = '600.00', int $stock = 10): array
{
    $p = Product::factory()->create(['price' => $price]);
    $v = $p->variants()->first();
    if ($stock > 0) {
        app(InventoryLedger::class)->apply($w->a->id, $v->id, MovementType::OpeningBalance, $stock);
    }

    return [$p, $v];
}

function adjustUrl(object $a, string $path = 'adjustments'): string
{
    return "/api/v1/admin/fulfillments/{$a->f->id}/{$path}";
}

/** POST an adjustment as the signed-in staff member. */
function postAdjust(object $a, array $changes, string $reason = 'out_of_stock', array $extra = [])
{
    return test()->postJson(adjustUrl($a), ['changes' => $changes, 'reason_code' => $reason] + $extra);
}

/**
 * Another order in the same world (the customer places it, the branch manager accepts it and starts preparing it); the manager is signed in again.
 *
 * @return array{0: Order, 1: App\Domain\Orders\Models\OrderFulfillment}
 */
function anotherPreparedOrder(object $a, int $sofas = 1, int $lamps = 1): array
{
    actingAsCustomer($a->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $a->w->v1->id, 'quantity' => $sofas])->assertSuccessful();
    if ($lamps > 0) {
        test()->postJson('/api/v1/cart/items', ['variant_id' => $a->w->v2->id, 'quantity' => $lamps])->assertSuccessful();
    }
    $order = Order::find(quoteAndPlace(['address_id' => $a->w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id'));
    actingAsStaff($a->mgr);
    $f = $order->fresh()->fulfillments->first();
    foreach (['accepted', 'preparing'] as $s) {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }

    return [$order->fresh(), $f->fresh()];
}
