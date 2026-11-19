<?php

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;

/*
| Shared fixtures of the order / delivery integrity tests (docs/operations/DISPATCH.md). Names start with "ops" so they never clash with another file's helpers.
*/

/** Replaces Firebase with a recorder: every push is [token, title, body, data]. Returns the list. */
function opsFakeFcm(): ArrayObject
{
    $calls = new ArrayObject;
    $fcm = Mockery::mock(FcmClient::class);
    $fcm->shouldReceive('configured')->andReturn(true);
    $fcm->shouldReceive('sendProgress')->andReturn(['ok' => true]);
    $fcm->shouldReceive('send')->andReturnUsing(function (string $token, string $title, string $body, array $data = []) use ($calls) {
        $calls[] = [$token, $title, $body, $data];

        return ['ok' => true];
    });
    app()->instance(FcmClient::class, $fcm);

    return $calls;
}

function opsPhone(object $user, string $token, string $locale = 'en'): void
{
    UserDevice::create(['user_id' => $user->id, 'platform' => 'android', 'push_token' => $token, 'locale' => $locale]);
}

/**
 * A cash on delivery order of branch A, ready and handed to a driver (assigned). The session is the branch's order manager.
 * Returns the world, the driver, the manager, the parcel, the delivery and the order.
 */
function opsAssignedDelivery(string $status = 'assigned'): object
{
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f, $mgr] = readyOrder($w);
    test()->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $delivery = Delivery::where('order_fulfillment_id', $f->id)->firstOrFail();
    if ($status === 'accepted') {
        actingAsStaff($driver);
        test()->postJson("/api/v1/admin/deliveries/{$delivery->id}/accept")->assertOk();
        actingAsStaff($mgr);
    }

    return (object) ['w' => $w, 'driver' => $driver, 'mgr' => $mgr, 'f' => $f->fresh(), 'delivery' => $delivery->fresh(), 'order' => Order::find($f->order_id)];
}

/**
 * An order that has to come from two branches: 3 + 2 sofas (branch A holds 3, B holds 2 usable after the first 3), cash on delivery unless told.
 * `fa` is the part of branch A (3 sofas), `fb` the part of branch B (2 sofas); `mgrA` / `mgrB` are their order managers; the session is the customer.
 *
 * @return object{w: object, order: Order, fa: OrderFulfillment, fb: OrderFulfillment, mgrA: object, mgrB: object}
 */
function opsSplitOrder(string $payment = 'cod'): object
{
    if ($payment === 'sadad') {
        config(['platform.payments.sadad' => array_merge(config('platform.payments.sadad'), [
            'enabled' => true, 'merchant_id' => '1234567', 'secret_key' => 'test-secret', 'website' => 'albahar.qa', 'checkout_url' => 'https://sandbox.sadad.example/webpurchase',
            'status_url' => null, 'capabilities' => ['hosted_checkout' => true, 'status_api' => false, 'refund_api' => false, 'void' => false, 'authorize_capture' => false],
        ])]);
    }
    $w = commerceWorld([3, 5], [3, 5]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 5])->assertCreated();
    $order = Order::with('fulfillments')->find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => $payment])->assertCreated()->json('data.order.id'));
    expect($order->fulfillments)->toHaveCount(2);
    if ($payment === 'sadad') {
        app(PaymentService::class)->markPaid(Payment::where('order_id', $order->id)->firstOrFail());
        $order = $order->fresh('fulfillments');
    }
    $fa = $order->fulfillments->firstWhere('branch_id', $w->a->id);
    $fb = $order->fulfillments->firstWhere('branch_id', $w->b->id);

    return (object) ['w' => $w, 'order' => $order, 'fa' => $fa, 'fb' => $fb, 'mgrA' => staffWithRole('order_manager', $w->a), 'mgrB' => staffWithRole('order_manager', $w->b)];
}

/** Walks a parcel through the given statuses as the system (what the staff and driver apps do step by step). */
function opsWalk($f, array $steps): void
{
    foreach ($steps as $step) {
        app(OrderService::class)->transition($f->fresh(), $step instanceof FS ? $step : FS::from($step), null, system: true);
    }
}
