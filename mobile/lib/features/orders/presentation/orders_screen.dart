import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
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
      child: Scaffold(
        appBar: AppBar(
          title: Text(l10n.ordersTitle),
          bottom: TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [
              Tab(text: l10n.filterAll),
              Tab(text: l10n.filterActive),
              Tab(text: l10n.filterCompleted),
              Tab(text: l10n.filterCancelled),
            ],
          ),
        ),
        body: TabBarView(children: [for (final f in OrderFilter.values) _OrdersList(filter: f)]),
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

class OrderCard extends StatelessWidget {
  const OrderCard({super.key, required this.order});
  final OrderSummary order;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return ContentWidth(
      child: SurfaceCard(
        onTap: () => context.push('/orders/${order.id}'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(l10n.orderNumber(order.number), style: context.text.titleMedium)),
                StatusChip(label: order.statusLabel ?? order.status, tone: toneForStatus(order.status), dense: true),
              ],
            ),
            const Gap(AppSpace.xxs),
            Text(l10n.placedOn(QatarTime.dateTime(order.placedAt, context.localeCode)), style: context.text.bodySmall),
            if (order.thumbnails.isNotEmpty) ...[
              const Gap(AppSpace.sm),
              Row(
                children: [
                  for (final t in order.thumbnails)
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: AppSpace.xs),
                      child: AppNetworkImage(url: t, width: 48, height: 56, borderRadius: BorderRadius.circular(8)),
                    ),
                ],
              ),
            ],
            const Gap(AppSpace.sm),
            Row(
              children: [
                if (order.itemsCount != null) Text(l10n.itemsCount(order.itemsCount!), style: context.text.bodySmall),
                const Spacer(),
                if (order.grandTotal != null) Text(order.grandTotal!.formatted, style: context.text.titleSmall),
                const Icon(Icons.chevron_right_rounded),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
