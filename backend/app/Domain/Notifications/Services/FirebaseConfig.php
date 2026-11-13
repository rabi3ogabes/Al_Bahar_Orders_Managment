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

    /** What the public endpoint and the service worker may show: nothing at all while push is off or the server key is missing. */
    public function publicConfig(): array
    {
        $on = $this->configured();

        return ['enabled' => $on, 'web' => $on ? $this->webConfig() : null];
    }

    /** @return array{enabled: bool, server: bool, source: ?string, client_email: ?string, project_id: ?string, web: bool, vapid: bool} for the setup checklist */
    public function status(): array
    {
        $key = $this->serviceAccount();

        return [
            'enabled' => $this->enabled(),
            'server' => $this->hasServerCredentials(),
            'source' => $this->serviceAccountSource(),
            'client_email' => $key['client_email'] ?? null,
            'project_id' => $this->projectId(),
            'web' => $this->webConfig() !== null,
            'vapid' => $this->text(self::VAPID_KEY) !== null,
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
