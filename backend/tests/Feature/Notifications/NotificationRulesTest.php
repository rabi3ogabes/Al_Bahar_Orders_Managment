<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Services\NotificationCatalog;
use App\Domain\Notifications\Services\NotificationChannelStatus;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\PushNotifier;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Events\OrderStatusChanged;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Gateways\SadadChecksum;
use App\Domain\Payments\Models\Payment;
use App\Livewire\Admin\Content\NotificationRulesIndex;
use App\Livewire\Admin\Marketing\CampaignIndex;
use App\Support\AdminNavigation;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

require_once __DIR__.'/../Commerce/LiveOrderHelpers.php';

/*
 * Notification controls: what the admin switches off or on in the dashboard (Content > Notification controls) decides what is sent.
 * Every test that looks at a send goes through the real code path (NotificationService, the domain event subscriber, PushNotifier);
 * Firebase and the SADAD gateway are faked at the HTTP edge or by their signed callback.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::flush();
    $this->rules = app(NotificationRules::class);
    $this->notify = fn (User $u, string $event, ?array $channels = null) => app(NotificationService::class)->notify($u, $event, ['order_number' => 'AB1', 'name' => 'Noor'], $channels);
});

/** Order world with a frozen clock and Firebase faked. The customer's phone is registered later (rulesPhone), so placing an order does not itself send a card. */
function rulesWorld(): object
{
    lopFreeze();
    config(['platform.live_order.progress_steps' => 0, 'platform.live_order.countdown_shift_seconds' => 0]);
    $w = commerceWorld();
    lopFcm();

    return $w;
}

function rulesPhone(User $user, string $token = 'tok-1'): void
{
    UserDevice::create(['user_id' => $user->id, 'platform' => 'android', 'push_token' => $token, 'locale' => 'en']);
}

function rulesSadadCallback(array $data): void
{
    $key = urlencode('test-secret').'1234567';
    $data['checksumhash'] = SadadChecksum::generate(json_encode(['postData' => $data, 'secretKey' => urlencode('test-secret')]), $key);
    test()->post('/webhooks/sadad', $data, ['Accept' => 'application/json'])->assertOk();
}

function rulesConfigureSadad(): void
{
    config(['platform.payments.sadad' => array_merge(config('platform.payments.sadad'), [
        'enabled' => true, 'merchant_id' => '1234567', 'secret_key' => 'test-secret', 'website' => 'albahar.qa', 'checkout_url' => 'https://sandbox.sadad.example/webpurchase',
        'status_url' => null, 'capabilities' => ['hosted_checkout' => true, 'status_api' => false, 'refund_api' => false, 'void' => false, 'authorize_capture' => false],
    ])]);
}

// ── Nothing changes until the admin changes something ────────────────────────────────────────────────────────────────

it('behaves exactly as before while no rule is saved', function () {
    $u = User::factory()->create();

    expect(NotificationRule::count())->toBe(0);
    foreach (NotificationCatalog::EVENTS as $event => [$category, $defaults]) {
        expect($this->rules->enabled($event))->toBeTrue()
            ->and($this->rules->effectiveChannels($event, $defaults))->toBe($defaults)
            ->and($this->rules->state($event))->toBe(['enabled' => true, 'channels' => [], 'customized' => false]);
        foreach ($defaults as $channel) {
            expect($this->rules->channelEnabled($event, $channel))->toBeTrue()->and($this->rules->allows($event, $channel))->toBeTrue();
        }
    }
    // The six live-card events have the card on by default; nothing else has it.
    foreach (NotificationCatalog::LIVE_CARD_EVENTS as $event) {
        expect($this->rules->channelEnabled($event, 'live_card'))->toBeTrue();
    }
    expect($this->rules->channelEnabled('order.processing', 'live_card'))->toBeFalse()
        ->and(($this->notify)($u, 'support.reply'))->toBe(['push', 'in_app', 'email'])
        ->and(($this->notify)($u, 'order.processing'))->toBe(['in_app']);
});

it('has a group, a name and a "when" sentence in both languages for every event', function () {
    $grouped = collect(NotificationRules::GROUPS)->flatten();
    expect($grouped->duplicates()->all())->toBe([])
        ->and($grouped->diff(array_merge(array_keys(NotificationCatalog::EVENTS), NotificationRules::STAFF_EVENTS))->all())->toBe([])
        ->and(collect(array_keys(NotificationCatalog::EVENTS))->diff($grouped)->all())->toBe([]);
    foreach (['en', 'ar'] as $locale) {
        foreach ($grouped as $event) {
            $key = 'notification_rules_admin.ev.'.str_replace('.', '_', $event);
            expect(trans($key.'.name', [], $locale))->not->toBe($key.'.name')->and(trans($key.'.when', [], $locale))->not->toBe($key.'.when');
        }
    }
});

it('keeps the English and Arabic notification-control texts in step', function () {
    $en = Arr::dot(require base_path('lang/en/notification_rules_admin.php'));
    $ar = Arr::dot(require base_path('lang/ar/notification_rules_admin.php'));

    expect(array_keys($ar))->toEqualCanonicalizing(array_keys($en))
        ->and(collect($ar)->merge($en)->filter(fn ($v) => trim((string) $v) === '')->all())->toBe([])
        ->and(collect($ar)->filter(fn ($v, $k) => $v === $en[$k] && ! in_array($k, ['channels.sms'], true))->keys()->all())->toBe([]); // nothing left untranslated
});

// ── The master switch of an event ────────────────────────────────────────────────────────────────────────────────────

it('sends and stores nothing for an event that is switched off, while the next step still sends', function () {
    $u = User::factory()->create();
    $this->rules->set('order.confirmed', false);

    expect(($this->notify)($u, 'order.confirmed'))->toBe([])
        ->and(Notification::where('event', 'order.confirmed')->count())->toBe(0)
        ->and(NotificationLog::where('event', 'order.confirmed')->count())->toBe(0);

    // The next step of the same order is a different notification and is untouched.
    expect(($this->notify)($u, 'order.ready_for_pickup'))->toBe(['push', 'in_app', 'sms'])
        ->and(Notification::where('event', 'order.ready_for_pickup')->count())->toBe(1)
        ->and(NotificationLog::where('event', 'order.ready_for_pickup')->count())->toBe(2);
});

it('skips only the channel that is switched off', function () {
    $u = User::factory()->create();
    $this->rules->set('support.reply', true, ['push' => false]);

    expect(($this->notify)($u, 'support.reply'))->toBe(['in_app', 'email'])
        ->and(Notification::where('event', 'support.reply')->count())->toBe(1)
        ->and(NotificationLog::where('event', 'support.reply')->pluck('channel')->all())->toBe(['email']);

    // A caller that names its own channels keeps them, minus the one switched off.
    expect(($this->notify)($u, 'support.reply', ['push', 'in_app']))->toBe(['in_app']);
});

it('adds a channel the admin switched on to an event sent with its default channels only', function () {
    $u = User::factory()->create();
    $this->rules->set('order.processing', true, ['push' => true]);

    expect($this->rules->channelEnabled('order.processing', 'push'))->toBeTrue()
        ->and(($this->notify)($u, 'order.processing'))->toBe(['in_app', 'push'])
        ->and(($this->notify)($u, 'order.processing', ['in_app']))->toBe(['in_app']); // an explicit list is the caller's own choice
});

it('never adds a send the customer opted out of or never agreed to', function () {
    $u = User::factory()->create(['marketing_consent_at' => null]);
    NotificationPreference::create(['user_id' => $u->id, 'channel' => 'push', 'category' => 'transactional', 'is_enabled' => false]);
    $this->rules->set('order.processing', true, ['push' => true, 'sms' => true]);
    $this->rules->set('stock.back_in_stock', true, ['email' => true]);

    // Push is opted out: the rule cannot bring it back. SMS is allowed by default for transactional messages.
    expect(($this->notify)($u, 'order.processing'))->toBe(['in_app', 'sms'])
        // No marketing consent: nothing goes out whatever the rules say.
        ->and(($this->notify)($u, 'stock.back_in_stock'))->toBe([]);
});

it('does nothing for a customer who is not active, with or without rules', function () {
    $u = User::factory()->create(['status' => 'suspended']);
    expect(($this->notify)($u, 'support.reply'))->toBe([]);
});

// ── Locked ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('never lets sign-in codes be switched off', function () {
    expect($this->rules->isLocked('otp'))->toBeTrue();
    expect(fn () => $this->rules->set('otp', false))->toThrow(DomainException::class)
        ->and(fn () => $this->rules->set('otp', true, ['sms' => false]))->toThrow(DomainException::class);
    expect(NotificationRule::count())->toBe(0);

    // Even a row put there behind the service's back (an old import, a manual insert) cannot stop a code.
    NotificationRule::create(['event' => 'otp', 'is_enabled' => false, 'channels' => ['sms' => false]]);
    Cache::flush();
    expect($this->rules->enabled('otp'))->toBeTrue()->and($this->rules->allows('otp', 'sms'))->toBeTrue()->and($this->rules->effectiveChannels('otp', ['sms']))->toBe(['sms'])
        ->and($this->rules->channelEnabled('otp', 'sms'))->toBeTrue();
});

it('refuses to switch off sign-in codes from the page too, and shows them as always on', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    Livewire::test(NotificationRulesIndex::class)
        ->assertSee(__('notification_rules_admin.locked'))
        ->call('toggleMaster', 'otp')->assertDispatched('toast', type: 'error', message: __('notification_rules_admin.err_locked'))
        ->call('toggleChannel', 'otp', 'sms')->assertDispatched('toast', type: 'error', message: __('notification_rules_admin.err_locked'));
    expect(NotificationRule::count())->toBe(0);
});

// ── Saving, normalising, caching, audit ──────────────────────────────────────────────────────────────────────────────

it('stores only what differs from the default and removes the row when nothing does', function () {
    $this->rules->set('order.created', true, ['push' => false, 'email' => true, 'sms' => false, 'in_app' => true, 'bogus' => true]);
    // email is a default of order.created (so true is dropped), sms is not (so false is dropped), in_app is a default (dropped), bogus is unknown.
    expect(NotificationRule::where('event', 'order.created')->firstOrFail()->channels)->toBe(['push' => false]);

    $this->rules->set('order.created', true, []);
    expect(NotificationRule::count())->toBe(0)->and($this->rules->state('order.created')['customized'])->toBeFalse();

    expect(fn () => $this->rules->set('no.such_event', false))->toThrow(InvalidArgumentException::class);
});

it('flushes its cache on every change, so a switch applies at once', function () {
    expect($this->rules->enabled('order.cancelled'))->toBeTrue()->and(Cache::has('notification_rules:v1'))->toBeTrue();

    $this->rules->set('order.cancelled', false);
    expect(Cache::has('notification_rules:v1'))->toBeFalse()->and($this->rules->enabled('order.cancelled'))->toBeFalse()->and(Cache::has('notification_rules:v1'))->toBeTrue();

    $this->rules->reset('order.cancelled');
    expect(Cache::has('notification_rules:v1'))->toBeFalse()->and($this->rules->enabled('order.cancelled'))->toBeTrue();

    $this->rules->set('order.cancelled', false);
    $this->rules->set('payment.failed', true, ['push' => false]);
    expect($this->rules->resetAll())->toBe(2)->and(NotificationRule::count())->toBe(0)->and($this->rules->enabled('order.cancelled'))->toBeTrue();
});

it('writes every change to the audit log with the old and the new state, and nothing for a change that changes nothing', function () {
    $admin = staffWithRole('marketing_manager');
    $this->actingAs($admin);
    $this->rules->set('order.confirmed', false);
    $this->rules->set('order.confirmed', false); // same again: no write, no log
    $this->rules->set('order.confirmed', false, ['push' => false]);
    $this->rules->reset('order.confirmed');
    $this->rules->reset('order.confirmed'); // nothing to reset

    $log = AuditLog::where('action', 'notifications.rule_changed')->orderBy('id')->get();
    expect($log)->toHaveCount(3)
        ->and($log->every(fn ($l) => $l->actor_id === $admin->id && $l->metadata['event'] === 'order.confirmed'))->toBeTrue()
        ->and($log[0]->old_values)->toEqual(['is_enabled' => true, 'channels' => []])->and($log[0]->new_values)->toEqual(['is_enabled' => false, 'channels' => []])
        ->and($log[1]->new_values)->toEqual(['is_enabled' => false, 'channels' => ['push' => false]])
        ->and($log[2]->metadata['reset'])->toBeTrue()->and($log[2]->new_values)->toEqual(['is_enabled' => true, 'channels' => []])
        ->and(NotificationRule::count())->toBe(0);
});

it('re-checks the rules when a queued message is about to go out', function () {
    $u = User::factory()->create();
    $log = NotificationLog::create(['user_id' => $u->id, 'event' => 'order.ready_for_pickup', 'channel' => 'sms', 'status' => 'queued']);
    $this->rules->set('order.ready_for_pickup', true, ['sms' => false]);

    SendNotificationJob::dispatchSync($log->id, 'T', 'B');
    expect($log->fresh()->status)->toBe('skipped')->and($log->fresh()->response)->toBe(['reason' => 'switched_off']);

    // A channel that is not a default of the event is the caller's choice and still goes out.
    $own = NotificationLog::create(['user_id' => $u->id, 'event' => 'payment.link', 'channel' => 'whatsapp', 'status' => 'queued']);
    SendNotificationJob::dispatchSync($own->id, 'T', 'B');
    expect($own->fresh()->response['reason'] ?? null)->not->toBe('switched_off');
});

// ── The live order card ──────────────────────────────────────────────────────────────────────────────────────────────

it('skips the card step of an event that is switched off, and the card carries on with the next step', function () {
    $w = rulesWorld();
    $order = lopPlace($w);
    rulesPhone($w->customer);
    $this->rules->set('order.confirmed', false); // "order accepted" = the Preparing step of the card

    lopMove($order, FS::Preparing);
    app(PushNotifier::class)->refreshOrder($order->id);
    expect(lopSent())->toBe([]);

    // The next step (on the way) belongs to another event, which is on.
    lopMove($order, FS::Dispatched, delivery: DS::PickedUp, driver: driverFor($w->a));
    app(PushNotifier::class)->refreshOrder($order->id);
    expect(lopSent())->toHaveCount(1)->and(lopSent()[0]['data']['phase'])->toBe('on_the_way');
});

it('sends the card step again once its event is switched back on', function () {
    $w = rulesWorld();
    $order = lopPlace($w);
    rulesPhone($w->customer);
    lopMove($order, FS::Preparing);
    $this->rules->set('order.confirmed', false);
    app(PushNotifier::class)->refreshOrder($order->id);
    expect(lopSent())->toBe([]);

    $this->rules->reset('order.confirmed');
    app(PushNotifier::class)->refreshOrder($order->id);
    expect(lopSent())->toHaveCount(1)->and(lopSent()[0]['data']['phase'])->toBe('preparing');
});

it('turns the card off on its own for one step: the message stays and the classic push takes the card\'s place', function () {
    $w = rulesWorld();
    $order = lopPlace($w, 'pickup');
    rulesPhone($w->customer);
    lopMove($order, FS::PickupReady);

    // Default: the card announces it, so the classic push stays out.
    expect(($this->notify)($w->customer, 'order.ready_for_pickup'))->toBe(['in_app', 'sms']);

    $this->rules->set('order.ready_for_pickup', true, ['live_card' => false]);
    app(PushNotifier::class)->refreshOrder($order->id);
    expect(lopSent())->toBe([])
        ->and(($this->notify)($w->customer, 'order.ready_for_pickup'))->toBe(['push', 'in_app', 'sms'])
        ->and($this->rules->channelEnabled('order.ready_for_pickup', 'live_card'))->toBeFalse()->and($this->rules->channelEnabled('order.ready_for_pickup', 'push'))->toBeTrue();

    $this->rules->reset('order.ready_for_pickup');
    app(PushNotifier::class)->refreshOrder($order->id);
    expect(lopSent())->toHaveCount(1)->and(lopSent()[0]['data']['phase'])->toBe('ready');
});

it('stops the card AND the message when the whole event is off', function () {
    $w = rulesWorld();
    $order = lopPlace($w, 'pickup');
    rulesPhone($w->customer);
    lopMove($order, FS::PickupReady);
    $this->rules->set('order.ready_for_pickup', false);

    app(PushNotifier::class)->refreshOrder($order->id);
    expect(lopSent(all: true))->toBe([])->and(($this->notify)($w->customer, 'order.ready_for_pickup'))->toBe([]);
});

it('lets a failed delivery through to the card whatever is switched off', function () {
    $w = rulesWorld();
    foreach (NotificationCatalog::LIVE_CARD_EVENTS as $event) {
        $this->rules->set($event, false);
    }
    $order = lopPlace($w);
    rulesPhone($w->customer);
    lopMove($order, FS::Failed, delivery: DS::Failed, driver: driverFor($w->a));
    app(PushNotifier::class)->refreshOrder($order->id);

    expect(NotificationCatalog::cardEvent('failed'))->toBeNull()->and(lopSent())->toHaveCount(1)->and(lopSent()[0]['data']['phase'])->toBe('failed');
});

// ── Staff and driver alerts ──────────────────────────────────────────────────────────────────────────────────────────

it('switches the driver and branch pushes one by one', function () {
    $w = rulesWorld();
    $driver = User::factory()->staff()->create();
    $staff = staffWithRole('order_manager', $w->a);
    rulesPhone($driver, 'tok-driver');
    rulesPhone($staff, 'tok-staff');
    $assigned = new DispatchUpdated($w->a->id, 'AB1', 'delivery', 'assigned', $driver->id);
    $failed = new DispatchUpdated($w->a->id, 'AB1', 'delivery', 'failed');
    $ready = new DispatchUpdated($w->a->id, 'AB1', 'order', 'ready');
    $push = fn (DispatchUpdated $e) => app(PushNotifier::class)->dispatchUpdated($e);
    $tokens = fn () => collect(lopSent(all: true))->pluck('token')->all();

    $push($assigned);
    $push($failed);
    $push($ready);
    expect($tokens())->toBe(['tok-driver', 'tok-staff', 'tok-staff']);

    $this->rules->set('staff.driver_assigned', false);
    $this->rules->set('staff.delivery_failed', false);
    $push($assigned);
    $push($failed);
    expect($tokens())->toBe(['tok-driver', 'tok-staff', 'tok-staff']); // both are off, nothing new

    $push($ready); // still on
    expect($tokens())->toBe(['tok-driver', 'tok-staff', 'tok-staff', 'tok-staff']);
    $this->rules->set('staff.order_ready', false);
    $push($ready);
    expect($tokens())->toHaveCount(4);
});

// ── Other send paths: campaigns and payment links ────────────────────────────────────────────────────────────────────

it('holds a campaign back while campaign messages are switched off', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    User::factory()->create(['marketing_consent_at' => now()]);
    $campaign = MarketingCampaign::create(['name' => 'Eid', 'title_en' => 'Eid sale', 'title_ar' => 'تخفيضات العيد', 'body_en' => 'Up to 30% off', 'body_ar' => 'خصم حتى ٣٠٪',
        'deep_link' => null, 'channels' => ['push', 'in_app'], 'status' => 'draft', 'segment' => []]);
    $this->rules->set('campaign', false);

    expect(app(CampaignService::class)->send($campaign))->toBe(0)
        ->and($campaign->fresh()->status)->toBe('draft')->and(Notification::where('event', 'campaign')->count())->toBe(0);

    Livewire::test(CampaignIndex::class)->call('sendNow', $campaign->id)->assertDispatched('toast', type: 'error', message: __('notification_rules_admin.campaign_off'));
    expect($campaign->fresh()->status)->toBe('draft');

    $this->rules->reset('campaign');
    expect(app(CampaignService::class)->send($campaign->fresh()))->toBeGreaterThan(0)->and($campaign->fresh()->status)->toBe('sent')->and(Notification::where('event', 'campaign')->count())->toBeGreaterThan(0);
});

it('tells staff plainly when payment link messages are switched off instead of reporting a link as sent', function () {
    rulesConfigureSadad();
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $order = Order::findOrFail(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'sadad'])->assertCreated()->json('data.order.id'));
    $mgr = User::factory()->superAdmin()->create();

    expect(app(StaffOrderService::class)->sendPaymentLink($mgr, $order))->toContain('in_app');
    $this->rules->set('payment.link', false);
    expect(fn () => app(StaffOrderService::class)->sendPaymentLink($mgr, $order))->toThrow(DomainException::class, __('notification_rules_admin.err_payment_link_off'));
});

// ── The order status flow, end to end ────────────────────────────────────────────────────────────────────────────────

it('sends the "order accepted" message when an online payment confirms the order, and nothing when it is switched off', function (bool $off) {
    rulesConfigureSadad();
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $res = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'sadad'])->assertCreated();
    $payment = Payment::findOrFail($res->json('data.payment.payment_id'));
    if ($off) {
        $this->rules->set('order.confirmed', false);
    }

    rulesSadadCallback(['ORDERID' => $payment->id, 'RESPCODE' => '1', 'RESPMSG' => 'Txn Success', 'TXNAMOUNT' => $payment->amount, 'transaction_number' => 'SD100']);

    $events = Notification::where('user_id', $w->customer->id)->pluck('event')->all();
    expect(Order::findOrFail($res->json('data.order.id'))->status->value)->toBe('confirmed')
        ->and($events)->toContain('payment.succeeded')
        ->and(in_array('order.confirmed', $events, true))->toBe(! $off);
})->with(['switched on' => false, 'switched off' => true]);

it('sends the "order ready" message when a pickup order is ready, and nothing when it is switched off, while earlier steps still go out', function (bool $off) {
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $order = Order::with('fulfillments')->findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'));
    $mgr = staffWithRole('order_manager', $w->a);
    if ($off) {
        $this->rules->set('order.ready_for_pickup', false);
    }

    $svc = app(OrderService::class);
    foreach ([FS::Accepted, FS::Preparing, FS::PickupReady] as $step) {
        $svc->transition($order->fulfillments->first()->fresh(), $step, $mgr);
    }

    $events = Notification::where('user_id', $w->customer->id)->pluck('event')->all();
    expect($order->fresh()->status->value)->toBe('ready_for_pickup')
        ->and($events)->toContain('order.created')->toContain('order.processing')
        ->and(in_array('order.ready_for_pickup', $events, true))->toBe(! $off)
        ->and(NotificationLog::where('event', 'order.ready_for_pickup')->count())->toBe($off ? 0 : 2); // sms and the card-less push
})->with(['switched on' => false, 'switched off' => true]);

it('reacts to the status event itself, the way the app does, for an order the admin switched off', function () {
    $w = commerceWorld();
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1]);
    $order = Order::findOrFail(quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id'));
    $this->rules->set('order.cancelled', false);

    event(new OrderStatusChanged($order->id, 'confirmed', 'cancelled'));
    $events = Notification::where('user_id', $w->customer->id)->pluck('event')->all();
    expect(in_array('order.cancelled', $events, true))->toBeFalse();

    $this->rules->reset('order.cancelled');
    event(new OrderStatusChanged($order->id, 'confirmed', 'cancelled'));
    expect(Notification::where('user_id', $w->customer->id)->where('event', 'order.cancelled')->count())->toBe(1);
});

// ── The dashboard page ───────────────────────────────────────────────────────────────────────────────────────────────

it('opens for people who may see notifications, and is in the Content menu next to the templates', function () {
    $this->actingAs(staffWithRole('marketing_manager'))->get(route('admin.notification-rules'))->assertOk()->assertSee(__('notification_rules_admin.title'));
    $this->actingAs(staffWithRole('delivery_employee', Branch::factory()->create()))->get(route('admin.notification-rules'))->assertForbidden();

    $content = collect(AdminNavigation::for(staffWithRole('marketing_manager')))->firstWhere('label', 'nav.content');
    expect(collect($content['items'])->pluck('route')->all())->toContain('admin.notification-templates.index', 'admin.notification-rules');
});

it('shows every notification grouped, with friendly names and a "when it is sent" line, in English and Arabic', function () {
    $this->actingAs(staffWithRole('marketing_manager'));

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $page = Livewire::test(NotificationRulesIndex::class)
            ->assertViewHas('sections', fn (array $sections) => collect($sections)->pluck('group')->all() === array_keys(NotificationRules::GROUPS))
            ->assertViewHas('total', count(NotificationCatalog::EVENTS) + count(NotificationRules::STAFF_EVENTS))
            ->assertSee(__('notification_rules_admin.groups.orders'))->assertSee(__('notification_rules_admin.groups.staff'))
            ->assertSee(__('notification_rules_admin.ev.order_confirmed.name'))->assertSee(__('notification_rules_admin.ev.order_confirmed.when'))
            ->assertSee(__('notification_rules_admin.ev.order_ready_for_pickup.name'))->assertSee(__('notification_rules_admin.ev.staff_driver_assigned.name'))
            ->assertSee(__('notification_rules_admin.banner_body'));
        // The live-card switch exists on the six card events only.
        $html = $page->html();
        expect($html)->toContain("toggleChannel('order.confirmed', 'live_card')")->not->toContain("toggleChannel('order.processing', 'live_card')")
            ->and(preg_match_all('/wire:click="toggleChannel\(\'[a-z._]+\', \'live_card\'\)"/', $html))->toBe(count(NotificationCatalog::LIVE_CARD_EVENTS));
    }
    app()->setLocale('en');
    expect(__('notification_rules_admin.ev.order_confirmed.name'))->toBe('Order accepted');
});

it('saves a switch at once, tells the admin with a toast that can undo it, and writes the audit log', function () {
    $admin = staffWithRole('marketing_manager');
    $this->actingAs($admin);
    $page = Livewire::test(NotificationRulesIndex::class)->call('toggleMaster', 'order.confirmed')
        ->assertDispatched('toast', type: 'success', message: __('notification_rules_admin.toast_master_off', ['name' => 'Order accepted']), undoEvent: 'notification-rule-undo');

    expect(NotificationRule::where('event', 'order.confirmed')->value('is_enabled'))->toBeFalse()
        ->and(AuditLog::where('action', 'notifications.rule_changed')->where('actor_id', $admin->id)->count())->toBe(1)
        ->and($this->rules->enabled('order.confirmed'))->toBeFalse();
    $page->assertViewHas('anyCustomized', true)->assertSee(__('notification_rules_admin.badge_off'));

    $page->call('toggleChannel', 'order.confirmed', 'email'); // email is not a default of order.confirmed: switching it on is a deviation
    expect($this->rules->state('order.confirmed'))->toBe(['enabled' => false, 'channels' => ['email' => true], 'customized' => true]);

    $page->call('toggleMaster', 'order.confirmed');
    expect($this->rules->enabled('order.confirmed'))->toBeTrue();
    $page->call('toggleChannel', 'order.confirmed', 'push')->assertDispatched('toast', type: 'success', message: __('notification_rules_admin.toast_channel_off', ['name' => 'Order accepted', 'channel' => 'Push']));
    expect($this->rules->channelEnabled('order.confirmed', 'push'))->toBeFalse();
});

it('puts a change back exactly as it was when the admin presses Undo', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    $page = Livewire::test(NotificationRulesIndex::class);

    $page->call('toggleMaster', 'payment.failed');
    expect(NotificationRule::count())->toBe(1);
    $page->dispatch('notification-rule-undo', rule: 'payment.failed', enabled: true, channels: [], customized: false)->assertDispatched('toast', type: 'success', message: __('notification_rules_admin.toast_undone'));
    expect(NotificationRule::count())->toBe(0);

    $this->rules->set('support.reply', true, ['push' => false]);
    $page->call('toggleMaster', 'support.reply');
    expect($this->rules->state('support.reply')['enabled'])->toBeFalse();
    $page->dispatch('notification-rule-undo', rule: 'support.reply', enabled: true, channels: ['push' => false], customized: true);
    expect($this->rules->state('support.reply'))->toBe(['enabled' => true, 'channels' => ['push' => false], 'customized' => true]);
});

it('lets people who may only look, look, and refuses every change with 403', function () {
    $this->rules->set('order.completed', false);
    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(NotificationRulesIndex::class)->assertOk()->assertSee(__('notification_rules_admin.view_only'));

    $attempts = [
        fn ($p) => $p->call('toggleMaster', 'order.confirmed'),
        fn ($p) => $p->call('toggleChannel', 'order.confirmed', 'push'),
        fn ($p) => $p->call('resetRule', 'order.completed'),
        fn ($p) => $p->call('resetAll'),
        fn ($p) => $p->dispatch('notification-rule-undo', rule: 'order.completed', enabled: true, channels: [], customized: false),
    ];
    foreach ($attempts as $attempt) {
        $attempt(Livewire::test(NotificationRulesIndex::class))->assertForbidden();
    }
    expect($this->rules->enabled('order.confirmed'))->toBeTrue()->and($this->rules->enabled('order.completed'))->toBeFalse();
});

it('answers 404 for a notification, a channel or a group that does not exist', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    foreach ([['toggleMaster', 'no.such_event'], ['toggleChannel', 'order.processing', 'live_card'], ['toggleChannel', 'staff.order_ready', 'sms'], ['toggleGroup', 'nope']] as $call) {
        Livewire::test(NotificationRulesIndex::class)->call(...$call)->assertNotFound();
    }
    expect(NotificationRule::count())->toBe(0);
});

it('resets one notification, and all of them after a confirmation', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    $this->rules->set('order.completed', false);
    $this->rules->set('payment.failed', true, ['push' => false]);
    $page = Livewire::test(NotificationRulesIndex::class)->assertSee(__('notification_rules_admin.reset_all_title'));

    $page->call('resetRule', 'order.completed')->assertDispatched('toast', type: 'success', undoEvent: 'notification-rule-undo');
    expect($this->rules->enabled('order.completed'))->toBeTrue()->and(NotificationRule::count())->toBe(1);

    $page->call('resetAll')->assertDispatched('toast', type: 'success', message: __('notification_rules_admin.toast_reset_all'));
    expect(NotificationRule::count())->toBe(0);
    $page->assertViewHas('anyCustomized', false);
});

it('flags a channel that is not set up on the server instead of letting it look usable', function () {
    $this->actingAs(User::factory()->superAdmin()->create());
    config(['services.sms.driver' => 'null', 'mail.default' => 'log', 'services.whatsapp.phone_number_id' => null, 'services.whatsapp.access_token' => null, 'services.fcm.project_id' => null, 'services.fcm.credentials_path' => null]);

    $page = Livewire::test(NotificationRulesIndex::class)
        ->assertViewHas('channelStatus', fn (array $s) => $s === ['push' => false, 'in_app' => true, 'sms' => false, 'whatsapp' => false, 'email' => false, 'live_card' => false])
        ->assertViewHas('setup', fn (array $s) => collect($s)->pluck('channel')->all() === ['push', 'sms', 'whatsapp', 'email', 'live_card'])
        ->assertSee(__('notification_rules_admin.setup_title'))->assertSee(__('notification_rules_admin.not_set_up'));
    $page->assertViewHas('sections', function (array $sections) {
        $row = collect($sections)->flatMap(fn ($s) => $s['rows'])->firstWhere('event', 'order.ready_for_pickup');

        return $row['channels']['sms']['ready'] === false && $row['channels']['in_app']['ready'] === true;
    });
    // The switch of a channel that cannot send is disabled on the page.
    expect($page->html())->toContain('disabled');
    // A person who may open the Push page gets the link to it.
    $page->assertViewHas('setup', fn (array $s) => collect($s)->firstWhere('channel', 'push')['url'] === route('admin.push.index'));

    rulesWorld(); // Firebase set up (through its server variables) and mail, SMS and WhatsApp too
    config(['services.sms.driver' => 'http', 'services.sms.http.url' => 'https://sms.example/send', 'mail.default' => 'smtp', 'services.whatsapp.phone_number_id' => '1', 'services.whatsapp.access_token' => 'x']);
    expect(app(NotificationChannelStatus::class)->ready())->toBe(['push' => true, 'in_app' => true, 'sms' => true, 'whatsapp' => true, 'email' => true, 'live_card' => true]);
});

it('filters by group and searches in both languages', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    $page = Livewire::test(NotificationRulesIndex::class)
        ->call('toggleGroup', 'payments')->assertViewHas('total', 4)->assertViewHas('sections', fn (array $s) => count($s) === 1)
        ->call('toggleGroup', 'staff')->assertViewHas('total', 7)
        ->call('toggleGroup', 'payments')->call('toggleGroup', 'staff')->assertViewHas('total', count(NotificationCatalog::EVENTS) + 3);

    $page->set('q', 'accepted')->assertViewHas('total', 1)->assertSee('Order accepted')
        ->set('q', 'قبول')->assertViewHas('total', 1)
        ->set('q', 'order.ready_for_pickup')->assertViewHas('total', 1)
        ->set('q', 'no such thing')->assertViewHas('total', 0)->assertSee(__('notification_rules_admin.empty'))
        ->call('clearFilters')->assertSet('q', '')->assertSet('only', [])->assertViewHas('total', count(NotificationCatalog::EVENTS) + 3);
});

it('warns when something customers rely on is switched off', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    Livewire::test(NotificationRulesIndex::class)->assertDontSee(__('notification_rules_admin.warn.payment_link'));

    $this->rules->set('payment.link', false);
    $this->rules->set('payment.failed', false);
    $this->rules->set('order.completed', true, ['live_card' => false]);
    Livewire::test(NotificationRulesIndex::class)->assertSee(__('notification_rules_admin.warn.payment_link'))->assertSee(__('notification_rules_admin.warn.payment_failed'))
        ->assertSee(__('notification_rules_admin.warn.live_card_final'))->assertDontSee(__('notification_rules_admin.warn.campaign'));
});

it('shows how many messages went out in the last 30 days, from the delivery log', function () {
    $this->actingAs(staffWithRole('marketing_manager'));
    $u = User::factory()->create();
    foreach (['sent', 'delivered', 'sent', 'failed', 'queued'] as $status) {
        NotificationLog::create(['user_id' => $u->id, 'event' => 'order.created', 'channel' => 'push', 'status' => $status]);
    }
    $old = NotificationLog::create(['user_id' => $u->id, 'event' => 'order.created', 'channel' => 'push', 'status' => 'sent']);
    NotificationLog::whereKey($old->id)->update(['created_at' => now()->subDays(45)]);

    expect($this->rules->sentCounts())->toBe(['order.created' => 3]);
    Livewire::test(NotificationRulesIndex::class)->assertSee(__('notification_rules_admin.sent_30', ['n' => 3]));
});
