import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// One side of a replacement question: the line the customer ordered, or the one that would take its place.
@immutable
class SubstitutionLine {
  const SubstitutionLine({required this.name, this.variantName, this.quantity = 1, this.imageUrl, this.total});

  static SubstitutionLine? maybe(Object? json) {
    final m = asMapOrNull(json);
    if (m == null) return null;
    final variant = asString(m['variant_name']).trim();
    return SubstitutionLine(
      name: asString(m['name']),
      variantName: variant.isEmpty ? null : variant,
      quantity: asInt(m['quantity'], 1),
      imageUrl: asStringOrNull(m['image_url']),
      total: Money.maybe(m['total']),
    );
  }

  final String name;
  final String? variantName;
  final int quantity;
  final String? imageUrl;
  final Money? total;
}

/// "We do not have X. Replace it with Y?" (`substitution_requests` of an order): the customer accepts, or has the line removed and refunded;
/// the server settles it by the shop's fallback when [expiresAt] passes. The price is never above what was ordered.
@immutable
class SubstitutionOffer {
  const SubstitutionOffer({
    required this.id,
    required this.status,
    this.original,
    this.replacement,
    this.priceDifference,
    this.fallback = 'remove',
    this.expiresAt,
    this.canAnswer = false,
  });

  factory SubstitutionOffer.fromJson(JsonMap j) => SubstitutionOffer(
    id: asString(j['id']),
    status: asString(j['status']),
    original: SubstitutionLine.maybe(j['original']),
    replacement: SubstitutionLine.maybe(j['replacement']),
    priceDifference: Money.maybe(j['price_difference']),
    fallback: asString(j['fallback'], 'remove'),
    expiresAt: asDate(j['expires_at']),
    canAnswer: asBool(j['can_answer']),
  );

  final String id;

  /// `waiting`, `accepted`, `declined`, `expired_removed`, `expired_kept` or `cancelled`.
  final String status;
  final SubstitutionLine? original;
  final SubstitutionLine? replacement;

  /// What the customer pays more (a negative amount: less) than for the line they ordered; never above zero.
  final Money? priceDifference;

  /// What happens when time runs out: `remove` (taken out and refunded) or `keep` (the replacement stays at no extra cost).
  final String fallback;
  final DateTime? expiresAt;
  final bool canAnswer;

  bool get isWaiting => status == 'waiting';

  /// True when it costs less than what was ordered (the amount is negative); it can never cost more.
  bool get isCheaper => (double.tryParse(priceDifference?.amount ?? '0') ?? 0) < 0;

  /// Whole minutes left (rounded up), never below zero.
  int minutesLeft(DateTime now) {
    final at = expiresAt;
    if (at == null) return 0;
    final ms = at.difference(now).inMilliseconds;
    return ms <= 0 ? 0 : (ms / 60000).ceil();
  }
}

/// The open questions, soonest to run out first.
List<SubstitutionOffer> waitingOffers(List<SubstitutionOffer> all) =>
    all.where((o) => o.isWaiting).toList()
      ..sort((a, b) => (a.expiresAt ?? DateTime(9999)).compareTo(b.expiresAt ?? DateTime(9999)));
