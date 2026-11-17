import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/deals/domain/deal_countdown.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:flutter/foundation.dart';

/// How a deal sets its price.
enum DealDiscountType {
  percent('percent'),
  amountOff('amount_off'),
  fixedPrice('fixed_price'),
  unknown('');

  const DealDiscountType(this.wire);
  final String wire;

  static DealDiscountType parse(Object? raw) {
    if (raw is! String) return unknown;
    final key = raw.trim().toLowerCase();
    return values.where((t) => t != unknown && t.wire == key).firstOrNull ?? unknown;
  }
}

/// What the Deals list and the deal page show about one deal (`Deal` of the contract).
@immutable
class Deal {
  const Deal({
    required this.id,
    required this.name,
    required this.phase,
    this.tagline,
    this.startsAt,
    this.endsAt,
    this.secondsLeft,
    this.discountType = DealDiscountType.unknown,
    this.discountValue,
    this.limitPerOrder,
    this.limitPerCustomer,
    this.productsTotal = 0,
    this.products = const [],
    this.design = DealDesign.fallback,
    this.showOnHome = false,
  });

  /// [fallbackPhase] is what a deal with a missing or unknown phase counts as: the list it came in says it (`live`, or
  /// `scheduled` for the upcoming list). [receivedAt] turns `seconds_left` into an end time when `ends_at` is missing.
  factory Deal.fromJson(JsonMap json, {DealPhase fallbackPhase = DealPhase.live, DateTime? receivedAt}) {
    final parsed = DealPhase.parse(json['phase']);
    final secondsLeft = json['seconds_left'] == null ? null : asInt(json['seconds_left']).clamp(0, 1 << 31);
    var endsAt = asDate(json['ends_at']);
    if (endsAt == null && secondsLeft != null && receivedAt != null) {
      endsAt = receivedAt.add(Duration(seconds: secondsLeft));
    }
    final discount = asMap(json['discount']);
    final limits = asMap(json['limits']);
    final products = _products(json['products']);
    return Deal(
      id: asString(json['id']).trim(),
      name: asString(json['name']),
      tagline: asStringOrNull(json['tagline'])?.trim(),
      phase: parsed == DealPhase.unknown ? fallbackPhase : parsed,
      startsAt: asDate(json['starts_at']),
      endsAt: endsAt,
      secondsLeft: secondsLeft,
      discountType: DealDiscountType.parse(discount['type']),
      discountValue: asStringOrNull(discount['value']),
      limitPerOrder: _limit(limits['per_order']),
      limitPerCustomer: _limit(limits['per_customer']),
      productsTotal: asInt(json['products_total'], products.items.length).clamp(0, 1 << 31),
      products: products.items,
      design: DealDesign.fromJson(json['design']),
      showOnHome: asBool(json['show_on_home']),
    );
  }

  static int? _limit(Object? v) {
    final n = asIntOrNull(v);
    return n == null || n <= 0 ? null : n;
  }

  /// `products` is a plain list in the Deals list and `{data, meta}` on the deal page.
  static ({List<ProductSummary> items, JsonMap meta}) _products(Object? raw) {
    if (raw is Map) {
      final m = asMap(raw);
      return (items: _cards(m['data']), meta: asMap(m['meta']));
    }
    return (items: _cards(raw), meta: const {});
  }

  static List<ProductSummary> _cards(Object? raw) {
    final seen = <String>{};
    return mapList(raw, ProductSummary.fromJson).where((p) => p.id.isNotEmpty && seen.add(p.id)).toList();
  }

  final String id;
  final String name;
  final String? tagline;
  final DealPhase phase;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final int? secondsLeft;
  final DealDiscountType discountType;

  /// The raw value of the rule, e.g. `"30"` for 30 percent.
  final String? discountValue;
  final int? limitPerOrder;
  final int? limitPerCustomer;
  final int productsTotal;
  final List<ProductSummary> products;
  final DealDesign design;
  final bool showOnHome;

  /// The percent off when the rule is a percentage; null for an amount off or a fixed price (the badge is then left out).
  int? get percent {
    if (discountType != DealDiscountType.percent) return null;
    final n = asDoubleOrNull(discountValue)?.round();
    return n == null || n <= 0 || n > 100 ? null : n;
  }

  /// The phase at [serverNow]: see [DealInfo.phaseAt].
  DealPhase phaseAt(DateTime serverNow) {
    if (phase == DealPhase.live || phase == DealPhase.soldOut) {
      if (endsAt != null && !serverNow.isBefore(endsAt!)) return DealPhase.ended;
      return phase;
    }
    if (phase == DealPhase.scheduled && startsAt != null && !serverNow.isBefore(startsAt!)) {
      if (endsAt != null && !serverNow.isBefore(endsAt!)) return DealPhase.ended;
      return DealPhase.live;
    }
    return phase;
  }

  /// The moment this deal changes state next: when it ends (live) or starts (scheduled). Null when it has no time.
  DateTime? get dueAt => switch (phase) {
    DealPhase.scheduled => startsAt ?? endsAt,
    _ => endsAt,
  };

  @override
  bool operator ==(Object other) =>
      other is Deal &&
      other.id == id &&
      other.name == name &&
      other.tagline == tagline &&
      other.phase == phase &&
      other.startsAt == startsAt &&
      other.endsAt == endsAt &&
      other.discountType == discountType &&
      other.discountValue == discountValue &&
      other.limitPerOrder == limitPerOrder &&
      other.limitPerCustomer == limitPerCustomer &&
      other.productsTotal == productsTotal &&
      listEquals(other.products, products) &&
      other.design == design &&
      other.showOnHome == showOnHome;

  @override
  int get hashCode => Object.hash(
    id,
    name,
    tagline,
    phase,
    startsAt,
    endsAt,
    discountType,
    discountValue,
    limitPerOrder,
    limitPerCustomer,
    productsTotal,
    Object.hashAll(products),
    design,
    showOnHome,
  );
}

/// `GET /deals`: the deals on sale now and the ones about to start.
@immutable
class DealsFeed {
  const DealsFeed({this.serverTime, this.skew = Duration.zero, this.live = const [], this.upcoming = const []});

  /// [deviceNow] is the phone's clock when the answer arrived: it turns `server_time` into the clock [skew].
  factory DealsFeed.fromJson(JsonMap json, {required DateTime deviceNow, Object? fallbackServerTime}) {
    final serverTime = asDate(json['server_time']) ?? asDate(fallbackServerTime);
    Deal? deal(JsonMap j, DealPhase fallback) {
      final d = Deal.fromJson(j, fallbackPhase: fallback, receivedAt: deviceNow.add(dealServerSkew(serverTime, deviceNow)));
      return d.id.isEmpty ? null : d;
    }

    final live = <Deal>[];
    for (final j in asList(json['live']).whereType<Map<dynamic, dynamic>>()) {
      final d = deal(asMap(j), DealPhase.live);
      // A deal in the live list that the server says is over or paused is not shown; sold out stays (it explains itself).
      if (d != null && d.phase != DealPhase.ended && d.phase != DealPhase.paused) live.add(d);
    }
    final upcoming = <Deal>[];
    for (final j in asList(json['upcoming']).whereType<Map<dynamic, dynamic>>()) {
      final d = deal(asMap(j), DealPhase.scheduled);
      if (d != null && d.phase != DealPhase.ended && d.phase != DealPhase.paused) upcoming.add(d);
    }
    return DealsFeed(
      serverTime: serverTime,
      skew: dealServerSkew(serverTime, deviceNow),
      live: List.unmodifiable(live),
      upcoming: List.unmodifiable(upcoming),
    );
  }

  static const empty = DealsFeed();

  final DateTime? serverTime;

  /// `server_time - device time` when the answer arrived.
  final Duration skew;
  final List<Deal> live;
  final List<Deal> upcoming;

  bool get isEmpty => live.isEmpty && upcoming.isEmpty;

  /// Any deal that is on sale now (not merely sold out).
  bool get hasLive => live.any((d) => d.phase == DealPhase.live);
}

/// One page of `GET /deals/{id}`: the deal and the products of this page.
@immutable
class DealPage {
  const DealPage({required this.deal, this.skew = Duration.zero, this.currentPage = 1, this.lastPage = 1, this.total});

  factory DealPage.fromJson(JsonMap json, {required DateTime deviceNow, Object? fallbackServerTime}) {
    final serverTime = asDate(json['server_time']) ?? asDate(fallbackServerTime);
    final skew = dealServerSkew(serverTime, deviceNow);
    final deal = Deal.fromJson(json, receivedAt: deviceNow.add(skew));
    final products = json['products'];
    final meta = products is Map ? asMap(asMap(products)['meta']) : const <String, dynamic>{};
    final current = asInt(meta['current_page'], 1).clamp(1, 1 << 31);
    return DealPage(
      deal: deal,
      skew: skew,
      currentPage: current,
      lastPage: asInt(meta['last_page'], current).clamp(current, 1 << 31),
      total: asIntOrNull(meta['total']),
    );
  }

  final Deal deal;
  final Duration skew;
  final int currentPage;
  final int lastPage;
  final int? total;

  bool get hasMore => currentPage < lastPage;
}
