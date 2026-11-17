import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/deals/domain/deal_countdown.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/painting.dart';

// The deal pieces that products carry (`deal` block) and that the Deals screens share: the design of the widget, the
// phases and the product-level block. Everything is parsed defensively: an old server sends none of it, a newer one may
// send values this app does not know, and neither may ever crash the app (see docs/api/DEALS.md).

/// Where a deal is in its life. Computed by the server, never stored.
enum DealPhase {
  scheduled('scheduled'),
  live('live'),
  soldOut('sold_out'),
  ended('ended'),
  paused('paused'),
  unknown('');

  const DealPhase(this.wire);
  final String wire;

  /// A value this app does not know reads as [unknown] (never as live).
  static DealPhase parse(Object? raw) {
    if (raw is! String) return unknown;
    final key = raw.trim().toLowerCase();
    return values.where((p) => p != unknown && p.wire == key).firstOrNull ?? unknown;
  }
}

/// The five looks of the widget.
enum DealStyle {
  classic,
  bold,
  minimal,
  luxe,
  ribbon;

  static DealStyle parse(Object? raw) => _pick(values, raw, classic);
}

/// The corners of the widget, its boxes and its buttons.
enum DealShape {
  rounded,
  sharp,
  pill;

  static DealShape parse(Object? raw) => _pick(values, raw, rounded);
}

/// How the countdown is drawn.
enum DealTimerLook {
  boxes,
  digits,
  text;

  static DealTimerLook parse(Object? raw) => _pick(values, raw, boxes);
}

/// The icons an admin can pick for a deal.
enum DealIconKey {
  flame,
  bolt,
  timer,
  tag,
  gift,
  star,
  crown;

  static DealIconKey parse(Object? raw) => _pick(values, raw, flame);
}

T _pick<T extends Enum>(List<T> values, Object? raw, T fallback) {
  if (raw is! String) return fallback;
  final key = raw.trim().toLowerCase();
  return values.where((v) => v.name == key).firstOrNull ?? fallback;
}

/// The phase of a deal at [serverNow], from the phase the server reported and the deal's times: a deal that was live ends
/// when its time is over, and a scheduled one goes live when its start comes. (The server stays the authority: the next
/// fetch confirms.)
DealPhase dealPhaseAt(DealPhase phase, DateTime? startsAt, DateTime? endsAt, DateTime serverNow) {
  if (phase == DealPhase.live || phase == DealPhase.soldOut) {
    if (endsAt != null && !serverNow.isBefore(endsAt)) return DealPhase.ended;
    return phase;
  }
  if (phase == DealPhase.scheduled && startsAt != null && !serverNow.isBefore(startsAt)) {
    if (endsAt != null && !serverNow.isBefore(endsAt)) return DealPhase.ended;
    return DealPhase.live;
  }
  return phase;
}

/// The three colours of a deal, resolved by the server (the app never invents colours). Text on background is readable.
@immutable
class DealColors {
  const DealColors({required this.background, required this.text, required this.accent});

  static final _hex = RegExp(r'^#[0-9a-fA-F]{6}$');

  static Color? _color(Object? v) {
    final s = v is String ? v.trim() : '';
    if (!_hex.hasMatch(s)) return null;
    return Color(int.parse('FF${s.substring(1)}', radix: 16));
  }

  /// Null unless all three colours are valid `#RRGGBB`: a half-filled set is no set.
  static DealColors? maybe(Object? raw) {
    if (raw is! Map) return null;
    final m = asMap(raw);
    final bg = _color(m['background']);
    final text = _color(m['text']);
    final accent = _color(m['accent']);
    if (bg == null || text == null || accent == null) return null;
    return DealColors(background: bg, text: text, accent: accent);
  }

  final Color background;
  final Color text;
  final Color accent;

  @override
  bool operator ==(Object other) =>
      other is DealColors && other.background == background && other.text == text && other.accent == accent;

  @override
  int get hashCode => Object.hash(background, text, accent);
}

/// The widget design of a deal (`design` of the contract, section 4).
@immutable
class DealDesign {
  const DealDesign({
    this.style = DealStyle.classic,
    this.colors,
    this.shape = DealShape.rounded,
    this.units = defaultUnits,
    this.look = DealTimerLook.boxes,
    this.urgencyMinutes = 60,
    this.stockBar = true,
    this.percentBadge = true,
    this.icon = DealIconKey.flame,
    this.headlineEn,
    this.headlineAr,
    this.ctaEn,
    this.ctaAr,
    this.imageUrl,
  });

  static const defaultUnits = [DealUnit.days, DealUnit.hours, DealUnit.minutes, DealUnit.seconds];

  /// What a deal looks like when the server sends no design.
  static const fallback = DealDesign();

  /// Parses a `design` object. Anything missing or unknown falls back to the contract's default for that field.
  static DealDesign fromJson(Object? raw) {
    if (raw is! Map) return fallback;
    final j = asMap(raw);
    final timer = asMap(j['timer']);
    final units = <DealUnit>{for (final u in asList(timer['units'])) ?DealUnit.parse(u)}.toList()
      ..sort((a, b) => b.inSeconds.compareTo(a.inSeconds));
    final urgency = timer.containsKey('urgency_minutes') ? asInt(timer['urgency_minutes'], 60) : 60;
    final image = asStringOrNull(j['image_url']);
    return DealDesign(
      style: DealStyle.parse(j['style']),
      colors: DealColors.maybe(j['colors']),
      shape: DealShape.parse(j['shape']),
      // Fewer than two valid units is not a countdown the contract allows: use all four.
      units: units.length >= 2 ? units : defaultUnits,
      look: DealTimerLook.parse(timer['look']),
      urgencyMinutes: urgency.clamp(0, 10080),
      stockBar: asBool(j['stock_bar'], true),
      percentBadge: asBool(j['percent_badge'], true),
      icon: DealIconKey.parse(j['icon']),
      headlineEn: asStringOrNull(j['headline_en'])?.trim(),
      headlineAr: asStringOrNull(j['headline_ar'])?.trim(),
      ctaEn: asStringOrNull(j['cta_en'])?.trim(),
      ctaAr: asStringOrNull(j['cta_ar'])?.trim(),
      // Only a real https picture can be drawn behind the text.
      imageUrl: image != null && image.startsWith('https://') ? image : null,
    );
  }

  final DealStyle style;

  /// The server's resolved colours; null when it sent none (the widget then borrows the app's brand colours).
  final DealColors? colors;
  final DealShape shape;
  final List<DealUnit> units;
  final DealTimerLook look;

  /// Below this many minutes the digits turn to the accent colour and pulse (0 = never).
  final int urgencyMinutes;
  final bool stockBar;
  final bool percentBadge;
  final DealIconKey icon;
  final String? headlineEn;
  final String? headlineAr;
  final String? ctaEn;
  final String? ctaAr;
  final String? imageUrl;

  /// The admin's headline for this language, or null (the app's own words are used).
  String? headlineFor(String languageCode) => _word(languageCode == 'ar' ? headlineAr : headlineEn, languageCode);

  /// The admin's button words for this language, or null.
  String? ctaFor(String languageCode) => _word(languageCode == 'ar' ? ctaAr : ctaEn, languageCode);

  // Only English and Arabic have words from the dashboard; any other language uses the app's own.
  static String? _word(String? value, String languageCode) {
    if (languageCode != 'ar' && languageCode != 'en') return null;
    return value == null || value.isEmpty ? null : value;
  }

  @override
  bool operator ==(Object other) =>
      other is DealDesign &&
      other.style == style &&
      other.colors == colors &&
      other.shape == shape &&
      listEquals(other.units, units) &&
      other.look == look &&
      other.urgencyMinutes == urgencyMinutes &&
      other.stockBar == stockBar &&
      other.percentBadge == percentBadge &&
      other.icon == icon &&
      other.headlineEn == headlineEn &&
      other.headlineAr == headlineAr &&
      other.ctaEn == ctaEn &&
      other.ctaAr == ctaAr &&
      other.imageUrl == imageUrl;

  @override
  int get hashCode => Object.hash(
    style,
    colors,
    shape,
    Object.hashAll(units),
    look,
    urgencyMinutes,
    stockBar,
    percentBadge,
    icon,
    headlineEn,
    headlineAr,
    ctaEn,
    ctaAr,
    imageUrl,
  );
}

/// The `deal` block on a product card, a product page, a variant and a cart line.
@immutable
class DealInfo {
  const DealInfo({
    required this.dealId,
    required this.name,
    required this.phase,
    this.startsAt,
    this.endsAt,
    this.dealPrice,
    this.regularPrice,
    this.percent,
    this.remaining,
    this.remainingPercent,
    this.limitPerOrder,
    this.limitPerCustomer,
    this.customerRemaining,
    this.soldOut = false,
    this.design = DealDesign.fallback,
  });

  /// Null when [raw] is no deal block (no map, or no deal id): the product then simply has no deal.
  static DealInfo? maybe(Object? raw) {
    if (raw is! Map) return null;
    final j = asMap(raw);
    final id = asString(j['deal_id']).trim();
    if (id.isEmpty) return null;
    return DealInfo(
      dealId: id,
      name: asString(j['name']),
      phase: DealPhase.parse(j['phase']),
      startsAt: asDate(j['starts_at']),
      endsAt: asDate(j['ends_at']),
      dealPrice: Money.lenient(j['deal_price']),
      regularPrice: Money.lenient(j['regular_price']),
      percent: _percent(j['percent']),
      remaining: _count(j['remaining']),
      remainingPercent: _count(j['remaining_percent'])?.clamp(0, 100),
      limitPerOrder: _limit(j['limit_per_order']),
      limitPerCustomer: _limit(j['limit_per_customer']),
      customerRemaining: _count(j['customer_remaining']),
      soldOut: asBool(j['sold_out']),
      design: DealDesign.fromJson(j['design']),
    );
  }

  static int? _percent(Object? v) {
    final n = asDoubleOrNull(v)?.round();
    return n == null || n <= 0 || n > 100 ? null : n;
  }

  /// A count that can be zero but never negative; null when missing or not a number.
  static int? _count(Object? v) {
    if (v == null) return null;
    final n = asDoubleOrNull(v)?.floor();
    return n == null || n < 0 ? null : n;
  }

  /// A limit of units: positive, or null for "no limit".
  static int? _limit(Object? v) {
    final n = _count(v);
    return n == null || n == 0 ? null : n;
  }

  final String dealId;
  final String name;
  final DealPhase phase;
  final DateTime? startsAt;
  final DateTime? endsAt;

  /// The price during the deal. For a product with variants: the cheapest variant's ("from").
  final Money? dealPrice;
  final Money? regularPrice;
  final int? percent;

  /// Units left in the deal (null = unlimited) and the share of the quantity that is left.
  final int? remaining;
  final int? remainingPercent;
  final int? limitPerOrder;
  final int? limitPerCustomer;

  /// Units this customer may still buy at the deal price. Null for a guest or without a per-customer limit; 0 = used up.
  final int? customerRemaining;
  final bool soldOut;
  final DealDesign design;

  /// The phase at [serverNow]: see [dealPhaseAt].
  DealPhase phaseAt(DateTime serverNow) => dealPhaseAt(phase, startsAt, endsAt, serverNow);

  /// The block as it reads for one variant of the product: that variant's own deal price and normal price. The percent is
  /// kept only while the price is the block's own (another price has another percent, and the app does not work it out).
  DealInfo forVariant({required Money dealPrice, Money? regularPrice}) {
    if (dealPrice == this.dealPrice) return this;
    return DealInfo(
      dealId: dealId,
      name: name,
      phase: phase,
      startsAt: startsAt,
      endsAt: endsAt,
      dealPrice: dealPrice,
      regularPrice: regularPrice ?? this.regularPrice,
      remaining: remaining,
      remainingPercent: remainingPercent,
      limitPerOrder: limitPerOrder,
      limitPerCustomer: limitPerCustomer,
      customerRemaining: customerRemaining,
      soldOut: soldOut,
      design: design,
    );
  }

  /// This customer has used all the units they may buy at the deal price.
  bool get limitReached => customerRemaining == 0;

  /// Show the deal price (struck regular price, badge...) at [serverNow]: live, not sold out, a price to show, and the
  /// customer still has units at that price. Without a server-corrected clock ([serverNow] null) the phase the server
  /// reported is trusted as it is: a phone's own clock is never used to second-guess it.
  bool showsDealPriceAt(DateTime? serverNow) {
    final p = serverNow == null ? phase : phaseAt(serverNow);
    return p == DealPhase.live && !soldOut && dealPrice != null && !limitReached;
  }

  /// Units are running out: the stock line and bar make sense.
  bool get hasStock => remaining != null;

  @override
  bool operator ==(Object other) =>
      other is DealInfo &&
      other.dealId == dealId &&
      other.name == name &&
      other.phase == phase &&
      other.startsAt == startsAt &&
      other.endsAt == endsAt &&
      other.dealPrice == dealPrice &&
      other.regularPrice == regularPrice &&
      other.percent == percent &&
      other.remaining == remaining &&
      other.remainingPercent == remainingPercent &&
      other.limitPerOrder == limitPerOrder &&
      other.limitPerCustomer == limitPerCustomer &&
      other.customerRemaining == customerRemaining &&
      other.soldOut == soldOut &&
      other.design == design;

  @override
  int get hashCode => Object.hash(
    dealId,
    name,
    phase,
    startsAt,
    endsAt,
    dealPrice,
    regularPrice,
    percent,
    remaining,
    remainingPercent,
    limitPerOrder,
    limitPerCustomer,
    customerRemaining,
    soldOut,
    design,
  );
}
