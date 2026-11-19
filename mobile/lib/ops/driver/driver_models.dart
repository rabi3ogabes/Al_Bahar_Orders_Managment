import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// The person who receives the order at the door. It is not always the account holder (a gift, a wife, a colleague at a
/// workplace), and the two have different phone numbers: the driver chooses whom to call.
@immutable
class DriverRecipient {
  const DriverRecipient({this.name, this.phone, this.isAccountHolder = true});

  factory DriverRecipient.fromJson(Object? raw) {
    final j = asMap(raw);
    return DriverRecipient(
      name: asStringOrNull(j['name']),
      phone: asStringOrNull(j['phone']),
      isAccountHolder: asBool(j['is_account_holder'], true),
    );
  }

  final String? name;
  final String? phone;

  /// True when the order's recipient is the account holder himself (or the order says nothing else).
  final bool isAccountHolder;
}

/// What the catalogue knows about one line of the parcel, by the line's id: weight and size per unit, and the two flags
/// that matter at the door. Everything is optional: a line the catalogue knows nothing about shows nothing extra.
@immutable
class ItemFacts {
  const ItemFacts({this.weightKg, this.length, this.width, this.height, this.isOversized = false, this.requiresAssembly = false});

  factory ItemFacts.fromJson(JsonMap j) {
    final d = asMapOrNull(j['dimensions_cm']);
    return ItemFacts(
      weightKg: asDoubleOrNull(j['weight_kg']),
      length: asDoubleOrNull(d?['length']),
      width: asDoubleOrNull(d?['width']),
      height: asDoubleOrNull(d?['height']),
      isOversized: asBool(j['is_oversized']),
      requiresAssembly: asBool(j['requires_assembly']),
    );
  }

  final double? weightKg;
  final double? length;
  final double? width;
  final double? height;
  final bool isOversized;
  final bool requiresAssembly;

  bool get hasSize => length != null && width != null && height != null;

  /// Nothing to say about this line.
  bool get isEmpty => weightKg == null && !hasSize && !isOversized && !requiresAssembly;
}

/// Everything a driver needs to know about one OPEN job beyond the basics (see `DriverJobPresenter` on the server): the customer's notes
/// and the gate code, who receives it, the delivery window, how heavy it is, what is to be collected. A finished job or an older server
/// has none of it: every field is then empty and the screens show nothing for it.
@immutable
class DriverJobContext {
  const DriverJobContext({
    this.instructions,
    this.customerNotes,
    this.landmark,
    this.recipient,
    this.slotStart,
    this.slotEnd,
    this.totalWeightKg,
    this.needsTwoPeople = false,
    this.isOversized = false,
    this.requiresAssembly = false,
    this.paymentNote,
    this.arrivedAt,
    this.redacted = false,
    this.itemFacts = const {},
  });

  factory DriverJobContext.fromJson(JsonMap j) {
    final slot = asMapOrNull(j['slot']);
    final facts = <String, ItemFacts>{};
    for (final raw in asList(j['items'])) {
      final item = asMap(raw);
      final id = asString(item['id']);
      if (id.isEmpty) continue;
      final f = ItemFacts.fromJson(item);
      if (!f.isEmpty) facts[id] = f;
    }
    return DriverJobContext(
      instructions: asStringOrNull(j['instructions']),
      customerNotes: asStringOrNull(j['customer_notes']),
      landmark: asStringOrNull(j['landmark']),
      recipient: j['recipient'] is Map ? DriverRecipient.fromJson(j['recipient']) : null,
      slotStart: asDate(slot?['start']),
      slotEnd: asDate(slot?['end']),
      totalWeightKg: asDoubleOrNull(j['total_weight_kg']),
      needsTwoPeople: asBool(j['needs_two_people']),
      isOversized: asBool(j['is_oversized']),
      requiresAssembly: asBool(j['requires_assembly']),
      paymentNote: asStringOrNull(j['payment_note']),
      arrivedAt: asDate(j['arrived_at']),
      redacted: asBool(j['redacted']),
      itemFacts: facts,
    );
  }

  static const empty = DriverJobContext();

  final String? instructions;
  final String? customerNotes;
  final String? landmark;
  final DriverRecipient? recipient;

  /// The delivery slot the customer chose: from when to when (null for a job that was not booked into one).
  final DateTime? slotStart;
  final DateTime? slotEnd;
  final double? totalWeightKg;
  final bool needsTwoPeople;
  final bool isOversized;
  final bool requiresAssembly;

  /// The server's one-line payment note ("Collect QAR 303.00 in cash at the door."), in the app's language.
  final String? paymentNote;

  /// When the driver said "I have arrived".
  final DateTime? arrivedAt;

  /// A finished job older than a day: the numbers and the street are gone from it.
  final bool redacted;
  final Map<String, ItemFacts> itemFacts;

  bool get hasNotes => customerNotes != null || instructions != null;

  /// Some "Before you go" fact is true (cash on delivery is added by the screen, which knows the amount).
  bool get hasHeadsUp => needsTwoPeople || requiresAssembly || isOversized;

  /// The same facts after the driver said he arrived (shown at once, before the server's list is read again).
  DriverJobContext withArrived(DateTime at) => DriverJobContext(
    instructions: instructions,
    customerNotes: customerNotes,
    landmark: landmark,
    recipient: recipient,
    slotStart: slotStart,
    slotEnd: slotEnd,
    totalWeightKg: totalWeightKg,
    needsTwoPeople: needsTwoPeople,
    isOversized: isOversized,
    requiresAssembly: requiresAssembly,
    paymentNote: paymentNote,
    arrivedAt: arrivedAt ?? at,
    redacted: redacted,
    itemFacts: itemFacts,
  );
}

/// How late the cash a driver holds is, by the order clock's cash limits: the same three states as the dashboard.
enum CashState {
  ok,
  amber,
  red;

  static CashState fromCode(Object? code) => switch (code) {
    'amber' => CashState.amber,
    'red' => CashState.red,
    _ => CashState.ok,
  };
}

/// The cash side of the driver's day.
@immutable
class DriverCash {
  const DriverCash({
    this.collectedToday = 0,
    this.held = 0,
    this.heldCount = 0,
    this.oldestAt,
    this.state = CashState.ok,
  });

  factory DriverCash.fromJson(Object? raw) {
    final j = asMap(raw);
    return DriverCash(
      collectedToday: asDouble(j['collected_today']),
      held: asDouble(j['held']),
      heldCount: asInt(j['held_count']),
      oldestAt: asDate(j['oldest_at']),
      state: CashState.fromCode(j['state']),
    );
  }

  /// Everything he collected today, also what he has already handed in.
  final double collectedToday;

  /// What he still has in his pocket: collected, not yet handed in to a cashier.
  final double held;
  final int heldCount;

  /// When the oldest cash he still holds was collected.
  final DateTime? oldestAt;
  final CashState state;

  bool get holdsCash => held > 0.004;
}

/// One line of "My day": a job of today and where it stands (`declined` for a job he handed back).
@immutable
class DayJob {
  const DayJob({required this.id, required this.orderNumber, required this.status, this.customerName = '', this.area, this.zone, this.codAmount = 0, this.at});

  factory DayJob.fromJson(JsonMap j) {
    final a = asMap(j['address']);
    return DayJob(
      id: asString(j['id']),
      orderNumber: asString(j['order_number']),
      status: asString(j['status']),
      customerName: asString(j['customer_name']),
      area: asStringOrNull(a['area']),
      zone: asStringOrNull(a['zone_number']),
      codAmount: asDouble(j['cod_amount']),
      at: asDate(j['at']),
    );
  }

  final String id;
  final String orderNumber;
  final String status;
  final String customerName;
  final String? area;
  final String? zone;
  final double codAmount;
  final DateTime? at;
}

/// `GET /admin/dispatch/driver/day`: what the driver did today, the cash he holds and the day's jobs.
@immutable
class DriverDay {
  const DriverDay({
    this.date = '',
    this.onDuty = false,
    this.onDutySince,
    this.done = 0,
    this.failed = 0,
    this.declined = 0,
    this.active = 0,
    this.cash = const DriverCash(),
    this.jobs = const [],
  });

  factory DriverDay.fromJson(JsonMap j) {
    final counts = asMap(j['jobs']);
    return DriverDay(
      date: asString(j['date']),
      onDuty: asBool(j['on_duty']),
      onDutySince: asDate(j['on_duty_since']),
      done: asInt(counts['done']),
      failed: asInt(counts['failed']),
      declined: asInt(counts['declined']),
      active: asInt(counts['active']),
      cash: DriverCash.fromJson(j['cash']),
      jobs: mapList(j['list'], DayJob.fromJson),
    );
  }

  final String date;
  final bool onDuty;
  final DateTime? onDutySince;
  final int done;
  final int failed;
  final int declined;
  final int active;
  final DriverCash cash;
  final List<DayJob> jobs;

  /// Nothing happened today and nothing is waiting.
  bool get isQuiet => done + failed + declined + active == 0 && jobs.isEmpty;
}
