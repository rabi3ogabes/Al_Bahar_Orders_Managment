<?php

use App\Domain\Notifications\Services\ServiceAccountKey;
use App\Support\BrowserFamily;

require_once __DIR__.'/PushSupport.php';

it('accepts a real key file for the matching project and keeps only the fields it needs', function () {
    $r = ServiceAccountKey::check(fakeServiceAccountJson(), 'al-bahar-home');

    expect($r['ok'])->toBeTrue()
        ->and(array_keys($r['key']))->toBe(['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id'])
        ->and(json_encode($r['key']))->not->toContain('evil.example')->not->toContain('auth_uri');
});

it('explains every way a key file can be wrong', function (string $raw, string $error, ?string $project = 'al-bahar-home') {
    expect(ServiceAccountKey::check($raw, $project))->toBe(['ok' => false, 'error' => $error]);
})->with([
    'empty' => ['  ', 'empty'],
    'too big' => [str_repeat('a', ServiceAccountKey::MAX_BYTES + 1), 'too_large'],
    'not json' => ['{oops', 'not_json'],
    'a list' => ['[1,2]', 'not_json'],
    'plain text' => ['hello', 'not_json'],
    'a web app config, not a key' => ['{"type":"authorized_user","project_id":"al-bahar-home"}', 'wrong_type'],
    'no type' => ['{"project_id":"al-bahar-home"}', 'wrong_type'],
    'no email' => [json_encode(['type' => 'service_account', 'project_id' => 'al-bahar-home', 'private_key' => '-----BEGIN PRIVATE KEY-----x']), 'missing_email'],
    'bad email' => [json_encode(['type' => 'service_account', 'project_id' => 'al-bahar-home', 'client_email' => 'nope', 'private_key' => 'PRIVATE KEY']), 'missing_email'],
    'no private key' => [json_encode(['type' => 'service_account', 'project_id' => 'al-bahar-home', 'client_email' => 'a@b.iam.gserviceaccount.com']), 'missing_key'],
    'no project' => [json_encode(['type' => 'service_account', 'client_email' => 'a@b.iam.gserviceaccount.com', 'private_key' => 'PRIVATE KEY']), 'missing_project'],
    'damaged key' => [json_encode(['type' => 'service_account', 'project_id' => 'al-bahar-home', 'client_email' => 'a@b.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n"]), 'bad_key'],
]);

it('rejects a key from another project', function () {
    expect(ServiceAccountKey::check(fakeServiceAccountJson('other-project'), 'al-bahar-home'))->toBe(['ok' => false, 'error' => 'project_mismatch']);
    expect(ServiceAccountKey::check(fakeServiceAccountJson('other-project'))['ok'])->toBeTrue(); // no project to compare with
});

it('names the browser family from a user agent and keeps nothing else', function (?string $ua, ?string $expected) {
    expect(BrowserFamily::from($ua))->toBe($expected);
})->with([
    'chrome on android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', 'Chrome on Android'],
    'safari on iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari on iPhone'],
    'chrome on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.0.0 Mobile/15E148 Safari/604.1', 'Chrome on iPhone'],
    'firefox on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0', 'Firefox on Windows'],
    'edge on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0', 'Edge on Windows'],
    'samsung internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Samsung Internet on Android'],
    'safari on mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari on Mac'],
    'opera' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 OPR/111.0.0.0', 'Opera on Windows'],
    'unknown' => ['curl/8.0', null],
    'empty' => ['', null],
    'null' => [null, null],
]);
