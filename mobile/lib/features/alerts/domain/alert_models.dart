import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

/// The two things a customer can ask to be told: that a product is back in stock, or that its price dropped.
enum AlertType {
  backInStock('back_in_stock'),
  priceDrop('price_drop');

  const AlertType(this.wire);

  /// The word the API uses.
  final String wire;

  static AlertType? parse(Object? value) {
    for (final t in values) {
      if (t.wire == value) return t;
    }
    return null;
  }
}

/// What the store offers (`GET /stock-alerts/config`). Until the server has answered, back-in-stock is on and price alerts are not
/// (the owner switches them on), so a choice that cannot fire is never shown.
@immutable
class AlertsConfig {
  const AlertsConfig({this.backInStock = true, this.priceDrop = false});

  factory AlertsConfig.fromJson(JsonMap json) =>
      AlertsConfig(backInStock: asBool(json['back_in_stock'], true), priceDrop: asBool(json['price_drop']));

  static const fallback = AlertsConfig();

  final bool backInStock;
  final bool priceDrop;
}

/// What a line of "My alerts" says: what the alert waits for, or what already happened.
enum AlertStatus { waitingStock, availableNow, watching, soldOut }

@immutable
class StockAlert {
  const StockAlert({
    required this.id,
    required this.type,
    required this.productId,
    required this.productName,
    required this.variantId,
    required this.inStock,
    this.productSlug,
    this.imageUrl,
    this.variantName,
    this.branchId,
    this.referencePrice,
    this.currentPrice,
    this.since,
    this.expiresAt,
  });

  factory StockAlert.fromJson(JsonMap json) {
    final product = asMap(json['product']);
    final variant = asMap(json['variant']);
    return StockAlert(
      id: asString(json['id']),
      type: AlertType.parse(json['type']) ?? AlertType.backInStock,
      productId: asString(product['id']),
      productSlug: asStringOrNull(product['slug']),
      productName: asString(product['name']),
      imageUrl: asStringOrNull(product['thumb_url']) ?? asStringOrNull(product['image_url']),
      variantId: asString(variant['id']),
      variantName: asStringOrNull(variant['name']),
      branchId: asStringOrNull(json['branch_id']),
      referencePrice: Money.maybe(json['reference_price']),
      currentPrice: Money.maybe(json['current_price']),
      inStock: asBool(json['in_stock']),
      since: asDate(json['since']),
      expiresAt: asDate(json['expires_at']),
    );
  }

  final String id;
  final AlertType type;
  final String productId;
  final String? productSlug;
  final String productName;
  final String? imageUrl;
  final String variantId;
  final String? variantName;
  final String? branchId;

  /// The price the customer started from (a price alert), and what it costs now.
  final Money? referencePrice;
  final Money? currentPrice;
  final bool inStock;
  final DateTime? since;
  final DateTime? expiresAt;

  AlertStatus get status => type == AlertType.backInStock
      ? (inStock ? AlertStatus.availableNow : AlertStatus.waitingStock)
      : (inStock ? AlertStatus.watching : AlertStatus.soldOut);

  /// How far the price has fallen since the customer started watching, as a whole percent (positive = cheaper). Null when there is nothing to compare,
  /// and zero when the price is the same or higher.
  int? get droppedPercent {
    final was = referencePrice?.displayValue;
    final now = currentPrice?.displayValue;
    if (was == null || now == null || was <= 0) return null;
    return now < was ? ((was - now) / was * 100).round() : 0;
  }
}

/// What the product page offers for the chosen variant.
enum BellState { hidden, notify, subscribed }

enum PriceAlertState { hidden, offer, subscribed }

@immutable
class ProductAlertControls {
  const ProductAlertControls({required this.bell, required this.price});
  final BellState bell;
  final PriceAlertState price;
  bool get isEmpty => bell == BellState.hidden && price == PriceAlertState.hidden;
}

/// The back-in-stock bell is for what cannot be bought; "Tell me if the price drops" is for what can, and only while the owner has it on. A customer who
/// already watches the price may always turn it off, even if the owner switched the feature off meanwhile.
ProductAlertControls productAlertControls({
  required bool canBuy,
  required AlertsConfig config,
  required List<StockAlert> alerts,
  required String? variantId,
}) {
  if (variantId == null) return const ProductAlertControls(bell: BellState.hidden, price: PriceAlertState.hidden);
  bool has(AlertType t) => alerts.any((a) => a.variantId == variantId && a.type == t);
  final watching = has(AlertType.priceDrop);
  return ProductAlertControls(
    bell: canBuy || !config.backInStock
        ? BellState.hidden
        : has(AlertType.backInStock)
        ? BellState.subscribed
        : BellState.notify,
    price: !canBuy && !watching
        ? PriceAlertState.hidden
        : watching
        ? PriceAlertState.subscribed
        : config.priceDrop
        ? PriceAlertState.offer
        : PriceAlertState.hidden,
  );
}
