import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:latlong2/latlong.dart';

/// Pure geography and timing helpers shared by every map in the apps (no widgets, so they are cheap to unit test).
abstract final class MapGeo {
  /// A driver position older than this is shown as "last seen" (grey) instead of live.
  static const Duration staleAfter = Duration(seconds: 90);

  /// At or above this speed (km/h) a driver counts as moving.
  static const double movingKmh = 3;

  /// Rough box around Qatar (with a small sea margin); used to reject a delivery pin dropped abroad.
  static const double qatarSouth = 24.40;
  static const double qatarNorth = 26.25;
  static const double qatarWest = 50.65;
  static const double qatarEast = 51.75;

  /// Doha, the default centre.
  static const LatLng doha = LatLng(25.2854, 51.5310);

  static bool isFinitePair(double? lat, double? lng) =>
      lat != null && lng != null && lat.isFinite && lng.isFinite && lat.abs() <= 90 && lng.abs() <= 180;

  static bool isInQatar(double? lat, double? lng) =>
      isFinitePair(lat, lng) && lat! >= qatarSouth && lat <= qatarNorth && lng! >= qatarWest && lng <= qatarEast;

  /// Degrees folded into 0 (inclusive) .. 360 (exclusive).
  static double normalizeDegrees(double degrees) {
    final d = degrees % 360;
    return d < 0 ? d + 360 : d;
  }

  /// The signed turn (-180, 180] that takes [from] to [to] the short way round.
  static double shortestAngleDelta(double from, double to) {
    var d = (to - from) % 360;
    if (d > 180) d -= 360;
    if (d <= -180) d += 360;
    return d;
  }

  /// Interpolates two compass angles along the short arc, so 350 to 10 passes through 0 and not through 180.
  static double lerpAngle(double from, double to, double t) =>
      normalizeDegrees(from + shortestAngleDelta(from, to) * t);

  static LatLng lerpLatLng(LatLng a, LatLng b, double t) =>
      LatLng(a.latitude + (b.latitude - a.latitude) * t, a.longitude + (b.longitude - a.longitude) * t);

  /// Great-circle distance in metres (haversine).
  static double distanceMeters(LatLng a, LatLng b) {
    const r = 6371008.8;
    final p1 = a.latitudeInRad;
    final p2 = b.latitudeInRad;
    final dp = p2 - p1;
    final dl = b.longitudeInRad - a.longitudeInRad;
    final h = math.pow(math.sin(dp / 2), 2) + math.cos(p1) * math.cos(p2) * math.pow(math.sin(dl / 2), 2);
    return 2 * r * math.asin(math.min(1, math.sqrt(h)));
  }

  /// Initial compass bearing from [a] to [b], 0..360 clockwise from north.
  static double bearing(LatLng a, LatLng b) {
    final p1 = a.latitudeInRad;
    final p2 = b.latitudeInRad;
    final dl = b.longitudeInRad - a.longitudeInRad;
    final y = math.sin(dl) * math.cos(p2);
    final x = math.cos(p1) * math.sin(p2) - math.sin(p1) * math.cos(p2) * math.cos(dl);
    return normalizeDegrees(math.atan2(y, x) * 180 / math.pi);
  }

  /// Rough minutes to cover [meters] at [speedKmh] (used by the driver app, which has no routing server).
  /// A crawling or stopped phone is assumed to drive at [fallbackKmh] again once it sets off.
  static int estimateMinutes(double meters, {double? speedKmh, double fallbackKmh = 30}) {
    final kmh = speedKmh != null && speedKmh >= 12 ? math.min(speedKmh, 70) : fallbackKmh;
    final minutes = meters / 1000 / kmh * 60;
    return minutes <= 1 ? 1 : minutes.ceil();
  }
}

/// What a driver marker is doing right now.
enum DriverActivity { moving, idle, stale, noSignal }

/// One position report of a driver or of the phone: where, which way, how fast, how sure, and how old.
@immutable
class GeoFix {
  const GeoFix({
    required this.lat,
    required this.lng,
    this.heading,
    this.speedKmh,
    this.accuracyM,
    this.at,
    this.ageSeconds,
    this.receivedAt,
  });

  /// Reads a position map from the API or a broadcast. Every field but the coordinates is optional, `speed_kmh` and
  /// `speed` are both understood (km/h), and an unusable pair gives null.
  static GeoFix? tryParse(Object? raw, {DateTime? receivedAt}) {
    if (raw is! Map) return null;
    final lat = _num(raw['lat'] ?? raw['latitude']);
    final lng = _num(raw['lng'] ?? raw['longitude']);
    if (!MapGeo.isFinitePair(lat, lng)) return null;
    final heading = _num(raw['heading']);
    final speed = _num(raw['speed_kmh'] ?? raw['speed']);
    final accuracy = _num(raw['accuracy'] ?? raw['accuracy_m']);
    final age = _num(raw['age_seconds']);
    return GeoFix(
      lat: lat!,
      lng: lng!,
      heading: heading != null && heading.isFinite && heading >= 0 ? MapGeo.normalizeDegrees(heading) : null,
      speedKmh: speed != null && speed.isFinite && speed >= 0 ? speed : null,
      accuracyM: accuracy != null && accuracy.isFinite && accuracy >= 0 ? accuracy : null,
      at: DateTime.tryParse('${raw['at'] ?? raw['last_seen_at'] ?? ''}'),
      ageSeconds: age != null && age.isFinite && age >= 0 ? age.round() : null,
      receivedAt: receivedAt ?? DateTime.now(),
    );
  }

  static double? _num(Object? v) => v is num ? v.toDouble() : (v is String ? double.tryParse(v) : null);

  final double lat;
  final double lng;

  /// Compass heading in degrees, null when unknown (a standing phone has none).
  final double? heading;
  final double? speedKmh;
  final double? accuracyM;

  /// When the phone took the fix (server clock).
  final DateTime? at;

  /// How old the server said the fix was when it answered; counted on from [receivedAt] so a wrong phone clock
  /// cannot make a live driver look stale.
  final int? ageSeconds;
  final DateTime? receivedAt;

  LatLng get point => LatLng(lat, lng);

  /// Age of the fix at [now], or null when the report says nothing about when it was taken.
  Duration? age(DateTime now) {
    if (ageSeconds != null) {
      final waited = receivedAt == null ? Duration.zero : now.difference(receivedAt!);
      return Duration(seconds: ageSeconds!) + (waited.isNegative ? Duration.zero : waited);
    }
    if (at != null) {
      final d = now.difference(at!);
      return d.isNegative ? Duration.zero : d;
    }
    return null;
  }

  /// True when the fix is too old to call live. A fix with no timestamp at all is treated as live.
  bool isStale(DateTime now, {Duration threshold = MapGeo.staleAfter}) {
    final a = age(now);
    return a != null && a > threshold;
  }

  DriverActivity activity(DateTime now, {Duration staleAfter = MapGeo.staleAfter}) {
    if (isStale(now, threshold: staleAfter)) return DriverActivity.stale;
    return (speedKmh ?? 0) >= MapGeo.movingKmh ? DriverActivity.moving : DriverActivity.idle;
  }

  /// False for a report that is older than [other] (a late or duplicated delivery), so it never moves a marker back.
  /// Reports without a time compare by arrival.
  bool isNewerThan(GeoFix other) {
    if (at != null && other.at != null) {
      final c = at!.compareTo(other.at!);
      if (c != 0) return c > 0;
    }
    return _arrivedLater(other);
  }

  bool _arrivedLater(GeoFix other) {
    final a = receivedAt;
    final b = other.receivedAt;
    if (a == null || b == null) return true;
    return !a.isBefore(b);
  }

  @override
  bool operator ==(Object other) =>
      other is GeoFix &&
      other.lat == lat &&
      other.lng == lng &&
      other.heading == heading &&
      other.speedKmh == speedKmh &&
      other.accuracyM == accuracyM &&
      other.at == at &&
      other.ageSeconds == ageSeconds;

  @override
  int get hashCode => Object.hash(lat, lng, heading, speedKmh, accuracyM, at, ageSeconds);
}

/// An animated marker's current look: where it is on screen and which way it points.
@immutable
class MarkerPose {
  const MarkerPose(this.point, this.heading);
  final LatLng point;

  /// Degrees clockwise from north, null when the driver has never reported a heading.
  final double? heading;

  /// The pose [t] (0..1) of the way from [from] to [to]; the heading takes the short arc and is kept when one side
  /// is unknown, so the arrow never snaps to north.
  static MarkerPose lerp(MarkerPose from, MarkerPose to, double t) {
    final c = t.clamp(0.0, 1.0);
    final h = switch ((from.heading, to.heading)) {
      (final double a, final double b) => MapGeo.lerpAngle(a, b, c),
      (null, final double b) => b,
      (final double a, null) => a,
      _ => null,
    };
    return MarkerPose(MapGeo.lerpLatLng(from.point, to.point, c), h);
  }
}

/// Groups points into a coarse grid so a crowded map shows a count instead of a pile of pins. [cell] is the grid size
/// in degrees (smaller = less merging); each group keeps its members and the average position.
List<PointCluster<T>> clusterPoints<T>(
  Iterable<T> items,
  LatLng Function(T item) at, {
  required double cell,
  int minGroup = 2,
}) {
  final groups = <(int, int), List<T>>{};
  for (final item in items) {
    final p = at(item);
    groups.putIfAbsent(((p.latitude / cell).floor(), (p.longitude / cell).floor()), () => []).add(item);
  }
  final out = <PointCluster<T>>[];
  for (final members in groups.values) {
    var lat = 0.0;
    var lng = 0.0;
    for (final m in members) {
      final p = at(m);
      lat += p.latitude;
      lng += p.longitude;
    }
    out.add(PointCluster<T>(LatLng(lat / members.length, lng / members.length), members));
  }
  // Groups below the threshold are broken back into single points by the caller through [PointCluster.isSingle].
  return minGroup <= 1
      ? out
      : [
          for (final g in out)
            if (g.members.length >= minGroup) g else ...g.members.map((m) => PointCluster<T>(at(m), [m])),
        ];
}

/// One pin or a group of nearby pins.
@immutable
class PointCluster<T> {
  const PointCluster(this.center, this.members);
  final LatLng center;
  final List<T> members;
  bool get isSingle => members.length == 1;
}

/// The degrees of grid cell that keep clusters about [pixels] wide at map [zoom] (256 px tiles).
double clusterCellDegrees(double zoom, {double pixels = 56}) => 360 / (256 * math.pow(2, zoom)) * pixels;
