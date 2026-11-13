<?php

/* Shared by the live order notification tests (LiveOrder*Test.php): small fixtures that put one order into any stage. */

use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderProgress;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Freezes the clock (default: Saturday 14 Nov 2026, 18:00 in Qatar = 15:00 UTC; every branch is open) and returns it. */
function lopFreeze(string $at = '2026-11-14 18:00:00'): CarbonImmutable
{
    $now = CarbonImmutable::parse($at, 'Asia/Qatar');
    test()->travelTo($now);

    return $now;
}

/** A cash-on-delivery order (delivery) or a pay-at-pickup order (pickup at branch A) placed now by the world's customer. */
function lopPlace(object $w, string $type = 'delivery'): Order
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertCreated();
    $options = $type === 'pickup'
        ? ['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup']
        : ['address_id' => $w->address->id, 'payment_method' => 'cod'];

    return Order::with('fulfillments')->findOrFail(quoteAndPlace($options)->assertCreated()->json('data.order.id'));
}

/** Moves the order's (first) fulfilment to a status and optionally gives it a delivery; returns the order as freshly read. */
function lopMove(Order $order, FS $status, array $fulfillment = [], ?DS $delivery = null, ?User $driver = null, array $deliveryAttrs = []): Order
{
    $f = $order->fulfillments()->orderBy('number')->firstOrFail();
    $f->forceFill(['status' => $status->value] + $fulfillment)->save();
    if ($delivery !== null) {
        lopDelivery($f, $delivery, $driver, $deliveryAttrs);
    }

    return Order::findOrFail($order->id);
}

function lopDelivery(OrderFulfillment $f, DS $status, ?User $driver = null, array $attrs = []): Delivery
{
    return Delivery::create($attrs + ['order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id, 'mode' => 'internal', 'driver_id' => $driver?->id,
        'status' => $status->value, 'tracking_number' => 'TRK'.Str::upper(Str::random(10)), 'assigned_at' => now()]);
}

/** A delivery slot of the fulfilment's branch on the given local date, saved on the fulfilment. */
function lopSlot(OrderFulfillment $f, string $date, string $from, string $to): DeliverySlot
{
    $slot = DeliverySlot::create(['branch_id' => $f->branch_id, 'fulfillment_type' => $f->type, 'day_of_week' => CarbonImmutable::parse($date)->dayOfWeek,
        'starts_at' => $from, 'ends_at' => $to, 'capacity' => 5, 'cutoff_minutes' => 30]);
    $f->forceFill(['delivery_slot_id' => $slot->id, 'slot_date' => $date])->save();

    return $slot;
}

/** The live order card of an order, as freshly read, in a language. @return array<string, mixed> */
function lopCard(Order $order, string $locale = 'en', bool $remote = true): array
{
    return app(OrderProgress::class)->forOrder(Order::findOrFail($order->id), $locale, $remote);
}

/**
 * Makes Firebase "configured" (a throw-away service account) and fakes Google's endpoints. The FCM answer is read from
 * `$fcm` on every call, so a test can change it as it goes: 'mode' = ok | down (connection error) | error (HTTP 500) |
 * gone (token unknown), and 'bad_token' = a token whose request always fails to connect.
 */
function lopFcm(): ArrayObject
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode(['client_email' => 'push@albahar-test.iam.gserviceaccount.com', 'private_key' => $pem]));
    register_shutdown_function(fn () => @unlink($path));
    config(['services.fcm.project_id' => 'albahar-test', 'services.fcm.credentials_path' => $path]);

    $fcm = new ArrayObject(['mode' => 'ok', 'bad_token' => null]);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']),
        'fcm.googleapis.com/*' => function ($request) use ($fcm) {
            if ($fcm['mode'] === 'down' || ($fcm['bad_token'] !== null && ($request->data()['message']['token'] ?? null) === $fcm['bad_token'])) {
                throw new ConnectionException('Firebase is not reachable');
            }

            return match ($fcm['mode']) {
                'error' => Http::response(['error' => ['code' => 500]], 500),
                'gone' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
                default => Http::response(['name' => 'projects/albahar-test/messages/1']),
            };
        },
    ]);

    return $fcm;
}

/**
 * The FCM messages sent so far, in order: the live order cards by default, or every message when $all (the template
 * notifications, driver and staff pushes carry a `notification` block and no order_progress data).
 *
 * @return list<array<string, mixed>>
 */
function lopSent(bool $all = false): array
{
    return Http::recorded(fn ($request) => str_contains($request->url(), 'messages:send'))->map(fn ($pair) => $pair[0]->data()['message'])
        ->filter(fn (array $m) => $all || ($m['data']['kind'] ?? null) === 'order_progress')->values()->all();
}
