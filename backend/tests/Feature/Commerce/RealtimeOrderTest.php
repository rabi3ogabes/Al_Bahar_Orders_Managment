<?php

use App\Broadcasting\BranchChannel;
use App\Broadcasting\DriverChannel;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Events\OrderUpdated;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Facades\Event;

it('puts the add-to-bag variant on product cards that have only one', function () {
    $w = commerceWorld();
    $card = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))->firstWhere('id', $w->p1->id);
    expect($card['variant_id'])->toBe($w->v1->id)->and($card['has_variants'])->toBeFalse();
});

it('pings the customer in real time on private-users when the order or its delivery moves', function () {
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $f = Order::with('fulfillments')->find($orderId)->fulfillments->first();

    Event::fake([OrderUpdated::class]);
    actingAsStaff(staffWithRole('order_manager', $w->a));
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'accepted'])->assertOk();

    Event::assertDispatched(OrderUpdated::class, fn (OrderUpdated $e) => $e->userId === $w->customer->id && $e->orderId === $orderId && $e->status === 'processing');
    $e = new OrderUpdated($w->customer->id, $orderId, 'AB1', 'processing', 'accepted');
    expect($e->broadcastAs())->toBe('order.updated')->and($e->broadcastOn()[0]->name)->toBe('private-users.'.$w->customer->id)
        ->and($e->broadcastWith())->toHaveKeys(['order_id', 'number', 'status', 'status_label', 'delivery_status']);
});

it('tells the branch and the assigned driver in real time, and only them', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f] = readyOrder($w);
    Event::fake([DispatchUpdated::class]);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();

    Event::assertDispatched(DispatchUpdated::class, function ($e) use ($w, $driver) {
        $names = collect($e->broadcastOn())->map->name->all();

        return $e->kind === 'delivery' && $e->status === 'assigned' && in_array('private-branches.'.$w->a->id, $names, true) && in_array('private-drivers.'.$driver->id, $names, true);
    });
});

it('authorises branch and driver channels only for the right people', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    $other = staffWithRole('order_manager', $w->b);
    $branch = new BranchChannel;
    $own = new DriverChannel;

    expect($branch->join($driver, $w->a->id))->toBeTrue()
        ->and($branch->join($other, $w->a->id))->toBeFalse()
        ->and($branch->join($other, $w->b->id))->toBeTrue()
        ->and($branch->join(User::factory()->superAdmin()->create(), $w->a->id))->toBeTrue()
        ->and($own->join($driver, $driver->id))->toBeTrue()
        ->and($own->join($other, $driver->id))->toBeFalse();
});
