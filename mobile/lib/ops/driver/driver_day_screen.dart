import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/driver/driver_models.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "My day": friendly numbers for today (delivered, not delivered, handed back, still to do), the cash the driver holds and whether it is late,
/// and today's jobs with their state. Reached from the home screen's header; it reads from the server when it opens and on pull-to-refresh.
class DriverDayScreen extends ConsumerWidget {
  const DriverDayScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final day = ref.watch(driverDayProvider);
    return Scaffold(
      appBar: AppBar(title: Text(t.myDay)),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () => ref.refresh(driverDayProvider.future),
          child: day.when(
            skipLoadingOnRefresh: true,
            skipError: true,
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (e, _) => ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [const Gap(AppSpace.xxl), ErrorState(error: e, onRetry: () => ref.invalidate(driverDayProvider))],
            ),
            data: (d) => _DayBody(day: d, stale: day.hasError, onRetry: () => ref.invalidate(driverDayProvider)),
          ),
        ),
      ),
    );
  }
}

class _DayBody extends StatelessWidget {
  const _DayBody({required this.day, required this.stale, required this.onRetry});
  final DriverDay day;
  final bool stale;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final lang = Localizations.localeOf(context).languageCode;
    final p = context.palette;
    final since = day.onDutySince;
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.xl),
      children: [
        if (stale) ...[StaleBanner(onRetry: onRetry), const Gap(AppSpace.sm)],
        Row(
          children: [
            Container(
              width: 12,
              height: 12,
              decoration: BoxDecoration(shape: BoxShape.circle, color: day.onDuty ? p.success : p.muted),
            ),
            const Gap(AppSpace.xs),
            Expanded(
              child: Text(
                day.onDuty ? (since != null ? t.onDutySince(QatarTime.time(since, lang)) : t.onDuty) : t.offDuty,
                style: context.text.titleMedium,
              ),
            ),
          ],
        ),
        const Gap(AppSpace.md),
        _Stats(day: day),
        const Gap(AppSpace.md),
        _CashCard(cash: day.cash),
        const Gap(AppSpace.lg),
        Semantics(header: true, child: Text(t.dayJobsTitle, style: context.text.titleMedium)),
        const Gap(AppSpace.xs),
        if (day.jobs.isEmpty)
          EmptyState(icon: Icons.wb_sunny_outlined, title: t.dayEmptyTitle, message: t.dayEmptyHint, compact: true)
        else
          for (final job in day.jobs) ...[_JobRow(job: job), const Gap(AppSpace.xs)],
      ],
    );
  }
}

/// The four numbers of the day, two by two: big, with one plain word each (and an icon, so the meaning is never colour alone).
class _Stats extends StatelessWidget {
  const _Stats({required this.day});
  final DriverDay day;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    Widget tile(String key, IconData icon, Color color, int n, String label) => Expanded(
      child: Semantics(
        container: true,
        label: '$label: $n',
        excludeSemantics: true,
        child: Container(
          key: ValueKey(key),
          padding: const EdgeInsets.all(AppSpace.md),
          decoration: BoxDecoration(color: p.surfaceMuted, borderRadius: BorderRadius.circular(AppRadius.input)),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 22, color: color),
              const Gap(AppSpace.xs),
              Directionality(
                textDirection: TextDirection.ltr,
                child: Text('$n', style: context.text.headlineMedium?.copyWith(fontWeight: FontWeight.w700)),
              ),
              Text(label, style: context.text.bodyMedium),
            ],
          ),
        ),
      ),
    );
    return Column(
      children: [
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              tile('stat-done', Icons.check_circle_outline_rounded, p.success, day.done, t.delivered),
              const Gap(AppSpace.xs),
              tile('stat-failed', Icons.report_gmailerrorred_rounded, p.danger, day.failed, t.dayNotDelivered),
            ],
          ),
        ),
        const Gap(AppSpace.xs),
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              tile('stat-declined', Icons.undo_rounded, p.warning, day.declined, t.dayHandedBack),
              const Gap(AppSpace.xs),
              tile('stat-active', Icons.two_wheeler_rounded, p.info, day.active, t.dayToDo),
            ],
          ),
        ),
      ],
    );
  }
}

/// "Cash with you: QAR 450.00. Hand it in at the branch." Calm while it is fresh; an amber, then red, hint (with its own icon and words) when it
/// has been with the driver too long.
class _CashCard extends StatelessWidget {
  const _CashCard({required this.cash});
  final DriverCash cash;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final lang = Localizations.localeOf(context).languageCode;
    final p = context.palette;
    final (Color bg, Color fg) = switch (cash.state) {
      _ when !cash.holdsCash => (p.surfaceMuted, p.success),
      CashState.amber => (p.warning.withValues(alpha: 0.14), p.warning),
      CashState.red => (p.danger.withValues(alpha: 0.14), p.danger),
      CashState.ok => (p.tint, p.brassDeep),
    };
    final oldest = cash.oldestAt;
    return Semantics(
      container: true,
      child: Container(
        key: const ValueKey('cash-card'),
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpace.md),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.input)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(cash.holdsCash ? Icons.payments_outlined : Icons.check_circle_outline_rounded, color: fg, size: 26),
                const Gap(AppSpace.xs),
                Expanded(
                  child: cash.holdsCash
                      ? Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(t.cashWithYou(cash.held.toStringAsFixed(2)), style: context.text.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
                            const Gap(2),
                            Text(t.handItIn, style: context.text.bodyMedium),
                          ],
                        )
                      : Text(t.cashNone, style: context.text.titleMedium),
                ),
              ],
            ),
            if (cash.holdsCash && cash.state != CashState.ok) ...[
              const Gap(AppSpace.sm),
              Row(
                key: ValueKey('cash-${cash.state.name}'),
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(cash.state == CashState.red ? Icons.error_rounded : Icons.schedule_rounded, size: 20, color: fg),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Text(
                      cash.state == CashState.red ? t.cashRed : t.cashAmber,
                      style: context.text.bodyMedium?.copyWith(color: fg, fontWeight: FontWeight.w700),
                    ),
                  ),
                ],
              ),
            ],
            if (cash.holdsCash && oldest != null) ...[
              const Gap(AppSpace.xs),
              Text(t.cashOldest(QatarTime.dateTime(oldest, lang)), style: context.text.bodySmall?.copyWith(color: p.muted)),
            ],
            if (cash.collectedToday > 0) ...[
              const Gap(AppSpace.xxs),
              Text(t.collectedToday(cash.collectedToday.toStringAsFixed(2)), style: context.text.bodySmall?.copyWith(color: p.muted)),
            ],
          ],
        ),
      ),
    );
  }
}

class _JobRow extends StatelessWidget {
  const _JobRow({required this.job});
  final DayJob job;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final lang = Localizations.localeOf(context).languageCode;
    final place = [if (job.zone != null) '${t.zoneWord} ${job.zone}', if (job.area != null) job.area!].join(' · ');
    return SurfaceCard(
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('#${job.orderNumber}', style: context.text.titleSmall),
                if (job.customerName.isNotEmpty) Text(job.customerName, style: context.text.bodyMedium),
                if (place.isNotEmpty) Text(place, style: context.text.bodySmall?.copyWith(color: context.palette.muted)),
                if (job.at != null) Text(QatarTime.time(job.at, lang), style: context.text.bodySmall?.copyWith(color: context.palette.muted)),
              ],
            ),
          ),
          const Gap(AppSpace.xs),
          StatusChip(label: t.dayStatus(job.status), tone: job.status == 'declined' ? StatusTone.warning : toneForOpsStatus(job.status)),
        ],
      ),
    );
  }
}
