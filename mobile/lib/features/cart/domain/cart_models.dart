import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/domain/deal_lines.dart';
import 'package:flutter/foundation.dart';

@immutable
class CartItem {
  const CartItem({
    required this.id,
    required this.variantId,
    required this.product,
    required this.quantity,
    this.variantName,
    this.unitPrice,
    this.lineTotal,
    this.available = true,
    this.availableQuantity,
    this.priceChanged = false,
    this.previousPrice,
    this.issue,
    this.deal,
    this.dealUnits,
  });

  factory CartItem.fromJson(JsonMap json) {
    final product = ProductSummary.fromJson(asMap(json['product']));
    return CartItem(
      id: asString(json['id']),
      variantId: asString(json['variant_id']),
      product: product,
      variantName: asStringOrNull(json['variant_name']),
      quantity: asInt(json['quantity'], 1),
      unitPrice: EffectivePrice.maybe(json['unit_price']),
      lineTotal: Money.maybe(json['line_total']),
      available: asBool(json['available'], true),
      availableQuantity: asIntOrNull(json['available_quantity']),
      priceChanged: asBool(json['price_changed']),
      previousPrice: _previous(json['previous_price']),
      issue: asStringOrNull(json['issue']),
      // The line's own `deal` (the product's deal block plus how many of this line's units get the deal price), else the one on its product card.
      deal: DealInfo.maybe(json['deal']) ?? product.deal,
      dealUnits: DealLineUnits.maybe(json['deal']),
    );
  }

  static Money? _previous(Object? v) {
    if (v is Map && v.containsKey('final')) return EffectivePrice.fromJson(asMap(v)).finalPrice;
    return Money.maybe(v);
  }

  final String id;
  final String variantId;
  final ProductSummary product;
  final String? variantName;
  final int quantity;
  final EffectivePrice? unitPrice;
  final Money? lineTotal;
  final bool available;
  final int? availableQuantity;
  final bool priceChanged;
  final Money? previousPrice;

  /// `null` | `unavailable_in_branch` | `insufficient_stock` | `inactive`.
  final String? issue;

  /// The deal this line's product is in (null: none, and always null on an older server).
  final DealInfo? deal;

  /// How many of this line's units get the deal price, as the server worked it out for this quantity; null without a deal, from an
  /// older server, and while a new quantity is on its way (the words wait for the server's answer).
  final DealLineUnits? dealUnits;

  bool get hasIssue => issue != null || !available;

  CartItem withQuantity(int q) => CartItem(
    id: id,
    variantId: variantId,
    product: product,
    variantName: variantName,
    quantity: q,
    unitPrice: unitPrice,
    lineTotal: lineTotal,
    available: available,
    availableQuantity: availableQuantity,
    priceChanged: priceChanged,
    previousPrice: previousPrice,
    issue: issue,
    deal: deal,
    // The unit counts belong to the old quantity: the server words the new one.
    dealUnits: null,
  );
}

@immutable
class Cart {
  const Cart({
    required this.id,
    required this.items,
    required this.itemsCount,
    required this.subtotal,
    this.branchId,
    this.fulfillmentType,
    this.addressId,
    this.couponCode,
    this.warnings = const [],
    this.dealSavings,
  });

  factory Cart.fromJson(JsonMap json) {
    final items = mapList(json['items'], CartItem.fromJson);
    return Cart(
      id: asString(json['id']),
      branchId: asStringOrNull(json['branch_id']),
      fulfillmentType: asStringOrNull(json['fulfillment_type']),
      addressId: asStringOrNull(json['address_id']),
      couponCode: asStringOrNull(json['coupon_code']),
      items: items,
      itemsCount: asInt(json['items_count'], items.fold<int>(0, (s, i) => s + i.quantity)),
      subtotal: Money.maybe(json['subtotal']) ?? Money.zero,
      dealSavings: Money.lenient(json['deal_savings']),
      warnings: asList(json['warnings'])
          .map((w) => w is Map ? asString(w['message']) : asString(w))
          .where((w) => w.isNotEmpty)
          .toList(),
    );
  }

  static const empty = Cart(id: '', items: [], itemsCount: 0, subtotal: Money.zero);

  final String id;
  final String? branchId;
  final String? fulfillmentType;
  final String? addressId;
  final String? couponCode;
  final List<CartItem> items;
  final int itemsCount;
  final Money subtotal;
  final List<String> warnings;

  /// What the deals save on this cart ("0.00" or null when none): shown as its own row, never worked out here.
  final Money? dealSavings;

  bool get isEmpty => items.isEmpty;
  bool get hasIssues => items.any((i) => i.hasIssue);
  bool get canCheckout => items.isNotEmpty && !hasIssues;
}

/// `POST /orders/{id}/reorder` → Cart + skipped.
@immutable
class ReorderResult {
  const ReorderResult({required this.cart, required this.skipped});
  factory ReorderResult.fromJson(JsonMap json) => ReorderResult(
    cart: Cart.fromJson(json['cart'] is Map ? asMap(json['cart']) : json),
    skipped: asList(json['skipped']),
  );
  final Cart cart;
  final List<Object?> skipped;
}
