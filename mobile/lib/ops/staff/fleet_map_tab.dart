import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/al_bahar_map.dart';
import 'package:albahar_customer/core/map/driver_markers_layer.dart';
import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_layers.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/core/map/map_providers.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/fleet_logic.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

/// Drivers of the branch the staff app is showing (the overview's branch), so the map never lists another branch's drivers.
final staffFleetProvider = FutureProvider.autoDispose<List<FleetDriver>>((ref) async {
  final repo = ref.watch(opsRepositoryProvider);
  final branchId = ref.watch(staffOverviewProvider.select((o) => o.value?.branchId));
  return repo.fleet(branchId: branchId == null || branchId.isEmpty ? null : branchId);
});

/// State of the live connection (the current state first, then every change), for the "Live / Reconnecting" pill.
final staffConnectionProvider = StreamProvider.autoDispose<RealtimeState>((ref) async* {
  final client = ref.watch(pusherClientProvider);
  yield client.state;
  yield* client.states;
});

/// Live map of every driver of the branch. Pins glide as `driver.moved` pushes arrive (a 20 s refresh is the safety net),
/// turn grey with "last seen" when a phone goes quiet, and merge into count badges when there are more than 40. Filter
/// chips (all, moving, waiting, not live) narrow the map and the list together; the driver list is a bottom sheet kept
/// in step with the map (tap a row to fly to the driver, tap a pin to find the row). A driver with no position yet is
/// listed but not pinned.
class FleetMapTab extends ConsumerStatefulWidget {
  const FleetMapTab({super.key, required this.events});

  /// The branch's real-time stream (`private-branches.{id}`), shared with the rest of the staff app.
  final Stream<Map<String, dynamic>> events;

  @override
  ConsumerState<FleetMapTab> createState() => _FleetMapTabState();
}

class _FleetMapTabState extends ConsumerState<FleetMapTab> with OpsPolling {
  final _map = AlBaharMapController();
  final _sheet = DraggableScrollableController();
  ScrollController? _sheetScroll;
  StreamSubscription<Map<String, dynamic>>? _sub;
  final _live = <String, GeoFix>{};
  FleetFilter _filter = FleetFilter.all;
  String? _selected;
  bool _fitted = false;
  double _sheetFraction = 0.3;
  List<LatLng> _points = const [];

  void _listen() {
    unawaited(_sub?.cancel());
    _sub = widget.events.listen((d) {
      final id = d['driver_id']?.toString();
      // A push without a time is a fix taken just now; one with `at` or `age_seconds` says how old it really is.
      final fix = GeoFix.tryParse({...d, 'age_seconds': d['age_seconds'] ?? (d['at'] == null ? 0 : null)});
      if (id == null || fix == null || !mounted) return;
      setState(() => _live[id] = bestFix(_live[id], fix) ?? fix);
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
    _map.pointsProvider = () => _points;
    startPolling(every: const Duration(seconds: 20), () => ref.invalidate(staffFleetProvider));
  }

  @override
  void dispose() {
    unawaited(_sub?.cancel());
    _sheet.dispose();
    _map.dispose();
    super.dispose();
  }

  void _setFilter(FleetFilter f) {
    if (f == _filter) return;
    setState(() => _filter = f);
    // The map frames what the filter now shows.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted && _points.isNotEmpty) _map.fit(_points, maxZoom: 16);
    });
  }

  void _select(FleetEntry e, {bool fly = true, bool scroll = false, List<FleetEntry> list = const []}) {
    setState(() => _selected = e.id);
    final p = e.point;
    final dest = e.driver.destination;
    if (fly && p != null) {
      // A driver on a delivery is shown together with the address; one without goes to street level.
      if (dest != null) {
        _map.fit([p, dest], maxZoom: 16);
      } else {
        _map.flyTo(p, zoom: 16);
      }
    }
    if (scroll) _scrollToRow(list.indexWhere((x) => x.id == e.id));
    if (_sheet.isAttached && _sheet.size > 0.5) {
      unawaited(_sheet.animateTo(0.3, duration: AppDurations.medium, curve: Curves.easeOutCubic));
    }
  }

  void _scrollToRow(int index) {
    final c = _sheetScroll;
    if (index < 0 || c == null || !c.hasClients) return;
    final target = (index * _rowHeight).clamp(0.0, c.position.maxScrollExtent);
    unawaited(c.animateTo(target, duration: AppDurations.medium, curve: Curves.easeOutCubic));
  }

  static const double _rowHeight = 72;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final async = ref.watch(staffFleetProvider);
    final settings = ref.watch(mapConfigProvider);
    final stale = async.hasError && async.hasValue;
    return async.when(
      skipLoadingOnRefresh: true,
      skipError: true,
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffFleetProvider)),
      data: (list) {
        if (list.isEmpty) {
          return RefreshIndicator(
            onRefresh: () => ref.refresh(staffFleetProvider.future),
            child: LayoutBuilder(
              builder: (context, c) => ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  SizedBox(
                    height: c.maxHeight,
                    child: EmptyState(
                      icon: Icons.map_rounded,
                      title: t.mapEmptyTitle,
                      message: t.mapEmptyHint,
                      compact: true,
                    ),
                  ),
                ],
              ),
            ),
          );
        }
        final now = DateTime.now();
        final entries = classifyFleet(list, _live, now, staleAfter: settings.staleAfter);
        final counts = fleetCounts(entries);
        final shown = [
          for (final e in entries)
            if (e.matches(_filter) || e.id == _selected) e,
        ];
        _points = [for (final e in shown) ?e.point];
        if (!_fitted && _points.isNotEmpty) {
          _fitted = true;
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _map.fit(_points, maxZoom: 16);
          });
        }
        final selected = entries.where((e) => e.id == _selected).firstOrNull;
        return Column(
          children: [
            if (stale) StaleBanner(onRetry: () => ref.invalidate(staffFleetProvider)),
            Expanded(
              child: LayoutBuilder(
                builder: (context, box) {
                  final h = box.maxHeight;
                  final inset = (_sheetFraction.clamp(0.0, 0.5)) * h;
                  return Stack(
                    children: [
                      AlBaharMap(
                        controller: _map,
                        initialFit: _points,
                        maxFitZoom: 16,
                        bottomInset: inset,
                        topInset: 56,
                        semanticsLabel: t.mapTab,
                        onTap: (_) {
                          if (_selected != null) setState(() => _selected = null);
                        },
                        children: [
                          if (selected?.point != null && selected?.driver.destination != null) ...[
                            ...routeLayers(context, remaining: [selected!.point!, selected.driver.destination!]),
                            MarkerLayer(
                              markers: [
                                pinMarker(
                                  selected.driver.destination!,
                                  kind: PinKind.destination,
                                  label: context.l10n.trackYou,
                                ),
                              ],
                            ),
                          ],
                          DriverMarkersLayer(
                            drivers: [
                              for (final e in shown)
                                if (e.fix != null)
                                  DriverMarkerData(
                                    id: e.id,
                                    fix: e.fix!,
                                    name: e.driver.name,
                                    vehicle: e.driver.vehicle,
                                    selected: e.id == _selected,
                                  ),
                            ],
                            staleAfter: settings.staleAfter,
                            onTap: (id) {
                              final e = entries.where((x) => x.id == id).firstOrNull;
                              if (e != null) _select(e, fly: false, scroll: true, list: shown);
                            },
                            onCluster: (center, _) => _map.flyTo(center, zoom: (_map.camera?.zoom ?? 12) + 2),
                          ),
                        ],
                      ),
                      PositionedDirectional(
                        top: AppSpace.xs,
                        start: 0,
                        end: 0,
                        child: _FilterBar(filter: _filter, counts: counts, onChanged: _setFilter),
                      ),
                      NotificationListener<DraggableScrollableNotification>(
                        onNotification: (n) {
                          if ((n.extent - _sheetFraction).abs() > 0.004) setState(() => _sheetFraction = n.extent);
                          return false;
                        },
                        child: DraggableScrollableSheet(
                          controller: _sheet,
                          initialChildSize: 0.3,
                          minChildSize: (96 / h).clamp(0.12, 0.3),
                          maxChildSize: 0.85,
                          snap: true,
                          snapSizes: const [0.3, 0.6],
                          builder: (context, scroll) {
                            _sheetScroll = scroll;
                            return _DriverSheet(
                              controller: scroll,
                              entries: shown,
                              total: entries.length,
                              onDuty: list.where((d) => d.onDuty).length,
                              selectedId: _selected,
                              now: now,
                              onSelect: (e) => _select(e),
                              onRefresh: () => ref.refresh(staffFleetProvider.future),
                            );
                          },
                        ),
                      ),
                    ],
                  );
                },
              ),
            ),
          ],
        );
      },
    );
  }
}

/// The filter chips, in one row that scrolls sideways when the text is large.
class _FilterBar extends StatelessWidget {
  const _FilterBar({required this.filter, required this.counts, required this.onChanged});
  final FleetFilter filter;
  final Map<FleetFilter, int> counts;
  final ValueChanged<FleetFilter> onChanged;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final label = {
      FleetFilter.all: t.fleetFilterAll,
      FleetFilter.moving: t.fleetFilterMoving,
      FleetFilter.idle: t.fleetFilterIdle,
      FleetFilter.stale: t.fleetFilterStale,
    };
    return SizedBox(
      height: 48,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: AppSpace.xs),
        child: Row(
          children: [
            for (final f in FleetFilter.values)
              Padding(
                padding: const EdgeInsetsDirectional.only(start: AppSpace.xs),
                child: Center(
                  child: FilterChip(
                    showCheckmark: false,
                    selected: filter == f,
                    label: Text('${label[f]} · ${counts[f] ?? 0}'),
                    onSelected: (_) => onChanged(f),
                    backgroundColor: Theme.of(context).colorScheme.surface,
                    elevation: 2,
                    shadowColor: Colors.black38,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// "Live", "Reconnecting" or "Offline" with the number of drivers on duty: whether what the map shows is up to the second.
class _ConnectionPill extends ConsumerWidget {
  const _ConnectionPill({required this.onDuty});
  final int onDuty;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final palette = context.palette;
    final online = ref.watch(onlineProvider);
    final socket = ref.watch(staffConnectionProvider).value ?? RealtimeState.idle;
    final (Color color, String text) = !online
        ? (palette.muted, t.fleetLinkOffline)
        : switch (socket) {
            RealtimeState.connected => (palette.success, t.liveNow),
            RealtimeState.reconnecting => (palette.warning, t.fleetLinkReconnecting),
            RealtimeState.disconnected => (palette.danger, t.fleetLinkReconnecting),
            _ => (palette.warning, t.fleetLinkConnecting),
          };
    return Semantics(
      label: '$text, $onDuty',
      excludeSemantics: true,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              '$text · $onDuty',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelMedium,
            ),
          ),
        ],
      ),
    );
  }
}

/// The driver list as a bottom sheet: a handle, a title with the count, then one row per driver.
class _DriverSheet extends StatelessWidget {
  const _DriverSheet({
    required this.controller,
    required this.entries,
    required this.total,
    required this.onDuty,
    required this.selectedId,
    required this.now,
    required this.onSelect,
    required this.onRefresh,
  });

  final ScrollController controller;
  final List<FleetEntry> entries;
  final int total;
  final int onDuty;
  final String? selectedId;
  final DateTime now;
  final ValueChanged<FleetEntry> onSelect;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.surface,
      elevation: 8,
      shadowColor: Colors.black38,
      borderRadius: const BorderRadius.vertical(top: Radius.circular(AppRadius.sheet)),
      clipBehavior: Clip.antiAlias,
      child: RefreshIndicator(
        onRefresh: onRefresh,
        child: ListView.builder(
          controller: controller,
          physics: const AlwaysScrollableScrollPhysics(),
          padding: EdgeInsets.zero,
          itemCount: entries.isEmpty ? 2 : entries.length + 1,
          itemBuilder: (context, i) {
            if (i == 0) {
              return Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const SizedBox(height: AppSpace.xs),
                  ExcludeSemantics(
                    child: Container(
                      width: 36,
                      height: 4,
                      decoration: BoxDecoration(color: context.palette.line, borderRadius: BorderRadius.circular(2)),
                    ),
                  ),
                  SizedBox(
                    height: 48,
                    child: Padding(
                      padding: const EdgeInsetsDirectional.only(start: AppSpace.gutter, end: AppSpace.xxs),
                      child: Row(
                        children: [
                          Flexible(
                            flex: 2,
                            child: Semantics(
                              header: true,
                              child: Text(
                                t.fleetListTitle(total),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: Theme.of(context).textTheme.titleSmall,
                              ),
                            ),
                          ),
                          const SizedBox(width: AppSpace.sm),
                          Flexible(flex: 3, child: _ConnectionPill(onDuty: onDuty)),
                          const Spacer(),
                          // Pulling the list down while the sheet is small drags the sheet, so refreshing has a button too.
                          IconButton(
                            tooltip: t.refresh,
                            icon: const Icon(Icons.refresh_rounded),
                            onPressed: () => unawaited(onRefresh()),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              );
            }
            if (entries.isEmpty) {
              return Padding(
                padding: const EdgeInsets.all(AppSpace.lg),
                child: Text(t.fleetNoMatch, textAlign: TextAlign.center),
              );
            }
            final e = entries[i - 1];
            return _DriverRow(entry: e, selected: e.id == selectedId, now: now, onTap: () => onSelect(e));
          },
        ),
      ),
    );
  }
}

class _DriverRow extends StatelessWidget {
  const _DriverRow({required this.entry, required this.selected, required this.now, required this.onTap});
  final FleetEntry entry;
  final bool selected;
  final DateTime now;
  final VoidCallback onTap;

  Color _tone(BuildContext context) {
    final p = context.palette;
    return switch (entry.activity) {
      DriverActivity.moving => p.success,
      DriverActivity.idle => p.info,
      _ => p.muted,
    };
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final d = entry.driver;
    final tone = _tone(context);
    final age = entry.fix?.age(now) ?? (d.lastSeen == null ? null : now.difference(d.lastSeen!));
    final job = d.orderNumber == null
        ? (d.onDuty ? t.driversFree : t.offDutyTag)
        : '${t.orderNo(d.orderNumber!)} · ${t.statusLabel(d.jobStatus ?? '')}';
    final speed = entry.activity == DriverActivity.moving && entry.fix?.speedKmh != null
        ? ' · ${t.fleetSpeed(entry.fix!.speedKmh!.round())}'
        : '';
    final subtitle = !entry.hasPosition
        ? '$job · ${t.noLocationYet}'
        : age == null
        ? '$job$speed'
        : '$job$speed · ${t.seenAgo(age)}';
    return ListTile(
      minTileHeight: _FleetMapTabState._rowHeight - 8,
      selected: selected,
      selectedTileColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.08),
      onTap: entry.hasPosition ? onTap : null,
      leading: CircleAvatar(
        backgroundColor: tone.withValues(alpha: 0.18),
        child: Icon(Icons.two_wheeler_rounded, color: tone),
      ),
      title: Text(d.name),
      subtitle: Text(subtitle),
      trailing: d.phone == null
          ? null
          : IconButton(
              tooltip: '${t.call} ${d.name}',
              icon: const Icon(Icons.call_rounded),
              onPressed: () => dial(context, d.phone),
            ),
    );
  }
}
