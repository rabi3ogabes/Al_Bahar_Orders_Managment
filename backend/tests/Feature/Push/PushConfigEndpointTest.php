<?php

use App\Domain\Settings\Services\Settings;
use App\Support\FirebaseWorkerScript;

require_once __DIR__.'/PushSupport.php';

it('says push is off before anything is set up', function () {
    $this->getJson('/api/v1/push-config')->assertOk()->assertExactJson(['data' => ['enabled' => false, 'web' => null]]);
});

it('hands browsers the public web settings once push is on, and nothing that is secret', function () {
    configureFirebase();
    $res = $this->getJson('/api/v1/push-config')->assertOk();

    $res->assertJsonPath('data.enabled', true)->assertExactJson(['data' => ['enabled' => true, 'web' => [
        'apiKey' => 'AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q', 'authDomain' => 'al-bahar-home.firebaseapp.com', 'projectId' => 'al-bahar-home',
        'messagingSenderId' => '123456789012', 'appId' => '1:123456789012:web:0a1b2c3d4e5f6a7b8c9d0e', 'vapidKey' => firebaseFormValues()['vapidKey'],
    ]]]);
    expect($res->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and($res->getContent())->not->toContain('PRIVATE KEY')->not->toContain('client_email')->not->toContain('firebase-adminsdk')->not->toContain('private_key');
});

it('returns nothing while the master switch is off, or the server key is missing', function () {
    configureFirebase(enabled: false);
    $this->getJson('/api/v1/push-config')->assertExactJson(['data' => ['enabled' => false, 'web' => null]]);

    configureFirebase(enabled: true, withKey: false);
    app(Settings::class)->set('push.firebase.service_account', null, secret: true);
    $this->getJson('/api/v1/push-config')->assertExactJson(['data' => ['enabled' => false, 'web' => null]]);
});

it('needs no sign-in and sends no cookies', function () {
    configureFirebase();
    $res = $this->get('/api/v1/push-config')->assertOk();

    expect($res->headers->getCookies())->toBeEmpty();
});

dataset('worker urls', ['/firebase-messaging-sw.js', '/mobile-web/firebase-messaging-sw.js']);

it('serves the service worker as JavaScript with the saved public settings', function (string $url) {
    configureFirebase();
    $res = $this->get($url)->assertOk();
    $js = $res->getContent();

    expect($res->headers->get('Content-Type'))->toStartWith('text/javascript')
        ->and($res->headers->get('Service-Worker-Allowed'))->toBe('/')
        ->and($res->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->getCookies())->toBeEmpty()
        ->and($js)->toContain('importScripts(')->toContain('/firebasejs/')->toContain('firebase.initializeApp(')
        ->toContain('AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q')->toContain(FirebaseWorkerScript::SDK_VERSION)
        ->not->toContain('PRIVATE KEY')->not->toContain('client_email')->not->toContain(firebaseFormValues()['vapidKey']); // the VAPID key is for the page, not the worker
})->with('worker urls');

it('is never swallowed by the storefront catch-all', function () {
    $this->get('/firebase-messaging-sw.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=UTF-8');
    expect($this->get('/firebase-messaging-sw.js')->getContent())->not->toContain('<html');
});

it('serves a harmless worker that does nothing while push is off or not set up', function (bool $configured) {
    if ($configured) {
        configureFirebase(enabled: false);
    }
    $js = $this->get('/firebase-messaging-sw.js')->assertOk()->getContent();

    expect($js)->not->toContain('importScripts')->not->toContain('initializeApp')->toContain('skipWaiting');
})->with([[false], [true]]);

it('writes the settings into the worker as one escaped JSON value, so no setting can break out of the script', function () {
    configureFirebase();
    $hostile = "\"};alert(1);var x={\"a\":\"</script><img src=x onerror=alert(2)>\u{2028}'x";
    app(Settings::class)->set('push.firebase.app_id_web', $hostile);
    app(Settings::class)->set('brand.name_en', "Evil'; </script>");

    $js = $this->get('/firebase-messaging-sw.js')->assertOk()->getContent();

    expect($js)->not->toContain('</script>')->not->toContain('"};alert(1)')->not->toContain("\u{2028}")->not->toContain('<img')->not->toContain("Evil'");
    preg_match('/var SETTINGS = (\{.*\});\n/', $js, $m);
    $settings = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    expect($settings['firebase']['appId'])->toBe($hostile)->and($settings['brand'])->toBe("Evil'; </script>")->and($settings['sdk'])->toBe(FirebaseWorkerScript::SDK_VERSION);
});

it('ships a template whose placeholder is replaced exactly once', function () {
    $template = file_get_contents(resource_path('push/firebase-messaging-sw.js'));

    expect(substr_count($template, '/*@settings*/ {}'))->toBe(1);
});
