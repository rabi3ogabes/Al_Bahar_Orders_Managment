import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/map_format.dart';
import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

/// One driver to draw.
@immutable
class DriverMarkerData {
  const DriverMarkerData({
    required this.id,
    required this.fix,
    required this.name,
    this.vehicle,
    this.selected = false,
    this.showName = true,
  });

  final String id;
  final GeoFix fix;
  final String name;

  /// Free text from the server (`motorcycle`, `van` ...), picks the glyph.
  final String? vehicle;
  final bool selected;
  final bool showName;
}

/// The animated driver markers of one map. A new position glides from the old one in about a second along the short
/// arc for the heading (never a teleport, never a spin through the long way round); a report older than the one already
/// shown is ignored; a marker that has not been heard from for [MapGeo.staleAfter] turns grey with "Last seen"; the
/// whole thing stands still when the phone asks for reduced motion. With more than [clusterAbove] drivers, close ones
/// merge into count badges.
///
/// Place it among the children of an `AlBaharMap` (it reads the map camera).
class DriverMarkersLayer extends StatefulWidget {
  const DriverMarkersLayer({
    super.key,
    required this.drivers,
    this.onTap,
    this.onCluster,
    this.now = DateTime.now,
    this.duration = const Duration(milliseconds: 1000),
    this.clusterAbove = 40,
    this.staleAfter = MapGeo.staleAfter,
  });

  final List<DriverMarkerData> drivers;
  final void Function(String id)? onTap;

  /// A count badge was tapped: where it is and which drivers it holds (fly in to open it up).
  final void Function(LatLng center, List<String> ids)? onCluster;

  /// The clock (replaced in tests).
  final DateTime Function() now;
  final Duration duration;
  final int clusterAbove;

  /// A position older than this is grey with "Last seen" (the server's setting, 90 s by default).
  final Duration staleAfter;

  @override
  State<DriverMarkersLayer> createState() => _DriverMarkersLayerState();
}

class _Track {
  _Track({required this.from, required this.fix, required this.data});
  MarkerPose from;
  GeoFix fix;
  DriverMarkerData data;

  MarkerPose get target => MarkerPose(fix.point, fix.heading ?? from.heading);

  MarkerPose at(double t) => MarkerPose.lerp(from, target, t);
}

class _DriverMarkersLayerState extends State<DriverMarkersLayer> with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl = AnimationController(vsync: this, duration: widget.duration);
  final _tracks = <String, _Track>{};
  Timer? _ageTimer;

  double get _t => Curves.easeInOutCubic.transform(_ctrl.value);

  @override
  void initState() {
    super.initState();
    _ctrl.value = 1;
    _sync(initial: true);
    // Markers age on their own: a driver who stops reporting turns grey without any new data arriving.
    _ageTimer = Timer.periodic(const Duration(seconds: 10), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void didUpdateWidget(DriverMarkersLayer old) {
    super.didUpdateWidget(old);
    if (old.duration != widget.duration) _ctrl.duration = widget.duration;
    _sync();
  }

  void _sync({bool initial = false}) {
    // Where every marker is drawn right now: a restarted glide continues from there, so nothing ever jumps.
    final shown = <String, MarkerPose>{for (final e in _tracks.entries) e.key: e.value.at(_t)};
    final ids = <String>{};
    var moved = false;
    for (final d in widget.drivers) {
      ids.add(d.id);
      final t = _tracks[d.id];
      if (t == null) {
        _tracks[d.id] = _Track(from: MarkerPose(d.fix.point, d.fix.heading), fix: d.fix, data: d);
        continue;
      }
      t.data = d;
      // A late or repeated report never pulls a marker backwards.
      if (!d.fix.isNewerThan(t.fix)) continue;
      if (d.fix.point != t.fix.point || d.fix.heading != t.fix.heading) moved = true;
      t.fix = d.fix;
    }
    _tracks.removeWhere((id, _) => !ids.contains(id));
    if (!moved) return;
    final reduce = !initial && (MediaQuery.maybeDisableAnimationsOf(context) ?? false);
    for (final e in shown.entries) {
      final t = _tracks[e.key];
      if (t != null) t.from = reduce ? t.target : e.value;
    }
    if (reduce) {
      _ctrl.value = 1;
    } else {
      _ctrl.forward(from: 0);
    }
  }

  @override
  void dispose() {
    _ageTimer?.cancel();
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final camera = MapCamera.maybeOf(context);
    final rotation = camera?.rotation ?? 0;
    final now = widget.now();
    return AnimatedBuilder(
      animation: _ctrl,
      builder: (context, _) {
        final t = _t;
        final items = <_Item>[for (final track in _tracks.values) _Item(track, track.at(t))]
          ..sort((a, b) => (a.track.data.selected ? 1 : 0).compareTo(b.track.data.selected ? 1 : 0));
        final l10n = context.l10n;
        final markers = <Marker>[];
        final crowded = items.length > widget.clusterAbove && camera != null;
        final singles = <_Item>[];
        if (crowded) {
          final selected = items.where((i) => i.track.data.selected).toList();
          final others = items.where((i) => !i.track.data.selected).toList();
          final groups = clusterPoints<_Item>(others, (i) => i.pose.point, cell: clusterCellDegrees(camera.zoom));
          for (final g in groups) {
            if (g.isSingle) {
              singles.add(g.members.first);
            } else {
              markers.add(
                Marker(
                  point: g.center,
                  width: ClusterBadge.size,
                  height: ClusterBadge.size,
                  child: ClusterBadge(
                    count: g.members.length,
                    semanticsLabel: l10n.mapCluster(g.members.length),
                    onTap: widget.onCluster == null
                        ? null
                        : () => widget.onCluster!(g.center, [for (final m in g.members) m.track.data.id]),
                  ),
                ),
              );
            }
          }
          singles.addAll(selected);
        } else {
          singles.addAll(items);
        }
        for (final i in singles) {
          final d = i.track.data;
          final fix = i.track.fix;
          final activity = fix.activity(now, staleAfter: widget.staleAfter);
          final stale = activity == DriverActivity.stale;
          final age = fix.age(now);
          final lastSeen = stale && age != null ? MapFormat.lastSeen(l10n, age) : null;
          final status = switch (activity) {
            DriverActivity.moving => l10n.mapStatusMoving,
            DriverActivity.idle => l10n.mapStatusIdle,
            _ => lastSeen ?? l10n.mapStatusOffline,
          };
          markers.add(
            Marker(
              point: i.pose.point,
              width: DriverMarkerView.width,
              height: DriverMarkerView.height,
              child: DriverMarkerView(
                label: d.name,
                semanticsLabel: l10n.mapDriverSemantics(d.name, status),
                vehicle: vehicleKindOf(d.vehicle),
                heading: i.pose.heading,
                mapRotation: rotation,
                moving: activity == DriverActivity.moving,
                stale: stale,
                selected: d.selected,
                lastSeen: lastSeen,
                showName: d.showName,
                onTap: widget.onTap == null ? null : () => widget.onTap!(d.id),
              ),
            ),
          );
        }
        return MarkerLayer(markers: markers);
      },
    );
  }
}

class _Item {
  const _Item(this.track, this.pose);
  final _Track track;
  final MarkerPose pose;
}
