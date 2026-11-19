import 'dart:async';

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_clock_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_rights.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The compact "Needs attention (N)" strip at the top of the overview, for a manager (or anyone whose list is not empty): one tap opens the list.
/// Red when anything is late, amber when anything is getting late, calm otherwise. Before the list is read, or when it cannot be, the strip is
/// simply not there: it never gets in the way of the orders.
class AttentionStrip extends ConsumerWidget {
  const AttentionStrip({super.key, required this.onOpen});

  /// Takes the person to what a line asks for (the order's window, the driver list, a new pass); called after the list has closed.
  final ValueChanged<AttentionItem> onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summary = ref.watch(staffAttentionProvider).value;
    final manager = ref.watch(staffRightsProvider.select((r) => r.canDecideCancellation));
    if (summary == null || (summary.total == 0 && !manager)) return const SizedBox.shrink();
    final t = OpsStrings.of(context);
    final p = context.palette;
    final state = summary.worst;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final (bg, fg, icon) = switch (state) {
      OrderClockState.red => (
        dark ? p.danger.withValues(alpha: 0.2) : AppPalette.dangerTint,
        p.danger,
        Icons.warning_amber_rounded,
      ),
      OrderClockState.amber => (
        dark ? p.warning.withValues(alpha: 0.18) : AppPalette.warningTint,
        p.warning,
        Icons.schedule_rounded,
      ),
      OrderClockState.ok => (
        dark ? p.success.withValues(alpha: 0.16) : AppPalette.successTint,
        p.success,
        summary.total == 0 ? Icons.check_circle_outline_rounded : Icons.notifications_active_outlined,
      ),
    };
    final detail = t.attentionSummary(summary.red, summary.amber);
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, 0),
      child: Semantics(
        button: true,
        label: [t.attentionStrip(summary.total), if (detail.isNotEmpty) detail].join(t.ar ? '، ' : ', '),
        excludeSemantics: true,
        child: Material(
          color: bg,
          borderRadius: AppRadius.inputAll,
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            key: const ValueKey('attention-strip'),
            onTap: () => unawaited(_open(context, ref)),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: kMinTouchTarget),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
                child: Row(
                  children: [
                    Icon(icon, size: 20, color: fg),
                    const Gap(AppSpace.xs),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            t.attentionStrip(summary.total),
                            style: context.text.titleSmall?.copyWith(color: fg, fontWeight: FontWeight.w700),
                          ),
                          if (detail.isNotEmpty) Text(detail, style: context.text.bodySmall?.copyWith(color: p.ink)),
                        ],
                      ),
                    ),
                    Icon(Icons.chevron_right_rounded, color: fg),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _open(BuildContext context, WidgetRef ref) async {
    // Fresh numbers when it opens (the strip is read again every couple of minutes and whenever an order moves).
    ref.invalidate(staffAttentionProvider);
    final item = await showAttentionSheet(context);
    if (item != null && context.mounted) onOpen(item);
  }
}

/// The list itself, in a window: every line says what it is, where, how long it has waited and takes one tap to the right place.
/// Resolves to the line the person chose, or null.
Future<AttentionItem?> showAttentionSheet(BuildContext context) => showSidePanel<AttentionItem>(
  context: context,
  title: OpsStrings.of(context).attentionTitle,
  scrollable: false,
  builder: (_) => const _AttentionList(),
);

class _AttentionList extends ConsumerWidget {
  const _AttentionList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final async = ref.watch(staffAttentionProvider);
    return async.when(
      skipLoadingOnRefresh: true,
      loading: () => const Padding(
        padding: EdgeInsets.all(AppSpace.xl),
        child: Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffAttentionProvider), compact: true),
      data: (summary) {
        if (summary.items.isEmpty) {
          return Padding(
            padding: const EdgeInsets.all(AppSpace.lg),
            child: EmptyState(icon: Icons.task_alt_rounded, title: t.attentionAllClear, compact: true),
          );
        }
        return ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.fromLTRB(AppSpace.md, 0, AppSpace.md, AppSpace.md),
          children: [
            for (final item in summary.items) ...[_AttentionTile(item: item), const Gap(AppSpace.xs)],
            if (summary.total > summary.items.length || summary.truncated)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
                child: Text(t.attentionMore, style: context.text.bodySmall?.copyWith(color: context.palette.muted)),
              ),
          ],
        );
      },
    );
  }
}

class _AttentionTile extends StatelessWidget {
  const _AttentionTile({required this.item});
  final AttentionItem item;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final action = switch (item.target) {
      AttentionTarget.openOrder => t.attentionOpenOrder,
      AttentionTarget.assignDriver => t.attentionAssign,
      AttentionTarget.reissuePass => t.attentionReissue,
      AttentionTarget.dashboard => null,
    };
    final dark = Theme.of(context).brightness == Brightness.dark;
    final tint = switch (item.state) {
      OrderClockState.red => dark ? p.danger.withValues(alpha: 0.12) : AppPalette.dangerTint.withValues(alpha: 0.55),
      OrderClockState.amber =>
        dark ? p.warning.withValues(alpha: 0.12) : AppPalette.warningTint.withValues(alpha: 0.55),
      OrderClockState.ok => p.surfaceMuted,
    };
    final subject = item.subjectIsNumber ? '#${item.subject}' : item.subject;
    return Semantics(
      container: true,
      label: [
        item.label,
        subject,
        item.detail,
        t.clockSpeech(item.state, item.ageMinutes),
        if (action == null) item.hint ?? t.attentionOnDashboard,
      ].join('. '),
      excludeSemantics: true,
      child: Material(
        key: ValueKey('attention-${item.id}'),
        color: tint,
        borderRadius: BorderRadius.circular(AppRadius.sm),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: action == null ? null : () => Navigator.of(context).pop(item),
          child: Padding(
            padding: const EdgeInsets.all(AppSpace.sm),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // What it is and how long it has waited: side by side, or one under the other when the words are long (large text, Arabic).
                Wrap(
                  spacing: AppSpace.xs,
                  runSpacing: AppSpace.xxs,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(item.label, style: context.text.labelLarge?.copyWith(color: p.muted)),
                    StatusChip(
                      label: t.clockChip(item.state, item.ageMinutes),
                      tone: toneOfClock(item.state),
                      icon: switch (item.state) {
                        OrderClockState.ok => Icons.check_circle_outline_rounded,
                        OrderClockState.amber => Icons.schedule_rounded,
                        OrderClockState.red => Icons.warning_amber_rounded,
                      },
                    ),
                  ],
                ),
                const Gap(2),
                Row(
                  children: [
                    Text(subject, style: context.text.titleMedium),
                    if (item.branchCode != null) ...[
                      const Gap(AppSpace.xs),
                      Text(item.branchCode!, style: context.text.labelMedium?.copyWith(color: p.muted)),
                    ],
                  ],
                ),
                if (item.detail.isNotEmpty) Text(item.detail, style: context.text.bodyMedium),
                if (item.note != null && item.note!.isNotEmpty)
                  Text(item.note!, style: context.text.bodySmall?.copyWith(color: p.muted)),
                const Gap(AppSpace.xs),
                Row(
                  children: [
                    if (action != null)
                      Expanded(
                        child: FilledButton(
                          onPressed: () => Navigator.of(context).pop(item),
                          style: FilledButton.styleFrom(minimumSize: const Size(0, kMinTouchTarget)),
                          child: Text(action, maxLines: 1, overflow: TextOverflow.ellipsis),
                        ),
                      )
                    else
                      Expanded(
                        child: Row(
                          children: [
                            Icon(Icons.desktop_windows_outlined, size: 16, color: p.muted),
                            const Gap(AppSpace.xs),
                            Expanded(
                              child: Text(
                                item.hint ?? t.attentionOnDashboard,
                                style: context.text.bodySmall?.copyWith(color: p.muted),
                              ),
                            ),
                          ],
                        ),
                      ),
                    if (item.callPhone != null) ...[
                      const Gap(AppSpace.xs),
                      _CallButton(phone: item.callPhone!, subject: subject),
                    ],
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _CallButton extends StatelessWidget {
  const _CallButton({required this.phone, required this.subject});
  final String phone;
  final String subject;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return IconButton.outlined(
      tooltip: '${t.call} $subject',
      onPressed: () => unawaited(dial(context, phone)),
      icon: const Icon(Icons.call_rounded),
    );
  }
}
