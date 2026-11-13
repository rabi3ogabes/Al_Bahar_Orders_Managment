<?php

namespace App\Domain\Fulfillment\Services\Eta;

/** One way of guessing how long a driver needs to reach a point. The first estimator that answers wins (see EtaService). */
interface EtaEstimator
{
    /** @return array{minutes: int, distance_m: int}|null null = this estimator cannot answer right now */
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array;

    /** Stable name reported to clients in eta.source. */
    public function source(): string;
}
