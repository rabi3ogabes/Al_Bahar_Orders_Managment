<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\UserDevice;
use App\Domain\Settings\Services\Settings;

/**
 * "Is push going to work on this phone?", answered per phone app from what is saved in the dashboard, what the phones themselves reported
 * ({@see PushDiagnostics}) and how many devices are registered. Every check is one plain sentence the page can show in either language
 * (`push_health.check.<key>.<level>`). Nothing here talks to Google: that is what Test connection and the test notification are for.
 *
 * Levels: `ok`, `warn` (works, but look at it) and `fail` (this app cannot register or receive as things stand).
 */
class PushHealth
{
    public const APP_ID_PATTERN = ['android' => '/^1:(\d{6,20}):android:[0-9a-f]{8,40}$/i', 'ios' => '/^1:(\d{6,20}):ios:[0-9a-f]{8,40}$/i'];

    public const API_KEY_PATTERN = '/^AIza[0-9A-Za-z_-]{35}$/';

    public function __construct(
        private readonly Settings $settings,
        private readonly FirebaseConfig $config,
        private readonly PushDiagnostics $diagnostics,
    ) {}

    /**
     * One entry per phone app (Android customer, driver, staff and iPhone customer).
     *
     * @return list<array{platform: string, app: string, package: string, app_id: ?string, level: string, checks: list<array{key: string, level: string, params: array<string, string>}>, devices: int, phones: int, last_registered_at: ?string, problems: list<array<string, mixed>>}>
     */
    public function phoneApps(): array
    {
        $ids = [];
        foreach (FirebaseConfig::NATIVE_APPS as $platform => $apps) {
            foreach ($apps as $app) {
                $ids[$platform][$app] = $this->text(FirebaseConfig::nativeAppKey($platform, $app));
            }
        }
        $duplicates = self::duplicates($ids);
        $sender = $this->text(FirebaseConfig::SENDER_ID);
        $numbers = array_values(array_unique(array_filter(array_map(fn (?string $id) => $id !== null && preg_match('/^1:(\d+):/', $id, $m) ? $m[1] : null, array_merge(...array_values($ids))))));
        $registered = $this->diagnostics->registered();
        $problems = $this->diagnostics->problems();
        $counts = $this->deviceCounts();
        $serverOk = $this->config->enabled() && $this->config->hasServerCredentials();

        $out = [];
        foreach ($ids as $platform => $apps) {
            foreach ($apps as $app => $appId) {
                $checks = [
                    $this->serverCheck($serverOk),
                    $this->appIdCheck($platform, $appId),
                    $this->apiKeyCheck($this->text(FirebaseConfig::nativeApiKey($platform))),
                    $this->numberCheck($appId, $sender, $numbers),
                    $this->uniqueCheck($appId, $duplicates, $platform, $app),
                ];
                $phones = $registered[$app][$platform] ?? null;
                $mine = array_values(array_filter($problems, fn (array $p) => $p['app'] === $app && $p['platform'] === $platform));
                $checks[] = $this->phonesCheck($phones, $mine);
                // The iPhone app may simply not exist yet: nothing saved for it at all is "not set up", not a failure.
                $untouched = $platform === 'ios' && $appId === null && $this->text(FirebaseConfig::nativeApiKey('ios')) === null;
                $out[] = [
                    'platform' => $platform, 'app' => $app, 'package' => FirebaseConfig::PACKAGES[$app], 'app_id' => $appId,
                    'level' => $untouched ? 'off' : self::worst($checks), 'checks' => $checks,
                    'devices' => $counts[$platform][$app] ?? 0, 'phones' => $phones['phones'] ?? 0, 'last_registered_at' => $phones['last_at'] ?? null,
                    'problems' => $mine,
                ];
            }
        }

        return $out;
    }

    /**
     * App IDs that two or more phone apps share. Every app needs its own: each Android package (and the iPhone bundle) is a separate app in
     * Firebase, and the key restriction, the token and the delivery all follow that app.
     *
     * @param  array<string, array<string, ?string>>  $ids  platform => app => app id
     * @return array<string, list<string>> app id => the "platform:app" entries that hold it (only those held more than once)
     */
    public static function duplicates(array $ids): array
    {
        $by = [];
        foreach ($ids as $platform => $apps) {
            foreach ($apps as $app => $id) {
                if ($id !== null && trim($id) !== '') {
                    $by[strtolower(trim($id))][] = "$platform:$app";
                }
            }
        }

        return array_filter($by, fn (array $holders) => count($holders) > 1);
    }

    /** The lowest level of a set of checks. @param list<array{level: string}> $checks */
    public static function worst(array $checks): string
    {
        $levels = array_column($checks, 'level');

        return in_array('fail', $levels, true) ? 'fail' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }

    /** The sentence a check says, in the current language. @param array{key: string, level: string, params: array<string, string>} $check */
    public static function sentence(array $check): string
    {
        $key = "push_health.check.{$check['key']}.{$check['level']}";
        if (isset($check['params']['problem'])) {
            $key .= '.'.$check['params']['problem'];
        } elseif ($check['key'] === 'phones' && $check['level'] === 'warn' && isset($check['params']['code'])) {
            $key = 'push_health.check.phones.warn_with_phones';
        }

        return __($key, $check['params']);
    }

    /** Whether an app ID looks like the one Firebase hands out for this platform. */
    public static function appIdProblem(string $platform, ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return 'missing';
        }

        return preg_match(self::APP_ID_PATTERN[$platform] ?? self::APP_ID_PATTERN['android'], trim($value)) === 1 ? null : 'format';
    }

    /** @return array{key: string, level: string, params: array<string, string>} */
    private function check(string $key, string $level, array $params = []): array
    {
        return ['key' => $key, 'level' => $level, 'params' => $params];
    }

    private function serverCheck(bool $ok): array
    {
        return $this->check('server', $ok ? 'ok' : 'fail');
    }

    private function appIdCheck(string $platform, ?string $appId): array
    {
        $problem = self::appIdProblem($platform, $appId);

        return $this->check('app_id', $problem === null ? 'ok' : 'fail', $problem === null ? [] : ['problem' => $problem]);
    }

    private function apiKeyCheck(?string $key): array
    {
        if ($key === null) {
            return $this->check('api_key', 'fail', ['problem' => 'missing']);
        }

        return preg_match(self::API_KEY_PATTERN, $key) === 1 ? $this->check('api_key', 'ok') : $this->check('api_key', 'fail', ['problem' => 'format']);
    }

    /** @param list<string> $numbers the project numbers found in the saved app IDs */
    private function numberCheck(?string $appId, ?string $sender, array $numbers): array
    {
        if ($appId === null || ! preg_match('/^1:(\d+):/', $appId, $m)) {
            return $this->check('project_number', 'ok');
        }
        if ($sender !== null && $m[1] !== $sender) {
            return $this->check('project_number', 'fail', ['number' => $m[1], 'sender' => $sender]);
        }

        return count($numbers) > 1 ? $this->check('project_number', 'fail', ['number' => $m[1], 'sender' => implode(', ', $numbers)]) : $this->check('project_number', 'ok');
    }

    /** @param array<string, list<string>> $duplicates */
    private function uniqueCheck(?string $appId, array $duplicates, string $platform, string $app): array
    {
        $holders = $appId !== null ? ($duplicates[strtolower(trim($appId))] ?? []) : [];
        $others = array_values(array_diff($holders, ["$platform:$app"]));
        if ($others === []) {
            return $this->check('unique', 'ok');
        }

        $name = function (string $holder): string {
            [$platform, $app] = explode(':', $holder);

            return __('push_health.reports.app.'.$app).' ('.__('push_health.reports.platform.'.$platform).')';
        };

        return $this->check('unique', 'fail', ['others' => implode(', ', array_map($name, $others))]);
    }

    /**
     * What the phones themselves say: registered phones (good), or the failures they reported, or silence (nobody has opened this app
     * since the settings were saved, which is the case that looks like "push does not work" from the outside).
     *
     * @param  array{phones: int, last_at: string}|null  $phones
     * @param  list<array<string, mixed>>  $problems
     */
    private function phonesCheck(?array $phones, array $problems): array
    {
        if ($phones !== null && $problems === []) {
            return $this->check('phones', 'ok', ['phones' => (string) $phones['phones']]);
        }
        if ($problems !== []) {
            return $this->check('phones', $phones !== null ? 'warn' : 'fail', ['code' => (string) $problems[0]['code']]);
        }

        return $this->check('phones', 'warn');
    }

    /** @return array<string, array<string, int>> platform => app => active devices */
    private function deviceCounts(): array
    {
        $out = [];
        $rows = UserDevice::whereNull('disabled_at')->whereIn('platform', ['android', 'ios'])->selectRaw('platform, coalesce(app, \'\') as app, count(*) as n')->groupBy('platform', 'app')->get();
        foreach ($rows as $r) {
            $out[$r->platform][$r->app] = (int) $r->n;
        }

        return $out;
    }

    private function text(string $key): ?string
    {
        $v = $this->settings->get($key);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
