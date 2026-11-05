<?php

use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliverySlotBooking;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use App\Support\BusinessTime;
use App\Support\DomainException;

/** A delivery slot at the given branch, tomorrow's weekday, 16:00–18:00 (inside default opening hours on every day). */
function deliverySlot(object $w, int $capacity = 2, string $branch = 'a', string $from = '16:00', string $to = '18:00'): array
{
    $date = BusinessTime::now()->addDay();
    $slot = DeliverySlot::create(['branch_id' => $w->$branch->id, 'fulfillment_type' => 'delivery', 'day_of_week' => $date->dayOfWeek,
        'starts_at' => $from, 'ends_at' => $to, 'capacity' => $capacity, 'cutoff_minutes' => 60]);

    return [$slot, $date->toDateString()];
}

function addToCart(object $w): void
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertCreated();
}

function deliveryOptions(object $w, array $extra = []): array
{
    return ['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => false] + $extra;
}

it('lists delivery slots with live availability', function () {
    $w = commerceWorld();
    [$slot, $date] = deliverySlot($w, 2);

    $rows = $this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date={$date}")->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['id' => $slot->id, 'starts_at' => '16:00', 'ends_at' => '18:00', 'capacity' => 2, 'available' => 2, 'is_full' => false]);

    $this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots")->assertStatus(422);
    $this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date=2020-01-01")->assertStatus(422);

    $w->a->update(['delivery_enabled' => false]);
    $this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date={$date}")->assertNotFound();
});

it('requires a delivery slot when the branch publishes slots, and accepts none otherwise', function () {
    $w = commerceWorld();
    addToCart($w);

    // No slots configured → delivery orders keep working without one.
    $q = $this->postJson('/api/v1/checkout/quote', deliveryOptions($w))->assertOk()->json('data');
    expect($q['can_place_order'])->toBeTrue()->and($q['fulfillments'][0]['slot_required'])->toBeFalse();

    [$slot, $date] = deliverySlot($w);
    $q = $this->postJson('/api/v1/checkout/quote', deliveryOptions($w))->assertOk()->json('data');
    expect($q['can_place_order'])->toBeFalse()->and($q['fulfillments'][0]['slot_required'])->toBeTrue()
        ->and(collect($q['errors'])->pluck('code'))->toContain('slot_required');

    // A slot id without a date is just as incomplete.
    $q = $this->postJson('/api/v1/checkout/quote', deliveryOptions($w, ['delivery_slot_id' => $slot->id]))->json('data');
    expect(collect($q['errors'])->pluck('code'))->toContain('slot_required');

    $q = $this->postJson('/api/v1/checkout/quote', deliveryOptions($w, ['delivery_slot_id' => $slot->id, 'delivery_date' => $date]))->assertOk()->json('data');
    expect($q['can_place_order'])->toBeTrue()
        ->and($q['fulfillments'][0]['slot'])->toMatchArray(['id' => $slot->id, 'date' => $date, 'starts_at' => '16:00', 'ends_at' => '18:00']);
});

it('rejects a slot of another branch, a wrong weekday and a pickup slot', function () {
    $w = commerceWorld();
    addToCart($w);
    [$otherBranchSlot, $date] = deliverySlot($w, 2, 'b');
    [$slot] = deliverySlot($w);
    $wrongDay = BusinessTime::now()->addDays(2)->toDateString();
    $pickup = DeliverySlot::create(['branch_id' => $w->a->id, 'fulfillment_type' => 'pickup', 'day_of_week' => BusinessTime::now()->addDay()->dayOfWeek,
        'starts_at' => '12:00', 'ends_at' => '13:00', 'capacity' => 5, 'cutoff_minutes' => 60]);

    foreach ([[$otherBranchSlot->id, $date], [$slot->id, $wrongDay], [$pickup->id, $date]] as [$id, $d]) {
        $q = $this->postJson('/api/v1/checkout/quote', deliveryOptions($w, ['delivery_slot_id' => $id, 'delivery_date' => $d]))->json('data');
        expect($q['can_place_order'])->toBeFalse()->and(collect($q['errors'])->pluck('code')->all())->toContain('slot_unavailable');
    }
});

it('books slot capacity when the order is placed and releases it on cancellation', function () {
    $w = commerceWorld();
    [$slot, $date] = deliverySlot($w, 1);
    $opts = deliveryOptions($w, ['delivery_slot_id' => $slot->id, 'delivery_date' => $date]);

    addToCart($w);
    $orderId = quoteAndPlace($opts)->assertCreated()->json('data.order.id');
    $booked = fn () => (int) DeliverySlotBooking::where('delivery_slot_id', $slot->id)->whereDate('slot_date', $date)->value('booked');
    expect($booked())->toBe(1)
        ->and($this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date={$date}")->json('data.0.is_full'))->toBeTrue()
        ->and($this->getJson("/api/v1/orders/{$orderId}")->json('data.fulfillments.0.slot'))->toMatchArray(['date' => $date, 'starts_at' => '16:00', 'ends_at' => '18:00']);

    // The slot is full now: a second customer cannot take it.
    addToCart($w);
    $q = $this->postJson('/api/v1/checkout/quote', $opts)->json('data');
    expect($q['can_place_order'])->toBeFalse()->and(collect($q['errors'])->pluck('code')->all())->toContain('slot_unavailable');

    app(OrderService::class)->cancelOrder(Order::findOrFail($orderId), staffWithRole('order_manager', $w->a), 'customer changed their mind');
    expect($booked())->toBe(0);
});

it('defaults a new delivery\'s schedule to the customer\'s slot', function () {
    $w = commerceWorld();
    [$slot, $date] = deliverySlot($w);
    addToCart($w);
    $order = Order::with('fulfillments')->find(quoteAndPlace(deliveryOptions($w, ['delivery_slot_id' => $slot->id, 'delivery_date' => $date]))->assertCreated()->json('data.order.id'));

    $d = app(DeliveryService::class)->create($order->fulfillments->first(), staffWithRole('order_manager', $w->a));
    expect(BusinessTime::local($d->scheduled_for)->format('Y-m-d H:i'))->toBe("{$date} 16:00");
});

it('reschedules a failed delivery onto another slot, moving the booking atomically', function () {
    $w = commerceWorld();
    [$slot, $date] = deliverySlot($w, 1);
    [$next] = deliverySlot($w, 1, 'a', '19:00', '21:00');
    addToCart($w);
    $order = Order::with('fulfillments')->find(quoteAndPlace(deliveryOptions($w, ['delivery_slot_id' => $slot->id, 'delivery_date' => $date]))->assertCreated()->json('data.order.id'));
    $mgr = staffWithRole('order_manager', $w->a);
    $svc = app(DeliveryService::class);
    $d = $svc->fail($svc->pickUp($svc->create($order->fulfillments->first(), $mgr), $mgr), $mgr, 'customer_absent');
    $booked = fn ($s) => (int) DeliverySlotBooking::where('delivery_slot_id', $s->id)->whereDate('slot_date', $date)->value('booked');

    // Another customer fills the later slot first → the move is refused and nothing changes.
    addToCart($w);
    quoteAndPlace(deliveryOptions($w, ['delivery_slot_id' => $next->id, 'delivery_date' => $date]))->assertCreated();
    expect(fn () => $svc->rescheduleToSlot($d, $mgr, $next->id, $date))->toThrow(DomainException::class)
        ->and($booked($slot))->toBe(1)->and($booked($next))->toBe(1);

    // Free capacity at the later slot by moving to a different weekday-compatible date is not possible here; use an unbooked slot instead.
    [$free] = deliverySlot($w, 3, 'a', '21:00', '22:00');
    $d = $svc->rescheduleToSlot($d, $mgr, $free->id, $date);
    expect($d->status->value)->toBe('rescheduled')->and($booked($slot))->toBe(0)->and($booked($free))->toBe(1)
        ->and(BusinessTime::local($d->scheduled_for)->format('H:i'))->toBe('21:00')
        ->and($order->fulfillments->first()->fresh()->delivery_slot_id)->toBe($free->id);

    // Only staff with assign rights on the delivery's branch may reschedule.
    expect(fn () => $svc->rescheduleToSlot($d, staffWithRole('order_manager', $w->b), $free->id, $date))->toThrow(DomainException::class);
});
