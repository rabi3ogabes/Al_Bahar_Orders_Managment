<?php

namespace App\Support;

/**
 * Removes secrets and sensitive payment/personal data from arrays destined for logs,
 * audit records, provider request/response storage and admin event viewers.
 */
final class Redactor
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'secret', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'authorization', 'otp', 'code', 'otp_code', 'pin', 'cvv', 'cvc', 'card_number', 'pan',
        'card', 'checksumhash', 'checksum', 'signature', 'secret_key', 'merchant_key', 'private_key', 'two_factor_secret',
        'two_factor_recovery_codes', 'credentials', 'webhook_secret', 'remember_token', 'push_token',
        // The customer's second number (for the driver and branch staff only) is never written to a log or an audit record.
        'alternate_phone',
    ];

    public static function redact(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[depth-limit]';
        }
        if (! is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            $k = is_string($key) ? strtolower($key) : $key;
            if (is_string($k) && self::isSensitive($k)) {
                $out[$key] = '[REDACTED]';
            } elseif (is_string($k) && in_array($k, ['phone', 'mobile', 'mobile_no', 'msisdn'], true) && is_string($value)) {
                $out[$key] = Phone::mask($value);
            } elseif (is_string($k) && $k === 'email' && is_string($value)) {
                $out[$key] = self::maskEmail($value);
            } else {
                $out[$key] = self::redact($value, $depth + 1);
            }
        }

        return $out;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($local, 0, 2).'•••@'.$domain;
    }

    private static function isSensitive(string $key): bool
    {
        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }
        foreach (['password', 'secret', 'token', 'cvv', 'card_number', 'private'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
