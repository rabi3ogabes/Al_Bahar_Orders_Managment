<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Services\PushDiagnostics;
use App\Support\BrowserFamily;

/**
 * Stores push tokens. A token belongs to exactly one person and one browser or phone: registering it again refreshes the row, and
 * registering it as another user moves it (a shared browser or phone must never keep sending the previous person's notifications).
 */
class DeviceRegistry
{
    /**
     * `notification_permission` (granted | denied | not_asked) is what the phone says about showing notifications. It is stored with the time
     * it was said, on the same write as the token. A registration that does not carry it (an older app, a browser, an explicit null) leaves
     * the stored answer alone: not knowing is never turned into "off".
     *
     * @param  array{platform: string, push_token: string, device_id?: ?string, app_version?: ?string, locale?: ?string, app?: ?string, notification_permission?: ?string}  $data
     */
    public function register(User $user, array $data, ?string $userAgent = null): UserDevice
    {
        $web = $data['platform'] === 'web';
        $permission = $data['notification_permission'] ?? null;

        $device = UserDevice::updateOrCreate(['push_token' => $data['push_token']], [
            'user_id' => $user->id,
            'platform' => $data['platform'],
            'app' => $web ? ($data['app'] ?? null) : $this->phoneApp(),
            'device_id' => $data['device_id'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'browser' => $web ? BrowserFamily::from($userAgent) : null,
            'locale' => $data['locale'] ?? app()->getLocale(),
            'last_seen_at' => now(),
            'disabled_at' => null,
        ] + (is_string($permission) && in_array($permission, UserDevice::PERMISSIONS, true)
            ? ['notification_permission' => $permission, 'notification_permission_at' => now()]
            : []));
        $this->retireOlderTokens($device);

        return $device;
    }

    /**
     * Which of the three apps a phone token comes from, read from the X-App header every call of the apps carries (null for anything else).
     * The dashboard counts and labels devices per app with it.
     */
    private function phoneApp(): ?string
    {
        $app = request()->header('X-App');

        return is_string($app) && in_array($app, PushDiagnostics::APPS, true) ? $app : null;
    }

    /**
     * A phone gets a new token now and then (Firebase rotates it, the app data is cleared) and registers the new one under the same install
     * id. The old token can stay valid at Google for a while, so keeping both would ring the phone twice: only the newest one stays on.
     * Browsers are left alone: one browser holds several tokens (the store and the dashboard have their own worker scope).
     */
    private function retireOlderTokens(UserDevice $device): void
    {
        if ($device->platform === 'web' || $device->device_id === null || $device->device_id === '') {
            return;
        }
        UserDevice::where('device_id', $device->device_id)->where('platform', $device->platform)->whereKeyNot($device->id)
            ->whereNull('disabled_at')->update(['disabled_at' => now()]);
    }

    /** Turns off one of the user's own tokens (a token of somebody else is never touched). */
    public function disable(User $user, string $pushToken): int
    {
        return UserDevice::where('user_id', $user->id)->where('push_token', $pushToken)->whereNull('disabled_at')->update(['disabled_at' => now()]);
    }
}
