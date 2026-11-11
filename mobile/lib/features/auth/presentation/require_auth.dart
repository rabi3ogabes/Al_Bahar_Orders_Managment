import 'dart:async';

import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Returns true when signed in; otherwise opens sign-in (returning here).
bool requireAuth(BuildContext context, WidgetRef ref) {
  if (ref.read(isAuthenticatedProvider)) return true;
  final here = GoRouterState.of(context).uri.toString();
  unawaited(context.push('/login?from=${Uri.encodeComponent(here)}'));
  return false;
}
