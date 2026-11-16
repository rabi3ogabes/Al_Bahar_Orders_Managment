import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';
import 'package:latlong2/latlong.dart';

/// Which of the two staff apps this build is.
enum OpsRole { driver, staff }

/// How the staff and driver apps ask for a status change (the dashboard's "Staff and driver apps" setting, served by
/// `GET /app-config` as `ops.status_style`): [buttons] is the plain button, [slide] is "slide to confirm".
enum OpsStatusStyle {
  buttons,
  slide;

  /// Anything but the exact word "slide" is [buttons], so an older server or a wrong value keeps the buttons.
  static OpsStatusStyle fromCode(Object? code) => code == 'slide' ? slide : buttons;
}

/// What the staff and driver apps read from the public `GET /app-config`: how a status is changed (`ops.status_style`) and whether the
/// customer's alternative phone number is shown (`alternate_phone_enabled`, the dashboard's switch).
@immutable
class OpsAppConfig {
  const OpsAppConfig({this.statusStyle = OpsStatusStyle.buttons, this.alternatePhoneEnabled = true});

  /// A missing or wrongly typed value keeps today's behaviour: plain buttons, and the alternative number shown.
  factory OpsAppConfig.fromJson(JsonMap json) => OpsAppConfig(
    statusStyle: OpsStatusStyle.fromCode(asMap(json['ops'])['status_style']),
    alternatePhoneEnabled: asBool(json['alternate_phone_enabled'], true),
  );

  final OpsStatusStyle statusStyle;
  final bool alternatePhoneEnabled;
}

@immutable
class OpsAddress {
  const OpsAddress({
    required this.line,
    this.zone,
    this.street,
    this.building,
    this.unit,
    this.area,
    this.lat,
    this.lng,
    this.notes,
  });

  factory OpsAddress.fromJson(Object? raw) {
    final j = raw is Map ? asMap(raw) : <String, dynamic>{};
    final zone = asStringOrNull(j['zone_number']);
    final street = asStringOrNull(j['street_number']);
    final building = asStringOrNull(j['building_number']);
    final unit = asStringOrNull(j['unit_number']);
    final area = asStringOrNull(j['area']);
    final parts = <String>[
      if (zone != null) 'Zone $zone',
      if (street != null) 'Street $street',
      if (building != null) 'Bldg $building',
      if (unit != null) 'Unit $unit',
      ?area,
    ];
    return OpsAddress(
      line: parts.isEmpty ? asString(j['label'], '—') : parts.join(' · '),
      zone: zone,
      street: street,
      building: building,
      unit: unit,
      area: area,
      lat: asDoubleOrNull(j['latitude']),
      lng: asDoubleOrNull(j['longitude']),
      notes: asStringOrNull(j['notes']) ?? asStringOrNull(j['landmark']),
    );
  }

  /// English one-liner (also the text sent to the maps app when there are no coordinates); screens show
  /// `OpsStrings.address` so the words follow the app language.
  final String line;
  final String? zone;
  final String? street;
  final String? building;
  final String? unit;
  final String? area;
  final double? lat;
  final double? lng;
  final String? notes;
}

/// One product line of a parcel (`items` on a delivery job and on a queue order). [quantity] is what is still to be
/// delivered (cancelled units are already left out); there are no prices.
@immutable
class OpsItem {
  const OpsItem({required this.id, required this.name, required this.quantity, this.variantName, this.sku});

  factory OpsItem.fromJson(JsonMap j) => OpsItem(
    id: asString(j['id']),
    name: asString(j['name']),
    variantName: asStringOrNull(j['variant_name']),
    sku: asStringOrNull(j['sku']),
    quantity: asInt(j['quantity'], 1),
  );

  /// The list from a response; an older server that has no `items` gives an empty list (screens then show the count).
  static List<OpsItem> listFrom(Object? raw) => mapList(raw, OpsItem.fromJson);

  final String id;
  final String name;
  final String? variantName;
  final String? sku;
  final int quantity;
}

/// An empty or blank second number is "none".
String? _alternatePhone(Object? v) {
  final s = asStringOrNull(v)?.trim();
  return s == null || s.isEmpty ? null : s;
}

/// One delivery job as the driver sees it.
@immutable
class DriverJob {
  const DriverJob({
    required this.id,
    required this.status,
    required this.orderNumber,
    required this.customerName,
    required this.customerPhone,
    required this.address,
    required this.paymentMethod,
    required this.codAmount,
    required this.requiresProof,
    required this.branchName,
    required this.scheduledFor,
    this.items = const [],
    this.alternatePhone,
    this.orderId = '',
    this.seq = 0,
  });

  factory DriverJob.fromJson(JsonMap j) => DriverJob(
    orderId: asString(j['order_id']),
    seq: asInt(j['seq']),
    id: asString(j['id']),
    status: asString(j['status']),
    orderNumber: asString(j['order_number']),
    customerName: asString(j['customer_name']),
    customerPhone: asStringOrNull(j['customer_phone']),
    alternatePhone: _alternatePhone(j['alternate_phone']),
    address: OpsAddress.fromJson(j['address']),
    paymentMethod: asString(j['payment_method']),
    codAmount: double.tryParse(asString(j['cod_amount'], '0')) ?? 0,
    requiresProof: asBool(j['requires_proof'], true),
    branchName: asStringOrNull(asMapOrNull(j['branch'])?['name']) ?? '',
    scheduledFor: asDate(j['scheduled_for']),
    items: OpsItem.listFrom(j['items']),
  );

  final String id;
  final String status;
  final String orderNumber;
  final String customerName;
  final String? customerPhone;

  /// The customer's second number, to call when the main one is not answered. Null when they gave none, or when an older server does not send it.
  /// Personal data: shown only in the driver's own job windows, never logged or put in a notification.
  final String? alternatePhone;
  final OpsAddress address;
  final String paymentMethod;
  final double codAmount;
  final bool requiresProof;
  final String branchName;
  final DateTime? scheduledFor;

  /// What is in the parcel; empty when the server did not send it.
  final List<OpsItem> items;

  /// The order this job belongs to, and the order's version when this job was read (see SeqGate): a real-time message with a newer
  /// version replaces what a read showed, an older one is ignored.
  final String orderId;
  final int seq;

  /// The same job in another state (a real-time message said it moved).
  DriverJob withState({String? status, int? seq}) => DriverJob(
    id: id,
    status: status ?? this.status,
    orderNumber: orderNumber,
    customerName: customerName,
    customerPhone: customerPhone,
    address: address,
    paymentMethod: paymentMethod,
    codAmount: codAmount,
    requiresProof: requiresProof,
    branchName: branchName,
    scheduledFor: scheduledFor,
    items: items,
    alternatePhone: alternatePhone,
    orderId: orderId,
    seq: seq ?? this.seq,
  );

  bool get isCod => paymentMethod == 'cod' && codAmount > 0;

  /// The next driver action for this status (null when nothing is left to do).
  String? get nextAction => switch (status) {
    'assigned' => 'accept',
    'accepted' => 'pickup',
    'picked_up' => 'out-for-delivery',
    'out_for_delivery' => 'deliver',
    // A failed or rescheduled delivery can go out again (or be handed back to the branch).
    'failed' || 'rescheduled' => 'out-for-delivery',
    _ => null,
  };

  /// A failed delivery may also be brought back to the branch.
  bool get canReturn => status == 'failed';

  /// The driver can report "could not deliver" from these steps.
  bool get canFail => status == 'picked_up' || status == 'out_for_delivery';
}

@immutable
class DriverMe {
  const DriverMe({
    this.id = '',
    required this.name,
    required this.onDuty,
    required this.openJobs,
    required this.branches,
  });

  factory DriverMe.fromJson(JsonMap j) => DriverMe(
    id: asString(j['id']),
    name: asString(j['name']),
    onDuty: asBool(j['on_duty']),
    openJobs: asInt(j['open_jobs']),
    branches: mapList(j['branches'], (b) => asString(b['name'])),
  );

  final String id;
  final String name;
  final bool onDuty;
  final int openJobs;
  final List<String> branches;
}

@immutable
class StaffOverview {
  const StaffOverview({
    required this.branchId,
    required this.branchName,
    required this.autoDispatch,
    required this.fresh,
    required this.preparing,
    required this.ready,
    required this.out,
    required this.failed,
    required this.waitingForDriver,
    required this.driversOnDuty,
    required this.driversTotal,
  });

  factory StaffOverview.fromJson(JsonMap j) {
    final b = asMap(j['branch']);
    return StaffOverview(
      branchId: asString(b['id']),
      branchName: asString(b['name']),
      autoDispatch: asString(b['dispatch_mode']) == 'auto',
      fresh: asInt(j['new']),
      preparing: asInt(j['preparing']),
      ready: asInt(j['ready']),
      out: asInt(j['out_for_delivery']),
      failed: asInt(j['failed']),
      waitingForDriver: asInt(j['waiting_for_driver']),
      driversOnDuty: asInt(j['drivers_on_duty']),
      driversTotal: asInt(j['drivers_total']),
    );
  }

  final String branchId;
  final String branchName;
  final bool autoDispatch;
  final int fresh;
  final int preparing;
  final int ready;
  final int out;
  final int failed;
  final int waitingForDriver;
  final int driversOnDuty;
  final int driversTotal;

  /// The same counts after some orders moved between stages (a real-time message said so): each number moves by the given amount,
  /// never below zero. The next read of the server replaces it.
  StaffOverview shifted({
    int fresh = 0,
    int preparing = 0,
    int ready = 0,
    int out = 0,
    int failed = 0,
    int waitingForDriver = 0,
  }) => StaffOverview(
    branchId: branchId,
    branchName: branchName,
    autoDispatch: autoDispatch,
    fresh: _atLeastZero(this.fresh + fresh),
    preparing: _atLeastZero(this.preparing + preparing),
    ready: _atLeastZero(this.ready + ready),
    out: _atLeastZero(this.out + out),
    failed: _atLeastZero(this.failed + failed),
    waitingForDriver: _atLeastZero(this.waitingForDriver + waitingForDriver),
    driversOnDuty: driversOnDuty,
    driversTotal: driversTotal,
  );

  static int _atLeastZero(int n) => n < 0 ? 0 : n;
}

/// A delivery order in the branch queue.
@immutable
class QueueOrder {
  const QueueOrder({
    required this.fulfillmentId,
    required this.status,
    required this.number,
    required this.customerName,
    required this.customerPhone,
    required this.address,
    required this.itemsCount,
    required this.paymentMethod,
    required this.total,
    required this.deliveryStatus,
    required this.deliveryId,
    required this.driverName,
    required this.driverId,
    this.items = const [],
    this.alternatePhone,
    this.orderId = '',
    this.seq = 0,
  });

  factory QueueOrder.fromJson(JsonMap j) {
    final d = asMapOrNull(j['delivery']);
    final driver = asMapOrNull(d?['driver']);
    return QueueOrder(
      orderId: asString(j['order_id']),
      seq: asInt(j['seq']),
      fulfillmentId: asString(j['fulfillment_id']),
      status: asString(j['status']),
      number: asString(j['number']),
      customerName: asString(j['customer_name']),
      customerPhone: asStringOrNull(j['customer_phone']),
      alternatePhone: _alternatePhone(j['alternate_phone']),
      address: OpsAddress.fromJson(j['address']),
      itemsCount: asInt(j['items_count']),
      paymentMethod: asString(j['payment_method']),
      total: asString(j['grand_total'], '0'),
      deliveryStatus: asStringOrNull(d?['status']),
      deliveryId: asStringOrNull(d?['id']),
      driverName: asStringOrNull(driver?['name']),
      driverId: asStringOrNull(driver?['id']),
      items: OpsItem.listFrom(j['items']),
    );
  }

  final String fulfillmentId;
  final String status;
  final String number;
  final String customerName;
  final String? customerPhone;

  /// The customer's second number (see [DriverJob.alternatePhone]); null when none or when an older server omits it.
  final String? alternatePhone;
  final OpsAddress address;
  final int itemsCount;
  final String paymentMethod;
  final String total;
  final String? deliveryStatus;
  final String? deliveryId;
  final String? driverName;
  final String? driverId;

  /// The order this row belongs to and the order's version when the row was read (see SeqGate).
  final String orderId;
  final int seq;

  /// The same row in another state (a real-time message said the part or its delivery moved). The delivery id and the driver are
  /// replaced together: pass [clearDelivery] when the delivery is gone.
  QueueOrder withState({
    String? status,
    int? seq,
    String? deliveryStatus,
    String? deliveryId,
    String? driverId,
    String? driverName,
    bool clearDriver = false,
  }) => QueueOrder(
    fulfillmentId: fulfillmentId,
    status: status ?? this.status,
    number: number,
    customerName: customerName,
    customerPhone: customerPhone,
    address: address,
    itemsCount: itemsCount,
    paymentMethod: paymentMethod,
    total: total,
    deliveryStatus: deliveryStatus ?? this.deliveryStatus,
    deliveryId: deliveryId ?? this.deliveryId,
    driverName: clearDriver ? null : (driverName ?? this.driverName),
    driverId: clearDriver ? null : (driverId ?? this.driverId),
    items: items,
    alternatePhone: alternatePhone,
    orderId: orderId,
    seq: seq ?? this.seq,
  );

  /// What is in the parcel; empty when the server did not send it (screens then show [itemsCount]).
  final List<OpsItem> items;

  /// Staff step for the order status: accept → start preparing → mark ready; then assign a driver.
  String? get nextStep => switch (status) {
    'pending' => 'accepted',
    'accepted' => 'preparing',
    'preparing' => 'ready',
    _ => null,
  };
}

@immutable
class BranchDriver {
  const BranchDriver({
    required this.id,
    required this.name,
    required this.phone,
    required this.onDuty,
    required this.openJobs,
  });

  factory BranchDriver.fromJson(JsonMap j) => BranchDriver(
    id: asString(j['id']),
    name: asString(j['name']),
    phone: asStringOrNull(j['phone']),
    onDuty: asBool(j['on_duty']),
    openJobs: asInt(j['open_jobs']),
  );

  final String id;
  final String name;
  final String? phone;
  final bool onDuty;
  final int openJobs;
}

/// A category for the product form; `name` already carries the parent path ("Living › Sofas").
class ShopCategory {
  const ShopCategory({required this.id, required this.name, this.children = const []});

  factory ShopCategory.fromJson(JsonMap j) => ShopCategory(
    id: asString(j['id']),
    name: asString(j['name']),
    children: [for (final c in asList(j['children'])) ShopCategory.fromJson(asMap(c))],
  );

  final String id;
  final String name;
  final List<ShopCategory> children;
}

/// Short and full description in both languages, as written by Claude or typed by hand.
class ProductDraftText {
  const ProductDraftText({
    this.shortEn = '',
    this.descriptionEn = '',
    this.shortAr = '',
    this.descriptionAr = '',
    this.nameEn = '',
    this.nameAr = '',
  });

  factory ProductDraftText.fromJson(JsonMap j) {
    final en = asMap(j['en']);
    final ar = asMap(j['ar']);
    final name = j['name'] is Map ? asMap(j['name']) : const <String, dynamic>{};
    return ProductDraftText(
      nameEn: asString(name['en']),
      nameAr: asString(name['ar']),
      shortEn: asString(en['short']),
      descriptionEn: asString(en['description']),
      shortAr: asString(ar['short']),
      descriptionAr: asString(ar['description']),
    );
  }

  final String shortEn;
  final String descriptionEn;
  final String shortAr;
  final String descriptionAr;

  /// The title in each language; the one that was missing is translated.
  final String nameEn;
  final String nameAr;
}

/// One driver on the live map (`GET /admin/dispatch/fleet`): last position, duty, and the job in hand.
///
/// The server now sends the whole position (`heading`, `speed_kmh`, `accuracy_m`, `at`, `age_seconds`), the vehicle and
/// the job's destination; an older server sends only `lat`, `lng` and `last_seen_at`, which still works.
@immutable
class FleetDriver {
  const FleetDriver({
    required this.id,
    required this.name,
    required this.phone,
    required this.onDuty,
    required this.lat,
    required this.lng,
    required this.lastSeen,
    required this.openJobs,
    this.orderNumber,
    this.jobStatus,
    this.vehicle,
    this.fix,
    this.destination,
    this.battery,
  });

  factory FleetDriver.fromJson(JsonMap j) {
    final job = j['job'] is Map ? asMap(j['job']) : const <String, dynamic>{};
    final dest = job['destination'];
    final lat = j['lat'] == null ? null : asDouble(j['lat']);
    final lng = j['lng'] == null ? null : asDouble(j['lng']);
    final destLat = dest is Map && dest['lat'] is num ? (dest['lat'] as num).toDouble() : null;
    final destLng = dest is Map && dest['lng'] is num ? (dest['lng'] as num).toDouble() : null;
    return FleetDriver(
      id: asString(j['id']),
      name: asString(j['name']),
      phone: asStringOrNull(j['phone']),
      onDuty: asBool(j['on_duty']),
      lat: lat,
      lng: lng,
      lastSeen: DateTime.tryParse(asString(j['last_seen_at'])),
      openJobs: asInt(j['open_jobs']),
      orderNumber: asStringOrNull(job['order_number']),
      jobStatus: asStringOrNull(job['status']),
      vehicle: asStringOrNull(j['vehicle']),
      fix: GeoFix.tryParse(j),
      destination: MapGeo.isFinitePair(destLat, destLng) ? LatLng(destLat!, destLng!) : null,
      battery: j['battery'] is num ? (j['battery'] as num).round() : null,
    );
  }

  final String id;
  final String name;
  final String? phone;
  final bool onDuty;
  final double? lat;
  final double? lng;
  final DateTime? lastSeen;
  final int openJobs;
  final String? orderNumber;
  final String? jobStatus;
  final String? vehicle;

  /// The last position with its heading, speed, accuracy and age; null when the driver has not reported one.
  final GeoFix? fix;

  /// Where the job in hand is going.
  final LatLng? destination;
  final int? battery;

  bool get hasPosition => lat != null && lng != null;
}

/// A money amount as the server sends it (`{"amount": "1000.00", "currency": "QAR"}`). Shown as text; the phone never adds money up.
@immutable
class OpsMoney {
  const OpsMoney(this.amount, {this.currency = 'QAR'});

  /// `{amount, currency}`; a missing or malformed value is "0.00", never an error.
  factory OpsMoney.from(Object? raw) {
    final j = raw is Map ? asMap(raw) : const <String, dynamic>{};
    return OpsMoney(asString(j['amount'], '0.00'), currency: asString(j['currency'], 'QAR'));
  }

  static const zero = OpsMoney('0.00');

  final String amount;
  final String currency;

  /// For comparing only (is it more than the original price, is it zero); never for adding up.
  double get value => double.tryParse(amount) ?? 0;

  /// The amount without a minus sign: "Customer pays QAR 25.00 less" is built from the difference, which is zero or negative.
  String get unsigned => amount.startsWith('-') ? amount.substring(1) : amount;

  bool get isZero => value == 0;

  @override
  bool operator ==(Object other) => other is OpsMoney && other.amount == amount && other.currency == currency;

  @override
  int get hashCode => Object.hash(amount, currency);
}

/// What an earlier correction already did to a line (`adjustment` of an item of the adjustments context), so the staff see it before
/// changing the line again. The contract only promises `null` or this small object.
@immutable
class AdjustMark {
  const AdjustMark({required this.kind, this.originalQuantity});

  static AdjustMark? maybe(Object? raw) {
    if (raw is! Map) return null;
    final j = asMap(raw);
    return AdjustMark(kind: asString(j['kind']), originalQuantity: asIntOrNull(j['original_quantity']));
  }

  /// `reduced`, `removed`, `replaced` or `replacement`.
  final String kind;
  final int? originalQuantity;
}

/// One product line of the parcel, as the "Missing an item?" flow lists it (`items` of `GET …/adjustments/context`).
@immutable
class AdjustItem {
  const AdjustItem({
    required this.orderItemId,
    required this.name,
    required this.quantity,
    required this.unitPrice,
    required this.lineTotal,
    this.productId,
    this.variantId,
    this.sku,
    this.variantName,
    this.imageUrl,
    this.mark,
  });

  factory AdjustItem.fromJson(JsonMap j) => AdjustItem(
    orderItemId: asString(j['order_item_id']),
    productId: asStringOrNull(j['product_id']),
    variantId: asStringOrNull(j['variant_id']),
    sku: asStringOrNull(j['sku']),
    name: asString(j['name']),
    variantName: asStringOrNull(j['variant_name']),
    imageUrl: asStringOrNull(j['image_url']),
    quantity: asInt(j['quantity']),
    unitPrice: OpsMoney.from(j['unit_price']),
    lineTotal: OpsMoney.from(j['line_total']),
    mark: AdjustMark.maybe(j['adjustment']),
  );

  final String orderItemId;
  final String? productId;
  final String? variantId;
  final String? sku;
  final String name;
  final String? variantName;
  final String? imageUrl;

  /// What is still in the parcel; a line that an earlier correction already emptied (0) cannot be changed again.
  final int quantity;
  final OpsMoney unitPrice;
  final OpsMoney lineTotal;
  final AdjustMark? mark;

  bool get canChange => quantity > 0;
}

/// `GET /admin/fulfillments/{id}/adjustments/context`: whether the parcel can still be corrected, why not, the reasons to choose from
/// and the lines.
@immutable
class AdjustContext {
  const AdjustContext({
    required this.canAdjust,
    required this.reasons,
    required this.items,
    this.blockedReason,
    this.grandTotal = OpsMoney.zero,
    this.paymentMethod,
    this.paymentStatus,
  });

  factory AdjustContext.fromJson(JsonMap j) {
    final totals = asMap(j['totals']);
    final reasons = asStringList(j['reasons']);
    return AdjustContext(
      canAdjust: asBool(j['can_adjust']),
      blockedReason: asStringOrNull(j['blocked_reason']),
      reasons: reasons.isEmpty ? defaultReasons : reasons,
      items: mapList(j['items'], AdjustItem.fromJson),
      grandTotal: OpsMoney.from(totals['grand_total']),
      paymentMethod: asStringOrNull(totals['payment_method']),
      paymentStatus: asStringOrNull(totals['payment_status']),
    );
  }

  /// The reasons of the contract, used when an older answer lists none.
  static const defaultReasons = ['out_of_stock', 'not_found', 'damaged', 'quality', 'other'];

  final bool canAdjust;

  /// `status` (not accepted or preparing) or `none`.
  final String? blockedReason;
  final List<String> reasons;
  final List<AdjustItem> items;
  final OpsMoney grandTotal;
  final String? paymentMethod;
  final String? paymentStatus;

  bool get isCash => paymentMethod == 'cod';
}

/// A product the branch has, offered as a replacement (`GET …/substitutes`).
@immutable
class Substitute {
  const Substitute({
    required this.variantId,
    required this.name,
    required this.unitPrice,
    required this.available,
    this.productId,
    this.variantName,
    this.sku,
    this.imageUrl,
  });

  factory Substitute.fromJson(JsonMap j) => Substitute(
    variantId: asString(j['variant_id']),
    productId: asStringOrNull(j['product_id']),
    name: asString(j['name']),
    variantName: asStringOrNull(j['variant_name']),
    sku: asStringOrNull(j['sku']),
    imageUrl: asStringOrNull(j['image_url']),
    unitPrice: OpsMoney.from(j['unit_price']),
    available: asInt(j['available']),
  );

  final String variantId;
  final String? productId;
  final String name;
  final String? variantName;
  final String? sku;
  final String? imageUrl;
  final OpsMoney unitPrice;
  final int available;
}

/// One change of a correction, as it is sent (`changes` of `POST …/adjustments`).
@immutable
sealed class AdjustChange {
  const AdjustChange(this.orderItemId);

  final String orderItemId;

  JsonMap toJson();
}

/// Fewer of this line than ordered (at least 1; none at all is [AdjustRemove]).
final class AdjustReduce extends AdjustChange {
  const AdjustReduce(super.orderItemId, this.newQuantity);
  final int newQuantity;

  @override
  JsonMap toJson() => {'order_item_id': orderItemId, 'action': 'reduce', 'new_quantity': newQuantity};
}

final class AdjustRemove extends AdjustChange {
  const AdjustRemove(super.orderItemId);

  @override
  JsonMap toJson() => {'order_item_id': orderItemId, 'action': 'remove'};
}

/// The whole line is swapped for [quantity] of another variant the branch has.
final class AdjustReplace extends AdjustChange {
  const AdjustReplace(super.orderItemId, this.substitute, this.quantity);
  final Substitute substitute;
  final int quantity;

  @override
  JsonMap toJson() => {
    'order_item_id': orderItemId,
    'action': 'replace',
    'variant_id': substitute.variantId,
    'quantity': quantity,
  };
}

/// What a correction does to one line (`lines` of the answer), in the server's words: names, not ids.
@immutable
class AdjustLine {
  const AdjustLine({
    required this.orderItemId,
    required this.action,
    required this.name,
    required this.fromQuantity,
    required this.toQuantity,
    this.variantName,
    this.replacement,
  });

  factory AdjustLine.fromJson(JsonMap j) => AdjustLine(
    orderItemId: asString(j['order_item_id']),
    action: asString(j['action']),
    name: asString(j['name']),
    variantName: asStringOrNull(j['variant_name']),
    fromQuantity: asInt(j['from_quantity']),
    toQuantity: asInt(j['to_quantity']),
    replacement: j['replacement'] is Map ? AdjustReplacement.fromJson(asMap(j['replacement'])) : null,
  );

  final String orderItemId;

  /// `reduce`, `remove` or `replace`.
  final String action;
  final String name;
  final String? variantName;
  final int fromQuantity;
  final int toQuantity;
  final AdjustReplacement? replacement;
}

@immutable
class AdjustReplacement {
  const AdjustReplacement({required this.name, required this.quantity, required this.unitPrice, this.variantName});

  factory AdjustReplacement.fromJson(JsonMap j) => AdjustReplacement(
    name: asString(j['name']),
    variantName: asStringOrNull(j['variant_name']),
    quantity: asInt(j['quantity']),
    unitPrice: OpsMoney.from(j['unit_price']),
  );

  final String name;
  final String? variantName;
  final int quantity;
  final OpsMoney unitPrice;
}

/// The money of the order before or after a correction.
@immutable
class AdjustTotals {
  const AdjustTotals({required this.subtotal, required this.discount, required this.tax, required this.grandTotal});

  factory AdjustTotals.fromJson(Object? raw) {
    final j = raw is Map ? asMap(raw) : const <String, dynamic>{};
    return AdjustTotals(
      subtotal: OpsMoney.from(j['subtotal']),
      discount: OpsMoney.from(j['discount_total']),
      tax: OpsMoney.from(j['tax_total']),
      grandTotal: OpsMoney.from(j['grand_total']),
    );
  }

  final OpsMoney subtotal;
  final OpsMoney discount;
  final OpsMoney tax;
  final OpsMoney grandTotal;
}

/// What the server worked out (a preview, nothing written) or did (`POST …/adjustments`).
@immutable
class AdjustResult {
  const AdjustResult({
    required this.applied,
    required this.preview,
    required this.lines,
    required this.before,
    required this.after,
    required this.difference,
    required this.fulfillmentCancelled,
    required this.orderCancelled,
    required this.customerNotified,
    this.revisionNumber,
    this.refund,
    this.refundNeedsApproval = false,
  });

  factory AdjustResult.fromJson(JsonMap j) {
    final refund = j['refund'] is Map ? asMap(j['refund']) : null;
    return AdjustResult(
      applied: asBool(j['applied']),
      preview: asBool(j['preview']),
      revisionNumber: asIntOrNull(asMapOrNull(j['revision'])?['number']),
      lines: mapList(j['lines'], AdjustLine.fromJson),
      before: AdjustTotals.fromJson(j['before']),
      after: AdjustTotals.fromJson(j['after']),
      difference: OpsMoney.from(j['difference']),
      refund: refund == null ? null : OpsMoney.from(refund['amount']),
      refundNeedsApproval: refund != null && asBool(refund['needs_approval']),
      fulfillmentCancelled: asBool(j['fulfillment_cancelled']),
      orderCancelled: asBool(j['order_cancelled']),
      customerNotified: asBool(j['customer_notified']),
    );
  }

  final bool applied;
  final bool preview;
  final int? revisionNumber;
  final List<AdjustLine> lines;
  final AdjustTotals before;
  final AdjustTotals after;

  /// After minus before: zero or negative.
  final OpsMoney difference;

  /// The refund the correction creates (paid online orders only), and whether finance has to approve it first.
  final OpsMoney? refund;
  final bool refundNeedsApproval;
  final bool fulfillmentCancelled;
  final bool orderCancelled;
  final bool customerNotified;
}
