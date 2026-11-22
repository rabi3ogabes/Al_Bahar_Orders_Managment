<?php

namespace App\Domain\Operations\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * How long a wall display account stays signed in (docs/guides/SEARCH_AND_LIVE_BOARD.md, "Wall display account").
 *
 * The screen signs in once and stays signed in for the shift: every request the board makes (its own refresh polling counts) moves the account's "last seen"
 * forward, and an account that has not been seen for the configured number of hours is signed out (Settings > Operations clock, default 16 hours). It also
 * signs out by the explicit "Sign out" button. The last-seen moment is kept in the cache under the account, not in the browser session, so it holds whatever
 * the session driver does; an empty cache (restarted cache, first request, a sign-in) counts as "just seen", never as expired, so a screen is never locked out by a flush.
 */
final class WallDisplaySession
{
    public const HOURS_KEY = 'operations.wall_session_hours';

    public const DEFAULT_HOURS = 16;

    public const MAX_HOURS = 72;

    public function __construct(private readonly Settings $settings) {}

    /** The idle time after which the account is signed out. A stored value that makes no sense falls back to the default. */
    public function hours(): int
    {
        $v = $this->settings->get(self::HOURS_KEY, self::DEFAULT_HOURS);

        return is_numeric($v) && (int) $v >= 1 && (int) $v <= self::MAX_HOURS ? (int) $v : self::DEFAULT_HOURS;
    }

    /** Called on every request of the account: true while the screen is still inside its time (and the moment is moved forward), false once it has run out. */
    public function touch(User $user): bool
    {
        $key = $this->key($user);
        $seen = Cache::get($key);
        $now = now()->getTimestamp();
        if (is_int($seen) && $now - $seen > $this->hours() * 3600) {
            Cache::forget($key);

            return false;
        }
        Cache::forever($key, $now); // never expires by itself: an account idle for longer than its time must be found out, not forgotten

        return true;
    }

    /** Signing out forgets the moment, so the next sign-in starts a new shift. */
    public function forget(User $user): void
    {
        Cache::forget($this->key($user));
    }

    private function key(User $user): string
    {
        return 'wall-display-seen:'.$user->id;
    }
}
