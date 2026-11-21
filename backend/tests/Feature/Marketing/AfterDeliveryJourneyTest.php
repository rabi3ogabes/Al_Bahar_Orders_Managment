<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Marketing\Models\JourneyStep;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Services\AfterDeliveryJourney;
use App\Domain\Marketing\Services\JourneyService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Support\Models\SupportTicket;
use App\Livewire\Admin\Marketing\JourneyIndex;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| The "After delivery" journey: a thank-you, a review request and an optional "goes well with it" message for each delivered order. Time stands still at a
| Thursday noon in Qatar; tests that need the quiet hours move it there on purpose.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Qatar'));
    Cache::flush();
    $this->w = commerceWorld();
    $this->customer = $this->w->customer;
    $this->customer->update(['marketing_consent_at' => now(), 'locale' => 'en', 'name' => 'Noor Ali']);
    $this->journeys = app(JourneyService::class);
    $this->journeys->ensureDefaults();
    $this->journey = Journey::where('key', 'after_delivery')->firstOrFail();
    $this->delivered = fn (array $o = []) => deliveredOrderFor($this, $o);
});

/**
 * Places an order for the signed-in customer and marks it delivered `$minutesAgo` minutes ago with every unit handed over.
 *
 * @param  array{variant?: object, units?: int, minutes_ago?: int, user?: User}  $o
 */
function deliveredOrderFor(object $t, array $o = []): Order
{
    $variant = $o['variant'] ?? $t->w->v1;
    test()->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => $o['units'] ?? 1])->assertCreated();
    $placed = quoteAndPlace(['address_id' => $t->w->address->id, 'payment_method' => 'cod']);
    expect($placed->status())->toBe(201, $placed->getContent());
    $order = Order::find($placed->json('data.order.id'));
    $order->fulfillments()->update(['status' => 'delivered', 'delivered_at' => now()->subMinutes($o['minutes_ago'] ?? 0)]);
    $order->items()->update(['quantity_handed_over' => DB::raw('quantity')]);
    $order->forceFill(['status' => 'completed', 'completed_at' => now()->subMinutes($o['minutes_ago'] ?? 0)])->save();

    return $order->fresh();
}

function switchOnAfterDelivery(object $t, bool $suggest = false): void
{
    $t->journey->update(['is_enabled' => true]);
    if ($suggest) {
        $t->journey->steps()->where('kind', 'suggest')->update(['is_enabled' => true]);
    }
}

function adMessages(User $u)
{
    return Notification::where('user_id', $u->id)->where('event', 'journey.after_delivery')->orderBy('created_at')->get();
}

// ── The journey itself ───────────────────────────────────────────────────────────────────────────────────────────

it('exists from the first visit, switched off, with its three messages in English and Arabic and the optional one off', function () {
    $journey = Journey::where('key', 'after_delivery')->with('steps.template')->firstOrFail();
    expect($journey->is_enabled)->toBeFalse()->and($journey->steps)->toHaveCount(3)->and($journey->setting('quiet_start'))->toBe('22:00')->and($journey->setting('quiet_end'))->toBe('08:00')
        ->and($journey->steps->pluck('kind')->all())->toBe(['thank_you', 'review', 'suggest'])
        ->and($journey->steps->pluck('delay_minutes')->all())->toBe([120, 5760, 10080])
        ->and($journey->steps->pluck('is_enabled')->all())->toBe([true, true, false]);
    foreach ($journey->steps as $step) {
        expect($step->template->title_en)->not->toBe('')->and($step->template->title_ar)->not->toBe('')->and($step->template->body_en)->not->toBe('')->and($step->template->body_ar)->not->toBe('')
            ->and($step->channels)->toBe(['push', 'in_app']);
    }
    $this->journeys->ensureDefaults();
    $this->journeys->ensureDefaults();
    expect(Journey::where('key', 'after_delivery')->count())->toBe(1)->and(JourneyStep::where('journey_id', $journey->id)->count())->toBe(3);
});

it('sends nothing while it is switched off, and makes no run for orders that are not delivered', function () {
    $order = ($this->delivered)(['minutes_ago' => 600]);
    $this->journeys->tick();
    expect(JourneyRun::where('journey_id', $this->journey->id)->count())->toBe(0)->and(adMessages($this->customer))->toHaveCount(0);

    switchOnAfterDelivery($this);
    $pending = Order::find(placePendingOrder($this));
    $this->journeys->tick();
    expect(JourneyRun::where('journey_id', $this->journey->id)->pluck('trigger_order_id')->all())->toBe([$order->id])->and($pending->status->value)->not->toBe('completed');
});

function placePendingOrder(object $t): string
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $t->w->v2->id, 'quantity' => 1])->assertCreated();

    return quoteAndPlace(['address_id' => $t->w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
}

it('makes one run per delivered order however often the scheduler looks, and says thank you after the delay, not before', function () {
    switchOnAfterDelivery($this);
    $order = ($this->delivered)(['minutes_ago' => 30]);
    foreach (range(1, 4) as $i) {
        $this->journeys->tick();
    }
    $run = JourneyRun::where('trigger_order_id', $order->id)->firstOrFail();
    expect(JourneyRun::where('journey_id', $this->journey->id)->count())->toBe(1)->and($run->anchor_at->equalTo($order->completed_at))->toBeTrue()->and(adMessages($this->customer))->toHaveCount(0);

    $this->travelTo(now()->addMinutes(95)); // 2 hours 5 minutes after the delivery
    $this->journeys->tick();
    $this->journeys->tick();
    $messages = adMessages($this->customer);
    expect($messages)->toHaveCount(1)->and($messages[0]->title)->toBe('Thank you, Noor')->and($messages[0]->body)->toContain($order->number)->and($messages[0]->data['deep_link'])->toBe('albahar://orders/'.$order->id);
    expect(JourneyDelivery::where('run_id', $run->id)->where('status', 'sent')->count())->toBe(1);
});

it('asks for a review of a product they received and have not reviewed, deep-linked to the review window, in Arabic for an Arabic customer', function () {
    switchOnAfterDelivery($this);
    $this->customer->update(['locale' => 'ar']);
    $order = ($this->delivered)(['minutes_ago' => 60 * 24 * 4 + 30]);
    $this->journeys->tick();
    $messages = adMessages($this->customer);
    $product = $this->w->p1;
    expect($messages)->toHaveCount(1)->and($messages[0]->title)->toBe('كيف وجدت '.$product->trans('name', 'ar').'؟')
        ->and($messages[0]->data['deep_link'])->toBe('albahar://products/'.$product->id.'?review=1');
    expect($order->id)->not->toBeNull();
});

it('skips the review request for a product that is already reviewed, and keeps the next message', function () {
    switchOnAfterDelivery($this, suggest: true);
    $order = ($this->delivered)(['minutes_ago' => 60 * 24 * 4 + 30]);
    Review::create(['product_id' => $this->w->p1->id, 'user_id' => $this->customer->id, 'rating' => 5, 'status' => 'pending', 'is_verified_purchase' => true]);
    $this->journeys->tick();
    expect(adMessages($this->customer)->where('title', 'like', 'How is your%'))->toHaveCount(0);
    $run = JourneyRun::where('trigger_order_id', $order->id)->firstOrFail();
    $review = $this->journey->steps()->where('kind', 'review')->firstOrFail();
    expect(JourneyDelivery::where('run_id', $run->id)->where('step_id', $review->id)->pluck('reason')->unique()->all())->toBe(['nothing_to_review']);
});

it('suggests up to three products that go with it, never ones the customer already has, and only when the owner switched the message on', function () {
    $extras = collect(range(1, 5))->map(function () {
        $p = Product::factory()->create(['price' => '80.00']);
        app(InventoryLedger::class)->apply($this->w->a->id, $p->variants()->first()->id, MovementType::OpeningBalance, 5);

        return $p;
    });
    foreach ($extras as $i => $p) {
        DB::table('product_related')->insert(['product_id' => $this->w->p1->id, 'related_product_id' => $p->id, 'type' => 'upsell', 'sort_order' => $i]);
    }
    DB::table('product_related')->insert(['product_id' => $this->w->p1->id, 'related_product_id' => $this->w->p2->id, 'type' => 'upsell', 'sort_order' => 9]);
    // The customer already bought the first suggestion (and p2) in an earlier order.
    $earlier = ($this->delivered)(['variant' => $extras[0]->variants()->first(), 'minutes_ago' => 60 * 24 * 40]);
    ($this->delivered)(['variant' => $this->w->v2, 'minutes_ago' => 60 * 24 * 39]);
    $order = ($this->delivered)(['minutes_ago' => 60 * 24 * 7 + 30]);
    $this->journey->update(['is_enabled' => true]);

    $this->journeys->tick(); // the suggest step is off: no message of that kind
    expect(adMessages($this->customer)->where('title', 'Goes well with your order'))->toHaveCount(0);

    $this->journey->steps()->where('kind', 'suggest')->update(['is_enabled' => true]);
    $this->journeys->wake($this->journey);
    DB::table('journey_runs')->where('trigger_order_id', $order->id)->update(['status' => 'active', 'finished_at' => null, 'next_at' => now()]);
    $this->journeys->tick();
    $m = adMessages($this->customer)->where('title', 'Goes well with your order')->first();
    expect($m)->not->toBeNull();
    $named = $extras->filter(fn ($p) => str_contains($m->body, $p->trans('name')));
    expect($named)->toHaveCount(3)->and($named->pluck('id')->contains($extras[0]->id))->toBeFalse()->and($m->body)->not->toContain($this->w->p2->trans('name'))->and($m->body)->toContain(' and ');
    expect($earlier->id)->not->toBe($order->id);
});

// ── Who is left out ──────────────────────────────────────────────────────────────────────────────────────────────

it('leaves out, for good, an order that was refunded, had a failed delivery or has a return or damage claim', function (string $why) {
    switchOnAfterDelivery($this);
    $order = ($this->delivered)(['minutes_ago' => 60 * 24 * 4 + 200]);
    match ($why) {
        'refunded' => $order->forceFill(['paid_total' => '1000.00', 'refunded_total' => '100.00'])->save(),
        'return_claim' => ReturnRequest::create(['number' => 'RR-'.Str::random(6), 'order_id' => $order->id, 'user_id' => $this->customer->id, 'branch_id' => $this->w->a->id, 'status' => 'requested',
            'reason_code' => 'damaged', 'items' => []]),
        'delivery_problem' => DB::table('deliveries')->insert(['id' => (string) Str::uuid(), 'order_fulfillment_id' => $order->fulfillments()->value('id'), 'branch_id' => $this->w->a->id, 'mode' => 'internal',
            'status' => 'delivered', 'tracking_number' => 'T'.Str::random(8), 'failed_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]),
    };
    $this->journeys->tick();
    $run = JourneyRun::where('trigger_order_id', $order->id)->firstOrFail();
    expect($run->status)->toBe('stopped')->and($run->stop_reason)->toBe($why)->and(adMessages($this->customer))->toHaveCount(0);
})->with(['refunded', 'return_claim', 'delivery_problem']);

it('does nothing for an order that was returned or cancelled, and ends a run when its order is returned before the next message', function () {
    switchOnAfterDelivery($this);
    $returned = ($this->delivered)(['minutes_ago' => 60]);
    $returned->forceFill(['status' => 'returned'])->save();
    $this->journeys->tick();
    expect(JourneyRun::where('trigger_order_id', $returned->id)->exists())->toBeFalse()->and(adMessages($this->customer))->toHaveCount(0);

    $order = ($this->delivered)(['variant' => $this->w->v2, 'minutes_ago' => 30]);
    $this->journeys->tick();
    $order->forceFill(['status' => 'returned'])->save();                              // returned after the run started
    $this->travelTo(now()->addHours(3));
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(0)->and(JourneyRun::where('trigger_order_id', $order->id)->value('stop_reason'))->toBe('order_changed');
});

it('stops a run that is under way when a claim is opened or the order is returned before the next message', function () {
    switchOnAfterDelivery($this);
    $order = ($this->delivered)(['minutes_ago' => 30]);
    $this->journeys->tick();
    $this->travelTo(now()->addHours(3));
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(1);                              // the thank-you went

    ReturnRequest::create(['number' => 'RR-'.Str::random(6), 'order_id' => $order->id, 'user_id' => $this->customer->id, 'branch_id' => $this->w->a->id, 'status' => 'under_review',
        'reason_code' => 'damaged', 'items' => []]);
    $this->travelTo(now()->addDays(4));                                               // the review request is due
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(1)->and(JourneyRun::where('trigger_order_id', $order->id)->value('stop_reason'))->toBe('return_claim');
});

it('skips a message while a support ticket is open, and sends the next one once it is closed', function () {
    switchOnAfterDelivery($this);
    $order = ($this->delivered)(['minutes_ago' => 30]);
    $ticket = SupportTicket::create(['number' => 'T-'.Str::random(6), 'user_id' => $this->customer->id, 'order_id' => $order->id, 'category' => 'order', 'subject' => 'Late', 'status' => 'open']);
    $this->journeys->tick();
    $this->travelTo(now()->addHours(3));
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(0);                              // the thank-you was skipped, not sent late

    $ticket->update(['status' => 'closed']);
    $this->travelTo(now()->addDays(4));
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(1)->and(adMessages($this->customer)[0]->title)->toStartWith('How is your');
    $thankYou = $this->journey->steps()->where('kind', 'thank_you')->firstOrFail();
    expect(JourneyDelivery::where('step_id', $thankYou->id)->pluck('reason')->unique()->all())->toBe(['open_ticket']);
});

it('does not start the journey again for a second order delivered within 14 days, but does after that', function () {
    switchOnAfterDelivery($this);
    $first = ($this->delivered)(['minutes_ago' => 60 * 24 * 3]);
    $second = ($this->delivered)(['variant' => $this->w->v2, 'minutes_ago' => 60]);
    $this->journeys->tick();
    $runs = JourneyRun::where('journey_id', $this->journey->id)->get()->keyBy('trigger_order_id');
    expect($runs[$first->id]->status)->not->toBe('stopped')->and($runs[$second->id]->status)->toBe('stopped')->and($runs[$second->id]->stop_reason)->toBe('recent_journey');

    $third = ($this->delivered)(['minutes_ago' => 10]);
    DB::table('journey_runs')->where('trigger_order_id', $first->id)->update(['anchor_at' => now()->subDays(20)]);
    $this->journeys->tick();
    expect(JourneyRun::where('trigger_order_id', $third->id)->value('status'))->toBe('active');
});

it('respects consent: a customer who has not agreed gets nothing, and the reason is on record', function () {
    switchOnAfterDelivery($this);
    $this->customer->update(['marketing_consent_at' => null]);
    $order = ($this->delivered)(['minutes_ago' => 600]);
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(0)->and(JourneyDelivery::where('user_id', $this->customer->id)->pluck('reason')->unique()->all())->toBe(['no_marketing_consent']);
    expect($order->id)->not->toBeNull();
});

// ── Timing ───────────────────────────────────────────────────────────────────────────────────────────────────────

it('holds a message due in the quiet hours until they end', function () {
    switchOnAfterDelivery($this);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 21:30:00', 'Asia/Qatar'));
    ($this->delivered)(['minutes_ago' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 23:40:00', 'Asia/Qatar')); // 2 hours 10 minutes later, in the quiet hours
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:05:00', 'Asia/Qatar'));
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(1)->and(adMessages($this->customer)[0]->title)->toStartWith('Thank you');
});

it('never sends late: switched on three days after a delivery it drops the thank-you but still asks for the review at day four', function () {
    $order = ($this->delivered)(['minutes_ago' => 60 * 24 * 3]);
    switchOnAfterDelivery($this);
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(0);
    $this->travelTo(now()->addDays(1)->addHours(1));
    $this->journeys->tick();
    expect(adMessages($this->customer))->toHaveCount(1)->and(adMessages($this->customer)[0]->title)->toStartWith('How is your')->and($order->id)->not->toBeNull();
});

// ── The dashboard ────────────────────────────────────────────────────────────────────────────────────────────────

it('lets the owner switch the journey and each message on or off, audited, and keeps the three messages fixed', function () {
    $admin = staffWithRole('marketing_manager');
    $this->actingAs($admin);
    $steps = $this->journey->steps()->orderBy('position')->get();
    $c = Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->assertSee('Switched off until you turn it on')->assertSee('Thank you')->assertSee('Review request')->assertSee('Goes well with it');
    $c->call('toggle')->assertSet('tab', 'after_delivery');
    expect($this->journey->fresh()->is_enabled)->toBeTrue()->and(AuditLog::where('action', 'marketing.journey_enabled')->exists())->toBeTrue();

    $suggest = $steps->firstWhere('kind', 'suggest');
    $c->call('toggleStep', $suggest->id);
    expect($suggest->fresh()->is_enabled)->toBeTrue()->and(AuditLog::where('action', 'marketing.journey_step_enabled')->exists())->toBeTrue();
    $c->call('toggleStep', $suggest->id);
    expect($suggest->fresh()->is_enabled)->toBeFalse()->and(AuditLog::where('action', 'marketing.journey_step_disabled')->exists())->toBeTrue();

    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->call('editStep')->assertForbidden();                           // no "add a message"
    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->call('deleteStep', $steps[0]->id)->assertForbidden();         // and no delete: switch it off instead
    expect($this->journey->steps()->count())->toBe(3);

    // The last message switched off pauses the journey instead of leaving it "running" with nothing to send.
    foreach ($steps->take(2) as $s) {
        $c->call('toggleStep', $s->id);
    }
    expect($this->journey->fresh()->is_enabled)->toBeFalse();
});

it('keeps the do-not-repeat days and the quiet hours in the safety rules, with limits', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->set('rules.cooldown_days', 30)->set('rules.quiet_start', '23:00')->set('rules.quiet_end', '07:00')->call('saveRules')->assertHasNoErrors();
    expect($this->journey->fresh()->setting('cooldown_days'))->toBe(30)->and(AfterDeliveryJourney::cooldownDays($this->journey->fresh()))->toBe(30);
    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->set('rules.cooldown_days', 0)->call('saveRules')->assertHasErrors(['rules.cooldown_days']);
    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->set('rules.cooldown_days', 91)->call('saveRules')->assertHasErrors(['rules.cooldown_days']);
});

it('lets a person without edit rights look but not touch', function () {
    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->call('toggle')->assertForbidden();
    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->call('toggleStep', $this->journey->steps()->firstOrFail()->id)->assertForbidden();
    expect($this->journey->fresh()->is_enabled)->toBeFalse();
});

it('shows each message as the customer will read it, with the numbers of what was sent and opened', function () {
    switchOnAfterDelivery($this);
    ($this->delivered)(['minutes_ago' => 600]);
    $this->actingAs(staffWithRole('marketing_manager'));
    $this->journeys->tick();
    $entry = adMessages($this->customer)->first();
    expect($entry)->not->toBeNull();
    $before = app(AfterDeliveryJourney::class)->stepStats($this->journey);
    $thankYou = $this->journey->steps()->where('kind', 'thank_you')->firstOrFail();
    expect($before[$thankYou->id])->toBe(['sent' => 1, 'opened' => 0]);
    $entry->update(['read_at' => now()]);
    expect(app(AfterDeliveryJourney::class)->stepStats($this->journey)[$thankYou->id])->toBe(['sent' => 1, 'opened' => 1]);

    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->assertSee('Thank you, Fatima')->assertSee('Sent 1 · opened 1');
    app()->setLocale('ar');
    Livewire::test(JourneyIndex::class, ['tab' => 'after_delivery'])->assertSee('شكراً لك يا فاطمة')->assertSee('بعد التوصيل');
});

it('shows the Arabic and English messages in the template preview with an example customer', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    $template = $this->journey->steps()->where('kind', 'review')->firstOrFail()->template;
    Livewire::test(JourneyIndex::class, ['tab' => 'templates'])->call('editTemplate', $template->id)->assertSee('How is your Cotton bedsheet set?')->assertSee('كيف وجدت طقم أغطية سرير قطن؟')->assertSee('{{ order_number }}');
});
