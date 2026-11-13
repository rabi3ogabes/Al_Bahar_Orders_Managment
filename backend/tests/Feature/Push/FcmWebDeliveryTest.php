<?php

use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Settings\Services\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/PushSupport.php';

const FCM_SEND = 'https://fcm.googleapis.com/v1/projects/al-bahar-home/messages:send';

function fakeGoogle(array $send = ['name' => 'projects/al-bahar-home/messages/1'], int $status = 200): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response($send, $status),
    ]);
}

function sentMessage(): array
{
    $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))->last();

    return $sent[0]->data()['message'];
}

beforeEach(function () {
    configureFirebase();
    config(['app.url' => 'https://albahar.example']);
});

it('signs the OAuth request with the dashboard key and sends to the dashboard project', function () {
    fakeGoogle();
    $res = app(FcmClient::class)->send('tok-android', 'Hello', 'World');

    expect($res['ok'])->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && str_contains($r['assertion'], '.')
        && json_decode(base64_decode(strtr(explode('.', $r['assertion'])[1], '-_', '+/')), true)['iss'] === 'firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com');
    Http::assertSent(fn (Request $r) => $r->url() === FCM_SEND && $r->hasHeader('Authorization', 'Bearer ya29.test'));
});

it('sends a phone token the Android and iPhone blocks, as before', function () {
    fakeGoogle();
    app(FcmClient::class)->send('tok-android', 'Hello', 'World', ['event' => 'x']);
    $m = sentMessage();

    expect($m['token'])->toBe('tok-android')->and($m['notification'])->toBe(['title' => 'Hello', 'body' => 'World'])
        ->and($m['android']['priority'])->toBe('high')->and($m['apns']['payload']['aps']['sound'])->toBe('default')->and($m)->not->toHaveKey('webpush');
});

it('sends a browser token a web push message with a notification, icons, ttl and a same-site link', function () {
    fakeGoogle();
    $device = new UserDevice(['platform' => 'web', 'app' => 'store', 'push_token' => 'web-tok']);
    app(FcmClient::class)->send('web-tok', 'Order ready', 'AB100 is ready', ['event' => 'order.ready', 'deep_link' => 'albahar://orders/abc'], $device);
    $m = sentMessage();

    expect($m['token'])->toBe('web-tok')->and($m)->not->toHaveKeys(['android', 'apns', 'notification'])
        ->and($m['webpush']['headers'])->toBe(['TTL' => '86400', 'Urgency' => 'normal'])
        ->and($m['webpush']['notification'])->toMatchArray(['title' => 'Order ready', 'body' => 'AB100 is ready', 'icon' => 'https://albahar.example/mobile-web/icons/Icon-192.png'])
        ->and($m['webpush']['fcm_options'])->toBe(['link' => 'https://albahar.example/orders/abc'])
        ->and($m['data'])->toBe(['event' => 'order.ready', 'deep_link' => '/orders/abc']);
});

it('routes the link of the web version of the mobile app after its hash', function () {
    fakeGoogle();
    $device = new UserDevice(['platform' => 'web', 'app' => 'mobile-web']);
    app(FcmClient::class)->send('web-tok', 'T', 'B', ['deep_link' => '/orders/abc'], $device);

    expect(sentMessage()['webpush']['fcm_options']['link'])->toBe('https://albahar.example/mobile-web/#/orders/abc')
        ->and(sentMessage()['data']['deep_link'])->toBe('/mobile-web/#/orders/abc');
});

it('never puts an off-site or script link into a push', function (string $link) {
    fakeGoogle();
    app(FcmClient::class)->send('web-tok', 'T', 'B', ['deep_link' => $link], new UserDevice(['platform' => 'web']));

    expect(sentMessage()['data']['deep_link'])->toBe('/')->and(sentMessage()['webpush']['fcm_options']['link'])->toBe('https://albahar.example/');
})->with(['https://evil.example/phish', '//evil.example/x', 'javascript:alert(1)', 'data:text/html,x', '', 'orders/1']);

it('keeps an own-site https link and drops the link option on a plain http site', function () {
    fakeGoogle();
    app(FcmClient::class)->send('web-tok', 'T', 'B', ['deep_link' => 'https://albahar.example/products/7?x=1'], new UserDevice(['platform' => 'web']));
    expect(sentMessage()['data']['deep_link'])->toBe('/products/7?x=1');

    config(['app.url' => 'http://localhost:8000']);
    app(FcmClient::class)->send('web-tok', 'T', 'B', ['deep_link' => '/orders/1'], new UserDevice(['platform' => 'web']));
    expect(sentMessage()['webpush'])->not->toHaveKey('fcm_options');
});

it('sends the live order card to a browser as a data-only message that replaces the previous one', function () {
    fakeGoogle();
    $payload = ['kind' => 'order_progress', 'order_id' => '0190c2a0-1b2c-7d3e-8f40-123456789abc', 'number' => 'AB100', 'step' => 2, 'total' => 4, 'phase' => 'on_the_way', 'deep_link' => '/orders/0190c2a0', 'quiet' => null];
    app(FcmClient::class)->sendProgress('web-tok', 'Order AB100', 'On the way', $payload, new UserDevice(['platform' => 'web', 'app' => 'store']));
    $m = sentMessage();

    expect($m)->not->toHaveKeys(['notification', 'android', 'apns'])->and($m['webpush'])->not->toHaveKey('notification')
        ->and($m['webpush']['headers'])->toMatchArray(['Urgency' => 'high', 'TTL' => '14400', 'Topic' => '0190c2a01b2c7d3e8f40123456789abc'])
        ->and(strlen($m['webpush']['headers']['Topic']))->toBeLessThanOrEqual(32)
        ->and($m['data'])->toMatchArray(['kind' => 'order_progress', 'order_id' => '0190c2a0-1b2c-7d3e-8f40-123456789abc', 'title' => 'Order AB100', 'body' => 'On the way',
            'step' => '2', 'total' => '4', 'phase' => 'on_the_way', 'deep_link' => '/orders/0190c2a0'])
        ->and($m['data'])->not->toHaveKey('quiet');
    expect(collect($m['data'])->every(fn ($v) => is_string($v)))->toBeTrue();
});

it('lowers the urgency of a quiet estimate-only update', function () {
    fakeGoogle();
    app(FcmClient::class)->sendProgress('web-tok', 'T', 'B', ['order_id' => 'o1', 'quiet' => '1'], new UserDevice(['platform' => 'web']));

    expect(sentMessage()['webpush']['headers']['Urgency'])->toBe('normal');
});

it('still sends the live order card to phones the old way when no device is given', function () {
    fakeGoogle();
    app(FcmClient::class)->sendProgress('tok', 'T', 'B', ['order_id' => 'o1']);
    $m = sentMessage();

    expect($m)->toHaveKeys(['android', 'apns'])->and($m)->not->toHaveKey('webpush');
});

it('reports a dead token as unregistered but not a wrong project or a bad payload', function (int $status, array $body, bool $dead) {
    fakeGoogle($body, $status);
    $res = app(FcmClient::class)->send('web-tok', 'T', 'B', [], new UserDevice(['platform' => 'web']));

    expect($res['ok'])->toBeFalse()->and($res['unregistered'])->toBe($dead)->and($res['status'])->toBe($status);
})->with([
    'unregistered' => [404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], true],
    'invalid token' => [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token', 'details' => [['fieldViolations' => [['field' => 'message.token']]]]]], true],
    'bad payload' => [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Invalid value at message.data', 'details' => [['fieldViolations' => [['field' => 'message.data']]]]]], false],
    'wrong project' => [404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']], false],
    'permission' => [403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'nope']], false],
]);

it('switches off a browser FCM no longer knows and keeps the one it accepts (queued notification)', function () {
    $user = App\Domain\Identity\Models\User::factory()->create();
    $dead = UserDevice::create(['user_id' => $user->id, 'platform' => 'web', 'app' => 'store', 'push_token' => 'dead-web']);
    $live = UserDevice::create(['user_id' => $user->id, 'platform' => 'web', 'app' => 'store', 'push_token' => 'live-web']);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
        'fcm.googleapis.com/*' => fn (Request $r) => $r['message']['token'] === 'dead-web'
            ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
            : Http::response(['name' => 'ok']),
    ]);
    $log = NotificationLog::create(['user_id' => $user->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'queued']);

    App\Domain\Notifications\Jobs\SendNotificationJob::dispatchSync($log->id, 'T', 'B');

    expect($dead->fresh()->disabled_at)->not->toBeNull()->and($live->fresh()->disabled_at)->toBeNull()->and($log->fresh()->status)->toBe('sent');
});

it('sends a scheduled notification to a browser through the queued job', function () {
    fakeGoogle();
    $user = App\Domain\Identity\Models\User::factory()->create();
    UserDevice::create(['user_id' => $user->id, 'platform' => 'web', 'app' => 'store', 'push_token' => 'job-web']);
    $log = NotificationLog::create(['user_id' => $user->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'queued']);

    App\Domain\Notifications\Jobs\SendNotificationJob::dispatchSync($log->id, 'Confirmed', 'Your order is confirmed', 'albahar://orders/1');

    expect($log->fresh()->status)->toBe('sent');
    expect(sentMessage())->toHaveKey('webpush')->and(sentMessage()['webpush']['notification']['title'])->toBe('Confirmed');
});

it('does not send anything while push is switched off', function () {
    app(Settings::class)->set('push.enabled', false);
    Http::fake();

    expect(app(FcmClient::class)->configured())->toBeFalse();
    $user = App\Domain\Identity\Models\User::factory()->create();
    UserDevice::create(['user_id' => $user->id, 'platform' => 'web', 'push_token' => 'off-web']);
    $log = NotificationLog::create(['user_id' => $user->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'queued']);
    App\Domain\Notifications\Jobs\SendNotificationJob::dispatchSync($log->id, 'T', 'B');

    expect($log->fresh()->status)->toBe('skipped');
    Http::assertNothingSent();
});

it('keeps one OAuth token per key and fetches a new one when the key changes', function () {
    fakeGoogle();
    $fcm = app(FcmClient::class);
    $fcm->send('a', 'T', 'B');
    $fcm->send('b', 'T', 'B');
    expect(Http::recorded(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token'))->toHaveCount(1);

    app(Settings::class)->set('push.firebase.service_account', fakeServiceAccountJson('al-bahar-home', 'new-key@al-bahar-home.iam.gserviceaccount.com'), secret: true);
    $fcm->send('c', 'T', 'B');
    expect(Http::recorded(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token'))->toHaveCount(2);
});

it('tests the connection with validate_only and maps every failure to a plain code', function (callable $fake, string $code, bool $ok) {
    $fake();
    $res = app(FcmClient::class)->check();

    expect($res['ok'])->toBe($ok)->and($res['code'])->toBe($code);
})->with([
    'ok' => [fn () => fakeGoogle(['name' => 'projects/al-bahar-home/messages/fake_message_id']), 'ok', true],
    'key rejected' => [fn () => Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)]), 'key_rejected', false],
    'oauth other' => [fn () => Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_client'], 401)]), 'oauth_failed', false],
    'api disabled' => [fn () => fakeGoogle(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Firebase Cloud Messaging API has not been used in project 1 before or it is disabled.']], 403), 'api_disabled', false],
    'permission denied' => [fn () => fakeGoogle(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']], 403), 'permission_denied', false],
    'project not found' => [fn () => fakeGoogle(['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']], 404), 'project_not_found', false],
    'rate limited' => [fn () => fakeGoogle(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429), 'rate_limited', false],
    'fcm down' => [fn () => fakeGoogle(['error' => ['status' => 'UNAVAILABLE']], 503), 'fcm_down', false],
    'rejected' => [fn () => fakeGoogle(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'bad']], 400), 'rejected', false],
    'offline' => [fn () => Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')), 'unreachable', false],
]);

it('validates without delivering: the test message is validate_only and goes to a topic, never a device', function () {
    fakeGoogle();
    app(FcmClient::class)->check();
    $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))->last()[0];

    expect($sent->url())->toBe(FCM_SEND)->and($sent['validate_only'])->toBeTrue()->and($sent['message'])->toHaveKey('topic')->not->toHaveKey('token');
});

it('says not configured without calling anyone when there is no key', function () {
    app(Settings::class)->set('push.firebase.service_account', null, secret: true);
    Http::fake();

    expect(app(FcmClient::class)->check()['code'])->toBe('not_configured');
    Http::assertNothingSent();
});
