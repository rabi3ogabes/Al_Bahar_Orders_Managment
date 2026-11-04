import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/data/cart_repository.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
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

  Future<Cart> addItem(String variantId, {int quantity = 1}) async =>
      _apply(await _repo.addItem(variantId, quantity));

  Future<void> updateQuantity(String itemId, int quantity) async {
    if (quantity <= 0) return removeItem(itemId);
    final previous = state.value;
    final busy = ref.read(cartBusyItemsProvider.notifier);
    busy.add(itemId);
    if (previous != null) {
      state = AsyncData(
        Cart(
          id: previous.id,
          branchId: previous.branchId,
          fulfillmentType: previous.fulfillmentType,
          addressId: previous.addressId,
          couponCode: previous.couponCode,
          items: [for (final i in previous.items) i.id == itemId ? i.withQuantity(quantity) : i],
          itemsCount: previous.itemsCount,
          subtotal: previous.subtotal,
          warnings: previous.warnings,
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

  /// Throws [ApiException] (422 `coupon_invalid`) for inline display.
  Future<void> applyCoupon(String code) async => _apply(await _repo.update({'coupon_code': code.trim()}));

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
