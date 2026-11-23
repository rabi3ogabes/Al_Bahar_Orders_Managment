<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Prove it is really you, right now": a two-factor code typed within the last 5 minutes. Sensitive actions (switching off a security
 * setting, approving your own refund, turning off test sign-in codes) ask for it. The proof lives in the browser session; the staff
 * API, which has no session, sends the code with the request instead (verifyCode).
 */
class FreshTwoFactor
{
    public const WINDOW_SECONDS = 300;

    private const SESSION_KEY = 'two_factor_confirmed_at';

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function stamp(): void
    {
        session([self::SESSION_KEY => time()]);
    }

    public function isFresh(): bool
    {
        $at = session(self::SESSION_KEY);

        return is_int($at) && $at >= time() - self::WINDOW_SECONDS && $at <= time() + 5;
    }

    public function canConfirm(User $user): bool
    {
        return $user->hasTwoFactorEnabled();
    }

    /** Checks a code (five tries per five minutes, replay-protected) without touching the session. */
    public function verifyCode(User $user, string $code): bool
    {
        $key = '2fa-fresh:'.$user->getKey();
        if (! $user->hasTwoFactorEnabled() || RateLimiter::tooManyAttempts($key, 5)) {
            return false;
        }
        if (! $this->twoFactor->verify($user, $code)) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);

            return false;
        }
        RateLimiter::clear($key);

        return true;
    }

    /** Checks the code and, when right, makes this browser session "fresh" for five minutes. */
    public function confirm(User $user, string $code): bool
    {
        if (! $this->verifyCode($user, $code)) {
            return false;
        }
        $this->stamp();

        return true;
    }
}
