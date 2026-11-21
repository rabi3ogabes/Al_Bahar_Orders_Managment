import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/domain/deal_lines.dart';
import 'package:flutter/foundation.dart';

/// Assembly and installation at the door for one cart line (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). The line carries one only
/// when its product offers it; [selected] is the customer's tick and [amount] what the line adds while ticked (null: unticked, or a pickup cart).
@immutable
class CartAssembly {
  const CartAssembly({required this.selected, required this.feeEach, this.amount});

  static CartAssembly? maybe(Object? json) {
    if (json is! Map) return null;
    final j = asMap(json);
    final fee = Money.lenient(j['fee_each']);
    if (fee == null) return null;
    return CartAssembly(selected: asBool(j['selected']), feeEach: fee, amount: Money.lenient(j['amount']));
  }

  final bool selected;
  final Money feeEach;
  final Money? amount;

  CartAssembly withSelected(bool value) =>
      CartAssembly(selected: value, feeEach: feeEach, amount: value ? amount : null);
}

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
    this.assembly,
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
      // The line's own `deal` (the product's deal block plus how many of this line's units get the deal price). Only a server that sends no
      // `deal` key at all falls back to the block on the product card: an explicit `deal: null` means this line has none.
      deal: json.containsKey('deal') ? DealInfo.maybe(json['deal']) : product.deal,
      dealUnits: DealLineUnits.maybe(json['deal']),
      assembly: CartAssembly.maybe(json['assembly']),
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

  /// Assembly and installation: null when the product offers none (and always on an older server).
  final CartAssembly? assembly;

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
    // The line's assembly total belongs to the old quantity too: it comes back with the server's answer.
    assembly: assembly == null ? null : CartAssembly(selected: assembly!.selected, feeEach: assembly!.feeEach),
  );

  CartItem withAssembly(CartAssembly? value) => CartItem(
    id: id,
    variantId: variantId,
    product: product,
    quantity: quantity,
    variantName: variantName,
    unitPrice: unitPrice,
    lineTotal: lineTotal,
    available: available,
    availableQuantity: availableQuantity,
    priceChanged: priceChanged,
    previousPrice: previousPrice,
    issue: issue,
    deal: deal,
    dealUnits: dealUnits,
    assembly: value,
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
    this.assemblyTotal,
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
      assemblyTotal: _positive(Money.lenient(json['assembly_total'])),
      warnings: asList(json['warnings'])
          .map((w) => w is Map ? asString(w['message']) : asString(w))
          .where((w) => w.isNotEmpty)
          .toList(),
    );
  }

  static Money? _positive(Money? m) => m != null && m.displayValue > 0 ? m : null;

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

  /// What the lines that chose assembly add to the total on delivery; null when none did. The subtotal stays goods only.
  final Money? assemblyTotal;

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
