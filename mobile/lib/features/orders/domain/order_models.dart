import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter/foundation.dart';

/// Row in `GET /orders` (shape assumed: a subset of Order).
@immutable
class OrderSummary {
  const OrderSummary({
    required this.id,
    required this.number,
    required this.status,
    this.statusLabel,
    this.paymentStatus,
    this.fulfillmentType,
    this.placedAt,
    this.grandTotal,
    this.itemsCount,
    this.thumbnails = const [],
  });

  factory OrderSummary.fromJson(JsonMap json) {
    final items = asList(json['items']);
    return OrderSummary(
      id: asString(json['id']),
      number: asString(json['number']),
      status: asString(json['status']),
      statusLabel: asStringOrNull(json['status_label']),
      paymentStatus: asStringOrNull(json['payment_status']),
      fulfillmentType: asStringOrNull(json['fulfillment_type']),
      placedAt: asDate(json['placed_at']),
      grandTotal: Money.maybe(json['grand_total']) ?? Money.maybe(asMapOrNull(json['totals'])?['grand_total']),
      itemsCount: asIntOrNull(json['items_count']) ?? (items.isEmpty ? null : items.length),
      thumbnails: [
        ...asStringList(json['thumbnails']),
        ...items.map((i) => asStringOrNull(asMap(i)['image_url'])).whereType<String>(),
        if (json['image_url'] != null) asString(json['image_url']),
      ].take(4).toList(),
    );
  }

  final String id;
  final String number;
  final String status;
  final String? statusLabel;
  final String? paymentStatus;
  final String? fulfillmentType;
  final DateTime? placedAt;
  final Money? grandTotal;
  final int? itemsCount;
  final List<String> thumbnails;
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
  });

  factory Order.fromJson(JsonMap json) => Order(
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

/// Cancellation reason codes (assumed; server validates).
const cancelReasonCodes = ['changed_mind', 'ordered_by_mistake', 'delivery_too_long', 'found_better_price', 'other'];
const returnReasonCodes = ['damaged', 'wrong_item', 'not_as_described', 'changed_mind', 'other'];
