<?php

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Models\PickupVerification;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\CashCollection;
use App\Support\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function placePickupOrder(object $w, int $qty1 = 2, int $qty2 = 1): Order
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => $qty1]);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => $qty2]);

    return Order::with('fulfillments.pickup')->find(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->json('data.order.id'));
}

it('issues an opaque single-use pickup QR and verifies it at the right branch only', function () {
    $w = commerceWorld();
    $order = placePickupOrder($w);
    $f = $order->fulfillments->first();
    $staff = staffWithRole('pickup_employee', $w->a);
    $svc = app(PickupService::class);

    $this->getJson("/api/v1/orders/{$order->id}/fulfillments/{$f->id}/pickup-pass")->assertStatus(409);
    $svc->markReady($f, $staff);
    $pass = $this->getJson("/api/v1/orders/{$order->id}/fulfillments/{$f->id}/pickup-pass")->assertOk()->json('data');
    expect($pass['qr_payload'])->toStartWith('ABPK1.')->not->toContain($order->number)->not->toContain($order->id);

    $otherBranchStaff = staffWithRole('pickup_employee', $w->b);
    expect($svc->verify($pass['qr_payload'], $otherBranchStaff, $w->b->id)['result'])->toBe('wrong_branch')
        ->and($svc->verify('ABPK1.guessed-token', $staff, $w->a->id)['result'])->toBe('invalid_token')
        ->and($svc->verify($pass['qr_payload'], $staff, $w->a->id)['result'])->toBe('success');
    expect(fn () => $svc->verify($pass['qr_payload'], $otherBranchStaff, $w->a->id))->toThrow(DomainException::class); // not assigned to A

    $r = $f->pickup->fresh();
    $svc->handover($r, staffWithRole('pickup_employee', $w->a), [], 'Noor', collection: ['tender' => 'card_terminal', 'amount' => $order->grand_total, 'receipt_reference' => 'POS-1']);
    expect($svc->verify($pass['qr_payload'], $staff, $w->a->id)['result'])->toBe('already_used')
        ->and($order->fresh()->status->value)->toBe('completed')->and($order->fresh()->payment_status->value)->toBe('paid')
        ->and(stockOf($w->a, $w->v1))->toBe(['on_hand' => 3, 'reserved' => 0])
        ->and(PickupVerification::count())->toBeGreaterThanOrEqual(5);
});

it('expires pickup passes', function () {
    $w = commerceWorld();
    $order = placePickupOrder($w);
    $staff = staffWithRole('pickup_employee', $w->a);
    app(PickupService::class)->markReady($order->fulfillments->first(), $staff);
    $pass = app(PickupService::class)->pass($order->fulfillments->first()->pickup->fresh());
    $this->travel(config('platform.pickup.token_ttl_hours') + 1)->hours();
    expect(app(PickupService::class)->verify($pass['qr_payload'], $staff, $w->a->id)['result'])->toBe('expired');
});

it('supports partial pickup while keeping the remainder reserved', function () {
    $w = commerceWorld();
    $order = placePickupOrder($w, 2, 1);
    $f = $order->fulfillments->first();
    $staff = staffWithRole('pickup_employee', $w->a);
    $svc = app(PickupService::class);
    $r = $svc->markReady($f, $staff);
    $item1 = $f->items()->where('product_variant_id', $w->v1->id)->first();
    $collection = ['tender' => 'cash', 'amount' => $order->grand_total];

    $r = $svc->handover($r, $staff, [$item1->id => 1], 'Noor', collection: $collection);
    expect($r->status)->toBe('partially_collected')->and(stockOf($w->a, $w->v1))->toBe(['on_hand' => 4, 'reserved' => 1])
        ->and($f->fresh()->status->value)->toBe('pickup_ready');

    $r = $svc->handover($r, $staff, [], 'Ahmed (brother)', alternateCollector: true);
    expect($r->status)->toBe('collected')->and($f->fresh()->status->value)->toBe('picked_up')->and(stockOf($w->a, $w->v1))->toBe(['on_hand' => 3, 'reserved' => 0]);
});

it('refuses handover before pay-at-pickup payment is collected', function () {
    $w = commerceWorld();
    $order = placePickupOrder($w);
    $staff = staffWithRole('pickup_employee', $w->a);
    $r = app(PickupService::class)->markReady($order->fulfillments->first(), $staff);
    expect(fn () => app(PickupService::class)->handover($r, $staff))->toThrow(DomainException::class);
});

function deliveryOrder(object $w): Order
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);

    return Order::with('fulfillments')->find(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id'));
}

it('runs internal delivery with eligible driver, proof of delivery and COD collection', function () {
    Storage::fake('local');
    $w = commerceWorld();
    $order = deliveryOrder($w);
    $f = $order->fulfillments->first();
    $mgr = staffWithRole('order_manager', $w->a);
    $driver = staffWithRole('delivery_employee', $w->a);
    BranchEmployee::where('user_id', $driver->id)->update(['is_delivery_driver' => true]);
    $notDriver = staffWithRole('sales_employee', $w->a);
    $svc = app(DeliveryService::class);

    $d = $svc->create($f, $mgr);
    expect(fn () => $svc->assignDriver($d, $notDriver, $mgr))->toThrow(DomainException::class);
    $d = $svc->assignDriver($d, $driver, $mgr);

    // Another driver cannot act on someone else's delivery.
    $otherDriver = staffWithRole('delivery_employee', $w->a);
    expect(fn () => $svc->pickUp($d, $otherDriver))->toThrow(DomainException::class);

    $d = $svc->pickUp($d, $driver);
    expect($f->fresh()->status->value)->toBe('dispatched')->and(stockOf($w->a, $w->v1)['on_hand'])->toBe(4);
    $d = $svc->outForDelivery($d, $driver, 25.3, 51.5);
    expect(fn () => $svc->deliver($d, $driver, 'Noor'))->toThrow(DomainException::class); // proof required
    expect(fn () => $svc->deliver($d, $driver, 'Noor', UploadedFile::fake()->image('door.jpg')))->toThrow(DomainException::class); // COD required
    $d = $svc->deliver($d, $driver, 'Noor', UploadedFile::fake()->image('door.jpg'), 'photo', ['tender' => 'cash', 'amount' => $order->grand_total]);

    expect($d->status->value)->toBe('delivered')->and($order->fresh()->status->value)->toBe('completed')
        ->and($order->fresh()->payment_status->value)->toBe('paid')
        ->and(CashCollection::where('status', 'collected')->value('collected_by'))->toBe($driver->id)
        ->and($d->proofs()->count())->toBe(1);
});

it('records failed delivery attempts, reschedules and returns stock to the branch', function () {
    $w = commerceWorld();
    $order = deliveryOrder($w);
    $mgr = staffWithRole('order_manager', $w->a);
    $svc = app(DeliveryService::class);
    $d = $svc->pickUp($svc->create($order->fulfillments->first(), $mgr), $mgr);
    $d = $svc->fail($d, $mgr, 'customer_unreachable', 'No answer x3');
    expect($d->attempt_count)->toBe(1)->and($order->fulfillments->first()->fresh()->status->value)->toBe('failed')
        ->and(CashCollection::where('status', 'failed')->exists())->toBeTrue();
    $d = $svc->reschedule($d, $mgr, now()->addDay()->toIso8601String());
    $d = $svc->returnToBranch($d, $mgr);
    expect($d->status->value)->toBe('returned')->and(stockOf($w->a, $w->v1)['on_hand'])->toBe(5) // re-added
        ->and($order->fresh()->status->value)->toBe('returned');
});

it('handles provider timeouts and authenticated, idempotent provider webhooks', function () {
    $w = commerceWorld();
    $order = deliveryOrder($w);
    $mgr = staffWithRole('order_manager', $w->a);
    $provider = DeliveryProvider::create(['code' => 'mockx', 'name' => 'Mock Express', 'driver' => 'mock', 'is_active' => true, 'webhook_secret' => 'whsec', 'settings' => ['timeout' => true]]);
    $svc = app(DeliveryService::class);

    $d = $svc->create($order->fulfillments->first(), $mgr, 'provider', $provider->id);
    expect($d->status->value)->toBe('pending')->and($d->last_error)->toContain('timeout');
    $provider->update(['settings' => []]);
    $d = $svc->book($d);
    expect($d->provider_reference)->toStartWith('MOCK-')->and($d->status->value)->toBe('accepted');

    $send = function (array $body, string $secret = 'whsec') {
        $json = json_encode($body);

        return test()->call('POST', '/webhooks/delivery/mockx', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MOCK_SIGNATURE' => hash_hmac('sha256', $json, $secret)], $json);
    };
    $send(['event_id' => 'e1', 'reference' => $d->provider_reference, 'status' => 'picked_up'])->assertOk()->assertJsonPath('status', 'processed');
    $send(['event_id' => 'e1', 'reference' => $d->provider_reference, 'status' => 'picked_up'])->assertOk()->assertJsonPath('status', 'duplicate');
    $send(['event_id' => 'e2', 'reference' => $d->provider_reference, 'status' => 'delivered'], 'wrong')->assertStatus(400);
    expect($order->fulfillments->first()->fresh()->status->value)->toBe('dispatched');
});

it('lets drivers list only their own active tasks', function () {
    $w = commerceWorld();
    $order = deliveryOrder($w);
    $mgr = staffWithRole('order_manager', $w->a);
    $driver = staffWithRole('delivery_employee', $w->a);
    BranchEmployee::where('user_id', $driver->id)->update(['is_delivery_driver' => true]);
    app(DeliveryService::class)->assignDriver(app(DeliveryService::class)->create($order->fulfillments->first(), $mgr), $driver, $mgr);

    actingAsStaff($driver);
    $this->getJson('/api/v1/admin/me/deliveries')->assertOk()->assertJsonCount(1, 'data');
    actingAsStaff(staffWithRole('delivery_employee', $w->a));
    $this->getJson('/api/v1/admin/me/deliveries')->assertOk()->assertJsonCount(0, 'data');
});
