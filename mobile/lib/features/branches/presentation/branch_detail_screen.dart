import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/branch_widgets.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

class BranchDetailScreen extends ConsumerWidget {
  const BranchDetailScreen({super.key, required this.branchId, this.embedded = false});
  final String branchId;

  /// True inside a side window (a step of the branch picker): no app bar.
  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final branch = ref.watch(branchDetailProvider(branchId));
    final selectedId = ref.watch(preferredBranchIdProvider);
    final body = branch.when(
      loading: () => const ListSkeleton(count: 4, leading: false),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(branchDetailProvider(branchId))),
      data: (b) => ListView(
        children: [
          if (b.hasLocation)
            SizedBox(
              height: 200,
              child: BranchMap(branches: [b], selectedId: b.id, onSelect: (_) {}, interactive: false),
            ),
          ContentWidth(
            child: Padding(
              padding: const EdgeInsets.all(AppSpace.gutter),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(b.name, style: context.text.headlineMedium),
                  const Gap(AppSpace.xs),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: OpeningChip(opening: b.opening),
                  ),
                  if (b.address != null) ...[const Gap(AppSpace.sm), Text(b.address!, style: context.text.bodyMedium)],
                  if (b.description != null) ...[
                    const Gap(AppSpace.sm),
                    Text(b.description!, style: context.text.bodyMedium?.copyWith(color: context.palette.muted)),
                  ],
                  const Gap(AppSpace.md),
                  Row(
                    children: [
                      if (b.phone != null)
                        Expanded(
                          child: AppButton.secondary(
                            label: l10n.call,
                            icon: Icons.call_outlined,
                            onPressed: () => launchUrl(Uri(scheme: 'tel', path: b.phone)),
                          ),
                        ),
                      if (b.phone != null && b.hasLocation) const Gap(AppSpace.sm),
                      if (b.hasLocation)
                        Expanded(
                          child: AppButton.secondary(
                            label: l10n.directions,
                            icon: Icons.directions_outlined,
                            onPressed: () => launchUrl(
                              Uri.parse(
                                'https://www.google.com/maps/dir/?api=1&destination=${b.latitude},${b.longitude}',
                              ),
                              mode: LaunchMode.externalApplication,
                            ),
                          ),
                        ),
                    ],
                  ),
                  const Gap(AppSpace.lg),
                  Text(l10n.branchServices, style: context.text.titleMedium),
                  const Gap(AppSpace.xs),
                  Wrap(
                    spacing: AppSpace.xs,
                    runSpacing: AppSpace.xs,
                    children: [
                      if (b.pickupEnabled) StatusChip(label: l10n.pickupAvailable, icon: Icons.shopping_bag_outlined),
                      if (b.deliveryEnabled)
                        StatusChip(label: l10n.deliveryAvailable, icon: Icons.local_shipping_outlined),
                      if (b.codEnabled) StatusChip(label: l10n.serviceCod, icon: Icons.payments_outlined),
                      if (b.payAtPickupEnabled)
                        StatusChip(label: l10n.servicePayAtPickup, icon: Icons.point_of_sale_outlined),
                    ],
                  ),
                  const Gap(AppSpace.lg),
                  _HoursSection(branchId: b.id),
                  const Gap(AppSpace.xl),
                  if (selectedId == b.id)
                    StatusChip(label: l10n.selectedBranch, tone: StatusTone.accent, icon: Icons.check_rounded)
                  else
                    AppButton(
                      label: l10n.selectThisBranch,
                      onPressed: () async {
                        Haptics.success();
                        await ref.read(preferredBranchProvider.notifier).select(b.ref);
                        if (context.mounted) showInfoSnack(context, l10n.branchSelectedToast(b.name));
                      },
                    ),
                  const Gap(AppSpace.sm),
                  Text(l10n.branchChangeNote, style: context.text.bodySmall),
                ],
              ),
            ),
          ),
        ],
      ),
    );
    return embedded
        ? body
        : Scaffold(
            appBar: AppBar(title: Text(branch.value?.name ?? l10n.branchesTitle)),
            body: body,
          );
  }
}

class _HoursSection extends ConsumerWidget {
  const _HoursSection({required this.branchId});
  final String branchId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final locale = context.localeCode;
    final hours = ref.watch(branchHoursProvider(branchId));
    final today = QatarTime.today().weekday % 7; // DateTime: Mon=1..Sun=7 → 0=Sun
    // A known Sunday to derive localised weekday names.
    String dayName(int dow) => DateFormat.EEEE(locale).format(DateTime.utc(2024, 1, 7 + dow));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(l10n.branchHours, style: context.text.titleMedium),
        const Gap(AppSpace.xs),
        hours.when(
          loading: () => const Skeleton(height: 140, radius: AppRadius.card),
          error: (e, _) =>
              ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(branchHoursProvider(branchId))),
          data: (h) => SurfaceCard(
            child: Column(
              children: [
                for (final WeeklyHours d in h.weekly)
                  DefaultTextStyle.merge(
                    style: d.dayOfWeek == today ? const TextStyle(fontWeight: FontWeight.w700) : null,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(child: Text(dayName(d.dayOfWeek))),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Text(
                                d.isClosed
                                    ? l10n.closed
                                    : '${QatarTime.clock(d.opensAt, locale)} – ${QatarTime.clock(d.closesAt, locale)}',
                              ),
                              if (!d.isClosed && d.breakStartsAt != null && d.breakEndsAt != null)
                                Text(
                                  l10n.breakTime(
                                    QatarTime.clock(d.breakStartsAt, locale),
                                    QatarTime.clock(d.breakEndsAt, locale),
                                  ),
                                  style: context.text.bodySmall,
                                ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                if (h.holidays.isNotEmpty) ...[
                  Divider(color: context.palette.line),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(l10n.holidays, style: context.text.titleSmall),
                  ),
                  for (final hol in h.holidays)
                    SummaryRow(
                      label: hol.name,
                      value: [
                        QatarTime.date(hol.startsAt, locale),
                        QatarTime.date(hol.endsAt, locale),
                      ].where((s) => s.isNotEmpty).toSet().join(' – '),
                    ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
