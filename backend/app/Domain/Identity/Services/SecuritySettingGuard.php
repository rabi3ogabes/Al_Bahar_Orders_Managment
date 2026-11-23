<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Services\WallDisplaySession;

/**
 * The settings that decide how safe the dashboard is. WEAKENING one (two-factor no longer required, self-approval of refunds allowed,
 * a wall display staying signed in longer) needs the owner (super admin) AND a two-factor code typed in the last 5 minutes; making it
 * stricter needs only the normal settings right. Every change to one of these is audit-logged with the old and the new value.
 * This list is the one place to add a new security setting.
 */
class SecuritySettingGuard
{
    public const SELF_APPROVAL = 'refunds.allow_self_approval';

    public const TWO_FACTOR = 'auth.staff_two_factor_required';

    public const WALL_HOURS = WallDisplaySession::HOURS_KEY;

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::TWO_FACTOR, self::SELF_APPROVAL, self::WALL_HOURS];
    }

    public static function isProtected(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public function weakens(string $key, mixed $old, mixed $new): bool
    {
        return match ($key) {
            self::TWO_FACTOR => (bool) $old && ! (bool) $new,
            self::SELF_APPROVAL => ! (bool) $old && (bool) $new,
            self::WALL_HOURS => is_numeric($new) && is_numeric($old) && (int) $new > (int) $old,
            default => false,
        };
    }

    public function mayWeaken(?User $user): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function record(string $key, mixed $old, mixed $new, ?User $actor = null): void
    {
        app(AuditLogger::class)->log('security.setting_changed', 'settings', ['value' => $old], ['value' => $new], ['key' => $key, 'weakened' => $this->weakens($key, $old, $new)], actor: $actor);
    }
}
