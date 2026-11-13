<?php

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use Illuminate\Support\Facades\RateLimiter;

const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

it('registers a web push token with its language, browser and the web app that asked', function () {
    $user = actingAsCustomer();
    $this->withHeader('User-Agent', CHROME_ANDROID)->postJson('/api/v1/me/devices', [
        'platform' => 'web', 'push_token' => 'web-token-aaaaaaaaaaaaaaaaaaaa', 'locale' => 'ar', 'app' => 'store', 'device_id' => 'dev-1', 'app_version' => '1.4.0',
    ])->assertCreated()->assertJsonPath('data.registered', true);

    $d = UserDevice::where('push_token', 'web-token-aaaaaaaaaaaaaaaaaaaa')->firstOrFail();
    expect($d->user_id)->toBe($user->id)->and($d->platform)->toBe('web')->and($d->app)->toBe('store')->and($d->browser)->toBe('Chrome on Android')
        ->and($d->locale)->toBe('ar')->and($d->device_id)->toBe('dev-1')->and($d->app_version)->toBe('1.4.0')->and($d->disabled_at)->toBeNull();
});

it('falls back to the request language and accepts the web version of the mobile app', function () {
    actingAsCustomer();
    $this->withHeader('User-Agent', SAFARI_IPHONE)->withHeader('Accept-Language', 'ar')
        ->postJson('/api/v1/me/devices', ['platform' => 'web', 'push_token' => 'mw-token-bbbbbbbbbbbbbbbbbbbbbb', 'app' => 'mobile-web'])->assertCreated();

    $d = UserDevice::where('push_token', 'mw-token-bbbbbbbbbbbbbbbbbbbbbb')->firstOrFail();
    expect($d->app)->toBe('mobile-web')->and($d->browser)->toBe('Safari on iPhone')->and($d->locale)->toBe('ar');
});

it('is idempotent: the same token again refreshes the row and switches a disabled device back on', function () {
    actingAsCustomer();
    $payload = ['platform' => 'web', 'push_token' => 'same-token-ccccccccccccccccccc', 'locale' => 'en'];
    $this->postJson('/api/v1/me/devices', $payload)->assertCreated();
    UserDevice::query()->update(['disabled_at' => now(), 'last_seen_at' => now()->subDays(3)]);

    $this->postJson('/api/v1/me/devices', [...$payload, 'locale' => 'ar'])->assertCreated();

    expect(UserDevice::count())->toBe(1);
    $d = UserDevice::first();
    expect($d->disabled_at)->toBeNull()->and($d->locale)->toBe('ar')->and($d->last_seen_at->isToday())->toBeTrue();
});

it('moves a token to the person who registers it now (shared browser or phone)', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $payload = ['platform' => 'web', 'push_token' => 'shared-token-dddddddddddddddd'];
    actingAsCustomer($a);
    $this->postJson('/api/v1/me/devices', $payload)->assertCreated();
    app('auth')->forgetGuards();
    actingAsCustomer($b);
    $this->postJson('/api/v1/me/devices', $payload)->assertCreated();

    expect(UserDevice::count())->toBe(1)->and(UserDevice::first()->user_id)->toBe($b->id);
});

it('never lets one person switch off another person’s device', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    UserDevice::create(['user_id' => $a->id, 'platform' => 'web', 'push_token' => 'owned-by-a-eeeeeeeeeeeeeeeeee']);
    actingAsCustomer($b);

    $this->deleteJson('/api/v1/me/devices', ['push_token' => 'owned-by-a-eeeeeeeeeeeeeeeeee'])->assertOk();

    expect(UserDevice::first()->disabled_at)->toBeNull();
});

it('switches off the caller’s own device on DELETE and a new registration turns it back on', function () {
    $user = actingAsCustomer();
    UserDevice::create(['user_id' => $user->id, 'platform' => 'web', 'push_token' => 'mine-ffffffffffffffffffffff']);

    $this->deleteJson('/api/v1/me/devices', ['push_token' => 'mine-ffffffffffffffffffffff'])->assertOk()->assertJsonPath('data.unregistered', true);
    expect(UserDevice::first()->disabled_at)->not->toBeNull();

    $this->postJson('/api/v1/me/devices', ['platform' => 'web', 'push_token' => 'mine-ffffffffffffffffffffff'])->assertCreated();
    expect(UserDevice::first()->disabled_at)->toBeNull();
});

it('rejects values the contract does not allow', function (array $payload) {
    actingAsCustomer();
    $this->postJson('/api/v1/me/devices', $payload)->assertUnprocessable();
    expect(UserDevice::count())->toBe(0);
})->with([
    'unknown platform' => [['platform' => 'desktop', 'push_token' => 'tok']],
    'no token' => [['platform' => 'web']],
    'token too long' => [['platform' => 'web', 'push_token' => str_repeat('x', 513)]],
    'unknown app' => [['platform' => 'web', 'push_token' => 'tok', 'app' => 'admin']],
    'unknown language' => [['platform' => 'web', 'push_token' => 'tok', 'locale' => 'fr']],
    'array token' => [['platform' => 'web', 'push_token' => ['a']]],
]);

it('needs a signed-in customer', function () {
    $this->postJson('/api/v1/me/devices', ['platform' => 'web', 'push_token' => 'tok'])->assertUnauthorized();
    $this->deleteJson('/api/v1/me/devices', ['push_token' => 'tok'])->assertUnauthorized();
});

it('rate limits registrations per person', function () {
    $user = actingAsCustomer();
    RateLimiter::clear('devices');
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/v1/me/devices', ['platform' => 'web', 'push_token' => "burst-token-{$i}-gggggggggggggg"])->assertCreated();
    }

    $this->postJson('/api/v1/me/devices', ['platform' => 'web', 'push_token' => 'burst-token-21-gggggggggggggg'])->assertStatus(429);
    expect(UserDevice::where('user_id', $user->id)->count())->toBe(20);
});

it('does not keep a browser family or web app for a phone app token', function () {
    actingAsCustomer();
    $this->withHeader('User-Agent', CHROME_ANDROID)->postJson('/api/v1/me/devices', ['platform' => 'android', 'push_token' => 'phone-token-hhhhhhhhhhhhhhhh', 'app' => 'store'])->assertCreated();

    $d = UserDevice::first();
    expect($d->app)->toBeNull()->and($d->browser)->toBeNull();
});

it('lets staff register a web token on the admin API too', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    actingAsStaff($driver);
    $this->postJson('/api/v1/admin/devices', ['platform' => 'web', 'push_token' => 'staff-web-iiiiiiiiiiiiiiiiiiii', 'locale' => 'ar'])->assertCreated();

    expect(UserDevice::where('push_token', 'staff-web-iiiiiiiiiiiiiiiiiiii')->value('user_id'))->toBe($driver->id);
});

it('switches off the device named on logout and every device on logout-all', function () {
    $user = User::factory()->create(['phone' => '+97466112233', 'password' => 'Secret123']);
    $login = fn () => $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'Secret123'])->json('data.tokens.access_token');
    foreach (['one-jjjjjjjjjjjjjjjjjjjjjj', 'two-kkkkkkkkkkkkkkkkkkkkkk'] as $t) {
        UserDevice::create(['user_id' => $user->id, 'platform' => 'web', 'push_token' => $t]);
    }
    $other = UserDevice::create(['user_id' => User::factory()->create()->id, 'platform' => 'web', 'push_token' => 'other-llllllllllllllllllllll']);

    $this->withToken($login())->postJson('/api/v1/auth/logout', ['push_token' => 'one-jjjjjjjjjjjjjjjjjjjjjj'])->assertOk();
    expect(UserDevice::where('push_token', 'one-jjjjjjjjjjjjjjjjjjjjjj')->value('disabled_at'))->not->toBeNull()
        ->and(UserDevice::where('push_token', 'two-kkkkkkkkkkkkkkkkkkkkkk')->value('disabled_at'))->toBeNull();

    app('auth')->forgetGuards();
    $this->withToken($login())->postJson('/api/v1/auth/logout-all')->assertOk();
    expect(UserDevice::where('user_id', $user->id)->whereNull('disabled_at')->count())->toBe(0)->and($other->fresh()->disabled_at)->toBeNull();
});

it('ignores a token of someone else sent with logout', function () {
    $user = User::factory()->create(['phone' => '+97466112233', 'password' => 'Secret123']);
    $victim = UserDevice::create(['user_id' => User::factory()->create()->id, 'platform' => 'web', 'push_token' => 'victim-mmmmmmmmmmmmmmmmmmmm']);
    $token = $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'Secret123'])->json('data.tokens.access_token');

    $this->withToken($token)->postJson('/api/v1/auth/logout', ['push_token' => 'victim-mmmmmmmmmmmmmmmmmmmm'])->assertOk();

    expect($victim->fresh()->disabled_at)->toBeNull()->and($user->id)->not->toBe($victim->user_id);
});
