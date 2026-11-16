import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../l10n/l10n.dart';
import '../push/push_service.dart';
import '../theme/tokens.dart';
import '../widgets/app_button.dart';
import '../widgets/common.dart';
import 'live_order_controller.dart';
import 'live_order_platform.dart';
import 'notification_permission.dart';

/// Tells the customer, at the top of the notification settings, that this phone will not show alerts (notifications are off, or the
/// question was never asked), with the one button that fixes it: the system question while it can still be shown, else the phone's
/// notification settings. Nothing is drawn while notifications are on, or while the phone has not answered yet.
///
/// The standing is read again whenever the app comes back to the screen: the person may have changed it in the phone's settings.
class NotificationStatusCard extends ConsumerStatefulWidget {
  const NotificationStatusCard({super.key, this.onAnswered});

  /// Told after the system answered; by default the push service tells the server the new state.
  final Future<void> Function()? onAnswered;

  @override
  ConsumerState<NotificationStatusCard> createState() => _NotificationStatusCardState();
}

class _NotificationStatusCardState extends ConsumerState<NotificationStatusCard> with WidgetsBindingObserver {
  /// Null until the phone has answered (or when it cannot): nothing is shown then.
  LiveOrderPermission? _standing;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_refresh());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(_refresh());
  }

  Future<void> _refresh() async {
    try {
      final standing = await ref.read(liveOrderPlatformProvider).permission();
      if (mounted) setState(() => _standing = standing);
    } catch (_) {
      // The phone cannot say: the card stays as it is.
    }
  }

  Future<void> _turnOn() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await turnOnNotifications(
        platform: ref.read(liveOrderPlatformProvider),
        controller: ref.read(liveOrderControllerProvider),
        onAnswered: widget.onAnswered ?? ref.read(pushServiceProvider).onPermissionChanged,
      );
    } catch (_) {
      // Never throws into the screen; the standing below says what is true now.
    }
    await _refresh();
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final standing = _standing;
    final off = standing != null && standing != LiveOrderPermission.granted;
    final instant = MediaQuery.disableAnimationsOf(context);
    return AnimatedSize(
      duration: instant ? Duration.zero : const Duration(milliseconds: 220),
      curve: Curves.easeOutCubic,
      alignment: AlignmentDirectional.topCenter,
      child: off
          ? _card(context, blocked: standing == LiveOrderPermission.blocked)
          : const SizedBox(width: double.infinity),
    );
  }

  Widget _card(BuildContext context, {required bool blocked}) {
    final l10n = context.l10n;
    final p = context.palette;
    return Semantics(
      container: true,
      child: Container(
        margin: const EdgeInsets.only(bottom: AppSpace.md),
        padding: const EdgeInsets.all(AppSpace.md),
        decoration: BoxDecoration(
          color: p.tint,
          borderRadius: AppRadius.cardAll,
          border: Border.all(color: p.brass.withValues(alpha: 0.28)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ExcludeSemantics(
                  child: Container(
                    width: 36,
                    height: 36,
                    decoration: BoxDecoration(shape: BoxShape.circle, color: p.brass.withValues(alpha: 0.16)),
                    child: Icon(Icons.notifications_off_outlined, size: 20, color: p.brassDeep),
                  ),
                ),
                const Gap(AppSpace.sm),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Semantics(
                      header: true,
                      child: Text(
                        l10n.notificationStatusTitle,
                        style: context.text.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const Gap(AppSpace.xs),
            Text(
              blocked ? l10n.notificationStatusBlocked : l10n.notificationStatusNotAsked,
              style: context.text.bodyMedium?.copyWith(color: context.colors.onSurfaceVariant),
            ),
            const Gap(AppSpace.md),
            AppButton(
              label: blocked ? l10n.openSettings : l10n.notificationStatusAllow,
              icon: blocked ? Icons.settings_rounded : Icons.notifications_active_rounded,
              loading: _busy,
              onPressed: _busy ? null : () => unawaited(_turnOn()),
            ),
          ],
        ),
      ),
    );
  }
}
