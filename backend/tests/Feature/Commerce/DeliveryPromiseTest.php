<?php

use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliverySlotBooking;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Checkout\Services\DeliveryPromise;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../Operations/OpsSupport.php';

/** Monday 23 Nov 2026 at the given Qatar time: the clock every promise test runs on (Qatar is UTC+3, so never rely on the real hour). */
function promiseAt(string $time = '12:00'): void
{
    test()->travelTo(qatar('2026-11-23 '.$time));
    Cache::flush();
}

function promiseUrl(object $w, array $query = []): string
{
    return "/api/v1/products/{$w->p1->id}/delivery-promise?".http_build_query($query);
}

function promiseSlot(object $branch, string $date, string $from = '16:00', string $to = '18:00', int $capacity = 2, int $cutoff = 60): DeliverySlot
{
    return DeliverySlot::create(['branch_id' => $branch->id, 'fulfillment_type' => 'delivery', 'day_of_week' => BusinessTime::local($date)->dayOfWeek,
        'starts_at' => $from, 'ends_at' => $to, 'capacity' => $capacity, 'cutoff_minutes' => $cutoff]);
}

it('promises same-day delivery before the cut-off and says until when the branch is open', function () {
    $w = commerceWorld();
    promiseAt('12:00'); // Monday, cut-off 15:00, branch open 10:00-22:00

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');

    expect($r['enabled'])->toBeTrue()->and($r['status'])->toBe('scheduled')->and($r['location_known'])->toBeTrue()
        ->and($r['parcels'])->toHaveCount(1)
        ->and($r['parcels'][0])->toMatchArray(['kind' => 'same_day', 'date' => '2026-11-23', 'until' => '22:00', 'order_by' => '15:00', 'status' => 'scheduled'])
        ->and($r['parcels'][0]['branch']['id'])->toBe($w->a->id)
        ->and($r['message'])->toBe('Delivery today before 10 pm')
        ->and($r['parcels'][0]['detail'])->toBe('if you order by 3 pm');
});

it('speaks Arabic when the customer does', function () {
    $w = commerceWorld();
    promiseAt('12:00');

    $r = $this->withHeaders(['Accept-Language' => 'ar'])->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');

    expect($r['message'])->toBe('التوصيل اليوم قبل 10 م')->and($r['parcels'][0]['detail'])->toBe('إذا طلبت قبل 3 م');

    promiseAt('16:30'); // after the cut-off: tomorrow, with the Arabic weekday for a later day
    $r = $this->withHeaders(['Accept-Language' => 'ar'])->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    expect($r['message'])->toBe('التوصيل غداً');
});

it('moves to the next open day after the cut-off or when too little open time is left for the drive', function () {
    $w = commerceWorld();
    promiseAt('15:30'); // Monday, after the 15:00 cut-off

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    expect($r['parcels'][0])->toMatchArray(['kind' => 'day', 'date' => '2026-11-24'])->and($r['message'])->toBe('Delivery tomorrow');

    // A later cut-off, but the branch closes at 22:00 and the drive takes 45 minutes (25 + 20 by default): 21:30 is too late.
    app(Settings::class)->set('checkout.same_day_cutoff', '23:00');
    promiseAt('21:30');
    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    expect($r['parcels'][0]['date'])->toBe('2026-11-24');

    promiseAt('20:00'); // enough time left: same day again
    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    expect($r['parcels'][0])->toMatchArray(['kind' => 'same_day', 'date' => '2026-11-23', 'order_by' => '23:00']);
});

it('skips closed days and holidays and names the weekday and date', function () {
    $w = commerceWorld();
    $hours = app(BranchService::class)->defaultHours();
    $hours[2] = ['is_closed' => true]; // Tuesday
    app(BranchService::class)->setWeeklyHours($w->a, $hours, audit: false);
    BranchHoliday::create(['branch_id' => $w->a->id, 'type' => 'holiday', 'name_en' => 'National Day', 'name_ar' => 'اليوم الوطني',
        'starts_at' => qatar('2026-11-25 00:00')->utc(), 'ends_at' => qatar('2026-11-26 00:00')->utc()]); // Wednesday
    promiseAt('16:00'); // after the cut-off, so tomorrow (closed), the day after (holiday), then Thursday

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');

    expect($r['parcels'][0])->toMatchArray(['kind' => 'day', 'date' => '2026-11-26', 'label' => 'Thu 26 Nov'])->and($r['message'])->toBe('Delivery Thu 26 Nov');
});

it('is honest when the item is not in stock: no invented date, still a 200', function () {
    $w = commerceWorld([0, 5], [0, 5]); // product one is out everywhere
    promiseAt('12:00');

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');

    expect($r['status'])->toBe('unconfirmed')->and($r['all_by'])->toBeNull()->and($r['message'])->toBe('We will confirm your delivery date')
        ->and($r['parcels'][0]['reason'])->toBe('out_of_stock')->and($r['parcels'][0]['date'])->toBeNull()
        ->and($r['parcels'][0]['detail'])->toBe('Not in stock right now.');
});

it('uses another branch that serves the address when the chosen one is out of stock', function () {
    $w = commerceWorld([0, 5], [5, 5]); // only branch B holds product one
    promiseAt('12:00');

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');

    expect($r['status'])->toBe('scheduled')->and($r['parcels'][0]['branch']['id'])->toBe($w->b->id);
});

it('does not promise when the branch is closed for now or the branch does not deliver', function () {
    $w = commerceWorld();
    promiseAt('12:00');
    $w->a->update(['opening_status' => 'temporarily_closed']);
    $w->b->update(['delivery_enabled' => false]);

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id]))->assertOk()->json('data');

    // B no longer delivers and A is closed: nothing can be promised, plainly.
    expect($r['status'])->toBe('unconfirmed')->and($r['parcels'][0]['reason'])->toBe('branch_closed')->and($r['parcels'][0]['detail'])->toContain($w->a->name_en);
});

it('adds the product lead time and, only for delivery with assembly, the assembly days', function () {
    $w = commerceWorld();
    promiseAt('12:00');
    $w->p1->update(['lead_time_days' => 2, 'assembly_fee' => '75.00']);

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    expect($r['parcels'][0])->toMatchArray(['kind' => 'day', 'date' => '2026-11-25', 'lead_days' => 2]);

    app(Settings::class)->set('checkout.assembly_extra_days', 1);
    Cache::flush();
    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66', 'assembly' => 1]))->assertOk()->json('data');
    expect($r['parcels'][0])->toMatchArray(['date' => '2026-11-26', 'lead_days' => 3]);

    // A product that does not offer assembly gets no extra days from the tick (the switch is on, the fee is not set).
    $w->p1->update(['assembly_fee' => null]);
    Cache::flush();
    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66', 'assembly' => 1]))->assertOk()->json('data');
    expect($r['parcels'][0]['lead_days'])->toBe(2);

    // Pickup never carries assembly days.
    $w->p1->update(['assembly_fee' => '75.00']);
    Cache::flush();
    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'fulfillment_type' => 'pickup', 'assembly' => 1]))->assertOk()->json('data');
    expect($r['parcels'][0]['lead_days'])->toBe(2);
});

it('takes the date from the first delivery slot with a free place, the same slots the checkout picker shows', function () {
    $w = commerceWorld();
    promiseAt('12:00');
    $tomorrow = '2026-11-24';
    $slot = promiseSlot($w->a, $tomorrow, capacity: 1);

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    expect($r['parcels'][0])->toMatchArray(['kind' => 'slot', 'date' => $tomorrow, 'status' => 'scheduled'])
        ->and($r['parcels'][0]['slot'])->toBe(['id' => $slot->id, 'starts_at' => '16:00', 'ends_at' => '18:00'])
        ->and($r['message'])->toBe('Delivery tomorrow, 4 pm – 6 pm');

    // The picker offers exactly that place.
    $picker = collect($this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date={$tomorrow}")->assertOk()->json('data'))->firstWhere('id', $slot->id);
    expect($picker['available'])->toBeGreaterThan(0);

    // The last place goes: the promise moves to the next day that slot runs (a week later), and the picker agrees it is full tomorrow.
    DeliverySlotBooking::create(['delivery_slot_id' => $slot->id, 'slot_date' => $tomorrow, 'booked' => 1]);
    Cache::flush();
    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    expect($r['parcels'][0]['date'])->toBe('2026-12-01');
    $full = collect($this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date={$tomorrow}")->json('data'))->firstWhere('id', $slot->id);
    expect($full['is_full'])->toBeTrue();
});

it('never names a day or a time the slot picker cannot offer, and the checkout accepts the promised slot', function () {
    $w = commerceWorld();
    promiseAt('15:30');
    // Today 16:00-18:00 needs 60 minutes of notice: 15:30 is too late for it, so the promise must not say today.
    $today = promiseSlot($w->a, '2026-11-23', '16:00', '18:00', 3, 60);
    $later = promiseSlot($w->a, '2026-11-24', '10:00', '12:00', 3, 60);

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    $parcel = $r['parcels'][0];
    expect($parcel['date'])->toBe('2026-11-24')->and($parcel['slot']['id'])->toBe($later->id)->and($parcel['slot']['id'])->not->toBe($today->id);

    // The same service the picker uses lists it as bookable; and an order with exactly that slot is accepted.
    $offered = collect($this->getJson("/api/v1/branches/{$w->a->id}/delivery-slots?date={$parcel['date']}")->json('data'))->firstWhere('id', $parcel['slot']['id']);
    expect($offered['is_full'])->toBeFalse();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertCreated();
    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => false,
        'delivery_slot_id' => $parcel['slot']['id'], 'delivery_date' => $parcel['date']])->assertOk()->json('data');
    expect($q['can_place_order'])->toBeTrue()->and($q['fulfillments'][0]['promise']['slot']['id'])->toBe($parcel['slot']['id'])
        ->and($q['fulfillments'][0]['promise']['message'])->toBe('Delivery tomorrow, 10 am – 12 pm');
});

it('makes the checkout refuse a slot earlier than the items can be ready, and the quote says from when', function () {
    $w = commerceWorld();
    promiseAt('12:00');
    $w->p1->update(['lead_time_days' => 3]);
    $slot = promiseSlot($w->a, '2026-11-24', '16:00', '18:00', 3, 60); // tomorrow: too soon for a 3-day item
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertCreated();

    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => false,
        'delivery_slot_id' => $slot->id, 'delivery_date' => '2026-11-24'])->assertOk()->json('data');

    expect($q['can_place_order'])->toBeFalse()->and(collect($q['errors'])->pluck('code'))->toContain('slot_too_soon')
        ->and($q['fulfillments'][0]['earliest_date'])->toBe('2026-11-26');
});

it('promises pickup from the preparation time inside opening hours, rounded up to a quarter of an hour', function () {
    $w = commerceWorld();
    $w->a->update(['pickup_preparation_minutes' => 60]);
    promiseAt('12:07');

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'fulfillment_type' => 'pickup']))->assertOk()->json('data');
    expect($r['parcels'][0])->toMatchArray(['kind' => 'ready', 'date' => '2026-11-23', 'from' => '13:15'])->and($r['message'])->toBe('Pick up today from 1:15 pm')
        ->and($r['location_known'])->toBeTrue();

    promiseAt('21:30'); // not enough time before closing: tomorrow when the branch opens
    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'fulfillment_type' => 'pickup']))->assertOk()->json('data');
    expect($r['parcels'][0])->toMatchArray(['date' => '2026-11-24', 'from' => '10:00'])->and($r['message'])->toBe('Pick up tomorrow from 10 am');
});

it('promises pickup from the branch slots when it publishes them', function () {
    $w = commerceWorld();
    promiseAt('12:00');
    $slot = DeliverySlot::create(['branch_id' => $w->a->id, 'fulfillment_type' => 'pickup', 'day_of_week' => 2, 'starts_at' => '17:00', 'ends_at' => '19:00', 'capacity' => 2, 'cutoff_minutes' => 30]);

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'fulfillment_type' => 'pickup']))->assertOk()->json('data');

    expect($r['parcels'][0])->toMatchArray(['kind' => 'slot', 'date' => '2026-11-24'])->and($r['parcels'][0]['slot']['id'])->toBe($slot->id)
        ->and($r['message'])->toBe('Pick up tomorrow, 5 pm – 7 pm');
});

it('gives a guest one date per parcel and an overall "all by" for a basket that splits across branches', function () {
    $w = commerceWorld([5, 0], [0, 5]); // product one only at A, product two only at B
    $this->app['auth']->forgetGuards();
    promiseAt('12:00');
    $hours = app(BranchService::class)->defaultHours();
    $hours[1] = ['is_closed' => true]; // Monday: branch B is closed today, so its parcel is a day later
    app(BranchService::class)->setWeeklyHours($w->b, $hours, audit: false);

    $r = $this->postJson('/api/v1/cart/promise', ['zone_number' => '66', 'lines' => [['variant_id' => $w->v1->id, 'quantity' => 1], ['variant_id' => $w->v2->id, 'quantity' => 2]]])
        ->assertOk()->json('data');

    expect($r['is_split'])->toBeTrue()->and($r['parcels'])->toHaveCount(2)->and($r['status'])->toBe('scheduled');
    $byBranch = collect($r['parcels'])->keyBy(fn ($p) => $p['branch']['id']);
    expect($byBranch[$w->a->id]['date'])->toBe('2026-11-23')->and($byBranch[$w->b->id]['kind'])->toBe('day')->and($byBranch[$w->b->id]['date'])->toBe('2026-11-24')
        ->and($r['all_by'])->toBe(['date' => $byBranch[$w->b->id]['date'], 'label' => $byBranch[$w->b->id]['label']])
        ->and($r['message'])->toStartWith('All delivered by');
});

it('says what it can when part of the basket cannot be promised', function () {
    $w = commerceWorld([5, 0], [0, 0]); // product two is nowhere
    $this->app['auth']->forgetGuards();
    promiseAt('12:00');

    $r = $this->postJson('/api/v1/cart/promise', ['zone_number' => '66', 'lines' => [['variant_id' => $w->v1->id, 'quantity' => 1], ['variant_id' => $w->v2->id, 'quantity' => 1]]])
        ->assertOk()->json('data');

    expect($r['status'])->toBe('partial')->and($r['all_by'])->toBeNull()->and($r['message'])->toContain('We will confirm the rest')
        ->and(collect($r['parcels'])->pluck('status')->all())->toContain('scheduled', 'unconfirmed');
});

it('serves a guest cart without a token, validates it and ignores variants the shop does not know', function () {
    $w = commerceWorld();
    $this->app['auth']->forgetGuards();
    promiseAt('12:00');

    $this->postJson('/api/v1/cart/promise', [])->assertStatus(422)->assertJsonValidationErrors(['lines']);
    $this->postJson('/api/v1/cart/promise', ['lines' => [['variant_id' => 'not-a-uuid', 'quantity' => 1]]])->assertStatus(422);
    $this->postJson('/api/v1/cart/promise', ['lines' => [['variant_id' => $w->v1->id, 'quantity' => 0]]])->assertStatus(422);

    $stale = (string) Str::uuid(); // a line left in a phone's storage for a variant that was deleted
    $r = $this->postJson('/api/v1/cart/promise', ['lines' => [['variant_id' => $stale, 'quantity' => 1], ['variant_id' => $w->v1->id, 'quantity' => 1]]])->assertOk()->json('data');
    expect($r['status'])->toBe('scheduled')->and($r['location_known'])->toBeFalse()->and($r['parcels'])->toHaveCount(1);

    $only = $this->postJson('/api/v1/cart/promise', ['lines' => [['variant_id' => $stale, 'quantity' => 1]]])->assertOk()->json('data');
    expect($only['status'])->toBe('unconfirmed')->and($only['parcels'])->toBe([]);
});

it('answers the signed-in cart from the server cart, with the customer\'s own address only', function () {
    $w = commerceWorld();
    promiseAt('12:00');
    $w->p1->update(['assembly_fee' => '60.00']);
    app(Settings::class)->set('checkout.assembly_extra_days', 2);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1, 'assembly' => true])->assertCreated();

    $own = $this->getJson('/api/v1/cart/promise?address_id='.$w->address->id)->assertOk()->json('data');
    expect($own['location_known'])->toBeTrue()->and($own['parcels'][0])->toMatchArray(['date' => '2026-11-25', 'lead_days' => 2]);

    // Someone else's address is never used: the zone stays unknown (the date shown is from the best showroom).
    $other = User::factory()->create();
    $theirs = $other->addresses()->create(['recipient_name' => 'Mona', 'recipient_phone' => '+97455123457', 'zone_number' => '66', 'street_number' => '1', 'building_number' => '2']);
    $r = $this->getJson('/api/v1/cart/promise?address_id='.$theirs->id)->assertOk()->json('data');
    expect($r['location_known'])->toBeFalse();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/cart/promise')->assertStatus(401);
});

it('shows nothing and works as before when the dashboard switch is off', function () {
    $w = commerceWorld();
    promiseAt('12:00');
    app(Settings::class)->set('checkout.promise_enabled', false);

    $r = $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id, 'zone_number' => '66']))->assertOk()->json('data');
    expect($r['enabled'])->toBeFalse()->and($r['parcels'])->toBe([])->and($r['message'])->toBeNull();

    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertCreated();
    $q = $this->postJson('/api/v1/checkout/quote', ['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertOk()->json('data');
    expect($q['promise'])->toBeNull()->and($q['fulfillments'][0]['promise'])->toBeNull()->and($q['can_place_order'])->toBeTrue();
});

it('adds the promise to the quote without touching the quote hash', function () {
    $w = commerceWorld();
    promiseAt('12:00');
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1])->assertCreated();
    $options = ['address_id' => $w->address->id, 'payment_method' => 'cod', 'allow_split' => false];

    $q = $this->postJson('/api/v1/checkout/quote', $options)->assertOk()->json('data');
    expect($q['promise']['status'])->toBe('scheduled')->and($q['promise']['message'])->toBe('Delivery today before 10 pm')
        ->and($q['fulfillments'][0]['promise']['kind'])->toBe('same_day')->and($q['fulfillments'][0]['earliest_date'])->toBe('2026-11-23');

    // Same cart a little later in the same day: another promise text can never invalidate the confirmed total.
    $hash = $q['quote_hash'];
    promiseAt('14:30');
    expect($this->postJson('/api/v1/checkout/quote', $options)->json('data.quote_hash'))->toBe($hash);
});

it('formats day and time labels the way a customer reads them', function () {
    $svc = app(DeliveryPromise::class);
    $now = qatar('2026-11-23 09:00');

    expect($svc->dayLabel('2026-11-23', $now))->toBe('today')->and($svc->dayLabel('2026-11-24', $now))->toBe('tomorrow')
        ->and($svc->dayLabel('2026-11-28', $now))->toBe('Sat 28 Nov')
        ->and($svc->timeLabel(qatar('2026-11-23 21:00')))->toBe('9 pm')->and($svc->timeLabel(qatar('2026-11-23 16:15')))->toBe('4:15 pm');

    app()->setLocale('ar');
    expect($svc->dayLabel('2026-11-28', $now))->toBe('يوم السبت 28 نوفمبر')->and($svc->timeLabel(qatar('2026-11-23 16:15')))->toBe('4:15 م');
});

it('rate limits the public promise endpoints', function () {
    $w = commerceWorld();
    $this->app['auth']->forgetGuards();
    promiseAt('12:00');

    foreach (range(1, 60) as $i) {
        $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id]))->assertOk();
    }
    $this->getJson(promiseUrl($w, ['branch_id' => $w->a->id]))->assertStatus(429);
});
