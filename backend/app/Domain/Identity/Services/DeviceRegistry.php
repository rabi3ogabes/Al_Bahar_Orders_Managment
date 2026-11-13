<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Support\BrowserFamily;

/**
 * Stores push tokens. A token belongs to exactly one person and one browser or phone: registering it again refreshes the row, and
 * registering it as another user moves it (a shared browser or phone must never keep sending the previous person's notifications).
 */
class DeviceRegistry
{
    /** @param array{platform: string, push_token: string, device_id?: ?string, app_version?: ?string, locale?: ?string, app?: ?string} $data */
    public function register(User $user, array $data, ?string $userAgent = null): UserDevice
    {
        $web = $data['platform'] === 'web';

        return UserDevice::updateOrCreate(['push_token' => $data['push_token']], [
            'user_id' => $user->id,
            'platform' => $data['platform'],
            'app' => $web ? ($data['app'] ?? null) : null,
            'device_id' => $data['device_id'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'browser' => $web ? BrowserFamily::from($userAgent) : null,
            'locale' => $data['locale'] ?? app()->getLocale(),
            'last_seen_at' => now(),
            'disabled_at' => null,
        ]);
    }

    /** Turns off one of the user's own tokens (a token of somebody else is never touched). */
    public function disable(User $user, string $pushToken): int
    {
        return UserDevice::where('user_id', $user->id)->where('push_token', $pushToken)->whereNull('disabled_at')->update(['disabled_at' => now()]);
    }
}
