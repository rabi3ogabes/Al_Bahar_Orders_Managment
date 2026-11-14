<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Support\AlternatePhone;
use App\Support\Phone;

// The customer's second number, for the delivery driver. Rules, persistence, who can see it and who never can.

const ALT = '+97455987654';

beforeEach(function () {
    $this->w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1])->assertCreated();
    $this->opts = ['address_id' => $this->w->address->id, 'payment_method' => 'cod'];
});

function quoteWith(array $extra): array
{
    return test()->postJson('/api/v1/checkout/quote', test()->opts + $extra)->assertOk()->json('data');
}

function errorCodes(array $q): array
{
    return collect($q['errors'])->pluck('code')->all();
}

/** Places a delivery order with the given alternate-phone fields and walks it to "ready"; returns [order, fulfillment, manager]. */
function readyOrderWith(object $w, array $extra): array
{
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'] + $extra)->assertCreated()->json('data.order.id');
    $order = Order::with('fulfillments')->findOrFail($orderId);
    $f = $order->fulfillments->first();
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    foreach (['accepted', 'preparing', 'ready'] as $s) {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }

    return [$order->fresh(), $f->fresh(), $mgr];
}

/* ───────────────────────── validation ───────────────────────── */

it('needs a number or the "I don\'t have another number" tick once the client asks for it', function () {
    $q = quoteWith(['alternate_phone' => null, 'no_alternate_phone' => false]);
    expect($q['can_place_order'])->toBeFalse()->and(errorCodes($q))->toContain('alternate_phone_required')
        ->and(collect($q['errors'])->firstWhere('code', 'alternate_phone_required')['message'])->toContain('another number');

    expect(quoteWith(['alternate_phone' => ALT])['can_place_order'])->toBeTrue();
    expect(quoteWith(['no_alternate_phone' => true])['can_place_order'])->toBeTrue();
    expect(quoteWith(['alternate_phone' => '   ', 'no_alternate_phone' => true])['can_place_order'])->toBeTrue();
});

it('accepts Qatar mobile numbers in any common spelling, Arabic digits included', function (string $typed) {
    $q = quoteWith(['alternate_phone' => $typed]);
    expect($q['can_place_order'])->toBeTrue()->and(errorCodes($q))->toBe([]);
})->with(['55987654', '+974 5598 7654', '00974 55987654', '٥٥٩٨٧٦٥٤', '+٩٧٤٥٥٩٨٧٦٥٤', '33 987654', '6612 3456', '74123456']);

it('explains in plain words why a number is not accepted', function (string $typed) {
    $q = quoteWith(['alternate_phone' => $typed]);
    expect($q['can_place_order'])->toBeFalse()->and(errorCodes($q))->toBe(['alternate_phone_invalid'])
        ->and(collect($q['errors'])->firstWhere('code', 'alternate_phone_invalid')['message'])->toBe('Please enter a valid Qatar mobile number: 8 digits starting with 3, 5, 6 or 7.');
})->with(['4412345', '12345678', '4455987654', 'call me', '+1 202 555 0143', '5598765', '555987654321', '+974 2012 3456']);

it('refuses the main number, however it is typed', function (string $typed) {
    $main = $this->w->customer->phone; // +9745xxxxxxx
    $q = quoteWith(['alternate_phone' => str_replace('{main}', substr($main, 4), $typed)]);
    expect(errorCodes($q))->toBe(['alternate_phone_same'])->and($q['can_place_order'])->toBeFalse();
})->with(['{main}', '+974{main}', '974 {main}', '00974{main}']);

it('answers in Arabic when the customer app speaks Arabic', function () {
    $q = $this->postJson('/api/v1/checkout/quote', $this->opts + ['alternate_phone' => 'abc'], ['Accept-Language' => 'ar'])->json('data');
    $message = collect($q['errors'])->firstWhere('code', 'alternate_phone_invalid')['message'];
    expect($message)->toContain('جوال');
});

it('never asks pickup orders for it, whatever the app sends', function () {
    $pickup = ['fulfillment_type' => 'pickup', 'branch_id' => $this->w->a->id, 'payment_method' => 'pay_at_pickup'];
    foreach ([[], ['alternate_phone' => null, 'no_alternate_phone' => false], ['alternate_phone' => 'garbage']] as $extra) {
        $q = $this->postJson('/api/v1/checkout/quote', $pickup + $extra)->assertOk()->json('data');
        expect($q['can_place_order'])->toBeTrue()->and(errorCodes($q))->toBe([]);
    }
    $orderId = quoteAndPlace($pickup + ['alternate_phone' => ALT])->assertCreated()->json('data.order.id');
    expect(Order::findOrFail($orderId)->alternate_phone)->toBeNull()->and($this->w->customer->fresh()->alternate_phone)->toBeNull();
});

it('keeps older apps working: no alternate fields means no requirement', function () {
    $q = quoteWith([]);
    expect($q['can_place_order'])->toBeTrue()->and(errorCodes($q))->toBe([]);
});

it('uses the number saved on the profile for an older app that does not send the fields', function () {
    $this->w->customer->forceFill(['alternate_phone' => ALT])->save();
    $orderId = quoteAndPlace($this->opts)->assertCreated()->json('data.order.id');
    expect(Order::findOrFail($orderId)->alternate_phone)->toBe(ALT);
});

it('keeps the profile number out of an order when the customer ticked "no other number"', function () {
    $this->w->customer->forceFill(['alternate_phone' => ALT])->save();
    $orderId = quoteAndPlace($this->opts + ['alternate_phone' => null, 'no_alternate_phone' => true])->assertCreated()->json('data.order.id');
    expect(Order::findOrFail($orderId)->alternate_phone)->toBeNull()->and($this->w->customer->fresh()->alternate_phone)->toBe(ALT);
});

it('refuses to place the order without it (the quote error comes back as the order error)', function () {
    $quote = quoteWith(['alternate_phone' => ALT]);
    $this->postJson('/api/v1/checkout/orders', $this->opts + ['alternate_phone' => null, 'no_alternate_phone' => false, 'quote_hash' => $quote['quote_hash']], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonPath('code', 'checkout_invalid')
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'another number'));
    expect(Order::count())->toBe(0);
});

/* ───────────────────────── persistence ───────────────────────── */

it('snapshots it on the order in E.164 and saves it on the profile for next time', function () {
    $orderId = quoteAndPlace($this->opts + ['alternate_phone' => '٥٥٩٨٧٦٥٤'])->assertCreated()->json('data.order.id');
    expect(Order::findOrFail($orderId)->alternate_phone)->toBe(ALT)->and($this->w->customer->fresh()->alternate_phone)->toBe(ALT);

    // Next order: the app sends the saved number; a different one replaces it on the profile but never rewrites the first order.
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1])->assertCreated();
    $second = quoteAndPlace($this->opts + ['alternate_phone' => '+97466111222'])->assertCreated()->json('data.order.id');
    expect(Order::findOrFail($second)->alternate_phone)->toBe('+97466111222')->and(Order::findOrFail($orderId)->alternate_phone)->toBe(ALT)
        ->and($this->w->customer->fresh()->alternate_phone)->toBe('+97466111222');
});

it('leaves the profile alone when the customer says they have no other number', function () {
    $orderId = quoteAndPlace($this->opts + ['alternate_phone' => null, 'no_alternate_phone' => true])->assertCreated()->json('data.order.id');
    expect(Order::findOrFail($orderId)->alternate_phone)->toBeNull()->and($this->w->customer->fresh()->alternate_phone)->toBeNull();
});

it('does not echo the number back in the quote or the placed-order response of the customer', function () {
    $quote = quoteWith(['alternate_phone' => ALT]);
    expect(json_encode($quote))->not->toContain('987654');
    $placed = quoteAndPlace($this->opts + ['alternate_phone' => ALT])->assertCreated()->getContent();
    expect($placed)->not->toContain('987654');
});

/* ───────────────────────── profile ───────────────────────── */

it('lets the customer save, replace and clear it in their profile', function () {
    $this->patchJson('/api/v1/me', ['alternate_phone' => '٥٥٩٨٧٦٥٤'])->assertOk()->assertJsonPath('data.alternate_phone', ALT);
    expect($this->w->customer->fresh()->alternate_phone)->toBe(ALT);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.alternate_phone', ALT);

    $this->patchJson('/api/v1/me', ['name' => 'Noor Al Thani'])->assertOk()->assertJsonPath('data.alternate_phone', ALT); // untouched by other edits
    $this->patchJson('/api/v1/me', ['alternate_phone' => '+97466111222'])->assertOk()->assertJsonPath('data.alternate_phone', '+97466111222');
    $this->patchJson('/api/v1/me', ['alternate_phone' => null])->assertOk()->assertJsonPath('data.alternate_phone', null);
    $this->patchJson('/api/v1/me', ['alternate_phone' => ALT])->assertOk();
    $this->patchJson('/api/v1/me', ['alternate_phone' => ''])->assertOk()->assertJsonPath('data.alternate_phone', null);
    expect($this->w->customer->fresh()->alternate_phone)->toBeNull();
});

it('validates the profile number the same way as checkout', function (string $typed, string $message) {
    $this->patchJson('/api/v1/me', ['alternate_phone' => $typed])->assertStatus(422)->assertJsonValidationErrors('alternate_phone')
        ->assertJsonPath('errors.alternate_phone.0', fn ($m) => str_contains($m, $message));
    expect($this->w->customer->fresh()->alternate_phone)->toBeNull();
})->with([['12345', 'valid Qatar mobile'], ['hello', 'valid Qatar mobile'], ['+97420123456', 'valid Qatar mobile']]);

it('refuses the main number in the profile too', function () {
    $this->patchJson('/api/v1/me', ['alternate_phone' => $this->w->customer->phone])->assertStatus(422)
        ->assertJsonPath('errors.alternate_phone.0', fn ($m) => str_contains($m, 'main number'));
});

it('never writes the number into the audit trail', function () {
    $this->patchJson('/api/v1/me', ['alternate_phone' => ALT])->assertOk();
    $this->patchJson('/api/v1/me', ['alternate_phone' => '+97466111222'])->assertOk();
    $trail = AuditLog::where('action', 'account.updated')->get()->map(fn ($a) => json_encode([$a->old_values, $a->new_values, $a->metadata]))->implode(' ');
    expect($trail)->toContain('alternate_phone')->and($trail)->toContain('REDACTED')->and($trail)->not->toContain('987654')->and($trail)->not->toContain('111222');

    quoteAndPlace($this->opts + ['alternate_phone' => '+97477123123'])->assertCreated();
    expect(json_encode(AuditLog::all()->map(fn ($a) => [$a->old_values, $a->new_values, $a->metadata])))->not->toContain('123123');
});

it('is cleared with the account: the profile and every order snapshot', function () {
    $orderId = quoteAndPlace($this->opts + ['alternate_phone' => ALT])->assertCreated()->json('data.order.id');
    // Somebody else's order must stay as it is.
    $stranger = User::factory()->create();
    $stranger->addresses()->create(['recipient_name' => 'Sara', 'recipient_phone' => '+97455000111', 'zone_number' => '66', 'street_number' => '850', 'building_number' => '12', 'is_default' => true]);
    actingAsCustomer($stranger);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $this->w->v1->id, 'quantity' => 1])->assertCreated();
    $strangerOrderId = quoteAndPlace(['address_id' => $stranger->addresses()->first()->id, 'payment_method' => 'cod', 'alternate_phone' => '+97433333333'])->assertCreated()->json('data.order.id');

    actingAsCustomer($this->w->customer);
    $this->postJson('/api/v1/me/deletion-request', ['reason' => 'moving'])->assertSuccessful();
    expect($this->w->customer->fresh()->alternate_phone)->toBeNull()->and(Order::findOrFail($orderId)->alternate_phone)->toBeNull()
        ->and(Order::findOrFail($strangerOrderId)->alternate_phone)->toBe('+97433333333')->and($stranger->fresh()->alternate_phone)->toBe('+97433333333');

    // The same helper is what any future anonymiser must call.
    $user = User::factory()->create(['alternate_phone' => ALT]);
    AlternatePhone::erase($user);
    expect($user->fresh()->alternate_phone)->toBeNull();
});

/* ───────────────────────── who can see it ───────────────────────── */

it('shows it to the driver of that delivery, and to nobody else on the road', function () {
    $driver = driverFor($this->w->a);
    $rival = driverFor($this->w->a);
    [$order, $f, $mgr] = readyOrderWith($this->w, ['alternate_phone' => ALT]);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();

    actingAsStaff($driver);
    $jobs = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data');
    expect($jobs)->toHaveCount(1)->and($jobs[0]['alternate_phone'])->toBe(ALT)->and($jobs[0]['customer_phone'])->toBe($order->customer_phone);

    actingAsStaff($rival);
    $theirs = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk();
    expect($theirs->json('data'))->toBe([])->and($theirs->getContent())->not->toContain('987654');
});

it('gives the driver null (not a missing key) when the customer has no other number', function () {
    $driver = driverFor($this->w->a);
    [, $f] = readyOrderWith($this->w, ['alternate_phone' => null, 'no_alternate_phone' => true]);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    actingAsStaff($driver);
    $job = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->json('data.0');
    expect(array_key_exists('alternate_phone', $job))->toBeTrue()->and($job['alternate_phone'])->toBeNull();
});

it('shows it in the queue of the branch that fulfils the order, not in another branch\'s', function () {
    [, $f, $mgr] = readyOrderWith($this->w, ['alternate_phone' => ALT]);
    $queue = $this->getJson('/api/v1/admin/dispatch/queue?stage=ready&branch_id='.$this->w->a->id)->assertOk()->json('data');
    expect($queue)->toHaveCount(1)->and($queue[0]['alternate_phone'])->toBe(ALT);

    actingAsStaff(staffWithRole('order_manager', $this->w->b));
    $this->getJson('/api/v1/admin/dispatch/queue?stage=ready&branch_id='.$this->w->a->id)->assertForbidden();
    $own = $this->getJson('/api/v1/admin/dispatch/queue?stage=all&branch_id='.$this->w->b->id)->assertOk();
    expect($own->json('data'))->toBe([])->and($own->getContent())->not->toContain('987654');
});

it('shows it on the staff order detail and the admin order page, only to staff who may see the order', function () {
    [$order, , $mgr] = readyOrderWith($this->w, ['alternate_phone' => ALT]);
    $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.alternate_phone', ALT);
    $this->actingAs($mgr)->get(route('admin.orders.show', $order))->assertOk()->assertSee('+97455987654')->assertSee('Alternative number');

    $outsider = staffWithRole('order_manager', $this->w->b);
    $this->actingAs($outsider)->get(route('admin.orders.show', $order))->assertForbidden();
    $this->actingAs(staffWithRole('inventory_manager', $this->w->a))->get(route('admin.orders.show', $order))->assertForbidden();
});

it('gives the web driver console a second call button for it', function () {
    $driver = driverFor($this->w->a);
    [, $f] = readyOrderWith($this->w, ['alternate_phone' => ALT]);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $this->actingAs($driver)->get(route('admin.driver'))->assertOk()->assertSee('tel:+97455987654', false)->assertSee('Call alternative number');
});

it('keeps it out of everything the customer, the live order card and raw model dumps can see', function () {
    $orderId = quoteAndPlace($this->opts + ['alternate_phone' => ALT])->assertCreated()->json('data.order.id');
    $order = Order::findOrFail($orderId);

    $payloads = [
        $this->getJson("/api/v1/orders/{$orderId}")->assertOk()->getContent(),
        $this->getJson('/api/v1/orders')->assertOk()->getContent(),
        $this->getJson('/api/v1/orders/active-progress')->getContent(),
        json_encode($order->toArray()), json_encode($order), $order->toJson(),
        json_encode($this->w->customer->fresh()->toArray()),
    ];
    foreach ($payloads as $payload) {
        expect($payload)->not->toContain('987654');
    }
    // The customer's own profile is the one customer payload that carries it (to prefill the next order).
    $this->getJson('/api/v1/me')->assertJsonPath('data.alternate_phone', ALT);

    // Another customer cannot read the order at all.
    actingAsCustomer(User::factory()->create());
    $this->getJson("/api/v1/orders/{$orderId}")->assertNotFound();
});

it('puts nothing about it in the order timeline, notifications or logs', function () {
    $orderId = quoteAndPlace($this->opts + ['alternate_phone' => ALT])->assertCreated()->json('data.order.id');
    $order = Order::with('history')->findOrFail($orderId);
    expect(json_encode($order->history->toArray()))->not->toContain('987654');
    expect(json_encode(\App\Domain\Notifications\Models\Notification::all()->toArray()))->not->toContain('987654');
});

it('only ever stores a normalised Qatar mobile number', function () {
    expect(AlternatePhone::check('55987654', '+97455123456'))->toBe([ALT, null])
        ->and(AlternatePhone::check('+974 5512 3456', '+97455123456'))->toBe([null, 'alternate_phone_same'])
        ->and(AlternatePhone::check('', '+97455123456'))->toBe([null, null])
        ->and(AlternatePhone::check(null, null))->toBe([null, null])
        ->and(AlternatePhone::check('abc', null))->toBe([null, 'alternate_phone_invalid'])
        ->and(AlternatePhone::check('55987654', null))->toBe([ALT, null])
        ->and(Phone::same('55123456', '+974 5512 3456'))->toBeTrue()->and(Phone::same('x', 'x'))->toBeFalse();
});
