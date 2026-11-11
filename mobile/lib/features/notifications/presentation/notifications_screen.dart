import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/notifications/domain/notification_models.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final async = ref.watch(notificationsProvider);
    final unread = ref.watch(unreadCountProvider);
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.notificationsTitle),
        actions: [
          if (unread > 0)
            TextButton(
              onPressed: () async {
                try {
                  await ref.read(notificationsProvider.notifier).markAllRead();
                } catch (e) {
                  if (context.mounted) showErrorSnack(context, e);
                }
              },
              child: Text(l10n.markAllRead),
            ),
          IconButton(
            tooltip: l10n.notificationPreferences,
            icon: const Icon(Icons.tune_rounded),
            onPressed: () => context.push('/preferences/notifications'),
          ),
        ],
      ),
      body: async.when(
        skipLoadingOnRefresh: true,
        loading: () => const ListSkeleton(),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(notificationsProvider)),
        data: (state) => state.items.isEmpty
            ? EmptyState(
                icon: Icons.notifications_none_rounded,
                title: l10n.noNotifications,
                message: l10n.noNotificationsMessage,
              )
            : RefreshIndicator(
                onRefresh: () => ref.read(notificationsProvider.notifier).refresh(),
                child: NotificationListener<ScrollNotification>(
                  onNotification: (n) =>
                      handleLoadMoreScroll(n, () => ref.read(notificationsProvider.notifier).loadMore()),
                  child: ListView.separated(
                    itemCount: state.items.length + 1,
                    separatorBuilder: (_, _) => Divider(color: context.palette.line, height: 1),
                    itemBuilder: (context, i) {
                      if (i == state.items.length) {
                        return LoadMoreFooter(
                          state: state,
                          onLoadMore: () => ref.read(notificationsProvider.notifier).loadMore(),
                        );
                      }
                      return _NotificationTile(item: state.items[i]);
                    },
                  ),
                ),
              ),
      ),
    );
  }
}

IconData _iconFor(String event) {
  if (event.startsWith('order.')) return Icons.receipt_long_outlined;
  if (event.startsWith('payment.')) return Icons.credit_card_outlined;
  if (event.startsWith('delivery.') || event.contains('delivery')) return Icons.local_shipping_outlined;
  if (event.startsWith('auction.') || event.startsWith('lot.')) return Icons.gavel_rounded;
  if (event.startsWith('support.')) return Icons.support_agent_outlined;
  if (event.startsWith('marketing.') || event.startsWith('promotion.')) return Icons.local_offer_outlined;
  return Icons.notifications_none_rounded;
}

class _NotificationTile extends ConsumerWidget {
  const _NotificationTile({required this.item});
  final AppNotification item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unread = !item.isRead;
    return Semantics(
      button: true,
      child: InkWell(
        onTap: () {
          unawaited(ref.read(notificationsProvider.notifier).markRead(item));
          unawaited(context.push(notificationLocation(item.routingPayload)));
        },
        child: Container(
          color: unread ? context.palette.brassTint.withValues(alpha: 0.5) : null,
          padding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter, vertical: AppSpace.md),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                backgroundColor: context.palette.surfaceMuted,
                child: Icon(_iconFor(item.event), color: context.palette.brass, size: 20),
              ),
              const Gap(AppSpace.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.title,
                      style: context.text.titleSmall?.copyWith(fontWeight: unread ? FontWeight.w700 : FontWeight.w500),
                    ),
                    const Gap(2),
                    Text(item.body, style: context.text.bodyMedium),
                    const Gap(4),
                    Text(QatarTime.dateTime(item.createdAt, context.localeCode), style: context.text.bodySmall),
                  ],
                ),
              ),
              if (unread)
                Container(
                  width: 8,
                  height: 8,
                  margin: const EdgeInsetsDirectional.only(start: AppSpace.xs, top: 6),
                  decoration: BoxDecoration(color: context.palette.brass, shape: BoxShape.circle),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
