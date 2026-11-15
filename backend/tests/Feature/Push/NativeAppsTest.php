<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\FirebaseConfig;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Organization\PushNotificationsPage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

require_once __DIR__.'/PushSupport.php';

const ANDROID_KEY = 'AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R';
const IOS_KEY = 'AIzaSyC3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S';

/** The phone-app part of a saved setup, written the way the dashboard page writes it. */
function configurePhoneApps(array $only = ['android_customer', 'android_driver', 'android_staff', 'ios_customer']): void
{
    $s = app(Settings::class);
    $s->set('push.firebase.api_key_android', ANDROID_KEY);
    $s->set('push.firebase.api_key_ios', IOS_KEY);
    foreach ($only as $which) {
        [$platform, $app] = explode('_', $which);
        $s->set("push.firebase.app_id_{$platform}_{$app}", "1:123456789012:{$platform}:".substr(md5($which), 0, 16));
    }
}

it('hands one phone app the settings it needs to start Firebase, and nothing for the others', function () {
    configureFirebase();
    configurePhoneApps();

    $driver = $this->getJson('/api/v1/push-config?platform=android&app=driver')->assertOk();
    $driver->assertJsonPath('data.enabled', true)->assertJsonPath('data.native', [
        'projectId' => 'al-bahar-home', 'messagingSenderId' => '123456789012', 'apiKey' => ANDROID_KEY,
        'appId' => '1:123456789012:android:'.substr(md5('android_driver'), 0, 16),
    ]);
    $this->getJson('/api/v1/push-config?platform=ios&app=customer')->assertJsonPath('data.native.apiKey', IOS_KEY)
        ->assertJsonPath('data.native.appId', '1:123456789012:ios:'.substr(md5('ios_customer'), 0, 16));
    expect($driver->getContent())->not->toContain('PRIVATE KEY')->not->toContain('client_email')->not->toContain('private_key');
});

it('answers null for an app that has no ID yet, an app the platform does not have, or odd values', function () {
    configureFirebase();
    configurePhoneApps(['android_customer']);

    $this->getJson('/api/v1/push-config?platform=android&app=customer')->assertJsonPath('data.native.apiKey', ANDROID_KEY);
    foreach (['platform=android&app=driver', 'platform=ios&app=customer', 'platform=ios&app=driver', 'platform=android&app=admin'] as $query) {
        $this->getJson('/api/v1/push-config?'.$query)->assertOk()->assertJsonPath('data.native', null);
    }
});

it('leaves `native` out unless a platform and an app are asked for, so the web answer is unchanged', function () {
    configureFirebase();
    configurePhoneApps();

    expect($this->getJson('/api/v1/push-config')->json('data'))->not->toHaveKey('native');
    expect($this->getJson('/api/v1/push-config?platform=android')->json('data'))->not->toHaveKey('native');
    expect($this->getJson('/api/v1/push-config?platform=windows&app=customer')->json('data'))->not->toHaveKey('native');
});

it('gives phone apps nothing while push is off or the server key is missing', function () {
    configureFirebase(enabled: false);
    configurePhoneApps();
    $this->getJson('/api/v1/push-config?platform=android&app=customer')->assertJsonPath('data.native', null)->assertJsonPath('data.enabled', false);

    configureFirebase(enabled: true, withKey: false);
    app(Settings::class)->set('push.firebase.service_account', null, secret: true);
    $this->getJson('/api/v1/push-config?platform=android&app=customer')->assertJsonPath('data.native', null);
});

it('needs the platform key as well as the app ID', function () {
    configureFirebase();
    app(Settings::class)->set('push.firebase.app_id_android_customer', '1:123456789012:android:0a1b2c3d4e5f6a7b');

    $this->getJson('/api/v1/push-config?platform=android&app=customer')->assertJsonPath('data.native', null);
});

it('takes the sender ID from the app ID when the web sender ID was never filled in', function () {
    $s = app(Settings::class);
    $s->set('push.firebase.project_id', 'al-bahar-home');
    $s->set('push.firebase.service_account', fakeServiceAccountJson(), secret: true);
    $s->set('push.enabled', true);
    $s->set('push.firebase.api_key_android', ANDROID_KEY);
    $s->set('push.firebase.app_id_android_staff', '1:987654321098:android:aabbccdd11223344');

    $this->getJson('/api/v1/push-config?platform=android&app=staff')->assertJsonPath('data.native.messagingSenderId', '987654321098')
        ->assertJsonPath('data.web', null);
});

describe('the dashboard page', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->superAdmin()->create());
    });

    function phoneForm(array $over = []): array
    {
        return array_merge(firebaseFormValues(), [
            'apiKeyAndroid' => ANDROID_KEY, 'apiKeyIos' => IOS_KEY,
            'appIdAndroidCustomer' => '1:123456789012:android:0a1b2c3d4e5f6a7b8c9d0e', 'appIdAndroidDriver' => '1:123456789012:android:1111111111111111',
            'appIdAndroidStaff' => '1:123456789012:android:2222222222222222', 'appIdIosCustomer' => '1:123456789012:ios:0a1b2c3d4e5f6a7b8c9d0e',
            'serviceAccountJson' => fakeServiceAccountJson(),
        ], $over);
    }

    function fillPhone($component, array $values)
    {
        foreach ($values as $prop => $value) {
            $component->set($prop, $value);
        }

        return $component;
    }

    it('saves the phone app settings, and the apps can read them straight away', function () {
        fillPhone(Livewire::test(PushNotificationsPage::class), phoneForm())->call('save')->assertHasNoErrors();

        $s = app(Settings::class);
        expect($s->get('push.firebase.api_key_android'))->toBe(ANDROID_KEY)
            ->and($s->get('push.firebase.api_key_ios'))->toBe(IOS_KEY)
            ->and($s->get('push.firebase.app_id_android_driver'))->toBe('1:123456789012:android:1111111111111111')
            ->and($s->get('push.firebase.app_id_ios_customer'))->toBe('1:123456789012:ios:0a1b2c3d4e5f6a7b8c9d0e');
        $s->set('push.enabled', true);
        $this->getJson('/api/v1/push-config?platform=android&app=staff')->assertJsonPath('data.native.appId', '1:123456789012:android:2222222222222222');
    });

    it('shows how many apps are ready on the checklist', function () {
        fillPhone(Livewire::test(PushNotificationsPage::class), phoneForm())->call('save')
            ->assertSee(__('push_admin.status.ok_android', ['ready' => 3, 'total' => 3]))->assertSee(__('push_admin.status.ok_ios'));
    });

    it('names the package of every app so the right ones are registered in Firebase', function () {
        $page = Livewire::test(PushNotificationsPage::class);
        foreach (['qa.albahar.customer', 'qa.albahar.driver', 'qa.albahar.staff'] as $package) {
            $page->assertSee($package);
        }
    });

    it('checks the format of every phone field and says what is wrong', function (string $prop, string $bad, string $messageKey) {
        Livewire::test(PushNotificationsPage::class)->set('projectId', 'al-bahar-home')->set($prop, $bad)
            ->assertHasErrors([$prop])->assertSee(__('push_admin.err.'.$messageKey));
    })->with([
        'android key' => ['apiKeyAndroid', 'not-a-key', 'api_key'],
        'iphone key' => ['apiKeyIos', 'AIza-short', 'api_key'],
        'android app id with a web id' => ['appIdAndroidCustomer', '1:123456789012:web:0a1b2c3d4e5f6a7b', 'app_id_android'],
        'android app id with an ios id' => ['appIdAndroidDriver', '1:123456789012:ios:0a1b2c3d4e5f6a7b', 'app_id_android'],
        'iphone app id with an android id' => ['appIdIosCustomer', '1:123456789012:android:0a1b2c3d4e5f6a7b', 'app_id_ios'],
        'staff app id with junk' => ['appIdAndroidStaff', 'hello', 'app_id_android'],
    ]);

    it('needs the platform key together with an app ID, and the project ID with both', function () {
        fillPhone(Livewire::test(PushNotificationsPage::class), ['appIdAndroidCustomer' => '1:123456789012:android:0a1b2c3d4e5f6a7b'])
            ->call('save')->assertHasErrors(['apiKeyAndroid', 'projectId']);

        fillPhone(Livewire::test(PushNotificationsPage::class), phoneForm(['apiKeyIos' => '']))->call('save')->assertHasErrors(['apiKeyIos']);
    });

    it('refuses an app ID that belongs to another Firebase project', function () {
        fillPhone(Livewire::test(PushNotificationsPage::class), phoneForm(['appIdAndroidDriver' => '1:999999999999:android:1111111111111111']))
            ->call('save')->assertHasErrors(['appIdAndroidDriver'])->assertSee(__('push_admin.err.number_mismatch'));
        expect(app(Settings::class)->get('push.firebase.app_id_android_driver'))->toBeNull();
    });

    it('accepts a Firebase setup that has phone apps only, with no web app', function () {
        fillPhone(Livewire::test(PushNotificationsPage::class), [
            'projectId' => 'al-bahar-home', 'apiKeyAndroid' => ANDROID_KEY, 'appIdAndroidCustomer' => '1:123456789012:android:0a1b2c3d4e5f6a7b8c9d0e',
            'serviceAccountJson' => fakeServiceAccountJson(),
        ])->call('save')->assertHasNoErrors();

        app(Settings::class)->set('push.enabled', true);
        $this->getJson('/api/v1/push-config?platform=android&app=customer')->assertJsonPath('data.native.messagingSenderId', '123456789012');
    });

    it('never leaves a secret in the audit log when the phone settings are saved', function () {
        fillPhone(Livewire::test(PushNotificationsPage::class), phoneForm())->call('save');

        expect(json_encode(AuditLog::all()->toArray()))->not->toContain('PRIVATE KEY');
    });
});

it('lists exactly the phone apps that exist: three Android apps and the iPhone customer app', function () {
    expect(FirebaseConfig::NATIVE_APPS)->toBe(['android' => ['customer', 'driver', 'staff'], 'ios' => ['customer']])
        ->and(FirebaseConfig::PACKAGES)->toBe(['customer' => 'qa.albahar.customer', 'driver' => 'qa.albahar.driver', 'staff' => 'qa.albahar.staff']);
});
