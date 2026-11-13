import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/core/router/panel_links.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/notifications/domain/notification_models.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:albahar_customer/features/profile/presentation/notification_prefs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Opens the notifications in a side window. A notification about an order or a ticket opens that record in its own
/// window in place of this one; one without a destination opens its full text.
Future<void> showNotificationsPanel(BuildContext context) {
  final host = context;
  return showSidePanel<void>(
    context: context,
    title: context.l10n.notificationsTitle,
    scrollable: false,
    builder: (_) => NotificationsScreen(embedded: true, host: host),
  );
}

class NotificationsScreen extends ConsumerStatefulWidget {
  const NotificationsScreen({super.key, this.embedded = false, this.host});

  /// True inside a side window ([showNotificationsPanel]): no app bar; [host] is the page that opened it.
  final bool embedded;
  final BuildContext? host;

  @override
  ConsumerState<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends ConsumerState<NotificationsScreen> {
  AppNotification? _detail;

  /// Opens one notification: its record when it has one, otherwise its full text.
  void _open(AppNotification item) {
    unawaited(ref.read(notificationsProvider.notifier).markRead(item));
    final location = notificationLocation(item.routingPayload);
    if (location == '/notifications') {
      if (widget.embedded) {
        setState(() => _detail = item);
      } else {
        unawaited(
          showSidePanel<void>(
            context: context,
            title: item.title,
            builder: (_) => NotificationDetail(item: item),
          ),
        );
      }
      return;
    }
    if (!widget.embedded) {
      unawaited(openLocation(context, location));
      return;
    }
    // One window at a time: this one closes and the record opens on the page behind.
    final page = widget.host;
    Navigator.of(context).pop();
    if (page != null && page.mounted) unawaited(openLocation(page, location));
  }

  void _openPrefs() {
    if (!widget.embedded) {
      unawaited(showNotificationPrefsPanel(context));
      return;
    }
    final page = widget.host;
    Navigator.of(context).pop();
    if (page != null && page.mounted) unawaited(showNotificationPrefsPanel(page));
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final detail = _detail;
    if (widget.embedded && detail != null) {
      return PanelStep(
        title: detail.title,
        onBack: () => setState(() => _detail = null),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
          child: NotificationDetail(item: detail),
        ),
      );
    }
    final async = ref.watch(notificationsProvider);
    final unread = ref.watch(unreadCountProvider);
    final actions = [
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
        constraints: const BoxConstraints(minWidth: kMinTouchTarget, minHeight: kMinTouchTarget),
        icon: const Icon(Icons.tune_rounded),
        onPressed: _openPrefs,
      ),
    ];
    final body = async.when(
      skipLoadingOnRefresh: true,
      loading: () => const ListSkeleton(),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(notificationsProvider)),
      data: (state) => state.items.isEmpty
          ? EmptyState(
              icon: Icons.notifications_none_rounded,
              title: l10n.noNotifications,
              message: l10n.noNotificationsMessage,
              compact: widget.embedded,
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
                    return _NotificationTile(item: state.items[i], onOpen: _open);
                  },
                ),
              ),
            ),
    );
    if (widget.embedded) {
      return Column(
        children: [
          // The actions of the page's app bar live in a slim row under the window's header.
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: Padding(
              padding: const EdgeInsetsDirectional.only(end: AppSpace.xs),
              child: Row(mainAxisSize: MainAxisSize.min, children: actions),
            ),
          ),
          Expanded(child: body),
        ],
      );
    }
    return Scaffold(
      appBar: AppBar(title: Text(l10n.notificationsTitle), actions: actions),
      body: body,
    );
  }
}

/// The whole text of a notification: what happened, when, and the details.
class NotificationDetail extends StatelessWidget {
  const NotificationDetail({super.key, required this.item});
  final AppNotification item;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Row(
        children: [
          CircleAvatar(
            backgroundColor: context.palette.surfaceMuted,
            child: Icon(_iconFor(item.event), color: context.palette.brass, size: 20),
          ),
          const Gap(AppSpace.sm),
          Expanded(child: Text(QatarTime.dateTime(item.createdAt, context.localeCode), style: context.text.bodySmall)),
        ],
      ),
      const Gap(AppSpace.md),
      Semantics(header: true, child: Text(item.title, style: context.text.titleMedium)),
      const Gap(AppSpace.xs),
      SelectableText(item.body, style: context.text.bodyLarge),
    ],
  );
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

class _NotificationTile extends StatelessWidget {
  const _NotificationTile({required this.item, required this.onOpen});
  final AppNotification item;
  final ValueChanged<AppNotification> onOpen;

  @override
  Widget build(BuildContext context) {
    final unread = !item.isRead;
    return Semantics(
      button: true,
      child: InkWell(
        onTap: () => onOpen(item),
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
                    Text(item.body, maxLines: 3, overflow: TextOverflow.ellipsis, style: context.text.bodyMedium),
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
