<?php

namespace App\Domain\Settings\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Settings\Models\SystemSetting;
use App\Support\MapConfig;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Runtime-editable settings stored in system_settings, falling back to config('platform.*').
 * Secret values are encrypted at rest and never returned unmasked to the UI.
 *
 * What the shared cache (Redis in production) holds is deliberately limited: every NON-secret value, and for a secret only the fact that it
 * exists. A secret is decrypted on demand, from its own row, and remembered for the rest of the request or queue job only (the class is
 * scoped, so the container drops that memory between requests and jobs). Encryption at rest therefore stays meaningful: a copy of the cache
 * (a Redis dump, a monitoring tool reading it) never contains a provider key.
 */
#[Scoped]
class Settings
{
    /** The snapshot itself. (v1 held the decrypted secrets, so this is a new key and the old one is never read as data again.) */
    private const CACHE_KEY = 'system_settings:v2';

    /**
     * The cache has always been cleared by forgetting this key (Settings::set, and the migration that removed the cash on delivery fee). It is kept
     * as the snapshot's STAMP: the snapshot is only trusted while this key holds the stamp it was built with, so forgetting it still invalidates
     * everything, and an old copy of the cache that held decrypted secrets is overwritten by the first rebuild. It holds an array, so a server still
     * running the previous release (which reads this key as its own data) finds no setting in it and uses its defaults instead of failing.
     */
    private const STAMP_KEY = 'system_settings:v1';

    private const CACHE_SECONDS = 300;

    /** @var array<string, mixed> decrypted secrets read during this request or job: never written anywhere shared */
    private array $secretMemo = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $snapshot = $this->snapshot();
        if (array_key_exists($key, $snapshot['values'])) {
            return $snapshot['values'][$key];
        }
        if (isset($snapshot['secrets'][$key]) && ($secret = $this->secret($key)) !== null) {
            return $secret; // an empty or undecryptable secret reads as "not set": the default below
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
        $this->forget();
        if ($group === 'map') {
            MapConfig::forget();
        }

        app(AuditLogger::class)->log('settings.updated', $setting, ['value' => $old], ['value' => $secret ? '[secret]' : $value], ['key' => $key]);
    }

    public function masked(string $key): ?string
    {
        $value = $this->get($key);

        return $value ? str_repeat('•', 8).substr((string) $value, -4) : null;
    }

    /**
     * Every setting by "group.key", secrets decrypted. Meant for code that must read them all; it is built for this call and never cached.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $snapshot = $this->snapshot();
        $all = $snapshot['values'];
        foreach (array_keys($snapshot['secrets']) as $key) {
            $all[$key] = $this->secret($key);
        }

        return $all;
    }

    /** Forget what was read (after a change, and for tests that write the table directly). */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::STAMP_KEY);
        $this->secretMemo = [];
    }

    /** @return array{values: array<string, mixed>, secrets: array<string, true>} the shared, secret-free view of the table */
    private function snapshot(): array
    {
        $cached = Cache::many([self::CACHE_KEY, self::STAMP_KEY]);
        $snapshot = $cached[self::CACHE_KEY] ?? null;
        $stamp = $cached[self::STAMP_KEY] ?? null;
        if (is_array($snapshot) && is_array($stamp) && isset($snapshot['stamp'], $stamp['stamp']) && $snapshot['stamp'] === $stamp['stamp']
            && is_array($snapshot['values'] ?? null) && is_array($snapshot['secrets'] ?? null)) {
            return ['values' => $snapshot['values'], 'secrets' => $snapshot['secrets']];
        }

        $values = [];
        $secrets = [];
        foreach (SystemSetting::query()->get(['group', 'key', 'value', 'is_secret']) as $s) {
            $name = $s->group.'.'.$s->key;
            if ($s->is_secret) {
                $secrets[$name] = true; // that it exists, never what it is
            } else {
                $values[$name] = ($s->value ?? [])['v'] ?? null;
            }
        }
        $stampValue = bin2hex(random_bytes(8));
        Cache::put(self::CACHE_KEY, ['stamp' => $stampValue, 'values' => $values, 'secrets' => $secrets], self::CACHE_SECONDS);
        Cache::put(self::STAMP_KEY, ['stamp' => $stampValue], self::CACHE_SECONDS); // overwrites a legacy copy that held decrypted secrets

        return ['values' => $values, 'secrets' => $secrets];
    }

    /** Decrypts one secret from its own row, once per request. A value that cannot be decrypted (the app key changed) reads as "not set". */
    private function secret(string $key): mixed
    {
        if (array_key_exists($key, $this->secretMemo)) {
            return $this->secretMemo[$key];
        }
        [$group, $name] = $this->split($key);
        $row = SystemSetting::query()->where('group', $group)->where('key', $name)->where('is_secret', true)->first(['value']);
        $encrypted = is_array($row?->value) ? ($row->value['encrypted'] ?? null) : null;
        $plain = null;
        if (is_string($encrypted)) {
            try {
                $plain = json_decode(Crypt::decryptString($encrypted), true);
            } catch (DecryptException) {
                Log::warning('A stored secret setting could not be decrypted and is treated as not set.', ['setting' => $key]);
            }
        }

        return $this->secretMemo[$key] = $plain;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $key): array
    {
        $pos = strpos($key, '.');

        return $pos === false ? ['general', $key] : [substr($key, 0, $pos), substr($key, $pos + 1)];
    }
}
