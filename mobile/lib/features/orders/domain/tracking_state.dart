import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';

/// Where an order is in its journey, as far as the live map is concerned.
enum TrackingStage {
  /// No driver yet: the order is being prepared or is waiting for one.
  notAssigned,

  /// A driver has the order but has not left the branch with it.
  waitingPickup,

  /// Picked up and on the road: the driver moves on the map.
  onTheWay,

  /// Handed over: the map stands still.
  delivered,

  /// Cancelled, returned or a delivery that did not happen: there is nothing to follow.
  closed,
}

/// The four steps the customer sees, in order.
enum TrackingStep { confirmed, preparing, onTheWay, delivered }

const _closedOrder = {'cancelled', 'partially_cancelled', 'returned'};
const _failedDelivery = {'failed', 'returning', 'returned', 'cancelled'};

TrackingStage trackingStageOf(OrderTracking t) {
  final status = t.status ?? '';
  final delivery = t.deliveryStatus ?? '';
  if (delivery == 'delivered' || status == 'completed') return TrackingStage.delivered;
  if (_closedOrder.contains(status) || _failedDelivery.contains(delivery)) return TrackingStage.closed;
  if (t.live || delivery == 'picked_up' || delivery == 'out_for_delivery' || status == 'out_for_delivery') {
    return TrackingStage.onTheWay;
  }
  if (delivery == 'accepted' || delivery == 'assigned' || (t.hasDriver && delivery.isEmpty)) {
    return TrackingStage.waitingPickup;
  }
  return TrackingStage.notAssigned;
}

TrackingStep trackingStepOf(OrderTracking t) => switch (trackingStageOf(t)) {
  TrackingStage.delivered => TrackingStep.delivered,
  TrackingStage.onTheWay => TrackingStep.onTheWay,
  TrackingStage.waitingPickup => TrackingStep.preparing,
  _ => switch (t.status) {
    'processing' || 'partially_fulfilled' || 'ready_for_pickup' => TrackingStep.preparing,
    _ => TrackingStep.confirmed,
  },
};

/// The newer of two driver fixes: a push that arrives after a newer poll (or the other way round) never moves the
/// marker back.
GeoFix? newerFix(GeoFix? current, GeoFix? incoming) {
  if (incoming == null) return current;
  if (current == null) return incoming;
  return incoming.isNewerThan(current) ? incoming : current;
}

/// Heading to draw: the one the phone reported, else the direction of the last real move (more than 4 m), else the
/// previous heading, else none (an arrow pointing north for a parked driver would be wrong).
double? headingFor(GeoFix now, GeoFix? previous, {double? lastHeading}) {
  if (now.heading != null && (now.speedKmh ?? 1) >= 1) return now.heading;
  if (previous != null && MapGeo.distanceMeters(previous.point, now.point) > 4) {
    return MapGeo.bearing(previous.point, now.point);
  }
  return now.heading ?? lastHeading;
}

/// Straight-line metres from the driver to the target, for when the server sent no distance.
double? distanceToTarget(GeoFix? driver, OrderTracking t) {
  if (driver == null) return null;
  final target = trackingStageOf(t) == TrackingStage.waitingPickup ? (t.branch ?? t.destination) : t.destination;
  return target == null ? null : MapGeo.distanceMeters(driver.point, target);
}
