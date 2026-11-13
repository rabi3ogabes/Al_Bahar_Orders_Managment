import '../../features/orders/data/order_repository.dart';
import '../network/api_client.dart';
import '../network/api_exception.dart';
import '../utils/json.dart';
import 'live_order.dart';

/// Where the app asks the server what its orders look like right now.
abstract class LiveOrderApi {
  /// Active (and just finished) orders, in the push payload shape. Null when the server cannot say.
  Future<List<LiveOrder>?> activeProgress();

  /// One order's current state, for an order that dropped out of the active list (it finished or was cancelled).
  Future<LiveOrder?> orderProgress(String orderId);
}

/// `GET /orders/active-progress` (the same payload as the push) with a fallback built from the orders endpoints, so the
/// notification keeps working against a server that does not have it yet.
class RestLiveOrderApi implements LiveOrderApi {
  RestLiveOrderApi(this._api, this._orders);

  final ApiClient _api;
  final OrderRepository _orders;

  /// Set once the server answered "no such endpoint": the orders list is used for the rest of the session.
  bool _missing = false;

  /// Orders on the road whose estimate is fetched in the fallback (one request each).
  static const _maxEtaLookups = 3;

  @override
  Future<List<LiveOrder>?> activeProgress() async {
    if (!_missing) {
      try {
        final data = await _api.get('/orders/active-progress');
        final rows = data is Map ? (data['orders'] ?? data['items'] ?? data['data']) : data;
        return [
          for (final row in asList(rows))
            if (row is Map) ?LiveOrder.tryParse(asMap(row)),
        ];
      } on ApiException catch (e) {
        // 404 (the route does not exist, `orders/{id}` takes it for an id), 405 or 422: an older server.
        if (e.statusCode != 404 && e.statusCode != 405 && e.statusCode != 422) rethrow;
        _missing = true;
      }
    }
    return _fromOrdersList();
  }

  @override
  Future<LiveOrder?> orderProgress(String orderId) async {
    final order = await _orders.get(orderId);
    final fulfillment = order.fulfillments.firstOrNull;
    final delivery = fulfillment?.delivery;
    return LiveOrder.fromStatus(
      orderId: order.id,
      number: order.number,
      status: order.status,
      fulfillmentType: order.fulfillmentType ?? fulfillment?.type,
      deliveryStatus: delivery?.status,
      etaTo: delivery?.eta,
      driverName: delivery?.driverName,
      driverPhone: delivery?.driverPhone,
    );
  }

  Future<List<LiveOrder>> _fromOrdersList() async {
    final summaries = await _orders.active();
    final result = <LiveOrder>[];
    var lookups = 0;
    for (final s in summaries) {
      var live = LiveOrder.fromStatus(
        orderId: s.id,
        number: s.number,
        status: s.status,
        fulfillmentType: s.fulfillmentType,
        updatedAt: s.placedAt,
      );
      if (live == null) continue;
      if (live.phase == LiveOrderPhase.onTheWay && lookups < _maxEtaLookups) {
        lookups++;
        live = await _withTracking(live);
      }
      result.add(live);
    }
    return result;
  }

  /// Adds the driver and the estimate from `GET /orders/{id}/tracking`; the order shows without them if it fails.
  Future<LiveOrder> _withTracking(LiveOrder live) async {
    try {
      final t = await _orders.tracking(live.orderId);
      final minutes = t.eta?.minutes;
      final base = t.eta?.updatedAt ?? DateTime.now();
      final arrival = minutes == null ? null : base.add(Duration(minutes: minutes));
      return LiveOrder(
        orderId: live.orderId,
        number: live.number,
        phase: live.phase,
        pickup: live.pickup,
        etaTo: arrival,
        etaAt: live.phase == LiveOrderPhase.onTheWay ? arrival : null,
        driverName: t.driverFirstName ?? t.driverName,
        driverPhone: t.driverPhone,
        vehicle: t.vehicle,
        updatedAt: live.updatedAt,
      );
    } catch (_) {
      return live;
    }
  }
}
