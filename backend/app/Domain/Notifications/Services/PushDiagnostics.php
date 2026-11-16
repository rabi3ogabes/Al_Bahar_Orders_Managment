<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Models\PushDiagnostic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The reports the phone apps send about their own push set-up (POST /api/v1/push-diagnostics) and the numbers the dashboard reads back.
 *
 * A phone that cannot start Firebase or get a token cannot be pushed to, so this is the only way it can say what went wrong. A report is a
 * stage (the step that failed), a short machine code, a clipped message, and the app, version and install id. It never holds a person, a
 * token, an API key or an IP address: the message is scrubbed here again even though the app already does it (a build is not trusted).
 * One row per install, app, stage and code per day; a repeat only raises `count`.
 */
class PushDiagnostics
{
    public const APPS = ['customer', 'driver', 'staff'];

    public const PLATFORMS = ['android', 'ios'];

    /** In the order a phone goes through them; `registered` is the good news. */
    public const STAGES = ['options', 'init', 'permission', 'token', 'register', 'registered'];

    /** The report that says a phone's token reached the server. */
    public const OK_STAGE = 'registered';

    /** How long the dashboard keeps a phone's failure in view, and how long rows stay in the table (days). */
    public const PROBLEM_DAYS = 7;

    public const REGISTERED_DAYS = 30;

    public const KEEP_DAYS = 30;

    /** Longest message stored (characters). */
    public const MESSAGE_MAX = 200;

    /** A machine code: lowercase words joined by underscores, so a build newer than this server can still be stored. */
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_.-]{1,47}$/';

    /**
     * Stores one report. Never throws on a repeat: the same install reporting the same thing on the same day only counts up.
     *
     * @param  array{app: string, platform: string, stage: string, code: string, message?: ?string, app_version?: ?string, os_version?: ?string, install_id: string}  $d  already validated
     */
    public function record(array $d): void
    {
        $now = now();
        $values = [
            'id' => (string) Str::uuid7(),
            'app' => $d['app'], 'platform' => $d['platform'], 'stage' => $d['stage'], 'code' => $d['code'],
            'message' => self::scrub($d['message'] ?? null),
            'app_version' => self::scrub($d['app_version'] ?? null, 32),
            'os_version' => self::scrub($d['os_version'] ?? null, 64),
            'install_id' => $d['install_id'],
            'report_day' => $now->clone()->utc()->toDateString(),
            'count' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('push_diagnostics')->upsert([$values], ['install_id', 'app', 'stage', 'code', 'report_day'], [
            'count' => DB::raw('push_diagnostics.count + 1'),
            'last_seen_at' => $now, 'updated_at' => $now,
            'message' => $values['message'], 'app_version' => $values['app_version'], 'os_version' => $values['os_version'], 'platform' => $d['platform'],
        ]);
    }

    /**
     * Text from a phone made safe to keep and to show: whitespace collapsed, anything that looks like an API key, a token, an e-mail
     * address or a long number replaced, query strings cut, and the whole clipped to $max characters.
     */
    public static function scrub(?string $text, int $max = self::MESSAGE_MAX): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = (string) preg_replace('/\s+/u', ' ', trim($text));
        $text = (string) preg_replace('/AIza[0-9A-Za-z_-]{10,}/', '[key]', $text);
        $text = (string) preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', '[email]', $text);
        $text = (string) preg_replace('/\?[^\s]*/', '?…', $text);
        $text = (string) preg_replace('/[A-Za-z0-9_:-]{32,}/', '[token]', $text);
        $text = (string) preg_replace('/\+?\d{8,}/', '[number]', $text);
        $text = trim($text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * What is wrong right now, grouped by app, stage and code: how many phones, how many reports, when it was last seen and the latest
     * message. A phone that reported a failure and then registered fine afterwards is not counted (it got better).
     *
     * @return list<array{app: string, stage: string, code: string, phones: int, reports: int, last_seen_at: string, message: ?string, platform: string, app_version: ?string}>
     */
    public function problems(int $days = self::PROBLEM_DAYS): array
    {
        $rows = DB::select(<<<'SQL'
            select d.app, d.stage, d.code,
                   count(distinct d.install_id) as phones, sum(d.count) as reports, max(d.last_seen_at) as last_seen_at,
                   (array_agg(d.message order by d.last_seen_at desc))[1] as message,
                   (array_agg(d.platform order by d.last_seen_at desc))[1] as platform,
                   (array_agg(d.app_version order by d.last_seen_at desc))[1] as app_version
            from push_diagnostics d
            where d.stage <> ? and d.last_seen_at >= ?
              and not exists (
                  select 1 from push_diagnostics r
                  where r.install_id = d.install_id and r.app = d.app and r.stage = ? and r.last_seen_at > d.last_seen_at
              )
            group by d.app, d.stage, d.code
            order by max(d.last_seen_at) desc
            limit 50
        SQL, [self::OK_STAGE, now()->subDays($days), self::OK_STAGE]);

        return array_map(fn (object $r) => [
            'app' => $r->app, 'stage' => $r->stage, 'code' => $r->code, 'phones' => (int) $r->phones, 'reports' => (int) $r->reports,
            'last_seen_at' => (string) $r->last_seen_at, 'message' => $r->message, 'platform' => (string) $r->platform, 'app_version' => $r->app_version,
        ], $rows);
    }

    /**
     * The phones that got a token to the server: per app and platform, how many installs and when the latest one reported.
     *
     * @return array<string, array<string, array{phones: int, last_at: string}>> app => platform => numbers
     */
    public function registered(int $days = self::REGISTERED_DAYS): array
    {
        $rows = PushDiagnostic::where('stage', self::OK_STAGE)->where('last_seen_at', '>=', now()->subDays($days))
            ->selectRaw('app, platform, count(distinct install_id) as phones, max(last_seen_at) as last_at')->groupBy('app', 'platform')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->app][$r->platform] = ['phones' => (int) $r->getAttribute('phones'), 'last_at' => (string) $r->getAttribute('last_at')];
        }

        return $out;
    }

    /** Rows older than $days are removed (called by push:prune-devices). */
    public function prune(int $days = self::KEEP_DAYS): int
    {
        return PushDiagnostic::where('last_seen_at', '<', now()->subDays(max(1, $days)))->delete();
    }
}
