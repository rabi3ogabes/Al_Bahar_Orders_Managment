import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/foundation.dart';

/// One thing to put in the cart: a variant, how many, and what the cart window needs to draw it before the server answers.
@immutable
class CartAddition {
  const CartAddition({
    required this.variantId,
    required this.product,
    this.quantity = 1,
    this.unitPrice,
    this.variantName,
    this.assembly = false,
  });
  final String variantId;
  final ProductSummary product;
  final int quantity;
  final EffectivePrice? unitPrice;
  final String? variantName;

  /// Also add assembly and installation for the line (only a product that offers it).
  final bool assembly;
}
