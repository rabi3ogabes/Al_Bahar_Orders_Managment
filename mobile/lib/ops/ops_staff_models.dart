import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/*
 * What the branch staff API says besides the plain order rows (docs/api/STAFF_QUEUE.md): the order clock, the customer's request to cancel,
 * the pickup pass, the branches of the person, the needs-attention list and the answer to a scanned pickup pass. Plain values, read leniently:
 * a server that does not send a field (an older one) gives its calm default, never an error.
 */

/// How worried an order's wait should make the person: fine, getting late, late. Never shown as colour alone (each has its own icon and words).
enum OrderClockState {
  ok,
  amber,
  red;

  static OrderClockState parse(Object? code) => switch (code) {
    'red' => red,
    'amber' => amber,
    _ => ok,
  };

  bool get late => this != ok;

  /// The worse of two states.
  OrderClockState worst(OrderClockState other) => index >= other.index ? this : other;
}

/// How long an order has waited for the branch, as the server's order clock says (business minutes: a closed shop does not count).
@immutable
class OrderClock {
  const OrderClock({
    required this.state,
    required this.ageMinutes,
    this.label = '',
    this.kind = 'none',
    this.paused = false,
    this.amberAt,
    this.redAt,
    this.readAt,
  });

  static OrderClock? maybeFrom(Object? raw, {DateTime? now}) {
    final j = asMapOrNull(raw);
    if (j == null) return null;
    return OrderClock(
      state: OrderClockState.parse(j['state']),
      ageMinutes: asInt(j['age_minutes']),
      label: asString(j['label']),
      kind: asString(j['kind'], 'none'),
      paused: asBool(j['paused']),
      amberAt: asIntOrNull(j['amber_at']),
      redAt: asIntOrNull(j['red_at']),
      readAt: now ?? DateTime.now(),
    );
  }

  final OrderClockState state;

  /// The minutes that count, as of [readAt].
  final int ageMinutes;

  /// The server's own words for the age ("12 min"), in the language of the request. The screens write their own from [ageMinutes].
  final String label;

  /// What the part waits for: pending, preparing, ready, driver, pickup, cancellation; `none` when nobody has to act (a driver has it);
  /// `placed` for a row of an older server that has no clock (the time since it was placed).
  final String kind;

  /// True while the branch is closed: the clock stands still.
  final bool paused;

  /// The limits of this wait in the same minutes (null when nobody has to act): between two reads the age moves on and the state follows them.
  final int? amberAt;
  final int? redAt;
  final DateTime? readAt;

  /// The same clock at [now]: the minutes since it was read are added (not while the branch is closed) and the state follows the limits, so the
  /// age on a card never stands still between two reads of the server. The next read replaces it.
  OrderClock at(DateTime now) {
    final since = readAt;
    if (paused || since == null) return this;
    final extra = now.difference(since).inMinutes;
    if (extra <= 0) return this;
    final age = ageMinutes + extra;
    final byLimit = switch ((redAt, amberAt)) {
      (final r?, _) when age >= r => OrderClockState.red,
      (_, final a?) when age >= a => OrderClockState.amber,
      _ => OrderClockState.ok,
    };
    return OrderClock(
      state: state.worst(byLimit),
      ageMinutes: age,
      label: label,
      kind: kind,
      paused: paused,
      amberAt: amberAt,
      redAt: redAt,
      readAt: since,
    );
  }

  /// A clock for a row an older server sent without one: how long since [placedAt], never amber or red.
  static OrderClock? since(DateTime? placedAt, DateTime now) {
    if (placedAt == null) return null;
    final minutes = now.difference(placedAt).inMinutes;
    return OrderClock(state: OrderClockState.ok, ageMinutes: minutes < 0 ? 0 : minutes, kind: 'placed', readAt: now);
  }
}

/// The customer's request to cancel an order, waiting for a manager. While it is open nothing may leave the branch.
@immutable
class CancellationRequest {
  const CancellationRequest({required this.id, required this.status, this.reason, this.requestedAt});

  static CancellationRequest? maybeFrom(Object? raw) {
    final j = asMapOrNull(raw);
    if (j == null || asString(j['id']).isEmpty) return null;
    return CancellationRequest(
      id: asString(j['id']),
      status: asString(j['status'], 'requested'),
      reason: asStringOrNull(j['reason']),
      requestedAt: asDate(j['requested_at']),
    );
  }

  final String id;
  final String status;
  final String? reason;
  final DateTime? requestedAt;

  /// Only an undecided request holds the order back.
  bool get open => status == 'requested';
}

/// The pickup pass of a pickup order: its state and whether it ran out.
@immutable
class PickupPass {
  const PickupPass({this.status, this.expiresAt, this.expired = false});

  static PickupPass? maybeFrom(Object? raw) {
    final j = asMapOrNull(raw);
    if (j == null) return null;
    return PickupPass(
      status: asStringOrNull(j['status']),
      expiresAt: asDate(j['token_expires_at']),
      expired: asBool(j['expired']),
    );
  }

  final String? status;
  final DateTime? expiresAt;
  final bool expired;

  /// Expired as the server saw it, or by the time on this phone.
  bool isExpired(DateTime now) => expired || (expiresAt != null && expiresAt!.isBefore(now));
}

/// A branch the person works in (the branch picker of a manager of several).
@immutable
class StaffBranchRef {
  const StaffBranchRef({required this.id, required this.name});

  factory StaffBranchRef.fromJson(JsonMap j) => StaffBranchRef(id: asString(j['id']), name: asString(j['name']));

  final String id;
  final String name;
}

/// Where a line of the needs-attention list takes the person.
enum AttentionTarget {
  openOrder,
  assignDriver,
  reissuePass,

  /// Handled on the dashboard: the app only tells the person.
  dashboard;

  static AttentionTarget parse(Object? code) => switch (code) {
    'open_order' => openOrder,
    'assign_driver' => assignDriver,
    'reissue_pass' => reissuePass,
    _ => dashboard,
  };
}

/// One line of the needs-attention list (`GET dispatch/attention`).
@immutable
class AttentionItem {
  const AttentionItem({
    required this.id,
    required this.kind,
    required this.target,
    required this.subject,
    required this.label,
    required this.detail,
    required this.state,
    required this.ageMinutes,
    this.subjectIsNumber = true,
    this.note,
    this.ageLabel,
    this.branchId,
    this.branchCode,
    this.orderId,
    this.fulfillmentId,
    this.hint,
    this.callPhone,
  });

  factory AttentionItem.fromJson(JsonMap j) => AttentionItem(
    id: asString(j['id']),
    kind: asString(j['kind']),
    target: AttentionTarget.parse(j['target']),
    subject: asString(j['subject']),
    subjectIsNumber: asBool(j['subject_is_number'], true),
    label: asString(j['label']),
    detail: asString(j['detail']),
    note: asStringOrNull(j['note']),
    state: OrderClockState.parse(j['state']),
    ageMinutes: asInt(j['age_minutes']),
    ageLabel: asStringOrNull(j['age_label']),
    branchId: asStringOrNull(j['branch_id']),
    branchCode: asStringOrNull(j['branch_code']),
    orderId: asStringOrNull(j['order_id']),
    fulfillmentId: asStringOrNull(j['fulfillment_id']),
    hint: asStringOrNull(j['hint']),
    callPhone: asStringOrNull(asMapOrNull(j['call'])?['phone']),
  );

  final String id;

  /// The dashboard's category: unaccepted, stuck, undispatched, driver_silent, delivery_failed, pickup_waiting, cancellation, refund, cash, queue, campaign.
  final String kind;
  final AttentionTarget target;

  /// The order number (or, for cash and the system, a name: [subjectIsNumber] is false).
  final String subject;
  final bool subjectIsNumber;

  /// What it is, in the language of the request ("Waiting to be accepted").
  final String label;

  /// Who and what ("Noor · 3 items").
  final String detail;
  final String? note;
  final OrderClockState state;
  final int ageMinutes;
  final String? ageLabel;
  final String? branchId;
  final String? branchCode;
  final String? orderId;
  final String? fulfillmentId;

  /// Why the app cannot do it ("This is handled on the dashboard.").
  final String? hint;
  final String? callPhone;

  bool get inApp => target != AttentionTarget.dashboard;
}

/// The needs-attention list for the signed-in person: its top lines and the counts of the whole.
@immutable
class AttentionSummary {
  const AttentionSummary({
    this.items = const [],
    this.total = 0,
    this.red = 0,
    this.amber = 0,
    this.ok = 0,
    this.byKind = const {},
    this.truncated = false,
  });

  factory AttentionSummary.fromJson(JsonMap j) {
    final counts = asMap(j['counts']);
    return AttentionSummary(
      items: mapList(j['items'], AttentionItem.fromJson),
      total: asInt(counts['total']),
      red: asInt(counts['red']),
      amber: asInt(counts['amber']),
      ok: asInt(counts['ok']),
      byKind: {for (final e in asMap(counts['by_kind']).entries) e.key: asInt(e.value)},
      truncated: asBool(j['truncated']),
    );
  }

  final List<AttentionItem> items;
  final int total;
  final int red;
  final int amber;
  final int ok;
  final Map<String, int> byKind;
  final bool truncated;

  /// The strip's tone: red when anything is late, amber when anything is getting late, calm otherwise.
  OrderClockState get worst => red > 0 ? OrderClockState.red : (amber > 0 ? OrderClockState.amber : OrderClockState.ok);
}

/// One line of the parcel a scanned pickup pass is for.
@immutable
class PickupItem {
  const PickupItem({
    required this.id,
    required this.name,
    required this.quantity,
    required this.handedOver,
    this.variant,
    this.sku,
  });

  factory PickupItem.fromJson(JsonMap j) => PickupItem(
    id: asString(j['id']),
    name: asString(j['name']),
    variant: asStringOrNull(j['variant']),
    sku: asStringOrNull(j['sku']),
    quantity: asInt(j['quantity']),
    handedOver: asInt(j['handed_over']),
  );

  final String id;
  final String name;
  final String? variant;
  final String? sku;

  /// What the order holds of this line, and how much of it was already handed over.
  final int quantity;
  final int handedOver;

  int get left => quantity - handedOver < 0 ? 0 : quantity - handedOver;
}

/// What `POST pickup/verify` answered for a scanned or typed pass.
@immutable
class PickupCheck {
  const PickupCheck({
    required this.result,
    this.reservationId,
    this.orderNumber = '',
    this.customerName = '',
    this.paymentMethod = '',
    this.paymentStatus = '',
    this.amountDue = '0.00',
    this.currency = 'QAR',
    this.items = const [],
  });

  factory PickupCheck.fromJson(JsonMap j) {
    final order = asMap(j['order']);
    final due = asMap(order['amount_due']);
    return PickupCheck(
      result: asString(j['result'], 'invalid_token'),
      reservationId: asStringOrNull(j['reservation_id']),
      orderNumber: asString(order['number']),
      customerName: asString(order['customer_name']),
      paymentMethod: asString(order['payment_method']),
      paymentStatus: asString(order['payment_status']),
      amountDue: asString(due['amount'], '0.00'),
      currency: asString(due['currency'], 'QAR'),
      items: mapList(j['items'], PickupItem.fromJson),
    );
  }

  /// success | invalid_token | expired | already_used | wrong_branch | not_ready
  final String result;
  final String? reservationId;
  final String orderNumber;
  final String customerName;
  final String paymentMethod;
  final String paymentStatus;
  final String amountDue;
  final String currency;
  final List<PickupItem> items;

  bool get ok => result == 'success' && reservationId != null;

  /// Money to take at the counter before the goods go: only a pay-at-pickup order that is not settled yet.
  bool get collects => paymentMethod == 'pay_at_pickup' && (double.tryParse(amountDue) ?? 0) > 0;
}

/// The money taken at the counter for a pay-at-pickup order, as the hand-over sends it (`collection`).
@immutable
class PickupCollection {
  const PickupCollection({required this.tender, required this.amount, this.receiptReference});

  /// `cash` or `card_terminal`.
  final String tender;
  final String amount;
  final String? receiptReference;

  Map<String, Object> toJson() => {
    'tender': tender,
    'amount': amount,
    if (receiptReference != null && receiptReference!.trim().isNotEmpty) 'receipt_reference': receiptReference!.trim(),
  };
}
