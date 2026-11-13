<?php

use App\Domain\Notifications\Services\FirebaseConfig;
use App\Domain\Settings\Models\SystemSetting;
use App\Domain\Settings\Services\Settings;

require_once __DIR__.'/PushSupport.php';

function envCredentialsFile(string $project = 'env-project'): string
{
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, fakeServiceAccountJson($project, 'env-sa@env-project.iam.gserviceaccount.com'));
    config(['services.fcm.project_id' => $project, 'services.fcm.credentials_path' => $path]);

    return $path;
}

it('is off and empty on a fresh install', function () {
    $c = app(FirebaseConfig::class);
    expect($c->enabled())->toBeFalse()->and($c->configured())->toBeFalse()->and($c->hasServerCredentials())->toBeFalse()
        ->and($c->webConfig())->toBeNull()->and($c->serviceAccount())->toBeNull()->and($c->serviceAccountSource())->toBeNull()
        ->and($c->publicConfig())->toBe(['enabled' => false, 'web' => null]);
});

it('builds the public web config from the dashboard values and defaults the auth domain from the project', function () {
    configureFirebase();
    $web = app(FirebaseConfig::class)->webConfig();

    expect($web)->toBe([
        'apiKey' => 'AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q', 'authDomain' => 'al-bahar-home.firebaseapp.com', 'projectId' => 'al-bahar-home',
        'messagingSenderId' => '123456789012', 'appId' => '1:123456789012:web:0a1b2c3d4e5f6a7b8c9d0e', 'vapidKey' => firebaseFormValues()['vapidKey'],
    ]);

    app(Settings::class)->set('push.firebase.auth_domain', 'login.al-bahar.example');
    expect(app(FirebaseConfig::class)->webConfig()['authDomain'])->toBe('login.al-bahar.example');
});

it('has no web config until every web field is saved', function () {
    configureFirebase();
    app(Settings::class)->set('push.firebase.vapid_key', null);
    $c = app(FirebaseConfig::class);

    expect($c->webConfig())->toBeNull()->and($c->status()['web'])->toBeFalse()->and($c->status()['vapid'])->toBeFalse()
        ->and($c->configured())->toBeTrue(); // the server can still send to the phone apps
});

it('seals the server key in the database and never exposes it through the status', function () {
    configureFirebase();
    $row = SystemSetting::where('group', 'push')->where('key', 'firebase.service_account')->firstOrFail();

    expect($row->is_secret)->toBeTrue()->and(json_encode($row->value))->not->toContain('PRIVATE KEY')->not->toContain('firebase-adminsdk')
        ->and(array_keys($row->value))->toBe(['encrypted']);
    $status = app(FirebaseConfig::class)->status();
    expect(json_encode($status))->not->toContain('PRIVATE KEY')->and($status['client_email'])->toBe('firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com');
});

it('keeps working with the old server variables and lets a dashboard value win', function () {
    $path = envCredentialsFile();
    $c = app(FirebaseConfig::class);
    // Old setup, dashboard untouched: push stays on exactly as before.
    expect($c->enabled())->toBeTrue()->and($c->configured())->toBeTrue()->and($c->projectId())->toBe('env-project')
        ->and($c->serviceAccountSource())->toBe('env')->and($c->serviceAccount()['client_email'])->toBe('env-sa@env-project.iam.gserviceaccount.com');

    configureFirebase();
    expect($c->projectId())->toBe('al-bahar-home')->and($c->serviceAccountSource())->toBe('dashboard')
        ->and($c->serviceAccount()['client_email'])->toBe('firebase-adminsdk-abc12@al-bahar-home.iam.gserviceaccount.com');

    // Removing the dashboard key falls back to the file again.
    app(Settings::class)->set('push.firebase.service_account', null, secret: true);
    expect($c->serviceAccountSource())->toBe('env');
    unlink($path);
});

it('is switched off by the master switch even when everything is configured', function () {
    configureFirebase(enabled: false);
    $c = app(FirebaseConfig::class);

    expect($c->hasServerCredentials())->toBeTrue()->and($c->configured())->toBeFalse()->and($c->publicConfig())->toBe(['enabled' => false, 'web' => null]);
    app(Settings::class)->set('push.enabled', true);
    expect($c->configured())->toBeTrue();
});

it('an explicit off beats the old server variables', function () {
    $path = envCredentialsFile();
    app(Settings::class)->set('push.enabled', false);

    expect(app(FirebaseConfig::class)->configured())->toBeFalse();
    unlink($path);
});

it('ignores a stored key that is not usable', function () {
    configureFirebase(withKey: false);
    app(Settings::class)->set('push.firebase.service_account', '{"type":"service_account"}', secret: true);

    expect(app(FirebaseConfig::class)->hasServerCredentials())->toBeFalse();
});
