import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/alerts/domain/alert_models.dart';
import 'package:albahar_customer/features/alerts/presentation/state/alerts_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// "My alerts" in a side window: every back-in-stock and price alert of the customer, with what it waits for and a way to remove it.
/// Opened from the account page and from the wishlist.
Future<void> showAlertsPanel(BuildContext context) => showSidePanel<void>(
  context: context,
  title: context.l10n.alertsTitle,
  scrollable: false,
  builder: (_) => const AlertsScreen(),
);

class AlertsScreen extends ConsumerWidget {
  const AlertsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final alerts = ref.watch(alertsControllerProvider);
    return alerts.when(
      skipLoadingOnRefresh: true,
      loading: () => const ListSkeleton(count: 4),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(alertsControllerProvider)),
      data: (items) => items.isEmpty
          ? EmptyState(
              icon: Icons.notifications_none_rounded,
              title: l10n.alertsEmptyTitle,
              message: l10n.alertsEmptyMessage,
              actionLabel: l10n.alertsBrowse,
              onAction: () {
                final router = GoRouter.of(context);
                Navigator.of(context).pop();
                router.go('/home');
              },
            )
          : RefreshIndicator(
              onRefresh: () => ref.refresh(alertsControllerProvider.future),
              child: ListView.separated(
                padding: const EdgeInsets.all(AppSpace.gutter),
                itemCount: items.length,
                separatorBuilder: (_, _) => const Gap(AppSpace.sm),
                itemBuilder: (_, i) => AlertCard(alert: items[i]),
              ),
            ),
    );
  }
}

class AlertCard extends ConsumerWidget {
  const AlertCard({super.key, required this.alert});
  final StockAlert alert;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final palette = context.palette;
    final a = alert;
    final dropped = a.type == AlertType.priceDrop ? a.droppedPercent : null;
    final statusText = switch (a.status) {
      AlertStatus.waitingStock => l10n.alertWaitingStock,
      AlertStatus.availableNow => l10n.alertAvailableNow,
      AlertStatus.watching => l10n.alertWatching,
      AlertStatus.soldOut => l10n.alertSoldOut,
    };
    return Card(
      margin: EdgeInsets.zero,
      child: InkWell(
        borderRadius: AppRadius.cardAll,
        onTap: () {
          Navigator.of(context).pop();
          unawaited(GoRouter.of(context).push('/products/${a.productId}'));
        },
        child: Padding(
          padding: const EdgeInsets.all(AppSpace.sm),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: AppRadius.imageAll,
                child: SizedBox(width: 72, height: 72, child: AppNetworkImage(url: a.imageUrl, memCacheWidth: 216)),
              ),
              const Gap(AppSpace.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(
                          a.type == AlertType.priceDrop
                              ? Icons.trending_down_rounded
                              : Icons.notifications_none_rounded,
                          size: 14,
                          color: palette.brassDeep,
                        ),
                        const Gap(AppSpace.xxs),
                        Flexible(
                          child: Text(
                            a.type == AlertType.priceDrop ? l10n.alertPriceDrop : l10n.alertBackInStock,
                            style: context.text.labelSmall?.copyWith(color: palette.brassDeep),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                    const Gap(AppSpace.xxs),
                    Text(a.productName, style: context.text.titleSmall, maxLines: 2, overflow: TextOverflow.ellipsis),
                    if (a.variantName != null)
                      Text(
                        a.variantName!,
                        style: context.text.bodySmall?.copyWith(color: palette.muted),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    const Gap(AppSpace.xxs),
                    Text(
                      statusText,
                      style: context.text.bodySmall?.copyWith(
                        color: a.status == AlertStatus.availableNow ? palette.success : palette.muted,
                        fontWeight: a.status == AlertStatus.availableNow ? FontWeight.w600 : null,
                      ),
                    ),
                    if (a.type == AlertType.priceDrop && a.currentPrice != null) ...[
                      const Gap(AppSpace.xxs),
                      Text.rich(
                        TextSpan(
                          children: [
                            TextSpan(
                              text: l10n.alertNow(a.currentPrice!.formatted),
                              style: const TextStyle(fontWeight: FontWeight.w600),
                            ),
                            if (a.referencePrice != null)
                              TextSpan(text: ' · ${l10n.alertWas(a.referencePrice!.formatted)}'),
                            if (dropped != null && dropped > 0)
                              TextSpan(
                                text: ' · ${l10n.alertDropped(dropped)}',
                                style: TextStyle(color: palette.success),
                              ),
                          ],
                        ),
                        style: context.text.bodySmall,
                      ),
                    ],
                    if (a.since != null)
                      Padding(
                        padding: const EdgeInsets.only(top: AppSpace.xxs),
                        child: Text(
                          l10n.alertSince(QatarTime.date(a.since, context.localeCode)),
                          style: context.text.labelSmall?.copyWith(color: palette.muted),
                        ),
                      ),
                  ],
                ),
              ),
              RoundIconButton(
                icon: Icons.delete_outline_rounded,
                background: Colors.transparent,
                color: palette.danger,
                size: kMinTouchTarget,
                tooltip: l10n.alertRemove,
                onPressed: () async {
                  try {
                    await ref.read(alertsControllerProvider.notifier).remove(a);
                    if (context.mounted) showInfoSnack(context, l10n.alertRemoved);
                  } catch (e) {
                    if (context.mounted) showErrorSnack(context, e);
                  }
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}
