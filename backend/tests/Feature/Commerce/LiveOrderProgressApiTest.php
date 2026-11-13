<?php

use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus as OS;
use App\Domain\Orders\Models\Order;

require_once __DIR__.'/LiveOrderHelpers.php';

/* GET /api/v1/orders/active-progress and the "progress" block of the order detail. */

beforeEach(function () {
    lopFreeze();
    $this->w = commerceWorld([50, 50], [50, 50]);
});

it('lists the live order card of each of my orders in progress, newest first', function () {
    $first = lopPlace($this->w);
    $this->travelTo(now()->addMinute());
    $second = lopMove(lopPlace($this->w), FS::Preparing);

    $rows = $this->getJson('/api/v1/orders/active-progress')->assertOk()->json('data');

    expect(array_column($rows, 'order_id'))->toBe([$second->id, $first->id])
        ->and($rows[0])->toMatchArray(['kind' => 'order_progress', 'number' => $second->number, 'phase' => 'preparing', 'step' => 1, 'total' => 4, 'type' => 'delivery', 'done' => '0'])
        ->and($rows[0])->toHaveKeys(['title', 'body', 'eta_label', 'eta_from', 'eta_to', 'driver_name', 'driver_phone', 'vehicle', 'seq', 'updated_at', 'deep_link', 'brand', 'step_label', 'detail'])
        ->and($rows[1]['phase'])->toBe('placed');
});

it('answers on /orders/active-progress and not as an order called "active-progress"', function () {
    $this->getJson('/api/v1/orders/active-progress')->assertOk()->assertJson(['data' => []]);
});

it('never shows another customer\'s order', function () {
    $mine = lopPlace($this->w);
    $other = User::factory()->create();
    $address = $other->addresses()->create(['recipient_name' => 'Sara', 'recipient_phone' => '+97455987654', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '9', 'is_default' => true]);
    actingAsCustomer($other);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v2->id, 'quantity' => 1])->assertCreated();
    $theirs = quoteAndPlace(['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id');

    expect(array_column($this->getJson('/api/v1/orders/active-progress')->assertOk()->json('data'), 'order_id'))->toBe([$theirs]);
    actingAsCustomer($this->w->customer);
    expect(array_column($this->getJson('/api/v1/orders/active-progress')->assertOk()->json('data'), 'order_id'))->toBe([$mine->id]);

    // The detail of someone else's order stays closed, with or without a progress block.
    $this->getJson("/api/v1/orders/{$theirs}")->assertNotFound();
});

it('needs a signed-in customer', function () {
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/orders/active-progress')->assertUnauthorized();
});

it('keeps staff out of the customer list', function () {
    actingAsStaff(staffWithRole('order_manager', $this->w->a));
    $this->getJson('/api/v1/orders/active-progress')->assertStatus(403);
});

it('keeps a finished order for 30 minutes and then drops it', function () {
    $order = lopMove(lopPlace($this->w), FS::Delivered, ['delivered_at' => now()], DS::Delivered, driverFor($this->w->a));
    $order->forceFill(['status' => OS::Completed->value, 'completed_at' => now()])->save();
    $this->travelTo(now()->addMinute());
    $cancelled = lopPlace($this->w);
    $cancelled->forceFill(['status' => OS::Cancelled->value, 'cancelled_at' => now()])->save();

    $rows = $this->getJson('/api/v1/orders/active-progress')->assertOk()->json('data');
    expect(collect($rows)->pluck('phase', 'order_id')->all())->toBe([$cancelled->id => 'cancelled', $order->id => 'delivered'])
        ->and(collect($rows)->every(fn ($r) => $r['done'] === '1'))->toBeTrue();

    $this->travelTo(now()->addMinutes(28));                       // 29 minutes after the first one, 28 after the second
    $this->getJson('/api/v1/orders/active-progress')->assertJsonCount(2, 'data');
    $this->travelTo(now()->addMinutes(3));                        // 31 and 32 minutes: both are over
    $this->getJson('/api/v1/orders/active-progress')->assertOk()->assertJson(['data' => []]);
});

it('does not list an order that was never finished but is a week old', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    $this->travelTo(now()->addDays(8));
    $this->getJson('/api/v1/orders/active-progress')->assertOk()->assertJson(['data' => []]);
    expect(Order::find($order->id))->not->toBeNull();
});

it('returns at most ten orders, or as many as the setting says', function () {
    config(['platform.live_order.max_active' => 2]);
    foreach (range(1, 3) as $i) {
        lopPlace($this->w);
        $this->travelTo(now()->addMinute());
    }

    $this->getJson('/api/v1/orders/active-progress')->assertOk()->assertJsonCount(2, 'data');
    expect(config('platform.live_order.max_active'))->toBe(2);
    config(['platform.live_order.max_active' => 10]);
    $this->getJson('/api/v1/orders/active-progress')->assertJsonCount(3, 'data');
});

it('writes the cards in the language of the request', function () {
    lopMove(lopPlace($this->w), FS::Preparing);

    expect($this->getJson('/api/v1/orders/active-progress')->json('data.0.title'))->toBe('Arrives at 6:35pm-6:55pm')
        ->and($this->getJson('/api/v1/orders/active-progress', ['Accept-Language' => 'ar'])->json('data.0.title'))->toBe('تصل بين 6:35 م و 6:55 م')
        ->and($this->getJson('/api/v1/orders/active-progress', ['Accept-Language' => 'ar'])->json('data.0.body'))->toStartWith('قيد التجهيز في')
        ->and($this->getJson('/api/v1/orders/active-progress?lang=ar', ['Accept-Language' => 'en'])->json('data.0.step_label'))->toBe('قيد التجهيز');
});

it('adds the same card to the order detail, never to the list', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);

    $detail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
    $card = $this->getJson('/api/v1/orders/active-progress')->json('data.0');
    expect($detail['progress'])->toMatchArray(['order_id' => $order->id, 'phase' => 'preparing', 'title' => $card['title'], 'eta_to' => $card['eta_to']]);
    expect($this->getJson('/api/v1/orders')->assertOk()->json('data.0'))->not->toHaveKey('progress');
});

it('gives an old finished order a finished card in its detail', function () {
    $order = lopMove(lopPlace($this->w), FS::Delivered, ['delivered_at' => now()], DS::Delivered, driverFor($this->w->a));
    $order->forceFill(['status' => OS::Completed->value, 'completed_at' => now()])->save();
    $this->travelTo(now()->addDays(3));

    expect($this->getJson("/api/v1/orders/{$order->id}")->json('data.progress'))->toMatchArray(['phase' => 'delivered', 'done' => '1']);
});
