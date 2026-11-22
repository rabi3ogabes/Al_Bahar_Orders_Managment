import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// One delivery time the customer can choose after a failed delivery.
@immutable
class OfferedTime {
  const OfferedTime({
    required this.slotId,
    required this.date,
    required this.startsAt,
    required this.endsAt,
    this.available = true,
  });

  factory OfferedTime.fromJson(JsonMap j) => OfferedTime(
    slotId: asString(j['slot_id']),
    date: asString(j['date']),
    startsAt: asString(j['starts_at']),
    endsAt: asString(j['ends_at']),
    available: asBool(j['available'], true),
  );

  static OfferedTime? maybe(Object? json) {
    final m = asMapOrNull(json);
    return m == null ? null : OfferedTime.fromJson(m);
  }

  final String slotId;

  /// The day, `YYYY-MM-DD`, in Qatar time.
  final String date;

  /// `HH:mm` in Qatar time.
  final String startsAt;
  final String endsAt;

  /// False once somebody else took the last place (the server asks again every time the order is read).
  final bool available;

  String get key => '$slotId|$date';
}

/// "We could not deliver. Choose a new time" (`fulfillments[].slot_offer`): `open` (the customer can pick), `expired` (the old "we will call you"
/// applies) or `chosen` (the new time is booked). Only the server decides which times are offered and whether one is still free.
@immutable
class SlotOfferInfo {
  const SlotOfferInfo({
    required this.id,
    required this.status,
    this.options = const [],
    this.expiresAt,
    this.canChoose = false,
    this.chosen,
  });

  factory SlotOfferInfo.fromJson(JsonMap j) => SlotOfferInfo(
    id: asString(j['id']),
    status: asString(j['status']),
    options: mapList(j['options'], OfferedTime.fromJson),
    expiresAt: asDate(j['expires_at']),
    canChoose: asBool(j['can_choose']),
    chosen: OfferedTime.maybe(j['chosen']),
  );

  static SlotOfferInfo? maybe(Object? json) {
    final m = asMapOrNull(json);
    return m == null ? null : SlotOfferInfo.fromJson(m);
  }

  final String id;
  final String status;
  final List<OfferedTime> options;
  final DateTime? expiresAt;
  final bool canChoose;
  final OfferedTime? chosen;

  bool get isOpen => status == 'open';
  bool get isExpired => status == 'expired';
  bool get isChosen => status == 'chosen';
}

/// "Delivered on (date, time), received by (name)" with the photo (`fulfillments[].proof`). The photo link is short-lived and private; the server
/// never sends the driver's position or phone. [photoUrl] is null when the shop hides the photo, or there is none.
@immutable
class DeliveryProofInfo {
  const DeliveryProofInfo({this.deliveredAt, this.receivedBy, this.photoUrl});

  static DeliveryProofInfo? maybe(Object? json) {
    final m = asMapOrNull(json);
    if (m == null) return null;
    final name = asString(m['received_by']).trim();
    return DeliveryProofInfo(
      deliveredAt: asDate(m['delivered_at']),
      receivedBy: name.isEmpty ? null : name,
      photoUrl: asStringOrNull(asMapOrNull(m['photo'])?['url']),
    );
  }

  final DateTime? deliveredAt;
  final String? receivedBy;
  final String? photoUrl;
}

/// What the driver answered for the paid assembly of a part (`fulfillments[].assembly`): `pending`, `done` or `not_done`.
@immutable
class AssemblyAnswer {
  const AssemblyAnswer({required this.status, this.doneAt, this.reasonLabel});

  static AssemblyAnswer? maybe(Object? json) {
    final m = asMapOrNull(json);
    if (m == null) return null;
    return AssemblyAnswer(
      status: asString(m['status'], 'pending'),
      doneAt: asDate(m['done_at']),
      reasonLabel: asStringOrNull(m['reason_label']),
    );
  }

  final String status;
  final DateTime? doneAt;
  final String? reasonLabel;

  bool get isDone => status == 'done';
  bool get isNotDone => status == 'not_done';
}
