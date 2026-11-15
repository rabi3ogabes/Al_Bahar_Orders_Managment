<?php

namespace App\Domain\Notifications\Services;

/**
 * Checks an Apple push key (the .p8 file from the Apple Developer account) before it is stored: it must be a PKCS#8 private key
 * OpenSSL can read, on the P-256 curve (the only one Apple's token authentication accepts). The text may be pasted with or without its
 * BEGIN/END lines; what comes back is always a complete PEM, the only form that is ever stored (sealed) or signed with.
 */
final class ApnsKey
{
    public const MAX_BYTES = 2048;

    /**
     * @return array{ok: true, pem: string}|array{ok: false, error: string} error is a key under push_apns.key_error.*
     */
    public static function check(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'too_large'];
        }
        $pem = self::pem($raw);
        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            return ['ok' => false, 'error' => 'bad_key'];
        }
        $details = openssl_pkey_get_details($key) ?: [];
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return ['ok' => false, 'error' => 'not_p256'];
        }

        return ['ok' => true, 'pem' => $pem];
    }

    /** A complete PEM from whatever was pasted: the file as it is, or only the base64 body of it. */
    public static function pem(string $raw): string
    {
        $raw = trim(str_replace("\r", '', $raw));
        if (str_contains($raw, 'BEGIN')) {
            return $raw."\n";
        }
        $body = (string) preg_replace('/\s+/', '', $raw);

        return "-----BEGIN PRIVATE KEY-----\n".chunk_split($body, 64, "\n")."-----END PRIVATE KEY-----\n";
    }
}
