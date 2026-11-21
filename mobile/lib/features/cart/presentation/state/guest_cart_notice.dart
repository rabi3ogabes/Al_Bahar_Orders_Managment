import 'package:albahar_customer/features/cart/domain/guest_cart.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What changed behind a customer's cart that they have not been told yet: a price the shop changed, fewer units on the shelf than they wanted,
/// a product that is gone, or something the server would not take when a guest's cart was folded into their account on sign-in. The cart
/// screens show it once, in plain words, and the customer dismisses it.
class GuestCartNotice extends Notifier<List<GuestCartChange>> {
  @override
  List<GuestCartChange> build() => const [];

  void report(List<GuestCartChange> changes) {
    if (changes.isEmpty) return;
    state = [...state, ...changes];
  }

  void clear() => state = const [];
}

final guestCartNoticeProvider = NotifierProvider<GuestCartNotice, List<GuestCartChange>>(GuestCartNotice.new);
