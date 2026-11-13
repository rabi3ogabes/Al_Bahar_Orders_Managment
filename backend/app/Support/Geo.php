<?php

namespace App\Support;

/**
 * Plain geometry for coordinates: distances, bearings, bounding boxes, point-in-polygon and path simplification.
 * Latitude/longitude are degrees (WGS84); distances are metres unless the name says otherwise.
 * Polygon rings follow GeoJSON order: [[lng, lat], ...].
 */
final class Geo
{
    public const EARTH_RADIUS_M = 6371008.8;

    /** Qatar's bounding box [min lat, max lat, min lng, max lng], with the usual slack for the peninsula and islands. */
    public const QATAR_BOUNDS = [24.4, 26.3, 50.7, 51.7];

    public static function validLat(mixed $lat): bool
    {
        return is_numeric($lat) && (float) $lat >= -90 && (float) $lat <= 90;
    }

    public static function validLng(mixed $lng): bool
    {
        return is_numeric($lng) && (float) $lng >= -180 && (float) $lng <= 180;
    }

    /** A usable map pin: both numbers present and inside the valid ranges ((0, 0) is the classic "no fix" value and is refused). */
    public static function validPoint(mixed $lat, mixed $lng): bool
    {
        return self::validLat($lat) && self::validLng($lng) && ! ((float) $lat === 0.0 && (float) $lng === 0.0);
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds  [min lat, max lat, min lng, max lng]
     */
    public static function inBounds(float $lat, float $lng, array $bounds = self::QATAR_BOUNDS, float $marginDeg = 0.0): bool
    {
        return $lat >= $bounds[0] - $marginDeg && $lat <= $bounds[1] + $marginDeg && $lng >= $bounds[2] - $marginDeg && $lng <= $bounds[3] + $marginDeg;
    }

    /** Great-circle distance in metres (haversine). */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return self::distanceM($lat1, $lng1, $lat2, $lng2) / 1000;
    }

    /** Initial compass bearing from the first point to the second, 0–359.99 (0 = north, 90 = east). */
    public static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lng2 - $lng1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * Slippy-map tile (Web Mercator, the scheme every raster provider uses) that holds the point at the zoom level.
     *
     * @return array{x: int, y: int}
     */
    public static function tile(float $lat, float $lng, int $zoom): array
    {
        $n = 2 ** $zoom;
        $lat = max(-85.0511, min(85.0511, $lat));
        $x = (int) floor(($lng + 180) / 360 * $n);
        $y = (int) floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n);

        return ['x' => max(0, min($n - 1, $x)), 'y' => max(0, min($n - 1, $y))];
    }

    /** Ray casting on one outer ring of [lng, lat] pairs. A point exactly on an edge may fall either way. */
    public static function pointInRing(float $lat, float $lng, array $ring): bool
    {
        $n = count($ring);
        if ($n < 3) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Length of a path in metres.
     *
     * @param  list<array{lat: float, lng: float}>  $points
     */
    public static function pathLengthM(array $points): float
    {
        $sum = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $sum += self::distanceM($points[$i - 1]['lat'], $points[$i - 1]['lng'], $points[$i]['lat'], $points[$i]['lng']);
        }

        return $sum;
    }

    /**
     * Douglas–Peucker simplification (iterative, so thousands of points cannot exhaust the stack). The first and last
     * point are always kept and the points keep their extra keys.
     *
     * @template T of array{lat: float, lng: float}
     *
     * @param  list<T>  $points
     * @return list<T>
     */
    public static function simplify(array $points, float $toleranceM): array
    {
        $n = count($points);
        if ($n < 3) {
            return $points;
        }
        $lat0 = deg2rad($points[0]['lat']);
        // Local flat projection in metres: accurate to well under a metre across a city.
        $xy = array_map(fn (array $p) => [self::EARTH_RADIUS_M * deg2rad($p['lng']) * cos($lat0), self::EARTH_RADIUS_M * deg2rad($p['lat'])], $points);
        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];
        while ($stack) {
            [$a, $b] = array_pop($stack);
            $maxD = 0.0;
            $idx = -1;
            for ($i = $a + 1; $i < $b; $i++) {
                $d = self::segmentDistance($xy[$i], $xy[$a], $xy[$b]);
                if ($d > $maxD) {
                    $maxD = $d;
                    $idx = $i;
                }
            }
            if ($idx !== -1 && $maxD > $toleranceM) {
                $keep[$idx] = true;
                $stack[] = [$a, $idx];
                $stack[] = [$idx, $b];
            }
        }

        $out = [];
        foreach ($points as $i => $p) {
            if ($keep[$i]) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Simplifies until at most $maxPoints remain, using the smallest tolerance that gets there (the shape stays as
     * faithful as the limit allows). Falls back to even thinning only when the limit is smaller than the path's corners.
     *
     * @template T of array{lat: float, lng: float}
     *
     * @param  list<T>  $points
     * @return list<T>
     */
    public static function simplifyTo(array $points, int $maxPoints): array
    {
        $maxPoints = max(2, $maxPoints);
        if (count($points) <= $maxPoints) {
            return $points;
        }
        $lo = 0.0;
        $hi = 100000.0; // 100 km tolerance collapses any city path to its end points
        $best = null;
        for ($i = 0; $i < 18; $i++) {
            $mid = ($lo + $hi) / 2;
            $simple = self::simplify($points, $mid);
            if (count($simple) <= $maxPoints) {
                $best = $simple;
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        return $best ?? self::thin($points, $maxPoints);
    }

    /**
     * Keeps evenly spaced points (and always the last one).
     *
     * @template T
     *
     * @param  list<T>  $points
     * @return list<T>
     */
    public static function thin(array $points, int $maxPoints): array
    {
        $n = count($points);
        $maxPoints = max(2, $maxPoints);
        if ($n <= $maxPoints) {
            return $points;
        }
        $out = [];
        for ($k = 0; $k < $maxPoints; $k++) {
            $out[] = $points[(int) round($k * ($n - 1) / ($maxPoints - 1))];
        }

        return $out;
    }

    /** Distance in metres from point $p to the segment $a–$b (all flat [x, y] metres). */
    private static function segmentDistance(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 == 0.0) {
            return hypot($p[0] - $a[0], $p[1] - $a[1]);
        }
        $t = max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len2));

        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }
}
