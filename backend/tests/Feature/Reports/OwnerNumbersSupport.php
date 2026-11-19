<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

// Fixtures for the owner's-numbers tests (dashboard, reports, campaign sending). Rows are written directly with concrete amounts, so every
// expected figure in those tests can be checked by hand. require_once keeps them defined once per run.

/** Two branches, a customer and a company-wide admin. */
function onWorld(): object
{
    // "Today" is a Qatar day (UTC+3). Without a fixed clock these tests fail between 21:00 and 24:00 UTC, when Qatar is already on the next date, so every test
    // here starts at midday on a fixed Qatar day and the expected figures never depend on when the suite runs.
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Qatar'));
    test()->seed(RolesAndPermissionsSeeder::class);

    return (object) [
        'a' => Branch::factory()->create(['code' => 'DOH', 'name_en' => 'Doha']),
        'b' => Branch::factory()->create(['code' => 'WKR', 'name_en' => 'Wakra']),
        'customer' => User::factory()->create(),
        'admin' => staffWithRole('admin'),
    ];
}

/**
 * An order with one fulfillment per entry of $parts ([branch, subtotal, delivery fee, optional status]). Each part has one line worth its subtotal.
 * grand_total defaults to the sum of the parts.
 *
 * @param  list<array{0: Branch, 1: string, 2?: string, 3?: string}>  $parts
 * @param  array<string, mixed>  $attrs  overrides for the orders row (status, placed_at, grand_total, payment_method, payment_status, user_id ...)
 */
function onOrder(array $parts, array $attrs = []): Order
{
    $sum = Money::zero();
    foreach ($parts as $p) {
        $sum = $sum->plus(Money::of($p[1]))->plus(Money::of($p[2] ?? '0.00'));
    }
    $sum = $sum->toDecimal();
    $order = Order::create($attrs + [
        'number' => 'ON'.strtoupper(Str::random(10)), 'user_id' => test()->world->customer->id ?? null, 'source' => 'app', 'status' => 'confirmed',
        'payment_status' => 'pending', 'payment_method' => 'cod', 'fulfillment_type' => 'delivery', 'currency' => 'QAR',
        'subtotal' => $sum, 'delivery_fee_total' => '0.00', 'grand_total' => $sum, 'customer_name' => 'Noor Ali', 'placed_at' => now(),
    ]);
    foreach ($parts as $i => $p) {
        $f = OrderFulfillment::create(['order_id' => $order->id, 'branch_id' => $p[0]->id, 'number' => $order->number.'-'.($i + 1), 'type' => 'delivery',
            'status' => $p[3] ?? 'pending', 'subtotal' => $p[1], 'delivery_fee' => $p[2] ?? '0.00']);
        OrderItem::create(['order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $p[0]->id, 'sku' => 'SKU-'.($i + 1), 'name_en' => 'Majlis sofa',
            'name_ar' => 'كنبة مجلس', 'unit_price' => $p[1], 'unit_final_price' => $p[1], 'quantity' => 1, 'line_subtotal' => $p[1], 'line_total' => $p[1]]);
    }

    return $order->fresh(['fulfillments', 'items']);
}

/** Money that came in online for an order: a captured SADAD payment, paid now unless told otherwise. */
function onOnlinePayment(Order $order, string $amount, array $attrs = []): Payment
{
    return Payment::create($attrs + ['payable_type' => 'order', 'payable_id' => $order->id, 'user_id' => $order->user_id, 'order_id' => $order->id, 'method' => 'sadad', 'provider' => 'sadad',
        'status' => 'paid', 'currency' => 'QAR', 'amount' => $amount, 'captured_amount' => $amount, 'paid_at' => now()]);
}

/** Cash a driver or counter took for an order (cash on delivery / pay at pickup), recorded now unless told otherwise. */
function onCashCollection(Order $order, string $amount, array $attrs = []): CashCollection
{
    $f = $order->fulfillments->first();
    $payment = Payment::create(['payable_type' => 'order', 'payable_id' => $order->id, 'user_id' => $order->user_id, 'order_id' => $order->id, 'method' => 'cod', 'provider' => 'cod',
        'status' => 'paid', 'currency' => 'QAR', 'amount' => $amount, 'captured_amount' => $amount, 'paid_at' => now()]);

    return CashCollection::create($attrs + ['payment_id' => $payment->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id, 'collected_by' => test()->world->admin->id,
        'context' => 'cod', 'tender' => 'cash', 'amount_due' => $amount, 'amount_collected' => $amount, 'status' => 'collected', 'collected_at' => now()]);
}

/** A refund on an order's payment (processed now unless told otherwise). */
function onRefund(Order $order, string $amount, string $reason = 'return', array $attrs = []): Refund
{
    $payment = $order->payments()->first() ?? onOnlinePayment($order, $order->grand_total);

    return Refund::create($attrs + ['number' => 'RF'.strtoupper(Str::random(8)), 'payment_id' => $payment->id, 'order_id' => $order->id, 'amount' => $amount, 'currency' => 'QAR',
        'reason_code' => $reason, 'method' => 'manual_bank_transfer', 'status' => 'processed', 'processed_at' => now()]);
}

/** Product variants to hold stock. @return list<App\Domain\Catalog\Models\ProductVariant> */
function onVariants(int $n): array
{
    return collect(range(1, $n))->map(fn () => Product::factory()->create(['price' => '10.00'])->variants()->first())->all();
}
