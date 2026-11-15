<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\LiveActivityToken;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\ApnsConfig;
use App\Domain\Notifications\Services\PushNotifier;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus as OS;
use App\Domain\Orders\Events\OrderUpdated;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/ApnsSupport.php';
require_once __DIR__.'/../Commerce/LiveOrderHelpers.php';

/*
 * The live order card reaches an iPhone's Live Activity straight from Apple's servers: updates through the pipeline the FCM card already
 * uses, the end when the order is final, the language of each phone, the priorities, and never a failure into the order flow.
 * Clock frozen at Sat 14 Nov 2026 18:00 Qatar, as in LiveOrderPushTest.
 */

beforeEach(function () {
    lopFreeze();
    config(['platform.live_order.progress_steps' => 0, 'platform.live_order.countdown_shift_seconds' => 0, 'platform.live_order.apns_backoff_ms' => 0]);
    $this->w = commerceWorld();
    configureApns();
    $this->apns = fakeApns();
    $this->push = fn (Order $o) => app(PushNotifier::class)->refreshOrder($o->id);
    $this->token = fn (Order $o, string $seed = 'a', array $attrs = []) => activityToken($this->w->customer->id, $o, hexToken($seed), $attrs);
    $this->preparing = fn () => lopMove(lopPlace($this->w), FS::Preparing);
});

// ── The update ───────────────────────────────────────────────────────────────────────────────────────────────────────

it('updates the running activity with the card: the stage, the arrival window, the texts and the clock', function () {
    $order = ($this->preparing)();
    $token = ($this->token)($order);
    ($this->push)($order);

    $sent = apnsSent();
    expect($sent)->toHaveCount(1);
    $aps = $sent[0]['aps'];
    expect($sent[0]['url'])->toBe('https://api.push.apple.com/3/device/'.hexToken('a'))
        ->and($sent[0]['headers']['apns-push-type'])->toBe(['liveactivity'])
        ->and($sent[0]['headers']['apns-topic'])->toBe(['qa.albahar.customer.push-type.liveactivity'])
        ->and($sent[0]['headers']['apns-priority'])->toBe(['10'])
        ->and($sent[0]['headers']['apns-expiration'])->toBe([(string) (now()->getTimestamp() + 3600)])
        ->and(array_keys($aps))->toBe(['timestamp', 'content-state', 'event', 'stale-date'])
        ->and($aps['event'])->toBe('update')
        ->and($aps['timestamp'])->toBe(now()->getTimestamp())                  // the card's `seq` in seconds: Apple applies updates in this order
        ->and($aps['stale-date'])->toBe(now()->getTimestamp() + 1800);
    expect($aps['content-state'])->toBe([
        'phase' => 'preparing', 'detail' => 'preparing', 'step' => 1, 'total' => 4, 'sub' => $aps['content-state']['sub'],
        'title' => 'Arrives at 6:35pm-6:55pm', 'body' => 'Being prepared at '.$order->fulfillments->first()->branch->name_en.'.', 'step_label' => 'Preparing',
        'eta_label' => '6:35pm-6:55pm', 'eta_from' => strtotime('2026-11-14T15:35:00Z'), 'eta_to' => strtotime('2026-11-14T15:55:00Z'),
        'done' => false, 'lang' => 'en',
    ]);
    expect($token->fresh()->last_pushed_at)->not->toBeNull()->and($token->fresh()->disabled_at)->toBeNull();
});

it('leaves out what the card does not have: no sub-progress, no countdown, no driver', function () {
    $order = lopPlace($this->w);
    ($this->token)($order);
    ($this->push)($order);

    $state = apnsSent()[0]['aps']['content-state'];
    expect($state)->toMatchArray(['phase' => 'placed', 'step' => 0, 'done' => false])->not->toHaveKeys(['sub', 'eta_at', 'driver_first_name']);
    expect(array_filter($state, fn ($v) => $v === null))->toBe([]);
});

it('carries the driver\'s first name and the countdown while the order is on the road, and never his phone number or vehicle', function () {
    $driver = driverFor($this->w->a);
    $driver->update(['name' => 'Ahmed Al Marri', 'phone' => '+97455123456']);
    $order = lopMove(lopPlace($this->w), FS::Dispatched, delivery: DS::OutForDelivery, driver: $driver);
    ($this->token)($order);
    ($this->push)($order);

    $state = apnsSent()[0]['aps']['content-state'];
    expect($state)->toMatchArray(['phase' => 'on_the_way', 'step' => 2, 'driver_first_name' => 'Ahmed'])->toHaveKey('eta_at');
    $raw = json_encode(apnsSent()[0]['body']);
    expect($raw)->not->toContain('97455123456')->not->toContain('Al Marri')->not->toContain('driver_phone')->not->toContain('vehicle');
});

it('never carries the customer\'s address or number', function () {
    $order = ($this->preparing)();
    ($this->token)($order);
    ($this->push)($order);

    $raw = json_encode(apnsSent()[0]['body']);
    foreach (['97455123456', 'Noor', '850', 'street', 'recipient'] as $private) {
        expect($raw)->not->toContain($private);
    }
});

it('writes each activity in the language its phone reported', function () {
    $order = ($this->preparing)();
    ($this->token)($order, 'en', ['locale' => 'en']);
    ($this->token)($order, 'ar', ['locale' => 'ar']);
    ($this->token)($order, 'none', ['locale' => null]);
    ($this->push)($order);

    $byToken = collect(apnsSent())->mapWithKeys(fn ($s) => [basename($s['url']) => $s['aps']['content-state']]);
    expect($byToken[hexToken('en')])->toMatchArray(['title' => 'Arrives at 6:35pm-6:55pm', 'lang' => 'en'])
        ->and($byToken[hexToken('ar')])->toMatchArray(['title' => 'تصل بين 6:35 م و 6:55 م', 'lang' => 'ar'])
        ->and($byToken[hexToken('ar')]['body'])->toStartWith('قيد التجهيز في')
        ->and($byToken[hexToken('none')]['lang'])->toBe('en');
});

it('says nothing when nothing changed, and updates once per real change', function () {
    $order = ($this->preparing)();
    ($this->token)($order);
    ($this->push)($order);
    ($this->push)($order);
    expect(apnsSent())->toHaveCount(1);

    lopMove($order, FS::Dispatched, delivery: DS::OutForDelivery, driver: driverFor($this->w->a));
    ($this->push)($order);
    ($this->push)($order);

    expect(array_map(fn ($s) => $s['aps']['content-state']['phase'], apnsSent()))->toBe(['preparing', 'on_the_way']);
});

it('sends a move that is not a stage change (the window slid) at Apple\'s low priority, and a stage change at the high one', function () {
    $order = ($this->preparing)();
    ($this->token)($order);
    ($this->push)($order);
    $this->travelTo(now()->addMinutes(33));                                              // 18:33: the window slides from 18:35-18:55 to 18:45-19:00
    ($this->push)($order);
    lopMove($order, FS::Dispatched, delivery: DS::OutForDelivery, driver: driverFor($this->w->a));
    ($this->push)($order);

    $sent = apnsSent();
    expect(array_map(fn ($s) => $s['headers']['apns-priority'][0], $sent))->toBe(['10', '5', '10'])
        ->and(array_map(fn ($s) => $s['aps']['content-state']['phase'], $sent))->toBe(['preparing', 'preparing', 'on_the_way'])
        ->and(array_map(fn ($s) => $s['aps']['event'], $sent))->toBe(['update', 'update', 'update']);
});

// ── The end ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('ends the activity when the order is final, showing the last state for 30 minutes, and then leaves the token alone', function () {
    $order = ($this->preparing)();
    $token = ($this->token)($order);
    ($this->push)($order);
    $order->forceFill(['status' => OS::Cancelled->value, 'cancelled_at' => now()])->save();
    ($this->push)($order);

    $end = apnsSent()[1];
    expect($end['aps']['event'])->toBe('end')
        ->and($end['aps']['content-state'])->toMatchArray(['phase' => 'cancelled', 'done' => true, 'title' => 'Order cancelled'])
        ->and($end['aps']['dismissal-date'])->toBe(now()->getTimestamp() + 1800)
        ->and($end['aps'])->not->toHaveKey('stale-date')
        ->and($end['headers']['apns-priority'])->toBe(['10'])
        ->and($token->fresh()->disabled_at)->not->toBeNull();

    ($this->push)($order);
    expect(apnsSent())->toHaveCount(2);                                                  // an ended activity is never written to again
});

it('does not touch an activity of an order that finished long ago', function () {
    $order = ($this->preparing)();
    ($this->token)($order);
    $order->forceFill(['status' => OS::Cancelled->value, 'cancelled_at' => now()->subHours(3)])->save();
    $this->travelTo(now()->addHour());
    ($this->push)($order);

    expect(apnsSent())->toBe([]);
});

// ── Whose, and when ──────────────────────────────────────────────────────────────────────────────────────────────────

it('only uses the activity tokens of this order and of the customer who placed it', function () {
    $order = ($this->preparing)();
    $other = lopPlace($this->w);
    ($this->token)($order, 'mine');
    ($this->token)($other, 'other-order');
    activityToken(User::factory()->create()->id, $order, hexToken('other-user'));
    ($this->token)($order, 'off', ['disabled_at' => now()]);
    ($this->push)($order);

    expect(array_map(fn ($s) => basename($s['url']), apnsSent()))->toBe([hexToken('mine')]);
});

it('sends nothing, and calls nobody, while the Apple setup is switched off or incomplete', function (callable $break) {
    $order = ($this->preparing)();
    ($this->token)($order);
    $break();
    ($this->push)($order);

    expect(apnsSent())->toBe([]);
})->with([
    'switched off' => [fn () => app(Settings::class)->set(ApnsConfig::ENABLED, false)],
    'no key' => [fn () => app(Settings::class)->set(ApnsConfig::PRIVATE_KEY, null, secret: true)],
    'no team' => [fn () => app(Settings::class)->set(ApnsConfig::TEAM_ID, null)],
    'no key id' => [fn () => app(Settings::class)->set(ApnsConfig::KEY_ID, null)],
]);

it('follows the customer\'s choice: no order-update push, no live activity', function () {
    $order = ($this->preparing)();
    ($this->token)($order);
    NotificationPreference::create(['user_id' => $this->w->customer->id, 'channel' => 'push', 'category' => 'transactional', 'is_enabled' => false]);
    ($this->push)($order);

    expect(apnsSent())->toBe([]);
});

it('works without Firebase, and next to it without either disturbing the other', function () {
    $order = ($this->preparing)();
    ($this->token)($order);
    ($this->push)($order);
    expect(apnsSent())->toHaveCount(1)->and(lopSent())->toBe([]);                       // Firebase is not set up: the Live Activity still moves

    $fcm = lopFcm();                                                                     // (registering Firebase's fake starts a fresh record of requests)
    UserDevice::create(['user_id' => $this->w->customer->id, 'platform' => 'ios', 'push_token' => 'fcm-tok', 'locale' => 'en']);
    $second = ($this->preparing)();
    ($this->token)($second, 'second');
    ($this->push)($second);
    $cards = lopSent();
    expect(apnsSent())->toHaveCount(1)->and(basename(apnsSent()[0]['url']))->toBe(hexToken('second'))
        ->and(end($cards)['token'])->toBe('fcm-tok')->and(end($cards)['data']['phase'])->toBe('preparing')->and($fcm['mode'])->toBe('ok');
});

it('keeps sending the card through Firebase when Apple is down, and never throws into the order flow', function () {
    $fcm = lopFcm();
    $order = ($this->preparing)();
    ($this->token)($order);
    UserDevice::create(['user_id' => $this->w->customer->id, 'platform' => 'android', 'push_token' => 'fcm-tok', 'locale' => 'en']);
    $this->apns['mode'] = 'offline';

    ($this->push)($order);                                                               // does not throw

    expect(lopSent())->toHaveCount(1)->and($this->apns['calls'])->toBe(3);              // one try and two retries
    expect(LiveActivityToken::first()->disabled_at)->toBeNull();                         // a lost connection is not a dead token
});

it('retires a token Apple says is gone and keeps sending to the others', function () {
    $order = ($this->preparing)();
    $dead = ($this->token)($order, 'dead');
    $alive = ($this->token)($order, 'alive');
    $this->apns['mode'] = 'gone';
    ($this->push)($order);

    expect($dead->fresh()->disabled_at)->not->toBeNull()->and($alive->fresh()->disabled_at)->not->toBeNull()
        ->and(apnsSent())->toHaveCount(2);                                               // both were tried: one dead token did not stop the loop

    $this->apns['mode'] = 'ok';
    $order2 = ($this->preparing)();
    $ok = ($this->token)($order2, 'fine');
    ($this->push)($order2);
    expect($ok->fresh()->disabled_at)->toBeNull()->and($ok->fresh()->last_pushed_at)->not->toBeNull();
});

it('tries a failed update again with the next change of the card, not before', function () {
    $order = ($this->preparing)();
    ($this->token)($order);
    $this->apns['mode'] = 'down';
    ($this->push)($order);
    expect(apnsSent())->toHaveCount(3);

    $this->apns['mode'] = 'ok';
    ($this->push)($order);                                                               // nothing was delivered, so the card still counts as unsent
    expect(apnsSent())->toHaveCount(4)->and(apnsSent()[3]['aps']['content-state']['phase'])->toBe('preparing');
    ($this->push)($order);
    expect(apnsSent())->toHaveCount(4);
});

it('does not log a token or the key anywhere', function () {
    $order = ($this->preparing)();
    ($this->token)($order);
    $this->apns['mode'] = 'invalid_key';
    ($this->push)($order);

    $logs = json_encode(AuditLog::all()->toArray()).json_encode(NotificationLog::all()->toArray());
    expect($logs)->not->toContain(hexToken('a'))->not->toContain('PRIVATE KEY');
});

// ── Starting an activity from the server (iOS 17.2+, push-to-start) ──────────────────────────────────────────────────

it('does not start an activity by itself until remote start is switched on', function () {
    $order = ($this->preparing)();
    activityToken($this->w->customer->id, null, hexToken('start'));
    ($this->push)($order);

    expect(apnsSent())->toBe([]);
});

it('starts the activity through the phone\'s push-to-start token when the order has none, once per order and phone', function () {
    config(['platform.live_order.activity_remote_start' => true]);
    $order = ($this->preparing)();
    activityToken($this->w->customer->id, null, hexToken('start'), ['locale' => 'ar']);
    ($this->push)($order);

    $sent = apnsSent();
    expect($sent)->toHaveCount(1)->and($sent[0]['url'])->toBe('https://api.push.apple.com/3/device/'.hexToken('start'));
    $aps = $sent[0]['aps'];
    expect($aps['event'])->toBe('start')
        ->and($aps['attributes-type'])->toBe('LiveOrderAttributes')
        ->and($aps['attributes'])->toBe(['order_id' => $order->id, 'number' => $order->number, 'type' => 'delivery', 'brand_primary' => '#8A1538', 'brand_accent' => '#B3254F'])
        ->and($aps['alert'])->toBe(['title' => 'تصل بين 6:35 م و 6:55 م', 'body' => $aps['alert']['body'], 'sound' => 'default'])
        ->and($aps['content-state'])->toMatchArray(['phase' => 'preparing', 'lang' => 'ar'])
        ->and($aps['input-push-token'])->toBe(1)
        ->and($sent[0]['headers']['apns-priority'])->toBe(['10']);

    lopMove($order, FS::Dispatched, delivery: DS::OutForDelivery, driver: driverFor($this->w->a));
    ($this->push)($order);                                                               // a later change: the phone already has the activity
    expect(apnsSent())->toHaveCount(1);
});

it('does not start a second activity for an order the app has already started', function () {
    config(['platform.live_order.activity_remote_start' => true]);
    $order = ($this->preparing)();
    ($this->token)($order);
    activityToken($this->w->customer->id, null, hexToken('start'));
    ($this->push)($order);

    expect(array_map(fn ($s) => $s['aps']['event'], apnsSent()))->toBe(['update'])->and(array_map(fn ($s) => basename($s['url']), apnsSent()))->toBe([hexToken('a')]);
});

it('tries to start again when the start never reached the phone, and never starts a finished order', function () {
    config(['platform.live_order.activity_remote_start' => true]);
    $order = ($this->preparing)();
    activityToken($this->w->customer->id, null, hexToken('start'));
    $this->apns['mode'] = 'down';
    ($this->push)($order);
    expect(Cache::has('live_activity:start:'.$order->id.':'.LiveActivityToken::first()->id))->toBeFalse();

    $this->apns['mode'] = 'ok';
    ($this->push)($order);
    expect(array_map(fn ($s) => $s['aps']['event'], array_slice(apnsSent(), 3)))->toBe(['start']);

    $done = lopPlace($this->w);
    $done->forceFill(['status' => OS::Cancelled->value, 'cancelled_at' => now()])->save();
    $before = count(apnsSent());
    ($this->push)($done);
    expect(apnsSent())->toHaveCount($before);
});

it('retires a push-to-start token Apple no longer knows', function () {
    config(['platform.live_order.activity_remote_start' => true]);
    $order = ($this->preparing)();
    $start = activityToken($this->w->customer->id, null, hexToken('start'));
    $this->apns['mode'] = 'gone';
    ($this->push)($order);

    expect($start->fresh()->disabled_at)->not->toBeNull();
});

it('is wired into the order events: a stage change reaches the activity', function () {
    $order = lopPlace($this->w);
    ($this->token)($order);
    app(PushNotifier::class)->orderUpdated(new OrderUpdated($this->w->customer->id, $order->id, $order->number, 'processing'));

    expect(apnsSent())->toHaveCount(1);

    $f = $order->fulfillments->first();
    lopMove($order, FS::Preparing);
    app(PushNotifier::class)->dispatchUpdated(new DispatchUpdated($f->branch_id, $order->number, 'order', 'accepted', null, $f->id));
    expect(apnsSent())->toHaveCount(2);                                                  // the branch part moved: no Firebase needed for the card to follow
});

it('keeps the driver and staff pushes as they were: Firebase only', function () {
    $fcm = lopFcm();
    $order = lopPlace($this->w);
    $f = $order->fulfillments->first();
    app(PushNotifier::class)->dispatchUpdated(new DispatchUpdated($f->branch_id, $order->number, 'order', 'ready', null, $f->id));

    expect(apnsSent())->toBe([])->and($fcm['mode'])->toBe('ok');
});

it('does nothing for an order that does not exist or is not a uuid', function () {
    app(PushNotifier::class)->refreshOrder('not-a-uuid');
    app(PushNotifier::class)->refreshOrder((string) Str::uuid());

    expect(apnsSent())->toBe([]);
    Http::assertNothingSent();
});
