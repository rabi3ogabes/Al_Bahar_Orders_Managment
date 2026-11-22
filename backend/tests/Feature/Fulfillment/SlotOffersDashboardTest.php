<?php

use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Fulfillment\Models\DeliverySlotOffer;
use App\Domain\Fulfillment\Services\DeliveryFollowUpSettings;
use App\Domain\Fulfillment\Services\SlotOffers;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Operations\Services\LiveBoard;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\SettingsIndex;
use App\Livewire\Admin\Sales\DeliveryFollowUp;
use App\Livewire\Admin\Sales\DeliveryIndex;
use App\Livewire\Admin\Sales\OrderShow;
use Livewire\Livewire;

require_once __DIR__.'/SlotOfferSupport.php';

/*
| What the branch sees about the new times offered after a failed delivery: on the order, in "Needs attention", on the live board and in the deliveries
| list; and the "Offer new times" window. The clock is frozen on Thursday 19 Nov 2026, midday in Qatar.
*/

function fuPart(object $x): string
{
    return $x->delivery->fresh()->order_fulfillment_id;
}

function fuFailedLine(object $x): ?string
{
    return app(AttentionFeed::class)->for($x->mgr)->items->firstWhere('category', 'delivery_failed')?->note;
}

it('says on the order that the customer is choosing, until when', function () {
    $x = fuSlotWorld();
    fuFail($x);

    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])
        ->assertSee('New delivery time')->assertSee('Waiting for the customer to choose a new time')->assertSee('Sat 21 Nov, 12:00')->assertSee('Offer new times');
});

it('says what the customer chose, and that an offer ran out, and that there was nothing to offer', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $c = Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)]);

    $this->travelTo(qatar('2026-11-21 12:01'));
    $c->call('$refresh')->assertSee('The customer did not choose in time. Call them or offer new times.');

    $this->travelTo(qatar('2026-11-19 12:30'));
    $offer->update(['expires_at' => now()->addHours(5)]);
    fuChoose($x, $offer->fresh(), $offer->options[1])->assertOk();
    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])->assertSee('Customer chose Fri 20 Nov, 16:00-18:00')->assertDontSee('Offer new times');
});

it('says nothing was offered when offers are switched off, and still lets the branch offer', function () {
    app(Settings::class)->set('delivery.slot_offers_enabled', false);
    $x = fuSlotWorld();
    fuFail($x);

    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])->assertSee('switched off')->assertSee('Offer new times');
});

it('lets a manager offer times: the system\'s picks are ticked, five at most, and sending tells the customer', function () {
    $x = fuSlotWorld();
    fuFail($x);
    $part = fuPart($x);

    $c = Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => $part])->call('openOffer')->assertSet('showOffer', true);
    expect($c->get('picked'))->toHaveCount(3);

    // untick one, tick others up to five, the sixth is refused
    $first = $c->get('picked')[0];
    $c->call('toggle', $first);
    expect($c->get('picked'))->toHaveCount(2)->and($c->get('picked'))->not->toContain($first);
    foreach (range(1, 6) as $dow) { // 22 Nov 2026 is a Sunday, so weekday n falls on the (22 + n)th
        $c->call('toggle', $x->slots[$dow]->id.'|2026-11-'.(22 + $dow));
    }
    expect($c->get('picked'))->toHaveCount(5);

    $c->call('sendOffer')->assertSet('showOffer', false)->assertHasNoErrors();
    $offer = DeliverySlotOffer::where('delivery_id', $x->delivery->id)->where('status', 'open')->firstOrFail();
    expect($offer->source)->toBe('branch')->and($offer->options)->toHaveCount(5)->and($offer->offered_by)->toBe($x->mgr->id)
        ->and(Notification::where('user_id', $x->customer->id)->where('event', 'delivery.slots_offered')->count())->toBe(2);
});

it('does not let the branch send an empty offer', function () {
    $x = fuSlotWorld();
    fuFail($x);

    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])->call('openOffer')->set('picked', [])->call('sendOffer')->assertSet('showOffer', true);
    expect(DeliverySlotOffer::where('source', 'branch')->count())->toBe(0);
});

it('offers the button only to people who may send drivers, and only for a failed delivery', function () {
    $x = fuSlotWorld();
    $viewer = opsStaffWith($x->w->a, ['orders.view', 'deliveries.view']);

    // before the delivery failed: no button, nothing to say
    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])->assertDontSee('Offer new times');
    fuFail($x);
    Livewire::actingAs($viewer)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])->assertSee('Waiting for the customer')->assertDontSee('Offer new times')
        ->call('openOffer')->assertForbidden();
    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])->assertSee('Offer new times');
});

it('keeps another branch out, and an outsider cannot call the actions', function () {
    $x = fuSlotWorld();
    fuFail($x);
    $other = staffWithRole('branch_manager', $x->w->b);

    Livewire::actingAs($other)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])->assertForbidden();
});

it('is part of the order window, for the part that has a delivery', function () {
    $x = fuSlotWorld();
    fuFail($x);

    Livewire::actingAs($x->mgr)->test(OrderShow::class, ['order' => $x->orderId, 'drawer' => true])->assertSeeLivewire(DeliveryFollowUp::class)->assertSee('Waiting for the customer to choose a new time');
});

it('adds the offer to the line of the failed delivery in "Needs attention"', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);

    expect(fuFailedLine($x))->toContain('Customer not home')->toContain('Waiting for the customer to choose a new time (open until Sat 21 Nov, 12:00)');

    $this->travelTo(qatar('2026-11-21 12:01'));
    expect(fuFailedLine($x))->toContain('The customer did not choose in time. Call them or offer new times.');

    // once the customer chose, the delivery is back in the queue and the line is gone
    $this->travelTo(qatar('2026-11-19 12:30'));
    $offer->update(['expires_at' => now()->addHours(5)]);
    fuChoose($x, $offer->fresh(), $offer->options[1])->assertOk();
    expect(app(AttentionFeed::class)->for($x->mgr)->items->firstWhere('category', 'delivery_failed'))->toBeNull();
});

it('adds "nothing free to offer" to the line when the branch had no slot', function () {
    $x = fuSlotWorld();
    DeliverySlot::query()->update(['is_active' => false]);
    fuFail($x);

    expect(fuFailedLine($x))->toContain('No free times to offer. Call the customer.');
});

it('adds the offer and the choice to the card on the live board', function () {
    $x = fuSlotWorld();
    $offer = fuFail($x);
    $card = fn () => collect(app(LiveBoard::class)->for($x->mgr)->lanes)->flatMap(fn (array $lane) => $lane['cards'])->first(fn ($c) => $c->reason === 'failed');

    expect($card()->note)->toContain('Customer not home')->toContain('Waiting for the customer to choose a new time');

    fuChoose($x, $offer, $offer->options[1])->assertOk();
    expect($card()->note)->toContain('Customer chose Fri 20 Nov, 16:00-18:00');
});

it('shows the line in the deliveries list, on the exceptions tab', function () {
    $x = fuSlotWorld();
    fuFail($x);

    Livewire::actingAs($x->mgr)->withQueryParams(['lane' => 'exceptions'])->test(DeliveryIndex::class)->assertSee('Waiting for the customer to choose a new time');
});

it('shows the line to a branch-limited manager only for their own branch', function () {
    $x = fuSlotWorld();
    fuFail($x);
    $other = staffWithRole('branch_manager', $x->w->b);

    expect(app(AttentionFeed::class)->for($other)->items->where('category', 'delivery_failed')->all())->toBe([]);
    Livewire::actingAs($other)->withQueryParams(['lane' => 'exceptions'])->test(DeliveryIndex::class)->assertDontSee('Waiting for the customer');
});

it('has every word in Arabic: the order line, the window and the settings', function () {
    $x = fuSlotWorld();
    fuFail($x);
    app()->setLocale('ar');

    Livewire::actingAs($x->mgr)->test(DeliveryFollowUp::class, ['fulfillmentId' => fuPart($x)])->assertSee('موعد التوصيل الجديد')->assertSee('عرض مواعيد جديدة')->assertDontSee('delivery_followup.');
    foreach (['slot_offers_enabled', 'slot_offer_count', 'slot_offer_hours', 'proof_for_customer', 'assembly_answer_required'] as $field) {
        expect(__('delivery_followup.setting.'.$field))->not->toStartWith('delivery_followup')->and(__('delivery_followup.setting_hint.'.$field))->not->toStartWith('delivery_followup');
    }
    expect(SlotOffers::when('2026-11-20', '16:00', '18:00', 'ar'))->toContain('16:00');
});

it('lists the five switches under Settings, Checkout & fees, with their defaults, in English and Arabic', function () {
    $owner = User::factory()->superAdmin()->create();
    Livewire::actingAs($owner)->test(SettingsIndex::class)->set('tab', 'checkout')
        ->assertSee('Offer new times after a failed delivery')->assertSee('How many times to offer')->assertSee('How long an offer stays open')
        ->assertSee('Show the proof of delivery to customers')->assertSee('Driver must answer for paid assembly')
        ->assertSet('values.checkout.delivery__slot_offers_enabled', true)->assertSet('values.checkout.delivery__slot_offer_count', '3')->assertSet('values.checkout.delivery__slot_offer_hours', '48')
        ->assertSet('values.checkout.delivery__proof_for_customer', true)->assertSet('values.checkout.delivery__assembly_answer_required', true);

    app()->setLocale('ar');
    Livewire::actingAs($owner)->test(SettingsIndex::class)->set('tab', 'checkout')->assertSee('عرض مواعيد جديدة بعد تعذّر التوصيل')->assertDontSee('delivery_followup.');
});

it('saves the switches and the platform follows them at once, and refuses values out of range', function () {
    $owner = User::factory()->superAdmin()->create();
    Livewire::actingAs($owner)->test(SettingsIndex::class)->set('tab', 'checkout')
        ->set('values.checkout.delivery__slot_offers_enabled', false)->set('values.checkout.delivery__slot_offer_count', 2)->set('values.checkout.delivery__slot_offer_hours', 24)
        ->set('values.checkout.delivery__proof_for_customer', false)->set('values.checkout.delivery__assembly_answer_required', false)->call('save')->assertHasNoErrors();

    $c = app(DeliveryFollowUpSettings::class);
    expect($c->offersEnabled())->toBeFalse()->and($c->offerCount())->toBe(2)->and($c->offerHours())->toBe(24)->and($c->proofForCustomer())->toBeFalse()->and($c->assemblyAnswerRequired())->toBeFalse();

    foreach ([['delivery__slot_offer_count', 0], ['delivery__slot_offer_count', 6], ['delivery__slot_offer_hours', 0], ['delivery__slot_offer_hours', 337]] as [$key, $value]) {
        Livewire::actingAs($owner)->test(SettingsIndex::class)->set('tab', 'checkout')->set("values.checkout.{$key}", $value)->call('save')->assertHasErrors();
    }
});
