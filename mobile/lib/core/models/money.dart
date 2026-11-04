import 'package:flutter/foundation.dart';

import '../utils/json.dart';

/// Server-computed monetary amount. The app never performs authoritative
/// arithmetic on money; it renders [formatted], which the API localises.
@immutable
class Money {
  const Money({required this.amount, required this.currency, required this.formatted});

  factory Money.fromJson(JsonMap json) {
    final amount = asString(json['amount'], '0');
    final currency = asString(json['currency'], 'QAR');
    return Money(
      amount: amount,
      currency: currency,
      formatted: asStringOrNull(json['formatted']) ?? '$currency $amount',
    );
  }

  static Money? maybe(Object? json) => json is Map ? Money.fromJson(asMap(json)) : null;

  static const zero = Money(amount: '0.00', currency: 'QAR', formatted: 'QAR 0.00');

  /// Decimal string as sent by the server, e.g. "1250.50".
  final String amount;
  final String currency;

  /// Localised display string, e.g. "QAR 1,250.50" / "1,250.50 ر.ق".
  final String formatted;

  /// Numeric value for *display-only* purposes (animations, comparisons for
  /// UI hints). Never use it to compute totals.
  double get displayValue => double.tryParse(amount) ?? 0;

  bool get isZero => displayValue == 0;

  JsonMap toJson() => {'amount': amount, 'currency': currency, 'formatted': formatted};

  @override
  bool operator ==(Object other) =>
      other is Money && other.amount == amount && other.currency == currency && other.formatted == formatted;

  @override
  int get hashCode => Object.hash(amount, currency, formatted);

  @override
  String toString() => formatted;
}

/// Price computed by the server for the requested / preferred branch.
@immutable
class EffectivePrice {
  const EffectivePrice({
    required this.regular,
    required this.sale,
    required this.finalPrice,
    required this.discountPercent,
    required this.saleEndsAt,
    required this.source,
  });

  factory EffectivePrice.fromJson(JsonMap json) {
    final regular = Money.maybe(json['regular']) ?? Money.maybe(json['final']) ?? Money.zero;
    return EffectivePrice(
      regular: regular,
      sale: Money.maybe(json['sale']),
      finalPrice: Money.maybe(json['final']) ?? regular,
      discountPercent: asIntOrNull(json['discount_percent']),
      saleEndsAt: asDate(json['sale_ends_at']),
      source: asStringOrNull(json['source']),
    );
  }

  static EffectivePrice? maybe(Object? json) => json is Map ? EffectivePrice.fromJson(asMap(json)) : null;

  final Money regular;
  final Money? sale;
  final Money finalPrice;
  final int? discountPercent;
  final DateTime? saleEndsAt;
  final String? source;

  bool get isOnSale => sale != null && finalPrice.amount != regular.amount;
}

@immutable
class ImageRef {
  const ImageRef({required this.url, this.thumbUrl, this.alt, this.variantId});

  factory ImageRef.fromJson(JsonMap json) => ImageRef(
    url: asString(json['url']),
    thumbUrl: asStringOrNull(json['thumb_url']),
    alt: asStringOrNull(json['alt']),
    variantId: asStringOrNull(json['variant_id']),
  );

  static ImageRef? maybe(Object? json) {
    if (json is Map) {
      final ref = ImageRef.fromJson(asMap(json));
      return ref.url.isEmpty ? null : ref;
    }
    if (json is String && json.isNotEmpty) return ImageRef(url: json);
    return null;
  }

  final String url;
  final String? thumbUrl;
  final String? alt;
  final String? variantId;

  String get listUrl => thumbUrl ?? url;
}

/// A `{id, name}` reference used by many resources.
@immutable
class NamedRef {
  const NamedRef({required this.id, required this.name, this.slug});

  factory NamedRef.fromJson(JsonMap json) => NamedRef(
    id: asString(json['id']),
    name: asString(json['name']),
    slug: asStringOrNull(json['slug']),
  );

  static NamedRef? maybe(Object? json) => json is Map ? NamedRef.fromJson(asMap(json)) : null;

  final String id;
  final String name;
  final String? slug;

  JsonMap toJson() => {'id': id, 'name': name, if (slug != null) 'slug': slug};
}
