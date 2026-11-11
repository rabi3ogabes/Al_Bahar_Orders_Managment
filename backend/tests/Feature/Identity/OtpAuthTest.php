<?php

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\OtpException;
use App\Domain\Identity\Models\OtpVerification;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OtpService;
use App\Domain\Notifications\Channels\NullSmsSender;
use App\Domain\Notifications\Contracts\SmsSender;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['platform.otp.dev_echo' => true]); // testing env only: returns the code in the response
});

function requestCode(string $phone = '55123456', string $purpose = 'login'): string
{
    $res = test()->postJson('/api/v1/auth/otp/request', ['phone' => $phone, 'purpose' => $purpose])->assertOk();

    return $res->json('data.debug_code');
}

it('issues a hashed OTP and never stores or logs the plaintext code', function () {
    Log::spy();
    $code = requestCode('5512 3456');

    $otp = OtpVerification::firstOrFail();
    expect($otp->phone)->toBe('+97455123456')
        ->and($otp->code_hash)->not->toContain($code)
        ->and(strlen($otp->code_hash))->toBe(64);

    Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => ! str_contains(json_encode($ctx), $code));
});

it('rejects non-Qatar or malformed numbers with a localized validation error', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '12345'], ['Accept-Language' => 'ar'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.phone.0', 'يرجى إدخال رقم جوال قطري صحيح.')
        ->assertJsonStructure(['message', 'code', 'errors', 'request_id']);
});

it('signs up a new number through a registration token, then logs in existing customers directly', function () {
    $code = requestCode();
    $res = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+97455123456', 'code' => $code])->assertOk();
    expect($res->json('data.status'))->toBe('registration_required');

    $this->postJson('/api/v1/auth/register', [
        'registration_token' => $res->json('data.registration_token'),
        'name' => 'Noor Al-Thani',
        'locale' => 'ar',
    ])->assertCreated()
        ->assertJsonPath('data.user.phone', '+97455123456')
        ->assertJsonPath('data.user.phone_verified', true)
        ->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token', 'expires_in']]]);

    $this->travel(2)->minutes();
    $code = requestCode();
    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '55123456', 'code' => $code])
        ->assertOk()->assertJsonPath('data.status', 'authenticated');
});

it('rejects an expired OTP', function () {
    $code = requestCode();
    $this->travel(config('platform.otp.ttl_seconds') + 1)->seconds();

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '55123456', 'code' => $code])
        ->assertStatus(422)->assertJsonPath('code', 'otp_expired');
});

it('does not allow an OTP to be reused', function () {
    User::factory()->create(['phone' => '+97455123456']);
    $code = requestCode();
    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '55123456', 'code' => $code])->assertOk();
    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '55123456', 'code' => $code])
        ->assertStatus(422)->assertJsonPath('code', 'otp_invalid');
});

it('locks the OTP after the maximum number of wrong attempts (attempts persist across requests)', function () {
    $code = requestCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    $max = config('platform.otp.max_attempts');

    foreach (range(1, $max - 1) as $i) {
        app(RateLimiter::class)->clear('login:+97455123456|127.0.0.1');
        expect(fn () => app(OtpService::class)->verify('+97455123456', OtpPurpose::Login, $wrong))->toThrow(OtpException::class);
    }
    expect(OtpVerification::first()->attempts)->toBe($max - 1);

    expect(fn () => app(OtpService::class)->verify('+97455123456', OtpPurpose::Login, $wrong))
        ->toThrow(fn (OtpException $e) => expect($e->errorCode)->toBe('otp_attempts_exceeded'));
    // Even the correct code is now refused.
    expect(fn () => app(OtpService::class)->verify('+97455123456', OtpPurpose::Login, $code))->toThrow(OtpException::class);
});

it('enforces a resend cooldown and invalidates the previous code when a new one is issued', function () {
    $first = requestCode();
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '55123456'])->assertStatus(429)->assertJsonPath('code', 'otp_cooldown');

    $this->travel(61)->seconds();
    app(RateLimiter::class)->clear(md5('otp'.'otp-ip:127.0.0.1'));
    $second = requestCode();
    expect(OtpVerification::whereNotNull('invalidated_at')->count())->toBe(1);
    if ($first !== $second) {
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '55123456', 'code' => $first])->assertStatus(422);
    }
});

it('never echoes the OTP when dev echo is off', function () {
    config(['platform.otp.dev_echo' => false]);
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '55123456'])->assertOk()->assertJsonMissingPath('data.debug_code');
});

it('responds identically to password-reset requests for unknown numbers (no enumeration)', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '55999999', 'purpose' => 'password_reset'])
        ->assertOk()->assertJsonPath('data.sent', true);
    expect(OtpVerification::count())->toBe(0);
});

it('uses the fixed test code (and shows it) while OTP_TEST_CODE is set and no SMS provider is connected', function () {
    config(['platform.otp.dev_echo' => false, 'platform.otp.test_code' => '123456', 'services.sms.driver' => 'null']);
    $res = $this->postJson('/api/v1/auth/otp/request', ['phone' => '55123456', 'purpose' => 'login'])->assertOk();
    expect($res->json('data.debug_code'))->toBe('123456');
    app(OtpService::class)->verify('+97455123456', OtpPurpose::Login, '123456');
    expect(OtpVerification::firstOrFail()->consumed_at)->not->toBeNull();
});

it('ignores the test code once a real SMS provider is configured', function () {
    config(['platform.otp.dev_echo' => false, 'platform.otp.test_code' => '123456', 'services.sms.driver' => 'http']);
    app()->bind(SmsSender::class, fn () => new NullSmsSender);
    $res = $this->postJson('/api/v1/auth/otp/request', ['phone' => '55123456', 'purpose' => 'login'])->assertOk();
    expect($res->json('data.debug_code'))->toBeNull();
});
