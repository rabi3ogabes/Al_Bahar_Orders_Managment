<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliverySlotBooking;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliverySlotOffer;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\SlotOffers;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/SlotOfferSupport.php';

/*
| "We could not deliver. Choose a new time" (docs/guides/DELIVERY_FOLLOW_UPS.md): the system offers a few free delivery slots after a failed delivery,
| the customer picks one, and the delivery goes back to the queue. The clock is frozen on Thursday 19 Nov 2026, midday in Qatar (no quiet hours).
*/

// ── Offering ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('offers up to three free slots, the earliest of each day first, when a delivery fails because the customer was not there', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);

    expect($offer)->not->toBeNull()->and($offer->status)->toBe('open')->and($offer->source)->toBe('system')->and($offer->user_id)->toBe($x->customer->id)
        ->and($offer->failure_reason)->toBe('customer_absent')->and($offer->options)->toHaveCount(3)
        ->and(array_column($offer->options, 'date'))->toBe(['2026-11-19', '2026-11-20', '2026-11-21'])
        ->and($offer->expires_at->equalTo($offer->notify_at->addHours(48)))->toBeTrue();
    $slot = $offer->options[1]['slot_id'];
    expect($slot)->toBe($x->slots[5]->id); // Friday 20 Nov
    expect(AuditLog::where('action', 'delivery.slots_offered')->where('auditable_id', $x->delivery->id)->count())->toBe(1);
});

it('tells the customer once, in their language, in the app inbox, with a link to the order', function () {
    $x = fuSlotWorld();
    $x->customer->update(['locale' => 'ar']);
    fuFail($x);

    $n = Notification::where('user_id', $x->customer->id)->where('event', 'delivery.slots_offered')->get();
    expect($n)->toHaveCount(1)->and($n[0]->data['deep_link'])->toBe('albahar://orders/'.$x->orderId)->and($n[0]->title)->toBe('اختر موعد توصيل جديداً')
        ->and($n[0]->body)->toContain('AB')->toContain('قبل');

    Artisan::call('deliveries:process-slot-offers'); // a second run never repeats it
    expect(Notification::where('event', 'delivery.slots_offered')->count())->toBe(1);
});

it('tells an English-speaking customer in English', function () {
    $x = fuSlotWorld();
    fuFail($x);

    $n = Notification::where('user_id', $x->customer->id)->where('event', 'delivery.slots_offered')->firstOrFail();
    expect($n->title)->toBe('Choose a new delivery time')->and($n->body)->toContain('We could not deliver order')->toContain('Sat 21 Nov');
});

it('offers nothing for a refused or damaged delivery, or an "other" reason: those are not fixed by a new time', function (string $reason) {
    $x = fuSlotWorld();
    expect(fuFail($x, $reason))->toBeNull()->and(DeliverySlotOffer::count())->toBe(0);
    expect(Notification::where('event', 'delivery.slots_offered')->count())->toBe(0);
})->with(['refused', 'damaged', 'other']);

it('offers for every reason a new time can fix', function (string $reason) {
    $x = fuSlotWorld();
    expect(fuFail($x, $reason))->not->toBeNull();
})->with(['customer_absent', 'customer_unreachable', 'wrong_address', 'access_denied']);

it('offers nothing when the switch is off, and the old "we will call you" path stays', function () {
    app(Settings::class)->set('delivery.slot_offers_enabled', false);
    $x = fuSlotWorld();

    expect(fuFail($x))->toBeNull()->and(DeliverySlotOffer::count())->toBe(0);
    $card = fuDetail($x)['fulfillments'][0];
    expect($card['slot_offer'])->toBeNull()->and($card['delivery']['status'])->toBe('failed');
});

it('keeps a closed record when the branch has no free time, so the branch sees why nobody was asked', function () {
    $x = fuSlotWorld();
    DeliverySlot::query()->update(['is_active' => false]);
    $offer = fuFail($x);

    expect($offer)->not->toBeNull()->and($offer->status)->toBe('cancelled')->and($offer->closed_reason)->toBe('no_slots')->and($offer->options)->toBe([])
        ->and(Notification::where('event', 'delivery.slots_offered')->count())->toBe(0)
        ->and(fuDetail($x)['fulfillments'][0]['slot_offer'])->toBeNull();
    expect(app(SlotOffers::class)->staffNotes([$x->delivery->fresh()])[$x->delivery->id])->toMatchArray(['state' => 'none']);
});

it('offers nothing to an order without a customer account', function () {
    $x = fuSlotWorld();
    DB::table('orders')->where('id', $x->orderId)->update(['user_id' => null]);

    expect(fuFail($x))->toBeNull();
});

it('honours capacity, closed days and the booking cut-off: a full slot, a holiday and a slot that starts too soon are never offered', function () {
    $x = fuSlotWorld(capacity: 1);
    // Today 16:00 is full, Friday is a holiday: the first free times are Saturday, Sunday, Monday.
    DeliverySlotBooking::create(['delivery_slot_id' => $x->slots[4]->id, 'slot_date' => '2026-11-19', 'booked' => 1]);
    BranchHoliday::create(['branch_id' => $x->w->a->id, 'type' => 'holiday', 'name_en' => 'Day off', 'name_ar' => 'عطلة', 'starts_at' => qatar('2026-11-20 00:00')->utc(), 'ends_at' => qatar('2026-11-21 00:00')->utc()]);

    $offer = fuFail($x);

    expect(array_column($offer->options, 'date'))->toBe(['2026-11-21', '2026-11-22', '2026-11-23']);
});

it('does not offer a slot that starts within three hours: the van has to come back to the branch first', function () {
    $x = fuSlotWorld('2026-11-19 14:00'); // today 16:00 is two hours away

    expect(array_column(fuFail($x)->options, 'date'))->toBe(['2026-11-20', '2026-11-21', '2026-11-22']);
});

it('offers the number of times the settings say, between one and five, and more of the same days when there are not enough days', function () {
    app(Settings::class)->set('delivery.slot_offer_count', 5);
    $x = fuSlotWorld();
    $a = fuFail($x);
    expect($a->options)->toHaveCount(5)->and(array_column($a->options, 'date'))->toBe(['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23']);
});

it('offers a single time when the setting says one', function () {
    app(Settings::class)->set('delivery.slot_offer_count', 1);
    $x = fuSlotWorld();

    expect(fuFail($x)->options)->toHaveCount(1);
});

it('falls back to three times when the setting is out of range', function () {
    app(Settings::class)->set('delivery.slot_offer_count', 9);
    $x = fuSlotWorld();

    expect(fuFail($x)->options)->toHaveCount(3);
});

it('holds the offer back at night and tells the customer at 08:00, when its time starts', function () {
    $x = fuSlotWorld('2026-11-19 12:00');
    $this->travelTo(qatar('2026-11-19 23:10'));
    $offer = fuFail($x);

    expect($offer->notified_at)->toBeNull()->and($offer->notify_at->equalTo(qatar('2026-11-20 08:00')))->toBeTrue()
        ->and($offer->expires_at->equalTo(qatar('2026-11-22 08:00')))->toBeTrue()
        ->and(Notification::where('event', 'delivery.slots_offered')->count())->toBe(0);

    $this->travelTo(qatar('2026-11-20 07:55'));
    Artisan::call('deliveries:process-slot-offers');
    expect(Notification::where('event', 'delivery.slots_offered')->count())->toBe(0);

    $this->travelTo(qatar('2026-11-20 08:01'));
    Artisan::call('deliveries:process-slot-offers');
    Artisan::call('deliveries:process-slot-offers');
    expect(Notification::where('user_id', $x->customer->id)->where('event', 'delivery.slots_offered')->count())->toBe(1)->and($offer->fresh()->notified_at)->not->toBeNull();
});

it('respects the notification controls: switched off, the offer still exists but nothing is sent or stored', function () {
    app(NotificationRules::class)->set('delivery.slots_offered', false);
    $x = fuSlotWorld();
    $offer = fuFail($x);

    expect($offer)->not->toBeNull()->and(Notification::where('event', 'delivery.slots_offered')->count())->toBe(0);
});

it('makes one open offer per delivery, however often the failure is processed', function () {
    $x = fuSlotWorld();
    fuFail($x);

    expect(app(SlotOffers::class)->offerAfterFailure($x->delivery->id))->toBeNull()->and(DeliverySlotOffer::where('delivery_id', $x->delivery->id)->count())->toBe(1);
});

it('stops offering by itself after the third failed attempt', function () {
    $x = fuSlotWorld();
    Delivery::whereKey($x->delivery->id)->update(['attempt_count' => 3]); // this failure is the fourth

    expect(fuFail($x))->toBeNull();
});

// ── What the customer sees ───────────────────────────────────────────────────────────────────────────────────────────

it('shows the customer the times on their order, with live availability, and nobody else', function () {
    $x = fuSlotWorld(capacity: 1);
    $offer = fuFail($x);

    $card = fuDetail($x)['fulfillments'][0]['slot_offer'];
    expect($card)->toMatchArray(['id' => $offer->id, 'status' => 'open', 'source' => 'system', 'can_choose' => true, 'chosen' => null])
        ->and($card['options'])->toHaveCount(3)->and($card['options'][0])->toMatchArray(['date' => '2026-11-19', 'starts_at' => '16:00', 'ends_at' => '18:00', 'available' => true, 'label' => 'Thu 19 Nov, 16:00-18:00']);

    // the first time is taken by somebody else in the meantime: it is marked, the others stay
    DeliverySlotBooking::create(['delivery_slot_id' => $x->slots[4]->id, 'slot_date' => '2026-11-19', 'booked' => 1]);
    $card = fuDetail($x)['fulfillments'][0]['slot_offer'];
    expect($card['options'][0]['available'])->toBeFalse()->and($card['options'][1]['available'])->toBeTrue()->and($card['can_choose'])->toBeTrue();

    // another customer's order does not exist for them
    actingAsCustomer(User::factory()->create());
    $this->getJson("/api/v1/orders/{$x->orderId}")->assertNotFound();
});

it('says the offer has run out once its time has passed, even before the job closed it', function () {
    $x = fuSlotWorld();
    fuFail($x);
    $this->travelTo(qatar('2026-11-21 12:01')); // 48 hours later

    $card = fuDetail($x)['fulfillments'][0]['slot_offer'];
    expect($card['status'])->toBe('expired')->and($card['can_choose'])->toBeFalse()->and($card['options'])->toBe([]);
});

// ── Choosing ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('moves the booking and the delivery when the customer chooses a time, and tells the branch and the driver', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $time = $offer->options[1]; // Friday 16:00

    $r = fuChoose($x, $offer, $time, 'choose-key-0000000001')->assertOk();

    $r->assertJsonPath('data.status', 'chosen')->assertJsonPath('data.replayed', false)->assertJsonPath('data.order.fulfillments.0.slot_offer.status', 'chosen')
        ->assertJsonPath('data.order.fulfillments.0.slot_offer.chosen.label', 'Fri 20 Nov, 16:00-18:00')->assertJsonPath('data.order.fulfillments.0.slot.date', '2026-11-20');
    $delivery = $x->delivery->fresh();
    $f = $delivery->fulfillment;
    expect($delivery->status->value)->toBe('rescheduled')->and($delivery->scheduled_for->equalTo(qatar('2026-11-20 16:00')))->toBeTrue()
        ->and($f->delivery_slot_id)->toBe($time['slot_id'])->and($f->slot_date->toDateString())->toBe('2026-11-20')
        ->and($offer->fresh())->status->toBe('chosen')->chosen_key->toBe('choose-key-0000000001')
        ->and(DeliverySlotBooking::where('delivery_slot_id', $time['slot_id'])->whereDate('slot_date', '2026-11-20')->value('booked'))->toBe(1);
    expect(DB::table('delivery_status_history')->where('delivery_id', $delivery->id)->where('to_status', 'rescheduled')->value('source'))->toBe('customer');

    // the audit trail names the customer; the branch managers and the driver get an inbox alert with the time
    $audit = AuditLog::where('action', 'delivery.slot_chosen')->firstOrFail();
    expect($audit->actor_id)->toBe($x->customer->id);
    $told = Notification::where('event', 'staff.slot_chosen')->pluck('user_id')->all();
    expect($told)->toContain($x->driver->id)->toContain($x->mgr->id);
    expect(Notification::where('event', 'staff.slot_chosen')->where('user_id', $x->driver->id)->value('body'))->toContain('Fri 20 Nov, 16:00-18:00')->toContain($x->delivery->fresh()->fulfillment->order->number)
        ->and(Notification::where('event', 'staff.slot_chosen')->pluck('body')->implode(' '))->not->toContain('Noor');
});

it('does nothing twice for the same key, and refuses a second pick with another key', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $time = $offer->options[1];

    fuChoose($x, $offer, $time, 'same-key-000000000001')->assertOk()->assertJsonPath('data.replayed', false);
    fuChoose($x, $offer, $time, 'same-key-000000000001')->assertOk()->assertJsonPath('data.replayed', true);
    expect(DeliverySlotBooking::where('delivery_slot_id', $time['slot_id'])->whereDate('slot_date', '2026-11-20')->value('booked'))->toBe(1)
        ->and(Notification::where('event', 'staff.slot_chosen')->where('user_id', $x->driver->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'delivery.slot_chosen')->count())->toBe(1);

    fuChoose($x, $offer, $offer->options[2], 'another-key-00000000002')->assertStatus(409)->assertJsonPath('code', 'offer_already_chosen');
    fuChoose($x, $offer, $time, 'another-key-00000000003')->assertStatus(409)->assertJsonPath('code', 'offer_already_chosen');
});

it('refuses an offer that ran out, with a plain message, and leaves the delivery as it was', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $this->travelTo(qatar('2026-11-21 12:01'));

    fuChoose($x, $offer, $offer->options[1])->assertStatus(409)->assertJsonPath('code', 'offer_expired')
        ->assertJsonPath('message', 'This offer has run out. We will call you to arrange a new time.');
    expect($x->delivery->fresh()->status->value)->toBe('failed')->and($offer->fresh()->status)->toBe('expired');
});

it('refuses a time that filled up since, keeps the offer open, and lets the customer pick another', function () {
    $x = fuSlotWorld(capacity: 1);
    $offer = fuFail($x);
    DeliverySlotBooking::create(['delivery_slot_id' => $x->slots[5]->id, 'slot_date' => '2026-11-20', 'booked' => 1]);

    fuChoose($x, $offer, $offer->options[1])->assertStatus(409)->assertJsonPath('code', 'slot_full');
    expect($offer->fresh()->status)->toBe('open')->and($x->delivery->fresh()->status->value)->toBe('failed');

    fuChoose($x, $offer, $offer->options[2])->assertOk();
    expect($x->delivery->fresh()->status->value)->toBe('rescheduled');
});

it('refuses a time that was not offered, a missing key and a bad date', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);

    fuChoose($x, $offer, ['slot_id' => $x->slots[1]->id, 'date' => '2026-11-23'])->assertStatus(422)->assertJsonPath('code', 'offer_time_not_offered');
    actingAsCustomer($x->customer);
    $this->postJson("/api/v1/orders/{$x->orderId}/slot-offers/{$offer->id}/choose", $offer->options[0])->assertStatus(400);
    fuChoose($x, $offer, ['slot_id' => 'nope', 'date' => '2026-11-23'])->assertStatus(422);
    fuChoose($x, $offer, ['slot_id' => $offer->options[0]['slot_id'], 'date' => 'tomorrow'])->assertStatus(422);
    expect($offer->fresh()->status)->toBe('open');
});

it('answers 404 to another customer, to an unknown offer and to an offer of another order', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $other = User::factory()->create();

    fuChoose($x, $offer, $offer->options[0], null, $other)->assertNotFound();
    expect($offer->fresh()->status)->toBe('open')->and($x->delivery->fresh()->status->value)->toBe('failed');

    actingAsCustomer($x->customer);
    $this->postJson("/api/v1/orders/{$x->orderId}/slot-offers/".Str::uuid().'/choose', $offer->options[0], ['Idempotency-Key' => 'unknown-offer-000000001'])->assertNotFound();
    $this->postJson('/api/v1/orders/'.Str::uuid()."/slot-offers/{$offer->id}/choose", $offer->options[0], ['Idempotency-Key' => 'unknown-order-000000001'])->assertNotFound();
    $this->postJson("/api/v1/orders/{$x->orderId}/slot-offers/not-a-uuid/choose", $offer->options[0], ['Idempotency-Key' => 'unknown-offer-000000002'])->assertNotFound();
});

it('refuses a pick once the branch has arranged another time', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $mgr = actingAsStaff($x->mgr);
    app(DeliveryService::class)->rescheduleToSlot($x->delivery->fresh(), $mgr, $x->slots[1]->id, '2026-11-23');

    expect($offer->fresh())->status->toBe('cancelled')->closed_reason->toBe('rescheduled_by_branch');
    fuChoose($x, $offer, $offer->options[0])->assertStatus(409)->assertJsonPath('code', 'offer_closed');
});

it('closes the offer when the driver tries again', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    actingAsStaff($x->driver);
    $this->postJson("/api/v1/admin/deliveries/{$x->delivery->id}/out-for-delivery")->assertOk();
    expect($offer->fresh())->status->toBe('cancelled')->closed_reason->toBe('tried_again');
});

it('closes the offer when the goods go back to the branch', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $this->postJson("/api/v1/admin/deliveries/{$x->delivery->id}/return")->assertOk();

    expect($offer->fresh())->status->toBe('cancelled')->closed_reason->toBe('delivery_moved_on');
});

it('closes offers whose time ran out when the job runs, once', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $this->travelTo(qatar('2026-11-21 12:01'));

    Artisan::call('deliveries:process-slot-offers');
    Artisan::call('deliveries:process-slot-offers');

    expect($offer->fresh())->status->toBe('expired')->closed_reason->toBe('expired')
        ->and(AuditLog::where('action', 'delivery.slots_expired')->count())->toBe(1);
});

// ── The branch offers times itself ───────────────────────────────────────────────────────────────────────────────────

it('lets the branch offer times from the dashboard: replaces an open offer, needs the right, a failed delivery and times that are free now', function () {
    $x = fuSlotWorld();
    $first = fuFail($x);
    $mgr = actingAsStaff($x->mgr);
    $svc = app(SlotOffers::class);
    $pick = [['slot_id' => $x->slots[1]->id, 'date' => '2026-11-23'], ['slot_id' => $x->slots[2]->id, 'date' => '2026-11-24']];

    $offer = $svc->propose($x->delivery->fresh(), $mgr, $pick);

    expect($offer->source)->toBe('branch')->and($offer->offered_by)->toBe($mgr->id)->and($offer->options)->toEqual($pick)->and($first->fresh())->status->toBe('cancelled')->closed_reason->toBe('replaced')
        ->and(Notification::where('user_id', $x->customer->id)->where('event', 'delivery.slots_offered')->count())->toBe(2);

    // another branch's manager, a driver without dispatch rights
    expect(fn () => $svc->propose($x->delivery->fresh(), staffWithRole('branch_manager', $x->w->b), $pick))->toThrow(DomainException::class);
    // no time, too many, a time that is not free
    expect(fn () => $svc->propose($x->delivery->fresh(), $mgr, []))->toThrow(DomainException::class);
    $six = collect(range(0, 5))->map(fn ($i) => ['slot_id' => $x->slots[$i]->id, 'date' => '2026-11-'.(23 + $i)])->all();
    expect(fn () => $svc->propose($x->delivery->fresh(), $mgr, $six))->toThrow(DomainException::class);
    expect(fn () => $svc->propose($x->delivery->fresh(), $mgr, [['slot_id' => $x->slots[1]->id, 'date' => '2026-11-22']]))->toThrow(DomainException::class); // wrong weekday: not a slot
});

it('refuses to offer times for a delivery that did not fail', function () {
    $x = fuSlotWorld();
    $mgr = actingAsStaff($x->mgr);

    expect(fn () => app(SlotOffers::class)->propose($x->delivery->fresh(), $mgr, [['slot_id' => $x->slots[1]->id, 'date' => '2026-11-23']]))->toThrow(DomainException::class);
});

it('lets the customer pick a time the branch offered', function () {
    $x = fuSlotWorld();
    app(Settings::class)->set('delivery.slot_offers_enabled', false);
    fuFail($x);
    $mgr = actingAsStaff($x->mgr);
    $offer = app(SlotOffers::class)->propose($x->delivery->fresh(), $mgr, [['slot_id' => $x->slots[1]->id, 'date' => '2026-11-23']]);

    fuChoose($x, $offer, $offer->options[0])->assertOk();
    expect($x->delivery->fresh()->status->value)->toBe('rescheduled');
});

it('changes what the live order card says: choose a new time while the offer is open, booked once chosen, and the old words again when it runs out', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $body = fn () => fuDetail($x)['progress']['body'];

    expect($body())->toBe('We could not deliver your order. Open it to choose a new delivery time.');
    fuChoose($x, $offer, $offer->options[1])->assertOk();
    expect($body())->toBe('We could not deliver your order. Your new delivery time is booked.');
});

it('keeps the old words on the live card when there was nothing to offer, and when the offer ran out', function () {
    $x = fuSlotWorld();
    fuFail($x);
    $this->travelTo(qatar('2026-11-21 12:01'));

    expect(fuDetail($x)['progress']['body'])->toBe('We could not deliver your order. We will call you to arrange a new time.');
});
