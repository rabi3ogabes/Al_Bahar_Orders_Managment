import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';

void main() {
  group('angles', () {
    test('degrees fold into 0..360', () {
      expect(MapGeo.normalizeDegrees(0), 0);
      expect(MapGeo.normalizeDegrees(360), 0);
      expect(MapGeo.normalizeDegrees(-10), 350);
      expect(MapGeo.normalizeDegrees(725), 5);
    });

    test('the short way round: 350 to 10 is a turn of +20, not -340', () {
      expect(MapGeo.shortestAngleDelta(350, 10), 20);
      expect(MapGeo.shortestAngleDelta(10, 350), -20);
      expect(MapGeo.shortestAngleDelta(0, 180), 180);
      expect(MapGeo.shortestAngleDelta(90, 90), 0);
    });

    test('interpolating a heading never spins through the long arc', () {
      expect(MapGeo.lerpAngle(350, 10, 0), 350);
      expect(MapGeo.lerpAngle(350, 10, 0.5), closeTo(0, 1e-9)); // north, not south
      expect(MapGeo.lerpAngle(350, 10, 1), closeTo(10, 1e-9));
      expect(MapGeo.lerpAngle(10, 350, 0.5), closeTo(0, 1e-9));
      expect(MapGeo.lerpAngle(90, 180, 0.5), closeTo(135, 1e-9));
    });
  });

  group('MarkerPose.lerp', () {
    const a = MarkerPose(LatLng(25.0, 51.0), 350);
    const b = MarkerPose(LatLng(25.2, 51.4), 10);

    test('moves in a straight line and turns the short way', () {
      final mid = MarkerPose.lerp(a, b, 0.5);
      expect(mid.point.latitude, closeTo(25.1, 1e-9));
      expect(mid.point.longitude, closeTo(51.2, 1e-9));
      expect(mid.heading, closeTo(0, 1e-9));
    });

    test('ends exactly on the target and starts exactly on the start', () {
      expect(MarkerPose.lerp(a, b, 0).point, a.point);
      expect(MarkerPose.lerp(a, b, 1).point, b.point);
      expect(MarkerPose.lerp(a, b, 1).heading, closeTo(10, 1e-9));
      // Out-of-range progress is clamped, so an overshooting curve never throws the marker past its target.
      expect(MarkerPose.lerp(a, b, 1.4).point, b.point);
      expect(MarkerPose.lerp(a, b, -0.4).point, a.point);
    });

    test('an unknown heading on one side keeps the known one instead of snapping to north', () {
      const still = MarkerPose(LatLng(25, 51), null);
      expect(MarkerPose.lerp(a, still, 0.5).heading, 350);
      expect(MarkerPose.lerp(still, b, 0.5).heading, 10);
      expect(MarkerPose.lerp(still, still, 0.5).heading, isNull);
    });
  });

  group('distance and bearing', () {
    test('haversine matches known distances', () {
      const doha = LatLng(25.2854, 51.5310);
      const wakra = LatLng(25.1715, 51.6034);
      expect(MapGeo.distanceMeters(doha, doha), 0);
      expect(MapGeo.distanceMeters(doha, wakra), closeTo(14400, 600));
      // One degree of latitude is about 111.2 km.
      expect(MapGeo.distanceMeters(const LatLng(25, 51), const LatLng(26, 51)), closeTo(111195, 300));
    });

    test('bearing: north, east, south, west', () {
      const o = LatLng(25, 51);
      expect(MapGeo.bearing(o, const LatLng(26, 51)), closeTo(0, 0.01));
      expect(MapGeo.bearing(o, const LatLng(25, 52)), closeTo(90, 0.6));
      expect(MapGeo.bearing(o, const LatLng(24, 51)), closeTo(180, 0.01));
      expect(MapGeo.bearing(o, const LatLng(25, 50)), closeTo(270, 0.6));
    });

    test(
      'estimated minutes: a crawl or a stop is treated as city speed, a real speed is used, never under one minute',
      () {
        expect(MapGeo.estimateMinutes(15000), 30); // 15 km at the 30 km/h default
        expect(MapGeo.estimateMinutes(15000, speedKmh: 5), 30); // standing in traffic
        expect(MapGeo.estimateMinutes(15000, speedKmh: 60), 15);
        expect(MapGeo.estimateMinutes(15000, speedKmh: 140), 13); // capped at 70 km/h
        expect(MapGeo.estimateMinutes(20), 1);
      },
    );
  });

  group('Qatar bounds and coordinate pairs', () {
    test('places in Qatar are inside, abroad is outside', () {
      expect(MapGeo.isInQatar(25.2854, 51.5310), isTrue); // Doha
      expect(MapGeo.isInQatar(25.9, 51.0), isTrue); // north of the country
      expect(MapGeo.isInQatar(24.5, 51.2), isTrue); // south
      expect(MapGeo.isInQatar(25.2048, 55.2708), isFalse); // Dubai
      expect(MapGeo.isInQatar(51.5, -0.12), isFalse); // London
      expect(MapGeo.isInQatar(0, 0), isFalse);
    });

    test('a half pair, NaN or out-of-range numbers are not a position', () {
      expect(MapGeo.isFinitePair(null, 51), isFalse);
      expect(MapGeo.isFinitePair(25, null), isFalse);
      expect(MapGeo.isFinitePair(double.nan, 51), isFalse);
      expect(MapGeo.isFinitePair(25, double.infinity), isFalse);
      expect(MapGeo.isFinitePair(91, 51), isFalse);
      expect(MapGeo.isFinitePair(25, 181), isFalse);
      expect(MapGeo.isFinitePair(25.3, 51.5), isTrue);
      expect(MapGeo.isInQatar(25.3, null), isFalse);
    });
  });

  group('GeoFix parsing', () {
    final received = DateTime.utc(2026, 11, 13, 12);

    test('the current server shape: speed_kmh, accuracy_m, at, age_seconds', () {
      final f = GeoFix.tryParse({
        'lat': 25.3,
        'lng': 51.5,
        'heading': 370.0,
        'speed_kmh': 42.5,
        'accuracy_m': 12,
        'at': '2026-11-13T11:59:30Z',
        'age_seconds': 30,
        'stale': false,
      }, receivedAt: received)!;
      expect(f.point, const LatLng(25.3, 51.5));
      expect(f.heading, 10); // 370 folds into 0..360
      expect(f.speedKmh, 42.5);
      expect(f.accuracyM, 12);
      expect(f.ageSeconds, 30);
      expect(f.at, DateTime.utc(2026, 11, 13, 11, 59, 30));
    });

    test('the shorter names `speed`, `accuracy`, `latitude` and `longitude` are understood too', () {
      final f = GeoFix.tryParse({'latitude': '25.3', 'longitude': 51.5, 'speed': 10, 'accuracy': 5})!;
      expect(f.lat, 25.3);
      expect(f.speedKmh, 10);
      expect(f.accuracyM, 5);
    });

    test('only the coordinates are required; the rest is null, never a made-up number', () {
      final f = GeoFix.tryParse({'lat': 25.3, 'lng': 51.5})!;
      expect(f.heading, isNull);
      expect(f.speedKmh, isNull);
      expect(f.accuracyM, isNull);
      expect(f.at, isNull);
      expect(f.ageSeconds, isNull);
    });

    test('rubbish is refused: no pair, strings, NaN, a negative speed or age', () {
      expect(GeoFix.tryParse(null), isNull);
      expect(GeoFix.tryParse('x'), isNull);
      expect(GeoFix.tryParse({'lat': 25.3}), isNull);
      expect(GeoFix.tryParse({'lat': 'a', 'lng': 'b'}), isNull);
      expect(GeoFix.tryParse({'lat': double.nan, 'lng': 51.5}), isNull);
      final f = GeoFix.tryParse({'lat': 25.3, 'lng': 51.5, 'speed_kmh': -4, 'heading': -1, 'age_seconds': -9})!;
      expect(f.speedKmh, isNull);
      expect(f.heading, isNull);
      expect(f.ageSeconds, isNull);
    });
  });

  group('staleness', () {
    final received = DateTime.utc(2026, 11, 13, 12);

    test('a fix is live for 90 seconds, then stale', () {
      final f = GeoFix(lat: 25, lng: 51, at: received, receivedAt: received);
      expect(f.isStale(received.add(const Duration(seconds: 89))), isFalse);
      expect(f.isStale(received.add(const Duration(seconds: 90))), isFalse);
      expect(f.isStale(received.add(const Duration(seconds: 91))), isTrue);
      expect(f.isStale(received.add(const Duration(minutes: 20))), isTrue);
    });

    test('the server age counts on from when the answer arrived, so a wrong phone clock cannot fake staleness', () {
      // The server said "30 s old". The phone clock is an hour wrong, but only elapsed time since receipt matters.
      final f = GeoFix(lat: 25, lng: 51, ageSeconds: 30, at: DateTime.utc(2020), receivedAt: received);
      expect(f.age(received.add(const Duration(seconds: 20)))!.inSeconds, 50);
      expect(f.isStale(received.add(const Duration(seconds: 20))), isFalse);
      expect(f.isStale(received.add(const Duration(seconds: 70))), isTrue); // 30 + 70 = 100 s
    });

    test('the threshold is the server setting when given', () {
      final f = GeoFix(lat: 25, lng: 51, at: received, receivedAt: received);
      expect(f.isStale(received.add(const Duration(seconds: 100)), threshold: const Duration(seconds: 120)), isFalse);
      expect(f.isStale(received.add(const Duration(seconds: 130)), threshold: const Duration(seconds: 120)), isTrue);
    });

    test('a fix with no timestamp is treated as live (nothing says it is old)', () {
      const f = GeoFix(lat: 25, lng: 51);
      expect(f.age(received), isNull);
      expect(f.isStale(received), isFalse);
    });

    test('activity: moving from 3 km/h, otherwise waiting, stale wins over speed', () {
      GeoFix at(double? kmh, {int age = 0}) =>
          GeoFix(lat: 25, lng: 51, speedKmh: kmh, ageSeconds: age, receivedAt: received);
      expect(at(40).activity(received), DriverActivity.moving);
      expect(at(3).activity(received), DriverActivity.moving);
      expect(at(2.9).activity(received), DriverActivity.idle);
      expect(at(null).activity(received), DriverActivity.idle);
      expect(at(40, age: 200).activity(received), DriverActivity.stale);
    });
  });

  group('out-of-order reports', () {
    final t0 = DateTime.utc(2026, 11, 13, 12);

    test('a report with an older time is not newer, a later one is', () {
      final now = GeoFix(lat: 25, lng: 51, at: t0, receivedAt: t0);
      final older = GeoFix(
        lat: 25.1,
        lng: 51,
        at: t0.subtract(const Duration(seconds: 5)),
        receivedAt: t0.add(const Duration(seconds: 1)),
      );
      final newer = GeoFix(
        lat: 25.2,
        lng: 51,
        at: t0.add(const Duration(seconds: 5)),
        receivedAt: t0.add(const Duration(seconds: 1)),
      );
      expect(older.isNewerThan(now), isFalse);
      expect(newer.isNewerThan(now), isTrue);
    });

    test('without times the later arrival wins', () {
      final first = GeoFix(lat: 25, lng: 51, receivedAt: t0);
      final second = GeoFix(lat: 25.1, lng: 51, receivedAt: t0.add(const Duration(seconds: 2)));
      expect(second.isNewerThan(first), isTrue);
      expect(first.isNewerThan(second), isFalse);
    });
  });

  group('clustering', () {
    final points = <LatLng>[
      const LatLng(25.2000, 51.5000),
      const LatLng(25.2001, 51.5001),
      const LatLng(25.2002, 51.5002),
      const LatLng(25.9000, 51.0000),
    ];

    test('close points merge into one badge with the average position; far ones stay single', () {
      final groups = clusterPoints<LatLng>(points, (p) => p, cell: 0.01);
      expect(groups, hasLength(2));
      final big = groups.firstWhere((g) => !g.isSingle);
      expect(big.members, hasLength(3));
      expect(big.center.latitude, closeTo(25.2001, 1e-6));
      expect(groups.where((g) => g.isSingle), hasLength(1));
    });

    test('a very small cell leaves every point on its own', () {
      final groups = clusterPoints<LatLng>(points, (p) => p, cell: 0.00001);
      expect(groups.every((g) => g.isSingle), isTrue);
      expect(groups, hasLength(4));
    });

    test('the cell shrinks as the map zooms in, so clusters open up', () {
      expect(clusterCellDegrees(14), lessThan(clusterCellDegrees(10)));
      expect(clusterCellDegrees(10) / clusterCellDegrees(11), closeTo(2, 1e-9));
    });
  });
}
