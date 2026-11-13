import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/auctions/presentation/lot_widgets.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/orders/presentation/order_detail_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class MyAuctionsScreen extends StatelessWidget {
  const MyAuctionsScreen({super.key, this.initialTab});
  final String? initialTab;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final index = switch (initialTab) {
      'watchlist' => 1,
      'wins' => 2,
      _ => 0,
    };
    return DefaultTabController(
      length: 3,
      initialIndex: index,
      child: Scaffold(
        appBar: AppBar(
          title: Text(l10n.myAuctions),
          bottom: TabBar(
            tabs: [
              Tab(text: l10n.myBids),
              Tab(text: l10n.myWatchlist),
              Tab(text: l10n.myWins),
            ],
          ),
        ),
        body: TabBarView(
          children: [
            _PagedTab<MyBid>(
              provider: myBidsProvider,
              itemBuilder: (b) => LotCard(
                lot: b.lot,
                trailing: Padding(
                  padding: const EdgeInsetsDirectional.only(start: AppSpace.xs),
                  child: Text(b.displayAmount.formatted, style: context.text.titleSmall),
                ),
              ),
            ),
            _PagedTab<Lot>(
              provider: myWatchlistProvider,
              itemBuilder: (l) => LotCard(lot: l),
            ),
            _PagedTab<AuctionWin>(
              provider: myWinsProvider,
              itemBuilder: (w) => _WinCard(win: w),
            ),
          ],
        ),
      ),
    );
  }
}

class _PagedTab<T> extends ConsumerWidget {
  const _PagedTab({required this.provider, required this.itemBuilder});
  final AsyncNotifierProvider<PagedNotifier<T>, PagedState<T>> provider;
  final Widget Function(T item) itemBuilder;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final async = ref.watch(provider);
    return async.when(
      skipLoadingOnRefresh: true,
      loading: () => const ListSkeleton(),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(provider)),
      data: (state) => state.items.isEmpty
          ? EmptyState(
              icon: Icons.gavel_rounded,
              title: l10n.noAuctions,
              actionLabel: l10n.auctionsTitle,
              onAction: () => context.go('/auctions'),
            )
          : RefreshIndicator(
              onRefresh: () => ref.read(provider.notifier).refresh(),
              child: ListView.separated(
                padding: const EdgeInsets.all(AppSpace.gutter),
                itemCount: state.items.length + 1,
                separatorBuilder: (_, _) => const Gap(AppSpace.sm),
                itemBuilder: (_, i) => i == state.items.length
                    ? LoadMoreFooter(state: state, onLoadMore: () => ref.read(provider.notifier).loadMore())
                    : itemBuilder(state.items[i]),
              ),
            ),
    );
  }
}

class _WinCard extends StatelessWidget {
  const _WinCard({required this.win});
  final AuctionWin win;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        LotCard(lot: win.lot.copyWith(myStatus: 'won')),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${l10n.winningBid}: ${win.amount.formatted}', style: context.text.titleSmall),
                    if (win.payBy != null)
                      Text(
                        l10n.payBy(QatarTime.dateTime(win.payBy, context.localeCode)),
                        style: context.text.bodySmall,
                      ),
                  ],
                ),
              ),
              if (win.needsCheckout)
                AppButton.accent(
                  label: l10n.checkoutWin,
                  expand: false,
                  onPressed: () => context.push('/auctions/wins/${win.id}/checkout', extra: win),
                )
              else if (win.orderId != null)
                TextButton(
                  onPressed: () => unawaited(showOrderPanel(context, win.orderId!)),
                  child: Text(l10n.viewOrder),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
