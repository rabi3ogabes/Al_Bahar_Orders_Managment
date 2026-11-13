<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Fulfillment\Models\DriverLocation;
use App\Support\BusinessTime;
use App\Support\Geo;
use Carbon\CarbonImmutable;

/**
 * The path a driver took for one delivery, ready to draw: the recorded GPS breadcrumbs reduced with Douglas–Peucker to at
 * most platform.tracking.route_max_points (corners are kept, straight stretches collapse), the places the driver stood
 * still ("stops") and the totals.
 */
class RouteHistory
{
    /**
     * @return array{
     *     points: list<array{lat: float, lng: float, at: string|null, speed_kmh: float|null, heading: int|null}>,
     *     stops: list<array{lat: float, lng: float, from: string|null, to: string|null, duration_s: int}>,
     *     distance_m: int, duration_s: int|null, started_at: string|null, ended_at: string|null, raw_count: int
     * }
     */
    public function forDelivery(string $deliveryId, ?int $maxPoints = null): array
    {
        $maxPoints ??= (int) config('platform.tracking.route_max_points', 300);
        $raw = DriverLocation::where('delivery_id', $deliveryId)->orderBy('recorded_at')->orderBy('id')
            ->limit((int) config('platform.tracking.route_raw_limit', 5000))->get(['lat', 'lng', 'heading', 'speed_kmh', 'recorded_at'])
            ->map(fn (DriverLocation $l) => ['lat' => (float) $l->lat, 'lng' => (float) $l->lng, 't' => $l->recorded_at->getTimestamp(),
                'speed_kmh' => $l->speed_kmh !== null ? (float) $l->speed_kmh : null, 'heading' => $l->heading !== null ? (int) $l->heading : null])->all();

        $points = Geo::simplifyTo($raw, $maxPoints);
        $first = $raw[0]['t'] ?? null;
        $last = $raw ? $raw[count($raw) - 1]['t'] : null;

        return [
            'points' => array_map(fn (array $p) => ['lat' => $p['lat'], 'lng' => $p['lng'], 'at' => $this->iso($p['t']), 'speed_kmh' => $p['speed_kmh'], 'heading' => $p['heading']], $points),
            'stops' => $this->stops($raw),
            // Length of the full recording, not of the simplified line, so the figure does not shrink with the point limit.
            'distance_m' => (int) round(Geo::pathLengthM($raw)),
            'duration_s' => $first !== null && $last !== null ? $last - $first : null,
            'started_at' => $first !== null ? $this->iso($first) : null,
            'ended_at' => $last !== null ? $this->iso($last) : null,
            'raw_count' => count($raw),
        ];
    }

    /**
     * Stretches of at least stop_min_seconds spent within stop_radius_m of where they began.
     *
     * @param  list<array{lat: float, lng: float, t: int}>  $raw
     * @return list<array{lat: float, lng: float, from: string|null, to: string|null, duration_s: int}>
     */
    private function stops(array $raw): array
    {
        $radius = (float) config('platform.tracking.stop_radius_m', 40);
        $minSeconds = (int) config('platform.tracking.stop_min_seconds', 120);
        $stops = [];
        $n = count($raw);
        $i = 0;
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && Geo::distanceM($raw[$i]['lat'], $raw[$i]['lng'], $raw[$j + 1]['lat'], $raw[$j + 1]['lng']) <= $radius) {
                $j++;
            }
            if ($j > $i && $raw[$j]['t'] - $raw[$i]['t'] >= $minSeconds) {
                $slice = array_slice($raw, $i, $j - $i + 1);
                $stops[] = ['lat' => round(array_sum(array_column($slice, 'lat')) / count($slice), 6), 'lng' => round(array_sum(array_column($slice, 'lng')) / count($slice), 6),
                    'from' => $this->iso($raw[$i]['t']), 'to' => $this->iso($raw[$j]['t']), 'duration_s' => $raw[$j]['t'] - $raw[$i]['t']];
                if (count($stops) >= 50) {
                    break;
                }
            }
            $i = $j + 1;
        }

        return $stops;
    }

    private function iso(int $timestamp): ?string
    {
        return BusinessTime::iso(CarbonImmutable::createFromTimestampUTC($timestamp));
    }
}
