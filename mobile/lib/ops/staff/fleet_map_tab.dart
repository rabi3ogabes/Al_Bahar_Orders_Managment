import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

final staffFleetProvider = FutureProvider.autoDispose<List<FleetDriver>>(
  (ref) => ref.watch(opsRepositoryProvider).fleet(),
);

/// Live map of every driver of the branch: pins move in real time (`driver.moved` on the branch channel); a 20 s refresh is the
/// safety net. Tap a driver in the list to centre the map on them; a driver with no position yet is listed but not pinned.
class FleetMapTab extends ConsumerStatefulWidget {
  const FleetMapTab({super.key, required this.events});

  /// The branch's real-time stream (`private-branches.{id}`), shared with the rest of the staff app.
  final Stream<Map<String, dynamic>> events;

  @override
  ConsumerState<FleetMapTab> createState() => _FleetMapTabState();
}

class _FleetMapTabState extends ConsumerState<FleetMapTab> {
  final _map = MapController();
  StreamSubscription<Map<String, dynamic>>? _sub;
  Timer? _poll;
  final _live = <String, LatLng>{};
  final _seen = <String, DateTime>{};
  String? _selected;
  bool _fitted = false;

  void _listen() {
    unawaited(_sub?.cancel());
    _sub = widget.events.listen((d) {
      final id = d['driver_id']?.toString();
      final lat = (d['lat'] as num?)?.toDouble();
      final lng = (d['lng'] as num?)?.toDouble();
      if (id == null || lat == null || lng == null || !mounted) return;
      setState(() {
        _live[id] = LatLng(lat, lng);
        _seen[id] = DateTime.now();
      });
    });
  }

  @override
  void didUpdateWidget(FleetMapTab old) {
    super.didUpdateWidget(old);
    if (old.events != widget.events) _listen();
  }

  @override
  void initState() {
    super.initState();
    _listen();
    _poll = Timer.periodic(const Duration(seconds: 20), (_) {
      if (mounted) ref.invalidate(staffFleetProvider);
    });
  }

  @override
  void dispose() {
    unawaited(_sub?.cancel());
    _poll?.cancel();
    _map.dispose();
    super.dispose();
  }

  LatLng? _pos(FleetDriver d) => _live[d.id] ?? (d.hasPosition ? LatLng(d.lat!, d.lng!) : null);

  void _fit(List<FleetDriver> list) {
    final pts = [for (final d in list) ?_pos(d)];
    if (pts.isEmpty) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (pts.length == 1) {
        _map.move(pts.first, 15);
      } else {
        _map.fitCamera(
          CameraFit.bounds(bounds: LatLngBounds.fromPoints(pts), padding: const EdgeInsets.all(64), maxZoom: 16),
        );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final async = ref.watch(staffFleetProvider);
    final tiles = ref.watch(appConfigProvider).mapTileUrlTemplate;
    return async.when(
      skipLoadingOnRefresh: true,
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffFleetProvider)),
      data: (list) {
        if (list.isEmpty) {
          return EmptyState(icon: Icons.map_rounded, title: t.mapEmptyTitle, message: t.mapEmptyHint, compact: true);
        }
        if (!_fitted && list.any((d) => _pos(d) != null)) {
          _fitted = true;
          _fit(list);
        }
        final scheme = Theme.of(context).colorScheme;
        return Column(
          children: [
            Expanded(
              flex: 3,
              child: Stack(
                children: [
                  FlutterMap(
                    mapController: _map,
                    options: const MapOptions(
                      initialCenter: LatLng(25.2854, 51.531),
                      initialZoom: 11,
                      interactionOptions: InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
                    ),
                    children: [
                      TileLayer(urlTemplate: tiles, userAgentPackageName: 'qa.albahar.staff'),
                      MarkerLayer(
                        markers: [
                          for (final d in list)
                            if (_pos(d) != null) _pin(context, d, _pos(d)!, selected: d.id == _selected),
                        ],
                      ),
                      const RichAttributionWidget(attributions: [TextSourceAttribution('© OpenStreetMap')]),
                    ],
                  ),
                  PositionedDirectional(
                    top: AppSpace.sm,
                    start: AppSpace.sm,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        color: scheme.surface,
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: kSoftShadow,
                      ),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Container(
                              width: 8,
                              height: 8,
                              decoration: const BoxDecoration(color: Color(0xFF2E7D5B), shape: BoxShape.circle),
                            ),
                            const SizedBox(width: 6),
                            Text(
                              '${t.liveNow} · ${list.where((d) => d.onDuty).length}',
                              style: Theme.of(context).textTheme.labelMedium,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  PositionedDirectional(
                    bottom: AppSpace.sm,
                    end: AppSpace.sm,
                    child: FloatingActionButton.small(
                      heroTag: 'recenter',
                      tooltip: t.recenter,
                      onPressed: () {
                        setState(() => _selected = null);
                        _fit(list);
                      },
                      child: const Icon(Icons.center_focus_strong_rounded),
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              flex: 2,
              child: RefreshIndicator(
                onRefresh: () => ref.refresh(staffFleetProvider.future),
                child: ListView.separated(
                  padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
                  itemCount: list.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (_, i) => _tile(context, list[i]),
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Color _tone(FleetDriver d) {
    final last = _seen[d.id] ?? d.lastSeen;
    final stale = last == null || DateTime.now().difference(last).inMinutes > 15;
    if (!d.onDuty || stale) return const Color(0xFF9AA0A6);
    return d.openJobs > 0 ? const Color(0xFFB8893A) : const Color(0xFF2E7D5B);
  }

  Marker _pin(BuildContext context, FleetDriver d, LatLng at, {required bool selected}) => Marker(
    point: at,
    width: 96,
    height: 70,
    child: GestureDetector(
      onTap: () => setState(() => _selected = d.id),
      child: Semantics(
        label: d.name,
        button: true,
        child: Column(
          children: [
            AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              width: selected ? 44 : 38,
              height: selected ? 44 : 38,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: _tone(d),
                border: Border.all(color: Colors.white, width: 2),
                boxShadow: const [BoxShadow(blurRadius: 8, color: Colors.black26)],
              ),
              child: const Icon(Icons.two_wheeler_rounded, color: Colors.white, size: 20),
            ),
            const SizedBox(height: 2),
            DecoratedBox(
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.92),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                child: Text(
                  d.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w600, color: Colors.black87),
                ),
              ),
            ),
          ],
        ),
      ),
    ),
  );

  Widget _tile(BuildContext context, FleetDriver d) {
    final t = OpsStrings.of(context);
    final at = _pos(d);
    final last = _seen[d.id] ?? d.lastSeen;
    final job = d.orderNumber == null
        ? (d.onDuty ? t.driversFree : t.offDutyTag)
        : '${t.orderNo(d.orderNumber!)} · ${t.statusLabel(d.jobStatus ?? '')}';
    return ListTile(
      minTileHeight: 60,
      selected: d.id == _selected,
      selectedTileColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.08),
      onTap: at == null
          ? null
          : () {
              setState(() => _selected = d.id);
              _map.move(at, 16);
            },
      leading: CircleAvatar(
        backgroundColor: _tone(d).withValues(alpha: 0.18),
        child: Icon(Icons.two_wheeler_rounded, color: _tone(d)),
      ),
      title: Text(d.name),
      subtitle: Text(
        at == null
            ? '$job · ${t.noLocationYet}'
            : last == null
            ? job
            : '$job · ${t.seenAgo(DateTime.now().difference(last))}',
      ),
      trailing: d.phone == null
          ? null
          : IconButton(tooltip: d.phone, icon: const Icon(Icons.call_rounded), onPressed: () => dial(d.phone)),
    );
  }
}
