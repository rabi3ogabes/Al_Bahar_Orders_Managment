<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Checkout\Services\AbandonedCartService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Marketing\Models\JourneyStep;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\AbandonedCartReminders;
use App\Domain\Marketing\Services\JourneyService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Livewire\Admin\Messaging\MessageComposer;
use App\Livewire\Admin\Sales\AbandonedAutoReminders;
use App\Livewire\Admin\Sales\AbandonedCartsIndex;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Automatic abandoned-cart reminders: the one switch, the schedule, the safety rules and what the owner sees.
| Time stands still at a Wednesday noon in Qatar, so quiet hours (22:00 to 08:00) never get in the way unless a test moves the clock there.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Asia/Qatar'));
    Cache::flush();
    $this->w = commerceWorld();
    $this->w->customer->update(['marketing_consent_at' => now(), 'locale' => 'en', 'phone' => '+97455123456', 'name' => 'Noor Ali']);
    $this->journeys = app(JourneyService::class);
    $this->reminders = app(AbandonedCartReminders::class);
    $this->admin = User::factory()->superAdmin()->create();
});

/** A customer's open cart with two units of the 1,000.00 product, last touched `idle` minutes ago. */
function reminderCart(object $t, array $o = []): Cart
{
    $customer = $o['customer'] ?? $t->w->customer;
    $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'last_activity_at' => now()->subMinutes($o['idle'] ?? 0),
        'branch_id' => array_key_exists('branch', $o) ? $o['branch'] : $t->w->a->id, 'address_id' => $t->w->address->id]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $o['variant'] ?? $t->w->v1->id, 'quantity' => $o['qty'] ?? 2, 'price_at_add' => '1000.00']);

    return $cart;
}

/** Switch the reminders on the way the owner does. */
function remindersOn(object $t): void
{
    $t->reminders->setEnabled(true, $t->admin);
}

function remindersSent(Cart $cart): int
{
    return (int) $cart->fresh()->abandoned_notifications;
}

function inbox(object $t, ?User $who = null)
{
    return Notification::where('user_id', ($who ?? $t->w->customer)->id)->where('event', 'journey.abandoned_cart')->get();
}

describe('safe by default', function () {
    it('ships OFF: a fresh install sends nothing, however long a cart has been idle', function () {
        $cart = reminderCart($this, ['idle' => 300]);
        $this->journeys->tick();
        $this->travel(2)->days();
        $this->journeys->tick();

        expect($this->reminders->isEnabled())->toBeFalse()->and(Journey::where('key', 'abandoned_cart')->value('is_enabled'))->toBeFalse()
            ->and(JourneyRun::count())->toBe(0)->and(JourneyDelivery::count())->toBe(0)->and(Notification::count())->toBe(0)->and(remindersSent($cart))->toBe(0);
    });

    it('turning it on starts reminding; turning it off stops it again', function () {
        reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();
        expect(inbox($this))->toHaveCount(1);

        $this->reminders->setEnabled(false, $this->admin);
        $this->travel(25)->hours();
        $this->journeys->tick();

        expect(inbox($this))->toHaveCount(1); // the second reminder never goes out
    });

    it('only turns on when there is a reminder and a way to reach customers that works on this server', function () {
        $form = $this->reminders->form();
        $form['channels'] = ['push' => true, 'in_app' => false, 'whatsapp' => true]; // neither is set up in tests
        $this->reminders->save($form, $this->admin);
        expect(fn () => $this->reminders->setEnabled(true, $this->admin))->toThrow(DomainException::class, __('cart_reminders.err_no_channel'));

        $form['channels']['in_app'] = true; // the app inbox always works
        $this->reminders->save($form, $this->admin);
        $this->reminders->setEnabled(true, $this->admin);

        expect($this->reminders->isEnabled())->toBeTrue()->and(AuditLog::where('action', 'marketing.journey_enabled')->count())->toBe(1);
    });
});

describe('the schedule', function () {
    it('sends the first reminder once the cart has been idle for the first delay, and not before', function () {
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travel(59)->minutes();
        $this->journeys->tick();
        expect(JourneyRun::count())->toBe(0)->and(Notification::count())->toBe(0);

        $this->travel(2)->minutes();
        $this->journeys->tick();

        $n = inbox($this);
        expect($n)->toHaveCount(1)->and($n[0]->title)->toBe('Your cart is waiting')->and($n[0]->body)->toContain('Noor')->toContain('2 items')->toContain('QAR 2,000.00')
            ->and(remindersSent($cart))->toBe(1)->and($cart->fresh()->abandoned_notified_at)->not->toBeNull()
            ->and(JourneyDelivery::where('channel', 'push')->first()->reason)->toBe('no_devices'); // no phone is signed in: that channel is skipped, with its reason
    });

    it('sends the second reminder a day after the last touch and a third one only when it is switched on', function () {
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        $this->travel(23)->hours();
        $this->journeys->tick();
        expect(remindersSent($cart))->toBe(2)->and(inbox($this)->pluck('title')->all())->toContain('Your cart is waiting', 'Still thinking it over?');

        $this->travel(3)->days();
        $this->journeys->tick();
        expect(remindersSent($cart))->toBe(2)->and(inbox($this))->toHaveCount(2); // the third reminder is off in "Balanced"
    });

    it('runs the persistent schedule: 1 hour, 1 day and 3 days', function () {
        $this->reminders->save(AbandonedCartReminders::withPreset($this->reminders->form(), 'persistent'), $this->admin);
        $cart = reminderCart($this);
        remindersOn($this);
        foreach ([[61, 'minutes'], [23, 'hours'], [49, 'hours']] as [$n, $unit]) {
            $this->travel($n)->{$unit}();
            $this->journeys->tick();
        }

        expect(remindersSent($cart))->toBe(3)->and(inbox($this)->pluck('title')->all())->toContain('Your cart is still saved');
    });

    it('skips a reminder that is more than 12 hours late instead of sending it late', function () {
        $cart = reminderCart($this, ['idle' => 30 * 60]); // idle for 30 hours when the switch is turned on
        remindersOn($this);
        $this->journeys->tick();

        // the 1 hour reminder expired long ago; the 1 day reminder is due and still worth sending
        expect(remindersSent($cart))->toBe(1)->and(inbox($this)->pluck('title')->all())->toBe(['Still thinking it over?']);
    });

    it('only starts for carts idle for the first delay, and never for older carts than the cart lifetime allows', function () {
        $fresh = reminderCart($this, ['idle' => 20]);
        $other = User::factory()->create(['marketing_consent_at' => now()]);
        $old = reminderCart($this, ['customer' => $other, 'idle' => 60 * 24 * 40]);
        remindersOn($this);
        $this->journeys->tick();

        expect(JourneyRun::count())->toBe(0)->and(remindersSent($fresh))->toBe(0)->and(remindersSent($old))->toBe(0);
    });

    it('does not start runs for carts so old that every reminder has expired, so they cannot crowd out the carts that are waiting', function () {
        $stale = [];
        foreach (range(1, 3) as $i) {
            $stale[] = reminderCart($this, ['customer' => User::factory()->create(['marketing_consent_at' => now()]), 'idle' => 60 * 24 * 3]); // idle for 3 days
        }
        $waiting = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $r = $this->journeys->tick();

        expect($r['started'])->toBe(1)->and(JourneyRun::pluck('cart_id')->all())->toBe([$waiting->id])->and(remindersSent($waiting))->toBe(1);
    });

    it('uses the settings the owner saved: a gentle schedule is one reminder after 3 hours', function () {
        $this->reminders->save(AbandonedCartReminders::withPreset($this->reminders->form(), 'gentle'), $this->admin);
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travel(2)->hours();
        $this->journeys->tick();
        expect(remindersSent($cart))->toBe(0);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        $this->travel(2)->days();
        $this->journeys->tick();

        expect(remindersSent($cart))->toBe(1)->and(inbox($this))->toHaveCount(1);
    });
});

describe('quiet hours', function () {
    it('holds a reminder that falls in the quiet hours and sends it when they end', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 22:00:00', 'Asia/Qatar'));
        $cart = reminderCart($this); // due at 23:00, in the quiet hours (22:00 to 08:00)
        remindersOn($this);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 23:30:00', 'Asia/Qatar'));
        $this->journeys->tick();

        $run = JourneyRun::firstOrFail();
        expect(Notification::count())->toBe(0)->and($run->status)->toBe('active')
            ->and(BusinessTime::local($run->next_at)->format('Y-m-d H:i'))->toBe('2026-11-19 08:00'); // held until the quiet period ends

        $this->travelTo(CarbonImmutable::parse('2026-11-19 07:59:00', 'Asia/Qatar'));
        $this->journeys->tick();
        expect(Notification::count())->toBe(0);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 08:01:00', 'Asia/Qatar'));
        $this->journeys->tick();
        expect(inbox($this))->toHaveCount(1)->and(remindersSent($cart))->toBe(1);
    });

    it('drops a held reminder that would be more than 12 hours late when the quiet hours end', function () {
        $form = $this->reminders->form();
        $form['quiet_start'] = '20:00';
        $form['quiet_end'] = '12:30'; // a long quiet period, so a reminder due at 22:00 can only go out 14.5 hours late
        $this->reminders->save($form, $this->admin);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 21:00:00', 'Asia/Qatar'));
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 22:05:00', 'Asia/Qatar'));
        $this->journeys->tick();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:31:00', 'Asia/Qatar'));
        $this->journeys->tick();

        expect(remindersSent($cart))->toBe(0)->and(Notification::count())->toBe(0);
    });

    it('can be switched off: then reminders go out at night too', function () {
        $form = $this->reminders->form();
        $form['quiet'] = false;
        $this->reminders->save($form, $this->admin);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 22:00:00', 'Asia/Qatar'));
        reminderCart($this);
        remindersOn($this);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 23:05:00', 'Asia/Qatar'));
        $this->journeys->tick();

        expect(inbox($this))->toHaveCount(1);
    });
});

describe('who is never reminded', function () {
    it('skips a customer who has not agreed to marketing messages, and says why', function () {
        $this->w->customer->update(['marketing_consent_at' => null]);
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();

        expect(Notification::count())->toBe(0)->and(remindersSent($cart))->toBe(0)
            ->and(JourneyDelivery::pluck('reason')->unique()->all())->toBe(['no_marketing_consent'])
            ->and(JourneyDelivery::where('status', 'sent')->count())->toBe(0);
    });

    it('skips a customer who switched this kind of message off, per channel', function () {
        NotificationPreference::create(['user_id' => $this->w->customer->id, 'channel' => 'in_app', 'category' => 'marketing', 'is_enabled' => false]);
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();

        expect(Notification::count())->toBe(0)->and(JourneyDelivery::where('channel', 'in_app')->value('reason'))->toBe('opted_out')->and(remindersSent($cart))->toBe(0);
    });

    it('stops the sequence when the customer orders', function () {
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        expect(remindersSent($cart))->toBe(1);

        actingAsCustomer($this->w->customer);
        CartItem::where('cart_id', $cart->id)->delete();
        app(CartService::class)->add($this->w->customer, $this->w->v2->id, 1); // the customer is back (this resets the reminder counters)
        quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertCreated();
        $this->travel(24)->hours();
        $r = $this->journeys->tick();

        $run = JourneyRun::firstOrFail();
        expect($run->status)->toBeIn(['converted', 'stopped'])->and(inbox($this))->toHaveCount(1)->and($r['sent'])->toBe(0);
    });

    it('credits an order that follows a reminder, with its revenue', function () {
        reminderCart($this);
        remindersOn($this);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        actingAsCustomer($this->w->customer);
        quoteAndPlace(['address_id' => $this->w->address->id, 'payment_method' => 'cod'])->assertCreated();
        $this->journeys->tick();

        $run = JourneyRun::firstOrFail();
        expect($run->status)->toBe('converted')->and((float) $run->revenue)->toBeGreaterThan(0.0);
        $r = $this->reminders->results($this->admin, 7);
        expect($r['sent'])->toBe(1)->and($r['recovered'])->toBe(1)->and((float) $r['revenue'])->toBeGreaterThan(0.0);
    });

    it('stops when the cart is emptied', function () {
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        CartItem::where('cart_id', $cart->id)->delete();
        $this->travel(24)->hours();
        $this->journeys->tick();

        expect(JourneyRun::firstOrFail()->stop_reason)->toBe('cart_empty')->and(inbox($this))->toHaveCount(1);
    });

    it('stops when the customer changes the cart, and starts again from the first reminder when they leave again', function () {
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        expect(remindersSent($cart))->toBe(1);

        app(CartService::class)->add($this->w->customer, $this->w->v2->id, 1); // a change by the customer: the idle clock and the counters start over
        expect(remindersSent($cart))->toBe(0);
        $this->journeys->tick();
        expect(JourneyRun::firstOrFail()->stop_reason)->toBe('customer_returned');

        $this->travel(2)->hours();
        $this->journeys->tick();
        expect(inbox($this))->toHaveCount(2)->and(remindersSent($cart))->toBe(1);
    });

    it('stops for a cart staff discarded, and picks the reminders up again when the discard is undone', function () {
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        app(AbandonedCartService::class)->discard($this->admin, $cart->id);
        $this->travel(24)->hours();
        $this->journeys->tick();
        expect(JourneyRun::firstOrFail()->stop_reason)->toBe('cart_discarded')->and(inbox($this))->toHaveCount(1);

        app(AbandonedCartService::class)->restore($this->admin, $cart->id);
        $this->journeys->tick();
        expect(JourneyRun::count())->toBe(1)->and(JourneyRun::firstOrFail()->status)->not->toBe('stopped')->and(inbox($this))->toHaveCount(2);
    });

    it('does not remind a cart whose products can no longer be bought', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        $this->w->p1->update(['status' => 'draft']);
        remindersOn($this);
        $this->journeys->tick();

        expect(Notification::count())->toBe(0)->and(remindersSent($cart))->toBe(0)->and(JourneyDelivery::pluck('reason')->unique()->all())->toBe(['out_of_stock']);
    });

    it('counts and shows only what can be bought now when part of the cart is gone', function () {
        $cart = reminderCart($this, ['idle' => 90, 'more' => true]);
        CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $this->w->v2->id, 'quantity' => 3, 'price_at_add' => '250.00']);
        $this->w->p2->update(['status' => 'draft']);
        remindersOn($this);
        $this->journeys->tick();

        expect(inbox($this)->first()->body)->toContain('2 items')->toContain('QAR 2,000.00');
    });
});

describe('limits', function () {
    it('never goes past the most reminders per cart', function () {
        $form = $this->reminders->form();
        $form['max_per_cart'] = 1;
        $this->reminders->save($form, $this->admin);
        $cart = reminderCart($this);
        remindersOn($this);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        $this->travel(24)->hours();
        $this->journeys->tick();

        expect(remindersSent($cart))->toBe(1)->and(inbox($this))->toHaveCount(1)->and(JourneyDelivery::where('reason', 'cart_limit')->exists())->toBeTrue();
    });

    it('shares the counters with the staff "Send reminder" button, both ways', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        // staff go first: that is reminder number one
        expect(app(AbandonedCartService::class)->remind($this->admin, $cart->id)['status'])->toBe('sent')->and(remindersSent($cart))->toBe(1);
        $this->travel(4)->hours(); // far enough from the staff message for the automatic reminder to follow
        $this->journeys->tick();
        expect(remindersSent($cart))->toBe(2)->and(inbox($this))->toHaveCount(1);

        // the limit of 2 is used up: the second automatic reminder is not sent and a new staff reminder is refused
        $this->travel(24)->hours();
        $this->journeys->tick();
        expect(inbox($this))->toHaveCount(1)->and(app(AbandonedCartService::class)->remind($this->admin, $cart->id)['status'])->toBe('limit_reached');
    });

    it('holds a staff reminder back right after an automatic one', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();

        expect(remindersSent($cart))->toBe(1)->and(app(AbandonedCartService::class)->remind($this->admin, $cart->id)['status'])->toBe('too_soon');
    });

    it('keeps two reminders for one customer within the daily limit of messages', function () {
        Journey::where('key', 'abandoned_cart')->update(['settings' => ['max_per_day' => 1, 'max_per_cart' => 5, 'quiet_start' => null, 'quiet_end' => null, 'defaults_version' => 2, 'attribution_days' => 7]]);
        $first = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();
        $other = User::factory()->create(['marketing_consent_at' => now()]);
        NotificationPreference::create(['user_id' => $other->id, 'channel' => 'in_app', 'category' => 'marketing', 'is_enabled' => true]);

        expect(remindersSent($first))->toBe(1);
        // a second message to the same customer within 24 hours is refused by the daily limit: it waits, and goes out once the day has passed
        $this->travel(5)->hours();
        $this->journeys->tick();
        expect(remindersSent($first))->toBe(1);
        $this->travel(20)->hours();
        $this->journeys->tick();
        expect(remindersSent($first))->toBe(2);
    });
});

describe('the notification controls and the channels', function () {
    it('sends nothing while the Cart reminder notification is switched off, and goes on when it is back on', function () {
        $cart = reminderCart($this);
        remindersOn($this);
        app(NotificationRules::class)->set('cart.abandoned', false, [], $this->admin);
        $this->travel(61)->minutes();
        $this->journeys->tick();
        expect(Notification::count())->toBe(0)->and($this->reminders->blockedBy())->toBe('cart_rule')->and(remindersSent($cart))->toBe(0);

        app(NotificationRules::class)->reset('cart.abandoned', $this->admin);
        $this->journeys->tick();
        expect(inbox($this))->toHaveCount(1)->and($this->reminders->blockedBy())->toBeNull();
    });

    it('sends nothing while campaign messages are switched off', function () {
        reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        app(NotificationRules::class)->set('campaign', false, [], $this->admin);
        $this->journeys->tick();

        expect(Notification::count())->toBe(0)->and($this->reminders->blockedBy())->toBe('campaign_rule');
    });

    it('leaves out a channel the notification controls switched off', function () {
        reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        app(NotificationRules::class)->set('cart.abandoned', true, ['push' => false], $this->admin);
        $this->journeys->tick();

        expect(JourneyDelivery::where('channel', 'push')->exists())->toBeFalse()->and(JourneyDelivery::where('channel', 'in_app')->where('status', 'sent')->exists())->toBeTrue()
            ->and(inbox($this))->toHaveCount(1);
    });

    it('says plainly when WhatsApp is chosen but not set up, and reaches the customer another way', function () {
        $form = $this->reminders->form();
        $form['channels']['whatsapp'] = true;
        $this->reminders->save($form, $this->admin);
        NotificationPreference::create(['user_id' => $this->w->customer->id, 'channel' => 'whatsapp', 'category' => 'marketing', 'is_enabled' => true]);
        reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();

        expect(JourneyDelivery::where('channel', 'whatsapp')->first())->status->toBe('skipped')->reason->toBe('whatsapp_not_configured')
            ->and(inbox($this))->toHaveCount(1)->and($this->reminders->channelStatus()['whatsapp'])->toBeFalse();
    });
});

describe('never twice', function () {
    it('does not double-send when the scheduler runs twice in a row', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();
        $this->journeys->tick();
        $this->journeys->tick();

        expect(inbox($this))->toHaveCount(1)->and(remindersSent($cart))->toBe(1)->and(JourneyDelivery::where('channel', 'in_app')->count())->toBe(1);
    });

    it('leaves a run alone until its wake-up time', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick(); // the run starts and sends its first reminder
        JourneyDelivery::query()->delete();
        Notification::query()->delete();
        DB::table('carts')->where('id', $cart->id)->update(['abandoned_notifications' => 0, 'abandoned_notified_at' => null]);
        $run = JourneyRun::firstOrFail();
        $run->update(['status' => 'active', 'next_at' => now()->addMinutes(3), 'finished_at' => null]); // another pass holds the lease

        $this->journeys->tick();
        expect(Notification::count())->toBe(0);

        $run->update(['next_at' => now()->subMinute()]); // the lease ran out
        $this->journeys->tick();
        expect(Notification::count())->toBe(1);
    });

    it('leases the run while it sends, so an overlapping pass skips it', function () {
        reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $leased = [];
        Event::listen('eloquent.creating: '.Notification::class, function () use (&$leased) {
            $leased[] = JourneyRun::firstOrFail()->next_at->gt(now()); // at the moment the message is written the run is held for the next minutes
        });
        $this->journeys->tick();
        Event::forget('eloquent.creating: '.Notification::class);

        expect($leased)->toBe([true]);
    });

    it('does not send what another pass sent a moment ago, even when both started from the same picture of the run', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $done = false;
        Event::listen('eloquent.retrieved: '.Cart::class, function () use (&$done) {
            if ($done || ! JourneyRun::exists()) {
                return;
            }
            $done = true; // the other pass sends the same step on the same channel between our read of the run and our send
            $run = JourneyRun::firstOrFail();
            JourneyDelivery::create(['run_id' => $run->id, 'step_id' => $run->journey->steps()->first()->id, 'user_id' => $run->user_id, 'channel' => 'in_app', 'status' => 'sent', 'sent_at' => now()]);
        });
        $this->journeys->tick();
        Event::forget('eloquent.retrieved: '.Cart::class);

        expect($done)->toBeTrue()->and(Notification::where('event', 'journey.abandoned_cart')->count())->toBe(0)->and(JourneyDelivery::where('channel', 'in_app')->count())->toBe(1);
    });

    it('does not send a step on a channel that already has a delivery row', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $journey = Journey::where('key', 'abandoned_cart')->firstOrFail();
        $run = JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $this->w->customer->id, 'cart_id' => $cart->id, 'anchor_at' => $cart->last_activity_at, 'next_at' => now()]);
        JourneyDelivery::create(['run_id' => $run->id, 'step_id' => $journey->steps()->first()->id, 'user_id' => $this->w->customer->id, 'channel' => 'in_app', 'status' => 'queued']);
        $this->journeys->tick();

        expect(Notification::count())->toBe(0)->and(JourneyDelivery::where('channel', 'in_app')->count())->toBe(1);
    });

    it('closes a delivery a dead pass left queued, so it shows as failed with its reason', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $journey = Journey::where('key', 'abandoned_cart')->firstOrFail();
        $run = JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $this->w->customer->id, 'cart_id' => $cart->id, 'anchor_at' => $cart->last_activity_at, 'next_at' => now()->addDay()]);
        $d = JourneyDelivery::create(['run_id' => $run->id, 'step_id' => $journey->steps()->first()->id, 'user_id' => $this->w->customer->id, 'channel' => 'push', 'status' => 'queued']);
        $d->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $this->journeys->tick();

        expect($d->fresh())->status->toBe('failed')->reason->toBe('interrupted');
    });

    it('handles a bounded number of carts per pass and finishes the rest on the next ones', function () {
        foreach (range(1, 130) as $i) {
            $u = User::factory()->create(['marketing_consent_at' => now(), 'name' => 'Customer '.$i]);
            reminderCart($this, ['customer' => $u, 'idle' => 90]);
        }
        remindersOn($this);
        $first = $this->journeys->tick();
        $second = $this->journeys->tick();
        $third = $this->journeys->tick();

        expect($first['started'])->toBe(100)->and($first['sent'])->toBe(100)        // one pass starts and handles at most 100 carts
            ->and($second['started'])->toBe(30)->and($second['sent'])->toBe(30)     // the rest follows on the next pass
            ->and($third['started'])->toBe(0)->and($third['sent'])->toBe(0)
            ->and(Notification::where('event', 'journey.abandoned_cart')->count())->toBe(130);
    });
});

describe('the words', function () {
    it('fills the name, the number of items, the cart total and the link, in the language of the customer', function () {
        $this->w->customer->update(['locale' => 'ar']);
        reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();

        $n = inbox($this)->first();
        expect($n->title)->toBe('سلتك بانتظارك')->and($n->body)->toContain('Noor')->toContain('منتجان')->toContain('2,000.00 ر.ق')->and($n->body)->not->toContain('{{');
    });

    it('adds the cart link on its own line to a WhatsApp message and keeps the offer sentence', function () {
        $promo = Promotion::create(['name_en' => 'Free delivery', 'name_ar' => 'توصيل مجاني', 'type' => 'free_delivery', 'value' => '0', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
        $form = $this->reminders->form();
        $form['offer'] = ['promotion_id' => $promo->id, 'reminder' => 1, 'valid_hours' => 24];
        $this->reminders->save($form, $this->admin);
        $t = Journey::where('key', 'abandoned_cart')->firstOrFail()->steps()->first()->template;
        $text = $this->journeys->whatsAppText($t, 'en', ['name' => 'Noor', 'items_text' => '2 items', 'total' => 'QAR 2,000.00', 'link' => 'https://shop.example/cart', 'coupon' => 'BAHAR-AAA111', 'expires' => 'tomorrow', 'offer' => 'Free delivery']);

        expect($text)->toContain('Your cart is waiting')->toContain('Noor')->toContain('Your gift: Free delivery. Use code BAHAR-AAA111 before tomorrow.')->and(str_ends_with($text, "\nhttps://shop.example/cart"))->toBeTrue();
    });

    it('attaches the recovery offer as a personal single-use coupon and tells the customer the code', function () {
        $promo = Promotion::create(['name_en' => 'Free delivery', 'name_ar' => 'توصيل مجاني', 'type' => 'free_delivery', 'value' => '0', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
        $form = $this->reminders->form();
        $form['offer'] = ['promotion_id' => $promo->id, 'reminder' => 1, 'valid_hours' => 24];
        $this->reminders->save($form, $this->admin);
        reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();

        $d = JourneyDelivery::where('channel', 'in_app')->firstOrFail();
        $coupon = Coupon::where('code', $d->coupon_code)->firstOrFail();
        expect($coupon->assigned_user_id)->toBe($this->w->customer->id)->and($coupon->usage_limit)->toBe(1)->and($coupon->promotion_id)->toBe($promo->id)
            ->and($coupon->ends_at->gt(now()->addHours(23)))->toBeTrue()->and($coupon->ends_at->lt(now()->addHours(25)))->toBeTrue()
            ->and(inbox($this)->first()->body)->toContain($d->coupon_code)->toContain('Free delivery');
    });
});

describe('older settings stay valid', function () {
    it('brings an unused first-version journey up to the new defaults, and leaves a used or changed one alone', function () {
        $journeys = Journey::query()->get();
        expect($journeys)->toHaveCount(0);
        // The starter journey of the first version: 30 minutes, 4 hours and 3 days, never switched on, never run.
        $j = Journey::create(['key' => 'abandoned_cart', 'name' => 'Abandoned cart', 'is_enabled' => false, 'settings' => ['quiet_start' => '22:00', 'quiet_end' => '08:00', 'max_per_day' => 3]]);
        $remind = JourneyTemplate::create(['name' => 'Cart reminder', 'kind' => 'reminder', 'title_en' => 'Your cart is waiting', 'title_ar' => 'سلتك بانتظارك',
            'body_en' => 'Hi {{name}}, you left {{items}} item(s) in your cart. Complete your order before they sell out.', 'body_ar' => 'مرحباً {{name}}']);
        $offer = JourneyTemplate::create(['name' => 'Cart offer', 'kind' => 'offer', 'title_en' => 'A little something for you', 'title_ar' => 'هدية', 'body_en' => 'Hi {{name}}, use code {{coupon}}', 'body_ar' => 'مرحباً']);
        foreach ([[30, ['push', 'in_app'], $remind], [240, ['push', 'whatsapp'], $offer], [4320, ['push', 'whatsapp'], $offer]] as $i => [$min, $ch, $t]) {
            JourneyStep::create(['journey_id' => $j->id, 'position' => $i + 1, 'delay_minutes' => $min, 'channels' => $ch, 'template_id' => $t->id]);
        }

        $this->reminders->ensure();

        $j->refresh();
        $steps = $j->steps()->with('template')->get();
        expect($steps->pluck('delay_minutes')->all())->toBe([60, 1440, 4320])->and($steps->pluck('is_enabled')->all())->toBe([true, true, false])
            ->and($steps[0]->template->body_en)->not->toContain('sell out')->and($steps->pluck('template_id')->unique())->toHaveCount(3) // no template is shared between two reminders
            ->and($j->setting('max_per_cart'))->toBe(2)->and($j->is_enabled)->toBeFalse();

        // A journey somebody changed is left exactly as it is.
        $j2 = Journey::create(['key' => 'birthday', 'name' => 'Birthday', 'is_enabled' => false]);
        expect($j2->setting('defaults_version'))->toBeNull();
    });

    it('does not touch a journey that was switched on or has run', function () {
        $this->reminders->ensure();
        $j = Journey::where('key', 'abandoned_cart')->firstOrFail();
        $j->steps()->first()->update(['delay_minutes' => 45]);
        $j->update(['settings' => ['max_per_day' => 3], 'is_enabled' => true]); // no `defaults_version`: as a journey from before this feature
        $this->reminders->ensure();

        expect($j->fresh()->steps()->first()->delay_minutes)->toBe(45)->and($j->fresh()->is_enabled)->toBeTrue()->and($this->reminders->form()['reminders'][0]['delay'])->toBe(45);
    });

    it('copies the reminders already sent by the journey onto the carts that are still open (once)', function () {
        $journey = $this->reminders->journey();
        $cart = reminderCart($this, ['idle' => 300]);
        $old = reminderCart($this, ['customer' => User::factory()->create(), 'idle' => 300]);
        $run = JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $this->w->customer->id, 'cart_id' => $cart->id, 'anchor_at' => $cart->last_activity_at]);
        $oldRun = JourneyRun::create(['journey_id' => $journey->id, 'user_id' => $old->user_id, 'cart_id' => $old->id, 'anchor_at' => now()->subDays(3)]); // an earlier idle period
        foreach ($journey->steps as $step) {
            foreach (['push', 'in_app'] as $channel) { // one step on two channels is one reminder
                JourneyDelivery::create(['run_id' => $run->id, 'step_id' => $step->id, 'user_id' => $run->user_id, 'channel' => $channel, 'status' => 'sent', 'sent_at' => now()->subHours(2)]);
            }
        }
        JourneyDelivery::create(['run_id' => $oldRun->id, 'step_id' => $journey->steps->first()->id, 'user_id' => $oldRun->user_id, 'channel' => 'push', 'status' => 'sent', 'sent_at' => now()->subDays(3)]);

        (require database_path('migrations/2026_11_18_100000_automatic_cart_reminders.php'))->up();

        expect(remindersSent($cart))->toBe(2)->and($cart->fresh()->abandoned_notified_at)->not->toBeNull()->and(remindersSent($old))->toBe(0);
    });
});

describe('the abandoned orders page', function () {
    it('shows the card switched off with the plain sentence of what will happen', function () {
        $page = Livewire::actingAs($this->admin)->test(AbandonedCartsIndex::class);

        $page->assertSee(__('cart_reminders.title'))->assertSee(__('cart_reminders.when_on'))
            ->assertSee('A reminder goes to customers whose cart has been idle for 1 hour, and again after 1 day, between 08:00 and 22:00 (Qatar time), up to 2 times.')
            ->assertSee(__('cart_reminders.settings'));
        expect($page->html())->toContain('data-state="off"');
    });

    it('turns on and off with one switch: it saves at once, says so with an Undo, and is audited', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class);

        $card->call('toggle')->assertDispatched('toast', type: 'success', message: __('cart_reminders.turned_on'), undoEvent: 'cart-reminders-undo', undoParams: ['on' => false]);
        expect($this->reminders->isEnabled())->toBeTrue()->and($card->html())->toContain('data-state="on"')->and(AuditLog::where('action', 'marketing.journey_enabled')->count())->toBe(1);

        $card->call('undoToggle', false);
        expect($this->reminders->isEnabled())->toBeFalse()->and(AuditLog::where('action', 'marketing.journey_disabled')->count())->toBe(1);
        $card->call('toggle')->call('toggle');
        expect($this->reminders->isEnabled())->toBeFalse();
    });

    it('lets only company-wide marketing editors change it, and everybody who can open the page see it', function () {
        $viewer = staffWithRole('auditor');                         // orders.view everywhere, marketing.edit nowhere
        $manager = staffWithRole('branch_manager', $this->w->a);   // orders.view in one branch
        $support = staffWithRole('customer_support');              // orders.create, but no marketing
        $nobody = staffWithRole('inventory_manager', $this->w->a);   // no orders.view at all

        foreach ([$viewer, $manager, $support] as $who) {
            Livewire::actingAs($who)->test(AbandonedAutoReminders::class)->assertSee(__('cart_reminders.title'))->assertSee(__('cart_reminders.read_only'))->assertDontSee(__('cart_reminders.settings'));
            Livewire::actingAs($who)->test(AbandonedAutoReminders::class)->call('toggle'); // refused (403) whatever the browser sends
            $window = Livewire::actingAs($who)->test(AbandonedAutoReminders::class)->call('openSettings');
            expect($this->reminders->isEnabled())->toBeFalse()->and($window->get('showSettings'))->not->toBeTrue()->and($window->get('form'))->toBeEmpty();
        }
        Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('toggle')->assertOk();
        expect($this->reminders->isEnabled())->toBeTrue();
        Livewire::actingAs($nobody)->test(AbandonedAutoReminders::class)->assertForbidden();
    });

    it('shows a branch manager the results of their own branch only', function () {
        $mgrA = staffWithRole('branch_manager', $this->w->a);
        $other = User::factory()->create(['marketing_consent_at' => now()]);
        $inA = reminderCart($this, ['idle' => 90, 'branch' => $this->w->a->id]);
        $inB = reminderCart($this, ['customer' => $other, 'idle' => 90, 'branch' => $this->w->b->id]);
        $this->w->customer->update(['marketing_consent_at' => now()]);
        remindersOn($this);
        $this->journeys->tick();

        expect($this->reminders->results($this->admin)['sent'])->toBe(2)->and($this->reminders->results($mgrA)['sent'])->toBe(1);
        Livewire::actingAs($mgrA)->test(AbandonedAutoReminders::class)->assertSeeHtml('data-result="sent">1<');
    });

    it('counts the last 7 and 30 days of results and shows a failed send with its plain reason', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        remindersOn($this);
        $this->journeys->tick();
        $run = JourneyRun::firstOrFail();
        $d = JourneyDelivery::where('channel', 'in_app')->firstOrFail();
        $d->forceFill(['sent_at' => now()->subDays(10)])->save(); // sent 10 days ago: only in the 30-day numbers
        $step = $run->journey->steps->last();
        JourneyDelivery::create(['run_id' => $run->id, 'step_id' => $step->id, 'user_id' => $run->user_id, 'channel' => 'whatsapp', 'status' => 'failed', 'reason' => 'n8n_http_500']);
        Cache::flush();

        expect($this->reminders->results($this->admin, 7)['sent'])->toBe(0)->and($this->reminders->results($this->admin, 7)['failed'])->toBe(1)
            ->and($this->reminders->results($this->admin, 30)['sent'])->toBe(1);
        $history = $this->reminders->history($cart->fresh());
        expect(collect($history['items'])->flatMap(fn ($i) => $i['issues'])->pluck('reason')->all())->toContain(__('cart_reminders.reason.n8n_http'));
    });

    it('shows each cart\'s reminders in its window: the staff one, the automatic one, the reason a channel did not go out and what comes next', function () {
        $cart = reminderCart($this, ['idle' => 90]);
        app(AbandonedCartService::class)->remind($this->admin, $cart->id);   // reminder number one, by hand
        remindersOn($this);
        $this->travel(4)->hours();
        $this->journeys->tick();                                               // reminder number two, automatic: the cart's limit is used up

        $page = Livewire::actingAs($this->admin)->test(AbandonedCartsIndex::class)->call('openCart', $cart->id);
        $page->assertSee(__('cart_reminders.history_title'))->assertSee(__('cart_reminders.history_auto', ['n' => 1]))
            ->assertSee(__('cart_reminders.history_staff', ['name' => $this->admin->name]))
            ->assertSee(__('cart_reminders.history_not_sent', ['channel' => __('cart_reminders.ch_push'), 'reason' => __('cart_reminders.reason.no_devices')]));
        expect($this->reminders->history($cart->fresh())['next'])->toBeNull(); // the limit of 2 per cart is used up
    });

    it('says what comes next for a cart that is waiting for its reminder, pushed past the quiet hours', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 21:30:00', 'Asia/Qatar'));
        $cart = reminderCart($this); // the first reminder is due at 22:30, in the quiet hours
        remindersOn($this);
        $next = $this->reminders->history($cart)['next'];

        expect($next['number'])->toBe(1)->and(BusinessTime::local($next['at'])->format('Y-m-d H:i'))->toBe('2026-11-19 08:00');
        $this->reminders->setEnabled(false, $this->admin);
        expect($this->reminders->history($cart)['next'])->toBeNull();
    });
});

describe('the settings window', function () {
    it('opens with the saved settings, and Save stays disabled until something changes', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings');
        $disabled = fn ($c) => (bool) preg_match('/<button[^>]*form="cart-reminders-form"[^>]*(?<=\s)disabled(?=[\s=>])/', $c->html());

        expect($card->get('showSettings'))->toBeTrue()->and($card->get('form.reminders.0.delay'))->toBe(60)->and($card->get('form.max_per_cart'))->toBe(2)->and($disabled($card))->toBeTrue();
        $card->set('form.max_per_cart', '3');
        expect($disabled($card))->toBeFalse();
        $card->set('form.max_per_cart', '2');
        expect($disabled($card))->toBeTrue(); // back to what it was: nothing to save
    });

    it('saves the schedule, the quiet hours, the channels, the limit and the words, and the card shows the new sentence', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings')
            ->set('form.reminders.0.delay', '180')->set('form.reminders.1.enabled', false)->set('form.quiet_start', '23:00')->set('form.quiet_end', '07:30')->set('form.max_per_cart', '1')
            ->set('form.channels.in_app', false)->set('form.reminders.0.title_en', 'Hello again')->set('form.reminders.0.body_ar', 'هلا {{name}}، سلتك تنتظرك.')
            ->call('save')->assertHasNoErrors()->assertSet('showSettings', false)->assertDispatched('toast', type: 'success', message: __('cart_reminders.saved'));

        $j = Journey::where('key', 'abandoned_cart')->firstOrFail();
        $steps = $j->steps()->with('template')->get();
        expect($j->setting('quiet_start'))->toBe('23:00')->and($j->setting('quiet_end'))->toBe('07:30')->and($j->setting('max_per_cart'))->toBe(1)
            ->and($steps[0]->delay_minutes)->toBe(180)->and($steps[0]->channels)->toBe(['push'])->and($steps[1]->is_enabled)->toBeFalse()
            ->and($steps[0]->template->title_en)->toBe('Hello again')->and($steps[0]->template->body_ar)->toBe('هلا {{name}}، سلتك تنتظرك.')
            ->and(AuditLog::where('action', 'marketing.cart_reminders_saved')->count())->toBe(1);
        $card->assertSee('A reminder goes to customers whose cart has been idle for 3 hours, between 07:30 and 23:00 (Qatar time), up to 1 time.');
    });

    it('fills a ready-made schedule into the form and says which one it is', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings')->call('preset', 'persistent');

        expect($card->get('form.reminders.2.enabled'))->toBeTrue()->and($card->get('form.reminders.2.delay'))->toBe(4320)->and($card->get('form.max_per_cart'))->toBe(3)
            ->and($card->html())->toContain('aria-pressed="true"');
        $card->call('preset', 'gentle');
        expect($card->get('form.reminders.0.delay'))->toBe(180)->and($card->get('form.reminders.1.enabled'))->toBeFalse()->and($card->get('form.max_per_cart'))->toBe(1);
    });

    it('moves a later reminder forward when an earlier one is set after it', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings')->set('form.reminders.0.delay', '1440');

        expect($card->get('form.reminders.1.delay'))->toBeGreaterThanOrEqual(1440 + AbandonedCartReminders::MIN_GAP_MINUTES);
    });

    it('refuses what makes no sense, in plain words, and keeps what was typed', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings')
            ->set('form.reminders.0.enabled', false)->set('form.reminders.1.enabled', false)->call('save')
            ->assertHasErrors(['form.reminders.0.enabled'])->assertSee(__('cart_reminders.err_no_reminder'));
        $card->set('form.reminders.0.enabled', true)->set('form.channels.push', false)->set('form.channels.in_app', false)->set('form.channels.whatsapp', false)->call('save')
            ->assertHasErrors(['form.channels'])->assertSet('showSettings', true);
        $card->set('form.channels.in_app', true)->set('form.reminders.0.body_en', 'Hi {{nickname}}')->call('save')->assertHasErrors(['form.reminders.0.body_en']);
        $card->set('form.reminders.0.body_en', 'Use {{coupon}} now')->call('save')->assertHasErrors(['form.reminders.0.body_en']); // no offer on this reminder
        $card->set('form.reminders.0.body_en', 'Hi {{name}}')->set('form.reminders.0.title_ar', '')->call('save')->assertHasErrors(['form.reminders.0.title_ar']);
        $card->set('form.reminders.0.title_ar', 'سلتك')->set('form.quiet_end', '22:00')->call('save')->assertHasErrors(['form.quiet_end']);
        expect(Journey::where('key', 'abandoned_cart')->firstOrFail()->steps()->first()->template->body_en)->not->toBe('Hi {{name}}')->and($card->get('form.reminders.0.body_en'))->toBe('Hi {{name}}');
    });

    it('checks the delays on the server, whatever the browser sends', function () {
        $form = $this->reminders->form();
        $form['reminders'][1]['delay'] = 90; // 30 minutes after the first one: too close
        expect(fn () => $this->reminders->save($form, $this->admin))->toThrow(ValidationException::class);
        $form = $this->reminders->form();
        $form['reminders'][0]['delay'] = 5; // below the shortest
        expect(fn () => $this->reminders->save($form, $this->admin))->toThrow(ValidationException::class);
    });

    it('accepts an offer only from a running promotion that needs a coupon, on a reminder that is on', function () {
        $running = Promotion::create(['name_en' => 'Free delivery', 'name_ar' => 'توصيل مجاني', 'type' => 'free_delivery', 'value' => '0', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
        $automatic = Promotion::create(['name_en' => 'Auto', 'name_ar' => 'تلقائي', 'type' => 'percentage', 'value' => '5', 'scope' => 'order', 'requires_coupon' => false, 'is_active' => true]);
        $form = $this->reminders->form();

        $form['offer'] = ['promotion_id' => $automatic->id, 'reminder' => 1, 'valid_hours' => 48];
        expect(fn () => $this->reminders->save($form, $this->admin))->toThrow(ValidationException::class);
        $form['offer'] = ['promotion_id' => $running->id, 'reminder' => 3, 'valid_hours' => 48]; // reminder 3 is off
        expect(fn () => $this->reminders->save($form, $this->admin))->toThrow(ValidationException::class);
        $form['offer'] = ['promotion_id' => $running->id, 'reminder' => 2, 'valid_hours' => 48];
        $this->reminders->save($form, $this->admin);

        $steps = Journey::where('key', 'abandoned_cart')->firstOrFail()->steps()->with('template')->get();
        expect($steps[1]->template->promotion_id)->toBe($running->id)->and($steps[0]->template->promotion_id)->toBeNull()->and($this->reminders->form()['offer']['reminder'])->toBe(2);
    });

    it('previews the words with an example customer, in either language, with the offer sentence when there is an offer', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings')->call('setTab', 'messages');

        $card->assertSee('Your cart is waiting')->assertSee('Hi Fatima, your cart has 2 items worth QAR 1,250.00. Come back whenever you are ready.');
        $card->call('setPreviewLocale', 'ar')->assertSee('سلتك بانتظارك')->assertSee('هلا فاطمة، في سلتك منتجان بقيمة 1,250.00 ر.ق. ارجع لها وقت ما تحب.');
        $promo = Promotion::create(['name_en' => 'Free delivery', 'name_ar' => 'توصيل مجاني', 'type' => 'free_delivery', 'value' => '0', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
        $card->set('form.offer.promotion_id', $promo->id)->call('setEditing', 2)->call('setPreviewLocale', 'en')->assertSee('Your gift: Free delivery. Use code BAHAR-7K2M9Q before tomorrow 20:00.');
    });

    it('puts the standard words back for one reminder', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings')->call('setTab', 'messages')
            ->set('form.reminders.0.title_en', 'Something else')->call('resetText');

        expect($card->get('form.reminders.0.title_en'))->toBe('Your cart is waiting');
    });

    it('speaks Arabic with real translations, in the same set of messages as English', function () {
        $flat = function (array $a, string $prefix = '') use (&$flat) {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? $out += $flat($v, $prefix.$k.'.') : $out[$prefix.$k] = $v;
            }

            return $out;
        };
        $en = $flat(require lang_path('en/cart_reminders.php'));
        $ar = $flat(require lang_path('ar/cart_reminders.php'));
        expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en));
        foreach ($en as $key => $text) {
            expect($ar[$key])->not->toBe('')->and($ar[$key] === $text && ! in_array($key, ['ph.name'], true) && preg_match('/[a-z]{4,}/i', $text))->toBeFalsy("$key is not translated");
            preg_match_all('/:[a-z_]+/', $text, $a);
            preg_match_all('/:[a-z_]+/', (string) $ar[$key], $b);
            expect(collect($b[0])->unique()->sort()->values()->all())->toBe(collect($a[0])->unique()->sort()->values()->all());
        }
        App::setLocale('ar');
        $page = Livewire::actingAs($this->admin)->test(AbandonedCartsIndex::class);
        $page->assertSee('التذكيرات التلقائية')->assertSee('إعدادات التذكير')->assertSee('بتوقيت قطر');
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings');
        $card->assertSee('ساعات الهدوء')->assertSee('طرق الوصول إلى العميل')->assertSee('متوازن');
    });

    it('escapes everything the owner typed', function () {
        $card = Livewire::actingAs($this->admin)->test(AbandonedAutoReminders::class)->call('openSettings')->call('setTab', 'messages')
            ->set('form.reminders.0.title_en', '<script>alert(1)</script>');

        expect($card->html())->not->toContain('<script>alert(1)</script>');
    });
});

describe('the customer messages composer', function () {
    it('does not offer the reminder templates, which use cart details a manual message cannot fill, and still offers the others', function () {
        $this->reminders->ensure();
        $this->journeys->ensureDefaults();
        $reminder = Journey::where('key', 'abandoned_cart')->firstOrFail()->steps()->first()->template;
        $manager = staffWithRole('marketing_manager');
        $customer = User::factory()->create(['marketing_consent_at' => now()]);

        $composer = Livewire::actingAs($manager)->test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$customer->id]);

        $composer->assertSee('Birthday wish')->assertDontSee($reminder->name);
        $composer->call('choose', $reminder->id)->assertDispatched('toast', type: 'error', message: __('messaging_composer.template_gone'))->assertSet('templateId', '');
        $composer->call('choose', JourneyTemplate::where('name', 'Birthday wish')->value('id'))->assertSet('kind', 'offer');
    });

    it('offers a reminder template again once it only uses details the composer knows', function () {
        $this->reminders->ensure();
        $t = Journey::where('key', 'abandoned_cart')->firstOrFail()->steps()->first()->template;
        $t->update(['body_en' => 'Hi {{name}}, you left {{items}} item(s) in your cart.', 'body_ar' => 'مرحباً {{name}}']);
        $manager = staffWithRole('marketing_manager');
        $customer = User::factory()->create(['marketing_consent_at' => now()]);

        Livewire::actingAs($manager)->test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: [$customer->id])->assertSee($t->name);
    });
});
