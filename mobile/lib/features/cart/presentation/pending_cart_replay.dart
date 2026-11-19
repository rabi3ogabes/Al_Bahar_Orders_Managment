import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/cart/presentation/cart_panel.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/cart/presentation/state/pending_cart_add.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// A guest who tapped "Add to cart" was sent to sign in. Once they are signed in (the cart has been read for them) and the sign-in
/// screens have gone (they return to the page they were on), the add is done for them with the usual confirmation: the cart window opens
/// over that page. Started by the cart itself (`CartController.build`), which is alive for the whole run of the app, so no screen hosts it.
Future<void> replayPendingCartAdd(Ref ref, CartController cart) async {
  final pending = ref.read(pendingCartAddProvider.notifier).take();
  if (pending == null) return;
  // The sign-in screens close by themselves; the add waits until the page the person came from is showing again.
  for (var i = 0; i < 40; i++) {
    final context = rootNavigatorKey.currentContext;
    if (!ref.mounted || context == null || !context.mounted) return;
    if (!currentAppLocation(GoRouter.of(context)).startsWith('/login')) break;
    await Future<void>.delayed(const Duration(milliseconds: 150));
  }
  await Future<void>.delayed(const Duration(milliseconds: 300));
  final context = rootNavigatorKey.currentContext;
  if (!ref.mounted || context == null || !context.mounted || !ref.read(isAuthenticatedProvider)) return;
  await performAddToCart(context, cart, pending.additions);
}
