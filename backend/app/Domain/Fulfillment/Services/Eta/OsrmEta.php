<?php

namespace App\Domain\Fulfillment\Services\Eta;

use App\Support\MapConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road-based ETA from an OSRM-compatible routing server (admin setting map.routing_url). Hard 3 s limit; after any failure
 * the server is left alone for a minute so a dead router cannot slow every tracking request.
 */
class OsrmEta implements EtaEstimator
{
    private const PAUSE_KEY = 'eta:osrm:paused';

    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $base = MapConfig::routingUrl();
        if ($base === null || Cache::has(self::PAUSE_KEY)) {
            return null;
        }
        $path = sprintf('%s/route/v1/driving/%s,%s;%s,%s', $base, $this->n($fromLng), $this->n($fromLat), $this->n($toLng), $this->n($toLat));
        try {
            $response = Http::timeout((int) config('platform.tracking.eta_timeout_seconds', 3))->connectTimeout(2)->withoutRedirecting()->acceptJson()
                ->get($path, ['overview' => 'false', 'alternatives' => 'false', 'steps' => 'false']);
            $route = $response->json('routes.0');
            if ($response->successful() && $response->json('code') === 'Ok' && is_array($route) && is_numeric($route['duration'] ?? null) && is_numeric($route['distance'] ?? null)) {
                return ['minutes' => max(1, (int) ceil((float) $route['duration'] / 60)), 'distance_m' => (int) round((float) $route['distance'])];
            }
            $why = 'http '.$response->status();
        } catch (Throwable $e) {
            $why = $e::class;
        }
        Cache::put(self::PAUSE_KEY, 1, 60);
        Log::warning('routing service unavailable, using the straight-line estimate for a minute', ['reason' => $why]);

        return null;
    }

    public function source(): string
    {
        return 'osrm';
    }

    private function n(float $v): string
    {
        return number_format($v, 6, '.', '');
    }
}
