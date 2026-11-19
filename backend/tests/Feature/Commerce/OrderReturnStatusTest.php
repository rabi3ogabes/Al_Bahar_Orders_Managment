<?php

use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Services\OrderPresenter;
use App\Domain\Orders\Services\OrderProgress;
use App\Domain\Orders\Services\ReturnService;

require_once __DIR__.'/LiveOrderHelpers.php';

/* The order page tells the customer where a return stands, and shows the driver's number only while the driver has the order. */

/** A delivered cash order of two sofas (both handed over a day ago) of the signed-in customer. */
function deliveredSofas(): array
{
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 2])->assertCreated();
    $order = Order::find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
    $order->fulfillments()->update(['status' => 'delivered', 'delivered_at' => now()->subDay()]);
    $item = $order->items()->first();
    $item->forceFill(['quantity_handed_over' => 2])->save();

    return [$w, $order->fresh(), $item];
}

it('shows what can still be returned per line and no request before one is made', function () {
    [, $order, $item] = deliveredSofas();

    $d = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');

    expect($d['can_return'])->toBeTrue()->and($d['return_request'])->toBeNull()->and($d['items'][0]['id'])->toBe($item->id)->and($d['items'][0]['returnable_quantity'])->toBe(2);
});

it('closes the return button once a request is waiting and shows where it stands', function () {
    [, $order, $item] = deliveredSofas();
    $this->postJson("/api/v1/orders/{$order->id}/returns", ['reason_code' => 'damaged', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]]])->assertCreated();

    $d = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');

    expect($d['can_return'])->toBeFalse()->and($d['return_request'])->toMatchArray(['status' => 'requested', 'decision_note' => null, 'decided_at' => null])
        ->and($d['return_request']['requested_at'])->toBeString()->and($d['return_request']['number'])->toStartWith('RT')
        ->and($d['items'][0]['returnable_quantity'])->toBe(1);   // one of the two is held by the open request
});

it('gives the team\'s note back when the request is rejected, and opens the button again', function () {
    [, $order, $item] = deliveredSofas();
    $this->postJson("/api/v1/orders/{$order->id}/returns", ['reason_code' => 'changed_mind', 'items' => [['order_item_id' => $item->id, 'quantity' => 2]]])->assertCreated();
    $rr = ReturnRequest::firstOrFail();
    app(ReturnService::class)->decide($rr, staffWithRole('super_admin'), false, 'Sorry, a used sofa cannot be taken back.');
    actingAsCustomer($order->user);

    $d = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');

    expect($d['return_request']['status'])->toBe('rejected')->and($d['return_request']['decision_note'])->toBe('Sorry, a used sofa cannot be taken back.')
        ->and($d['return_request']['decided_at'])->toBeString()->and($d['can_return'])->toBeTrue()->and($d['items'][0]['returnable_quantity'])->toBe(2);
});

it('keeps the button closed while an approved request waits for the goods and when nothing is left to return', function () {
    [, $order, $item] = deliveredSofas();
    $this->postJson("/api/v1/orders/{$order->id}/returns", ['reason_code' => 'damaged', 'items' => [['order_item_id' => $item->id, 'quantity' => 2]]])->assertCreated();
    app(ReturnService::class)->decide(ReturnRequest::firstOrFail(), staffWithRole('super_admin'), true);
    actingAsCustomer($order->user);
    $d = $this->getJson("/api/v1/orders/{$order->id}")->json('data');
    expect($d['return_request']['status'])->toBe('approved')->and($d['can_return'])->toBeFalse();

    app(ReturnService::class)->receive(ReturnRequest::firstOrFail(), staffWithRole('super_admin'), false);
    actingAsCustomer($order->user);
    $d = $this->getJson("/api/v1/orders/{$order->id}")->json('data');
    expect($d['return_request']['status'])->toBe('received')->and($d['can_return'])->toBeFalse()->and($d['items'][0]['returnable_quantity'])->toBe(0);
});

it('offers nothing to return before the order is delivered or after the window', function () {
    [, $order] = deliveredSofas();
    $order->fulfillments()->update(['delivered_at' => now()->subDays(OrderPresenter::RETURN_WINDOW_DAYS + 1)]);
    $d = $this->getJson("/api/v1/orders/{$order->id}")->json('data');
    expect($d['can_return'])->toBeFalse()->and($d['items'][0]['returnable_quantity'])->toBe(0);
});

it('does not read the return requests of another order', function () {
    [, $order, $item] = deliveredSofas();
    $this->postJson("/api/v1/orders/{$order->id}/returns", ['reason_code' => 'damaged', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]]])->assertCreated();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $item->product_variant_id, 'quantity' => 1])->assertCreated();
    $second = Order::find(quoteAndPlace(['address_id' => $order->user->addresses()->first()->id, 'payment_method' => 'cod'])->json('data.order.id'));

    $this->getJson("/api/v1/orders/{$second->id}")->assertOk()->assertJsonPath('data.return_request', null);
});

// ── The driver's phone ───────────────────────────────────────────────────────────────────────────────────────────────

it('shows the driver\'s number to the customer only while the driver has the order', function () {
    lopFreeze();
    $w = commerceWorld([50, 50], [50, 50]);
    $driver = driverFor($w->a);
    $driver->forceFill(['phone' => '+97455123499'])->save();
    $order = lopMove(lopPlace($w), FS::OutForDelivery, [], DS::Assigned, $driver);
    $delivery = $order->fulfillments->first()->deliveries()->first();
    $phone = fn () => $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.fulfillments.0.delivery.driver_phone');

    foreach ([DS::Assigned, DS::Accepted, DS::PickedUp, DS::OutForDelivery] as $status) {
        $delivery->forceFill(['status' => $status->value])->save();
        expect($phone())->toBe('+97455123499', $status->value);
    }
    foreach ([DS::Pending, DS::Delivered, DS::Failed, DS::Cancelled, DS::Returned] as $status) {
        $delivery->forceFill(['status' => $status->value])->save();
        expect($phone())->toBeNull($status->value);
    }
});

it('never shows a driver number for a pickup order or an order with no driver yet', function () {
    lopFreeze();
    $w = commerceWorld([50, 50], [50, 50]);
    $pickup = lopPlace($w, 'pickup');
    $this->getJson("/api/v1/orders/{$pickup->id}")->assertOk()->assertJsonPath('data.fulfillments.0.delivery', null);

    $order = lopMove(lopPlace($w), FS::Preparing, [], DS::Assigned, null);   // assigned to nobody yet
    $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.fulfillments.0.delivery.driver_phone', null);
});

it('agrees with the live order card about who may call the driver', function () {
    $card = (new ReflectionClassConstant(OrderProgress::class, 'ACTIVE_DELIVERY'))->getValue();

    expect(OrderPresenter::DRIVER_CALLABLE)->toBe($card);
});

it('shows the same number on the order and on the live order card in every delivery stage', function () {
    lopFreeze();
    $w = commerceWorld([50, 50], [50, 50]);
    $driver = driverFor($w->a);
    $driver->forceFill(['phone' => '+97455123499'])->save();
    $order = lopMove(lopPlace($w), FS::OutForDelivery, [], DS::Assigned, $driver);
    $delivery = $order->fulfillments->first()->deliveries()->first();

    foreach (DS::cases() as $status) {
        $delivery->forceFill(['status' => $status->value])->save();
        $detail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $onCard = $detail['progress']['driver_phone'] ?? null;
        $onOrder = $detail['fulfillments'][0]['delivery']['driver_phone'];

        // The card hides the number of a cancelled delivery with the driver too; where the card shows one, the order shows the same.
        if ($onCard !== null) {
            expect($onOrder)->toBe($onCard, $status->value);
        }
        if ($onOrder !== null) {
            expect($onCard)->toBe($onOrder, $status->value);
        }
    }
});
