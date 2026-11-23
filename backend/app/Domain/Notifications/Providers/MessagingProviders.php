<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Settings\Services\Settings;

/**
 * Which SMS / WhatsApp provider the owner chose in the dashboard and what they typed for it. Provider keys are sealed settings
 * (messaging.<provider>_<field>), never environment variables. Nothing chosen = nothing changes: the server's old SMS_DRIVER setting and
 * WhatsApp keys keep working exactly as before.
 */
class MessagingProviders
{
    public const SMS = 'messaging.sms_provider';

    public const WHATSAPP = 'messaging.whatsapp_provider';

    /** When the provider fields were last saved: a delivered test only counts if it came after. */
    public const CONFIGURED_AT = 'messaging.configured_at';

    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, ProviderPreset> */
    public function presets(): array
    {
        return ['http' => new GenericHttpPreset, 'twilio' => new TwilioPreset, 'unifonic' => new UnifonicPreset, 'infobip' => new InfobipPreset];
    }

    public function preset(string $key): ?ProviderPreset
    {
        return $this->presets()[$key] ?? null;
    }

    public static function settingKey(string $provider, string $field): string
    {
        return "messaging.{$provider}_{$field}";
    }

    public function chosen(string $channel): string
    {
        $v = (string) $this->settings->get($channel === 'whatsapp' ? self::WHATSAPP : self::SMS, 'none');

        return $v !== 'none' && in_array($channel, $this->preset($v)?->channels() ?? [], true) ? $v : 'none';
    }

    /** @return array<string, string> the saved fields of one provider (secrets decrypted: for the server only, never for a page) */
    public function values(string $provider): array
    {
        $out = [];
        foreach ($this->preset($provider)?->fields() ?? [] as $f) {
            $v = $this->settings->get(self::settingKey($provider, $f['key']));
            $out[$f['key']] = is_scalar($v) ? (string) $v : '';
        }

        return $out;
    }

    /** Is every required field of the provider (for this channel) filled in? */
    public function complete(string $provider, string $channel): bool
    {
        $c = $this->values($provider);
        foreach ($this->preset($provider)?->fields() ?? [] as $f) {
            if ($f['required'] || ($channel === 'whatsapp' && $f['key'] === 'whatsapp_from') || ($channel === 'sms' && $provider === 'twilio' && $f['key'] === 'sms_from')) {
                if (in_array($channel, $f['channels'], true) && ($c[$f['key']] ?? '') === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array{0: ProviderPreset, 1: array<string, string>}|null the provider that sends this channel now, with its fields */
    public function active(string $channel): ?array
    {
        $key = $this->chosen($channel);
        $preset = $this->preset($key);

        return $preset && $this->complete($key, $channel) ? [$preset, $this->values($key)] : null;
    }

    public function configured(string $channel): bool
    {
        return $this->active($channel) !== null;
    }

    /** Does a secret field already hold a value? (so the page can say "saved" without ever reading it back) */
    public function has(string $provider, string $field): bool
    {
        return ($this->values($provider)[$field] ?? '') !== '';
    }

    public function touch(): void
    {
        $this->settings->set(self::CONFIGURED_AT, now()->toIso8601String());
    }

    public function configuredAt(): ?string
    {
        $v = $this->settings->get(self::CONFIGURED_AT);

        return is_string($v) ? $v : null;
    }
}
