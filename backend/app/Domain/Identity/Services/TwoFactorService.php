<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * RFC 6238 TOTP (SHA1, 30s, 6 digits) for staff accounts, compatible with Google Authenticator,
 * Microsoft Authenticator, 1Password, etc. Secrets are stored encrypted; recovery codes as SHA-256 hashes.
 * Each accepted time-step is remembered to prevent code replay within its validity window.
 */
class TwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public function otpauthUri(User $user, string $secret): string
    {
        $issuer = rawurlencode(config('platform.brand.name_en'));
        $label = rawurlencode(config('platform.brand.name_en').':'.($user->email ?? $user->phone));

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function verify(User $user, string $code, ?string $secret = null): bool
    {
        $secret ??= $user->two_factor_secret;
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! $secret) {
            return false;
        }
        if (strlen($code) > 6) {
            return $this->useRecoveryCode($user, $code);
        }
        $step = intdiv(time(), 30);
        foreach ([-1, 0, 1] as $drift) {
            $candidate = $step + $drift;
            if (hash_equals($this->totp($secret, $candidate), str_pad($code, 6, '0', STR_PAD_LEFT))) {
                // Replay protection: a time-step may be used once per user.
                return Cache::add("2fa-used:{$user->getKey()}:{$candidate}", true, 120);
            }
        }

        return false;
    }

    /** @return list<string> plaintext codes shown once to the user */
    public function generateRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    public function totp(string $secret, int $timeStep): string
    {
        $key = $this->base32Decode($secret);
        $binary = pack('N*', 0, $timeStep);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        $hash = hash('sha256', strtoupper($code));
        if (! in_array($hash, $hashes, true)) {
            return false;
        }
        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($hashes, [$hash]))])->save();

        return true;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';
        foreach (str_split($secret) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
