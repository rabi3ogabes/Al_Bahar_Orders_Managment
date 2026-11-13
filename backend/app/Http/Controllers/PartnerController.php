<?php

namespace App\Http\Controllers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Services\DriverPosition;
use App\Domain\Orders\Services\LiveOrderFeed;
use App\Domain\Partners\Services\PartnerAccess;
use App\Domain\Partners\Services\PartnerReport;
use App\Support\BusinessTime;
use App\Support\MapConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Partner statistics page: /partner/{token}. Unknown or disabled links answer 404 (nothing to learn); the password is
 * checked with a throttle; the data endpoints answer 401 until the session is unlocked.
 */
class PartnerController extends Controller
{
    private const SESSION_KEY = 'partner_unlocked';

    public function __construct(private readonly PartnerAccess $access, private readonly PartnerReport $report, private readonly LiveOrderFeed $feed) {}

    public function show(Request $request, string $token): Response
    {
        $this->guardToken($token);
        $unlocked = $this->unlocked($request);

        $view = ['token' => $token, 'today' => BusinessTime::now()->toDateString()];
        if ($unlocked) {
            // The maps read the same configuration as every other client; branch pins are public shop locations.
            $view['mapConfig'] = MapConfig::payload();
            $view['branchPins'] = $this->branchPins();
        }

        return response()->view($unlocked ? 'partner.dashboard' : 'partner.lock', $view)
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'strict-origin');
    }

    public function unlock(Request $request, string $token): JsonResponse
    {
        $this->guardToken($token);
        $key = 'partner-unlock:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return response()->json(['message' => 'too_many', 'retry_after' => RateLimiter::availableIn($key)], 429);
        }
        $data = $request->validate(['password' => 'required|string|max:200']);
        if (! $this->access->passwordMatches($data['password'])) {
            RateLimiter::hit($key, 300);
            $this->access->log('failed', $request->ip(), $request->userAgent());

            return response()->json(['message' => 'wrong_password'], 422);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, ['fp' => $this->access->fingerprint(), 'until' => now()->addHours(12)->timestamp]);
        $this->access->log('unlocked', $request->ip(), $request->userAgent());

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
        $this->guardData($request, $token);
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? BusinessTime::now()->toDateString();

        return $this->json($this->report->day($date));
    }

    public function order(Request $request, string $token, string $id): JsonResponse
    {
        $this->guardData($request, $token);
        abort_unless(Str::isUuid($id), 404);
        $detail = $this->report->order($id);
        abort_if($detail === null, 404);
        $this->access->log('viewed_order', $request->ip(), $request->userAgent());

        return $this->json($detail);
    }

    public function fleet(Request $request, string $token): JsonResponse
    {
        $this->guardData($request, $token);

        return $this->json(['drivers' => $this->report->fleet(), 'at' => BusinessTime::iso(now()), 'stale_after_seconds' => DriverPosition::staleAfter()]);
    }

    /** The recorded routes of one business day, for the replay on the partner map. */
    public function routes(Request $request, string $token): JsonResponse
    {
        $this->guardData($request, $token);
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? BusinessTime::now()->toDateString();

        return $this->json(['date' => $date, 'trips' => $this->report->routes($date)]);
    }

    /** New orders and order status changes since the cursor (Unix ms) for the live toasts; without a cursor it only hands one out. */
    public function events(Request $request, string $token): JsonResponse
    {
        $this->guardData($request, $token);
        $since = $request->validate(['since' => 'nullable|integer|min:0|max:99999999999999'])['since'] ?? null;

        return $this->json($this->feed->since($since === null ? null : (int) $since));
    }

    /** @return list<array{id: string, name_en: string, name_ar: string, lat: float, lng: float}> */
    private function branchPins(): array
    {
        return Branch::active()->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('sort_order')->get(['id', 'name_en', 'name_ar', 'latitude', 'longitude'])
            ->map(fn ($b) => ['id' => $b->id, 'name_en' => $b->name_en, 'name_ar' => $b->name_ar ?: $b->name_en, 'lat' => (float) $b->latitude, 'lng' => (float) $b->longitude])->all();
    }

    private function json(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    private function guardToken(string $token): void
    {
        abort_unless($this->access->tokenMatches($token), 404);
    }

    private function guardData(Request $request, string $token): void
    {
        $this->guardToken($token);
        abort_unless($this->unlocked($request), 401);
    }

    private function unlocked(Request $request): bool
    {
        $s = $request->session()->get(self::SESSION_KEY);

        return is_array($s) && hash_equals($this->access->fingerprint(), (string) ($s['fp'] ?? '')) && ($s['until'] ?? 0) > time();
    }
}
