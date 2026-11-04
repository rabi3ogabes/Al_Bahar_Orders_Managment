<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserSession;

it('logs in with password, records the login and returns rotating tokens', function () {
    $user = User::factory()->create(['phone' => '+97466112233', 'password' => 'Secret123']);

    $res = $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'Secret123', 'device_name' => 'iPhone'])
        ->assertOk()->assertJsonPath('data.user.id', $user->id);

    expect(AuditLog::where('action', 'auth.login')->where('actor_id', $user->id)->exists())->toBeTrue();

    $this->withToken($res->json('data.tokens.access_token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('records failed logins and returns a generic error', function () {
    User::factory()->create(['phone' => '+97466112233', 'password' => 'Secret123']);

    $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'wrong'])
        ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    expect(AuditLog::where('action', 'auth.login_failed')->count())->toBe(1);
});

it('rotates refresh tokens and revokes the whole family on reuse', function () {
    User::factory()->create(['phone' => '+97466112233', 'password' => 'Secret123']);
    $first = $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'Secret123'])->json('data.tokens');

    $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertOk()->json('data.tokens');
    expect($second['refresh_token'])->not->toBe($first['refresh_token']);

    // Replaying the rotated token is treated as theft: rejected AND the new session is revoked too.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertStatus(401);
    expect(UserSession::whereNull('revoked_at')->count())->toBe(0);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])->assertStatus(401);
});

it('logs out by revoking the access token and session', function () {
    User::factory()->create(['phone' => '+97466112233', 'password' => 'Secret123']);
    $tokens = $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'Secret123'])->json('data.tokens');

    $this->withToken($tokens['access_token'])->postJson('/api/v1/auth/logout')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
});

it('resets the password with an OTP and revokes existing sessions', function () {
    config(['platform.otp.dev_echo' => true]);
    User::factory()->create(['phone' => '+97466112233', 'password' => 'OldSecret1']);
    $tokens = $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'OldSecret1'])->json('data.tokens');

    $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '66112233', 'purpose' => 'password_reset'])->json('data.debug_code');
    $this->postJson('/api/v1/auth/password/reset', ['phone' => '66112233', 'code' => $code, 'password' => 'NewSecret9', 'password_confirmation' => 'NewSecret9'])->assertOk();

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'NewSecret9'])->assertOk();
});

it('blocks suspended accounts', function () {
    User::factory()->suspended()->create(['phone' => '+97466112233', 'password' => 'Secret123']);
    $this->postJson('/api/v1/auth/login', ['phone' => '66112233', 'password' => 'Secret123'])->assertStatus(403)->assertJsonPath('code', 'account_inactive');
});

it('keeps customers and staff on separate login channels', function () {
    User::factory()->staff()->create(['email' => 'ops@albahar.qa', 'password' => 'Secret123']);
    User::factory()->create(['email' => 'cust@example.com', 'password' => 'Secret123']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ops@albahar.qa', 'password' => 'Secret123'])->assertStatus(401);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'cust@example.com', 'password' => 'Secret123'])->assertStatus(401);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ops@albahar.qa', 'password' => 'Secret123'])->assertOk();
});

it('prevents a customer token from reaching staff endpoints', function () {
    actingAsCustomer();
    $this->getJson('/api/v1/admin/me')->assertStatus(403)->assertJsonPath('code', 'forbidden');
});

it('registers push devices and moves a token between accounts', function () {
    $a = actingAsCustomer();
    $this->postJson('/api/v1/me/devices', ['platform' => 'ios', 'push_token' => 'tok-1'])->assertCreated();
    $b = actingAsCustomer();
    $this->postJson('/api/v1/me/devices', ['platform' => 'ios', 'push_token' => 'tok-1'])->assertCreated();

    expect(\App\Domain\Identity\Models\UserDevice::where('push_token', 'tok-1')->value('user_id'))->toBe($b->id);
});
