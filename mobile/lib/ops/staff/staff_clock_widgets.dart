import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The order clock of a row as it stands now: the server's age, moved on by the minutes since it was read. A row from an older server that sent
/// no clock is measured from when it was placed (never amber or red); a row with neither has no age.
OrderClock? clockNow(QueueOrder o, DateTime now) => o.clock?.at(now) ?? OrderClock.since(o.placedAt, now);

/// The chip tone of a clock state.
StatusTone toneOfClock(OrderClockState state) => switch (state) {
  OrderClockState.ok => StatusTone.success,
  OrderClockState.amber => StatusTone.warning,
  OrderClockState.red => StatusTone.danger,
};

/// The age of an order as a chip that never relies on colour: its own icon (a tick, a clock, a warning sign) and its words ("Waiting 12 min",
/// "Getting late · 22 min", "Late · 40 min"). It keeps counting while the screen is open.
class OrderAgeChip extends ConsumerWidget {
  const OrderAgeChip({super.key, required this.order});
  final QueueOrder order;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final now = ref.watch(staffTickProvider);
    final clock = clockNow(order, now);
    if (clock == null) return const SizedBox.shrink();
    final t = OpsStrings.of(context);
    final calm = clock.kind == 'none' || clock.kind == 'placed';
    final chip = StatusChip(
      key: ValueKey('age-${order.fulfillmentId}'),
      label: t.clockChip(clock.state, clock.ageMinutes, kind: clock.kind),
      tone: toneOfClock(clock.state),
      icon: switch (clock.state) {
        OrderClockState.ok => calm ? Icons.autorenew_rounded : Icons.check_circle_outline_rounded,
        OrderClockState.amber => Icons.schedule_rounded,
        OrderClockState.red => Icons.warning_amber_rounded,
      },
    );
    return Semantics(
      label: t.clockSpeech(clock.state, clock.ageMinutes, paused: clock.paused, kind: clock.kind),
      excludeSemantics: true,
      child: clock.paused ? Tooltip(message: t.clockPaused, child: chip) : chip,
    );
  }
}

/// The background of a card's header: calm while the wait is fine, amber when it is getting late, red when it is late.
Color? clockHeaderColor(BuildContext context, OrderClockState state) {
  final p = context.palette;
  final dark = Theme.of(context).brightness == Brightness.dark;
  return switch (state) {
    OrderClockState.ok => null,
    OrderClockState.amber => dark ? p.warning.withValues(alpha: 0.18) : AppPalette.warningTint,
    OrderClockState.red => dark ? p.danger.withValues(alpha: 0.2) : AppPalette.dangerTint,
  };
}

/// "The customer asked to cancel. A manager must decide before this order leaves the branch.": the strip on a card and at the top of the order
/// window. [compact] is the card's version (no reason, a single hint line).
class CancellationBanner extends StatelessWidget {
  const CancellationBanner({super.key, required this.request, this.canDecide = false, this.compact = false});
  final CancellationRequest request;
  final bool canDecide;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final reason = request.reason;
    return Semantics(
      container: true,
      liveRegion: !compact,
      label: '${t.cancelBannerTitle}. ${t.cancelHold}',
      excludeSemantics: true,
      child: Container(
        key: const ValueKey('cancellation-banner'),
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpace.sm),
        decoration: BoxDecoration(
          color: dark ? p.danger.withValues(alpha: 0.16) : AppPalette.dangerTint,
          borderRadius: BorderRadius.circular(AppRadius.sm),
          border: Border.all(color: p.danger.withValues(alpha: 0.5)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.front_hand_rounded, size: 20, color: p.danger),
            const Gap(AppSpace.xs),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    t.cancelBannerTitle,
                    style: context.text.titleSmall?.copyWith(color: p.danger, fontWeight: FontWeight.w700),
                  ),
                  const Gap(2),
                  Text(t.cancelHold, style: context.text.bodyMedium?.copyWith(color: p.ink)),
                  if (!compact && reason != null && reason.isNotEmpty) ...[
                    const Gap(AppSpace.xxs),
                    Text(t.cancelReason(reason), style: context.text.bodySmall?.copyWith(color: p.muted)),
                  ],
                  if (compact) ...[
                    const Gap(AppSpace.xxs),
                    Text(
                      canDecide ? t.cancelOpenToDecide : t.cancelAskManager,
                      style: context.text.bodySmall?.copyWith(color: p.muted),
                    ),
                  ] else if (!canDecide) ...[
                    const Gap(AppSpace.xxs),
                    Text(t.cancelAskManager, style: context.text.bodySmall?.copyWith(color: p.muted)),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A button that is switched off for a reason the person can read: the disabled action and the sentence under it, so a blocked step is
/// explained instead of silently doing nothing.
class BlockedAction extends StatelessWidget {
  const BlockedAction({super.key, required this.label, required this.reason, this.icon});
  final String label;
  final String reason;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Semantics(
          button: true,
          enabled: false,
          label: '$label. $reason',
          excludeSemantics: true,
          child: FilledButton.icon(
            onPressed: null,
            icon: Icon(icon ?? Icons.lock_outline_rounded, size: 18),
            label: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
          ),
        ),
        const Gap(AppSpace.xxs),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.lock_outline_rounded, size: 14, color: p.muted),
            const Gap(AppSpace.xxs),
            Expanded(
              child: Text(reason, style: context.text.bodySmall?.copyWith(color: p.muted)),
            ),
          ],
        ),
      ],
    );
  }
}
