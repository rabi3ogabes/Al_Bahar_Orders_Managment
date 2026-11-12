<?php

namespace App\Domain\AppUpdates\Services;

use App\Domain\AppUpdates\Models\AppRelease;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Support\DomainException;
use Illuminate\Support\Facades\Cache;

/**
 * "Update required" rules for the store apps. An app calls evaluate() with its build number:
 *   required  → build < min_build   (the app shows a screen it cannot leave; the API answers 426 too)
 *   available → build < latest_build (a polite, dismissible prompt)
 *   ok        → nothing to do.
 * "Force update now" simply raises min_build to the newest build; "Stop forcing" lowers it to 0.
 */
class AppUpdateService
{
    private const DEFAULT_EN = 'A new version of Al Bahar is available. Please update to continue.';

    private const DEFAULT_AR = 'يتوفر إصدار جديد من البحار. يرجى التحديث للمتابعة.';

    public function __construct(private readonly AuditLogger $audit) {}

    public function release(string $app, string $platform): ?AppRelease
    {
        return AppRelease::where('app', $app)->where('platform', $platform)->first();
    }

    /**
     * The rule as plain numbers and text, cached for a minute. Never cache the Eloquent model itself: the cache store cannot
     * bring a model back (it would return an incomplete object and every API call of a store app would fail).
     *
     * @return array{latest_version: ?string, latest_build: int, min_build: int, store_url: ?string, message_en: ?string, message_ar: ?string}|null
     */
    private function rule(string $app, string $platform): ?array
    {
        $rule = Cache::remember("app_release:v2:$app:$platform", 60, function () use ($app, $platform) {
            $r = $this->release($app, $platform);

            return $r ? ['latest_version' => $r->latest_version, 'latest_build' => (int) $r->latest_build, 'min_build' => (int) $r->min_build,
                'store_url' => $r->store_url, 'message_en' => $r->message_en, 'message_ar' => $r->message_ar] : [];
        });

        return $rule === [] ? null : $rule;
    }

    /** @return array<string, mixed> */
    public function evaluate(string $app, string $platform, int $build): array
    {
        $r = $this->rule($app, $platform);
        $status = 'ok';
        if ($r && $r['min_build'] > 0 && $build < $r['min_build']) {
            $status = 'required';
        } elseif ($r && $r['latest_build'] > 0 && $build < $r['latest_build']) {
            $status = 'available';
        }

        return [
            'status' => $status,
            'latest_version' => $r['latest_version'] ?? null, 'latest_build' => $r['latest_build'] ?? 0, 'min_build' => $r['min_build'] ?? 0,
            'store_url' => $r['store_url'] ?? null,
            'message' => ['en' => ($r['message_en'] ?? null) ?: self::DEFAULT_EN, 'ar' => ($r['message_ar'] ?? null) ?: self::DEFAULT_AR],
        ];
    }

    /**
     * @param  array{latest_version?: ?string, latest_build: int, min_build: int, store_url?: ?string, message_en?: ?string, message_ar?: ?string}  $data
     */
    public function save(AppRelease $r, array $data, User $by): AppRelease
    {
        $data += ['latest_version' => null, 'store_url' => null, 'message_en' => null, 'message_ar' => null];
        if ($data['min_build'] > $data['latest_build']) {
            throw DomainException::make('app_min_above_latest');
        }
        if (! empty($data['store_url']) && ! preg_match('#^https://#i', $data['store_url'])) {
            throw DomainException::make('app_store_url_invalid');
        }
        $before = $r->only(['latest_version', 'latest_build', 'min_build', 'store_url']);
        $forcing = $data['min_build'] > 0 && $data['min_build'] > $r->min_build;
        $r->fill([
            'latest_version' => $data['latest_version'] ?: null, 'latest_build' => $data['latest_build'], 'min_build' => $data['min_build'],
            'store_url' => $data['store_url'] ?: null, 'message_en' => $data['message_en'] ?: null, 'message_ar' => $data['message_ar'] ?: null,
            'updated_by' => $by->id,
        ]);
        if ($forcing) {
            $r->forced_at = now();
        }
        if ($data['min_build'] === 0) {
            $r->forced_at = null;
        }
        $r->save();
        $this->forget($r);
        $this->audit->log('app_update.saved', $r, $before, $r->only(['latest_version', 'latest_build', 'min_build', 'store_url']), ['app' => $r->app, 'platform' => $r->platform]);

        return $r;
    }

    /** Everyone below the newest build must update (needs a newest build and a store link to send them to). */
    public function forceNow(AppRelease $r, User $by): AppRelease
    {
        if ($r->latest_build <= 0) {
            throw DomainException::make('app_no_latest_build');
        }
        if (! $r->store_url) {
            throw DomainException::make('app_no_store_url');
        }

        return $this->save($r, ['latest_version' => $r->latest_version, 'latest_build' => $r->latest_build, 'min_build' => $r->latest_build,
            'store_url' => $r->store_url, 'message_en' => $r->message_en, 'message_ar' => $r->message_ar], $by);
    }

    public function stopForcing(AppRelease $r, User $by): AppRelease
    {
        return $this->save($r, ['latest_version' => $r->latest_version, 'latest_build' => $r->latest_build, 'min_build' => 0,
            'store_url' => $r->store_url, 'message_en' => $r->message_en, 'message_ar' => $r->message_ar], $by);
    }

    private function forget(AppRelease $r): void
    {
        Cache::forget("app_release:v2:{$r->app}:{$r->platform}");
    }
}
