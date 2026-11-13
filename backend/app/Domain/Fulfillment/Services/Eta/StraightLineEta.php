<?php

namespace App\Domain\Fulfillment\Services\Eta;

use App\Support\Geo;

/** Always answers: straight-line distance × a road factor, at an average city speed (platform.tracking.eta_*). */
class StraightLineEta implements EtaEstimator
{
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $roadM = Geo::distanceM($fromLat, $fromLng, $toLat, $toLng) * (float) config('platform.tracking.eta_road_factor', 1.35);
        $kmh = max(5.0, (float) config('platform.tracking.eta_speed_kmh', 28));

        return ['minutes' => max(1, (int) ceil($roadM / 1000 / $kmh * 60)), 'distance_m' => (int) round($roadM)];
    }

    public function source(): string
    {
        return 'estimate';
    }
}
