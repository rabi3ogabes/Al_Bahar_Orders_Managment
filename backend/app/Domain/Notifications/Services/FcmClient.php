<?php

namespace App\Domain\Notifications\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Firebase Cloud Messaging HTTP v1 using a service-account JWT (RS256) — no SDK dependency. */
class FcmClient
{
    public function configured(): bool
    {
        $path = config('services.fcm.credentials_path');

        return config('services.fcm.project_id') && $path && is_readable($path);
    }

    /** @return array{ok: bool, unregistered?: bool} */
    public function send(string $token, string $title, string $body, array $data = []): array
    {
        $res = Http::withToken($this->accessToken())->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', ['message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
                'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'orders']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]]);

        return ['ok' => $res->successful(), 'unregistered' => $res->status() === 404 || str_contains($res->body(), 'UNREGISTERED')];
    }

    /**
     * Order progress: Android receives a data-only message (the app draws the live progress notification, also while in the
     * background); iOS receives a regular alert. `collapse_key` keeps one card per order.
     *
     * @return array{ok: bool, unregistered?: bool}
     */
    public function sendProgress(string $token, string $title, string $body, array $data): array
    {
        $res = Http::withToken($this->accessToken())->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', ['message' => [
                'token' => $token,
                'data' => array_map('strval', $data + ['title' => $title, 'body' => $body]),
                'android' => ['priority' => 'high', 'collapse_key' => 'order-'.($data['order_id'] ?? '')],
                'apns' => ['headers' => ['apns-collapse-id' => 'order-'.($data['order_id'] ?? '')], 'payload' => ['aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default', 'thread-id' => 'order-'.($data['order_id'] ?? '')]]],
            ]]);

        return ['ok' => $res->successful(), 'unregistered' => $res->status() === 404 || str_contains($res->body(), 'UNREGISTERED')];
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm:access_token', 3000, function () {
            $sa = json_decode((string) file_get_contents(config('services.fcm.credentials_path')), true);
            $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
            $now = time();
            $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            openssl_sign($unsigned, $signature, $sa['private_key'], 'sha256WithRSAEncryption');
            $jwt = $unsigned.'.'.$b64($signature);

            return Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }
}
