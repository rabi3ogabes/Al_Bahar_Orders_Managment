import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/foundation.dart';

/// The most units of one item a guest's cart holds (the same cap as the web store). The server's own limits are applied when they sign in.
const guestCartMaxQuantity = 20;

/// One line of a signed-out customer's cart, kept on this phone (shared preferences) so it survives closing the app. It holds only what is
/// needed to draw the line and to ask the server for the real thing later: the variant and how many, the choice of assembly, and a copy of the
/// product's name, picture and price as the shop showed them. Nothing here is trusted: prices, stock, deals and fees are read again from the
/// public product pages whenever the cart is shown, and the server prices the order when the customer signs in and checks out.
@immutable
class GuestCartRow {
  const GuestCartRow({
    required this.variantId,
    required this.productId,
    required this.name,
    required this.quantity,
    required this.price,
    this.slug,
    this.sku,
    this.brandName,
    this.imageUrl,
    this.thumbUrl,
    this.variantName,
    this.assemblyFee,
    this.assembly = false,
    this.availableQuantity,
    this.issue,
    this.previousPrice,
  });

  factory GuestCartRow.fromJson(JsonMap j) => GuestCartRow(
    variantId: asString(j['variant_id']),
    productId: asString(j['product_id']),
    name: asString(j['name']),
    quantity: asInt(j['quantity'], 1).clamp(1, guestCartMaxQuantity),
    price: EffectivePrice.fromJson(asMap(j['price'])),
    slug: asStringOrNull(j['slug']),
    sku: asStringOrNull(j['sku']),
    brandName: asStringOrNull(j['brand']),
    imageUrl: asStringOrNull(j['image_url']),
    thumbUrl: asStringOrNull(j['thumb_url']),
    variantName: asStringOrNull(j['variant_name']),
    assemblyFee: Money.maybe(j['assembly_fee']),
    assembly: asBool(j['assembly']),
    availableQuantity: asIntOrNull(j['available_quantity']),
    issue: asStringOrNull(j['issue']),
    previousPrice: Money.maybe(j['previous_price']),
  );

  /// A row for [quantity] units of a product's variant, from what the page or the card showed.
  factory GuestCartRow.of({
    required String variantId,
    required ProductSummary product,
    required int quantity,
    EffectivePrice? unitPrice,
    String? variantName,
    bool assembly = false,
  }) => GuestCartRow(
    variantId: variantId,
    productId: product.id,
    name: product.name,
    quantity: quantity.clamp(1, guestCartMaxQuantity),
    price:
        unitPrice ??
        product.price ??
        const EffectivePrice(
          regular: Money.zero,
          sale: null,
          finalPrice: Money.zero,
          discountPercent: null,
          saleEndsAt: null,
          source: null,
        ),
    slug: product.slug,
    sku: product.sku,
    brandName: product.brand?.name,
    imageUrl: product.image?.url,
    thumbUrl: product.image?.thumbUrl,
    variantName: variantName,
    assemblyFee: product.assemblyFee,
    assembly: assembly && product.assemblyFee != null,
  );

  final String variantId;
  final String productId;
  final String name;
  final int quantity;

  /// The price of one unit as the shop last showed it.
  final EffectivePrice price;
  final String? slug;
  final String? sku;
  final String? brandName;
  final String? imageUrl;
  final String? thumbUrl;
  final String? variantName;

  /// The fee per unit when the product offers assembly (null: it does not).
  final Money? assemblyFee;
  final bool assembly;

  /// What the shop last said is on the shelf (null: not known yet) and why a line cannot be bought now (`inactive`, `insufficient_stock`).
  final int? availableQuantity;
  final String? issue;

  /// The price before the shop changed it (null: it did not); shown once as "price changed".
  final Money? previousPrice;

  Money get unit => price.finalPrice;

  GuestCartRow copyWith({
    int? quantity,
    EffectivePrice? price,
    bool? assembly,
    Money? assemblyFee,
    bool clearAssemblyFee = false,
    int? availableQuantity,
    String? issue,
    bool clearIssue = false,
    Money? previousPrice,
    bool clearPrevious = false,
    String? name,
    String? thumbUrl,
    String? imageUrl,
  }) => GuestCartRow(
    variantId: variantId,
    productId: productId,
    name: name ?? this.name,
    quantity: quantity ?? this.quantity,
    price: price ?? this.price,
    slug: slug,
    sku: sku,
    brandName: brandName,
    imageUrl: imageUrl ?? this.imageUrl,
    thumbUrl: thumbUrl ?? this.thumbUrl,
    variantName: variantName,
    assemblyFee: clearAssemblyFee ? null : assemblyFee ?? this.assemblyFee,
    assembly: assembly ?? this.assembly,
    availableQuantity: availableQuantity ?? this.availableQuantity,
    issue: clearIssue ? null : issue ?? this.issue,
    previousPrice: clearPrevious ? null : previousPrice ?? this.previousPrice,
  );

  JsonMap toJson() => {
    'variant_id': variantId,
    'product_id': productId,
    'name': name,
    'quantity': quantity,
    'price': price.toJson(),
    'slug': slug,
    'sku': sku,
    'brand': brandName,
    'image_url': imageUrl,
    'thumb_url': thumbUrl,
    'variant_name': variantName,
    'assembly_fee': assemblyFee?.toJson(),
    'assembly': assembly,
    'available_quantity': availableQuantity,
    'issue': issue,
    'previous_price': previousPrice?.toJson(),
  };
}

/// Something the shop changed behind a guest's cart, said once in plain words ("The price of X changed", "Only 2 of X are left").
enum GuestChangeKind { priceChanged, reduced, unavailable, assemblyRemoved }

@immutable
class GuestCartChange {
  const GuestCartChange(this.kind, this.name, {this.from, this.to, this.available});
  final GuestChangeKind kind;
  final String name;
  final Money? from;
  final Money? to;
  final int? available;
}

/// The pure rules of a signed-out customer's cart (no storage, no network, no widgets), so they are tested alone
/// (`test/features/cart/guest_cart_test.dart`). Every function returns new rows and never changes its input.
abstract final class GuestCart {
  /// Adds [quantity] units of [row]: a variant that is already there grows (never a second line), up to the cap and the stock the shop last
  /// reported. Ticking assembly on the new add turns it on for the line; adding without it never turns it off.
  static List<GuestCartRow> add(List<GuestCartRow> rows, GuestCartRow row, int quantity) {
    if (quantity < 1) return rows;
    final i = rows.indexWhere((r) => r.variantId == row.variantId);
    if (i < 0) return [...rows, row.copyWith(quantity: _cap(quantity, row.availableQuantity))];
    final old = rows[i];
    final next = old.copyWith(
      quantity: _cap(old.quantity + quantity, old.availableQuantity),
      assembly: old.assembly || (row.assembly && (old.assemblyFee ?? row.assemblyFee) != null),
      assemblyFee: old.assemblyFee ?? row.assemblyFee,
    );
    return [for (var k = 0; k < rows.length; k++) k == i ? next : rows[k]];
  }

  /// Sets a line's quantity; zero or less removes it.
  static List<GuestCartRow> setQuantity(List<GuestCartRow> rows, String variantId, int quantity) {
    if (quantity <= 0) return remove(rows, variantId);
    return [
      for (final r in rows)
        r.variantId == variantId
            ? r.copyWith(quantity: _cap(quantity, r.availableQuantity), clearIssue: _fits(quantity, r))
            : r,
    ];
  }

  static List<GuestCartRow> remove(List<GuestCartRow> rows, String variantId) => [
    for (final r in rows)
      if (r.variantId != variantId) r,
  ];

  /// Ticks or unticks assembly; a product that offers none (no fee) stays as it is.
  static List<GuestCartRow> setAssembly(List<GuestCartRow> rows, String variantId, bool on) => [
    for (final r in rows) r.variantId == variantId && r.assemblyFee != null ? r.copyWith(assembly: on) : r,
  ];

  static int _cap(int quantity, int? available) {
    final stock = available != null && available > 0 ? available : guestCartMaxQuantity;
    return quantity.clamp(1, stock < guestCartMaxQuantity ? stock : guestCartMaxQuantity);
  }

  /// A smaller quantity clears the "only n left" note that no longer applies.
  static bool _fits(int quantity, GuestCartRow r) =>
      r.issue == 'insufficient_stock' && (r.availableQuantity == null || quantity <= r.availableQuantity!);

  /// The rows as a [Cart] the cart screens draw: lines with their totals, the subtotal of the lines that can be bought, and the assembly total.
  /// This is arithmetic on the shop's own prices for display only (the web store does the same); the server prices the order itself.
  static Cart toCart(List<GuestCartRow> rows, {required String locale, String? branchId}) {
    var subtotal = 0;
    var assembly = 0;
    var count = 0;
    final items = <CartItem>[];
    for (final r in rows) {
      final unit = _minor(r.unit);
      final buyable = r.issue == null;
      if (buyable) subtotal += unit * r.quantity;
      final fee = r.assemblyFee == null ? null : _minor(r.assemblyFee!);
      if (buyable && r.assembly && fee != null) assembly += fee * r.quantity;
      count += r.quantity;
      items.add(
        CartItem(
          id: 'guest-${r.variantId}',
          variantId: r.variantId,
          product: _summary(r),
          quantity: r.quantity,
          variantName: r.variantName,
          unitPrice: r.price,
          lineTotal: moneyOfMinor(unit * r.quantity, locale: locale),
          available: buyable,
          availableQuantity: r.availableQuantity,
          priceChanged: r.previousPrice != null,
          previousPrice: r.previousPrice,
          issue: r.issue,
          assembly: r.assemblyFee == null
              ? null
              : CartAssembly(
                  selected: r.assembly,
                  feeEach: r.assemblyFee!,
                  amount: r.assembly && fee != null ? moneyOfMinor(fee * r.quantity, locale: locale) : null,
                ),
        ),
      );
    }
    return Cart(
      id: 'guest',
      branchId: branchId,
      fulfillmentType: 'delivery',
      items: items,
      itemsCount: count,
      subtotal: moneyOfMinor(subtotal, locale: locale),
      assemblyTotal: assembly > 0 ? moneyOfMinor(assembly, locale: locale) : null,
    );
  }

  /// Brings the rows up to date with what the public product pages say now ([products] by product id; a product that could not be read is
  /// simply absent and its lines stay as they were). Returns the new rows and what changed, for one plain message.
  static ({List<GuestCartRow> rows, List<GuestCartChange> changes}) refresh(
    List<GuestCartRow> rows,
    Map<String, ProductDetail> products,
  ) {
    final changes = <GuestCartChange>[];
    final next = <GuestCartRow>[];
    for (final r in rows) {
      final product = products[r.productId];
      if (product == null) {
        next.add(r);
        continue;
      }
      final variant = product.variants.where((v) => v.id == r.variantId).firstOrNull;
      if (variant == null || !variant.purchasable) {
        if (r.issue != 'inactive') changes.add(GuestCartChange(GuestChangeKind.unavailable, r.name));
        next.add(r.copyWith(issue: 'inactive', availableQuantity: 0, name: product.summary.name));
        continue;
      }
      var row = r.copyWith(name: product.summary.name, clearIssue: true);
      // The price: a different price is shown once ("changed from ... to ...") and the line is priced at the new one from now on.
      final now = variant.price;
      if (now != null && now.finalPrice.amount != r.unit.amount) {
        row = row.copyWith(price: now, previousPrice: r.unit);
        changes.add(GuestCartChange(GuestChangeKind.priceChanged, r.name, from: r.unit, to: now.finalPrice));
      } else if (now != null) {
        row = row.copyWith(price: now, clearPrevious: true);
      }
      // The assembly offer: its fee as the shop says it now; a product that no longer offers it loses the tick.
      final fee = product.summary.assemblyFee;
      if (fee == null && r.assemblyFee != null) {
        row = row.copyWith(clearAssemblyFee: true, assembly: false);
        if (r.assembly) changes.add(GuestCartChange(GuestChangeKind.assemblyRemoved, r.name));
      } else if (fee != null) {
        row = row.copyWith(assemblyFee: fee);
      }
      // The stock: fewer on the shelf than wanted is said once and the line is brought down to what is there.
      final available = variant.availableQuantity;
      if (available != null) {
        row = row.copyWith(availableQuantity: available);
        if (row.quantity > available) {
          changes.add(GuestCartChange(GuestChangeKind.reduced, r.name, available: available));
          row = row.copyWith(
            quantity: available.clamp(1, guestCartMaxQuantity),
            issue: available < 1 ? 'inactive' : null,
            clearIssue: available >= 1,
          );
        }
      }
      next.add(row);
    }
    return (rows: next, changes: changes);
  }

  /// A short product card for [row], as the cart lines expect it.
  static ProductSummary _summary(GuestCartRow r) => ProductSummary.fromJson({
    'id': r.productId,
    'slug': r.slug,
    'sku': r.sku,
    'name': r.name,
    'brand': r.brandName == null ? null : {'id': '', 'name': r.brandName},
    'image': r.imageUrl == null ? null : {'url': r.imageUrl, 'thumb_url': r.thumbUrl},
    'price': r.price.toJson(),
    'in_stock': r.issue == null,
    'variant_id': r.variantId,
    'assembly_fee': r.assemblyFee?.toJson(),
  });
}

/// Whole dirham (1/100 QAR) of a money value read from its decimal text ("1250.50" -> 125050); anything unreadable is zero.
int _minor(Money m) {
  final parts = m.amount.trim().split('.');
  final whole = int.tryParse(parts.first) ?? 0;
  final cents = parts.length > 1 ? int.tryParse(parts[1].padRight(2, '0').substring(0, 2)) ?? 0 : 0;
  return whole * 100 + (m.amount.trim().startsWith('-') ? -cents : cents);
}

/// A money value of [minor] dirham in QAR, written the way the shop writes it: "QAR 1,250.50" in English, "1,250.50 ر.ق" in Arabic.
Money moneyOfMinor(int minor, {String locale = 'en'}) {
  final negative = minor < 0;
  final abs = minor.abs();
  final whole = (abs ~/ 100).toString();
  final grouped = whole.replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  final cents = (abs % 100).toString().padLeft(2, '0');
  final amount = '${negative ? '-' : ''}${abs ~/ 100}.$cents';
  final text = '${negative ? '-' : ''}$grouped.$cents';
  return Money(amount: amount, currency: 'QAR', formatted: locale == 'ar' ? '$text ر.ق' : 'QAR $text');
}
