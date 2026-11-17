import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/domain/deal_models.dart';
import 'package:albahar_customer/features/deals/presentation/state/deals_providers.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_card_data.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_widget.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Opens the page of one deal.
void openDeal(BuildContext context, String dealId) => unawaited(context.push('/deals/${Uri.encodeComponent(dealId)}'));

/// All the deals: the ones on sale now, each with a row of its products, and the ones about to start with a "starts in"
/// countdown. The whole screen shares one clock (one timer), and when a deal's time comes the list asks the server again.
class DealsScreen extends StatelessWidget {
  const DealsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(context.l10n.dealsTitle)),
    body: const DealClockHost(child: _DealsBody()),
  );
}

class _DealsBody extends ConsumerWidget {
  const _DealsBody();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final feed = ref.watch(dealsFeedProvider);
    return feed.when(
      skipLoadingOnRefresh: true,
      loading: () => const _DealsSkeleton(),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(dealsFeedProvider)),
      data: (data) {
        if (data.isEmpty) {
          return EmptyState(
            icon: Icons.local_fire_department_outlined,
            title: l10n.dealsEmptyTitle,
            message: l10n.dealsEmptyMessage,
            actionLabel: l10n.dealsBrowseShop,
            onAction: () => context.go('/categories'),
          );
        }
        final due = [
          for (final d in [...data.live, ...data.upcoming]) ?d.dueAt,
        ]..sort();
        return RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(dealsFeedProvider);
            await ref.read(dealsFeedProvider.future);
          },
          child: CustomScrollView(
            // Pull to refresh works even when one deal does not fill the screen.
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              SliverToBoxAdapter(
                child: ContentWidth(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // Draws nothing; asks the server again the moment the first deal ends or starts.
                      DealDueWatcher(
                        dueAt: due.isEmpty ? null : due.first,
                        onDue: () => ref.invalidate(dealsFeedProvider),
                      ),
                      if (data.live.isNotEmpty) ...[
                        if (data.upcoming.isNotEmpty) SectionHeader(title: l10n.dealsLiveSection),
                        for (final deal in data.live) _LiveDealSection(deal: deal),
                      ],
                      if (data.upcoming.isNotEmpty) ...[
                        SectionHeader(title: l10n.dealsUpcomingSection),
                        for (final deal in data.upcoming) _UpcomingDeal(deal: deal),
                      ],
                      const Gap(AppSpace.xxl),
                    ],
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

/// Hides [child] once [deal] is over on the server's clock, without rebuilding [child] every second.
class _HideWhenOver extends StatelessWidget {
  const _HideWhenOver({required this.deal, required this.child});

  final Deal deal;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final over = deal.phaseAt(DealClockScope.trustedNowOf(context)) == DealPhase.ended;
    return over ? const SizedBox.shrink() : child;
  }
}

/// A deal on sale: its widget, a row of its products and a link to all of them.
class _LiveDealSection extends StatelessWidget {
  const _LiveDealSection({required this.deal});

  final Deal deal;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final more = deal.productsTotal > deal.products.length;
    return _HideWhenOver(
      deal: deal,
      child: Padding(
        padding: const EdgeInsets.only(top: AppSpace.sm, bottom: AppSpace.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter),
              child: DealWidget(data: DealCardData.fromDeal(deal), onTap: () => openDeal(context, deal.id)),
            ),
            if (deal.products.isNotEmpty) ...[
              const Gap(AppSpace.md),
              ProductCarousel(products: deal.products, scope: 'deal-${deal.id}'),
            ],
            if (more)
              Align(
                alignment: AlignmentDirectional.centerEnd,
                child: Padding(
                  padding: const EdgeInsetsDirectional.only(end: AppSpace.sm),
                  child: TextButton(
                    onPressed: () => openDeal(context, deal.id),
                    child: Text(l10n.dealsSeeProducts(deal.productsTotal)),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// A deal that has not started: a smaller widget with its "starts in" countdown.
class _UpcomingDeal extends StatelessWidget {
  const _UpcomingDeal({required this.deal});

  final Deal deal;

  @override
  Widget build(BuildContext context) => _HideWhenOver(
    deal: deal,
    child: Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, 0, AppSpace.gutter, AppSpace.md),
      child: DealWidget(data: DealCardData.fromDeal(deal), compact: true, onTap: () => openDeal(context, deal.id)),
    ),
  );
}

class _DealsSkeleton extends StatelessWidget {
  const _DealsSkeleton();

  @override
  Widget build(BuildContext context) => const SingleChildScrollView(
    physics: NeverScrollableScrollPhysics(),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.md, AppSpace.gutter, AppSpace.md),
          child: Skeleton(height: 168, radius: AppRadius.lg),
        ),
        ProductCarouselSkeleton(),
      ],
    ),
  );
}
