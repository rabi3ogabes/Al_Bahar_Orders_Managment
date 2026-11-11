import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/home/presentation/home_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

const auctionStatuses = ['live', 'upcoming', 'completed'];

class AuctionsScreen extends ConsumerWidget {
  const AuctionsScreen({super.key, this.initialStatus});
  final String? initialStatus;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final initial = auctionStatuses.indexOf(initialStatus ?? 'live').clamp(0, 2);
    return DefaultTabController(
      key: ValueKey(initialStatus),
      length: auctionStatuses.length,
      initialIndex: initial,
      child: Scaffold(
        appBar: AppBar(
          title: Text(l10n.auctionsTitle),
          actions: [
            if (ref.watch(isAuthenticatedProvider))
              TextButton.icon(
                onPressed: () => context.push('/my-auctions'),
                icon: const Icon(Icons.person_pin_outlined, size: 20),
                label: Text(l10n.myAuctions),
              ),
          ],
          bottom: TabBar(
            tabs: [
              Tab(text: l10n.auctionLive),
              Tab(text: l10n.auctionUpcoming),
              Tab(text: l10n.auctionCompleted),
            ],
          ),
        ),
        body: TabBarView(children: [for (final s in auctionStatuses) _AuctionList(status: s)]),
      ),
    );
  }
}

class _AuctionList extends ConsumerWidget {
  const _AuctionList({required this.status});
  final String status;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final provider = auctionListProvider(status);
    final list = ref.watch(provider);
    return list.when(
      skipLoadingOnRefresh: true,
      loading: () => const ListSkeleton(count: 3),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(provider)),
      data: (state) {
        if (state.items.isEmpty) {
          return EmptyState(icon: Icons.gavel_rounded, title: l10n.noAuctions, message: l10n.noAuctionsMessage);
        }
        return RefreshIndicator(
          onRefresh: () => ref.read(provider.notifier).refresh(),
          child: NotificationListener<ScrollNotification>(
            onNotification: (n) => handleLoadMoreScroll(n, () => ref.read(provider.notifier).loadMore()),
            child: LayoutBuilder(
              builder: (context, c) {
                final cols = c.maxWidth >= 900 ? 3 : (c.maxWidth >= 600 ? 2 : 1);
                return CustomScrollView(
                  slivers: [
                    SliverPadding(
                      padding: const EdgeInsets.all(AppSpace.gutter),
                      sliver: SliverGrid.builder(
                        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: cols,
                          mainAxisSpacing: AppSpace.md,
                          crossAxisSpacing: AppSpace.md,
                          mainAxisExtent: 280,
                        ),
                        itemCount: state.items.length,
                        itemBuilder: (_, i) => AuctionCardView(auction: state.items[i]),
                      ),
                    ),
                    SliverToBoxAdapter(
                      child: LoadMoreFooter(state: state, onLoadMore: () => ref.read(provider.notifier).loadMore()),
                    ),
                  ],
                );
              },
            ),
          ),
        );
      },
    );
  }
}
