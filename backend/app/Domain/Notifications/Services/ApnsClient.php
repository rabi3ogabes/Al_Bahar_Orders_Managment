<?php

namespace App\Domain\Notifications\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A small Apple Push Notification service client for Live Activity pushes only (everything else goes through FCM): token authentication
 * (an ES256 JWT made from the .p8 key), HTTP/2, `apns-push-type: liveactivity` and the topic `<bundle id>.push-type.liveactivity`.
 *
 * Like {@see FcmClient} it never throws: a refused key, a dead token or a network problem comes back as a result the caller can act on,
 * so a Live Activity problem can never reach the order flow. Failures that can pass (Apple busy, no connection, 429, 5xx) are retried a
 * couple of times with a short back-off; a refused provider token is replaced once and the push sent again.
 *
 * @phpstan-type Result array{ok: bool, unregistered: bool, retry: bool, status: int, reason: ?string}
 */
class ApnsClient
{
    public const HOST_PRODUCTION = 'https://api.push.apple.com';

    public const HOST_SANDBOX = 'https://api.sandbox.push.apple.com';

    /** Apple asks for a new provider token no more often than every 20 minutes and no less often than every 60: this is in between. */
    private const JWT_SECONDS = 2400;

    public function __construct(private readonly ApnsConfig $config) {}

    public function configured(): bool
    {
        return $this->config->configured();
    }

    /**
     * One Live Activity push to one activity (or, for `event: start`, one phone's push-to-start token).
     *
     * @param  string  $token  the activity push token (hex)
     * @param  string  $tokenEnvironment  `production` or `sandbox`, as the app reported it (a forced dashboard setting wins)
     * @param  array<string, mixed>  $aps  the contents of the `aps` dictionary: timestamp, event, content-state, ...
     * @param  int  $priority  10 = deliver now (counts against Apple's budget), 5 = when convenient
     * @param  int|null  $expiresAt  Unix time after which Apple drops an undelivered push (null: Apple's default)
     * @return Result
     */
    public function sendLiveActivity(string $token, string $tokenEnvironment, array $aps, int $priority = 10, ?int $expiresAt = null): array
    {
        $url = $this->host($this->config->environmentFor($tokenEnvironment)).'/3/device/'.rawurlencode($token);
        $headers = [
            'apns-push-type' => 'liveactivity',
            'apns-topic' => $this->config->topic(),
            'apns-priority' => (string) $priority,
        ];
        if ($expiresAt !== null) {
            $headers['apns-expiration'] = (string) $expiresAt;
        }

        $attempts = 1 + max(0, (int) config('platform.live_order.apns_retries', 2));
        $refreshed = false;
        $result = $this->failure(0, 'UNAVAILABLE', retry: true);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $res = Http::withToken($this->jwt(), 'bearer')->withHeaders($headers)->withOptions(['version' => 2.0])
                    ->timeout(10)->connectTimeout(5)->post($url, ['aps' => $aps]);
            } catch (ConnectionException) {
                $result = $this->failure(0, 'UNAVAILABLE', retry: true);
                $this->backoff($attempt);

                continue;
            } catch (RuntimeException) { // the saved key can no longer be read
                return $this->failure(0, 'NoKey', retry: false);
            }
            $result = $this->outcome($res);
            if (! $refreshed && $res->status() === 403 && $result['reason'] === 'ExpiredProviderToken') {
                $refreshed = true;
                $this->jwt(fresh: true);
                $attempt--; // the replaced token gets its own try, it is not a retry

                continue;
            }
            if (! $result['retry']) {
                return $result;
            }
            $this->backoff($attempt);
        }

        return $result;
    }

    /**
     * "Test" for the dashboard: signs a provider token with the saved key and asks Apple to deliver a Live Activity push to a device token
     * that cannot exist. Apple checks the key, the team, the key ID and the topic BEFORE it looks at the device, so the answer
     * `BadDeviceToken` is the proof that everything the owner typed is right; nothing reaches any phone.
     *
     * @return array{ok: bool, code: string, status: ?int, detail: ?string}
     */
    public function check(): array
    {
        if (! $this->config->hasCredentials()) {
            return ['ok' => false, 'code' => 'not_configured', 'status' => null, 'detail' => null];
        }
        try {
            $jwt = $this->jwt(fresh: true);
        } catch (RuntimeException) {
            return ['ok' => false, 'code' => 'key_unreadable', 'status' => null, 'detail' => null];
        }
        $env = $this->config->forcesEnvironment() ? $this->config->environment() : 'production';
        try {
            $res = Http::withToken($jwt, 'bearer')->withOptions(['version' => 2.0])->timeout(10)->connectTimeout(5)->withHeaders([
                'apns-push-type' => 'liveactivity', 'apns-topic' => $this->config->topic(), 'apns-priority' => '5',
            ])->post($this->host($env).'/3/device/'.str_repeat('0', 64), ['aps' => ['timestamp' => time(), 'event' => 'update', 'content-state' => new \stdClass]]);
        } catch (ConnectionException) {
            return ['ok' => false, 'code' => $this->http2Supported() ? 'unreachable' : 'no_http2', 'status' => null, 'detail' => null];
        }
        $reason = $res->json('reason');
        $reason = is_string($reason) ? $reason : null;
        $code = match (true) {
            $res->status() === 400 && $reason === 'BadDeviceToken' => 'ok',
            $reason === 'InvalidProviderToken' => 'key_rejected',
            $reason === 'ExpiredProviderToken' => 'clock',
            in_array($reason, ['TopicDisallowed', 'BadTopic', 'MissingTopic', 'DeviceTokenNotForTopic'], true) => 'topic',
            $res->status() === 429 => 'rate_limited',
            $res->status() >= 500 => 'apns_down',
            default => 'rejected',
        };

        return ['ok' => $code === 'ok', 'code' => $code, 'status' => $res->status(), 'detail' => $reason];
    }

    /** Apple speaks HTTP/2 only; a PHP whose curl was built without it can never reach Apple. */
    protected function http2Supported(): bool
    {
        $info = curl_version();

        return defined('CURL_VERSION_HTTP2') && (($info['features'] ?? 0) & CURL_VERSION_HTTP2) !== 0;
    }

    /** The provider token: ES256, issued by the Team ID with the Key ID, kept for 40 minutes. @throws RuntimeException when the key cannot sign */
    public function jwt(bool $fresh = false): string
    {
        $team = $this->config->teamId();
        $kid = $this->config->keyId();
        $pem = $this->config->privateKey();
        if ($team === null || $kid === null || $pem === null) {
            throw new RuntimeException('APNs is not configured.');
        }
        $cache = 'apns:jwt:'.substr(hash('sha256', $team.'|'.$kid.'|'.hash('sha256', $pem)), 0, 24);
        if ($fresh) {
            Cache::forget($cache);
        }
        $token = Cache::remember($cache, self::JWT_SECONDS, fn () => $this->signJwt($team, $kid, $pem));
        if (! is_string($token) || $token === '') {
            Cache::forget($cache);

            throw new RuntimeException('Could not make a provider token.');
        }

        return $token;
    }

    private function signJwt(string $team, string $kid, string $pem): string
    {
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $unsigned = $b64((string) json_encode(['alg' => 'ES256', 'kid' => $kid])).'.'.$b64((string) json_encode(['iss' => $team, 'iat' => time()]));
        $key = @openssl_pkey_get_private($pem);
        if ($key === false || ! openssl_sign($unsigned, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The APNs key cannot sign.');
        }

        return $unsigned.'.'.$b64(self::derToRaw($der));
    }

    /**
     * OpenSSL returns an ECDSA signature as an ASN.1 DER sequence of two integers; a JWT carries them as the two 32-byte numbers
     * (R then S) glued together.
     */
    public static function derToRaw(string $der, int $size = 32): string
    {
        $offset = 2;
        if ((ord($der[1] ?? "\0") & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F; // long form: the length itself takes bytes
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (($der[$offset] ?? '') !== "\x02") {
                throw new RuntimeException('Unexpected signature format.');
            }
            $len = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $len), "\x00");
            $parts[] = str_pad($int, $size, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $parts[0].$parts[1];
    }

    private function host(string $environment): string
    {
        return $environment === 'sandbox' ? self::HOST_SANDBOX : self::HOST_PRODUCTION;
    }

    /**
     * What happened to one push.
     *  - `unregistered`: the token can never work again (410, or 400 BadDeviceToken while each token's own environment is trusted: the
     *    activity ended or the app was removed), so it is switched off. Never set when the dashboard forces one Apple host, so a wrong
     *    setting there cannot switch off every phone, and never for a refused key or topic.
     *  - `retry`: trying again can work (429, 5xx).
     *
     * @return Result
     */
    private function outcome(Response $res): array
    {
        $status = $res->status();
        $reason = $res->json('reason');
        $reason = is_string($reason) ? $reason : null;
        $dead = $status === 410 || $reason === 'Unregistered' || ($status === 400 && $reason === 'BadDeviceToken' && ! $this->config->forcesEnvironment());

        return [
            'ok' => $status === 200,
            'unregistered' => $status !== 200 && $dead,
            'retry' => $status === 429 || $status >= 500,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /** @return Result */
    private function failure(int $status, string $reason, bool $retry): array
    {
        return ['ok' => false, 'unregistered' => false, 'retry' => $retry, 'status' => $status, 'reason' => $reason];
    }

    /** A short pause before the next try (none after the last one). */
    private function backoff(int $attempt): void
    {
        $ms = (int) config('platform.live_order.apns_backoff_ms', 250);
        if ($ms > 0 && $attempt < 1 + (int) config('platform.live_order.apns_retries', 2)) {
            usleep($ms * 1000 * $attempt);
        }
    }
}
