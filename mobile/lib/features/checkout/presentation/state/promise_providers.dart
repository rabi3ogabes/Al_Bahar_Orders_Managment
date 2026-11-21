import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/checkout/data/promise_repository.dart';
import 'package:albahar_customer/features/checkout/domain/delivery_promise.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What the product page asks: this variant, this many, with or without assembly, for delivery or for pickup.
typedef ProductPromiseQuery = ({String productId, String? variantId, int quantity, bool assembly, String type});

/// The date promised for one product on its page, from the customer's showroom. A busy server or no network is simply no answer (null): the page
/// shows nothing and buying is never blocked.
final productPromiseProvider = FutureProvider.autoDispose.family<BasketPromise?, ProductPromiseQuery>((ref, q) async {
  ref.watch(localeCodeProvider);
  final branchId = ref.watch(preferredBranchIdProvider);
  if (q.variantId == null) return null;
  try {
    return await ref
        .read(promiseRepositoryProvider)
        .product(
          q.productId,
          variantId: q.variantId,
          quantity: q.quantity,
          assembly: q.assembly,
          branchId: branchId,
          type: q.type,
        );
  } on ApiException {
    return null;
  }
});

/// The date promised for the whole cart (a guest's or an account's): one date per parcel and an overall "all by". It asks again only when the
/// lines, the showroom or the language change.
final cartPromiseProvider = FutureProvider.autoDispose<BasketPromise?>((ref) async {
  ref.watch(localeCodeProvider);
  final branchId = ref.watch(preferredBranchIdProvider);
  final signedIn = ref.watch(isAuthenticatedProvider);
  // The lines as one text, so an unrelated cart change (a coupon, a warning) never asks again.
  ref.watch(
    cartControllerProvider.select(
      (c) =>
          c.value?.items.map((i) => '${i.variantId}:${i.quantity}:${i.assembly?.selected == true ? 1 : 0}').join('|') ??
          '',
    ),
  );
  final cart = ref.read(cartControllerProvider).value;
  if (cart == null || cart.items.isEmpty) return null;
  final addressId = signedIn ? (cart.addressId ?? ref.watch(defaultAddressProvider)?.id) : null;
  try {
    return await ref
        .read(promiseRepositoryProvider)
        .basket(
          [
            for (final i in cart.items)
              (variantId: i.variantId, quantity: i.quantity, assembly: i.assembly?.selected ?? false),
          ],
          type: cart.fulfillmentType == 'pickup' ? 'pickup' : 'delivery',
          branchId: branchId,
          addressId: addressId,
        );
  } on ApiException {
    return null;
  }
});
