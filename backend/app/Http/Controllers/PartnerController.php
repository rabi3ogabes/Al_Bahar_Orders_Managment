<?php

namespace App\Http\Controllers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Services\DriverPosition;
use App\Domain\Orders\Services\LiveOrderFeed;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Services\PartnerAccess;
use App\Domain\Partners\Services\PartnerLinks;
use App\Domain\Partners\Services\PartnerReport;
use App\Domain\Partners\Services\PartnerScope;
use App\Support\BusinessTime;
use App\Support\MapConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Partner statistics page: /partner/{token}. Unknown or disabled links answer 404 (nothing to learn); a revoked or expired per-partner
 * link answers 403; the password is checked with a throttle (and, per partner link, a lockout); the data endpoints answer 401 until the
 * session is unlocked.
 *
 * Two kinds of link share this page: a per-partner link (the partners table: branch-limited, no personal data, revocable, every visit logged
 * with the partner and a hash of the address) and the OLD shared link (one password for everyone, unchanged, until the owner turns it off).
 */
class PartnerController extends Controller
{
    private const SESSION_KEY = 'partner_unlocked';

    public function __construct(private readonly PartnerAccess $access, private readonly PartnerReport $report, private readonly LiveOrderFeed $feed, private readonly PartnerLinks $links) {}

    public function show(Request $request, string $token): Response
    {
        $partner = $this->guardToken($token, closedView: true);
        if ($partner === false) {
            return response()->view('partner.closed', [], 403)->header('X-Robots-Tag', 'noindex, nofollow, noarchive')->header('Cache-Control', 'no-store');
        }
        $unlocked = $this->unlocked($request, $partner);
        if ($unlocked && $partner) {
            $this->links->log($partner, 'opened', $request->ip(), $request->userAgent(), throttled: true);
        }

        $view = ['token' => $token, 'today' => BusinessTime::now()->toDateString()];
        if ($unlocked) {
            // The maps read the same configuration as every other client; branch pins are public shop locations.
            $view['mapConfig'] = MapConfig::payload();
            $view['branchPins'] = $this->branchPins($partner ? $this->links->scope($partner)->branchIds : null);
        }

        return response()->view($unlocked ? 'partner.dashboard' : 'partner.lock', $view)
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'strict-origin');
    }

    public function unlock(Request $request, string $token): JsonResponse
    {
        $partner = $this->guardToken($token);
        $key = 'partner-unlock:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return response()->json(['message' => 'too_many', 'retry_after' => RateLimiter::availableIn($key)], 429);
        }
        $data = $request->validate(['password' => 'required|string|max:200']);
        if ($partner) {
            $outcome = $this->links->attempt($partner, $data['password']);
            if ($outcome !== 'ok') {
                RateLimiter::hit($key, 300);
                $this->links->log($partner, $outcome === 'locked' ? 'locked_out' : 'failed', $request->ip(), $request->userAgent());

                return $outcome === 'locked'
                    ? response()->json(['message' => 'too_many', 'retry_after' => max(1, (int) now()->diffInSeconds($partner->fresh()?->locked_until ?? now()->addMinutes(PartnerLinks::LOCK_MINUTES)))], 429)
                    : response()->json(['message' => 'wrong_password'], 422);
            }
        } elseif (! $this->access->passwordMatches($data['password'])) {
            RateLimiter::hit($key, 300);
            $this->access->log('failed', $request->ip(), $request->userAgent());

            return response()->json(['message' => 'wrong_password'], 422);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, ['fp' => $partner ? $this->links->fingerprint($partner) : $this->access->fingerprint(), 'until' => now()->addHours(12)->timestamp]);
        $partner ? $this->links->log($partner, 'unlocked', $request->ip(), $request->userAgent()) : $this->access->log('unlocked', $request->ip(), $request->userAgent());

        return response()->json(['ok' => true]);
    }

    public function lock(Request $request, string $token): JsonResponse
    {
        $this->guardToken($token);
        $request->session()->forget(self::SESSION_KEY);

        return response()->json(['ok' => true]);
    }

    public function day(Request $request, string $token): JsonResponse
    {
        [$partner, $scope] = $this->guardData($request, $token);
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? BusinessTime::now()->toDateString();
        $partner && $this->links->log($partner, 'viewed_day', $request->ip(), $request->userAgent(), throttled: true);

        return $this->json($this->report->day($date, $scope));
    }

    public function order(Request $request, string $token, string $id): JsonResponse
    {
        [$partner, $scope] = $this->guardData($request, $token);
        abort_unless(Str::isUuid($id), 404);
        $detail = $this->report->order($id, $scope);
        abort_if($detail === null, 404);
        $partner ? $this->links->log($partner, 'viewed_order', $request->ip(), $request->userAgent()) : $this->access->log('viewed_order', $request->ip(), $request->userAgent());

        return $this->json($detail);
    }

    public function fleet(Request $request, string $token): JsonResponse
    {
        [$partner, $scope] = $this->guardData($request, $token);
        $partner && $this->links->log($partner, 'viewed_live', $request->ip(), $request->userAgent(), throttled: true);

        return $this->json(['drivers' => $this->report->fleet($scope), 'at' => BusinessTime::iso(now()), 'stale_after_seconds' => DriverPosition::staleAfter()]);
    }

    /** The recorded routes of one business day, for the replay on the partner map. */
    public function routes(Request $request, string $token): JsonResponse
    {
        [$partner, $scope] = $this->guardData($request, $token);
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? BusinessTime::now()->toDateString();
        $partner && $this->links->log($partner, 'viewed_routes', $request->ip(), $request->userAgent(), throttled: true);

        return $this->json(['date' => $date, 'trips' => $this->report->routes($date, $scope)]);
    }

    /** New orders and order status changes since the cursor (Unix ms) for the live toasts; without a cursor it only hands one out. */
    public function events(Request $request, string $token): JsonResponse
    {
        [, $scope] = $this->guardData($request, $token);
        $since = $request->validate(['since' => 'nullable|integer|min:0|max:99999999999999'])['since'] ?? null;

        return $this->json($this->feed->since($since === null ? null : (int) $since, $scope?->branchIds, anonymous: $scope !== null));
    }

    /**
     * @param  list<string>|null  $branchIds  null = every branch
     * @return list<array{id: string, name_en: string, name_ar: string, lat: float, lng: float}>
     */
    private function branchPins(?array $branchIds): array
    {
        return Branch::active()->whereNotNull('latitude')->whereNotNull('longitude')->when($branchIds !== null, fn ($q) => $q->whereIn('id', (array) $branchIds))->orderBy('sort_order')->get(['id', 'name_en', 'name_ar', 'latitude', 'longitude'])
            ->map(fn ($b) => ['id' => $b->id, 'name_en' => $b->name_en, 'name_ar' => $b->name_ar ?: $b->name_en, 'lat' => (float) $b->latitude, 'lng' => (float) $b->longitude])->all();
    }

    private function json(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    /**
     * The link must exist: a per-partner link (null = the old shared link, false = a closed link when $closedView, else 403) or the
     * old shared link while it is switched on. Anything else answers 404.
     */
    private function guardToken(string $token, bool $closedView = false): Partner|false|null
    {
        $partner = $this->links->findByToken($token);
        if ($partner !== null) {
            if (! $partner->isOpen()) {
                abort_unless($closedView, 403);

                return false;
            }

            return $partner;
        }
        abort_unless($this->access->tokenMatches($token), 404);

        return null;
    }

    /** @return array{0: Partner|null, 1: PartnerScope|null} */
    private function guardData(Request $request, string $token): array
    {
        $partner = $this->guardToken($token);
        abort_unless($partner instanceof Partner || $partner === null, 403);
        abort_unless($this->unlocked($request, $partner), 401);

        return [$partner, $partner ? $this->links->scope($partner) : null];
    }

    private function unlocked(Request $request, ?Partner $partner): bool
    {
        $s = $request->session()->get(self::SESSION_KEY);
        $fingerprint = $partner ? $this->links->fingerprint($partner) : $this->access->fingerprint();

        return is_array($s) && hash_equals($fingerprint, (string) ($s['fp'] ?? '')) && ($s['until'] ?? 0) > time();
    }
}
