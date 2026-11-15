<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Settings\Services\Settings;

/**
 * The single reader of the Apple push configuration used for iPhone Live Activities (the order on the lock screen and in the Dynamic
 * Island). FCM cannot carry Live Activity updates reliably, so these go straight to Apple (see {@see ApnsClient}).
 *
 * The owner types it in the dashboard (Push notifications page, "iPhone live activity"; Settings service, group "push"): Team ID, Key ID,
 * bundle ID and environment are plain settings, the .p8 key is sealed there and never shown again. Nothing here comes from the server
 * environment, and nothing is a placeholder: while a value is missing the feature simply reports "not set up" and sends nothing.
 */
class ApnsConfig
{
    public const ENABLED = 'push.apns.enabled';

    public const TEAM_ID = 'push.apns.team_id';

    public const KEY_ID = 'push.apns.key_id';

    public const BUNDLE_ID = 'push.apns.bundle_id';

    public const ENVIRONMENT = 'push.apns.environment';

    public const PRIVATE_KEY = 'push.apns.private_key';

    /** auto = each phone's own token says which Apple host it belongs to (Xcode builds: sandbox, TestFlight and the store: production). */
    public const ENVIRONMENTS = ['auto', 'production', 'sandbox'];

    public const DEFAULT_BUNDLE_ID = 'qa.albahar.customer';

    public function __construct(private readonly Settings $settings) {}

    /** The master switch for Live Activities. Off until the owner turns it on. */
    public function enabled(): bool
    {
        return filter_var($this->settings->get(self::ENABLED), FILTER_VALIDATE_BOOLEAN);
    }

    public function teamId(): ?string
    {
        return $this->text(self::TEAM_ID);
    }

    public function keyId(): ?string
    {
        return $this->text(self::KEY_ID);
    }

    public function bundleId(): string
    {
        return $this->text(self::BUNDLE_ID) ?? self::DEFAULT_BUNDLE_ID;
    }

    /** The topic Apple wants for Live Activity pushes. */
    public function topic(): string
    {
        return $this->bundleId().'.push-type.liveactivity';
    }

    /** `auto`, `production` or `sandbox`. */
    public function environment(): string
    {
        $v = $this->text(self::ENVIRONMENT);

        return in_array($v, self::ENVIRONMENTS, true) ? $v : 'auto';
    }

    /** True when the dashboard forces one Apple host for every token instead of trusting each token's own environment. */
    public function forcesEnvironment(): bool
    {
        return $this->environment() !== 'auto';
    }

    /** The Apple host for a token that says it belongs to [$tokenEnvironment]. */
    public function environmentFor(string $tokenEnvironment): string
    {
        return $this->forcesEnvironment() ? $this->environment() : ($tokenEnvironment === 'sandbox' ? 'sandbox' : 'production');
    }

    /** The saved key as a complete PEM, or null. */
    public function privateKey(): ?string
    {
        $v = $this->settings->get(self::PRIVATE_KEY);

        return is_string($v) && str_contains($v, 'PRIVATE KEY') ? $v : null;
    }

    /** Team ID, Key ID and the key are all there (whether or not the switch is on). */
    public function hasCredentials(): bool
    {
        return $this->teamId() !== null && $this->keyId() !== null && $this->privateKey() !== null;
    }

    /** The server may send Live Activity pushes: switched on and complete. */
    public function configured(): bool
    {
        return $this->enabled() && $this->hasCredentials();
    }

    /** @return array{enabled: bool, ready: bool, key: bool, team_id: ?string, key_id: ?string, bundle_id: string, environment: string} for the dashboard checklist */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'ready' => $this->hasCredentials(),
            'key' => $this->privateKey() !== null,
            'team_id' => $this->teamId(),
            'key_id' => $this->keyId(),
            'bundle_id' => $this->bundleId(),
            'environment' => $this->environment(),
        ];
    }

    private function text(string $key): ?string
    {
        $v = $this->settings->get($key);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
