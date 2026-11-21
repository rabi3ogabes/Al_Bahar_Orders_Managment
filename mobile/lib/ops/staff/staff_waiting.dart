import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "Waiting for the customer": a replacement was offered and the customer has not answered. A live clock to the moment their time runs out, and the
/// one thing the branch needs to know: the rest can be prepared, but this part cannot be marked ready until they answer or the time is up.
/// [compact] is the card's version (one line); the order window shows the longer words too.
class WaitingBanner extends ConsumerWidget {
  const WaitingBanner({super.key, required this.waiting, this.compact = false});
  final WaitingForCustomer waiting;
  final bool compact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final now = ref.watch(staffTickProvider);
    final t = OpsStrings.of(context);
    final p = context.palette;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final left = waiting.secondsLeft(now);
    final clock = left == null
        ? null
        : (left <= 0 ? t.waitingTimeUp : t.waitingTimeLeft('${left ~/ 60}:${(left % 60).toString().padLeft(2, '0')}'));
    return Semantics(
      container: true,
      liveRegion: !compact,
      label: '${t.waitingTitle}. ${t.waitingCount(waiting.count)}${clock == null ? '' : '. $clock'}',
      excludeSemantics: true,
      child: Container(
        key: const ValueKey('waiting-for-customer'),
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpace.sm),
        decoration: BoxDecoration(
          color: dark ? p.warning.withValues(alpha: 0.18) : AppPalette.warningTint,
          borderRadius: BorderRadius.circular(AppRadius.sm),
          border: Border.all(color: p.warning.withValues(alpha: 0.5)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.hourglass_top_rounded, size: 18, color: p.warning),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Text(
                    compact && clock != null ? '${t.waitingTitle} · $clock' : t.waitingTitle,
                    style: context.text.titleSmall,
                  ),
                ),
              ],
            ),
            if (!compact) ...[
              const Gap(AppSpace.xxs),
              Text(t.waitingCount(waiting.count), style: context.text.bodyMedium),
              if (clock != null)
                Text(
                  clock,
                  key: const ValueKey('waiting-clock'),
                  style: context.text.bodyMedium?.copyWith(fontWeight: FontWeight.w700),
                ),
              const Gap(AppSpace.xxs),
              Text(t.waitingHint, style: context.text.bodySmall?.copyWith(color: p.muted)),
            ],
          ],
        ),
      ),
    );
  }
}
