<?php

use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Operations/OpsSupport.php';

/** The driver's job card: what, where and when first, then the facts he needs before he leaves, only when the data says so. */

/** Walks a delivery to $status for a fresh order and lets the test describe its order, address and parcel. */
function contextJob(string $status = 'assigned'): object
{
    $x = deliveryAt($status);
    $order = Order::findOrFail($x->orderId);
    $x->order = $order;
    $x->url = '/api/v1/admin/dispatch/driver/jobs';
    $x->line = $order->fulfillments()->firstOrFail()->items()->firstOrFail();

    return $x;
}

function contextSnapshot(object $x, array $changes): void
{
    $x->order->forceFill(['address_snapshot' => array_merge((array) $x->order->address_snapshot, $changes)])->save();
}

function theJob(object $x): array
{
    return test()->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data.0');
}

it('gives the driver the gate code, the customer\'s note and the landmark of his own open job', function () {
    $x = contextJob();
    contextSnapshot($x, ['instructions' => 'Gate code 4471, call from downstairs', 'landmark' => 'Behind the blue pharmacy']);
    $x->order->update(['customer_notes' => 'Please ring twice']);

    $job = theJob($x);

    expect($job['instructions'])->toBe('Gate code 4471, call from downstairs')
        ->and($job['customer_notes'])->toBe('Please ring twice')
        ->and($job['landmark'])->toBe('Behind the blue pharmacy')
        ->and($job['address']['building_number'])->toBe('12')
        ->and($job['redacted'])->toBeFalse();
});

it('says null for a note nobody wrote, never an empty string', function () {
    $x = contextJob();
    $x->order->update(['customer_notes' => '   ']);
    contextSnapshot($x, ['instructions' => '']);

    $job = theJob($x);

    expect($job['customer_notes'])->toBeNull()->and($job['instructions'])->toBeNull()->and($job['landmark'])->toBeNull();
});

it('tells who receives the order when that is not the account holder', function () {
    $x = contextJob();
    $x->order->update(['customer_name' => 'Hamad Al Kuwari', 'customer_phone' => '+97455000001']);
    contextSnapshot($x, ['recipient_name' => 'Noor', 'recipient_phone' => '+97455123456']);

    $job = theJob($x);

    expect($job['recipient'])->toBe(['name' => 'Noor', 'phone' => '+97455123456', 'is_account_holder' => false])
        ->and($job['customer_name'])->toBe('Hamad Al Kuwari')->and($job['customer_phone'])->toBe('+97455000001');
});

it('treats the same number written another way as the account holder', function () {
    $x = contextJob();
    $x->order->update(['customer_phone' => '+97455123456']);
    contextSnapshot($x, ['recipient_name' => 'Noor', 'recipient_phone' => '55123456']);

    expect(theJob($x)['recipient']['is_account_holder'])->toBeTrue();
});

it('gives the delivery slot as a window with a start and an end', function () {
    $x = contextJob();
    $partId = $x->order->fulfillments()->value('id');
    $slot = DeliverySlot::create(['branch_id' => $x->w->a->id, 'fulfillment_type' => 'delivery', 'day_of_week' => 2, 'starts_at' => '14:00:00', 'ends_at' => '16:00:00', 'capacity' => 5]);
    DB::table('order_fulfillments')->where('id', $partId)->update(['delivery_slot_id' => $slot->id, 'slot_date' => '2026-11-24']);

    expect(theJob($x)['slot'])->toBe(['start' => '2026-11-24T14:00:00+03:00', 'end' => '2026-11-24T16:00:00+03:00']);
});

it('has no slot for a job that was not booked into one', function () {
    $x = contextJob();

    expect(theJob($x)['slot'])->toBeNull();
});

it('lists the weight, size and flags of every line, in kilograms and centimetres', function () {
    $x = contextJob();
    DB::table('products')->where('id', $x->line->product_id)->update(['weight' => 12.5, 'weight_unit' => 'kg', 'length' => 1.2, 'width' => 80, 'height' => 40, 'dimension_unit' => 'cm', 'requires_assembly' => true]);
    DB::table('product_variants')->where('id', $x->line->product_variant_id)->update(['weight' => null, 'length' => null, 'width' => null, 'height' => null]);

    $item = theJob($x)['items'][0];

    expect($item['weight_kg'])->toBe(12.5)->and($item['dimensions_cm'])->toEqual(['length' => 1.2, 'width' => 80, 'height' => 40])
        ->and($item['requires_assembly'])->toBeTrue()->and($item['is_oversized'])->toBeFalse()
        ->and($item)->toHaveKeys(['id', 'order_item_id', 'name', 'variant_name', 'sku', 'quantity'])->not->toHaveKeys(['unit_price', 'line_total']);
});

it('converts grams and pounds to kilograms and millimetres to centimetres', function () {
    $x = contextJob();
    DB::table('products')->where('id', $x->line->product_id)->update(['weight' => 2500, 'weight_unit' => 'g', 'length' => 600, 'width' => 400, 'height' => 300, 'dimension_unit' => 'mm']);
    DB::table('product_variants')->where('id', $x->line->product_variant_id)->update(['weight' => null, 'length' => null, 'width' => null, 'height' => null]);

    $item = theJob($x)['items'][0];

    expect($item['weight_kg'])->toBe(2.5)->and($item['dimensions_cm'])->toEqual(['length' => 60, 'width' => 40, 'height' => 30]);
});

it('says nothing about size or two people for a plain parcel', function () {
    $x = contextJob();
    DB::table('products')->where('id', $x->line->product_id)->update(['weight' => 3, 'weight_unit' => 'kg', 'is_oversized' => false, 'requires_assembly' => false]);

    $job = theJob($x);

    expect($job['needs_two_people'])->toBeFalse()->and($job['is_oversized'])->toBeFalse()->and($job['requires_assembly'])->toBeFalse()
        ->and($job['total_weight_kg'])->toEqual(3);
});

it('needs two people for an oversized line, whatever it weighs', function () {
    $x = contextJob();
    DB::table('products')->where('id', $x->line->product_id)->update(['weight' => 4, 'weight_unit' => 'kg', 'is_oversized' => true]);

    $job = theJob($x);

    expect($job['needs_two_people'])->toBeTrue()->and($job['is_oversized'])->toBeTrue();
});

it('needs two people above the weight in the setting, and not at it', function () {
    $x = contextJob();
    DB::table('products')->where('id', $x->line->product_id)->update(['weight' => 25, 'weight_unit' => 'kg', 'is_oversized' => false]);
    DB::table('product_variants')->where('id', $x->line->product_variant_id)->update(['weight' => null]);
    $x->line->forceFill(['quantity' => 1])->save();

    expect(theJob($x)['needs_two_people'])->toBeFalse(); // 25 kg is the limit, not over it

    DB::table('products')->where('id', $x->line->product_id)->update(['weight' => 25.5]);
    expect(theJob($x)['needs_two_people'])->toBeTrue();

    app(Settings::class)->set('dispatch.two_person_weight_kg', 40);
    expect(theJob($x)['needs_two_people'])->toBeFalse();
});

it('adds up the weight of all the units, so many light boxes can need two people', function () {
    $x = contextJob();
    DB::table('products')->where('id', $x->line->product_id)->update(['weight' => 10, 'weight_unit' => 'kg', 'is_oversized' => false]);
    DB::table('product_variants')->where('id', $x->line->product_variant_id)->update(['weight' => null]);
    $x->line->forceFill(['quantity' => 3])->save();

    $job = theJob($x);

    expect($job['total_weight_kg'])->toEqual(30)->and($job['needs_two_people'])->toBeTrue();
});

it('writes the cash to collect in one line, per parcel', function () {
    $x = contextJob();
    DB::table('deliveries')->where('id', $x->delivery->id)->update(['cod_amount' => '303.00']);

    $job = theJob($x);

    expect($job['cod'])->toBe(['due' => true, 'amount' => '303.00', 'currency' => 'QAR'])->and($job['payment_note'])->toBe('Collect QAR 303.00 in cash at the door.')
        ->and($job['cod_amount'])->toBe('303.00');
});

it('writes the cash note in Arabic when the app asks in Arabic', function () {
    $x = contextJob();
    DB::table('deliveries')->where('id', $x->delivery->id)->update(['cod_amount' => '303.00']);

    $job = $this->getJson('/api/v1/admin/dispatch/driver/jobs', ['Accept-Language' => 'ar'])->assertOk()->json('data.0');

    expect($job['payment_note'])->toBe('حصّل 303.00 ر.ق نقداً عند الباب.');
});

it('says nothing is to be collected for an order that is already paid, or whose part has no cash left', function () {
    $x = contextJob();
    $x->order->update(['payment_method' => 'sadad']);
    DB::table('deliveries')->where('id', $x->delivery->id)->update(['cod_amount' => '0.00']);

    expect(theJob($x)['payment_note'])->toBe('Already paid online. Nothing to collect.')->and(theJob($x)['cod']['due'])->toBeFalse();

    $x->order->update(['payment_method' => 'cod']);
    expect(theJob($x)['payment_note'])->toBe('Nothing to collect at the door.');
});

it('gives the alternative number only while the dashboard switch is on', function () {
    $x = contextJob();
    $x->order->forceFill(['alternate_phone' => '+97455987654'])->save();

    expect(theJob($x)['alternate_phone'])->toBe('+97455987654');

    app(Settings::class)->set('customer_app.alternate_phone_enabled', false);
    expect(theJob($x)['alternate_phone'])->toBeNull();
});

it('shows a job only to the driver it belongs to, one by one too', function () {
    $x = contextJob();
    $other = driverFor($x->w->a);

    $mine = $this->getJson('/api/v1/admin/dispatch/driver/jobs/'.$x->delivery->id)->assertOk()->json('data');
    expect($mine['id'])->toBe($x->delivery->id)->and($mine['instructions'])->toBeNull()->and($mine)->toHaveKey('needs_two_people');

    actingAsStaff($other);
    $this->getJson('/api/v1/admin/dispatch/driver/jobs/'.$x->delivery->id)->assertNotFound();
    expect($this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data'))->toBe([]);
});

it('does not give the owner\'s private context to staff who are not the driver', function () {
    $x = contextJob();
    contextSnapshot($x, ['instructions' => 'Gate code 4471']);
    actingAsStaff($x->mgr);

    $this->getJson('/api/v1/admin/dispatch/driver/jobs/'.$x->delivery->id)->assertForbidden();
});

// ── History: the short version, and the privacy of old jobs ───────────────────────────────────────────────────────

/** Delivers the job so it is in the history, then moves its finish time back $hoursAgo hours. */
function finishedJob(object $x, int $hoursAgo): array
{
    DB::table('deliveries')->where('id', $x->delivery->id)->update(['status' => 'delivered', 'delivered_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo)]);
    contextSnapshot($x, ['instructions' => 'Gate code 4471', 'landmark' => 'Blue pharmacy', 'recipient_phone' => '+97455123456']);

    return test()->getJson('/api/v1/admin/dispatch/driver/history')->assertOk()->json('data.0');
}

it('keeps the customer\'s number and street in a job finished today', function () {
    $x = contextJob();

    $row = finishedJob($x, 3);

    expect($row['redacted'])->toBeFalse()->and($row['customer_phone'])->not->toBeNull()->and($row['address']['street_number'])->toBe('850');
});

it('drops the phones and the street from a job finished more than a day ago, keeping area, number, time, status and amount', function () {
    $x = contextJob();
    $x->order->forceFill(['alternate_phone' => '+97455987654'])->save();
    DB::table('deliveries')->where('id', $x->delivery->id)->update(['cod_amount' => '120.00']);

    $row = finishedJob($x, 30);

    expect($row['redacted'])->toBeTrue()->and($row['customer_phone'])->toBeNull()->and($row['alternate_phone'])->toBeNull()
        ->and($row['address'])->not->toHaveKeys(['street_number', 'building_number', 'unit_number', 'landmark', 'instructions', 'latitude', 'longitude', 'recipient_phone', 'recipient_name'])
        ->and($row['address']['zone_number'])->toBe('66')
        ->and($row['order_number'])->toBe($x->order->number)->and($row['status'])->toBe('delivered')->and($row['cod_amount'])->toBe('120.00');
});

it('serves the same history from jobs?scope=history', function () {
    $x = contextJob();
    finishedJob($x, 30);

    $viaScope = $this->getJson('/api/v1/admin/dispatch/driver/jobs?scope=history')->assertOk()->json('data.0');

    expect($viaScope['redacted'])->toBeTrue()->and($viaScope['id'])->toBe($x->delivery->id);
});

it('gives a finished job no notes or before-you-go facts: those belong to an open job', function () {
    $x = contextJob();
    contextSnapshot($x, ['instructions' => 'Gate code 4471']);
    $row = finishedJob($x, 3);

    expect($row)->not->toHaveKeys(['instructions', 'customer_notes', 'needs_two_people', 'payment_note']);
});

it('limits the history to the last 30 jobs, newest first', function () {
    $x = contextJob();
    $first = $x->delivery->id;
    DB::table('deliveries')->where('id', $first)->update(['status' => 'delivered', 'delivered_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)]);
    $newest = [];
    for ($i = 0; $i < 30; $i++) {
        $ids = opsOrder($x->w->a, [], ['status' => 'delivered', 'type' => 'delivery']);
        $newest[] = opsDelivery($ids['part'], $x->w->a, 'delivered', ['driver_id' => $x->driver->id, 'delivered_at' => now()->subMinutes($i + 1), 'updated_at' => now()->subMinutes($i + 1)]);
    }

    $rows = $this->getJson('/api/v1/admin/dispatch/driver/history')->assertOk()->json('data');

    expect($rows)->toHaveCount(30)->and(collect($rows)->pluck('id'))->not->toContain($first)->and($rows[0]['id'])->toBe($newest[0]);
});
