import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/fresh.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return DefaultTabController(
      length: OrderFilter.values.length,
      child: FreshScaffold(
        appBar: AppBar(title: Text(l10n.ordersTitle)),
        header: FreshHeader(title: l10n.ordersTitle, tagline: l10n.freshTaglineOrders),
        body: Column(
          children: [
            _SegmentedTabs(labels: [l10n.filterAll, l10n.filterActive, l10n.filterCompleted, l10n.filterCancelled]),
            Expanded(
              child: TabBarView(children: [for (final f in OrderFilter.values) _OrdersList(filter: f)]),
            ),
          ],
        ),
      ),
    );
  }
}

/// Pill-shaped segmented control driving the surrounding [DefaultTabController].
class _SegmentedTabs extends StatelessWidget {
  const _SegmentedTabs({required this.labels});
  final List<String> labels;

  @override
  Widget build(BuildContext context) {
    final controller = DefaultTabController.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.xxs, AppSpace.gutter, AppSpace.xs),
      child: Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(
          color: context.palette.surfaceMuted,
          borderRadius: BorderRadius.circular(AppRadius.pill),
        ),
        child: AnimatedBuilder(
          animation: controller.animation!,
          builder: (context, _) => Row(
            children: [
              for (var i = 0; i < labels.length; i++)
                Expanded(
                  child: Semantics(
                    button: true,
                    selected: controller.index == i,
                    child: GestureDetector(
                      behavior: HitTestBehavior.opaque,
                      onTap: () => controller.animateTo(i),
                      child: AnimatedContainer(
                        duration: AppDurations.fast,
                        height: kMinTouchTarget,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: controller.index == i ? context.colors.primary : Colors.transparent,
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                        ),
                        child: Text(
                          labels[i],
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: context.text.labelMedium?.copyWith(
                            color: controller.index == i ? context.colors.onPrimary : context.palette.muted,
                            fontWeight: controller.index == i ? FontWeight.w600 : FontWeight.w500,
                            letterSpacing: 0,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _OrdersList extends ConsumerWidget {
  const _OrdersList({required this.filter});
  final OrderFilter filter;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final provider = ordersProvider(filter);
    final orders = ref.watch(provider);
    return orders.when(
      skipLoadingOnRefresh: true,
      loading: () => const ListSkeleton(),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(provider)),
      data: (state) {
        if (state.items.isEmpty) {
          return EmptyState(
            icon: Icons.receipt_long_outlined,
            title: l10n.noOrders,
            message: l10n.noOrdersMessage,
            actionLabel: l10n.startShopping,
            onAction: () => context.go('/home'),
          );
        }
        return RefreshIndicator(
          onRefresh: () => ref.read(provider.notifier).refresh(),
          child: NotificationListener<ScrollNotification>(
            onNotification: (n) => handleLoadMoreScroll(n, () => ref.read(provider.notifier).loadMore()),
            child: ListView.separated(
              padding: const EdgeInsets.all(AppSpace.gutter),
              itemCount: state.items.length + 1,
              separatorBuilder: (_, _) => const Gap(AppSpace.sm),
              itemBuilder: (context, i) {
                if (i == state.items.length) {
                  return LoadMoreFooter(state: state, onLoadMore: () => ref.read(provider.notifier).loadMore());
                }
                return OrderCard(order: state.items[i]);
              },
            ),
          ),
        );
      },
    );
  }
}

/// Order summary card: status pill + number, thumbnails, items and total, date.
class OrderCard extends StatelessWidget {
  const OrderCard({super.key, required this.order});
  final OrderSummary order;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return ContentWidth(
      child: SurfaceCard(
        onTap: () => context.push('/orders/${order.id}'),
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                StatusChip(label: order.statusLabel ?? order.status, tone: toneForStatus(order.status), dense: true),
                const Spacer(),
                Text(order.number, style: context.text.bodySmall),
              ],
            ),
            const Gap(AppSpace.sm),
            Row(
              children: [
                if (order.thumbnails.isNotEmpty)
                  for (final t in order.thumbnails.take(3))
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: AppSpace.xs),
                      child: Container(
                        width: 52,
                        height: 52,
                        decoration: BoxDecoration(
                          borderRadius: AppRadius.imageAll,
                          border: Border.all(color: context.palette.line),
                        ),
                        child: AppNetworkImage(
                          url: t,
                          width: 52,
                          height: 52,
                          borderRadius: AppRadius.imageAll,
                          fit: BoxFit.contain,
                        ),
                      ),
                    )
                else
                  Container(
                    width: 52,
                    height: 52,
                    decoration: BoxDecoration(color: context.palette.tint, borderRadius: AppRadius.imageAll),
                    child: Icon(Icons.inventory_2_outlined, color: context.colors.primary),
                  ),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (order.grandTotal != null)
                        Text(
                          order.itemsCount != null
                              ? '${l10n.itemsCount(order.itemsCount!)} · ${order.grandTotal!.formatted}'
                              : order.grandTotal!.formatted,
                          style: context.text.titleSmall,
                        ),
                      const Gap(2),
                      Text(
                        l10n.placedOn(QatarTime.dateTime(order.placedAt, context.localeCode)),
                        style: context.text.bodySmall,
                      ),
                    ],
                  ),
                ),
                Icon(Icons.chevron_right_rounded, color: context.palette.muted),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
