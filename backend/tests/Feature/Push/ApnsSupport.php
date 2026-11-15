<?php

use App\Domain\Notifications\Models\LiveActivityToken;
use App\Domain\Notifications\Services\ApnsConfig;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/* Shared by the iPhone Live Activity tests: a throw-away Apple key, the saved settings, a fake APNs and the pushes it saw. */

/** A real P-256 key (the kind Apple's .p8 files hold), made once per run: the code under test signs with it. */
function throwawayApnsKey(): string
{
    static $pem = null;
    if ($pem === null) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
    }

    return $pem;
}

/** Saves a complete, switched-on Apple push setup straight through the Settings service (what the dashboard section does). */
function configureApns(bool $enabled = true, string $environment = 'auto'): void
{
    $s = app(Settings::class);
    $s->set(ApnsConfig::TEAM_ID, 'ABCDE12345');
    $s->set(ApnsConfig::KEY_ID, 'FGHIJ67890');
    $s->set(ApnsConfig::BUNDLE_ID, 'qa.albahar.customer');
    $s->set(ApnsConfig::ENVIRONMENT, $environment);
    $s->set(ApnsConfig::PRIVATE_KEY, throwawayApnsKey(), secret: true);
    $s->set(ApnsConfig::ENABLED, $enabled);
}

/**
 * Fakes Apple. `$apns['mode']` is read on every call: ok | gone (410) | bad_token (400 BadDeviceToken) | topic (403 TopicDisallowed) |
 * invalid_key (403 InvalidProviderToken) | expired_once (403 ExpiredProviderToken, then ok) | busy (429) | down (503) | flaky (503, then ok) | offline (no connection).
 *
 * @return ArrayObject<string, mixed>
 */
function fakeApns(): ArrayObject
{
    $apns = new ArrayObject(['mode' => 'ok', 'calls' => 0]);
    $answer = function () use ($apns) {
        $apns['calls']++;

        return match ($apns['mode']) {
            'offline' => throw new ConnectionException('Apple is not reachable'),
            'gone' => Http::response(['reason' => 'Unregistered', 'timestamp' => 1], 410),
            'bad_token' => Http::response(['reason' => 'BadDeviceToken'], 400),
            'topic' => Http::response(['reason' => 'TopicDisallowed'], 403),
            'bad_topic' => Http::response(['reason' => 'BadTopic'], 400),
            'invalid_key' => Http::response(['reason' => 'InvalidProviderToken'], 403),
            'clock' => Http::response(['reason' => 'ExpiredProviderToken'], 403),
            'expired_once' => $apns['calls'] === 1 ? Http::response(['reason' => 'ExpiredProviderToken'], 403) : Http::response('', 200),
            'busy' => Http::response(['reason' => 'TooManyRequests'], 429),
            'down' => Http::response(['reason' => 'ServiceUnavailable'], 503),
            'flaky' => $apns['calls'] === 1 ? Http::response(['reason' => 'ServiceUnavailable'], 503) : Http::response('', 200),
            default => Http::response('', 200),
        };
    };
    Http::fake(['api.push.apple.com/*' => $answer, 'api.sandbox.push.apple.com/*' => $answer]);

    return $apns;
}

/** The pushes sent to Apple so far, oldest first: url, HTTP version, headers, the whole body and its `aps` part. @return list<array{url: string, version: string, headers: array<string, mixed>, body: array<string, mixed>, aps: array<string, mixed>}> */
function apnsSent(): array
{
    return Http::recorded(fn ($request) => str_contains($request->url(), 'push.apple.com'))->map(fn ($pair) => [
        'url' => $pair[0]->url(), 'version' => $pair[0]->toPsrRequest()->getProtocolVersion(), 'headers' => $pair[0]->headers(), 'body' => $pair[0]->data(), 'aps' => $pair[0]->data()['aps'] ?? [],
    ])->values()->all();
}

/** A Live Activity token for an order (or a push-to-start token when the order is null). */
function activityToken(string $userId, ?Order $order, string $token, array $attrs = []): LiveActivityToken
{
    return LiveActivityToken::create($attrs + [
        'user_id' => $userId, 'order_id' => $order?->id, 'kind' => $order === null ? 'start' : 'activity', 'token' => $token,
        'environment' => 'production', 'locale' => 'en', 'last_seen_at' => now(),
    ]);
}

/** 64 hex characters, one per seed. */
function hexToken(string $seed): string
{
    return substr(hash('sha256', $seed), 0, 64);
}

/** Verifies an ES256 JWT (the signature is two 32-byte numbers) with the public half of a key. */
function verifyEs256(string $jwt, string $privatePem): bool
{
    [$h, $p, $sig] = explode('.', $jwt);
    $raw = base64_decode(strtr($sig, '-_', '+/'));
    if (strlen($raw) !== 64) {
        return false;
    }
    $int = function (string $n) {
        $n = ltrim($n, "\x00");
        $n = ($n === '' || ord($n[0]) > 0x7F) ? "\x00".$n : $n;

        return "\x02".chr(strlen($n)).$n;
    };
    $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));
    $der = "\x30".chr(strlen($body)).$body;
    $public = openssl_pkey_get_details(openssl_pkey_get_private($privatePem))['key'];

    return openssl_verify($h.'.'.$p, $der, $public, OPENSSL_ALGO_SHA256) === 1;
}
