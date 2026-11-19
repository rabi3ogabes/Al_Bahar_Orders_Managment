<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\DeliveryCustomerAlerts;
use App\Domain\Fulfillment\Services\Eta\EtaService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** "I have arrived" and "your driver is close": the two messages a customer gets at the door, each once, never for a pickup order. */
const ARRIVED_URL = '/api/v1/admin/deliveries/%s/arrived';

function arrivalMessages(object $x, string $event): Collection
{
    return Notification::where('user_id', $x->customer->id)->where('event', $event)->get();
}

/** The driver's phone reports a position this many kilometres north of the customer's door (the door is at 25.30, 51.50). */
function driverIsKmAway(float $km): void
{
    test()->postJson('/api/v1/admin/dispatch/driver/location', ['latitude' => 25.30 + $km / 111.0, 'longitude' => 51.50])->assertOk();
}

// ── Arrived ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('records the arrival, tells the customer once and writes the history and the audit', function () {
    $x = deliveryAt('out_for_delivery');
    $this->travelTo(now()->addMinutes(12));

    $r = $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id), ['latitude' => 25.3001, 'longitude' => 51.5001, 'accuracy' => 12])->assertOk();

    expect($r->json('data.status'))->toBe('out_for_delivery')->and($r->json('data.arrived_at'))->not->toBeNull()
        ->and($x->delivery->fresh()->arrived_at)->not->toBeNull();
    $msg = arrivalMessages($x, 'delivery.arrived');
    expect($msg)->toHaveCount(1)->and($msg->first()->body)->toContain($x->orderNumber ?? Order::find($x->orderId)->number)->toContain(explode(' ', $x->driver->name)[0])
        ->and($msg->first()->body)->not->toContain($x->driver->phone ?? '+974')
        ->and($msg->first()->title)->toBe('Your driver has arrived');
    $history = DB::table('delivery_status_history')->where('delivery_id', $x->delivery->id)->orderByDesc('id')->first();
    expect($history->note)->toBe('arrived at the customer')->and($history->from_status)->toBe('out_for_delivery')->and($history->to_status)->toBe('out_for_delivery')
        ->and((float) $history->latitude)->toBe(25.3001)->and($history->source)->toBe('driver');
    expect(AuditLog::where('action', 'delivery.arrived')->where('auditable_id', $x->delivery->id)->count())->toBe(1);
});

it('does nothing the second time: same answer, one message, one audit row', function () {
    $x = deliveryAt('out_for_delivery');

    $first = $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertOk()->json('data.arrived_at');
    $this->travelTo(now()->addMinutes(3));
    $second = $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertOk()->json('data.arrived_at');

    expect($second)->toBe($first)->and(arrivalMessages($x, 'delivery.arrived'))->toHaveCount(1)
        ->and(AuditLog::where('action', 'delivery.arrived')->count())->toBe(1);
});

it('is allowed only while the order is out for delivery', function (string $status) {
    $x = deliveryAt($status);

    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertStatus(409)->assertJsonPath('code', 'invalid_state_transition');

    expect($x->delivery->fresh()->arrived_at)->toBeNull()->and(arrivalMessages($x, 'delivery.arrived'))->toHaveCount(0);
})->with(['assigned', 'accepted', 'picked_up']);

it('is the driver\'s own step: not another driver of the branch, not even a dispatcher', function () {
    $x = deliveryAt('out_for_delivery');

    actingAsStaff(driverFor($x->w->a));
    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertForbidden()->assertJsonPath('code', 'delivery_not_yours');

    actingAsStaff($x->mgr);
    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertForbidden();

    expect($x->delivery->fresh()->arrived_at)->toBeNull();
});

it('is refused on a delivery whose order was cancelled', function () {
    $x = deliveryAt('accepted');
    Delivery::whereKey($x->delivery->id)->update(['status' => 'cancelled']);

    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertStatus(409)->assertJsonPath('code', 'delivery_cancelled');
});

it('lets the driver finish even though the arrival message could not be sent', function () {
    $x = deliveryAt('out_for_delivery');
    $this->mock(DeliveryCustomerAlerts::class, fn ($m) => $m->shouldReceive('arrived')->andThrow(new RuntimeException('push down')));

    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertOk()->assertJsonPath('data.status', 'out_for_delivery');

    expect($x->delivery->fresh()->arrived_at)->not->toBeNull();
});

it('sends nothing when the admin switched the arrival message off, and still records the arrival', function () {
    $x = deliveryAt('out_for_delivery');
    app(NotificationRules::class)->set('delivery.arrived', false, [], $x->mgr);

    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertOk();

    expect(arrivalMessages($x, 'delivery.arrived'))->toHaveCount(0)->and($x->delivery->fresh()->arrived_at)->not->toBeNull();
});

it('speaks to the customer in their language', function () {
    $x = deliveryAt('out_for_delivery');
    $x->customer->update(['locale' => 'ar']);

    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertOk();

    expect(arrivalMessages($x, 'delivery.arrived')->first()->title)->toBe('وصل السائق');
});

it('never announces a pickup order, a courier delivery or a delivery that is no longer on the road', function () {
    $x = deliveryAt('out_for_delivery');
    $alerts = app(DeliveryCustomerAlerts::class);

    DB::table('order_fulfillments')->where('id', $x->delivery->order_fulfillment_id)->update(['type' => 'pickup']);
    expect($alerts->arrived($x->delivery->id))->toBeFalse();
    DB::table('order_fulfillments')->where('id', $x->delivery->order_fulfillment_id)->update(['type' => 'delivery']);

    Delivery::whereKey($x->delivery->id)->update(['mode' => 'provider']);
    expect($alerts->nearby($x->delivery->id))->toBeFalse();
    Delivery::whereKey($x->delivery->id)->update(['mode' => 'internal', 'status' => 'delivered']);
    expect($alerts->nearby($x->delivery->id))->toBeFalse()->and(Notification::whereIn('event', ['delivery.arrived', 'delivery.nearby'])->count())->toBe(0);
});

// ── Nearby ────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('tells the customer once, when the driver is ten minutes away or less', function () {
    $x = deliveryAt('out_for_delivery');

    driverIsKmAway(2.5);

    $msg = arrivalMessages($x, 'delivery.nearby');
    expect($msg)->toHaveCount(1)->and($msg->first()->title)->toBe('Your driver is close')->and($msg->first()->body)->toContain(Order::find($x->orderId)->number)
        ->and($x->delivery->fresh()->nearby_at)->not->toBeNull();

    $this->travelTo(now()->addMinute());
    driverIsKmAway(1.0);
    $this->travelTo(now()->addMinute());
    driverIsKmAway(0.2);
    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(1);
});

it('stays quiet while the driver is still more than ten minutes away', function () {
    $x = deliveryAt('out_for_delivery');

    driverIsKmAway(6.0); // about 14 minutes at city speed

    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(0)->and($x->delivery->fresh()->nearby_at)->toBeNull();
});

it('does not ask a routing server about a driver who is far away', function () {
    $x = deliveryAt('out_for_delivery');
    $this->mock(EtaService::class, fn ($m) => $m->shouldNotReceive('between'));

    driverIsKmAway(40.0);

    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(0);
});

it('asks for the arrival time at most once in a while, however often the phone reports', function () {
    $x = deliveryAt('out_for_delivery');
    $this->mock(EtaService::class, fn ($m) => $m->shouldReceive('between')->once()->andReturn(['minutes' => 25, 'distance_m' => 9000, 'updated_at' => now()->toIso8601String(), 'source' => 'osrm']));

    foreach ([8.0, 7.9, 7.8, 7.7] as $km) {
        driverIsKmAway($km);
        $this->travelTo(now()->addSeconds(4));
    }

    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(0);
});

it('falls back to the straight-line distance when no arrival time can be worked out', function () {
    $x = deliveryAt('out_for_delivery');
    $this->mock(EtaService::class, fn ($m) => $m->shouldReceive('between')->andThrow(new RuntimeException('routing down')));

    driverIsKmAway(2.2); // too far for 1.5 km
    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(0);

    $this->travelTo(now()->addMinute());
    driverIsKmAway(1.2);
    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(1);
});

it('follows the limits in the settings', function () {
    $x = deliveryAt('out_for_delivery');
    app(Settings::class)->set('dispatch.nearby_eta_min', 20);

    driverIsKmAway(6.0);

    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(1);
});

it('has nothing to measure against when the address has no pin on the map', function () {
    $x = deliveryAt('out_for_delivery');
    $order = Order::findOrFail($x->orderId);
    $order->forceFill(['address_snapshot' => array_merge((array) $order->address_snapshot, ['latitude' => null, 'longitude' => null])])->save();

    driverIsKmAway(0.1);

    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(0);
});

it('does not say "close" after the driver already said "arrived"', function () {
    $x = deliveryAt('out_for_delivery');
    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertOk();

    driverIsKmAway(0.1);

    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(0);
});

it('does not say "close" before the driver is out for delivery', function () {
    $x = deliveryAt('picked_up');

    driverIsKmAway(0.1);

    expect(arrivalMessages($x, 'delivery.nearby'))->toHaveCount(0)->and($x->delivery->fresh()->nearby_at)->toBeNull();
});

it('never lets a failed customer message get in the way of the driver\'s position being saved', function () {
    $x = deliveryAt('out_for_delivery');
    $this->mock(DeliveryCustomerAlerts::class, fn ($m) => $m->shouldReceive('nearby')->andThrow(new RuntimeException('push down')));

    $this->postJson('/api/v1/admin/dispatch/driver/location', ['latitude' => 25.31, 'longitude' => 51.5])->assertOk()->assertJsonPath('data.recorded', true);
});

it('announces a second trip to the door again: a failed delivery that goes out once more says "arrived" again', function () {
    $x = deliveryAt('out_for_delivery');
    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertOk();
    $this->postJson("/api/v1/admin/deliveries/{$x->delivery->id}/fail", ['reason_code' => 'customer_absent'])->assertOk();

    $this->postJson("/api/v1/admin/deliveries/{$x->delivery->id}/out-for-delivery")->assertOk();
    expect($x->delivery->fresh()->arrived_at)->toBeNull()->and($x->delivery->fresh()->nearby_at)->toBeNull();

    $this->postJson(sprintf(ARRIVED_URL, $x->delivery->id))->assertOk();
    expect(arrivalMessages($x, 'delivery.arrived'))->toHaveCount(2);
});
