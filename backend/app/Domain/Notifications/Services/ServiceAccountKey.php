<?php

namespace App\Domain\Notifications\Services;

/**
 * Checks a Google service-account key file (the JSON the Firebase console downloads) before it is stored: it must be real JSON,
 * of type "service_account", belong to the Firebase project that was typed in, carry a client e-mail and a private key OpenSSL can
 * read. Only the fields the server needs are kept, so nothing else from the file (for example its token endpoint) is ever stored or used.
 */
final class ServiceAccountKey
{
    public const MAX_BYTES = 16384;

    private const KEEP = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id'];

    /**
     * @return array{ok: true, key: array<string, string>}|array{ok: false, error: string} error is a key under push_admin.key_error.*
     */
    public static function check(string $raw, ?string $projectId = null): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'too_large'];
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || array_is_list($data)) {
            return ['ok' => false, 'error' => 'not_json'];
        }
        if (($data['type'] ?? null) !== 'service_account') {
            return ['ok' => false, 'error' => 'wrong_type'];
        }
        $email = $data['client_email'] ?? null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'missing_email'];
        }
        $pem = $data['private_key'] ?? null;
        if (! is_string($pem) || ! str_contains($pem, 'PRIVATE KEY')) {
            return ['ok' => false, 'error' => 'missing_key'];
        }
        if (! is_string($data['project_id'] ?? null) || $data['project_id'] === '') {
            return ['ok' => false, 'error' => 'missing_project'];
        }
        if ($projectId !== null && $projectId !== '' && $data['project_id'] !== $projectId) {
            return ['ok' => false, 'error' => 'project_mismatch'];
        }
        if (openssl_pkey_get_private($pem) === false) {
            return ['ok' => false, 'error' => 'bad_key'];
        }

        $key = [];
        foreach (self::KEEP as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $key[$field] = $data[$field];
            }
        }

        return ['ok' => true, 'key' => $key];
    }
}
