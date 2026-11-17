import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/deals/domain/deal_models.dart';
import 'package:albahar_customer/features/deals/presentation/state/deals_providers.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_card_data.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_widget.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// One deal: its widget with the countdown, how many units one order and one customer can buy, and every product with its
/// deal price. An ended or paused deal answers "not found": the page then says it ended and offers the current deals.
class DealDetailScreen extends ConsumerWidget {
  const DealDetailScreen({super.key, required this.dealId});

  final String dealId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final provider = dealDetailProvider(dealId);
    final detail = ref.watch(provider);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.dealsTitle)),
      body: DealClockHost(
        child: detail.when(
          skipLoadingOnRefresh: true,
          loading: () => const _DetailSkeleton(),
          error: (e, _) => e is ApiException && e.kind == ApiErrorKind.notFound
              ? EmptyState(
                  icon: Icons.timer_off_outlined,
                  title: l10n.dealEndedTitle,
                  message: l10n.dealEndedMessage,
                  actionLabel: l10n.dealsSeeCurrent,
                  onAction: () => context.go('/deals'),
                )
              : ErrorState(error: e, onRetry: () => ref.invalidate(provider)),
          data: (state) => RefreshIndicator(
            onRefresh: () => ref.read(provider.notifier).refresh(),
            child: NotificationListener<ScrollNotification>(
              onNotification: (n) => handleLoadMoreScroll(n, () => ref.read(provider.notifier).loadMore()),
              child: CustomScrollView(
                slivers: [
                  SliverToBoxAdapter(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        DealDueWatcher(dueAt: state.deal.dueAt, onDue: () => ref.invalidate(provider)),
                        Padding(
                          padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.md, AppSpace.gutter, 0),
                          child: DealWidget(data: DealCardData.fromDeal(state.deal)),
                        ),
                        _Limits(deal: state.deal),
                      ],
                    ),
                  ),
                  if (state.products.isEmpty)
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: EmptyState(
                        icon: Icons.local_fire_department_outlined,
                        title: l10n.noProducts,
                        message: l10n.dealNoProducts,
                        compact: true,
                      ),
                    )
                  else ...[
                    SliverPadding(
                      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.md, AppSpace.gutter, 0),
                      sliver: SliverLayoutBuilder(
                        builder: (context, c) => SliverGrid.builder(
                          gridDelegate: productGridDelegate(
                            c.crossAxisExtent,
                            horizontalPadding: 0,
                            textScale: MediaQuery.textScalerOf(context).scale(1),
                          ),
                          itemCount: state.products.length,
                          itemBuilder: (_, i) => ProductTile(product: state.products[i], heroScope: 'deal'),
                        ),
                      ),
                    ),
                    SliverToBoxAdapter(
                      child: LoadMoreFooter(
                        state: state.paged,
                        onLoadMore: () => ref.read(provider.notifier).loadMore(),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// "Limit 2 per order · Limit 3 per customer": what one order and one account can buy at the deal price.
class _Limits extends StatelessWidget {
  const _Limits({required this.deal});

  final Deal deal;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final lines = [
      if (deal.limitPerOrder != null) l10n.dealLimitPerOrder(deal.limitPerOrder!),
      if (deal.limitPerCustomer != null) l10n.dealLimitPerCustomer(deal.limitPerCustomer!),
    ];
    if (lines.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, 0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.info_outline_rounded, size: 16, color: context.palette.muted),
          const Gap(AppSpace.xs),
          Expanded(
            child: Text(lines.join(' · '), style: context.text.bodySmall?.copyWith(color: context.palette.muted)),
          ),
        ],
      ),
    );
  }
}

class _DetailSkeleton extends StatelessWidget {
  const _DetailSkeleton();

  @override
  Widget build(BuildContext context) => const SingleChildScrollView(
    physics: NeverScrollableScrollPhysics(),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.md, AppSpace.gutter, 0),
          child: Skeleton(height: 168, radius: AppRadius.lg),
        ),
        ProductGridSkeleton(count: 4, shrinkWrap: true),
      ],
    ),
  );
}
