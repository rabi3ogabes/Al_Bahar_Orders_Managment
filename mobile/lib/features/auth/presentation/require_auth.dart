import 'dart:async';

import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Returns true when signed in; otherwise opens sign-in (returning here).
///
/// Works from a side window too: the page behind the window is where sign-in returns to, and [beforeLeaving] runs first
/// so the window can close itself.
bool requireAuth(BuildContext context, WidgetRef ref, {VoidCallback? beforeLeaving}) {
  if (ref.read(isAuthenticatedProvider)) return true;
  final router = GoRouter.of(context);
  final here = router.routerDelegate.currentConfiguration.uri.toString();
  beforeLeaving?.call();
  unawaited(router.push('/login?from=${Uri.encodeComponent(here)}'));
  return false;
}
