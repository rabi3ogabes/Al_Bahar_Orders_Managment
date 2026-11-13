import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/foundation.dart';
import 'package:latlong2/latlong.dart';

/// The filter chips above the staff fleet map.
enum FleetFilter { all, moving, idle, stale }

/// One driver as the fleet map and list see them: who, the best-known position, and what they are doing.
@immutable
class FleetEntry {
  const FleetEntry({required this.driver, required this.fix, required this.activity});

  final FleetDriver driver;

  /// The newest position known (the server's or a live push, whichever is later); null when none was ever reported.
  final GeoFix? fix;
  final DriverActivity activity;

  String get id => driver.id;
  LatLng? get point => fix?.point;
  bool get hasPosition => fix != null;

  bool matches(FleetFilter f) => switch (f) {
    FleetFilter.all => true,
    FleetFilter.moving => activity == DriverActivity.moving,
    FleetFilter.idle => activity == DriverActivity.idle,
    FleetFilter.stale => activity == DriverActivity.stale,
  };
}

/// The newer of the server's position and a live push for one driver.
GeoFix? bestFix(GeoFix? server, GeoFix? live) {
  if (live == null) return server;
  if (server == null) return live;
  return live.isNewerThan(server) ? live : server;
}

/// Turns the server's list and the live pushes into entries, sorted moving first, then waiting, then not live, then
/// drivers with no position; names break ties.
List<FleetEntry> classifyFleet(
  List<FleetDriver> drivers,
  Map<String, GeoFix> live,
  DateTime now, {
  Duration staleAfter = MapGeo.staleAfter,
}) {
  final out = [
    for (final d in drivers)
      () {
        final fix = bestFix(d.fix, live[d.id]);
        final activity = fix == null ? DriverActivity.noSignal : fix.activity(now, staleAfter: staleAfter);
        return FleetEntry(driver: d, fix: fix, activity: activity);
      }(),
  ];
  const order = {DriverActivity.moving: 0, DriverActivity.idle: 1, DriverActivity.stale: 2, DriverActivity.noSignal: 3};
  out.sort((a, b) {
    final c = order[a.activity]!.compareTo(order[b.activity]!);
    return c != 0 ? c : a.driver.name.toLowerCase().compareTo(b.driver.name.toLowerCase());
  });
  return out;
}

/// How many drivers each chip stands for.
Map<FleetFilter, int> fleetCounts(List<FleetEntry> entries) => {
  for (final f in FleetFilter.values) f: entries.where((e) => e.matches(f)).length,
};
