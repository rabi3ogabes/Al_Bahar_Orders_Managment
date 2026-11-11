import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

/// Live map of the order on its way: the driver moves in real time (`driver.moved` on `private-orders.{id}`), with a
/// 15 s refresh as the safety net. Before pickup it says the driver will appear here.
class OrderTrackingScreen extends ConsumerStatefulWidget {
  const OrderTrackingScreen({super.key, required this.orderId});
  final String orderId;

  @override
  ConsumerState<OrderTrackingScreen> createState() => _OrderTrackingScreenState();
}

class _OrderTrackingScreenState extends ConsumerState<OrderTrackingScreen> {
  final _map = MapController();
  StreamSubscription<PusherEvent>? _sub;
  Timer? _poll;
  LatLng? _live;
  bool _fitted = false;

  @override
  void initState() {
    super.initState();
    _sub = ref.read(pusherClientProvider).channel('private-orders.${widget.orderId}').listen((e) {
      if (e.event != 'driver.moved') return;
      final lat = (e.data['lat'] as num?)?.toDouble();
      final lng = (e.data['lng'] as num?)?.toDouble();
      if (lat == null || lng == null || !mounted) return;
      setState(() => _live = LatLng(lat, lng));
    });
    _poll = Timer.periodic(const Duration(seconds: 15), (_) {
      if (mounted) ref.invalidate(orderTrackingProvider(widget.orderId));
    });
  }

  @override
  void dispose() {
    unawaited(_sub?.cancel());
    _poll?.cancel();
    _map.dispose();
    super.dispose();
  }

  void _fit(List<LatLng> pts) {
    if (_fitted || pts.length < 2) return;
    _fitted = true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _map.fitCamera(
        CameraFit.bounds(bounds: LatLngBounds.fromPoints(pts), padding: const EdgeInsets.all(72), maxZoom: 16),
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final async = ref.watch(orderTrackingProvider(widget.orderId));
    final tiles = ref.watch(appConfigProvider).mapTileUrlTemplate;
    return Scaffold(
      appBar: AppBar(title: Text(l10n.trackTitle)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(orderTrackingProvider(widget.orderId))),
        data: (t) {
          final driver = _live ?? t.driver;
          final pts = [?driver, ?t.destination, ?t.branch];
          final primary = context.colors.primary;
          if (pts.isEmpty) return Center(child: Text(l10n.trackWaiting, textAlign: TextAlign.center));
          _fit(pts);
          return Stack(
            children: [
              FlutterMap(
                mapController: _map,
                options: MapOptions(
                  initialCenter: pts.first,
                  initialZoom: 14,
                  interactionOptions: const InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
                ),
                children: [
                  TileLayer(urlTemplate: tiles, userAgentPackageName: 'qa.albahar.customer'),
                  MarkerLayer(
                    markers: [
                      if (t.branch != null)
                        _pin(t.branch!, Icons.storefront_rounded, context.palette.brass, l10n.trackBranch),
                      if (t.destination != null) _pin(t.destination!, Icons.home_rounded, primary, l10n.trackYou),
                      if (driver != null)
                        _pin(driver, Icons.two_wheeler_rounded, context.palette.success, t.driverName ?? ''),
                    ],
                  ),
                  RichAttributionWidget(attributions: [TextSourceAttribution(l10n.mapAttribution)]),
                ],
              ),
              PositionedDirectional(
                start: AppSpace.gutter,
                end: AppSpace.gutter,
                bottom: AppSpace.lg,
                child: SafeArea(
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(AppSpace.md),
                      child: Row(
                        children: [
                          CircleAvatar(
                            backgroundColor: primary.withValues(alpha: 0.12),
                            child: Icon(Icons.two_wheeler_rounded, color: primary),
                          ),
                          const SizedBox(width: AppSpace.sm),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(
                                  driver != null ? l10n.trackDriverOnWay : l10n.trackWaiting,
                                  style: context.text.titleSmall,
                                ),
                                if (t.driverName != null && driver != null)
                                  Text(
                                    t.driverName!,
                                    style: context.text.bodySmall?.copyWith(color: context.palette.muted),
                                  ),
                              ],
                            ),
                          ),
                          if (driver != null)
                            Text(
                              l10n.trackUpdated,
                              style: context.text.labelSmall?.copyWith(color: context.palette.muted),
                            ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Marker _pin(LatLng at, IconData icon, Color color, String label) => Marker(
    point: at,
    width: 48,
    height: 48,
    child: Semantics(
      label: label,
      child: DecoratedBox(
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: color,
          border: Border.all(color: Colors.white, width: 2),
          boxShadow: const [BoxShadow(blurRadius: 8, color: Colors.black26)],
        ),
        child: Icon(icon, color: Colors.white),
      ),
    ),
  );
}
