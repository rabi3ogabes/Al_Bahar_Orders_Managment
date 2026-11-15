<?php

use App\Domain\Notifications\Services\ApnsClient;
use App\Domain\Notifications\Services\ApnsConfig;
use App\Domain\Notifications\Services\ApnsKey;
use App\Domain\Settings\Services\Settings;

require_once __DIR__.'/ApnsSupport.php';

beforeEach(function () {
    config(['platform.live_order.apns_backoff_ms' => 0]); // no real waiting between retries
    configureApns();
    $this->apns = fakeApns();
    $this->client = fn () => app(ApnsClient::class);
    $this->aps = ['timestamp' => 1794668400, 'event' => 'update', 'content-state' => ['phase' => 'preparing']];
});

// ── The key ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('accepts a P-256 key as the .p8 file, as plain text without its lines, and with Windows line ends', function () {
    $pem = throwawayApnsKey();
    $body = trim(preg_replace('/-----[A-Z ]+-----/', '', $pem));

    expect(ApnsKey::check($pem)['ok'])->toBeTrue()
        ->and(ApnsKey::check($body)['ok'])->toBeTrue()
        ->and(ApnsKey::check(str_replace("\n", "\r\n", $pem))['ok'])->toBeTrue()
        ->and(ApnsKey::check($body)['pem'])->toContain('-----BEGIN PRIVATE KEY-----')->toContain('-----END PRIVATE KEY-----');
});

it('explains every way a key can be wrong', function (string $raw, string $error) {
    expect(ApnsKey::check($raw))->toBe(['ok' => false, 'error' => $error]);
})->with([
    'empty' => ['  ', 'empty'],
    'too big' => [str_repeat('a', ApnsKey::MAX_BYTES + 1), 'too_large'],
    'plain text' => ['hello there', 'bad_key'],
    'damaged key' => ["-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----", 'bad_key'],
]);

it('refuses an RSA key: Apple takes P-256 only', function () {
    $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsa, $pem);

    expect(ApnsKey::check($pem))->toBe(['ok' => false, 'error' => 'not_p256']);
});

// ── The provider token (JWT) ─────────────────────────────────────────────────────────────────────────────────────────

it('signs an ES256 token that names the key and the team, and that the key\'s public half verifies', function () {
    $jwt = ($this->client)()->jwt();
    [$h, $p] = array_map(fn ($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true), array_slice(explode('.', $jwt), 0, 2));

    expect($h)->toBe(['alg' => 'ES256', 'kid' => 'FGHIJ67890'])
        ->and($p['iss'])->toBe('ABCDE12345')->and($p['iat'])->toBeInt()->toBeGreaterThan(time() - 5)
        ->and(verifyEs256($jwt, throwawayApnsKey()))->toBeTrue();
});

it('keeps the token for 40 minutes (Apple refuses new ones more often than every 20) and makes a new one when told to', function () {
    $first = ($this->client)()->jwt();
    $this->travelTo(now()->addSeconds(2));

    expect(($this->client)()->jwt())->toBe($first)
        ->and(($this->client)()->jwt(fresh: true))->not->toBe($first);
});

it('turns an OpenSSL signature into the two fixed-width numbers a JWT carries, whatever the sizes of R and S', function () {
    // Short R (leading zeros dropped), high bit set (a 0x00 is added) and a long-form sequence length.
    $r = str_repeat("\x00", 3).str_repeat("\x11", 29);
    $der = fn (string $rInt, string $sInt) => "\x30".chr(strlen($rInt) + strlen($sInt) + 4)."\x02".chr(strlen($rInt)).$rInt."\x02".chr(strlen($sInt)).$sInt;

    expect(ApnsClient::derToRaw($der(ltrim($r, "\x00"), "\x00".str_repeat("\xFF", 32))))->toBe(str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT).str_repeat("\xFF", 32))
        ->and(strlen(ApnsClient::derToRaw($der("\x01", "\x02"))))->toBe(64);
    expect(fn () => ApnsClient::derToRaw("\x30\x03\x01\x01\x01"))->toThrow(RuntimeException::class);
});

// ── What is sent ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('sends one Live Activity push over HTTP/2 with the headers and topic Apple requires', function () {
    $res = ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps, priority: 10, expiresAt: 1794672000);

    $sent = apnsSent();
    expect($res)->toBe(['ok' => true, 'unregistered' => false, 'retry' => false, 'status' => 200, 'reason' => null])
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['url'])->toBe('https://api.push.apple.com/3/device/'.hexToken('a'))
        ->and($sent[0]['headers']['apns-push-type'])->toBe(['liveactivity'])
        ->and($sent[0]['headers']['apns-topic'])->toBe(['qa.albahar.customer.push-type.liveactivity'])
        ->and($sent[0]['headers']['apns-priority'])->toBe(['10'])
        ->and($sent[0]['headers']['apns-expiration'])->toBe(['1794672000'])
        ->and($sent[0]['headers']['Authorization'][0])->toStartWith('bearer ')
        ->and($sent[0]['body'])->toBe(['aps' => $this->aps]);
});

it('asks for HTTP/2 (Apple speaks nothing else), also for the Test button', function () {
    ($this->client)()->sendLiveActivity(hexToken('h'), 'production', $this->aps);
    ($this->client)()->check();

    expect(array_column(apnsSent(), 'version'))->toBe(['2.0', '2.0']);
});

it('uses Apple\'s test servers for a sandbox token and the real ones for a production token', function () {
    ($this->client)()->sendLiveActivity(hexToken('s'), 'sandbox', $this->aps);
    ($this->client)()->sendLiveActivity(hexToken('p'), 'production', $this->aps);
    ($this->client)()->sendLiveActivity(hexToken('x'), 'anything-else', $this->aps);

    expect(array_column(apnsSent(), 'url'))->toBe([
        'https://api.sandbox.push.apple.com/3/device/'.hexToken('s'), 'https://api.push.apple.com/3/device/'.hexToken('p'), 'https://api.push.apple.com/3/device/'.hexToken('x'),
    ]);
});

it('uses one Apple host for everything when the dashboard forces it', function () {
    configureApns(environment: 'sandbox');
    ($this->client)()->sendLiveActivity(hexToken('p'), 'production', $this->aps);

    expect(apnsSent()[0]['url'])->toStartWith('https://api.sandbox.push.apple.com/');
});

it('takes the topic from the saved bundle ID', function () {
    app(Settings::class)->set(ApnsConfig::BUNDLE_ID, 'qa.albahar.customer.staging');
    ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps);

    expect(apnsSent()[0]['headers']['apns-topic'])->toBe(['qa.albahar.customer.staging.push-type.liveactivity']);
});

// ── What comes back ──────────────────────────────────────────────────────────────────────────────────────────────────

it('retires a token Apple says is gone: 410, or BadDeviceToken while each token\'s own environment is trusted', function (string $mode, int $status, string $reason) {
    $this->apns['mode'] = $mode;
    $res = ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps);

    expect($res)->toBe(['ok' => false, 'unregistered' => true, 'retry' => false, 'status' => $status, 'reason' => $reason])
        ->and($this->apns['calls'])->toBe(1);                                  // a dead token is not retried
})->with([
    'unregistered' => ['gone', 410, 'Unregistered'],
    'bad device token' => ['bad_token', 400, 'BadDeviceToken'],
]);

it('does not retire a token for a wrong dashboard setting: with a forced environment BadDeviceToken is a configuration problem', function () {
    configureApns(environment: 'production');
    $this->apns['mode'] = 'bad_token';

    expect(($this->client)()->sendLiveActivity(hexToken('a'), 'sandbox', $this->aps)['unregistered'])->toBeFalse();
});

it('never retires a token because of a refused key or topic', function (string $mode, string $reason) {
    $this->apns['mode'] = $mode;
    $res = ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps);

    expect($res['ok'])->toBeFalse()->and($res['unregistered'])->toBeFalse()->and($res['retry'])->toBeFalse()->and($res['reason'])->toBe($reason);
})->with([
    'topic' => ['topic', 'TopicDisallowed'],
    'bad topic' => ['bad_topic', 'BadTopic'],
    'invalid key' => ['invalid_key', 'InvalidProviderToken'],
]);

it('retries a busy or broken Apple a couple of times, then reports that trying later can work', function (string $mode, int $status) {
    $this->apns['mode'] = $mode;
    $res = ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps);

    expect($res['ok'])->toBeFalse()->and($res['retry'])->toBeTrue()->and($res['status'])->toBe($status)->and($res['unregistered'])->toBeFalse()
        ->and($this->apns['calls'])->toBe(3);                                  // one try and two retries (platform.live_order.apns_retries)
})->with([
    'busy' => ['busy', 429],
    'down' => ['down', 503],
]);

it('answers a lost connection without throwing, after the same retries', function () {
    $this->apns['mode'] = 'offline';
    $res = ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps);

    expect($res)->toBe(['ok' => false, 'unregistered' => false, 'retry' => true, 'status' => 0, 'reason' => 'UNAVAILABLE'])->and($this->apns['calls'])->toBe(3);
});

it('does not retry when retries are switched off, and succeeds when Apple recovers in between', function () {
    config(['platform.live_order.apns_retries' => 0]);
    $this->apns['mode'] = 'down';
    ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps);
    expect($this->apns['calls'])->toBe(1);

    config(['platform.live_order.apns_retries' => 2]);
    $this->apns['mode'] = 'flaky';
    $this->apns['calls'] = 0;

    expect(($this->client)()->sendLiveActivity(hexToken('b'), 'production', $this->aps)['ok'])->toBeTrue()->and($this->apns['calls'])->toBe(2);
});

it('replaces a provider token Apple says has expired and sends again, once', function () {
    $this->apns['mode'] = 'expired_once';
    $first = ($this->client)()->jwt();
    $res = ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps);

    expect($res['ok'])->toBeTrue()->and($this->apns['calls'])->toBe(2)
        ->and(apnsSent()[1]['headers']['Authorization'][0])->toStartWith('bearer ');
    // A token that is refused again is not replaced again and again.
    $this->apns['mode'] = 'clock';
    $this->apns['calls'] = 0;
    $res = ($this->client)()->sendLiveActivity(hexToken('b'), 'production', $this->aps);
    expect($res['ok'])->toBeFalse()->and($res['reason'])->toBe('ExpiredProviderToken')->and($this->apns['calls'])->toBe(2)->and($first)->toBeString();
});

it('says "no key" without calling Apple when the key has been removed', function () {
    app(Settings::class)->set(ApnsConfig::PRIVATE_KEY, null, secret: true);
    $res = ($this->client)()->sendLiveActivity(hexToken('a'), 'production', $this->aps);

    expect($res['ok'])->toBeFalse()->and($res['retry'])->toBeFalse()->and($res['reason'])->toBe('NoKey')->and(apnsSent())->toBe([]);
});

// ── The Test button ──────────────────────────────────────────────────────────────────────────────────────────────────

it('proves the setup with the one rejection a perfect setup gets: BadDeviceToken for a phone that does not exist', function () {
    $this->apns['mode'] = 'bad_token';
    $check = ($this->client)()->check();

    expect($check)->toBe(['ok' => true, 'code' => 'ok', 'status' => 400, 'detail' => 'BadDeviceToken'])
        ->and(apnsSent())->toHaveCount(1)
        ->and(apnsSent()[0]['url'])->toBe('https://api.push.apple.com/3/device/'.str_repeat('0', 64))
        ->and(apnsSent()[0]['headers']['apns-topic'])->toBe(['qa.albahar.customer.push-type.liveactivity'])
        ->and(apnsSent()[0]['body']['aps']['event'])->toBe('update');
});

it('says what is wrong in the words the dashboard turns into advice', function (string $mode, string $code) {
    $this->apns['mode'] = $mode;

    expect(($this->client)()->check()['code'])->toBe($code);
})->with([
    'wrong key, key id or team' => ['invalid_key', 'key_rejected'],
    'server clock' => ['clock', 'clock'],
    'topic not allowed' => ['topic', 'topic'],
    'bad topic' => ['bad_topic', 'topic'],
    'too many requests' => ['busy', 'rate_limited'],
    'apple down' => ['down', 'apns_down'],
    'anything else' => ['gone', 'rejected'],
]);

it('tells a lost connection from a PHP that cannot speak HTTP/2', function (bool $http2, string $code) {
    $this->apns['mode'] = 'offline';
    $client = new class(app(ApnsConfig::class), $http2) extends ApnsClient
    {
        public function __construct(ApnsConfig $config, private readonly bool $h2)
        {
            parent::__construct($config);
        }

        protected function http2Supported(): bool
        {
            return $this->h2;
        }
    };

    expect($client->check()['code'])->toBe($code);
})->with([
    'curl without HTTP/2' => [false, 'no_http2'],
    'plain network problem' => [true, 'unreachable'],
]);

it('does not call Apple when nothing is set up', function () {
    foreach ([ApnsConfig::TEAM_ID, ApnsConfig::KEY_ID] as $key) {
        app(Settings::class)->set($key, null);
    }

    expect(($this->client)()->check()['code'])->toBe('not_configured')->and(apnsSent())->toBe([]);
});
