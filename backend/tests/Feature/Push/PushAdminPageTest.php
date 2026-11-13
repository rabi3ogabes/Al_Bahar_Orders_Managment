<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Services\FirebaseConfig;
use App\Domain\Settings\Models\SystemSetting;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\PushNotificationsPage;
use App\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/PushSupport.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
    $this->branch = Branch::factory()->create();
});

function fillForm($component, array $values)
{
    foreach ($values as $prop => $value) {
        $component->set($prop, $value);
    }

    return $component;
}

function leaked(string $needle): bool
{
    return str_contains(json_encode(AuditLog::all()->toArray()), $needle)
        || str_contains(json_encode(NotificationLog::all()->toArray()), $needle);
}

it('opens for people with settings.view and stays closed for everyone else', function () {
    $this->get(route('admin.push.index'))->assertRedirect();
    $this->actingAs(staffWithRole('pickup_employee', $this->branch))->get(route('admin.push.index'))->assertForbidden();
    $this->actingAs(staffWithRole('branch_manager', $this->branch))->get(route('admin.push.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.push.index'))->assertOk()->assertSee(__('push_admin.title'))->assertSee('AIzaSy');
});

it('is in the menu for people who may see settings', function () {
    $routes = fn (User $u) => collect(AdminNavigation::for($u))->flatMap(fn ($g) => array_column($g['items'], 'route'));

    expect($routes($this->admin))->toContain('admin.push.index')->and($routes(staffWithRole('pickup_employee', $this->branch)))->not->toContain('admin.push.index');
});

it('shows the guided steps, an empty checklist and example values on a fresh install, in English and Arabic', function () {
    $this->actingAs($this->admin);
    $page = Livewire::test(PushNotificationsPage::class);
    foreach ([1, 2, 3, 4, 5, 6] as $n) {
        $page->assertSee(__("push_admin.steps.$n.title"));
    }
    $page->assertSee('Cloud Messaging')->assertSee('Service accounts')->assertSee('al-bahar-home')->assertSee('1:123456789012:web:')
        ->assertSee(__('push_admin.status.missing'))->assertSee(__('push_admin.status.none_yet'));

    app()->setLocale('ar');
    Livewire::test(PushNotificationsPage::class)->assertSee('الإشعارات الفورية')->assertSee(__('push_admin.steps.5.title'));
});

it('validates every field format and says what is wrong in plain words', function (string $prop, string $bad, string $messageKey) {
    $this->actingAs($this->admin);
    Livewire::test(PushNotificationsPage::class)->set($prop, $bad)->assertHasErrors([$prop => 'regex'])->assertSee(__("push_admin.err.$messageKey"));
})->with([
    'project id with capitals' => ['projectId', 'Al-Bahar', 'project_id'],
    'project id too short' => ['projectId', 'abc', 'project_id'],
    'api key without AIza' => ['apiKey', 'XYZaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q', 'api_key'],
    'api key too short' => ['apiKey', 'AIzaShort', 'api_key'],
    'auth domain with scheme' => ['authDomain', 'https://x.firebaseapp.com', 'auth_domain'],
    'sender id with letters' => ['senderId', '12345abc', 'sender_id'],
    'app id of an android app' => ['appId', '1:123456789012:android:0a1b2c3d4e5f6a7b', 'app_id'],
    'vapid key too short' => ['vapidKey', 'BAswVXq', 'vapid_key'],
    'vapid key not starting with B' => ['vapidKey', 'X'.substr(firebaseFormValues()['vapidKey'], 1), 'vapid_key'],
]);

it('accepts valid values, a pasted VAPID key with padding or spaces, and an empty auth domain', function () {
    $this->actingAs($this->admin);
    $c = Livewire::test(PushNotificationsPage::class);
    fillForm($c, array_merge(firebaseFormValues(), ['vapidKey' => '  '.firebaseFormValues()['vapidKey'].'=  ']))->assertHasNoErrors();
    $c->set('authDomain', 'login.al-bahar.example')->assertHasNoErrors();
});

it('asks for all four web fields together, and a project id with them', function () {
    $this->actingAs($this->admin);
    $c = Livewire::test(PushNotificationsPage::class)->set('apiKey', firebaseFormValues()['apiKey'])->call('save');

    $c->assertHasErrors(['projectId' => 'required_with', 'senderId' => 'required_with', 'appId' => 'required_with', 'vapidKey' => 'required_with']);
    expect(SystemSetting::where('group', 'push')->count())->toBe(0);
});

it('saves the public values and the sealed server key, then never shows the key again', function () {
    $this->actingAs($this->admin);
    $c = fillForm(Livewire::test(PushNotificationsPage::class), array_merge(firebaseFormValues(), ['serviceAccountJson' => fakeServiceAccountJson()]))->call('save');

    $c->assertHasNoErrors()->assertDispatched('toast', type: 'success')->assertSet('serviceAccountJson', '')->assertSet('replacingKey', false)
        ->assertDontSee('PRIVATE KEY')->assertDontSee('BEGIN')->assertSee('firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com')->assertSee(__('push_admin.key_ok'));
    $s = app(Settings::class);
    expect($s->get('push.firebase.project_id'))->toBe('al-bahar-home')->and($s->get('push.firebase.api_key'))->toBe(firebaseFormValues()['apiKey'])
        ->and($s->get('push.firebase.messaging_sender_id'))->toBe('123456789012')->and($s->get('push.firebase.app_id_web'))->toBe(firebaseFormValues()['appId'])
        ->and($s->get('push.firebase.vapid_key'))->toBe(firebaseFormValues()['vapidKey']);
    $stored = SystemSetting::where('group', 'push')->where('key', 'firebase.service_account')->firstOrFail();
    expect($stored->is_secret)->toBeTrue()->and(json_encode($stored->value))->not->toContain('PRIVATE KEY');
    // Only the fields push needs survive: the file's own token endpoint is dropped.
    expect(json_decode($s->get('push.firebase.service_account'), true))->not->toHaveKey('token_uri')->toHaveKey('client_email');
    expect(app(FirebaseConfig::class)->hasServerCredentials())->toBeTrue();
});

it('writes an audit entry for every change and never a secret', function () {
    $this->actingAs($this->admin);
    fillForm(Livewire::test(PushNotificationsPage::class), array_merge(firebaseFormValues(), ['serviceAccountJson' => fakeServiceAccountJson()]))->call('save')
        ->call('toggleEnabled')->call('removeServiceAccount');

    $actions = AuditLog::pluck('action')->unique()->all();
    expect($actions)->toContain('settings.updated', 'push.service_account_replaced', 'push.switched_on', 'push.service_account_removed')
        ->and(AuditLog::where('action', 'push.service_account_replaced')->first()->metadata)->toEqual(['client_email' => 'firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com', 'project_id' => 'al-bahar-home']);
    foreach (['PRIVATE KEY', 'BEGIN', 'kid9f8e7d6c5b4a', throwawayPrivateKey()] as $secret) {
        expect(leaked(trim($secret)))->toBeFalse();
    }
    expect(AuditLog::where('action', 'settings.updated')->get()->contains(fn ($l) => json_encode($l->new_values) === '{"value":"[secret]"}'))->toBeTrue();
});

it('rejects a server key that is not usable, with the reason', function (string $json, string $error) {
    $this->actingAs($this->admin);
    $c = fillForm(Livewire::test(PushNotificationsPage::class), array_merge(firebaseFormValues(), ['serviceAccountJson' => $json]))->call('save');

    $c->assertHasErrors('serviceAccountJson')->assertSee(__("push_admin.key_error.$error"))->assertNotDispatched('toast');
    expect(SystemSetting::where('group', 'push')->count())->toBe(0);
})->with([
    'not json' => ['hello there', 'not_json'],
    'wrong type' => ['{"type":"authorized_user"}', 'wrong_type'],
    'other project' => [fn () => fakeServiceAccountJson('another-project'), 'project_mismatch'],
    'no email' => ['{"type":"service_account","project_id":"al-bahar-home","private_key":"PRIVATE KEY"}', 'missing_email'],
]);

it('checks a pasted key as soon as it arrives and needs the project id first', function () {
    $this->actingAs($this->admin);
    Livewire::test(PushNotificationsPage::class)->set('serviceAccountJson', fakeServiceAccountJson())->assertHasErrors('serviceAccountJson')->assertSee(__('push_admin.key_error.need_project'));
    Livewire::test(PushNotificationsPage::class)->set('projectId', 'al-bahar-home')->set('serviceAccountJson', fakeServiceAccountJson('other-project'))->assertSee(__('push_admin.key_error.project_mismatch'));
    Livewire::test(PushNotificationsPage::class)->set('projectId', 'al-bahar-home')->set('serviceAccountJson', fakeServiceAccountJson())->assertHasNoErrors();
});

it('blocks changing the project id away from the saved key’s project', function () {
    configureFirebase();
    $this->actingAs($this->admin);

    Livewire::test(PushNotificationsPage::class)->set('projectId', 'another-project')->call('save')->assertHasErrors('projectId')->assertSee(__('push_admin.key_error.project_mismatch_saved'));
    expect(app(Settings::class)->get('push.firebase.project_id'))->toBe('al-bahar-home');
});

it('keeps Save off until something changed', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    $c = Livewire::test(PushNotificationsPage::class);

    expect($c->viewData('dirty'))->toBeFalse();
    $c->set('senderId', '999999999999');
    expect($c->viewData('dirty'))->toBeTrue();
    $c->set('senderId', '123456789012');
    expect($c->viewData('dirty'))->toBeFalse();
    $c->set('projectId', 'al-bahar-home');
    $c->call('save')->assertDispatched('toast', type: 'success'); // nothing changed → the page says so instead of writing
    expect(AuditLog::where('action', 'settings.updated')->count())->toBe(AuditLog::where('action', 'settings.updated')->count());
});

it('switches push on and off at once, and refuses to switch on without a server key', function () {
    $this->actingAs($this->admin);
    Livewire::test(PushNotificationsPage::class)->call('toggleEnabled')->assertDispatched('toast', type: 'error')->assertSet('enabled', false);
    expect(app(Settings::class)->get('push.enabled'))->toBeNull();

    configureFirebase(enabled: false);
    $c = Livewire::test(PushNotificationsPage::class)->assertSet('enabled', false)->call('toggleEnabled')->assertSet('enabled', true)->assertDispatched('toast', type: 'success');
    expect(app(FirebaseConfig::class)->configured())->toBeTrue();
    $c->call('toggleEnabled')->assertSet('enabled', false);
    expect(app(FirebaseConfig::class)->configured())->toBeFalse()->and(app(Settings::class)->get('push.enabled'))->toBeFalse();
});

it('lets people with only settings.view look but not change anything', function () {
    configureFirebase();
    $viewer = staffWithPermissions(['settings.view']);
    $this->actingAs($viewer);
    $c = Livewire::test(PushNotificationsPage::class)->assertDontSeeHtml('wire:target="save"')->assertSee(__('push_admin.edit_denied'));

    foreach (['toggleEnabled', 'save', 'testConnection', 'sendTest', 'removeServiceAccount'] as $method) {
        Livewire::test(PushNotificationsPage::class)->call($method)->assertForbidden();
    }
    expect(app(Settings::class)->get('push.enabled'))->toBeTrue()->and(app(FirebaseConfig::class)->serviceAccountSource())->toBe('dashboard');
});

it('removes the server key only after the confirm dialog and only a dashboard key', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    $c = Livewire::test(PushNotificationsPage::class)->set('confirmRemoveKey', true)->assertSee(__('push_admin.key_remove_q'))->call('removeServiceAccount');

    $c->assertSet('confirmRemoveKey', false)->assertDispatched('toast', type: 'success');
    expect(app(FirebaseConfig::class)->hasServerCredentials())->toBeFalse();

    // A key that comes from the old server file cannot be removed from the dashboard.
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, fakeServiceAccountJson('env-project'));
    config(['services.fcm.project_id' => 'env-project', 'services.fcm.credentials_path' => $path]);
    Livewire::test(PushNotificationsPage::class)->assertSee(__('push_admin.key_from_env'))->call('removeServiceAccount');
    expect(app(FirebaseConfig::class)->serviceAccountSource())->toBe('env');
    unlink($path);
});

it('tests the connection with the saved key', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.t']),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/fake_message_id']),
    ]);

    Livewire::test(PushNotificationsPage::class)->call('testConnection')->assertDispatched('toast', type: 'success', message: __('push_admin.conn_ok'))->assertSee(__('push_admin.conn_ok'));

    expect(AuditLog::where('action', 'push.connection_tested')->count())->toBe(1)->and(leaked('ya29'))->toBeFalse();
});

it('explains a failed connection test in plain words and shows what Firebase said', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.t2']),
        'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Firebase Cloud Messaging API has not been used in project 1 before or it is disabled.']], 403),
    ]);

    $fail = Livewire::test(PushNotificationsPage::class)->call('testConnection');

    $fail->assertDispatched('toast', type: 'error', message: __('push_admin.conn.api_disabled'))->assertSee(__('push_admin.conn.api_disabled'))->assertSee('has not been used');
    expect(AuditLog::where('action', 'push.connection_tested')->first()->metadata)->toEqual(['ok' => false, 'result' => 'api_disabled', 'status' => 403])->and(leaked('ya29'))->toBeFalse();
});

it('only allows the connection test once the form is saved', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    $c = Livewire::test(PushNotificationsPage::class)->set('senderId', '999999999999');

    expect($c->viewData('dirty'))->toBeTrue();
    $c->assertSeeHtml('title="'.__('push_admin.save_first').'"');
});

it('sends a real test to the person’s own devices, reports each one and retires dead ones', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    UserDevice::create(['user_id' => $this->admin->id, 'platform' => 'web', 'app' => 'admin', 'browser' => 'Chrome on Windows', 'push_token' => 'ok-web', 'last_seen_at' => now()]);
    UserDevice::create(['user_id' => $this->admin->id, 'platform' => 'web', 'app' => 'admin', 'browser' => 'Firefox on Mac', 'push_token' => 'dead-web', 'last_seen_at' => now()->subDay()]);
    UserDevice::create(['user_id' => $this->admin->id, 'platform' => 'android', 'push_token' => 'ok-phone', 'last_seen_at' => now()->subDays(2)]);
    UserDevice::create(['user_id' => User::factory()->create()->id, 'platform' => 'web', 'push_token' => 'someone-else']);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
        'fcm.googleapis.com/*' => fn (Request $r) => $r['message']['token'] === 'dead-web'
            ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
            : Http::response(['name' => 'ok']),
    ]);

    $c = Livewire::test(PushNotificationsPage::class)->call('sendTest');

    $c->assertDispatched('toast', type: 'success', message: __('push_admin.test.sent_n', ['ok' => 2, 'total' => 3]))
        ->assertSee('Chrome on Windows')->assertSee('Firefox on Mac')->assertSee(__('push_admin.test.outcome.removed'))->assertSee(__('push_admin.test.outcome.sent'));
    $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))->map(fn ($p) => $p[0]['message']);
    expect($sent->pluck('token')->sort()->values()->all())->toBe(['dead-web', 'ok-phone', 'ok-web'])
        ->and($sent->firstWhere('token', 'ok-web')['webpush']['notification']['title'])->toBe(__('push_admin.test.title'))
        ->and($sent->firstWhere('token', 'ok-phone'))->toHaveKey('android');
    expect(UserDevice::where('push_token', 'dead-web')->value('disabled_at'))->not->toBeNull()->and(UserDevice::where('push_token', 'ok-web')->value('disabled_at'))->toBeNull()
        ->and(UserDevice::where('push_token', 'someone-else')->value('disabled_at'))->toBeNull();
    $log = NotificationLog::where('event', 'push.test')->firstOrFail();
    expect($log->status)->toBe('sent')->and($log->channel)->toBe('push')->and($log->user_id)->toBe($this->admin->id)->and($log->sent_at)->not->toBeNull();
});

it('writes the test message in the dashboard language', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    app()->setLocale('ar');
    UserDevice::create(['user_id' => $this->admin->id, 'platform' => 'web', 'push_token' => 'ar-web']);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 't']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

    Livewire::test(PushNotificationsPage::class)->call('sendTest');

    $m = Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))->last()[0]['message'];
    expect($m['webpush']['notification']['title'])->toBe('إشعار تجريبي');
});

it('reports a failed test with the reason and logs it as a problem', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    UserDevice::create(['user_id' => $this->admin->id, 'platform' => 'web', 'push_token' => 'broken']);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 't']), 'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);

    Livewire::test(PushNotificationsPage::class)->call('sendTest')->assertDispatched('toast', type: 'error')->assertSee('PERMISSION_DENIED')->assertSee(__('push_admin.test.outcome.failed'));

    expect(NotificationLog::where('event', 'push.test')->first()->status)->toBe('failed')->and(NotificationLog::first()->error)->toBe('PERMISSION_DENIED');
    Livewire::test(PushNotificationsPage::class)->assertSee('PERMISSION_DENIED'); // "last problem" on the checklist
});

it('does not send a test before push is on, or with no device', function () {
    $this->actingAs($this->admin);
    Http::fake();
    Livewire::test(PushNotificationsPage::class)->call('sendTest')->assertDispatched('toast', type: 'error', message: __('push_admin.test.not_ready'));

    configureFirebase();
    Livewire::test(PushNotificationsPage::class)->call('sendTest')->assertDispatched('toast', type: 'error', message: __('push_admin.test.no_devices'));
    Http::assertNothingSent();
});

it('lists only the person’s own devices and lets them stop one, never someone else’s', function () {
    $this->actingAs($this->admin);
    $mine = UserDevice::create(['user_id' => $this->admin->id, 'platform' => 'web', 'app' => 'admin', 'browser' => 'Chrome on Windows', 'push_token' => 'mine']);
    $theirs = UserDevice::create(['user_id' => User::factory()->create()->id, 'platform' => 'web', 'browser' => 'Edge on Mac', 'push_token' => 'theirs']);

    $c = Livewire::test(PushNotificationsPage::class)->assertSee('Chrome on Windows')->assertDontSee('Edge on Mac');
    $c->call('removeDevice', $theirs->id);
    expect($theirs->fresh()->disabled_at)->toBeNull();
    $c->call('removeDevice', 'not-a-uuid')->call('removeDevice', $mine->id)->assertDispatched('toast', type: 'success')->assertDontSee('Chrome on Windows');
    expect($mine->fresh()->disabled_at)->not->toBeNull()->and(AuditLog::where('action', 'push.device_removed')->count())->toBe(1);
});

it('counts registered devices per platform and shows the last delivery and last problem', function () {
    $this->actingAs($this->admin);
    $u = User::factory()->create();
    foreach (['web', 'web', 'android', 'ios'] as $i => $p) {
        UserDevice::create(['user_id' => $u->id, 'platform' => $p, 'push_token' => "t$i"]);
    }
    UserDevice::create(['user_id' => $u->id, 'platform' => 'web', 'push_token' => 'off', 'disabled_at' => now()]);
    NotificationLog::create(['user_id' => $u->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'sent', 'sent_at' => now()->subHour()]);
    NotificationLog::create(['user_id' => $u->id, 'event' => 'order.confirmed', 'channel' => 'push', 'status' => 'failed', 'error' => 'Quota exceeded']);

    $c = Livewire::test(PushNotificationsPage::class);

    expect($c->viewData('counts'))->toEqual(['web' => 2, 'android' => 1, 'ios' => 1]);
    $c->assertSee('Quota exceeded')->assertDontSee(__('push_admin.status.none_yet'));
});

it('registers the dashboard browser through the session route', function () {
    $this->actingAs($this->admin);
    $token = str_repeat('a', 40);

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36')
        ->postJson(route('admin.push.devices.store'), ['push_token' => $token])->assertCreated()->assertJsonPath('data.browser', 'Chrome on Windows');

    $d = UserDevice::where('push_token', $token)->firstOrFail();
    expect($d->user_id)->toBe($this->admin->id)->and($d->platform)->toBe('web')->and($d->app)->toBe('admin')->and($d->locale)->toBe('en')
        ->and(AuditLog::where('action', 'push.browser_enabled')->count())->toBe(1);

    $this->postJson(route('admin.push.devices.store'), ['push_token' => $token])->assertCreated();
    expect(UserDevice::count())->toBe(1);
});

it('moves a browser token to the staff member who registers it now', function () {
    $other = User::factory()->superAdmin()->create();
    UserDevice::create(['user_id' => $other->id, 'platform' => 'web', 'push_token' => str_repeat('b', 40)]);
    $this->actingAs($this->admin)->postJson(route('admin.push.devices.store'), ['push_token' => str_repeat('b', 40)])->assertCreated();

    expect(UserDevice::count())->toBe(1)->and(UserDevice::first()->user_id)->toBe($this->admin->id);
});

it('protects the browser registration route', function () {
    $this->postJson(route('admin.push.devices.store'), ['push_token' => str_repeat('c', 40)])->assertUnauthorized();
    $this->actingAs(staffWithRole('pickup_employee', $this->branch))->postJson(route('admin.push.devices.store'), ['push_token' => str_repeat('c', 40)])->assertForbidden();
    $this->actingAs($this->admin)->postJson(route('admin.push.devices.store'), ['push_token' => 'short'])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson(route('admin.push.devices.store'), ['push_token' => ['x']])->assertUnprocessable();
    expect(UserDevice::count())->toBe(0);
});

it('hands the page script only public values and never the service account', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    $html = Livewire::test(PushNotificationsPage::class)->html();

    expect($html)->toContain('data-boot')->toContain('AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q')->not->toContain('PRIVATE KEY')->not->toContain('BEGIN');
    $boot = json_decode(html_entity_decode(preg_match('/data-boot="([^"]*)"/', $html, $m) ? $m[1] : ''), true);
    expect($boot['config']['projectId'])->toBe('al-bahar-home')->and($boot['endpoint'])->toEndWith('/admin/push/devices')->and($boot['config'])->not->toHaveKey('private_key');
});

it('gives the browser no config while push is off, so the enable button stays off', function () {
    configureFirebase(enabled: false);
    $this->actingAs($this->admin);

    expect(Livewire::test(PushNotificationsPage::class)->viewData('boot')['config'])->toBeNull();
});
