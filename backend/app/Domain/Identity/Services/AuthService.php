<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Events\CustomerRegistered;
use App\Domain\Identity\Models\User;
use App\Support\DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    private const REGISTRATION_TOKEN_TTL = 900; // seconds

    public function __construct(
        private readonly OtpService $otp,
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Verify a login OTP. Existing customers receive tokens; new numbers receive a short-lived
     * registration token to complete their profile (one seamless sign-in/sign-up flow).
     */
    public function verifyLoginOtp(string $phone, string $code, array $context): array
    {
        $otp = $this->otp->verify($phone, OtpPurpose::Login, $code);
        $user = User::where('phone', $phone)->first();

        if ($user) {
            if ($user->isStaff()) {
                // Staff never sign in through the customer channel.
                throw DomainException::make('invalid_credentials', 401);
            }
            $this->assertActive($user);
            $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? now()])->save();

            return ['status' => 'authenticated', 'user' => $user, 'tokens' => $this->login($user, $context, 'otp')];
        }

        return [
            'status' => 'registration_required',
            'registration_token' => Crypt::encryptString(json_encode([
                'phone' => $phone, 'otp' => $otp->id, 'exp' => time() + self::REGISTRATION_TOKEN_TTL,
            ])),
            'expires_in' => self::REGISTRATION_TOKEN_TTL,
        ];
    }

    public function register(array $data, array $context): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($data['registration_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw DomainException::make('registration_token_invalid', 401);
        }
        if (($payload['exp'] ?? 0) < time()) {
            throw DomainException::make('registration_token_invalid', 401);
        }

        $user = DB::transaction(function () use ($payload, $data) {
            if (User::withTrashed()->where('phone', $payload['phone'])->lockForUpdate()->exists()) {
                throw DomainException::make('phone_taken', 409);
            }

            return User::create([
                'type' => UserType::Customer,
                'status' => UserStatus::Active,
                'name' => trim($data['name']),
                'phone' => $payload['phone'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'] ?? null,
                'phone_verified_at' => now(),
                'locale' => $data['locale'] ?? app()->getLocale(),
                'referral_code' => $this->uniqueReferralCode(),
                'marketing_consent_at' => ! empty($data['marketing_consent']) ? now() : null,
            ]);
        });

        $this->audit->log('auth.registered', $user, [], ['phone' => $user->phone], actor: $user);
        CustomerRegistered::dispatch($user, $data['referral_code'] ?? null, $context['device_id'] ?? null);

        return ['user' => $user, 'tokens' => $this->login($user, $context, 'registration')];
    }

    public function passwordLogin(array $data, array $context, ?UserType $expectedType = UserType::Customer): array
    {
        $query = User::query();
        if (! empty($data['phone'])) {
            $query->where('phone', \App\Support\Phone::normalize($data['phone']) ?? '__invalid__');
        } else {
            $query->whereRaw('lower(email) = ?', [strtolower($data['email'])]);
        }
        $user = $query->first();

        // Constant-ish time: always perform a hash check.
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$'.str_repeat('a', 53));

        if (! $user || ! $valid || $user->password === null || ($expectedType && $user->type !== $expectedType)) {
            $this->audit->log('auth.login_failed', 'User', [], [], ['identifier' => $data['phone'] ?? $data['email'] ?? null, 'user_id' => $user?->id, 'channel' => $expectedType?->value]);
            throw DomainException::make('invalid_credentials', 401);
        }
        $this->assertActive($user);

        if ($user->isStaff() && $user->hasTwoFactorEnabled()) {
            if (empty($data['two_factor_code'])) {
                throw DomainException::make('two_factor_required', 401);
            }
            if (! $this->twoFactor->verify($user, $data['two_factor_code'])) {
                $this->audit->log('auth.two_factor_failed', $user, actor: $user);
                throw DomainException::make('two_factor_invalid', 401);
            }
        }

        return ['user' => $user, 'tokens' => $this->login($user, $context, 'password')];
    }

    public function resetPassword(string $phone, string $code, string $password): void
    {
        $this->otp->verify($phone, OtpPurpose::PasswordReset, $code);
        $user = User::where('phone', $phone)->first();
        if (! $user) {
            throw DomainException::make('otp_invalid');
        }
        $user->forceFill(['password' => $password])->save();
        $this->tokens->revokeAll($user, 'password_reset');
        $this->audit->log('auth.password_reset', $user, actor: $user);
    }

    private function login(User $user, array $context, string $method): array
    {
        $tokens = $this->tokens->issue($user, $context['device_name'] ?? null, $context['platform'] ?? null, $context['ip'] ?? null, $context['user_agent'] ?? null);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $context['ip'] ?? null])->save();
        $this->audit->log('auth.login', $user, [], [], ['method' => $method, 'platform' => $context['platform'] ?? null], actor: $user);

        return $tokens;
    }

    private function assertActive(User $user): void
    {
        if (! $user->isActive()) {
            throw DomainException::make('account_inactive', 403);
        }
    }

    private function uniqueReferralCode(): string
    {
        do {
            $code = 'AB'.Str::upper(Str::random(6));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }
}
