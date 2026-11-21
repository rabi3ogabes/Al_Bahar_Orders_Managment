import 'dart:async';

import 'package:albahar_customer/core/router/app_router.dart' show isProtectedPath;
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Where the person really is, as a router location. A page opened with `push` (a product, an order, a deal) sits on top of the page it
/// was opened from, but the router's own location still says that page below ("/home"); the page on top is what sign-in must return to.
String currentAppLocation(GoRouter router) {
  final config = router.routerDelegate.currentConfiguration;
  final top = config.matches.isEmpty ? null : config.matches.last;
  return (top is ImperativeRouteMatch ? top.matches.uri : config.uri).toString();
}

/// Returns true when signed in; otherwise opens sign-in (returning here: to the page the person is on, not to the home page).
///
/// Works from a side window too: the page behind the window is where sign-in returns to, and [beforeLeaving] runs first
/// so the window can close itself.
bool requireAuth(BuildContext context, WidgetRef ref, {VoidCallback? beforeLeaving}) {
  if (ref.read(isAuthenticatedProvider)) return true;
  final router = GoRouter.of(context);
  final here = currentAppLocation(router);
  beforeLeaving?.call();
  unawaited(router.push('/login?from=${Uri.encodeComponent(here)}'));
  return false;
}

/// After a sign-in: leave the sign-in screens for the page the person came from. A sign-in opened with `push` (Add to cart, a review, a
/// bid) is closed by popping its screens, which shows the page underneath exactly as it was; one the router sent the person to
/// (a protected page) is replaced by [from], as the router's own redirect would. Does nothing when the router already moved on.
///
/// Checkout is where a signed-out customer is asked to sign in: when [from] is a protected page they were on their way to (`/checkout`), they go
/// there now, on top of the page they came from (Back returns to it), instead of being left where they started.
void leaveSignIn(BuildContext context, String? from) {
  final router = GoRouter.of(context);
  for (var i = 0; i < 6 && router.canPop() && currentAppLocation(router).startsWith('/login'); i++) {
    router.pop();
  }
  if (currentAppLocation(router).startsWith('/login')) {
    router.go(from != null && from.startsWith('/') && !from.startsWith('/login') ? from : '/home');
    return;
  }
  final target = from != null && from.startsWith('/') && !from.startsWith('/login') ? Uri.tryParse(from) : null;
  if (target != null && isProtectedPath(target.path) && currentAppLocation(router) != from) unawaited(router.push(from!));
}
