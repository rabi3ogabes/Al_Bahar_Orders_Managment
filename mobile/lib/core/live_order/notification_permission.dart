import 'dart:async';

import 'package:flutter/material.dart';

import '../l10n/l10n.dart';
import '../storage/app_preferences.dart';
import '../theme/tokens.dart';
import '../widgets/app_button.dart';
import '../widgets/common.dart';
import 'live_order_controller.dart';
import 'live_order_platform.dart';

/// What the customer is asked, and when. The system dialog is used up quickly (two refusals and Android stops showing
/// it), so it is never shown cold: a friendly sheet first, at the first order, and rarely again.
enum NotificationPromptKind {
  /// Nothing to ask (already on, or this platform has no permission of its own).
  none,

  /// Explain, then show the system dialog.
  ask,

  /// The system dialog is gone for good (or notifications are off): explain, then open the settings.
  settings,
}

/// Pure decision, unit-tested. [lastAt] and [count] are the previous prompts; after a "not now" the sheet waits two
/// weeks, three times at most; the settings variant is shown at most twice, a month apart.
NotificationPromptKind notificationPromptFor({
  required LiveOrderPermission permission,
  required DateTime? lastAt,
  required int count,
  required DateTime now,
}) {
  if (permission == LiveOrderPermission.granted) return NotificationPromptKind.none;
  final since = lastAt == null ? null : now.difference(lastAt);
  if (permission == LiveOrderPermission.denied) {
    if (count == 0) return NotificationPromptKind.ask;
    return count < 3 && since != null && since >= const Duration(days: 14)
        ? NotificationPromptKind.ask
        : NotificationPromptKind.none;
  }
  if (count == 0) return NotificationPromptKind.settings;
  return count < 2 && since != null && since >= const Duration(days: 30)
      ? NotificationPromptKind.settings
      : NotificationPromptKind.none;
}

/// Asks for notification permission with a rationale, once there is a reason to: right after an order is placed.
/// Never throws and never blocks the order flow; returns true when notifications are on afterwards.
Future<bool> promptForNotificationsIfNeeded(
  BuildContext context, {
  required LiveOrderPlatform platform,
  required AppPreferences prefs,
  required LiveOrderController controller,
  DateTime Function()? now,
}) async {
  try {
    final permission = await platform.permission();
    final previous = prefs.livePermissionPrompt;
    final at = (now ?? DateTime.now)();
    final kind = notificationPromptFor(permission: permission, lastAt: previous.at, count: previous.count, now: at);
    if (kind == NotificationPromptKind.none || !context.mounted) return permission == LiveOrderPermission.granted;

    await prefs.setLivePermissionPrompt(at, previous.count + 1);
    if (!context.mounted) return false;
    final accepted = await showAppBottomSheet<bool>(
      context: context,
      builder: (_) => NotificationPermissionSheet(settings: kind == NotificationPromptKind.settings),
    );
    if (accepted != true) return false;
    if (kind == NotificationPromptKind.settings) {
      await platform.openSettings();
      return false;
    }
    final after = await platform.requestPermission();
    if (after == LiveOrderPermission.granted) await controller.onPermissionGranted();
    return after == LiveOrderPermission.granted;
  } catch (_) {
    return false;
  }
}

/// The same, once the frame that shows the order page is done (the order flow has just navigated away from checkout).
void promptForNotificationsAfterOrder(
  BuildContext context, {
  required LiveOrderPlatform platform,
  required AppPreferences prefs,
  required LiveOrderController controller,
}) {
  WidgetsBinding.instance.addPostFrameCallback((_) {
    if (!context.mounted) return;
    unawaited(promptForNotificationsIfNeeded(context, platform: platform, prefs: prefs, controller: controller));
  });
}

/// The friendly explanation shown before the system dialog (or before sending the customer to the settings).
class NotificationPermissionSheet extends StatelessWidget {
  const NotificationPermissionSheet({super.key, required this.settings});

  /// True when the system dialog cannot be shown any more: the button opens the settings instead.
  final bool settings;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final primary = context.colors.primary;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Gap(AppSpace.xs),
        Center(
          child: ExcludeSemantics(
            child: CircleAvatar(
              radius: 32,
              backgroundColor: primary.withValues(alpha: 0.12),
              child: Icon(Icons.notifications_active_rounded, size: 32, color: primary),
            ),
          ),
        ),
        const Gap(AppSpace.md),
        Semantics(
          header: true,
          child: Text(
            settings ? l10n.liveOrderPermissionBlockedTitle : l10n.liveOrderPermissionTitle,
            textAlign: TextAlign.center,
            style: context.text.headlineSmall,
          ),
        ),
        const Gap(AppSpace.xs),
        Text(
          settings ? l10n.liveOrderPermissionBlockedBody : l10n.liveOrderPermissionBody,
          textAlign: TextAlign.center,
          style: context.text.bodyMedium?.copyWith(color: context.colors.onSurfaceVariant),
        ),
        const Gap(AppSpace.lg),
        AppButton(
          label: settings ? l10n.openSettings : l10n.liveOrderPermissionAllow,
          icon: settings ? Icons.settings_rounded : Icons.notifications_rounded,
          onPressed: () => Navigator.of(context).pop(true),
        ),
        const Gap(AppSpace.xs),
        AppButton.ghost(
          label: l10n.liveOrderPermissionLater,
          expand: true,
          onPressed: () => Navigator.of(context).pop(false),
        ),
      ],
    );
  }
}
