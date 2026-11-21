import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// The delivery or pickup date promised before the customer pays (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). The server works
/// everything out (stock, opening hours, holidays, delivery-slot places, lead time) and writes the sentence in the customer's language; this only
/// reads it. A date that cannot be promised is [PromiseStatus.unconfirmed]: the customer still can buy.
enum PromiseStatus {
  scheduled,
  partial,
  unconfirmed,
  disabled;

  static PromiseStatus parse(Object? raw) => values.where((s) => s.name == raw).firstOrNull ?? unconfirmed;
}

@immutable
class PromiseParcel {
  const PromiseParcel({
    required this.branchId,
    required this.branchName,
    required this.type,
    required this.scheduled,
    required this.message,
    this.detail,
    this.date,
  });

  factory PromiseParcel.fromJson(JsonMap j) {
    final branch = asMap(j['branch']);
    return PromiseParcel(
      branchId: asString(branch['id']),
      branchName: asString(branch['name']),
      type: asString(j['type'], 'delivery'),
      scheduled: j['status'] == 'scheduled',
      message: asString(j['message']),
      detail: asStringOrNull(j['detail']),
      date: asStringOrNull(j['date']),
    );
  }

  final String branchId;
  final String branchName;

  /// `delivery` | `pickup`.
  final String type;

  /// False: no date can be promised for this part (not in stock, closed, nothing free).
  final bool scheduled;
  final String message;
  final String? detail;

  /// The promised day, `YYYY-MM-DD` (Qatar); null when not scheduled.
  final String? date;
}

@immutable
class BasketPromise {
  const BasketPromise({
    required this.enabled,
    required this.fulfillmentType,
    required this.status,
    required this.message,
    this.detail,
    this.allByLabel,
    this.isSplit = false,
    this.locationKnown = true,
    this.parcels = const [],
  });

  factory BasketPromise.fromJson(JsonMap j) => BasketPromise(
    enabled: asBool(j['enabled'], true),
    fulfillmentType: asString(j['fulfillment_type'], 'delivery'),
    status: PromiseStatus.parse(j['status']),
    message: asStringOrNull(j['message']),
    detail: asStringOrNull(j['detail']),
    allByLabel: asStringOrNull(asMapOrNull(j['all_by'])?['label']),
    isSplit: asBool(j['is_split']),
    locationKnown: asBool(j['location_known'], true),
    parcels: mapList(j['parcels'], PromiseParcel.fromJson),
  );

  static BasketPromise? maybe(Object? json) => json is Map ? BasketPromise.fromJson(asMap(json)) : null;

  final bool enabled;
  final String fulfillmentType;
  final PromiseStatus status;

  /// The sentence to show, in the customer's language ("Delivery today before 10 pm"). Null: nothing to say.
  final String? message;

  /// The small print ("if you order by 3 pm", or why no date can be given).
  final String? detail;

  /// "All delivered by ..." day label for a basket that splits (the sentence above already says it).
  final String? allByLabel;
  final bool isSplit;

  /// Delivery only: the address (zone) is known; until then the date is the best showroom's.
  final bool locationKnown;
  final List<PromiseParcel> parcels;

  /// Whether there is anything to draw: switched on and a sentence to show.
  bool get visible => enabled && status != PromiseStatus.disabled && message != null && message!.isNotEmpty;

  bool get scheduled => status == PromiseStatus.scheduled;

  /// One row per branch when the order splits (otherwise the headline says it all).
  bool get showsParcels => isSplit && parcels.length > 1;

  /// The delivery date depends on the address and none is known yet.
  bool get needsAddress => fulfillmentType == 'delivery' && !locationKnown && status != PromiseStatus.unconfirmed;
}
