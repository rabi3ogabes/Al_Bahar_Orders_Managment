import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// Why fewer units than were asked for get the deal price (`note` of a cart line or a quote line).
enum DealNote {
  limitPerOrder('limit_per_order'),
  limitPerCustomer('limit_per_customer'),
  soldOut('sold_out'),
  ended('ended');

  const DealNote(this.wire);
  final String wire;

  /// Null for no note, and for a reason this app does not know (the wording then says nothing about why).
  static DealNote? parse(Object? raw) {
    if (raw is! String) return null;
    final key = raw.trim().toLowerCase();
    return values.where((n) => n.wire == key).firstOrNull;
  }
}

/// How many units of a line get the deal price: the words "Deal price on 2 of 3 · limit 2 per order".
///
/// The server works it out (the cart says it, the quote is the final word); the app only words it.
@immutable
class DealLineUnits {
  const DealLineUnits({required this.unitsAtDealPrice, required this.unitsAtRegularPrice, this.note});

  /// Reads `units_at_deal_price`, `units_at_regular_price` and `note` of a line's `deal`. Null when the line carries no unit
  /// counts (an older server, or a deal block on a product page).
  static DealLineUnits? maybe(Object? raw) {
    if (raw is! Map) return null;
    final j = asMap(raw);
    final deal = asIntOrNull(j['units_at_deal_price']);
    final regular = asIntOrNull(j['units_at_regular_price']);
    if (deal == null || regular == null || deal < 0 || regular < 0) return null;
    return DealLineUnits(unitsAtDealPrice: deal, unitsAtRegularPrice: regular, note: DealNote.parse(j['note']));
  }

  final int unitsAtDealPrice;
  final int unitsAtRegularPrice;
  final DealNote? note;

  int get total => unitsAtDealPrice + unitsAtRegularPrice;

  @override
  bool operator ==(Object other) =>
      other is DealLineUnits &&
      other.unitsAtDealPrice == unitsAtDealPrice &&
      other.unitsAtRegularPrice == unitsAtRegularPrice &&
      other.note == note;

  @override
  int get hashCode => Object.hash(unitsAtDealPrice, unitsAtRegularPrice, note);
}

/// The `deal` of a quote line: `{deal_id, name, units_at_deal_price, units_at_regular_price, savings, ends_at, note}`.
@immutable
class QuoteLineDeal {
  const QuoteLineDeal({required this.dealId, required this.name, required this.units, this.savings, this.endsAt});

  /// Null when the line has no deal (or the payload is no deal).
  static QuoteLineDeal? maybe(Object? raw) {
    if (raw is! Map) return null;
    final j = asMap(raw);
    final id = asString(j['deal_id']).trim();
    final units = DealLineUnits.maybe(j);
    if (id.isEmpty || units == null) return null;
    return QuoteLineDeal(
      dealId: id,
      name: asString(j['name']),
      units: units,
      savings: Money.lenient(j['savings']),
      endsAt: asDate(j['ends_at']),
    );
  }

  final String dealId;
  final String name;
  final DealLineUnits units;

  /// What the deal saves on this line (server money, never worked out here).
  final Money? savings;
  final DateTime? endsAt;
}

/// The `deal` of a placed order line: `{deal_id, name, units, unit_savings}`, a snapshot of what the line got.
@immutable
class OrderLineDeal {
  const OrderLineDeal({required this.dealId, required this.name, required this.units, this.unitSavings});

  /// Null when the line got no deal price (no deal, or no unit at the deal price).
  static OrderLineDeal? maybe(Object? raw) {
    if (raw is! Map) return null;
    final j = asMap(raw);
    final units = asIntOrNull(j['units']);
    if (units == null || units <= 0) return null;
    return OrderLineDeal(
      dealId: asString(j['deal_id']),
      name: asString(j['name']),
      units: units,
      unitSavings: Money.lenient(j['unit_savings']),
    );
  }

  final String dealId;
  final String name;

  /// How many units of the line were sold at the deal price.
  final int units;

  /// What the deal saved on each of them.
  final Money? unitSavings;
}
