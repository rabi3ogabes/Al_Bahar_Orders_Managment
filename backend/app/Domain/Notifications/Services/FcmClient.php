<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\UserDevice;
use App\Support\FirebaseWorkerScript;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Firebase Cloud Messaging HTTP v1 using a service-account JWT (RS256) — no SDK dependency. */
class FcmClient
{
    /** Switched on in the dashboard (or set up with the old server variables) and a project + service-account key exist. */
    public function configured(): bool
    {
        return app(FirebaseConfig::class)->configured();
    }

    /**
     * One notification to one phone or browser. Pass the device so a web token gets a web push message (platform "web") instead of
     * the Android/iOS one; without it the token is treated as a phone token, as before.
     *
     * @return array{ok: bool, unregistered?: bool, status?: int, error?: ?string}
     */
    public function send(string $token, string $title, string $body, array $data = [], ?UserDevice $device = null): array
    {
        if ($device?->platform === 'web') {
            return $this->sendWeb($token, $title, $body, $data, $device->app);
        }
        $res = Http::withToken($this->accessToken())->timeout(10)
            ->post($this->endpoint(), ['message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
                'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'orders']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]]);

        return $this->outcome($res);
    }

    /**
     * Order progress (the live order card). One message carries both platforms' instructions:
     *  - Android: DATA ONLY, high priority — the app draws the segmented progress notification itself, also while it is
     *    in the background, so there must be no `notification` block. `collapse_key` + ttl keep only the newest update
     *    for a phone that was offline; `data.seq` lets the app discard a stale one that arrives late.
     *  - iOS: a normal alert, grouped in one thread and collapsed per order. An ETA-only update (`data.quiet` = 1) is
     *    delivered passively (no sound, no wake) so a moving estimate never alerts twice.
     * `$data` values are sent as strings; null values are left out and arrays (the `brand` colours) are sent as JSON.
     *
     * A web token (pass its device) gets the same data-only message through Web Push; the service worker draws the card.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, unregistered?: bool, status?: int, error?: ?string}
     */
    public function sendProgress(string $token, string $title, string $body, array $data, ?UserDevice $device = null): array
    {
        if ($device?->platform === 'web') {
            return $this->sendWebProgress($token, $title, $body, $data, $device->app);
        }
        $order = 'order-'.($data['order_id'] ?? '');
        $ttl = (int) config('platform.live_order.ttl_seconds', 14400);
        $quiet = in_array($data['quiet'] ?? null, ['1', 1, true], true);
        $aps = ['alert' => ['title' => $title, 'body' => $body], 'thread-id' => $order, 'interruption-level' => $quiet ? 'passive' : 'active'] + ($quiet ? [] : ['sound' => 'default']);

        $res = Http::withToken($this->accessToken())->timeout(10)
            ->post($this->endpoint(), ['message' => [
                'token' => $token,
                'data' => $this->strings($data + ['title' => $title, 'body' => $body]),
                'android' => ['priority' => 'high', 'collapse_key' => $order, 'ttl' => $ttl.'s'],
                'apns' => [
                    'headers' => ['apns-push-type' => 'alert', 'apns-priority' => $quiet ? '5' : '10', 'apns-collapse-id' => $order, 'apns-expiration' => (string) (now()->getTimestamp() + $ttl)],
                    'payload' => ['aps' => $aps],
                ],
            ]]);

        return $this->outcome($res);
    }

    /** FCM `data` accepts string values only. @return array<string, string> */
    private function strings(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $out[$key] = is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
        }

        return $out;
    }

    /** The OAuth token for the saved service-account key, kept for 50 minutes per key (a new key gets a new token at once). */
    private function accessToken(bool $fresh = false): string
    {
        $sa = app(FirebaseConfig::class)->serviceAccount() ?? throw new \RuntimeException('Firebase service account is not configured.');
        $key = 'fcm:access_token:'.substr(hash('sha256', $sa['client_email'].'|'.($sa['private_key_id'] ?? hash('sha256', $sa['private_key']))), 0, 24);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3000, function () use ($sa) {
            $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
            $now = time();
            $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            openssl_sign($unsigned, $signature, $sa['private_key'], 'sha256WithRSAEncryption');
            $jwt = $unsigned.'.'.$b64($signature);

            return Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }

    /**
     * A notification to a browser (Web Push through FCM). The `notification` block makes the Firebase SDK in the service worker draw it
     * (title, body, icons) and `fcm_options.link` is where a click goes; `data` is what an open page receives in onMessage().
     *
     * @param  array<string, mixed>  $data  `deep_link` may be an app link (albahar://orders/1), a path or a link on this site
     * @param  string|null  $app  store | mobile-web | admin: the Flutter web app lives under /mobile-web/ with hash routes
     * @return array{ok: bool, unregistered?: bool, status?: int, error?: ?string}
     */
    public function sendWeb(string $token, string $title, string $body, array $data = [], ?string $app = null): array
    {
        $link = $this->webLink($data['deep_link'] ?? null, $app);
        $icon = $this->absolute(FirebaseWorkerScript::ICON);
        $webpush = [
            'headers' => ['TTL' => '86400', 'Urgency' => 'normal'],
            'notification' => ['title' => $title, 'body' => $body, 'icon' => $icon, 'badge' => $icon, 'dir' => 'auto'],
        ];
        if (str_starts_with($this->absolute($link), 'https://')) { // FCM accepts only https links here
            $webpush['fcm_options'] = ['link' => $this->absolute($link)];
        }
        $res = Http::withToken($this->accessToken())->timeout(10)
            ->post($this->endpoint(), ['message' => ['token' => $token, 'data' => $this->strings(array_merge($data, ['deep_link' => $link])), 'webpush' => $webpush]]);

        return $this->outcome($res);
    }

    /**
     * The live order card for a browser: data only (the service worker draws it with the same tag per order, so an update replaces the
     * card), high urgency, the same time to live as the phones. The `Topic` header makes the push service keep only the newest update
     * for a browser that was offline, like `collapse_key` does for Android.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, unregistered?: bool, status?: int, error?: ?string}
     */
    public function sendWebProgress(string $token, string $title, string $body, array $data, ?string $app = null): array
    {
        $quiet = in_array($data['quiet'] ?? null, ['1', 1, true], true);
        $headers = ['TTL' => (string) (int) config('platform.live_order.ttl_seconds', 14400), 'Urgency' => $quiet ? 'normal' : 'high'];
        // The Topic header allows 32 characters: a uuid without its dashes is exactly that.
        $topic = substr((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) ($data['order_id'] ?? '')), 0, 32);
        if ($topic !== '') {
            $headers['Topic'] = $topic;
        }
        $payload = $this->strings(array_merge($data, ['title' => $title, 'body' => $body, 'deep_link' => $this->webLink($data['deep_link'] ?? null, $app)]));
        $res = Http::withToken($this->accessToken())->timeout(10)
            ->post($this->endpoint(), ['message' => ['token' => $token, 'data' => $payload, 'webpush' => ['headers' => $headers]]]);

        return $this->outcome($res);
    }

    /**
     * "Test connection" for the dashboard: gets a fresh OAuth token with the saved key and asks FCM to validate a message without
     * sending it (validate_only), so nothing reaches a device. `code` says what is wrong in a form the dashboard turns into plain words.
     *
     * @return array{ok: bool, code: string, status: ?int, detail: ?string}
     */
    public function check(): array
    {
        if (! app(FirebaseConfig::class)->hasServerCredentials()) {
            return ['ok' => false, 'code' => 'not_configured', 'status' => null, 'detail' => null];
        }
        try {
            $token = $this->accessToken(fresh: true);
        } catch (RequestException $e) {
            $error = (string) $e->response->json('error');

            return ['ok' => false, 'code' => $error === 'invalid_grant' ? 'key_rejected' : 'oauth_failed', 'status' => $e->response->status(), 'detail' => $this->clip($e->response->json('error_description') ?? $error)];
        } catch (ConnectionException) {
            return ['ok' => false, 'code' => 'unreachable', 'status' => null, 'detail' => null];
        }
        try {
            $res = Http::withToken($token)->timeout(10)->post($this->endpoint(), [
                'validate_only' => true,
                'message' => ['topic' => 'connection-test', 'notification' => ['title' => 'Connection test', 'body' => 'Connection test']],
            ]);
        } catch (ConnectionException) {
            return ['ok' => false, 'code' => 'unreachable', 'status' => null, 'detail' => null];
        }
        if ($res->successful()) {
            return ['ok' => true, 'code' => 'ok', 'status' => $res->status(), 'detail' => null];
        }
        $status = (string) $res->json('error.status');
        $message = (string) $res->json('error.message');
        $code = match (true) {
            $res->status() === 429 => 'rate_limited',
            $res->status() >= 500 => 'fcm_down',
            $res->status() === 404 => 'project_not_found',
            $res->status() === 401 => 'key_rejected',
            $status === 'PERMISSION_DENIED' && (str_contains($message, 'has not been used') || str_contains($message, 'is disabled') || str_contains($res->body(), 'SERVICE_DISABLED')) => 'api_disabled',
            $status === 'PERMISSION_DENIED' => 'permission_denied',
            default => 'rejected',
        };

        return ['ok' => false, 'code' => $code, 'status' => $res->status(), 'detail' => $this->clip($message)];
    }

    private function endpoint(): string
    {
        return 'https://fcm.googleapis.com/v1/projects/'.app(FirebaseConfig::class)->projectId().'/messages:send';
    }

    /**
     * What happened to one send. `unregistered` is true only when FCM says this token is dead (UNREGISTERED, or INVALID_ARGUMENT that
     * blames the token itself), never for a wrong project or a bad payload, so a configuration mistake cannot switch off every device.
     *
     * @return array{ok: bool, unregistered: bool, status: int, error: ?string}
     */
    private function outcome(Response $res): array
    {
        $error = $res->json('error.status');
        $deadToken = str_contains($res->body(), 'UNREGISTERED') || ($error === 'INVALID_ARGUMENT' && $this->blamesToken($res));

        return ['ok' => $res->successful(), 'unregistered' => ! $res->successful() && $deadToken, 'status' => $res->status(), 'error' => is_string($error) ? $error : null];
    }

    private function blamesToken(Response $res): bool
    {
        foreach ((array) $res->json('error.details', []) as $detail) {
            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                if (($violation['field'] ?? null) === 'message.token') {
                    return true;
                }
            }
        }

        return str_contains((string) $res->json('error.message'), 'registration token');
    }

    /** A same-site path for a push click: albahar://orders/1 → /orders/1, own-site https links keep their path, anything else → "/". */
    private function webLink(mixed $deepLink, ?string $app): string
    {
        $link = is_string($deepLink) ? trim($deepLink) : '';
        $path = '/';
        if (str_starts_with($link, 'albahar://')) {
            $path = '/'.ltrim(substr($link, strlen('albahar://')), '/');
        } elseif (preg_match('#^https?://#i', $link)) {
            $parts = parse_url($link);
            if (($parts['host'] ?? null) === parse_url((string) config('app.url'), PHP_URL_HOST)) {
                $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
            }
        } elseif (str_starts_with($link, '/') && ! str_starts_with($link, '//')) {
            $path = $link;
        }
        if ($app === 'mobile-web' && ! str_starts_with($path, '/mobile-web')) {
            $path = '/mobile-web/#'.$path; // the Flutter web app routes after the hash
        }

        return $path;
    }

    private function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    private function clip(mixed $text): ?string
    {
        return is_string($text) && $text !== '' ? mb_substr($text, 0, 240) : null;
    }
}
