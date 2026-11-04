<?php

namespace App\Domain\Settings\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Settings\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Runtime-editable settings stored in system_settings, falling back to config('platform.*').
 * Secret values are encrypted at rest and never returned unmasked to the UI.
 */
class Settings
{
    private const CACHE_KEY = 'system_settings:v1';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return config('platform.'.$key, $default);
    }

    public function set(string $key, mixed $value, bool $secret = false): void
    {
        [$group, $name] = $this->split($key);
        $setting = SystemSetting::firstOrNew(['group' => $group, 'key' => $name]);
        $old = $setting->exists ? ($setting->is_secret ? '[secret]' : $setting->value) : null;
        $setting->fill([
            'value' => $secret ? ['encrypted' => Crypt::encryptString(json_encode($value))] : ['v' => $value],
            'is_secret' => $secret,
            'updated_by' => auth()->id(),
        ])->save();
        Cache::forget(self::CACHE_KEY);

        app(AuditLogger::class)->log('settings.updated', $setting, ['value' => $old], ['value' => $secret ? '[secret]' : $value], ['key' => $key]);
    }

    public function masked(string $key): ?string
    {
        $value = $this->get($key);

        return $value ? str_repeat('•', 8).substr((string) $value, -4) : null;
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $out = [];
            foreach (SystemSetting::all() as $s) {
                $value = $s->value ?? [];
                $out[$s->group.'.'.$s->key] = $s->is_secret
                    ? json_decode(Crypt::decryptString($value['encrypted']), true)
                    : ($value['v'] ?? null);
            }

            return $out;
        });
    }

    private function split(string $key): array
    {
        $pos = strpos($key, '.');

        return $pos === false ? ['general', $key] : [substr($key, 0, $pos), substr($key, $pos + 1)];
    }
}
