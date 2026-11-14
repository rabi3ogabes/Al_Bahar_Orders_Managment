<?php

use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliverySlotBooking;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

it('lists slots for a span of dates in one call, identical to asking day by day', function () {
    $w = commerceWorld();
    [$slot, $date] = deliverySlot($w, 2);                                   // tomorrow's weekday, 16:00–18:00
    deliverySlot($w, 5, 'a', '19:00', '21:00');                              // same weekday, second window
    $other = BusinessTime::now()->addDays(3);
    $slot2 = DeliverySlot::create(['branch_id' => $w->a->id, 'fulfillment_type' => 'delivery', 'day_of_week' => $other->dayOfWeek,
        'starts_at' => '15:00', 'ends_at' => '17:00', 'capacity' => 4, 'cutoff_minutes' => 60]);
    $from = BusinessTime::now()->addDay()->toDateString();
    $to = BusinessTime::now()->addDays(14)->toDateString();

    $res = $this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?from={$from}&to={$to}")->assertOk()->assertJsonPath('meta.from', $from)->assertJsonPath('meta.to', $to);
    $days = collect($res->json('data'));

    // Only dates with slots appear: tomorrow's weekday twice (days 1 and 8) plus the other weekday's dates; none outside the span.
    expect($days->pluck('date')->every(fn ($d) => $d >= $from && $d <= $to))->toBeTrue()
        ->and($days->pluck('date')->unique()->count())->toBe($days->count())
        ->and($days->firstWhere('date', $date)['slots'])->toHaveCount(2)
        ->and($days->firstWhere('date', $date)['available'])->toBe(7)        // 2 + 5
        ->and($days->firstWhere('date', $other->toDateString())['slots'][0]['id'])->toBe($slot2->id);

    // Each day equals the single-date answer.
    foreach ($days as $day) {
        expect($this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date={$day['date']}")->json('data'))->toBe($day['slots']);
    }
});

it('can hide dates that are fully booked', function () {
    $w = commerceWorld();
    [$slot, $date] = deliverySlot($w, 1);
    $from = $date;
    $to = BusinessTime::now()->addDays(10)->toDateString();
    addToCart($w);
    quoteAndPlace(deliveryOptions($w, ['delivery_slot_id' => $slot->id, 'delivery_date' => $date]))->assertCreated();

    $all = collect($this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?from={$from}&to={$to}")->json('data'));
    $free = collect($this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?from={$from}&to={$to}&only_available=1")->json('data'));

    expect($all->firstWhere('date', $date)['available'])->toBe(0)->and($all->firstWhere('date', $date)['slots'][0]['is_full'])->toBeTrue()
        ->and($free->pluck('date')->contains($date))->toBeFalse()
        ->and($free->count())->toBe($all->count() - 1)   // the same weekday a week later is still free
        ->and($free->every(fn ($d) => $d['available'] > 0))->toBeTrue();
});

it('answers a span with a fixed number of queries and no data for branches without slots', function () {
    $w = commerceWorld();
    deliverySlot($w, 3);
    $branch = $w->a->fresh()->load(['hours', 'holidays']);
    $svc = app(SlotService::class);
    $count = function (string $to) use ($svc, $branch) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $svc->availableRange($branch, 'delivery', BusinessTime::now()->addDay()->toDateString(), $to);

        return count(DB::getQueryLog());
    };

    expect($count(BusinessTime::now()->addDays(7)->toDateString()))->toBe($count(BusinessTime::now()->addDays(28)->toDateString()))
        ->and($count(BusinessTime::now()->addDays(28)->toDateString()))->toBeLessThanOrEqual(3);

    $empty = $w->b->fresh()->load(['hours', 'holidays']);
    expect($svc->availableRange($empty, 'delivery', BusinessTime::now()->addDay()->toDateString(), BusinessTime::now()->addDays(5)->toDateString()))->toBe([]);
});

it('validates the date, the span and the mode, for delivery and pickup alike', function () {
    $w = commerceWorld();
    $url = fn (string $q, string $kind = 'delivery') => "/api/v1/branches/{$w->a->id}/{$kind}-slots?{$q}";
    $d = fn (int $n) => BusinessTime::now()->addDays($n)->toDateString();

    foreach (['delivery', 'pickup'] as $kind) {
        $this->getJson($url('', $kind))->assertStatus(422);                                           // nothing given
        $this->getJson($url('from='.$d(1), $kind))->assertStatus(422);                                // from without to
        $this->getJson($url('to='.$d(3), $kind))->assertStatus(422);                                  // to without from
        $this->getJson($url('from='.$d(3).'&to='.$d(1), $kind))->assertStatus(422);                   // backwards
        $this->getJson($url('from='.$d(1).'&to='.$d(40), $kind))->assertStatus(422);                  // too far
        $this->getJson($url('from='.BusinessTime::now()->subDay()->toDateString().'&to='.$d(2), $kind))->assertStatus(422); // starts in the past
        $this->getJson($url('date='.$d(1).'&from='.$d(1).'&to='.$d(2), $kind))->assertStatus(422);    // one mode only
        $this->getJson($url('from='.$d(0).'&to='.$d(29), $kind))->assertOk();                         // widest allowed span
    }
    $this->getJson($url('date='.$d(1)))->assertOk();                                                  // the original form still works
});

it('supports spans for pickup slots too', function () {
    $w = commerceWorld();
    $day = BusinessTime::now()->addDay();
    DeliverySlot::create(['branch_id' => $w->a->id, 'fulfillment_type' => 'pickup', 'day_of_week' => $day->dayOfWeek, 'starts_at' => '16:00', 'ends_at' => '17:00', 'capacity' => 5, 'cutoff_minutes' => 60]);

    $rows = $this->getJson("/api/v1/branches/{$w->a->id}/pickup-slots?from={$day->toDateString()}&to={$day->addDays(6)->toDateString()}")->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['date'])->toBe($day->toDateString())->and($rows[0]['slots'][0]['available'])->toBe(5);
});

it('says until when each slot can be booked, so a screen left open knows by itself when a time has gone', function () {
    $w = commerceWorld();
    [$slot, $date] = deliverySlot($w, 2); // 16:00–18:00, cutoff 60 minutes

    $row = $this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date={$date}")->assertOk()->json('data.0');
    expect($row['bookable_until'])->toBe("{$date}T15:00:00+03:00");

    $span = $this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?from={$date}&to={$date}")->assertOk()->json('data.0.slots.0');
    expect($span['bookable_until'])->toBe("{$date}T15:00:00+03:00");
});

it('counts the day in Qatar time: just after midnight there, today is already the new date', function () {
    $w = commerceWorld();
    // 00:10 on 15 Nov in Doha is still 14 Nov 21:10 UTC.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 00:10:00', 'Asia/Qatar'));
    try {
        $slot = DeliverySlot::create(['branch_id' => $w->a->id, 'fulfillment_type' => 'delivery', 'day_of_week' => 0 /* Sunday = 15 Nov 2026 */,
            'starts_at' => '16:00', 'ends_at' => '18:00', 'capacity' => 2, 'cutoff_minutes' => 60]);
        $url = "/api/v1/branches/{$w->a->id}/delivery-slots";

        $this->getJson("$url?date=2026-11-15")->assertOk()->assertJsonPath('data.0.available', 2);
        $this->getJson("$url?date=2026-11-14")->assertStatus(422); // yesterday in Doha, although it is today in UTC
        $this->getJson("$url?from=2026-11-15&to=2026-11-15&only_available=1")->assertOk()->assertJsonPath('data.0.slots.0.id', $slot->id);

        // The cutoff is 15:00: one minute before it the slot can still be booked, one minute after it cannot.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 14:59:00', 'Asia/Qatar'));
        $this->getJson("$url?date=2026-11-15")->assertJsonPath('data.0.available', 2)->assertJsonPath('data.0.is_full', false);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 15:01:00', 'Asia/Qatar'));
        $this->getJson("$url?date=2026-11-15")->assertJsonPath('data.0.available', 0)->assertJsonPath('data.0.is_full', true)
            ->assertJsonPath('data.0.bookable_until', '2026-11-15T15:00:00+03:00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
