<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Contracts\SmsSender;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\PushDiagnostic;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Notifications\Services\PushHealth;
use App\Domain\Notifications\Services\PushTester;
use App\Domain\Notifications\Services\WhatsAppClient;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\PushNotificationsPage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/PushSupport.php';

/*
 * "Why does push not arrive?": the per-app checklist (and the warning when two apps share one App ID), what the phones report, the
 * real test push with Firebase's answer in plain words, `push:test`, and the import of google-services.json that makes the shared
 * App ID mistake hard to make.
 */

const KEY_ANDROID = 'AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R';

/** Saves the phone-app part of a setup. $ids: "platform_app" => app id (null = nothing saved). */
function savePhoneApps(array $ids, ?string $androidKey = KEY_ANDROID): void
{
    $s = app(Settings::class);
    if ($androidKey !== null) {
        $s->set('push.firebase.api_key_android', $androidKey);
    }
    foreach ($ids as $which => $id) {
        [$platform, $app] = explode('_', $which);
        $s->set("push.firebase.app_id_{$platform}_{$app}", $id);
    }
}

function androidId(string $seed): string
{
    return '1:123456789012:android:'.substr(md5($seed), 0, 16);
}

/** @return array<string, array<string, mixed>> the health entries by "platform:app" */
function healthByApp(): array
{
    $out = [];
    foreach (app(PushHealth::class)->phoneApps() as $h) {
        $out["{$h['platform']}:{$h['app']}"] = $h;
    }

    return $out;
}

function check(array $health, string $key): array
{
    return collect($health['checks'])->firstWhere('key', $key);
}

function fcmFake(int $status = 200, array $body = ['name' => 'projects/al-bahar-home/messages/1']): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response($body, $status),
    ]);
}

function nativeDevice(User $u, string $token, string $app = 'customer', string $platform = 'android', array $extra = []): UserDevice
{
    return UserDevice::create(['user_id' => $u->id, 'platform' => $platform, 'app' => $app, 'push_token' => $token, 'locale' => 'en', 'app_version' => '1.0.0+12', 'last_seen_at' => now(), ...$extra]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
    Cache::flush();
});

// ── The checklist per app ────────────────────────────────────────────────────────────────────────────────────────────

it('warns, for every app that holds it, when two phone apps share one App ID', function () {
    configureFirebase();
    savePhoneApps(['android_customer' => androidId('one'), 'android_driver' => androidId('one'), 'android_staff' => androidId('two')]);

    $h = healthByApp();

    expect(check($h['android:customer'], 'unique'))->toMatchArray(['level' => 'fail'])
        ->and(check($h['android:driver'], 'unique'))->toMatchArray(['level' => 'fail'])
        ->and(check($h['android:staff'], 'unique'))->toMatchArray(['level' => 'ok'])
        ->and($h['android:customer']['level'])->toBe('fail')->and($h['android:staff']['level'])->not->toBe('fail');
    expect(PushHealth::sentence(check($h['android:driver'], 'unique')))->toContain('Customer app (Android)');

    // The setup checklist at the top must not say "3 of 3 apps ready" while two of them share an App ID.
    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->assertSee(__('push_health.health.duplicate.title'))
        ->assertDontSee(__('push_admin.status.ok_android', ['ready' => 3, 'total' => 3]));
});

it('says nothing about duplicates when every app has its own App ID, and the apps are ready once a phone has registered', function () {
    configureFirebase();
    savePhoneApps(['android_customer' => androidId('1'), 'android_driver' => androidId('2'), 'android_staff' => androidId('3')]);
    PushDiagnostic::create(['app' => 'driver', 'platform' => 'android', 'stage' => 'registered', 'code' => 'ok', 'install_id' => 'inst-aaaaaaaa-1', 'report_day' => now()->toDateString(), 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $h = healthByApp();

    expect(check($h['android:customer'], 'unique')['level'])->toBe('ok')->and(check($h['android:driver'], 'phones')['level'])->toBe('ok')->and($h['android:driver']['level'])->toBe('ok')
        ->and($h['android:driver']['phones'])->toBe(1)->and(check($h['android:customer'], 'phones')['level'])->toBe('warn');
    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->assertDontSee(__('push_health.health.duplicate.title'));
});

it('finds the App ID, key and project number problems, and says what each one is', function () {
    configureFirebase();
    app(Settings::class)->set('push.firebase.api_key_android', 'AIzaShort');
    savePhoneApps(['android_customer' => 'not-an-app-id', 'android_driver' => '1:999999999999:android:0a1b2c3d4e5f6a7b'], null);

    $h = healthByApp();

    expect(check($h['android:customer'], 'app_id'))->toMatchArray(['level' => 'fail', 'params' => ['problem' => 'format']])
        ->and(check($h['android:staff'], 'app_id'))->toMatchArray(['level' => 'fail', 'params' => ['problem' => 'missing']])
        ->and(check($h['android:driver'], 'api_key'))->toMatchArray(['level' => 'fail', 'params' => ['problem' => 'format']])
        ->and(check($h['android:driver'], 'project_number')['level'])->toBe('fail'); // 999999999999 is not the sender id 123456789012
    expect(PushHealth::sentence(check($h['android:staff'], 'app_id')))->toBe(__('push_health.check.app_id.fail.missing'));
});

it('fails every app while push is off or the server key is missing', function () {
    configureFirebase(enabled: false);
    savePhoneApps(['android_customer' => androidId('1')]);
    expect(check(healthByApp()['android:customer'], 'server')['level'])->toBe('fail');

    configureFirebase(enabled: true);
    app(Settings::class)->set('push.firebase.service_account', null, secret: true);
    expect(check(healthByApp()['android:customer'], 'server')['level'])->toBe('fail');
});

it('shows the iPhone app as not set up (not as a failure) while nothing is saved for it', function () {
    configureFirebase();
    expect(healthByApp()['ios:customer']['level'])->toBe('off');

    app(Settings::class)->set('push.firebase.api_key_ios', 'AIzaSyC3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S');
    expect(healthByApp()['ios:customer']['level'])->toBe('fail'); // started but no App ID yet
});

it('counts registered devices per app and treats a phone that reported a problem as a warning when others registered', function () {
    configureFirebase();
    savePhoneApps(['android_customer' => androidId('1')]);
    $u = User::factory()->create();
    nativeDevice($u, 't-1');
    nativeDevice($u, 't-2', 'customer', 'android', ['disabled_at' => now()]);
    nativeDevice($u, 't-3', 'driver');
    $base = ['app' => 'customer', 'platform' => 'android', 'report_day' => now()->toDateString(), 'first_seen_at' => now(), 'last_seen_at' => now()];
    PushDiagnostic::create($base + ['stage' => 'registered', 'code' => 'ok', 'install_id' => 'inst-aaaaaaaa-1']);
    PushDiagnostic::create($base + ['stage' => 'token', 'code' => 'timeout', 'install_id' => 'inst-bbbbbbbb-2']);

    $h = healthByApp()['android:customer'];

    expect($h['devices'])->toBe(1)->and(healthByApp()['android:driver']['devices'])->toBe(1)->and(check($h, 'phones'))->toMatchArray(['level' => 'warn'])
        ->and($h['problems'][0]['code'])->toBe('timeout');
});

it('shows what the last Test connection said, or asks for one, in the health section', function () {
    configureFirebase();
    $keyAccepted = true;
    Http::fake([
        'oauth2.googleapis.com/*' => function () use (&$keyAccepted) {
            return $keyAccepted ? Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]) : Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT'], 400);
        },
        'fcm.googleapis.com/*' => Http::response(['name' => 'x']),
    ]);
    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->assertSee(__('push_health.health.never_tested'));

    $page->call('testConnection')->assertDontSee(__('push_health.health.never_tested'))->assertSee(__('push_admin.conn_ok'));

    Cache::flush();
    $keyAccepted = false;
    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->call('testConnection')->assertSee(__('push_admin.conn.key_rejected'));
});

// ── The page ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('explains each thing the phones report, in English and in Arabic', function (string $locale) {
    app()->setLocale($locale);
    configureFirebase();
    PushDiagnostic::create(['app' => 'staff', 'platform' => 'android', 'stage' => 'token', 'code' => 'api_key_blocked', 'message' => 'Requests from this Android client application are blocked.',
        'install_id' => 'inst-aaaaaaaa-1', 'app_version' => '1.0.0+12', 'report_day' => now()->toDateString(), 'first_seen_at' => now(), 'last_seen_at' => now()]);
    PushDiagnostic::create(['app' => 'driver', 'platform' => 'android', 'stage' => 'token', 'code' => 'a_code_from_a_newer_build', 'install_id' => 'inst-bbbbbbbb-2', 'report_day' => now()->toDateString(), 'first_seen_at' => now(), 'last_seen_at' => now()]);

    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)
        ->assertSee(__('push_health.reports.title'))->assertSee(__('push_health.codes.api_key_blocked.what'))->assertSee(__('push_health.codes.api_key_blocked.fix'))
        ->assertSee('Requests from this Android client application are blocked.')->assertSee('api_key_blocked')
        ->assertSee(__('push_health.reports.stage.token'))->assertSee(__('push_health.reports.app.staff'))
        ->assertSee(__('push_health.codes.unknown.fix')); // a code this page does not know yet still gets a useful answer
})->with(['en', 'ar']);

it('says so when no phone has reported anything yet, and shows how many registered when some did', function () {
    configureFirebase();
    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->assertSee(__('push_health.reports.none_at_all'));

    PushDiagnostic::create(['app' => 'customer', 'platform' => 'android', 'stage' => 'registered', 'code' => 'ok', 'install_id' => 'inst-aaaaaaaa-1', 'report_day' => now()->toDateString(), 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $page->call('$refresh')->assertSee(__('push_health.reports.empty'))->assertDontSee(__('push_health.reports.none_at_all'));
});

it('marks the App ID field that is shared with another app, while it is being typed', function () {
    configureFirebase();
    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)
        ->set('appIdAndroidCustomer', androidId('x'))->set('appIdAndroidDriver', androidId('x'))
        ->assertSee(__('push_health.health.duplicate_field', ['others' => __('push_health.reports.app.driver').' ('.__('push_health.reports.platform.android').')']))
        ->set('appIdAndroidDriver', androidId('y'))
        ->assertDontSee(__('push_health.health.duplicate.title'));
});

it('still saves when two App IDs match (the person may be halfway through) but tells them it will not work', function () {
    configureFirebase();
    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)
        ->set('apiKeyAndroid', KEY_ANDROID)->set('appIdAndroidCustomer', androidId('x'))->set('appIdAndroidDriver', androidId('x'))->call('save')
        ->assertHasNoErrors()->assertDispatched('toast', type: 'error');

    expect(app(Settings::class)->get('push.firebase.app_id_android_driver'))->toBe(androidId('x'));
});

// ── google-services.json ─────────────────────────────────────────────────────────────────────────────────────────────

function googleServices(array $packages, string $number = '123456789012', string $project = 'al-bahar-home'): string
{
    return json_encode([
        'project_info' => ['project_number' => $number, 'project_id' => $project],
        'client' => array_values(array_map(fn (string $package) => [
            'client_info' => ['mobilesdk_app_id' => "1:{$number}:android:".substr(md5($package), 0, 16), 'android_client_info' => ['package_name' => $package]],
            'api_key' => [['current_key' => KEY_ANDROID]],
        ], $packages)),
        'configuration_version' => '1',
    ]);
}

it('fills the three Android App IDs and the key by package name from google-services.json, each its own', function () {
    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)
        ->call('importGoogleServices', googleServices(['qa.albahar.customer', 'qa.albahar.driver', 'qa.albahar.staff', 'qa.albahar.customer.dev']));

    $page->assertSet('appIdAndroidCustomer', '1:123456789012:android:'.substr(md5('qa.albahar.customer'), 0, 16))
        ->assertSet('appIdAndroidDriver', '1:123456789012:android:'.substr(md5('qa.albahar.driver'), 0, 16))
        ->assertSet('appIdAndroidStaff', '1:123456789012:android:'.substr(md5('qa.albahar.staff'), 0, 16))
        ->assertSet('apiKeyAndroid', KEY_ANDROID)->assertSet('projectId', 'al-bahar-home')->assertHasNoErrors();
    expect($page->get('importNote')['level'])->toBe('ok');
    expect(PushHealth::duplicates(['android' => ['c' => $page->get('appIdAndroidCustomer'), 'd' => $page->get('appIdAndroidDriver'), 's' => $page->get('appIdAndroidStaff')]]))->toBe([]);
    expect(app(Settings::class)->get('push.firebase.app_id_android_customer'))->toBeNull(); // nothing is saved until the person presses Save
});

it('fills what the file has and says which packages are still missing from it', function () {
    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->call('importGoogleServices', googleServices(['qa.albahar.driver']));

    $page->assertSet('appIdAndroidDriver', '1:123456789012:android:'.substr(md5('qa.albahar.driver'), 0, 16))->assertSet('appIdAndroidCustomer', '');
    expect($page->get('importNote'))->toMatchArray(['level' => 'error'])->and($page->get('importNote')['text'])->toContain('qa.albahar.customer')->toContain('qa.albahar.staff');
});

it('refuses a file that is not google-services.json, one from another project, and one without our packages', function (string $json, string $messageKey) {
    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->set('senderId', '123456789012')->call('importGoogleServices', $json);

    expect($page->get('importNote')['level'])->toBe('error')->and($page->get('importNote')['text'])->toStartWith(substr(__($messageKey, ['number' => '999999999999', 'expected' => '123456789012']), 0, 20))
        ->and($page->get('appIdAndroidCustomer'))->toBe('');
})->with([
    'not json' => ['hello', 'push_health.import.bad_file'],
    'json of another kind' => ['{"type":"service_account"}', 'push_health.import.bad_file'],
    'another project' => [fn () => googleServices(['qa.albahar.customer'], '999999999999'), 'push_health.import.other_project'],
    'other packages only' => [fn () => googleServices(['com.example.other']), 'push_health.import.none'],
]);

it('lets only people who may edit the settings use the import and the test', function () {
    $viewer = staffWithPermissions(['settings.view']);

    Livewire::actingAs($viewer)->test(PushNotificationsPage::class)->call('importGoogleServices', googleServices(['qa.albahar.customer']))->assertForbidden();
    Livewire::actingAs($viewer)->test(PushNotificationsPage::class)->call('sendPhoneTest')->assertForbidden();
});

// ── The test push ────────────────────────────────────────────────────────────────────────────────────────────────────

it('sends a real test to one phone and says in plain words that Firebase accepted it', function () {
    configureFirebase();
    fcmFake();
    $phone = nativeDevice(User::factory()->create(), 'tok-customer-aaaaaaaaaaaaaaaaaaaaaaaa', 'driver');

    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->set('phoneTarget', $phone->id)->call('sendPhoneTest');

    $page->assertDispatched('toast', type: 'success')->assertSee(__('push_health.fcm.ok'));
    $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send'))->map(fn ($p) => $p[0]->data()['message'])->values()->all();
    expect($sent)->toHaveCount(1)->and($sent[0]['token'])->toBe('tok-customer-aaaaaaaaaaaaaaaaaaaaaaaa')->and($sent[0]['notification']['title'])->toBe(__('push_health.test.title'));
    expect(NotificationLog::where('event', 'push.test')->sole())->toMatchArray(['status' => 'sent']);
});

it('also sends the sample live order card to the customer app on Android, and only there', function () {
    configureFirebase();
    fcmFake();
    $u = User::factory()->create();
    $customer = nativeDevice($u, 'tok-customer-aaaaaaaaaaaaaaaaaaaaaaaa', 'customer');
    $driver = nativeDevice($u, 'tok-driver-bbbbbbbbbbbbbbbbbbbbbbbbbb', 'driver');
    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class);

    $page->set('phoneTarget', $customer->id)->set('phoneCard', true)->call('sendPhoneTest')->assertSee(__('push_health.test.card_ok'));
    $messages = Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send'))->map(fn ($p) => $p[0]->data()['message'])->values()->all();
    expect($messages)->toHaveCount(2)->and($messages[1])->not->toHaveKey('notification')->and($messages[1]['data']['kind'])->toBe('order_progress')
        ->and($messages[1]['data']['order_id'])->toBe(PushTester::SAMPLE_ORDER)->and((int) $messages[1]['data']['seq'])->toBeGreaterThan(0);

    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]), 'fcm.googleapis.com/*' => Http::response(['name' => 'x'])]);
    $page->set('phoneTarget', $driver->id)->call('sendPhoneTest');
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send')))->toHaveCount(1);
    expect(PushTester::hasCard($customer))->toBeTrue()->and(PushTester::hasCard($driver))->toBeFalse();
});

it('says what Firebase answered in plain words, and removes a registration Firebase says is dead', function (int $status, array $body, string $explain, bool $removed) {
    configureFirebase();
    fcmFake($status, $body);
    $phone = nativeDevice(User::factory()->create(), 'tok-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->set('phoneTarget', $phone->id)->call('sendPhoneTest');

    $page->assertSee(__("push_health.fcm.$explain"))->assertDispatched('toast', type: 'error');
    expect($phone->fresh()->disabled_at !== null)->toBe($removed)->and(NotificationLog::where('event', 'push.test')->sole()->status)->toBe('failed');
})->with([
    'token no longer valid' => [404, ['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]], 'unregistered', true],
    'token of another project' => [403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'SenderId mismatch', 'details' => [['errorCode' => 'SENDER_ID_MISMATCH']]]], 'sender_mismatch', true],
    'no apple key' => [401, ['error' => ['status' => 'UNAUTHENTICATED', 'message' => 'Auth error from APNS', 'details' => [['errorCode' => 'THIRD_PARTY_AUTH_ERROR']]]], 'apns_key', false],
    'server key refused' => [403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']], 'permission_denied', false],
    'too many' => [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded', 'details' => [['errorCode' => 'QUOTA_EXCEEDED']]]], 'rate_limited', false],
    'firebase down' => [503, ['error' => ['status' => 'UNAVAILABLE', 'message' => 'The service is currently unavailable.']], 'fcm_down', false],
]);

it('shows FCM\'s own code and HTTP status next to the plain words', function () {
    configureFirebase();
    fcmFake(404, ['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]]);
    $phone = nativeDevice(User::factory()->create(), 'tok-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->set('phoneTarget', $phone->id)->call('sendPhoneTest')
        ->assertSee(__('push_health.test.technical', ['status' => 404, 'code' => 'UNREGISTERED']));
});

it('tells the person when nobody has registered yet, and sends nothing', function () {
    configureFirebase();
    fcmFake();

    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->assertSee(__('push_health.test.no_devices'))->call('sendPhoneTest')->assertDispatched('toast', type: 'error');
    expect(Http::recorded())->toHaveCount(0);
});

it('never sends to a phone that was switched off, a web browser through the phone picker, or an id that is not a device', function () {
    configureFirebase();
    fcmFake();
    $u = User::factory()->create();
    $off = nativeDevice($u, 'tok-off-aaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'customer', 'android', ['disabled_at' => now()]);
    $web = UserDevice::create(['user_id' => $u->id, 'platform' => 'web', 'app' => 'store', 'push_token' => 'tok-web-aaaaaaaaaaaaaaaaaaaaaaaaaaa']);
    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class);

    foreach ([$off->id, $web->id, 'not-a-uuid', '00000000-0000-4000-8000-000000000000'] as $target) {
        $page->set('phoneTarget', $target)->call('sendPhoneTest')->assertDispatched('toast', type: 'error');
    }
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send')))->toHaveCount(0);
});

it('sends to the signed-in person\'s own devices when "my devices" is picked', function () {
    configureFirebase();
    fcmFake();
    nativeDevice($this->admin, 'tok-mine-aaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'staff');
    nativeDevice(User::factory()->create(), 'tok-other-aaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    Livewire::actingAs($this->admin)->test(PushNotificationsPage::class)->call('sendPhoneTest')->assertSee(__('push_health.fcm.ok'));

    $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send'))->map(fn ($p) => $p[0]->data()['message']['token'])->values()->all();
    expect($sent)->toBe(['tok-mine-aaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
});

it('does not hide the phones of other people from the picker, but never shows a token, only app, version, masked number and time', function () {
    configureFirebase();
    $other = User::factory()->create(['phone' => '+97455123456']);
    nativeDevice($other, 'tok-secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'customer');

    $page = Livewire::actingAs($this->admin)->test(PushNotificationsPage::class);

    $page->assertSee(__('push_health.target.app.customer'))->assertSee('1.0.0+12')->assertDontSee('tok-secret')->assertDontSee('+97455123456');
});

// ── push:test ────────────────────────────────────────────────────────────────────────────────────────────────────────

it('sends the test from the command line to a person\'s phones and prints what Firebase said', function () {
    configureFirebase();
    fcmFake();
    $u = User::factory()->create(['phone' => '+97455123456']);
    nativeDevice($u, 'tok-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    $this->artisan('push:test', ['user' => '+97455123456', '--card' => true])->expectsOutputToContain(__('push_health.fcm.ok', [], 'en'))->expectsOutputToContain('Test sent to 1 of 1')->assertSuccessful();
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send')))->toHaveCount(2);
});

it('sends to the phone that registered last when no person is named, and explains a failure', function () {
    configureFirebase();
    fcmFake(404, ['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]]);
    nativeDevice(User::factory()->create(), 'tok-old-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'customer', 'android', ['last_seen_at' => now()->subDay()]);
    nativeDevice(User::factory()->create(), 'tok-new-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'driver');

    $this->artisan('push:test')->expectsOutputToContain(__('push_health.fcm.unregistered', [], 'en'))->assertFailed();

    $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'messages:send'))->map(fn ($p) => $p[0]->data()['message']['token'])->values()->all();
    expect($sent)->toBe(['tok-new-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
});

it('says so when push is not ready, when the person is unknown and when they have no phone', function () {
    $this->artisan('push:test')->expectsOutputToContain('add the server key')->assertFailed();

    configureFirebase();
    $this->artisan('push:test', ['user' => 'nobody@example.test'])->expectsOutputToContain('No person found')->assertFailed();

    $u = User::factory()->create(['email' => 'has-none@example.test']);
    $this->artisan('push:test', ['user' => $u->email])->expectsOutputToContain('No phone has registered')->assertFailed();
});

// ── What the log says when a push reaches nobody ─────────────────────────────────────────────────────────────────────

it('records why a push reached nobody (Firebase\'s own words) in the notification log', function () {
    configureFirebase();
    fcmFake(403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'SenderId mismatch', 'details' => [['errorCode' => 'SENDER_ID_MISMATCH']]]]);
    $u = User::factory()->create();
    nativeDevice($u, 'tok-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    $log = NotificationLog::create(['user_id' => $u->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'queued']);

    (new SendNotificationJob($log->id, 'Hello', 'World'))->handle(app(SmsSender::class), app(FcmClient::class), app(WhatsAppClient::class));

    $log->refresh();
    expect($log->status)->toBe('failed')->and($log->error)->toContain('403')->toContain('SENDER_ID_MISMATCH')->toContain('SenderId mismatch');
});
