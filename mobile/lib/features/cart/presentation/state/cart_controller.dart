import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/data/cart_repository.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Server cart for the signed-in customer (empty for guests).
///
/// Quantity changes are applied optimistically and rolled back on error;
/// totals are always replaced by the server response.
class CartController extends AsyncNotifier<Cart> {
  @override
  Future<Cart> build() async {
    final signedIn = ref.watch(isAuthenticatedProvider);
    ref.watch(localeCodeProvider);
    if (!signedIn) return Cart.empty;

    // Keep the server cart's branch aligned with the preferred branch.
    ref.listen(preferredBranchIdProvider, (prev, next) {
      if (next != null && prev != next) setBranch(next);
    });

    return ref.read(cartRepositoryProvider).fetch(branchId: ref.read(preferredBranchIdProvider));
  }

  CartRepository get _repo => ref.read(cartRepositoryProvider);

  Future<Cart> _apply(Cart? fromServer) async {
    final cart = fromServer ?? await _repo.fetch(branchId: ref.read(preferredBranchIdProvider));
    if (ref.mounted) state = AsyncData(cart);
    return cart;
  }

  /// Adds are in flight; the server's cart is only shown once the last one has answered, so a quick second tap never
  /// flickers the quantity back to what the first answer said.
  int _adding = 0;

  /// Puts [quantity] of a variant in the cart. The cart (and the badge) change at once, before the server answers:
  /// a variant that is already in the cart gets its quantity raised (never a second line), a new one gets a line built
  /// from [product]. If the server refuses, the cart goes back to what it was and the error is thrown.
  Future<Cart> addItem(
    String variantId, {
    int quantity = 1,
    ProductSummary? product,
    EffectivePrice? unitPrice,
    String? variantName,
  }) async {
    final previous = state.value ?? Cart.empty;
    final existing = previous.items.where((i) => i.variantId == variantId).firstOrNull;
    final pendingId = existing?.id ?? (product == null ? null : 'pending-$variantId');
    final busy = ref.read(cartBusyItemsProvider.notifier);
    if (pendingId != null) {
      busy.add(pendingId);
      state = AsyncData(
        _copy(
          previous,
          items: existing != null
              ? [for (final i in previous.items) i.variantId == variantId ? i.withQuantity(i.quantity + quantity) : i]
              : [
                  ...previous.items,
                  CartItem(
                    id: pendingId,
                    variantId: variantId,
                    product: product!,
                    quantity: quantity,
                    variantName: variantName,
                    unitPrice: unitPrice ?? product.price,
                  ),
                ],
          itemsCount: previous.itemsCount + quantity,
        ),
      );
    }
    _adding++;
    try {
      final server = await _repo.addItem(variantId, quantity);
      _adding--;
      if (_adding > 0) return state.value ?? server ?? previous;
      return await _apply(server);
    } catch (_) {
      _adding--;
      if (pendingId != null && ref.mounted && _adding == 0) state = AsyncData(previous);
      rethrow;
    } finally {
      if (pendingId != null) busy.remove(pendingId);
    }
  }

  Cart _copy(Cart c, {required List<CartItem> items, required int itemsCount}) => Cart(
    id: c.id,
    branchId: c.branchId,
    fulfillmentType: c.fulfillmentType,
    addressId: c.addressId,
    couponCode: c.couponCode,
    items: items,
    itemsCount: itemsCount,
    subtotal: c.subtotal,
    warnings: c.warnings,
  );

  Future<void> updateQuantity(String itemId, int quantity) async {
    if (quantity <= 0) return removeItem(itemId);
    final previous = state.value;
    final busy = ref.read(cartBusyItemsProvider.notifier);
    busy.add(itemId);
    if (previous != null) {
      final old = previous.items.where((i) => i.id == itemId).firstOrNull?.quantity ?? quantity;
      state = AsyncData(
        _copy(
          previous,
          items: [for (final i in previous.items) i.id == itemId ? i.withQuantity(quantity) : i],
          itemsCount: previous.itemsCount + (quantity - old),
        ),
      );
    }
    try {
      await _apply(await _repo.updateItem(itemId, quantity));
    } catch (_) {
      if (previous != null && ref.mounted) state = AsyncData(previous);
      rethrow;
    } finally {
      busy.remove(itemId);
    }
  }

  Future<void> removeItem(String itemId) async {
    final previous = state.value;
    final busy = ref.read(cartBusyItemsProvider.notifier);
    busy.add(itemId);
    try {
      await _apply(await _repo.removeItem(itemId));
    } catch (_) {
      if (previous != null && ref.mounted) state = AsyncData(previous);
      rethrow;
    } finally {
      busy.remove(itemId);
    }
  }

  /// Throws [ApiException] (422, `errors.coupon_code`) for inline display.
  ///
  /// The backend accepts any code on `PUT /cart`, so the code is checked with
  /// a quote right after; an invalid code is removed again and surfaced as a
  /// validation error. If the check itself fails the code is kept and the
  /// checkout quote will report it.
  Future<void> applyCoupon(String code) async {
    final trimmed = code.trim();
    await _apply(await _repo.update({'coupon_code': trimmed}));
    QuoteIssue? issue;
    try {
      issue = await _repo.couponIssue(trimmed);
    } on ApiException {
      return;
    }
    if (issue == null) return;
    await _apply(await _repo.update({'coupon_code': null}));
    throw ApiException(
      kind: ApiErrorKind.validation,
      statusCode: 422,
      code: issue.code,
      message: issue.message,
      errors: {
        'coupon_code': [issue.message],
      },
    );
  }

  Future<void> removeCoupon() async => _apply(await _repo.update({'coupon_code': null}));

  Future<void> setBranch(String branchId) async {
    try {
      await _apply(await _repo.update({'branch_id': branchId}));
    } catch (_) {
      // Fall back to a plain re-fetch with the new branch.
      try {
        await _apply(null);
      } catch (_) {}
    }
  }

  /// Re-validates prices / stock with the server (e.g. before checkout).
  Future<Cart> revalidate() => _apply(null);

  /// Replace from another endpoint (e.g. reorder).
  void replace(Cart cart) => state = AsyncData(cart);
}

final cartControllerProvider = AsyncNotifierProvider<CartController, Cart>(CartController.new);

/// Cart badge count.
final cartCountProvider = Provider<int>((ref) => ref.watch(cartControllerProvider).value?.itemsCount ?? 0);

class CartBusyItems extends Notifier<Set<String>> {
  @override
  Set<String> build() => const {};
  void add(String id) => state = {...state, id};
  void remove(String id) => state = {...state}..remove(id);
}

final cartBusyItemsProvider = NotifierProvider<CartBusyItems, Set<String>>(CartBusyItems.new);
