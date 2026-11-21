import 'dart:async';

import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/data/cart_repository.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/domain/guest_cart.dart';
import 'package:albahar_customer/features/cart/domain/guest_merge.dart';
import 'package:albahar_customer/features/cart/presentation/state/guest_cart_notice.dart';
import 'package:albahar_customer/features/catalog/data/catalog_repository.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The customer's cart.
///
/// Signed in: the server cart. Quantity changes are applied optimistically and rolled back on error; totals are always replaced by the server
/// response. Signed out: a cart kept on this phone ([GuestCart], saved in shared preferences so it survives restarts), drawn from the shop's own
/// public prices; the same screens show it. Right after sign-in it is folded into the account cart ([GuestMerge]) and cleared, and anything the
/// server could not take as it was is told once through [guestCartNoticeProvider]. Checkout is where sign-in is asked: the router sends the
/// person to sign in and then straight back to checkout (`resolveRedirect`).
class CartController extends AsyncNotifier<Cart> {
  /// The id of a guest line is its variant: there is one line per variant, and no server id exists yet.
  static const _guestPrefix = 'guest-';

  @override
  Future<Cart> build() async {
    final signedIn = ref.watch(isAuthenticatedProvider);
    final locale = ref.watch(localeCodeProvider);
    if (!signedIn) {
      final rows = _guestRows();
      // The shop's prices and stock are read again behind the screen (never blocks it): a change is shown once, in plain words.
      if (rows.isNotEmpty) unawaited(Future<void>.microtask(refreshGuest));
      return GuestCart.toCart(rows, locale: locale, branchId: ref.read(preferredBranchIdProvider));
    }

    // Keep the server cart's branch aligned with the preferred branch.
    ref.listen(preferredBranchIdProvider, (prev, next) {
      if (next != null && prev != next) setBranch(next);
    });

    final cart = await _repo.fetch(branchId: ref.read(preferredBranchIdProvider));
    return _mergeGuest(cart);
  }

  CartRepository get _repo => ref.read(cartRepositoryProvider);

  bool get _signedIn => ref.read(isAuthenticatedProvider);

  // ── The cart of a signed-out customer ────────────────────────────────────────────────────────────

  List<GuestCartRow> _guestRows() => ref.read(appPreferencesProvider).guestCart.map(GuestCartRow.fromJson).toList();

  Cart _saveGuest(List<GuestCartRow> rows) {
    unawaited(ref.read(appPreferencesProvider).setGuestCart([for (final r in rows) r.toJson()]));
    final cart = GuestCart.toCart(
      rows,
      locale: ref.read(localeCodeProvider),
      branchId: ref.read(preferredBranchIdProvider),
    );
    if (ref.mounted) state = AsyncData(cart);
    return cart;
  }

  /// Reads the shop's public product pages again for what is in a guest's cart (one request per product, at most a dozen) and brings the
  /// lines up to date: a new price, fewer units on the shelf, a product that is gone, an assembly offer that ended. A page that cannot be
  /// read leaves its lines as they were. What changed is told once through [guestCartNoticeProvider].
  Future<void> refreshGuest() async {
    final rows = _guestRows();
    if (rows.isEmpty || _signedIn) return;
    final catalog = ref.read(catalogRepositoryProvider);
    final branch = ref.read(preferredBranchIdProvider);
    final ids = rows.map((r) => r.productId).toSet().take(12);
    final loaded = await Future.wait([
      for (final id in ids)
        catalog.product(id, branchId: branch).then<ProductDetail?>((p) => p).catchError((Object _) => null),
    ]);
    if (!ref.mounted || _signedIn) return;
    final products = {for (final p in loaded.whereType<ProductDetail>()) p.summary.id: p};
    // Quantities changed while the pages were loading are kept: refresh the rows as they are NOW.
    final result = GuestCart.refresh(_guestRows(), products);
    _saveGuest(result.rows);
    ref.read(guestCartNoticeProvider.notifier).report(result.changes);
  }

  /// Right after sign-in: the guest's lines are folded into the account cart, the phone's copy is cleared, and anything that is not as they
  /// left it is told once. A failure to add one line never stops the others (that line is reported).
  Future<Cart> _mergeGuest(Cart account) async {
    final rows = _guestRows();
    if (rows.isEmpty) return account;
    var cart = account;
    final refused = <String>{};
    for (final step in GuestMerge.plan(account, rows)) {
      try {
        if (step.addQuantity > 0) {
          cart = await _repo.addItem(step.variantId, step.addQuantity, assembly: step.assembly) ?? cart;
        } else if (step.assemblyItemId != null) {
          cart = await _repo.setAssembly(step.assemblyItemId!, true) ?? cart;
        }
      } on ApiException {
        refused.add(step.variantId);
      }
    }
    // The guest's copy goes only once every line has been tried: closing the app midway never loses it (the same plan, run again, adds nothing twice).
    await ref.read(appPreferencesProvider).setGuestCart(const []);
    final merged = await _repo.fetch(branchId: ref.read(preferredBranchIdProvider));
    ref.read(guestCartNoticeProvider.notifier).report(GuestMerge.report(merged, rows, refused: refused));

    return merged;
  }

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
    bool assembly = false,
  }) async {
    if (!_signedIn) {
      if (product == null) throw StateError('A guest cart needs the product to remember.');
      final row = GuestCartRow.of(
        variantId: variantId,
        product: product,
        quantity: quantity,
        unitPrice: unitPrice,
        variantName: variantName,
        assembly: assembly,
      );

      return _saveGuest(GuestCart.add(_guestRows(), row, quantity));
    }
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
                    assembly: product.assemblyFee == null
                        ? null
                        : CartAssembly(selected: assembly, feeEach: product.assemblyFee!),
                  ),
                ],
          itemsCount: previous.itemsCount + quantity,
        ),
      );
    }
    _adding++;
    try {
      final server = await _repo.addItem(variantId, quantity, assembly: assembly);
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
    dealSavings: c.dealSavings,
    assemblyTotal: c.assemblyTotal,
  );

  /// "Add assembly" / "Remove assembly" on a line. Shown at once; the server's cart (with the assembly total it worked out) replaces it.
  Future<void> setAssembly(String itemId, bool on) async {
    if (!_signedIn) {
      _saveGuest(GuestCart.setAssembly(_guestRows(), _guestVariant(itemId), on));
      return;
    }
    final previous = state.value;
    final busy = ref.read(cartBusyItemsProvider.notifier);
    busy.add(itemId);
    if (previous != null) {
      state = AsyncData(
        _copy(
          previous,
          items: [
            for (final i in previous.items)
              i.id == itemId && i.assembly != null ? i.withAssembly(i.assembly!.withSelected(on)) : i,
          ],
          itemsCount: previous.itemsCount,
        ),
      );
    }
    try {
      await _apply(await _repo.setAssembly(itemId, on));
    } catch (_) {
      if (previous != null && ref.mounted) state = AsyncData(previous);
      rethrow;
    } finally {
      busy.remove(itemId);
    }
  }

  String _guestVariant(String itemId) =>
      itemId.startsWith(_guestPrefix) ? itemId.substring(_guestPrefix.length) : itemId;

  Future<void> updateQuantity(String itemId, int quantity) async {
    if (!_signedIn) {
      _saveGuest(GuestCart.setQuantity(_guestRows(), _guestVariant(itemId), quantity));
      return;
    }
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
    if (!_signedIn) {
      _saveGuest(GuestCart.remove(_guestRows(), _guestVariant(itemId)));
      return;
    }
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

  /// Re-validates prices / stock with the server (e.g. before checkout). A guest's cart is read again from the shop's public product pages.
  Future<Cart> revalidate() async {
    if (_signedIn) return _apply(null);
    await refreshGuest();

    return state.value ?? Cart.empty;
  }

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
