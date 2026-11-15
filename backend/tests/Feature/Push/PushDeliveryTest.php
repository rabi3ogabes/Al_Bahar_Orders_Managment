<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Data\SendRequest;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Notifications\Services\PushNotifier;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Settings\Services\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/PushSupport.php';

/*
 * What reaches a phone, and what happens when Google is not having a good day: the Android channel, the size and shape of the
 * message, an expired OAuth token, a busy or unreachable FCM, a token made for another project, one phone per install, the language
 * of each phone, no second alert next to the live order card, and stale tokens leaving. Google's endpoints are faked.
 */

/** Google's endpoints: the OAuth token and, per call, whatever the closure answers (default: accepted). */
function pushFake(?Closure $answer = null): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => $answer ?? fn () => Http::response(['name' => 'projects/al-bahar-home/messages/1']),
    ]);
}

/** @return list<array<string, mixed>> the messages sent to FCM so far, oldest first */
function pushMessages(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send'))->map(fn ($pair) => $pair[0]->data()['message'])->values()->all();
}

function pushPhone(User $user, string $token, string $platform = 'android', ?string $locale = 'en', array $extra = []): UserDevice
{
    return UserDevice::create(['user_id' => $user->id, 'platform' => $platform, 'push_token' => $token, 'locale' => $locale] + $extra);
}

beforeEach(function () {
    configureFirebase();
    config(['app.url' => 'https://albahar.example']);
    Cache::flush();
});

// ── The message ──────────────────────────────────────────────────────────────────────────────────────────────────────

it('shows each kind of push in its own Android channel, so offers can be muted without muting order updates', function (string $event, string $channel, string $category) {
    pushFake();
    app(FcmClient::class)->send('tok', 'T', 'B', ['event' => $event]);
    $m = pushMessages()[0];

    expect($m['android']['notification']['channel_id'])->toBe($channel)->and($m['data']['category'])->toBe($category);
})->with([
    'order' => ['order.out_for_delivery', 'albahar_default', 'transactional'],
    'payment' => ['payment.failed', 'albahar_default', 'transactional'],
    'auction' => ['auction.outbid', 'albahar_auctions', 'auction'],
    'back in stock' => ['stock.back_in_stock', 'albahar_offers', 'marketing'],
    'campaign' => ['campaign', 'albahar_offers', 'marketing'],
    'staff message' => ['staff.message', 'albahar_offers', 'marketing'],
    'journey' => ['journey.abandoned_cart', 'albahar_offers', 'marketing'],
    'staff app push' => ['dispatch.updated', 'albahar_default', 'transactional'],
]);

it('lets an undelivered push expire after a day on Android and iPhone', function () {
    pushFake();
    $this->freezeTime();
    app(FcmClient::class)->send('tok', 'T', 'B', ['event' => 'order.confirmed']);
    $m = pushMessages()[0];

    expect($m['android']['ttl'])->toBe('86400s')->and($m['apns']['headers']['apns-expiration'])->toBe((string) (now()->getTimestamp() + 86400));
});

it('sends every data value as a string, whatever it was', function () {
    pushFake();
    $res = app(FcmClient::class)->send('tok', 'T', 'B', ['event' => 'x', 'n' => 5, 'flag' => true, 'none' => null, 'list' => ['a' => 1, 'b' => 'é']]);

    expect($res['ok'])->toBeTrue();
    $data = pushMessages()[0]['data'];
    expect($data)->toMatchArray(['n' => '5', 'flag' => '1', 'list' => '{"a":1,"b":"é"}'])->and($data)->not->toHaveKey('none')
        ->and(collect($data)->every(fn ($v) => is_string($v)))->toBeTrue();
});

it('keeps a very long Arabic title and body with emoji inside the message size FCM accepts', function () {
    pushFake();
    $title = str_repeat('طلبك في الطريق إليك 🚚 ', 40);
    $body = str_repeat('تفاصيل الطلب والتوصيل ✨ ', 400);
    app(FcmClient::class)->send('tok', $title, $body, ['event' => 'order.confirmed', 'deep_link' => 'albahar://orders/1'], new UserDevice(['platform' => 'web', 'app' => 'store']));
    app(FcmClient::class)->send('tok', $title, $body, ['event' => 'order.confirmed', 'deep_link' => 'albahar://orders/1']);

    foreach (pushMessages() as $m) {
        $shown = $m['notification'] ?? $m['webpush']['notification'];
        expect(mb_strlen($shown['title']))->toBeLessThanOrEqual(FcmClient::TITLE_MAX)->and(mb_strlen($shown['body']))->toBeLessThanOrEqual(FcmClient::BODY_MAX)
            ->and(mb_check_encoding($shown['title'].$shown['body'], 'UTF-8'))->toBeTrue()->and($shown['body'])->toEndWith('…')
            ->and(strlen(json_encode($m, JSON_UNESCAPED_UNICODE)))->toBeLessThan(4096);
    }
});

it('leaves a short title and body exactly as they are', function () {
    pushFake();
    app(FcmClient::class)->send('tok', 'طلبك في الطريق', 'Order #AB100 is on the way', ['event' => 'order.confirmed']);

    expect(pushMessages()[0]['notification'])->toBe(['title' => 'طلبك في الطريق', 'body' => 'Order #AB100 is on the way']);
});

// ── Google is down, busy, or says no ─────────────────────────────────────────────────────────────────────────────────

it('replaces an expired OAuth token once and sends the message again', function () {
    $calls = 0;
    Http::fake([
        'oauth2.googleapis.com/*' => function () use (&$calls) {
            return Http::response(['access_token' => 'ya29.token'.(++$calls)]);
        },
        'fcm.googleapis.com/*' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ya29.token1')
            ? Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401)
            : Http::response(['name' => 'ok']),
    ]);

    $res = app(FcmClient::class)->send('tok', 'T', 'B');

    expect($res)->toMatchArray(['ok' => true, 'status' => 200])->and($calls)->toBe(2);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send')))->toHaveCount(2);
});

it('gives up after one fresh token: a message that is refused again is a failure, not a loop', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401),
    ]);

    $res = app(FcmClient::class)->send('tok', 'T', 'B');

    expect($res)->toMatchArray(['ok' => false, 'retry' => false, 'unregistered' => false, 'status' => 401]);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send')))->toHaveCount(2);
});

it('answers instead of throwing when Firebase cannot be reached, and says trying again can work', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']), 'fcm.googleapis.com/*' => fn () => throw new ConnectionException('timed out')]);

    $res = app(FcmClient::class)->send('tok', 'T', 'B');

    expect($res)->toMatchArray(['ok' => false, 'retry' => true, 'unregistered' => false, 'status' => 0, 'error' => 'UNAVAILABLE']);
});

it('answers instead of throwing when Google’s token service is unreachable, rejects the key or returns no token', function (callable $fake, bool $retry) {
    $fake();

    $res = app(FcmClient::class)->send('tok', 'T', 'B');

    expect($res['ok'])->toBeFalse()->and($res['retry'])->toBe($retry)->and($res['unregistered'])->toBeFalse();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'messages:send'));
})->with([
    'unreachable' => [fn () => Http::fake(['oauth2.googleapis.com/*' => fn () => throw new ConnectionException('no route')]), true],
    'token service down' => [fn () => Http::fake(['oauth2.googleapis.com/*' => Http::response('', 503)]), true],
    'key rejected' => [fn () => Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]), false],
    'no token in the answer' => [fn () => Http::fake(['oauth2.googleapis.com/*' => Http::response(['expires_in' => 3599])]), false],
]);

it('does not remember a missing token: the next message asks Google again', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::sequence()->push(['expires_in' => 3599])->push(['access_token' => 'ya29.second']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

    $first = app(FcmClient::class)->send('tok', 'T', 'B');
    $second = app(FcmClient::class)->send('tok', 'T', 'B');

    expect($first['ok'])->toBeFalse()->and($second['ok'])->toBeTrue();
});

it('marks a busy or broken Firebase as worth retrying, and a bad message or a refused key as not', function (int $status, array $body, bool $retry) {
    pushFake(fn () => Http::response($body, $status));

    $res = app(FcmClient::class)->send('tok', 'T', 'B');

    expect($res['ok'])->toBeFalse()->and($res['retry'])->toBe($retry)->and($res['unregistered'])->toBeFalse()->and($res['status'])->toBe($status);
})->with([
    'rate limited' => [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED', 'details' => [['errorCode' => 'QUOTA_EXCEEDED']]]], true],
    'unavailable' => [503, ['error' => ['status' => 'UNAVAILABLE']], true],
    'internal' => [500, ['error' => ['status' => 'INTERNAL']], true],
    'bad payload' => [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid value at message.data', 'details' => [['fieldViolations' => [['field' => 'message.data']]]]]], false],
    'permission' => [403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'nope']], false],
    'apns key refused' => [401, ['error' => ['status' => 'UNAUTHENTICATED', 'details' => [['errorCode' => 'THIRD_PARTY_AUTH_ERROR']]]], false],
]);

it('switches a device off when its token was made for another Firebase project', function () {
    pushFake(fn () => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'SenderId mismatch', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'SENDER_ID_MISMATCH']]]], 403));

    $res = app(FcmClient::class)->send('tok', 'T', 'B');

    expect($res)->toMatchArray(['ok' => false, 'unregistered' => true, 'retry' => false]);
});

// ── The queued push ──────────────────────────────────────────────────────────────────────────────────────────────────

it('sends each phone the language of its own app, not only the language of the account', function () {
    pushFake();
    $user = User::factory()->create(['locale' => 'ar']);
    pushPhone($user, 'phone-ar', 'android', 'ar');
    pushPhone($user, 'phone-en', 'ios', 'en');
    pushPhone($user, 'phone-new', 'android', 'ar_QA');
    pushPhone($user, 'phone-unknown', 'android', null);
    $log = NotificationLog::create(['user_id' => $user->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'queued']);

    SendNotificationJob::dispatchSync($log->id, 'تم تأكيد طلبك', 'طلبك AB1 مؤكد', 'albahar://orders/1', null, [
        'ar' => ['subject' => 'تم تأكيد طلبك', 'body' => 'طلبك AB1 مؤكد'], 'en' => ['subject' => 'Order confirmed', 'body' => 'Your order AB1 is confirmed'],
    ]);

    $titles = collect(pushMessages())->mapWithKeys(fn ($m) => [$m['token'] => $m['notification']['title']]);
    expect($titles->all())->toBe(['phone-ar' => 'تم تأكيد طلبك', 'phone-en' => 'Order confirmed', 'phone-new' => 'تم تأكيد طلبك', 'phone-unknown' => 'تم تأكيد طلبك'])
        ->and($log->fresh()->status)->toBe('sent');
});

it('queues a push with the message in every supported language, taken from the same template', function () {
    Bus::fake();
    $user = User::factory()->create(['locale' => 'ar']);

    app(NotificationService::class)->notify($user, 'payment.failed', ['order_number' => 'AB9'], ['push']);

    Bus::assertDispatched(SendNotificationJob::class, function (SendNotificationJob $job) {
        return array_keys($job->texts) === ['en', 'ar'] && str_contains($job->texts['en']['body'], 'AB9') && str_contains($job->texts['ar']['body'], 'AB9')
            && $job->texts['en']['subject'] !== $job->texts['ar']['subject'] && $job->subject === $job->texts['ar']['subject'];
    });
});

it('sends a campaign to each phone in its own language', function () {
    pushFake();
    $user = User::factory()->create(['locale' => 'ar', 'marketing_consent_at' => now()]);
    pushPhone($user, 'phone-ar', 'android', 'ar');
    pushPhone($user, 'phone-en', 'ios', 'en');
    $campaign = MarketingCampaign::create(['name' => 'Eid', 'title_en' => 'Eid sale', 'title_ar' => 'تخفيضات العيد', 'body_en' => 'Up to 30% off', 'body_ar' => 'خصم حتى ٣٠٪',
        'deep_link' => 'albahar://categories', 'channels' => ['push', 'in_app'], 'status' => 'draft', 'segment' => []]);

    expect(app(CampaignService::class)->send($campaign))->toBe(1);

    $sent = collect(pushMessages())->mapWithKeys(fn ($m) => [$m['token'] => $m['notification']]);
    expect($sent['phone-ar'])->toBe(['title' => 'تخفيضات العيد', 'body' => 'خصم حتى ٣٠٪'])->and($sent['phone-en'])->toBe(['title' => 'Eid sale', 'body' => 'Up to 30% off']);
    $message = pushMessages()[0];
    expect($message['data']['event'])->toBe('campaign')->and($message['data']['category'])->toBe('marketing')->and($message['android']['notification']['channel_id'])->toBe('albahar_offers');
});

it('tries the push again later when nothing could be delivered because Firebase was busy', function () {
    pushFake(fn () => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503));
    $user = User::factory()->create();
    pushPhone($user, 'a');
    pushPhone($user, 'b');
    $log = NotificationLog::create(['user_id' => $user->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'queued']);

    expect(fn () => SendNotificationJob::dispatchSync($log->id, 'T', 'B'))->toThrow(RuntimeException::class, 'FCM unavailable: HTTP 503');

    expect($log->fresh()->status)->toBe('queued')->and($log->fresh()->error)->toContain('FCM unavailable')->and(UserDevice::whereNull('disabled_at')->count())->toBe(2);
});

it('does not ring the phones that already have the message when only some of them could be reached', function () {
    pushFake(fn (Request $r) => $r['message']['token'] === 'down' ? Http::response(['error' => ['status' => 'UNAVAILABLE']], 503) : Http::response(['name' => 'ok']));
    $user = User::factory()->create();
    pushPhone($user, 'fine');
    pushPhone($user, 'down');
    $log = NotificationLog::create(['user_id' => $user->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'queued']);

    SendNotificationJob::dispatchSync($log->id, 'T', 'B'); // does not throw: a retry would send "fine" a second time

    expect($log->fresh()->status)->toBe('sent')->and($log->fresh()->response)->toMatchArray(['delivered_to' => 1]);
});

it('fails the push for good when every phone is gone, without retrying', function () {
    pushFake(fn () => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404));
    $user = User::factory()->create();
    pushPhone($user, 'old-1');
    pushPhone($user, 'old-2');
    $log = NotificationLog::create(['user_id' => $user->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'queued']);

    SendNotificationJob::dispatchSync($log->id, 'T', 'B');

    expect($log->fresh()->status)->toBe('failed')->and(UserDevice::whereNull('disabled_at')->count())->toBe(0)->and($log->fresh()->response)->toMatchArray(['delivered_to' => 0, 'removed' => 2]);
});

it('keeps the in-app message and the push of the same event linked, so opening the push marks the inbox entry read', function () {
    pushFake();
    $user = User::factory()->create();
    pushPhone($user, 'phone');

    app(NotificationService::class)->notify($user, 'payment.failed', ['order_number' => 'AB5', 'deep_link' => 'albahar://orders/5']);

    $inApp = Notification::where('user_id', $user->id)->firstOrFail();
    expect(pushMessages()[0]['data'])->toMatchArray(['event' => 'payment.failed', 'notification_id' => $inApp->id, 'deep_link' => 'albahar://orders/5']);
});

// ── One alert for an order change ────────────────────────────────────────────────────────────────────────────────────

it('leaves the classic push out of an order change the live order card already announces', function (string $event) {
    Bus::fake();
    $user = User::factory()->create();
    pushPhone($user, 'phone');

    $sent = app(NotificationService::class)->notify($user, $event, ['order_number' => 'AB1']);

    expect($sent)->not->toContain('push')->and($sent)->toContain('in_app');
    expect(NotificationLog::where('channel', 'push')->count())->toBe(0);
})->with(['order.created', 'order.confirmed', 'order.ready_for_pickup', 'order.out_for_delivery', 'order.completed', 'order.cancelled']);

it('still sends the classic push when the card cannot reach the customer', function (string $why) {
    Bus::fake();
    $user = User::factory()->create();
    match ($why) {
        'no phone' => null,
        'phone switched off' => pushPhone($user, 'phone', 'android', 'en', ['disabled_at' => now()]),
        'push not set up' => (function () use ($user) {
            pushPhone($user, 'phone');
            app(Settings::class)->set('push.enabled', false);
        })(),
    };

    $sent = app(NotificationService::class)->notify($user, 'order.confirmed', ['order_number' => 'AB1'], ['push', 'in_app']);

    expect($sent)->toBe(['push', 'in_app']);
})->with(['no phone', 'phone switched off', 'push not set up']);

it('sends the classic push for events the card does not cover', function (string $event) {
    Bus::fake();
    $user = User::factory()->create();
    pushPhone($user, 'phone');

    expect(app(NotificationService::class)->notify($user, $event, ['order_number' => 'AB1']))->toContain('push');
})->with(['payment.succeeded', 'payment.failed', 'refund.processed', 'support.reply']);

// ── Staff messages ───────────────────────────────────────────────────────────────────────────────────────────────────

it('names the inbox entry in a staff message push, so opening the push marks it read', function () {
    pushFake();
    $customer = User::factory()->create(['marketing_consent_at' => now(), 'locale' => 'en', 'name' => 'Noor Ali', 'phone' => '+97455123456']);
    pushPhone($customer, 'phone');
    $content = ['title_en' => 'Hello {{name}}', 'title_ar' => 'مرحباً {{name}}', 'body_en' => 'A note for {{name}}', 'body_ar' => 'رسالة إلى {{name}}',
        'whatsapp_en' => '', 'whatsapp_ar' => '', 'deep_link' => 'albahar://home', 'coupon' => ['enabled' => false]];

    app(CustomerMessaging::class)->send(staffWithRole('marketing_manager'), new SendRequest(AudienceSelection::ofIds([$customer->id]), ['notification'], 'custom', $content, null, null, false));

    $inbox = Notification::where('user_id', $customer->id)->where('event', 'staff.message')->firstOrFail();
    $message = pushMessages()[0];
    expect($message['data'])->toMatchArray(['event' => 'staff.message', 'category' => 'marketing', 'notification_id' => $inbox->id, 'deep_link' => 'albahar://home'])
        ->and($message['data'])->toHaveKey('delivery_id')->and($message['notification']['title'])->toBe('Hello Noor')
        ->and($message['android']['notification']['channel_id'])->toBe('albahar_offers');
});

// ── The live order card follows the customer's order-update preference ───────────────────────────────────────────────

it('does not send the live order card to a customer who switched order push notifications off', function () {
    require_once __DIR__.'/../Commerce/LiveOrderHelpers.php';
    lopFreeze();
    $w = commerceWorld();
    $order = lopMove(lopPlace($w), FulfillmentStatus::Preparing);
    lopFcm();
    pushPhone($w->customer, 'phone');
    NotificationPreference::create(['user_id' => $w->customer->id, 'channel' => 'push', 'category' => 'transactional', 'is_enabled' => false]);

    app(PushNotifier::class)->refreshOrder($order->id);

    expect(lopSent(all: true))->toBe([]);

    NotificationPreference::query()->delete();
    app(PushNotifier::class)->refreshOrder($order->id);
    expect(lopSent())->toHaveCount(1);
});

// ── One phone, one token ─────────────────────────────────────────────────────────────────────────────────────────────

it('keeps one active token per install: a new token under the same install id switches the old one off', function () {
    $user = actingAsCustomer();
    $register = fn (string $token, string $deviceId, string $platform = 'android') => $this->postJson('/api/v1/me/devices', ['platform' => $platform, 'push_token' => $token, 'device_id' => $deviceId, 'locale' => 'ar'])->assertCreated();

    $register('token-1', 'install-A');
    $register('other-phone', 'install-B');
    $register('token-2', 'install-A');

    expect(UserDevice::where('push_token', 'token-1')->value('disabled_at'))->not->toBeNull()
        ->and(UserDevice::where('push_token', 'token-2')->value('disabled_at'))->toBeNull()
        ->and(UserDevice::where('push_token', 'other-phone')->value('disabled_at'))->toBeNull()
        ->and(UserDevice::whereNull('disabled_at')->where('user_id', $user->id)->count())->toBe(2);

    $register('token-1', 'install-A'); // the old token comes back (a restored backup): the newest registration wins again
    expect(UserDevice::whereNull('disabled_at')->where('device_id', 'install-A')->pluck('push_token')->all())->toBe(['token-1']);
});

it('also retires the old token when the same install is signed in by someone else', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    pushPhone($a, 'token-1', 'ios', 'en', ['device_id' => 'install-A']);
    actingAsCustomer($b);

    $this->postJson('/api/v1/me/devices', ['platform' => 'ios', 'push_token' => 'token-2', 'device_id' => 'install-A'])->assertCreated();

    expect(UserDevice::where('push_token', 'token-1')->value('disabled_at'))->not->toBeNull()->and(UserDevice::where('push_token', 'token-2')->value('user_id'))->toBe($b->id);
});

it('leaves a browser’s other tokens alone: the store and the dashboard share one device id', function () {
    actingAsCustomer();
    $post = fn (string $token, string $app) => $this->postJson('/api/v1/me/devices', ['platform' => 'web', 'app' => $app, 'push_token' => $token, 'device_id' => 'browser-1'])->assertCreated();

    $post('store-token', 'store');
    $post('mobile-web-token', 'mobile-web');

    expect(UserDevice::whereNull('disabled_at')->count())->toBe(2);
});

// ── Stale tokens ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('switches off tokens nobody has refreshed for two months, and keeps the rows', function () {
    $user = User::factory()->create();
    $fresh = pushPhone($user, 'fresh', 'android', 'en', ['last_seen_at' => now()->subDays(59)]);
    $old = pushPhone($user, 'old', 'android', 'en', ['last_seen_at' => now()->subDays(61)]);
    $neverSeen = pushPhone($user, 'never-seen', 'ios', 'en');
    $neverSeen->forceFill(['created_at' => now()->subDays(90)])->save();
    $new = pushPhone($user, 'brand-new', 'ios', 'en');

    expect(Artisan::call('push:prune-devices'))->toBe(0);

    expect($old->fresh()->disabled_at)->not->toBeNull()->and($neverSeen->fresh()->disabled_at)->not->toBeNull()
        ->and($fresh->fresh()->disabled_at)->toBeNull()->and($new->fresh()->disabled_at)->toBeNull()->and(UserDevice::count())->toBe(4);
    Artisan::call('push:prune-devices', ['--days' => 20]);
    expect($fresh->fresh()->disabled_at)->not->toBeNull();
});

it('runs the stale token clean-up every day', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'push:prune-devices'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('45 3 * * *');
});

// ── A bulk send ──────────────────────────────────────────────────────────────────────────────────────────────────────

it('keeps going through a bulk send when Google is slow for some phones', function () {
    pushFake(fn (Request $r) => str_starts_with($r['message']['token'], 'slow') ? throw new ConnectionException('timeout') : Http::response(['name' => 'ok']));
    $users = User::factory()->count(30)->create(['marketing_consent_at' => now()]);
    $users->each(fn (User $u, int $i) => pushPhone($u, ($i % 5 === 0 ? 'slow-' : 'fast-').$i));
    $campaign = MarketingCampaign::create(['name' => 'Bulk', 'title_en' => 'Hi', 'title_ar' => 'مرحبا', 'body_en' => 'Hello', 'body_ar' => 'أهلا', 'deep_link' => null, 'channels' => ['push'], 'status' => 'draft', 'segment' => []]);

    // The test queue runs each push inline: a phone Google cannot reach fails its job (the real queue tries again later), the 24 others are done.
    $n = app(CampaignService::class)->send($campaign);

    expect($campaign->fresh()->status)->toBe('sent')->and($n)->toBe(24)->and(NotificationLog::where('campaign_id', $campaign->id)->where('status', 'sent')->count())->toBe(24)
        ->and(NotificationLog::where('campaign_id', $campaign->id)->where('status', 'queued')->count())->toBe(6);
});
