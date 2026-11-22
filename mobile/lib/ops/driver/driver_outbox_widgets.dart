import 'dart:async';

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/driver/driver_outbox.dart';
import 'package:albahar_customer/ops/driver/driver_outbox_provider.dart';
import 'package:albahar_customer/ops/driver/driver_outbox_strings.dart';
import 'package:albahar_customer/ops/ops_read_cache.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "2 updates waiting to send" across the top of the driver's home (never over a job's buttons or the slide at the bottom), with "See updates".
/// Red when the server refused one: the driver has something to decide.
class DriverOutboxBanner extends ConsumerWidget {
  const DriverOutboxBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(driverOutboxProvider);
    if (items.isEmpty) return const SizedBox(width: double.infinity);
    final t = OpsStrings.of(context);
    final p = context.palette;
    final refused = items.where((i) => i.state == OutboxState.refused).length;
    final scheme = Theme.of(context).colorScheme;
    final fg = refused > 0 ? scheme.onErrorContainer : scheme.onSecondaryContainer;
    return Semantics(
      liveRegion: true,
      child: Material(
        key: const ValueKey('outbox-banner'),
        color: refused > 0 ? scheme.errorContainer : p.tint,
        child: InkWell(
          onTap: () => unawaited(showOutboxSheet(context)),
          child: Padding(
            padding: const EdgeInsetsDirectional.only(start: AppSpace.md, end: AppSpace.xs),
            child: Row(
              children: [
                Icon(refused > 0 ? Icons.error_outline_rounded : Icons.outbox_rounded, size: 20, color: fg),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
                    child: Text(
                      refused > 0 ? t.outboxRefused(refused) : t.outboxWaiting(items.length),
                      style: context.text.bodyMedium?.copyWith(color: fg, fontWeight: FontWeight.w600),
                    ),
                  ),
                ),
                TextButton(
                  key: const ValueKey('outbox-open'),
                  style: TextButton.styleFrom(
                    foregroundColor: fg,
                    minimumSize: const Size(kMinTouchTarget, kMinTouchTarget),
                  ),
                  onPressed: () => unawaited(showOutboxSheet(context)),
                  child: Text(t.outboxOpen),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The list of the updates that wait, in the order pressed: the job, what, when, and where each stands. A refused one says why and offers Discard,
/// Try again and Call the branch. "Send now" tries everything at once.
Future<void> showOutboxSheet(BuildContext context) => showSidePanel<void>(
  context: context,
  title: OpsStrings.of(context).outboxTitle,
  scrollable: false,
  builder: (_) => const _OutboxSheet(),
);

class _OutboxSheet extends ConsumerStatefulWidget {
  const _OutboxSheet();

  @override
  ConsumerState<_OutboxSheet> createState() => _OutboxSheetState();
}

class _OutboxSheetState extends ConsumerState<_OutboxSheet> {
  bool _sending = false;

  Future<void> _sendNow() async {
    if (_sending) return;
    setState(() => _sending = true);
    await ref.read(driverOutboxProvider.notifier).flush(force: true);
    if (mounted) setState(() => _sending = false);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final items = ref.watch(driverOutboxProvider);
    final p = context.palette;
    final blocked = <String>{};
    return SidePanelLayout(
      footer: items.isEmpty
          ? null
          : FilledButton.icon(
              key: const ValueKey('outbox-send-now'),
              onPressed: _sending ? null : () => unawaited(_sendNow()),
              icon: _sending
                  ? SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.2,
                        color: Theme.of(context).colorScheme.onPrimary,
                      ),
                    )
                  : const Icon(Icons.send_rounded),
              label: Text(_sending ? t.sendingNow : t.sendNow),
            ),
      child: items.isEmpty
          ? Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpace.xl),
              child: Column(
                children: [
                  Icon(Icons.check_circle_rounded, size: 40, color: p.success),
                  const Gap(AppSpace.sm),
                  Text(t.outboxAllSent, textAlign: TextAlign.center, style: context.text.titleSmall),
                ],
              ),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(t.outboxHint, style: context.text.bodySmall?.copyWith(color: p.muted)),
                const Gap(AppSpace.sm),
                for (final item in items) _OutboxRow(item: item, behind: !blocked.add(item.deliveryId)),
              ],
            ),
    );
  }
}

class _OutboxRow extends ConsumerWidget {
  const _OutboxRow({required this.item, required this.behind});
  final OutboxItem item;

  /// An earlier update of the same job is still waiting: this one goes after it.
  final bool behind;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final refused = item.state == OutboxState.refused;
    final (String line, StatusTone tone) = switch (item.state) {
      OutboxState.refused => (t.notSent, StatusTone.danger),
      OutboxState.signIn => (t.waitingToSend, StatusTone.warning),
      OutboxState.waiting => (t.waitingToSend, StatusTone.warning),
    };
    final outbox = ref.read(driverOutboxProvider.notifier);
    return Container(
      key: ValueKey('outbox-row-${item.key}'),
      margin: const EdgeInsets.only(bottom: AppSpace.xs),
      padding: const EdgeInsets.all(AppSpace.sm),
      decoration: BoxDecoration(
        color: refused ? Theme.of(context).colorScheme.errorContainer.withValues(alpha: 0.35) : null,
        borderRadius: BorderRadius.circular(AppRadius.input),
        border: Border.all(color: refused ? p.danger : Theme.of(context).colorScheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text('#${item.orderNumber} · ${t.outboxStep(item.action)}', style: context.text.titleSmall),
              ),
              StatusChip(label: line, tone: tone, dense: true),
            ],
          ),
          const Gap(2),
          Text(t.pressedAt(item.actedAt), style: context.text.bodySmall?.copyWith(color: p.muted)),
          if (item.state == OutboxState.signIn)
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xxs),
              child: Text(t.signInToSend, style: context.text.bodySmall),
            )
          else if (behind && !refused)
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xxs),
              child: Text(t.waitsForAbove, style: context.text.bodySmall),
            ),
          if (refused) ...[
            const Gap(AppSpace.xxs),
            Text(
              t.refusalText(item),
              style: context.text.bodyMedium?.copyWith(color: p.danger, fontWeight: FontWeight.w600),
            ),
            Text(t.whatToDo, style: context.text.bodySmall),
            const Gap(AppSpace.xxs),
            Wrap(
              spacing: AppSpace.xs,
              runSpacing: AppSpace.xxs,
              children: [
                OutlinedButton.icon(
                  key: ValueKey('outbox-discard-${item.key}'),
                  onPressed: () async {
                    final sure = await opsConfirm(
                      context,
                      title: t.discardUpdateQ,
                      body: t.discardUpdateBody,
                      verb: t.discardUpdate,
                      destructive: true,
                    );
                    if (sure) await outbox.discard(item.key);
                  },
                  icon: const Icon(Icons.delete_outline_rounded, size: 18),
                  label: Text(t.discardUpdate),
                ),
                OutlinedButton.icon(
                  key: ValueKey('outbox-retry-${item.key}'),
                  onPressed: () => unawaited(outbox.retry(item.key)),
                  icon: const Icon(Icons.refresh_rounded, size: 18),
                  label: Text(t.retry),
                ),
                if (item.branchPhone != null)
                  OutlinedButton.icon(
                    key: ValueKey('outbox-call-${item.key}'),
                    onPressed: () => unawaited(dial(context, item.branchPhone)),
                    icon: const Icon(Icons.call_rounded, size: 18),
                    label: Text(t.callTheBranch),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// On a job card and in its window: "Waiting to send" while its steps wait in the outbox, "Not sent" with the reason when the server refused one
/// (a tap opens the list, where the driver decides).
class OutboxJobStrip extends ConsumerWidget {
  const OutboxJobStrip({super.key, required this.deliveryId});
  final String deliveryId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = outboxFor(ref.watch(driverOutboxProvider), deliveryId);
    if (items.isEmpty) return const SizedBox.shrink();
    final t = OpsStrings.of(context);
    final p = context.palette;
    final refused = items.where((i) => i.state == OutboxState.refused).firstOrNull;
    final last = items.last;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpace.xs),
      child: Semantics(
        button: true,
        liveRegion: true,
        child: InkWell(
          key: ValueKey('outbox-job-$deliveryId'),
          borderRadius: BorderRadius.circular(AppRadius.input),
          onTap: () => unawaited(showOutboxSheet(context)),
          child: Container(
            constraints: const BoxConstraints(minHeight: kMinTouchTarget),
            padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
            decoration: BoxDecoration(
              color: refused != null ? Theme.of(context).colorScheme.errorContainer : p.tint,
              borderRadius: BorderRadius.circular(AppRadius.input),
            ),
            child: Row(
              children: [
                Icon(
                  refused != null ? Icons.error_outline_rounded : Icons.schedule_send_rounded,
                  size: 20,
                  color: refused != null ? p.danger : p.warning,
                ),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        refused != null ? t.notSent : t.waitingToSend,
                        style: context.text.labelLarge?.copyWith(fontWeight: FontWeight.w700),
                      ),
                      Text(
                        refused != null
                            ? t.refusalText(refused)
                            : '${t.outboxStep(last.action)} · ${t.pressedAt(last.actedAt)}',
                        style: context.text.bodySmall,
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.chevron_right_rounded, size: 20),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// "Last updated 2:05 PM" over the driver's lists while they are the ones the phone kept (no signal); nothing while they are live.
class DriverLastUpdated extends ConsumerWidget {
  const DriverLastUpdated({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final since = staleSince(ref.watch(opsStaleProvider), 'driver.jobs');
    if (since == null) return const SizedBox.shrink();
    final t = OpsStrings.of(context);
    return Padding(
      key: const ValueKey('driver-last-updated'),
      padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, AppSpace.xs, AppSpace.gutter, 0),
      child: Row(
        children: [
          Icon(Icons.history_rounded, size: 16, color: context.palette.muted),
          const Gap(AppSpace.xxs),
          Expanded(
            child: Text(t.lastUpdated(since), style: context.text.bodySmall?.copyWith(color: context.palette.muted)),
          ),
        ],
      ),
    );
  }
}
