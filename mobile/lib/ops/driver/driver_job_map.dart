import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/al_bahar_map.dart';
import 'package:albahar_customer/core/map/driver_markers_layer.dart';
import 'package:albahar_customer/core/map/map_format.dart';
import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_layers.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/core/map/measure_size.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

/// The driver's map of one delivery: where they are (from the same GPS the tracker shares), where the order goes, the
/// path still to cover, the distance and a rough arrival time, and a big Navigate button that hands the trip to the
/// phone's navigation app. The job card with its action buttons sits under the map, so the whole delivery can be
/// finished from here.
class DriverJobMapScreen extends ConsumerStatefulWidget {
  const DriverJobMapScreen({
    super.key,
    required this.job,
    required this.card,
    required this.fixes,
    required this.onNavigate,
    this.initialFix,
  });

  final DriverJob job;

  /// The job card (status, address, step buttons), built by the caller so it stays in step with the job list.
  final Widget card;

  /// The phone's own positions (the tracker's stream) and the newest one so far.
  final Stream<GeoFix> fixes;
  final GeoFix? initialFix;

  /// Starts navigation to the destination (the coordinates, or the address text when there is no pin).
  final VoidCallback onNavigate;

  @override
  ConsumerState<DriverJobMapScreen> createState() => _DriverJobMapScreenState();
}

class _DriverJobMapScreenState extends ConsumerState<DriverJobMapScreen> {
  final _map = AlBaharMapController();
  StreamSubscription<GeoFix>? _sub;
  GeoFix? _fix;
  List<LatLng> _trail = const [];
  double _panel = 0;
  LatLng? _fittedAt;

  LatLng? get _destination {
    final a = widget.job.address;
    return MapGeo.isFinitePair(a.lat, a.lng) ? LatLng(a.lat!, a.lng!) : null;
  }

  @override
  void initState() {
    super.initState();
    _fix = widget.initialFix;
    if (_fix != null) _trail = appendTrail(const [], _fix!.point);
    _sub = widget.fixes.listen(_onFix);
  }

  @override
  void didUpdateWidget(DriverJobMapScreen old) {
    super.didUpdateWidget(old);
    if (old.fixes != widget.fixes) {
      unawaited(_sub?.cancel());
      _sub = widget.fixes.listen(_onFix);
    }
    final a = old.job.address;
    if (a.lat != widget.job.address.lat || a.lng != widget.job.address.lng) _fit();
  }

  @override
  void dispose() {
    unawaited(_sub?.cancel());
    _map.dispose();
    super.dispose();
  }

  List<LatLng> get _points => [?_fix?.point, ?_destination];

  void _fit() {
    final pts = _points;
    if (pts.isEmpty) return;
    _fittedAt = _fix?.point;
    _map.fit(pts, maxZoom: 16.5);
  }

  void _onFix(GeoFix fix) {
    if (!mounted) return;
    final prev = _fix;
    if (prev != null && !fix.isNewerThan(prev)) return;
    final first = prev == null;
    setState(() {
      _fix = fix;
      _trail = appendTrail(_trail, fix.point);
    });
    if (_map.following) {
      _map.followPoint(fix.point);
    } else if (!_map.userMoved) {
      final moved = first || _fittedAt == null || MapGeo.distanceMeters(_fittedAt!, fix.point) > 60;
      if (moved) _fit();
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final l10n = context.l10n;
    final dest = _destination;
    final fix = _fix;
    final remaining = fix != null && dest != null ? MapGeo.distanceMeters(fix.point, dest) : null;
    return Scaffold(
      appBar: AppBar(title: Text('${t.jobMapTitle} · #${widget.job.orderNumber}')),
      body: Stack(
        children: [
          AlBaharMap(
            controller: _map,
            initialFit: _points,
            maxFitZoom: 16.5,
            bottomInset: _panel,
            topInset: 64,
            controls: const MapControlsConfig(follow: true),
            semanticsLabel: t.jobMapTitle,
            children: [
              if (fix != null && fix.accuracyM != null && fix.accuracyM! >= 5)
                CircleLayer(
                  circles: [
                    CircleMarker(
                      point: fix.point,
                      radius: fix.accuracyM!.clamp(5, 300).toDouble(),
                      useRadiusInMeter: true,
                      color: Theme.of(context).colorScheme.primary.withValues(alpha: 0.10),
                      borderColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.35),
                      borderStrokeWidth: 1,
                    ),
                  ],
                ),
              ...routeLayers(context, trail: _trail, remaining: [?fix?.point, ?dest]),
              if (dest != null)
                MarkerLayer(
                  markers: [pinMarker(dest, kind: PinKind.destination, label: l10n.trackYou)],
                ),
              if (fix != null)
                DriverMarkersLayer(
                  drivers: [DriverMarkerData(id: 'me', fix: fix, name: '', showName: false)],
                ),
            ],
          ),
          PositionedDirectional(
            top: AppSpace.xs,
            start: AppSpace.sm,
            end: AppSpace.sm,
            child: _InfoBar(
              text: dest == null
                  ? t.jobNoPin
                  : fix == null
                  ? t.jobFindingYou
                  : null,
              eta: remaining == null ? null : t.jobEta(MapGeo.estimateMinutes(remaining, speedKmh: fix?.speedKmh)),
              distance: remaining == null ? null : t.jobToGo(MapFormat.distance(l10n, remaining)),
              note: remaining == null ? null : t.jobEstimateNote,
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: MeasureSize(
              onChange: (s) {
                if (mounted && (s.height - _panel).abs() > 0.5) setState(() => _panel = s.height);
              },
              child: Material(
                color: Theme.of(context).colorScheme.surface,
                elevation: 8,
                shadowColor: Colors.black38,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(AppRadius.sheet)),
                clipBehavior: Clip.antiAlias,
                child: SafeArea(
                  top: false,
                  child: ConstrainedBox(
                    constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.62),
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.fromLTRB(AppSpace.sm, AppSpace.sm, AppSpace.sm, AppSpace.sm),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          SizedBox(
                            height: 56,
                            child: FilledButton.icon(
                              onPressed: widget.onNavigate,
                              icon: const Icon(Icons.navigation_rounded),
                              label: Text(
                                t.navigate,
                                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                  color: Theme.of(context).colorScheme.onPrimary,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                          ),
                          widget.card,
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The strip at the top of the map: arrival estimate and distance, or why there is none yet.
class _InfoBar extends StatelessWidget {
  const _InfoBar({this.text, this.eta, this.distance, this.note});
  final String? text;
  final String? eta;
  final String? distance;
  final String? note;

  @override
  Widget build(BuildContext context) {
    if (text == null && eta == null) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: Semantics(
        liveRegion: true,
        child: Material(
          color: scheme.surface.withValues(alpha: 0.96),
          borderRadius: BorderRadius.circular(AppRadius.input),
          elevation: 3,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
            child: text != null
                ? Text(text!, style: Theme.of(context).textTheme.bodyMedium)
                : Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // A Wrap: on a narrow phone with large text the distance drops under the time instead of overflowing.
                      Wrap(
                        spacing: 8,
                        runSpacing: 2,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.schedule_rounded, size: 18, color: scheme.primary),
                              const SizedBox(width: 6),
                              Text(
                                eta!,
                                style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                              ),
                            ],
                          ),
                          if (distance != null) Text(distance!, style: Theme.of(context).textTheme.bodyMedium),
                        ],
                      ),
                      if (note != null)
                        Text(
                          note!,
                          style: Theme.of(context).textTheme.labelSmall?.copyWith(color: context.palette.muted),
                        ),
                    ],
                  ),
          ),
        ),
      ),
    );
  }
}
