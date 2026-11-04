<?php

namespace App\Domain\Payments\Gateways;

/**
 * SADAD Web Checkout checksum.
 *
 * Implements the checksum scheme distributed in SADAD's merchant PHP kit for Web Checkout 2.x:
 *   hash  = sha256(payload + "|" + salt) + salt        (salt = 4 random chars)
 *   value = base64(AES-128-CBC(hash, key, IV "@@@@&&&&####$$$$"))
 * where `key` is url-encoded(secret key) + merchant id, and `payload` is the JSON of the posted data.
 *
 * ⚠ The exact field set, key derivation and endpoints MUST be confirmed against the official merchant onboarding pack
 *   before go-live (see docs/integrations/SADAD.md). The algorithm is isolated here so it can be adjusted without touching
 *   the payment workflow, and it is covered by round-trip tests.
 */
final class SadadChecksum
{
    private const IV = '@@@@&&&&####$$$$';

    public static function generate(string $payload, string $key): string
    {
        $salt = self::salt();
        $hash = hash('sha256', $payload.'|'.$salt).$salt;

        return self::encrypt($hash, $key);
    }

    public static function verify(string $payload, string $key, string $checksum): bool
    {
        $decrypted = self::decrypt($checksum, $key);
        if ($decrypted === null || strlen($decrypted) < 5) {
            return false;
        }
        $salt = substr($decrypted, -4);
        $expected = hash('sha256', $payload.'|'.$salt).$salt;

        return hash_equals($expected, $decrypted);
    }

    private static function encrypt(string $input, string $key): string
    {
        return (string) openssl_encrypt($input, 'AES-128-CBC', self::key($key), 0, self::IV);
    }

    private static function decrypt(string $input, string $key): ?string
    {
        $out = openssl_decrypt($input, 'AES-128-CBC', self::key($key), 0, self::IV);

        return $out === false ? null : $out;
    }

    private static function key(string $key): string
    {
        return substr(str_pad($key, 16, "\0"), 0, 16);
    }

    private static function salt(): string
    {
        $chars = 'AbcDE123IJKLMN67QRSTUVWXYZaBCdefghijklmn123opq45rs67tuv89wxyz0FGH45OP89';

        return implode('', array_map(fn () => $chars[random_int(0, strlen($chars) - 1)], range(1, 4)));
    }
}
