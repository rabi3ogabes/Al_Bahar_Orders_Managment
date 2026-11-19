import 'package:albahar_customer/features/cart/domain/cart_addition.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What a guest wanted to add to the cart when they were sent to sign in. It is done for them right after they sign in (see
/// `AlBaharApp`), so signing in never costs them the tap. It lives only a few minutes and is dropped when they carry on as a guest or
/// leave the sign-in screen, so a later, unrelated sign-in never puts something in their cart they had forgotten.
class PendingCartAdd {
  PendingCartAdd(this.additions, {DateTime? at}) : at = at ?? DateTime.now();
  final List<CartAddition> additions;
  final DateTime at;

  static const lifetime = Duration(minutes: 10);

  bool isFresh([DateTime? now]) => (now ?? DateTime.now()).difference(at) < lifetime;
}

class PendingCartAddController extends Notifier<PendingCartAdd?> {
  @override
  PendingCartAdd? build() => null;

  /// The latest add wins: a guest who taps "Add" twice on different things was only ever going to sign in once.
  void remember(List<CartAddition> additions) => state = additions.isEmpty ? null : PendingCartAdd(additions);

  void clear() => state = null;

  /// Hands the add over (once) when it is still fresh.
  PendingCartAdd? take() {
    final pending = state;
    state = null;
    return pending != null && pending.isFresh() ? pending : null;
  }
}

final pendingCartAddProvider = NotifierProvider<PendingCartAddController, PendingCartAdd?>(
  PendingCartAddController.new,
);
