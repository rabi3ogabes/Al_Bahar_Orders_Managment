import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

/// A pin whose tip sits on [at] (branch, delivery address, picked spot).
Marker pinMarker(
  LatLng at, {
  required PinKind kind,
  required String label,
  bool selected = false,
  VoidCallback? onTap,
}) => Marker(
  point: at,
  width: MapPin.width,
  height: MapPin.height,
  alignment: Alignment.topCenter,
  child: MapPin(kind: kind, semanticsLabel: label, selected: selected, onTap: onTap),
);

/// The route a driver still has to cover and the path already driven.
///
/// * [remaining] is drawn dashed over a soft casing, from the driver to the destination. There is no routing server,
///   so it is the straight line unless the caller has road geometry to pass in.
/// * [trail] is the last few real positions, a solid thinner line behind the driver.
///
/// Spread the result into `AlBaharMap.children`.
List<Widget> routeLayers(
  BuildContext context, {
  List<LatLng> remaining = const [],
  List<LatLng> trail = const [],
}) {
  final scheme = Theme.of(context).colorScheme;
  final dark = Theme.of(context).brightness == Brightness.dark;
  final casing = (dark ? const Color(0xFF181115) : Colors.white).withValues(alpha: 0.85);
  return [
    if (trail.length >= 2)
      PolylineLayer(
        polylines: [
          Polyline(
            points: trail,
            strokeWidth: 4,
            color: scheme.primary.withValues(alpha: 0.55),
            borderStrokeWidth: 2,
            borderColor: casing,
            strokeCap: StrokeCap.round,
            strokeJoin: StrokeJoin.round,
          ),
        ],
      ),
    if (remaining.length >= 2)
      PolylineLayer(
        polylines: [
          Polyline(
            points: remaining,
            strokeWidth: 5,
            color: scheme.primary,
            borderStrokeWidth: 3,
            borderColor: casing,
            pattern: const StrokePattern.dashed(segments: [10, 9]),
            strokeCap: StrokeCap.round,
            strokeJoin: StrokeJoin.round,
          ),
        ],
      ),
  ];
}

/// The phone's own position: a blue dot, with a pale circle the size of the GPS error.
List<Widget> myLocationLayers(
  BuildContext context, {
  required LatLng at,
  required String label,
  double? accuracyM,
  double? heading,
}) {
  final blue = Theme.of(context).brightness == Brightness.dark ? const Color(0xFF6FA8FF) : const Color(0xFF1A73E8);
  return [
    if (accuracyM != null && accuracyM >= 5)
      CircleLayer(
        circles: [
          CircleMarker(
            point: at,
            radius: accuracyM.clamp(5, 400).toDouble(),
            useRadiusInMeter: true,
            color: blue.withValues(alpha: 0.13),
            borderColor: blue.withValues(alpha: 0.4),
            borderStrokeWidth: 1,
          ),
        ],
      ),
    MarkerLayer(
      markers: [
        Marker(
          point: at,
          width: MyLocationDot.size,
          height: MyLocationDot.size,
          child: MyLocationDot(semanticsLabel: label, heading: heading),
        ),
      ],
    ),
  ];
}

/// Keeps the last [max] points of a moving thing (the breadcrumb behind a driver), dropping repeats and points
/// closer than about a metre so a parked phone does not fill the trail.
List<LatLng> appendTrail(List<LatLng> trail, LatLng p, {int max = 40}) {
  if (trail.isNotEmpty) {
    final last = trail.last;
    if ((last.latitude - p.latitude).abs() < 0.00001 && (last.longitude - p.longitude).abs() < 0.00001) return trail;
  }
  final next = [...trail, p];
  return next.length > max ? next.sublist(next.length - max) : next;
}
