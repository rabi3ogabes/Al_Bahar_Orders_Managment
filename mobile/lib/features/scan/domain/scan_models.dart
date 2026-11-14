import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/foundation.dart';

/// The answer of `GET /products/by-code/{code}`: the usual product card plus what the app needs to add the item at once.
///
/// [product] carries the price of the exact variant that was scanned (not the "from" price of the whole product) and
/// that variant's id in `variantId`; both are null/blank while the shopper still has to choose ([needsChoice]).
@immutable
class ScanLookup {
  const ScanLookup({
    required this.product,
    required this.variantName,
    required this.matchedBy,
    required this.inStock,
    required this.purchasable,
    required this.needsChoice,
    required this.maxQuantity,
  });

  factory ScanLookup.fromJson(JsonMap json) => ScanLookup(
    product: ProductSummary.fromJson(json),
    variantName: asStringOrNull(json['variant_name']),
    matchedBy: asString(json['matched_by']),
    inStock: asBool(json['in_stock']),
    purchasable: asBool(json['purchasable']),
    needsChoice: asBool(json['needs_choice']),
    maxQuantity: asIntOrNull(json['max_quantity']),
  );

  final ProductSummary product;
  final String? variantName;

  /// `variant_barcode | product_barcode | variant_sku | product_sku | url | deep_link | slug | id`.
  final String matchedBy;

  /// At least one unit is available.
  final bool inStock;

  /// The shop sells it in this branch context (it has a price here), whatever the stock.
  final bool purchasable;

  /// The code names a product with several variants (colour, size): the shopper must pick one.
  final bool needsChoice;

  /// Most units one add may reach (stock and the per-line cart limit); 0 when it cannot be added; null while a choice is open.
  final int? maxQuantity;

  String? get variantId => product.variantId;
  EffectivePrice? get price => product.price;

  /// Can the app put one unit in the cart without asking anything?
  bool get canAdd => !needsChoice && variantId != null && purchasable && inStock && (maxQuantity ?? 0) > 0;
}
