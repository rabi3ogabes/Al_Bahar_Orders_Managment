import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter/foundation.dart';
import 'package:latlong2/latlong.dart';

/// Row in `GET /orders` (`OrderPresenter::summary`).
@immutable
class OrderSummary {
  const OrderSummary({
    required this.id,
    required this.number,
    required this.status,
    this.statusLabel,
    this.paymentStatus,
    this.paymentMethod,
    this.fulfillmentType,
    this.placedAt,
    this.grandTotal,
    this.itemsCount,
    this.thumbnails = const [],
    this.seq = 0,
  });

  factory OrderSummary.fromJson(JsonMap json) {
    final items = asList(json['items']);
    return OrderSummary(
      seq: asInt(json['seq']),
      id: asString(json['id']),
      number: asString(json['number']),
      status: asString(json['status']),
      statusLabel: asStringOrNull(json['status_label']),
      paymentStatus: asStringOrNull(json['payment_status']),
      paymentMethod: asStringOrNull(json['payment_method']),
      fulfillmentType: asStringOrNull(json['fulfillment_type']),
      placedAt: asDate(json['placed_at']),
      grandTotal: Money.maybe(json['grand_total']) ?? Money.maybe(asMapOrNull(json['totals'])?['grand_total']),
      itemsCount: asIntOrNull(json['items_count']) ?? (items.isEmpty ? null : items.length),
      thumbnails: [
        ...asStringList(json['thumbnails']),
        ...items.map((i) => asStringOrNull(asMap(i)['image_url'])).whereType<String>(),
        if (json['thumbnail_url'] != null) asString(json['thumbnail_url']),
        if (json['image_url'] != null) asString(json['image_url']),
      ].take(4).toList(),
    );
  }

  final String id;
  final String number;
  final String status;
  final String? statusLabel;
  final String? paymentStatus;
  final String? paymentMethod;
  final String? fulfillmentType;
  final DateTime? placedAt;
  final Money? grandTotal;
  final int? itemsCount;
  final List<String> thumbnails;

  /// The order's version when it was read (see SeqGate): a real-time message with a newer one replaces what the read showed.
  final int seq;

  /// The same row after a real-time message (the status the customer sees changes at once).
  OrderSummary withLive({required String status, String? statusLabel, String? paymentStatus, required int seq}) =>
      OrderSummary(
        id: id,
        number: number,
        status: status,
        statusLabel: statusLabel ?? this.statusLabel,
        paymentStatus: paymentStatus ?? this.paymentStatus,
        paymentMethod: paymentMethod,
        fulfillmentType: fulfillmentType,
        placedAt: placedAt,
        grandTotal: grandTotal,
        itemsCount: itemsCount,
        thumbnails: thumbnails,
        seq: seq,
      );
}

@immutable
class OrderItem {
  const OrderItem({
    required this.id,
    required this.name,
    required this.quantity,
    this.productId,
    this.variantId,
    this.sku,
    this.variantName,
    this.imageUrl,
    this.unitPrice,
    this.lineTotal,
    this.branchId,
    this.fulfillmentId,
  });
  factory OrderItem.fromJson(JsonMap json) => OrderItem(
    id: asString(json['id']),
    productId: asStringOrNull(json['product_id']),
    variantId: asStringOrNull(json['variant_id']),
    sku: asStringOrNull(json['sku']),
    name: asString(json['name']),
    variantName: asStringOrNull(json['variant_name']),
    imageUrl: asStringOrNull(json['image_url']),
    quantity: asInt(json['quantity'], 1),
    unitPrice: Money.maybe(json['unit_price']),
    lineTotal: Money.maybe(json['line_total']),
    branchId: asStringOrNull(json['branch_id']),
    fulfillmentId: asStringOrNull(json['fulfillment_id']),
  );
  final String id;
  final String? productId;
  final String? variantId;
  final String? sku;
  final String name;
  final String? variantName;
  final String? imageUrl;
  final int quantity;
  final Money? unitPrice;
  final Money? lineTotal;
  final String? branchId;
  final String? fulfillmentId;
}

@immutable
class FulfillmentBranch {
  const FulfillmentBranch({required this.id, required this.name, this.phone, this.latitude, this.longitude});
  factory FulfillmentBranch.fromJson(JsonMap json) => FulfillmentBranch(
    id: asString(json['id']),
    name: asString(json['name']),
    phone: asStringOrNull(json['phone']),
    latitude: asDoubleOrNull(json['latitude']),
    longitude: asDoubleOrNull(json['longitude']),
  );
  final String id;
  final String name;
  final String? phone;
  final double? latitude;
  final double? longitude;
}

@immutable
class DeliveryInfo {
  const DeliveryInfo({
    this.trackingNumber,
    this.status,
    this.provider,
    this.driverName,
    this.driverPhone,
    this.eta,
    this.trackingUrl,
  });
  static DeliveryInfo? maybe(Object? json) {
    final m = asMapOrNull(json);
    if (m == null) return null;
    return DeliveryInfo(
      trackingNumber: asStringOrNull(m['tracking_number']),
      status: asStringOrNull(m['status']),
      provider: asStringOrNull(m['provider']),
      driverName: asStringOrNull(m['driver_name']),
      driverPhone: asStringOrNull(m['driver_phone']),
      eta: asDate(m['eta']),
      trackingUrl: asStringOrNull(m['tracking_url']),
    );
  }

  final String? trackingNumber;
  final String? status;
  final String? provider;
  final String? driverName;
  final String? driverPhone;
  final DateTime? eta;
  final String? trackingUrl;

  DeliveryInfo withStatus(String? next) => DeliveryInfo(
    trackingNumber: trackingNumber,
    status: next ?? status,
    provider: provider,
    driverName: driverName,
    driverPhone: driverPhone,
    eta: eta,
    trackingUrl: trackingUrl,
  );
}

@immutable
class Fulfillment {
  const Fulfillment({
    required this.id,
    required this.number,
    required this.branch,
    required this.type,
    required this.status,
    this.statusLabel,
    this.slot,
    this.delivery,
    this.pickupStatus,
    this.pickupTokenExpiresAt,
  });
  factory Fulfillment.fromJson(JsonMap json) {
    final pickup = asMapOrNull(json['pickup']);
    return Fulfillment(
      id: asString(json['id']),
      number: asString(json['number']),
      branch: FulfillmentBranch.fromJson(asMap(json['branch'])),
      type: asString(json['type'], 'delivery'),
      status: asString(json['status']),
      statusLabel: asStringOrNull(json['status_label']),
      slot: asMapOrNull(json['slot']),
      delivery: DeliveryInfo.maybe(json['delivery']),
      pickupStatus: asStringOrNull(pickup?['status']),
      pickupTokenExpiresAt: asDate(pickup?['token_expires_at']),
    );
  }
  final String id;
  final String number;
  final FulfillmentBranch branch;
  final String type;
  final String status;
  final String? statusLabel;
  final JsonMap? slot;
  final DeliveryInfo? delivery;
  final String? pickupStatus;
  final DateTime? pickupTokenExpiresAt;

  /// The same part after a real-time message: its status and its delivery's status (and the words for them) change at once.
  Fulfillment withLive({required String status, String? statusLabel, String? deliveryStatus}) => Fulfillment(
    id: id,
    number: number,
    branch: branch,
    type: type,
    status: status,
    statusLabel: statusLabel ?? this.statusLabel,
    slot: slot,
    delivery: deliveryStatus == null
        ? delivery
        : (delivery?.withStatus(deliveryStatus) ?? DeliveryInfo(status: deliveryStatus)),
    pickupStatus: pickupStatus,
    pickupTokenExpiresAt: pickupTokenExpiresAt,
  );

  bool get isPickupReady => type == 'pickup' && (status == 'pickup_ready' || status == 'ready');
  bool get isOnTheWay => status == 'dispatched' || status == 'out_for_delivery';
}

@immutable
class Order {
  const Order({
    required this.id,
    required this.number,
    required this.status,
    required this.items,
    required this.fulfillments,
    this.statusLabel,
    this.paymentStatus,
    this.paymentMethod,
    this.fulfillmentType,
    this.placedAt,
    this.totals,
    this.address,
    this.canCancel = false,
    this.canPay = false,
    this.canReturn = false,
    this.canReview = false,
    this.seq = 0,
  });

  factory Order.fromJson(JsonMap json) => Order(
    seq: asInt(json['seq']),
    id: asString(json['id']),
    number: asString(json['number']),
    status: asString(json['status']),
    statusLabel: asStringOrNull(json['status_label']),
    paymentStatus: asStringOrNull(json['payment_status']),
    paymentMethod: asStringOrNull(json['payment_method']),
    fulfillmentType: asStringOrNull(json['fulfillment_type']),
    placedAt: asDate(json['placed_at']),
    totals: json['totals'] is Map ? OrderTotals.fromJson(asMap(json['totals'])) : null,
    address: asMapOrNull(json['address']),
    items: mapList(json['items'], OrderItem.fromJson),
    fulfillments: mapList(json['fulfillments'], Fulfillment.fromJson),
    canCancel: asBool(json['can_cancel']),
    canPay: asBool(json['can_pay']),
    canReturn: asBool(json['can_return']),
    canReview: asBool(json['can_review']),
  );

  final String id;
  final String number;
  final String status;
  final String? statusLabel;
  final String? paymentStatus;
  final String? paymentMethod;
  final String? fulfillmentType;
  final DateTime? placedAt;
  final OrderTotals? totals;

  /// Address snapshot (same keys as Address).
  final JsonMap? address;
  final List<OrderItem> items;
  final List<Fulfillment> fulfillments;
  final bool canCancel;
  final bool canPay;
  final bool canReturn;
  final bool canReview;

  /// The order's version when it was read (see SeqGate).
  final int seq;

  /// The same order after a real-time message. The buttons that depend on the state are only ever switched OFF here (an order that
  /// finished or is awaiting a decision cannot be paid or cancelled); the read that confirms the message switches the right ones on.
  Order withLive({
    required int seq,
    required String status,
    String? statusLabel,
    String? paymentStatus,
    Map<String, ({String status, String? statusLabel, String? deliveryStatus})> parts = const {},
  }) {
    const closed = {'completed', 'cancelled', 'partially_cancelled', 'returned', 'cancellation_requested'};
    return Order(
      id: id,
      number: number,
      status: status,
      statusLabel: statusLabel ?? this.statusLabel,
      paymentStatus: paymentStatus ?? this.paymentStatus,
      paymentMethod: paymentMethod,
      fulfillmentType: fulfillmentType,
      placedAt: placedAt,
      totals: totals,
      address: address,
      items: items,
      fulfillments: [
        for (final f in fulfillments)
          parts[f.id] == null
              ? f
              : f.withLive(
                  status: parts[f.id]!.status,
                  statusLabel: parts[f.id]!.statusLabel,
                  deliveryStatus: parts[f.id]!.deliveryStatus,
                ),
      ],
      canCancel: canCancel && !closed.contains(status) && status != 'out_for_delivery',
      canPay: canPay && status == 'pending_payment',
      canReturn: canReturn,
      canReview: canReview || status == 'completed',
      seq: seq,
    );
  }

  List<OrderItem> itemsFor(Fulfillment f) => items.where((i) => i.fulfillmentId == f.id).toList();
}

@immutable
class TimelineEntry {
  const TimelineEntry({required this.kind, required this.status, required this.label, this.at, this.fulfillmentId});
  factory TimelineEntry.fromJson(JsonMap json) => TimelineEntry(
    kind: asString(json['kind'], 'order'),
    status: asString(json['status']),
    label: asString(json['label'], asString(json['status'])),
    at: asDate(json['at']),
    fulfillmentId: asStringOrNull(json['fulfillment_id']),
  );
  final String kind;
  final String status;
  final String label;
  final DateTime? at;
  final String? fulfillmentId;
}

@immutable
class PickupPass {
  const PickupPass({required this.qrPayload, this.expiresAt, this.branchName, this.status});
  factory PickupPass.fromJson(JsonMap json) => PickupPass(
    qrPayload: asString(json['qr_payload']),
    expiresAt: asDate(json['expires_at']),
    branchName: asStringOrNull(asMapOrNull(json['branch'])?['name'] ?? json['branch']),
    status: asStringOrNull(json['status']),
  );
  final String qrPayload;
  final DateTime? expiresAt;
  final String? branchName;
  final String? status;

  @override
  String toString() => 'PickupPass(status: $status)'; // never log the payload
}

/// Cancellation reason codes (subset of the backend's list, which also has `payment_issue`).
const cancelReasonCodes = ['changed_mind', 'ordered_by_mistake', 'delivery_too_long', 'found_better_price', 'other'];
const returnReasonCodes = ['damaged', 'wrong_item', 'not_as_described', 'changed_mind', 'other'];

/// Where the driver is right now (`GET /orders/{id}/tracking`); live moves arrive on `private-orders.{id}`.
///
/// The server grows this payload over time, so every field but `tracking` is optional and the old shape still reads:
/// `driver: {name, lat, lng, at}` (first version); the current one inlines the whole fix in `driver` (`name` is the first
/// name, plus `vehicle`, `status`, `lat`, `lng`, `heading`, `speed_kmh`, `accuracy_m`, `at`, `age_seconds`) beside `live`,
/// `delivery_status`, `eta: {minutes, distance_m, updated_at}` and `stale_after_seconds`. A separate `position` object is
/// read too, in case the fix moves there.
@immutable
class OrderTracking {
  const OrderTracking({
    required this.tracking,
    this.driverName,
    this.driverFirstName,
    this.vehicle,
    this.driverPhone,
    this.position,
    this.destination,
    this.branch,
    this.branchName,
    this.status,
    this.eta,
    this.deliveredAt,
    this.hasDriver = false,
    this.live = false,
    this.deliveryStatus,
    this.staleAfter,
  });

  factory OrderTracking.fromJson(Map<String, dynamic> j, {DateTime? receivedAt}) {
    LatLng? pt(Object? o) {
      if (o is! Map) return null;
      final lat = o['lat'] ?? o['latitude'];
      final lng = o['lng'] ?? o['longitude'];
      if (lat is! num || lng is! num || !MapGeo.isFinitePair(lat.toDouble(), lng.toDouble())) return null;
      return LatLng(lat.toDouble(), lng.toDouble());
    }

    final d = j['driver'] is Map ? Map<String, dynamic>.from(j['driver'] as Map) : null;
    final name = d == null ? null : asStringOrNull(d['name']);
    final first = d == null ? null : (asStringOrNull(d['first_name']) ?? name?.trim().split(RegExp(r'\s+')).first);
    final branch = j['branch'] is Map ? Map<String, dynamic>.from(j['branch'] as Map) : null;
    return OrderTracking(
      tracking: j['tracking'] == true,
      hasDriver: d != null,
      driverName: name,
      driverFirstName: first,
      vehicle: d == null ? null : asStringOrNull(d['vehicle']),
      driverPhone: d == null ? null : asStringOrNull(d['phone']),
      // The current payload nests the fix under `position`; the first version put it on `driver` itself.
      position: GeoFix.tryParse(j['position'], receivedAt: receivedAt) ?? GeoFix.tryParse(d, receivedAt: receivedAt),
      destination: pt(j['destination']),
      branch: pt(branch),
      branchName: branch == null ? null : asStringOrNull(branch['name']),
      status: asStringOrNull(j['status']),
      eta: TrackingEta.tryParse(j['eta']),
      deliveredAt: asDate(j['delivered_at']),
      live: j['live'] == true,
      deliveryStatus: asStringOrNull(j['delivery_status']) ?? (d == null ? null : asStringOrNull(d['status'])),
      staleAfter: j['stale_after_seconds'] is num && (j['stale_after_seconds'] as num) > 0
          ? Duration(seconds: (j['stale_after_seconds'] as num).round())
          : null,
    );
  }

  final bool tracking;

  /// True when the server named a driver for this order (even before the first position arrives).
  final bool hasDriver;
  final String? driverName;
  final String? driverFirstName;
  final String? vehicle;
  final String? driverPhone;

  /// The driver's last fix; null until the driver's phone has reported one.
  final GeoFix? position;
  final LatLng? destination;
  final LatLng? branch;
  final String? branchName;

  /// Order status code (`out_for_delivery`, `completed` ...).
  final String? status;

  /// Delivery step (`accepted`, `picked_up`, `out_for_delivery`, `delivered`); null before a driver is on the order.
  final String? deliveryStatus;

  /// True while the order is on the road with a driver sharing a position.
  final bool live;

  /// How old a position may be before it is shown as "last seen"; the server's setting, null on older servers.
  final Duration? staleAfter;
  final TrackingEta? eta;
  final DateTime? deliveredAt;

  /// The driver's coordinates (kept for callers that only need the point).
  LatLng? get driver => position?.point;
}

/// The server's arrival estimate.
@immutable
class TrackingEta {
  const TrackingEta({this.minutes, this.distanceM, this.updatedAt});

  static TrackingEta? tryParse(Object? raw) {
    if (raw is! Map) return null;
    final minutes = raw['minutes'];
    final distance = raw['distance_m'];
    final eta = TrackingEta(
      minutes: minutes is num && minutes.isFinite && minutes >= 0 ? minutes.round() : null,
      distanceM: distance is num && distance.isFinite && distance >= 0 ? distance.toDouble() : null,
      updatedAt: asDate(raw['updated_at']),
    );
    return eta.minutes == null && eta.distanceM == null ? null : eta;
  }

  final int? minutes;
  final double? distanceM;
  final DateTime? updatedAt;
}
