<?php

use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\BranchDiscovery;
use App\Support\Geo;

it('measures great-circle distance in metres', function () {
    // One degree of latitude is about 111.19 km everywhere; Doha to Al Wakrah is about 11 km as the crow flies.
    expect(Geo::distanceM(0, 0, 1, 0))->toEqualWithDelta(111195, 50)
        ->and(Geo::distanceM(25.2854, 51.5310, 25.2854, 51.5310))->toBe(0.0)
        ->and(Geo::distanceKm(25.2854, 51.5310, 25.1715, 51.6034))->toEqualWithDelta(14.4, 0.3)
        ->and(Geo::distanceM(10, 20, 30, 40))->toEqualWithDelta(Geo::distanceM(30, 40, 10, 20), 0.001)
        ->and(BranchDiscovery::distanceKm(25.2854, 51.5310, 25.1715, 51.6034))->toEqualWithDelta(Geo::distanceKm(25.2854, 51.5310, 25.1715, 51.6034), 1e-9);
});

it('does not blow up on antipodal points', function () {
    expect(Geo::distanceM(0, 0, 0, 180))->toEqualWithDelta(M_PI * Geo::EARTH_RADIUS_M, 1);
});

it('gives compass bearings', function () {
    expect(Geo::bearing(0, 0, 1, 0))->toEqualWithDelta(0, 0.01)       // north
        ->and(Geo::bearing(0, 0, 0, 1))->toEqualWithDelta(90, 0.01)   // east
        ->and(Geo::bearing(0, 0, -1, 0))->toEqualWithDelta(180, 0.01) // south
        ->and(Geo::bearing(0, 0, 0, -1))->toEqualWithDelta(270, 0.01); // west
});

it('validates map pins', function (mixed $lat, mixed $lng, bool $ok) {
    expect(Geo::validPoint($lat, $lng))->toBe($ok);
})->with([
    'doha' => [25.2854, 51.5310, true],
    'numeric strings' => ['25.2854', '51.5310', true],
    'null island is "no fix"' => [0, 0, false],
    'equator is fine on its own' => [0, 51.5, true],
    'lat too high' => [90.0001, 51, false],
    'lat too low' => [-90.5, 51, false],
    'lng too high' => [25, 180.5, false],
    'missing lat' => [null, 51, false],
    'missing lng' => [25, null, false],
    'not a number' => ['abc', 51, false],
]);

it('checks Qatar bounds with an optional margin', function () {
    expect(Geo::inBounds(25.2854, 51.5310))->toBeTrue()
        ->and(Geo::inBounds(24.7136, 46.6753))->toBeFalse()                 // Riyadh
        ->and(Geo::inBounds(26.8, 51.5))->toBeFalse()
        ->and(Geo::inBounds(26.8, 51.5, Geo::QATAR_BOUNDS, 1.0))->toBeTrue()   // inside the one-degree margin
        ->and(Geo::inBounds(24.7136, 46.6753, Geo::QATAR_BOUNDS, 1.0))->toBeFalse()
        ->and(Geo::inBounds(0.0, 0.0, Geo::QATAR_BOUNDS, 1.0))->toBeFalse();
});

it('finds the slippy-map tile of a point', function () {
    // Zoom 0 is one tile; Doha at zoom 11 sits in column 1317, row 875 of 2048 (checked against the standard slippy-map formula).
    expect(Geo::tile(25.2854, 51.5310, 0))->toBe(['x' => 0, 'y' => 0])
        ->and(Geo::tile(25.2854, 51.5310, 11)['x'])->toBe(1317)
        ->and(Geo::tile(25.2854, 51.5310, 11)['y'])->toBe(875)
        ->and(Geo::tile(89.9, 0, 3)['y'])->toBe(0)    // clamped to the Mercator limit
        ->and(Geo::tile(0, 180, 3)['x'])->toBe(7);
});

it('tests a point against a polygon ring, in [lng, lat] order', function () {
    $square = [[51.4, 25.2], [51.6, 25.2], [51.6, 25.4], [51.4, 25.4]];
    // An L-shape proves concave rings work: the notch at the top right is outside.
    $ell = [[0, 0], [4, 0], [4, 2], [2, 2], [2, 4], [0, 4]];
    expect(Geo::pointInRing(25.3, 51.5, $square))->toBeTrue()
        ->and(Geo::pointInRing(25.5, 51.5, $square))->toBeFalse()
        ->and(Geo::pointInRing(25.3, 51.7, $square))->toBeFalse()
        ->and(Geo::pointInRing(1, 1, $ell))->toBeTrue()       // lat 1, lng 1
        ->and(Geo::pointInRing(3, 3, $ell))->toBeFalse()      // in the notch
        ->and(Geo::pointInRing(1, 3, $ell))->toBeTrue()
        ->and(Geo::pointInRing(1, 1, []))->toBeFalse()
        ->and(Geo::pointInRing(1, 1, [[0, 0], [1, 1]]))->toBeFalse();
});

it('keeps DeliveryZone::contains on the shared polygon test', function () {
    $zone = new DeliveryZone(['polygon' => [[51.4, 25.2], [51.6, 25.2], [51.6, 25.4], [51.4, 25.4]]]);
    expect($zone->contains(25.3, 51.5))->toBeTrue()->and($zone->contains(26.0, 51.5))->toBeFalse()->and((new DeliveryZone)->contains(25.3, 51.5))->toBeFalse();
});

/** A straight eastbound line of $n points about 10 m apart, with a sharp corner north in the middle when $corner is set. */
function geoLine(int $n, bool $corner = false): array
{
    $pts = [];
    for ($i = 0; $i < $n; $i++) {
        $pts[] = ['lat' => 25.0 + ($corner && $i > $n / 2 ? ($i - $n / 2) * 0.00009 : 0), 'lng' => 51.0 + ($corner && $i > $n / 2 ? $n / 2 : $i) * 0.0001, 'tag' => $i];
    }

    return $pts;
}

it('collapses a straight line to its end points and keeps extra keys', function () {
    $out = Geo::simplify(geoLine(50), 5);
    expect($out)->toHaveCount(2)->and($out[0]['tag'])->toBe(0)->and($out[1]['tag'])->toBe(49);
});

it('keeps the corner of an L-shaped path', function () {
    $line = geoLine(60, true);
    $out = Geo::simplify($line, 5);
    expect($out)->toHaveCount(3)->and($out[0]['tag'])->toBe(0)->and($out[2]['tag'])->toBe(59)
        ->and($out[1]['tag'])->toBeBetween(29, 31);     // the turning point
});

it('leaves short paths alone', function () {
    expect(Geo::simplify([], 5))->toBe([])->and(Geo::simplify([['lat' => 1.0, 'lng' => 1.0]], 5))->toHaveCount(1)->and(Geo::simplify(geoLine(2), 5))->toHaveCount(2);
});

it('never drops a point further than the tolerance from the simplified line', function () {
    mt_srand(7);
    $noisy = array_map(fn ($i) => ['lat' => 25.0 + mt_rand(-200, 200) / 1e6, 'lng' => 51.0 + $i * 0.0001], range(0, 199));
    $tolerance = 10.0;
    $out = Geo::simplify($noisy, $tolerance);
    expect(count($out))->toBeLessThan(count($noisy));

    $mPerDegLat = Geo::EARTH_RADIUS_M * M_PI / 180;
    $mPerDegLng = $mPerDegLat * cos(deg2rad(25.0));
    $worst = 0.0;
    foreach ($noisy as $p) {
        $best = INF;
        for ($k = 1; $k < count($out); $k++) {
            [$ax, $ay, $bx, $by] = [$out[$k - 1]['lng'] * $mPerDegLng, $out[$k - 1]['lat'] * $mPerDegLat, $out[$k]['lng'] * $mPerDegLng, $out[$k]['lat'] * $mPerDegLat];
            [$px, $py] = [$p['lng'] * $mPerDegLng, $p['lat'] * $mPerDegLat];
            $len2 = ($bx - $ax) ** 2 + ($by - $ay) ** 2;
            $t = $len2 > 0 ? max(0, min(1, (($px - $ax) * ($bx - $ax) + ($py - $ay) * ($by - $ay)) / $len2)) : 0;
            $best = min($best, hypot($px - ($ax + $t * ($bx - $ax)), $py - ($ay + $t * ($by - $ay))));
        }
        $worst = max($worst, $best);
    }
    expect($worst)->toBeLessThanOrEqual($tolerance + 0.05);
});

it('reduces to at most the requested number of points, keeping both ends', function () {
    // A wiggly 2000-point path (a sine wave) cannot be a straight line, so the limit has to bite.
    $wave = array_map(fn ($i) => ['lat' => 25.0 + sin($i / 15) * 0.01, 'lng' => 51.0 + $i * 0.00005, 'tag' => $i], range(0, 1999));
    foreach ([300, 50, 7, 2] as $max) {
        $out = Geo::simplifyTo($wave, $max);
        expect(count($out))->toBeLessThanOrEqual($max)->and($out[0]['tag'])->toBe(0)->and($out[count($out) - 1]['tag'])->toBe(1999);
    }
    expect(Geo::simplifyTo(geoLine(10), 300))->toHaveCount(10);   // already small: untouched
});

it('thins evenly when simplification alone cannot reach the limit', function () {
    $out = Geo::thin(range(0, 99), 5);
    expect($out)->toBe([0, 25, 50, 74, 99])->and(Geo::thin(range(0, 3), 10))->toBe([0, 1, 2, 3]);
});

it('measures a path length', function () {
    expect(Geo::pathLengthM([]))->toBe(0.0)
        ->and(Geo::pathLengthM([['lat' => 0.0, 'lng' => 0.0], ['lat' => 1.0, 'lng' => 0.0], ['lat' => 2.0, 'lng' => 0.0]]))->toEqualWithDelta(222390, 100);
});
