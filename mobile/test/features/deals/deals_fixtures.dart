import '../../helpers/fakes.dart';

/// The instant every deals test treats as "now" (the server's clock and, unless a test skews it, the phone's).
final dealsNow = DateTime.utc(2026, 11, 17, 6, 12);

/// The moment the default deal times are built around. Tests that run on the real clock (a product card, the product page and the
/// cart have no fake clock) point it at `DateTime.now`.
DateTime Function() dealsBase = () => dealsNow;

String iso(DateTime t) => t.toUtc().toIso8601String();

Map<String, dynamic> designJson({
  String style = 'classic',
  String shape = 'rounded',
  String look = 'boxes',
  List<String> units = const ['d', 'h', 'm', 's'],
  int urgency = 60,
  bool stockBar = true,
  bool percentBadge = true,
  String icon = 'flame',
  Object? colors = const {'background': '#1B2A41', 'text': '#FFFFFF', 'accent': '#F2B84B'},
  String? headlineEn = 'Ends in',
  String? headlineAr = 'ينتهي خلال',
  String? ctaEn = 'Shop the deal',
  String? ctaAr = 'تسوق العرض',
  String? imageUrl,
}) => {
  'style': style,
  'palette': 'custom',
  'colors': colors,
  'shape': shape,
  'timer': {'units': units, 'look': look, 'urgency_minutes': urgency},
  'stock_bar': stockBar,
  'percent_badge': percentBadge,
  'icon': icon,
  'headline_en': headlineEn,
  'headline_ar': headlineAr,
  'cta_en': ctaEn,
  'cta_ar': ctaAr,
  'image_url': imageUrl,
};

/// The `deal` block of a product (docs/api/DEALS.md section 5).
Map<String, dynamic> dealBlockJson({
  String id = 'd1',
  String name = 'Weekend Furniture Drop',
  String phase = 'live',
  DateTime? endsAt,
  DateTime? startsAt,
  String dealPrice = '70.00',
  String regular = '100.00',
  int? percent = 30,
  int? remaining = 12,
  int? remainingPercent = 24,
  int? limitPerOrder = 2,
  int? limitPerCustomer = 3,
  int? customerRemaining,
  bool soldOut = false,
  Map<String, dynamic>? design,
}) => {
  'deal_id': id,
  'name': name,
  'phase': phase,
  'starts_at': iso(startsAt ?? dealsBase().subtract(const Duration(hours: 1))),
  'ends_at': iso(endsAt ?? dealsBase().add(const Duration(hours: 5))),
  'deal_price': money(dealPrice),
  'regular_price': money(regular),
  'percent': percent,
  'remaining': remaining,
  'remaining_percent': remainingPercent,
  'limit_per_order': limitPerOrder,
  'limit_per_customer': limitPerCustomer,
  'customer_remaining': customerRemaining,
  'sold_out': soldOut,
  'design': design ?? designJson(),
};

/// A product card that is in a deal.
Map<String, dynamic> dealProductJson(String id, {Map<String, dynamic>? deal, String amount = '100.00'}) => {
  ...productCard(id, amount: amount),
  'deal': deal ?? dealBlockJson(),
};

/// One `Deal` of `GET /deals`.
Map<String, dynamic> dealJson({
  String id = 'd1',
  String name = 'Weekend Furniture Drop',
  String? tagline = 'Big pieces, small prices',
  String phase = 'live',
  DateTime? startsAt,
  DateTime? endsAt,
  int? secondsLeft,
  Map<String, dynamic>? design,
  List<Object?>? products,
  int? productsTotal,
  String discountType = 'percent',
  String discountValue = '30',
  int? perOrder = 2,
  int? perCustomer = 3,
}) {
  final end = endsAt ?? dealsBase().add(const Duration(days: 1, hours: 2, minutes: 3, seconds: 4));
  return {
    'id': id,
    'name': name,
    'tagline': tagline,
    'phase': phase,
    'starts_at': iso(startsAt ?? dealsBase().subtract(const Duration(hours: 2))),
    'ends_at': iso(end),
    'seconds_left': secondsLeft ?? end.difference(dealsBase()).inSeconds,
    'discount': {'type': discountType, 'value': discountValue},
    'limits': {'per_order': perOrder, 'per_customer': perCustomer},
    'products_total': productsTotal ?? (products ?? [1, 2]).length,
    'products': products ?? [dealProductJson('p1'), dealProductJson('p2')],
    'design': design ?? designJson(),
    'show_on_home': true,
  };
}

/// The body of `GET /deals` (inside `data`).
Map<String, dynamic> feedJson({
  List<Object?> live = const [],
  List<Object?> upcoming = const [],
  DateTime? serverTime,
}) => {'server_time': iso(serverTime ?? dealsNow), 'live': live, 'upcoming': upcoming};
