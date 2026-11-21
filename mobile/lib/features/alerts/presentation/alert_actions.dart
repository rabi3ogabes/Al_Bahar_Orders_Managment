import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/alerts/domain/alert_models.dart';
import 'package:albahar_customer/features/alerts/presentation/state/alerts_controller.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Asks for an alert, or cancels it when the customer already has it (the bell and the price choice are toggles). A signed-out visitor is taken to
/// sign in and what they asked for is remembered ([resumeAlertIntent] does it when they are back). Failures are shown in the server's own words.
Future<void> toggleAlert(
  BuildContext context,
  WidgetRef ref, {
  required String variantId,
  required AlertType type,
}) async {
  if (!ref.read(isAuthenticatedProvider)) {
    ref.read(pendingAlertIntentProvider.notifier).set(AlertIntent(variantId, type));
    requireAuth(context, ref);
    return;
  }
  final mine = ref.read(alertsControllerProvider.notifier).find(variantId, type);
  await _apply(context, ref, variantId, type, subscribed: mine != null);
}

/// Back from sign-in: does what the visitor asked before the sign-in screen opened (only when it is about [variantId] of this page, and only soon enough).
Future<void> resumeAlertIntent(BuildContext context, WidgetRef ref, {required String? variantId}) async {
  final intent = ref.read(pendingAlertIntentProvider);
  if (intent == null || intent.variantId != variantId) return;
  ref.read(pendingAlertIntentProvider.notifier).set(null);
  if (intent.isStale()) return;
  await _apply(context, ref, intent.variantId, intent.type, subscribed: false);
}

Future<void> _apply(
  BuildContext context,
  WidgetRef ref,
  String variantId,
  AlertType type, {
  required bool subscribed,
}) async {
  final l10n = context.l10n;
  final controller = ref.read(alertsControllerProvider.notifier);
  try {
    if (subscribed) {
      final mine = controller.find(variantId, type);
      if (mine != null) await controller.remove(mine);
      if (context.mounted) showInfoSnack(context, l10n.alertRemoved);
    } else {
      await controller.subscribe(variantId, type, branchId: ref.read(preferredBranchIdProvider));
      Haptics.success();
      if (context.mounted) {
        showInfoSnack(context, type == AlertType.backInStock ? l10n.alertCreated : l10n.alertPriceCreated);
      }
    }
  } catch (e) {
    if (context.mounted) showErrorSnack(context, e);
  }
}
