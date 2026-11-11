<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\OtpException;
use App\Domain\Identity\Models\OtpVerification;
use App\Domain\Notifications\Contracts\SmsSender;
use Illuminate\Support\Facades\DB;

/**
 * OTP lifecycle: issue → verify (single use, short TTL, attempt-limited) → consume.
 * Codes are HMAC-SHA256 hashed with the app key; plaintext codes are never stored or logged.
 */
class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /** @return array{otp: OtpVerification, debug_code: ?string} */
    public function issue(string $phone, OtpPurpose $purpose, ?string $ip = null, ?string $userAgent = null): array
    {
        $cfg = config('platform.otp');

        $latest = OtpVerification::where('phone', $phone)->where('purpose', $purpose)->latest()->first();
        if ($latest && $latest->created_at->addSeconds($cfg['resend_cooldown_seconds'])->isFuture()) {
            throw OtpException::make('otp_cooldown', 429, ['seconds' => (int) now()->diffInSeconds($latest->created_at->addSeconds($cfg['resend_cooldown_seconds']))]);
        }
        $recent = OtpVerification::where('phone', $phone)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= $cfg['max_per_phone_per_hour']) {
            throw OtpException::make('otp_hourly_limit', 429);
        }

        $testMode = $this->testModeCode();
        $code = $testMode ?? $this->generateCode($cfg['length']);

        $otp = DB::transaction(function () use ($phone, $purpose, $code, $cfg, $ip, $userAgent) {
            // Any earlier unused code for this phone+purpose becomes invalid.
            OtpVerification::where('phone', $phone)->where('purpose', $purpose)
                ->whereNull('consumed_at')->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            return OtpVerification::create([
                'phone' => $phone,
                'purpose' => $purpose,
                'code_hash' => $this->hash($phone, $purpose, $code),
                'max_attempts' => $cfg['max_attempts'],
                'expires_at' => now()->addSeconds($cfg['ttl_seconds']),
                'ip' => $ip,
                'user_agent' => $userAgent ? substr($userAgent, 0, 512) : null,
            ]);
        });

        $this->sms->send($phone, __('auth.otp_sms', ['code' => $code, 'minutes' => (int) ceil($cfg['ttl_seconds'] / 60)]), 'otp');

        return ['otp' => $otp, 'debug_code' => ($testMode !== null || $this->devEchoEnabled()) ? $code : null];
    }

    /**
     * Verify and consume. Locks the row so concurrent guesses are serialised and counted.
     *
     * @throws OtpException
     */
    public function verify(string $phone, OtpPurpose $purpose, string $code): OtpVerification
    {
        // The transaction must COMMIT the attempt counter even when the code is wrong, so the outcome
        // is returned from the closure and the exception is thrown only after commit.
        [$otp, $error, $status] = DB::transaction(function () use ($phone, $purpose, $code) {
            /** @var OtpVerification|null $otp */
            $otp = OtpVerification::where('phone', $phone)->where('purpose', $purpose)
                ->whereNull('invalidated_at')->latest()->lockForUpdate()->first();

            if (! $otp || $otp->consumed_at !== null) {
                return [null, 'otp_invalid', 422];
            }
            if ($otp->expires_at->isPast()) {
                return [null, 'otp_expired', 422];
            }
            if ($otp->attempts >= $otp->max_attempts) {
                return [null, 'otp_attempts_exceeded', 429];
            }

            $otp->increment('attempts');

            if (! hash_equals($otp->code_hash, $this->hash($phone, $purpose, $code))) {
                if ($otp->attempts >= $otp->max_attempts) {
                    $otp->update(['invalidated_at' => now()]);

                    return [null, 'otp_attempts_exceeded', 429];
                }

                return [null, 'otp_invalid', 422];
            }

            $otp->update(['consumed_at' => now()]);

            return [$otp, null, 200];
        });

        if ($error !== null) {
            throw OtpException::make($error, $status);
        }

        return $otp;
    }

    private function hash(string $phone, OtpPurpose $purpose, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$purpose->value.'|'.$code, (string) config('app.key'));
    }

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /** The fixed test code, only while no real SMS provider is configured. */
    private function testModeCode(): ?string
    {
        $code = (string) config('platform.otp.test_code');

        return ($code !== '' && config('services.sms.driver') !== 'http') ? $code : null;
    }

    private function devEchoEnabled(): bool
    {
        return config('platform.otp.dev_echo') === true && app()->environment(['local', 'testing']);
    }
}
