<?php

namespace App\Domain\Fulfillment\Services\Eta;

use App\Support\BusinessTime;
use Illuminate\Support\Facades\Cache;

/**
 * Arrival estimate for a delivery in progress. Road-based when a routing server is configured and answering, otherwise a
 * straight-line estimate, so the answer never fails and never waits on a slow server for long. The result is kept for 30 s
 * per delivery: a customer polling every few seconds costs one calculation per half minute.
 */
class EtaService
{
    /** @var list<EtaEstimator> in order of preference */
    private array $estimators;

    public function __construct(OsrmEta $osrm, StraightLineEta $straight)
    {
        $this->estimators = [$osrm, $straight];
    }

    /** @return array{minutes: int, distance_m: int, updated_at: string, source: string} */
    public function between(string $deliveryId, float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        return Cache::remember('eta:'.$deliveryId, (int) config('platform.tracking.eta_cache_seconds', 30), function () use ($fromLat, $fromLng, $toLat, $toLng) {
            foreach ($this->estimators as $estimator) {
                $e = $estimator->estimate($fromLat, $fromLng, $toLat, $toLng);
                if ($e !== null) {
                    return $e + ['updated_at' => BusinessTime::iso(now()), 'source' => $estimator->source()];
                }
            }

            // The last estimator always answers; this only keeps the return type honest.
            return ['minutes' => 1, 'distance_m' => 0, 'updated_at' => BusinessTime::iso(now()), 'source' => 'estimate'];
        });
    }
}
