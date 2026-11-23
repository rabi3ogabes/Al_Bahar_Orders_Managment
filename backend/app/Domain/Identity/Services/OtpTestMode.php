<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\MessageProviderEvent;
use App\Domain\Notifications\Providers\MessagingProviders;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;

/**
 * The fixed test sign-in code (OTP_TEST_CODE). While it is on, anyone can sign in as any customer with it, so it is a temporary help for the
 * days before a real SMS provider exists. It stays on until the OWNER turns it off in the dashboard: after a provider is set up and a test
 * message was received on a real phone, with a fresh two-factor code. Once off, the server ignores OTP_TEST_CODE even if it is still in the
 * environment. (Today's behaviour is kept: on while OTP_TEST_CODE is set, no SMS_DRIVER=http, and nobody switched it off.)
 */
class OtpTestMode
{
    public const OFF = 'otp.test_codes_disabled';

    public function __construct(private readonly Settings $settings, private readonly MessagingProviders $providers, private readonly FreshTwoFactor $fresh) {}

    public function envCode(): string
    {
        return (string) config('platform.otp.test_code');
    }

    public function switchedOff(): bool
    {
        return (bool) $this->settings->get(self::OFF, false);
    }

    /** The code the server uses for every sign-in, or null when sign-in codes are real. */
    public function code(): ?string
    {
        return $this->active() ? $this->envCode() : null;
    }

    public function active(): bool
    {
        return $this->envCode() !== '' && config('services.sms.driver') !== 'http' && ! $this->switchedOff();
    }

    /** A provider is set up and a test message sent after the last change was marked "received" by an admin. */
    public function providerProven(): bool
    {
        if (! $this->providers->configured('sms')) {
            return false;
        }
        $since = $this->providers->configuredAt();

        return MessageProviderEvent::query()->where('purpose', 'test')->where('channel', 'sms')->where('provider', $this->providers->chosen('sms'))
            ->whereNotNull('confirmed_at')->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))->exists();
    }

    public function canTurnOff(): bool
    {
        return $this->active() && $this->providerProven();
    }

    public function turnOff(User $by): void
    {
        $this->guard($by);
        if (! $this->providerProven()) {
            throw DomainException::make('test_codes_need_proof', 422);
        }
        $this->set(true, $by);
    }

    /** Emergency: if real codes do not arrive, the owner can switch the test code back on (needs OTP_TEST_CODE in the environment). */
    public function turnOn(User $by): void
    {
        $this->guard($by);
        $this->set(false, $by);
    }

    private function guard(User $by): void
    {
        if (! $by->isSuperAdmin()) {
            throw DomainException::make('security_owner_only', 403);
        }
        if (! $this->fresh->isFresh()) {
            throw DomainException::make('security_two_factor_required', 403);
        }
    }

    private function set(bool $off, User $by): void
    {
        $old = $this->switchedOff();
        $this->settings->set(self::OFF, $off);
        app(AuditLogger::class)->log($off ? 'security.test_codes_off' : 'security.test_codes_on', 'settings', ['off' => $old], ['off' => $off], ['key' => self::OFF], actor: $by);
    }
}
