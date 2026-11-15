<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\LiveActivityToken;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Services\ApnsConfig;
use App\Domain\Notifications\Services\ApnsKey;
use App\Domain\Notifications\Services\FirebaseConfig;
use App\Domain\Settings\Models\SystemSetting;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\PushNotificationsPage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/PushSupport.php';
require_once __DIR__.'/ApnsSupport.php';

/*
 * The "iPhone live activity" section of Dashboard → Push notifications: the Apple key (.p8, sealed like the Firebase server key), its
 * switch, Save and the Test button, with EN/AR help.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
    config(['platform.live_order.apns_backoff_ms' => 0]);
    $this->values = ['apnsTeamId' => 'ABCDE12345', 'apnsKeyId' => 'FGHIJ67890', 'apnsBundleId' => 'qa.albahar.customer', 'apnsEnvironment' => 'auto', 'apnsKeyText' => throwawayApnsKey()];
    $this->fill = function ($component, array $values = []) {
        foreach (array_merge($this->values, $values) as $prop => $value) {
            $component->set($prop, $value);
        }

        return $component;
    };
    $this->leaked = fn (string $needle) => str_contains(json_encode(AuditLog::all()->toArray()), $needle) || str_contains(json_encode(NotificationLog::all()->toArray()), $needle);
});

it('shows the section with its steps, an empty checklist and example values, in English and Arabic', function () {
    $this->actingAs($this->admin);
    $page = Livewire::test(PushNotificationsPage::class);

    $page->assertSee(__('push_apns.title'))->assertSee(__('push_apns.status.missing'))->assertSee('ABCDE12345')->assertSee('AuthKey_')->assertSee('Apple Push Notifications service (APNs)')
        ->assertSee('docs/guides/IOS_LIVE_ACTIVITY.md')->assertSet('apnsBundleId', 'qa.albahar.customer')->assertSet('apnsEnvironment', 'auto')->assertSet('apnsEnabled', false);
    foreach (range(1, 6) as $n) {
        $page->assertSee(__("push_apns.steps.$n.title"));
    }

    app()->setLocale('ar');
    Livewire::test(PushNotificationsPage::class)->assertSee('النشاط المباشر على آيفون')->assertSee(__('push_apns.steps.3.title'));
});

it('has the same texts in English and Arabic', function () {
    $keys = function (string $locale) {
        $flat = [];
        $walk = function ($node, string $path) use (&$walk, &$flat) {
            foreach ($node as $k => $v) {
                is_array($v) ? $walk($v, "$path$k.") : $flat[] = "$path$k";
            }
        };
        $walk(require lang_path("$locale/push_apns.php"), '');
        sort($flat);

        return $flat;
    };

    expect($keys('ar'))->toBe($keys('en'));
});

it('validates every field and says what is wrong in plain words', function (string $prop, string $bad, string $messageKey) {
    $this->actingAs($this->admin);
    Livewire::test(PushNotificationsPage::class)->set($prop, $bad)->assertHasErrors([$prop])->assertSee(__("push_apns.err.$messageKey"));
})->with([
    'team id in lower case' => ['apnsTeamId', 'abcde12345', 'team_id'],
    'team id too short' => ['apnsTeamId', 'ABC', 'team_id'],
    'key id with a dash' => ['apnsKeyId', 'FGHIJ-7890', 'key_id'],
    'bundle id with spaces' => ['apnsBundleId', 'qa albahar', 'bundle_id'],
    'bundle id with one part' => ['apnsBundleId', 'albahar', 'bundle_id'],
    'empty bundle id' => ['apnsBundleId', '', 'bundle_id'],
    'unknown environment' => ['apnsEnvironment', 'staging', 'environment'],
]);

it('asks for the team and key ids together with the key', function () {
    $this->actingAs($this->admin);
    Livewire::test(PushNotificationsPage::class)->set('apnsKeyText', throwawayApnsKey())->call('saveApns')
        ->assertHasErrors(['apnsTeamId' => 'required_with', 'apnsKeyId' => 'required_with'])->assertSee(__('push_apns.err.required'));
    Livewire::test(PushNotificationsPage::class)->set('apnsTeamId', 'ABCDE12345')->call('saveApns')->assertHasErrors(['apnsKeyId' => 'required_with']);
    expect(SystemSetting::where('key', 'like', 'apns.%')->count())->toBe(0);
});

it('saves the ids and the sealed key, then never shows the key again', function () {
    $this->actingAs($this->admin);
    $body = trim(preg_replace('/-----[A-Z ]+-----/', '', throwawayApnsKey()));
    $c = ($this->fill)(Livewire::test(PushNotificationsPage::class))->call('saveApns');

    // (the page's own help text mentions "BEGIN PRIVATE KEY", so the key itself is what must not come back)
    $c->assertHasNoErrors()->assertDispatched('toast', type: 'success')->assertSet('apnsKeyText', '')->assertSet('replacingApnsKey', false)
        ->assertDontSee(substr($body, 0, 40))->assertDontSee(substr($body, 40, 40))->assertSee(__('push_apns.key_ok'))->assertSee('FGHIJ67890');
    $s = app(Settings::class);
    expect($s->get(ApnsConfig::TEAM_ID))->toBe('ABCDE12345')->and($s->get(ApnsConfig::KEY_ID))->toBe('FGHIJ67890')->and($s->get(ApnsConfig::BUNDLE_ID))->toBe('qa.albahar.customer')
        ->and($s->get(ApnsConfig::ENVIRONMENT))->toBe('auto');
    $stored = SystemSetting::where('group', 'push')->where('key', 'apns.private_key')->firstOrFail();
    expect($stored->is_secret)->toBeTrue()->and(json_encode($stored->value))->not->toContain('PRIVATE KEY')->and(json_encode($stored->value))->toContain('encrypted')
        ->and(app(ApnsConfig::class)->privateKey())->toContain('PRIVATE KEY')->and(app(ApnsConfig::class)->hasCredentials())->toBeTrue()
        ->and(app(ApnsConfig::class)->enabled())->toBeFalse();                                   // saving does not switch it on
});

it('accepts the key as pasted without its BEGIN and END lines and stores a complete one', function () {
    $this->actingAs($this->admin);
    $body = trim(preg_replace('/-----[A-Z ]+-----/', '', throwawayApnsKey()));
    ($this->fill)(Livewire::test(PushNotificationsPage::class), ['apnsKeyText' => $body])->call('saveApns')->assertHasNoErrors();

    expect(app(ApnsConfig::class)->privateKey())->toContain('-----BEGIN PRIVATE KEY-----');
});

it('writes an audit entry for every change and never the key', function () {
    $this->actingAs($this->admin);
    ($this->fill)(Livewire::test(PushNotificationsPage::class))->call('saveApns')->call('toggleApns')->call('toggleApns')->call('removeApnsKey');

    expect(AuditLog::pluck('action')->unique()->all())->toContain('settings.updated', 'push.apns_key_replaced', 'push.apns_switched_on', 'push.apns_switched_off', 'push.apns_key_removed')
        ->and(AuditLog::where('action', 'push.apns_key_replaced')->first()->metadata)->toEqual(['key_id' => 'FGHIJ67890']);
    foreach (['PRIVATE KEY', 'BEGIN', trim(preg_replace('/-----[A-Z ]+-----/', '', throwawayApnsKey()))] as $secret) {
        expect(($this->leaked)(trim($secret)))->toBeFalse();
    }
    expect(AuditLog::where('action', 'settings.updated')->get()->contains(fn ($l) => json_encode($l->new_values) === '{"value":"[secret]"}'))->toBeTrue();
});

it('rejects a key that is not usable, with the reason', function (string $raw, string $error) {
    $this->actingAs($this->admin);
    ($this->fill)(Livewire::test(PushNotificationsPage::class), ['apnsKeyText' => $raw])->call('saveApns')
        ->assertHasErrors('apnsKeyText')->assertSee(__("push_apns.key_error.$error"))->assertNotDispatched('toast');

    expect(app(ApnsConfig::class)->privateKey())->toBeNull()->and(SystemSetting::where('key', 'like', 'apns.%')->count())->toBe(0);
})->with([
    'plain text' => ['hello there', 'bad_key'],
    'damaged key' => ["-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----", 'bad_key'],
    'too big' => [str_repeat('a', ApnsKey::MAX_BYTES + 1), 'too_large'],
    'an RSA key' => [fn () => (function () {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);

        return $pem;
    })(), 'not_p256'],
]);

it('checks a pasted key as soon as it arrives', function () {
    $this->actingAs($this->admin);
    Livewire::test(PushNotificationsPage::class)->set('apnsKeyText', 'nope')->assertHasErrors('apnsKeyText')->assertSee(__('push_apns.key_error.bad_key'));
    Livewire::test(PushNotificationsPage::class)->set('apnsKeyText', throwawayApnsKey())->assertHasNoErrors();
});

it('keeps Save off until something changed', function () {
    $this->actingAs($this->admin);
    configureApns(enabled: false);
    $c = Livewire::test(PushNotificationsPage::class);

    expect($c->viewData('apnsDirty'))->toBeFalse();
    $c->set('apnsKeyId', 'ZZZZZ99999');
    expect($c->viewData('apnsDirty'))->toBeTrue();
    $c->set('apnsKeyId', 'FGHIJ67890');
    expect($c->viewData('apnsDirty'))->toBeFalse();
    $c->set('apnsEnvironment', 'sandbox');
    expect($c->viewData('apnsDirty'))->toBeTrue();
});

it('switches on and off at once, and refuses to switch on before everything is saved', function () {
    $this->actingAs($this->admin);
    Livewire::test(PushNotificationsPage::class)->call('toggleApns')->assertDispatched('toast', type: 'error', message: __('push_apns.need_setup'))->assertSet('apnsEnabled', false);
    expect(app(Settings::class)->get(ApnsConfig::ENABLED))->toBeNull();

    configureApns(enabled: false);
    $c = Livewire::test(PushNotificationsPage::class)->assertSet('apnsEnabled', false)->call('toggleApns')->assertSet('apnsEnabled', true)->assertDispatched('toast', type: 'success');
    expect(app(ApnsConfig::class)->configured())->toBeTrue();
    $c->call('toggleApns')->assertSet('apnsEnabled', false);
    expect(app(ApnsConfig::class)->configured())->toBeFalse()->and(app(Settings::class)->get(ApnsConfig::ENABLED))->toBeFalse();
});

it('is independent of the Firebase switch: neither moves the other', function () {
    configureFirebase();
    $this->actingAs($this->admin);
    configureApns(enabled: false);
    Livewire::test(PushNotificationsPage::class)->call('toggleApns')->call('toggleEnabled');

    expect(app(FirebaseConfig::class)->enabled())->toBeFalse()->and(app(ApnsConfig::class)->enabled())->toBeTrue();
});

it('removes the key only after the confirm dialog, and the switch goes off with it', function () {
    configureApns();
    $this->actingAs($this->admin);
    $c = Livewire::test(PushNotificationsPage::class)->set('confirmRemoveApnsKey', true)->assertSee(__('push_apns.key_remove_q'))->call('removeApnsKey');

    $c->assertSet('confirmRemoveApnsKey', false)->assertSet('apnsEnabled', false)->assertDispatched('toast', type: 'success');
    expect(app(ApnsConfig::class)->privateKey())->toBeNull()->and(app(ApnsConfig::class)->enabled())->toBeFalse()->and(app(ApnsConfig::class)->configured())->toBeFalse();
});

it('replaces the key and keeps the ids', function () {
    configureApns();
    $this->actingAs($this->admin);
    $new = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($new, $newPem);

    Livewire::test(PushNotificationsPage::class)->set('replacingApnsKey', true)->set('apnsKeyText', $newPem)->call('saveApns')->assertHasNoErrors();

    expect(trim(app(ApnsConfig::class)->privateKey()))->toBe(trim($newPem))->and(app(ApnsConfig::class)->keyId())->toBe('FGHIJ67890')->and(app(ApnsConfig::class)->enabled())->toBeTrue();
});

it('lets people with only settings.view look but not change anything', function () {
    configureApns();
    $this->actingAs(staffWithPermissions(['settings.view']));
    Livewire::test(PushNotificationsPage::class)->assertDontSeeHtml('wire:target="saveApns"')->assertSee(__('push_admin.edit_denied'));

    foreach (['toggleApns', 'saveApns', 'testApns', 'removeApnsKey'] as $method) {
        Livewire::test(PushNotificationsPage::class)->call($method)->assertForbidden();
    }
    expect(app(ApnsConfig::class)->enabled())->toBeTrue()->and(app(ApnsConfig::class)->privateKey())->not->toBeNull();
});

it('does not open for people without settings.view', function () {
    $this->actingAs(staffWithPermissions(['orders.view']));

    Livewire::test(PushNotificationsPage::class)->assertForbidden();
});

it('tests the saved key against Apple and reports a perfect setup as "connected"', function () {
    configureApns();
    $this->actingAs($this->admin);
    $apns = fakeApns();
    $apns['mode'] = 'bad_token';                                                                 // the rejection a perfect setup gets for a phone that does not exist

    Livewire::test(PushNotificationsPage::class)->call('testApns')->assertDispatched('toast', type: 'success', message: __('push_apns.conn_ok'))->assertSee(__('push_apns.conn_ok'));

    expect(AuditLog::where('action', 'push.apns_tested')->first()->metadata)->toEqual(['ok' => true, 'result' => 'ok', 'status' => 400])
        ->and(($this->leaked)('PRIVATE KEY'))->toBeFalse()
        ->and(apnsSent()[0]['url'])->toBe('https://api.push.apple.com/3/device/'.str_repeat('0', 64));
});

it('explains a failed test in plain words and shows what Apple said', function (string $mode, string $code) {
    configureApns();
    $this->actingAs($this->admin);
    fakeApns()['mode'] = $mode;

    $c = Livewire::test(PushNotificationsPage::class)->call('testApns');

    $c->assertDispatched('toast', type: 'error', message: __("push_apns.conn.$code"))->assertSee(__("push_apns.conn.$code"));
    expect(AuditLog::where('action', 'push.apns_tested')->first()->metadata['ok'])->toBeFalse();
})->with([
    'key, key id or team not accepted' => ['invalid_key', 'key_rejected'],
    'topic not allowed' => ['topic', 'topic'],
    'clock' => ['clock', 'clock'],
    'apple down' => ['down', 'apns_down'],
]);

it('shows Apple\'s own reason under a failed test', function () {
    configureApns();
    $this->actingAs($this->admin);
    fakeApns()['mode'] = 'invalid_key';

    Livewire::test(PushNotificationsPage::class)->call('testApns')->assertSee('InvalidProviderToken');
});

it('does not call Apple when there is nothing to test, and asks to save first when the form has changes', function () {
    $this->actingAs($this->admin);
    Http::fake();
    Livewire::test(PushNotificationsPage::class)->call('testApns')->assertDispatched('toast', type: 'error', message: __('push_apns.conn.not_configured'));
    Http::assertNothingSent();

    configureApns();
    $c = Livewire::test(PushNotificationsPage::class)->set('apnsTeamId', 'ZZZZZ99999');
    expect($c->viewData('apnsDirty'))->toBeTrue();
    $c->assertSeeHtml('title="'.__('push_admin.save_first').'"');
});

it('counts the live activities that are running and the phones that can start one', function () {
    configureApns();
    $this->actingAs($this->admin);
    $u = User::factory()->create();
    LiveActivityToken::create(['user_id' => $u->id, 'kind' => 'start', 'token' => hexToken('s1'), 'environment' => 'production']);
    LiveActivityToken::create(['user_id' => $u->id, 'kind' => 'start', 'token' => hexToken('s2'), 'environment' => 'production', 'disabled_at' => now()]);

    $c = Livewire::test(PushNotificationsPage::class);

    expect($c->viewData('apnsActivities'))->toBe(0)->and($c->viewData('apnsStarters'))->toBe(1)->and($c->viewData('apns'))->toMatchArray(['ready' => true, 'enabled' => true, 'key_id' => 'FGHIJ67890', 'team_id' => 'ABCDE12345']);
});

it('leaves the Firebase form as it was: saving it does not touch the Apple settings and the other way round', function () {
    configureApns();
    $this->actingAs($this->admin);
    $c = Livewire::test(PushNotificationsPage::class);
    foreach (firebaseFormValues() as $prop => $value) {
        $c->set($prop, $value);
    }
    $c->set('serviceAccountJson', fakeServiceAccountJson())->call('save')->assertHasNoErrors();

    expect(app(ApnsConfig::class)->configured())->toBeTrue()->and(app(Settings::class)->get('push.firebase.project_id'))->toBe('al-bahar-home');
    Livewire::test(PushNotificationsPage::class)->set('apnsKeyId', 'ZZZZZ99999')->call('saveApns')->assertHasNoErrors();
    expect(app(Settings::class)->get('push.firebase.project_id'))->toBe('al-bahar-home')->and(app(ApnsConfig::class)->keyId())->toBe('ZZZZZ99999');
});
