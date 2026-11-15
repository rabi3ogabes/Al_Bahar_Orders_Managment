<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Settings\Services\Settings;

/**
 * The single reader of the Firebase push configuration. The owner types it in the dashboard (Settings service, group "push";
 * the service-account key is sealed there); the old server variables FCM_PROJECT_ID + FCM_CREDENTIALS_PATH keep working as a
 * fallback, and a dashboard value always wins when it is set. The Settings service already caches everything as plain arrays and
 * drops its cache on every save, so a change is live at once without a second cache to bust.
 */
class FirebaseConfig
{
    public const ENABLED = 'push.enabled';

    public const PROJECT_ID = 'push.firebase.project_id';

    public const API_KEY = 'push.firebase.api_key';

    public const AUTH_DOMAIN = 'push.firebase.auth_domain';

    public const SENDER_ID = 'push.firebase.messaging_sender_id';

    public const APP_ID = 'push.firebase.app_id_web';

    public const VAPID_KEY = 'push.firebase.vapid_key';

    public const SERVICE_ACCOUNT = 'push.firebase.service_account';

    /** The platform API keys of the phone apps (Android and iPhone keys differ in Firebase; the web key cannot be used on a phone). */
    public const API_KEY_ANDROID = 'push.firebase.api_key_android';

    public const API_KEY_IOS = 'push.firebase.api_key_ios';

    /** The phone apps that exist, per platform: every Android app has its own package, the iPhone app is the customer app. */
    public const NATIVE_APPS = ['android' => ['customer', 'driver', 'staff'], 'ios' => ['customer']];

    /** The package name (Android) and bundle ID (iPhone) each app must be registered under in Firebase. */
    public const PACKAGES = ['customer' => 'qa.albahar.customer', 'driver' => 'qa.albahar.driver', 'staff' => 'qa.albahar.staff'];

    /** The settings key of one phone app's Firebase app ID, e.g. push.firebase.app_id_android_driver. */
    public static function nativeAppKey(string $platform, string $app): string
    {
        return "push.firebase.app_id_{$platform}_{$app}";
    }

    public static function nativeApiKey(string $platform): string
    {
        return $platform === 'ios' ? self::API_KEY_IOS : self::API_KEY_ANDROID;
    }

    public function __construct(private readonly Settings $settings) {}

    /** The master switch. Never touched in the dashboard → on only for servers that were set up with the old variables. */
    public function enabled(): bool
    {
        $v = $this->settings->get(self::ENABLED);

        return $v === null ? $this->envCredentials() !== null : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function projectId(): ?string
    {
        $id = $this->text(self::PROJECT_ID) ?? $this->text('services.fcm.project_id', true);

        return $id ?? $this->serviceAccount()['project_id'] ?? null;
    }

    /** @return array<string, string>|null the key with at least client_email + private_key (project_id when the file has it) */
    public function serviceAccount(): ?array
    {
        $stored = $this->settings->get(self::SERVICE_ACCOUNT);
        if (is_string($stored) && $stored !== '') {
            $key = $this->usable(json_decode($stored, true));
            if ($key !== null) {
                return $key;
            }
        }

        return $this->envCredentials();
    }

    /** "dashboard", "env" (the old server variables) or null when there is no key. */
    public function serviceAccountSource(): ?string
    {
        $stored = $this->settings->get(self::SERVICE_ACCOUNT);
        if (is_string($stored) && $stored !== '' && $this->usable(json_decode($stored, true)) !== null) {
            return 'dashboard';
        }

        return $this->envCredentials() !== null ? 'env' : null;
    }

    /** A project and a usable key exist (whether or not the master switch is on). */
    public function hasServerCredentials(): bool
    {
        return $this->projectId() !== null && $this->serviceAccount() !== null;
    }

    /** The server may send: switched on and credentials present. */
    public function configured(): bool
    {
        return $this->enabled() && $this->hasServerCredentials();
    }

    /** @return array{apiKey: string, authDomain: string, projectId: string, messagingSenderId: string, appId: string, vapidKey: string}|null public values only */
    public function webConfig(): ?array
    {
        $project = $this->projectId();
        $web = [
            'apiKey' => $this->text(self::API_KEY),
            'messagingSenderId' => $this->text(self::SENDER_ID),
            'appId' => $this->text(self::APP_ID),
            'vapidKey' => $this->text(self::VAPID_KEY),
        ];
        if ($project === null || in_array(null, $web, true)) {
            return null;
        }

        return [
            'apiKey' => $web['apiKey'],
            'authDomain' => $this->text(self::AUTH_DOMAIN) ?? $project.'.firebaseapp.com',
            'projectId' => $project,
            'messagingSenderId' => $web['messagingSenderId'],
            'appId' => $web['appId'],
            'vapidKey' => $web['vapidKey'],
        ];
    }

    /**
     * What one phone app needs to start Firebase without the google-services.json / GoogleService-Info.plist files: null unless push is on
     * and that app has its platform key and app ID. The messaging sender ID is the project number inside the app ID.
     *
     * @return array{projectId: string, messagingSenderId: string, apiKey: string, appId: string}|null public values only
     */
    public function native(string $platform, string $app): ?array
    {
        if (! in_array($app, self::NATIVE_APPS[$platform] ?? [], true) || ! $this->configured()) {
            return null;
        }
        $project = $this->projectId();
        $apiKey = $this->text(self::nativeApiKey($platform));
        $appId = $this->text(self::nativeAppKey($platform, $app));
        $sender = $this->text(self::SENDER_ID) ?? ($appId !== null && preg_match('/^1:(\d+):/', $appId, $m) ? $m[1] : null);
        if ($project === null || $apiKey === null || $appId === null || $sender === null) {
            return null;
        }

        return ['projectId' => $project, 'messagingSenderId' => $sender, 'apiKey' => $apiKey, 'appId' => $appId];
    }

    /** What the public endpoint and the service worker may show: nothing at all while push is off or the server key is missing. */
    public function publicConfig(?string $platform = null, ?string $app = null): array
    {
        $on = $this->configured();
        $out = ['enabled' => $on, 'web' => $on ? $this->webConfig() : null];
        if ($platform !== null && $app !== null) {
            $out['native'] = $this->native($platform, $app);
        }

        return $out;
    }

    /** @return array{enabled: bool, server: bool, source: ?string, client_email: ?string, project_id: ?string, web: bool, vapid: bool, android: int, android_total: int, ios: bool} for the setup checklist */
    public function status(): array
    {
        $key = $this->serviceAccount();
        $ready = fn (string $platform, string $app) => $this->text(self::nativeApiKey($platform)) !== null && $this->text(self::nativeAppKey($platform, $app)) !== null;

        return [
            'enabled' => $this->enabled(),
            'server' => $this->hasServerCredentials(),
            'source' => $this->serviceAccountSource(),
            'client_email' => $key['client_email'] ?? null,
            'project_id' => $this->projectId(),
            'web' => $this->webConfig() !== null,
            'vapid' => $this->text(self::VAPID_KEY) !== null,
            'android' => count(array_filter(self::NATIVE_APPS['android'], fn (string $app) => $ready('android', $app))),
            'android_total' => count(self::NATIVE_APPS['android']),
            'ios' => $ready('ios', 'customer'),
        ];
    }

    /** @return array<string, string>|null the legacy file-based key, only when both old variables are set and the file is readable */
    private function envCredentials(): ?array
    {
        $path = config('services.fcm.credentials_path');
        if (! config('services.fcm.project_id') || ! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        return $this->usable(json_decode((string) file_get_contents($path), true));
    }

    private function usable(mixed $key): ?array
    {
        return is_array($key) && is_string($key['client_email'] ?? null) && is_string($key['private_key'] ?? null) ? $key : null;
    }

    private function text(string $key, bool $fromConfig = false): ?string
    {
        $v = $fromConfig ? config($key) : $this->settings->get($key);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
