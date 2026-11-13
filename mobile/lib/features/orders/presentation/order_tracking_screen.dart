import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/al_bahar_map.dart';
import 'package:albahar_customer/core/map/driver_markers_layer.dart';
import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_layers.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/core/map/map_providers.dart';
import 'package:albahar_customer/core/map/measure_size.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/widgets/fresh.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/domain/tracking_state.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:albahar_customer/features/orders/presentation/tracking_panel.dart';
import 'package:albahar_customer/features/orders/presentation/tracking_view_model.dart';
import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

/// How often the screen asks again while the app is in front (the live push does the real work; this covers a dropped
/// socket and keeps the arrival estimate fresh).
const Duration kTrackingPoll = Duration(seconds: 12);

/// The driver's phone number for this order: from the tracking answer when the server sends it, else from the order's
/// delivery details. Null while it is not known.
final trackingDriverPhoneProvider = Provider.autoDispose.family<String?, String>((ref, orderId) {
  final order = ref.watch(orderDetailProvider(orderId)).value;
  if (order == null) return null;
  for (final f in order.fulfillments) {
    final phone = f.delivery?.driverPhone;
    if (phone != null && phone.isNotEmpty) return phone;
  }
  return null;
});

/// Live map of the order on its way: the driver glides along in real time (`driver.moved` on `private-orders.{id}`), a
/// 12 s refresh is the safety net, and a bottom panel shows the arrival estimate, the driver and the order steps.
/// Before pickup it says the driver will appear here; after delivery the map stands still.
class OrderTrackingScreen extends ConsumerStatefulWidget {
  const OrderTrackingScreen({super.key, required this.orderId});
  final String orderId;

  @override
  ConsumerState<OrderTrackingScreen> createState() => _OrderTrackingScreenState();
}

class _OrderTrackingScreenState extends ConsumerState<OrderTrackingScreen> with WidgetsBindingObserver {
  final _map = AlBaharMapController();
  StreamSubscription<PusherEvent>? _events;
  StreamSubscription<RealtimeState>? _states;
  Timer? _poll;
  Timer? _tick;
  GeoFix? _fix;
  GeoFix? _previous;
  double? _heading;
  List<LatLng> _trail = const [];
  LatLng? _fittedAt;
  TrackingStage? _stage;
  RealtimeState _socket = RealtimeState.idle;
  bool _foreground = true;
  double _panelHeight = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _socket = ref.read(pusherClientProvider).state;
    // Every answer (and the one already there) updates the driver's position, trail and camera.
    ref.listenManual(orderTrackingProvider(widget.orderId), (_, next) {
      final t = next.value;
      if (t != null) _afterBuild(() => _onTracking(t));
    }, fireImmediately: true);
    _listen();
    _poll = Timer.periodic(kTrackingPoll, (_) => _refresh());
    // The "last seen" wording ages without any new data.
    _tick = Timer.periodic(const Duration(seconds: 15), (_) {
      if (mounted && _foreground) setState(() {});
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_events?.cancel());
    unawaited(_states?.cancel());
    _poll?.cancel();
    _tick?.cancel();
    _map.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final front = state == AppLifecycleState.resumed;
    if (front == _foreground) return;
    _foreground = front;
    if (front) {
      _listen();
      _refresh();
      if (mounted) setState(() {});
    } else {
      // Nobody is looking: stop listening so the phone and the server are not kept busy.
      unawaited(_events?.cancel());
      unawaited(_states?.cancel());
      _events = null;
      _states = null;
    }
  }

  void _afterBuild(VoidCallback fn) {
    if (SchedulerBinding.instance.schedulerPhase == SchedulerPhase.persistentCallbacks) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) fn();
      });
    } else {
      fn();
    }
  }

  void _onTracking(OrderTracking t) {
    final stage = trackingStageOf(t);
    final changed = stage != _stage;
    _stage = stage;
    if (t.position != null) _accept(t.position!);
    if (changed) {
      if (stage == TrackingStage.delivered) {
        // Delivered: nothing more will arrive, so stop asking and listening.
        unawaited(_events?.cancel());
        unawaited(_states?.cancel());
        _events = null;
        _states = null;
      }
      _fitAll();
    }
  }

  void _refresh() {
    if (!mounted || !_foreground || _stage == TrackingStage.delivered || _stage == TrackingStage.closed) return;
    ref.invalidate(orderTrackingProvider(widget.orderId));
  }

  void _listen() {
    if (_events != null) return;
    final pusher = ref.read(pusherClientProvider);
    _events = pusher.channel('private-orders.${widget.orderId}').listen((e) {
      if (e.event != 'driver.moved' || !mounted) return;
      final fix = GeoFix.tryParse(e.data);
      if (fix != null) _accept(fix);
    });
    _states = pusher.states.listen((s) {
      if (!mounted) return;
      final reconnected = s == RealtimeState.connected && _socket != RealtimeState.connected;
      setState(() => _socket = s);
      // Whatever was missed while the socket was down is picked up from the server.
      if (reconnected) _refresh();
    });
  }

  /// A new driver position (push or poll): keeps the newest, remembers the path and moves the camera when it is useful.
  void _accept(GeoFix incoming) {
    final merged = newerFix(_fix, incoming);
    if (identical(merged, _fix) || merged == null) return;
    setState(() {
      _previous = _fix;
      _fix = merged;
      _heading = headingFor(merged, _previous, lastHeading: _heading);
      _trail = appendTrail(_trail, merged.point);
    });
    if (_map.following) {
      _map.followPoint(merged.point);
    } else if (!_map.userMoved) {
      // Keep the driver and the address in view as the distance closes: re-fit after a real move.
      final moved = _fittedAt == null || MapGeo.distanceMeters(_fittedAt!, merged.point) > 40;
      if (moved) _fitAll();
    }
  }

  List<LatLng> _points(OrderTracking t) {
    final stage = trackingStageOf(t);
    final showDriver = (stage == TrackingStage.onTheWay || stage == TrackingStage.waitingPickup) && _fix != null;
    return [if (showDriver) _fix!.point, ?t.destination, ?t.branch];
  }

  void _fitAll() {
    final t = ref.read(orderTrackingProvider(widget.orderId)).value;
    if (t == null) return;
    final pts = _points(t);
    if (pts.isEmpty) return;
    _fittedAt = _fix?.point;
    _map.fit(pts, maxZoom: 16.5);
  }

  Future<void> _call(String phone) async {
    final l10n = context.l10n;
    var ok = false;
    try {
      ok = await launchUrl(Uri(scheme: 'tel', path: phone));
    } catch (_) {}
    if (ok || !mounted) return;
    // No phone app (tablet, simulator): hand over the number instead.
    await Clipboard.setData(ClipboardData(text: phone));
    if (mounted) showInfoSnack(context, l10n.trackNumberCopied);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final id = widget.orderId;
    final async = ref.watch(orderTrackingProvider(id));
    final online = ref.watch(onlineProvider);
    return FreshScaffold(
      appBar: AppBar(title: Text(l10n.trackTitle)),
      header: FreshHeader(title: l10n.trackTitle, tagline: l10n.freshTaglineTrack),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipError: true,
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(orderTrackingProvider(id))),
        data: (t) => _body(context, t, online),
      ),
    );
  }

  Widget _body(BuildContext context, OrderTracking t, bool online) {
    final l10n = context.l10n;
    final stage = trackingStageOf(t);
    _stage = stage;
    final settings = ref.watch(mapConfigProvider);
    final phone = t.driverPhone ?? ref.watch(trackingDriverPhoneProvider(widget.orderId));
    final now = DateTime.now();
    final model = buildTrackingPanel(
      l10n,
      tracking: t,
      fix: _fix,
      now: now,
      offline: !online,
      socket: _socket,
      hasPhone: phone != null && phone.isNotEmpty,
      locale: context.localeCode,
      staleAfter: settings.staleAfter,
    );
    final panel = TrackingPanel(model: model, onCall: phone == null ? null : () => unawaited(_call(phone)));
    final points = _points(t);
    if (t.destination == null && t.branch == null && _fix == null) {
      // No coordinates at all (an old address with no pin, no driver yet): a clear message, not an empty map.
      return Column(
        children: [
          Expanded(
            child: EmptyState(
              icon: Icons.map_outlined,
              title: l10n.trackUnavailableTitle,
              message: l10n.trackUnavailableBody,
              compact: true,
            ),
          ),
          panel,
        ],
      );
    }

    final showDriver = (stage == TrackingStage.onTheWay || stage == TrackingStage.waitingPickup) && _fix != null;
    final threshold = t.staleAfter ?? settings.staleAfter;
    return Stack(
      children: [
        AlBaharMap(
          controller: _map,
          initialFit: points,
          maxFitZoom: 16.5,
          bottomInset: _panelHeight,
          controls: MapControlsConfig(follow: showDriver),
          semanticsLabel: l10n.trackMapSemantics,
          children: [
            if (showDriver)
              ...routeLayers(
                context,
                trail: _trail,
                remaining: [
                  _fix!.point,
                  ?(stage == TrackingStage.waitingPickup ? (t.branch ?? t.destination) : t.destination),
                ],
              ),
            MarkerLayer(
              markers: [
                if (t.branch != null)
                  pinMarker(t.branch!, kind: PinKind.branch, label: t.branchName ?? l10n.trackBranch),
                if (t.destination != null) pinMarker(t.destination!, kind: PinKind.destination, label: l10n.trackYou),
              ],
            ),
            if (showDriver)
              DriverMarkersLayer(
                drivers: [
                  DriverMarkerData(
                    id: 'driver',
                    fix: _withHeading(_fix!, _heading),
                    name: t.driverFirstName ?? '',
                    vehicle: t.vehicle,
                    showName: false,
                  ),
                ],
                staleAfter: threshold,
              ),
          ],
        ),
        Positioned(
          left: 0,
          right: 0,
          bottom: 0,
          child: MeasureSize(
            onChange: (s) {
              if (mounted && (s.height - _panelHeight).abs() > 0.5) setState(() => _panelHeight = s.height);
            },
            child: panel,
          ),
        ),
      ],
    );
  }

  GeoFix _withHeading(GeoFix f, double? heading) => heading == null || f.heading == heading
      ? f
      : GeoFix(
          lat: f.lat,
          lng: f.lng,
          heading: heading,
          speedKmh: f.speedKmh,
          accuracyM: f.accuracyM,
          at: f.at,
          ageSeconds: f.ageSeconds,
          receivedAt: f.receivedAt,
        );
}
