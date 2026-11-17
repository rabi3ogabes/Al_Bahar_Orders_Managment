import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:albahar_customer/features/deals/domain/deal_models.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Every deal on sale now and the ones about to start, with a few products each (the Deals screen).
final dealsFeedProvider = FutureProvider.autoDispose<DealsFeed>((ref) {
  ref.watch(localeCodeProvider);
  ref.watch(isAuthenticatedProvider);
  final branchId = ref.watch(preferredBranchIdProvider);
  return ref.watch(dealsRepositoryProvider).feed(branchId: branchId);
});

/// The deals the admin put on the home page, without their products (the strip needs only the widget).
final homeDealsProvider = FutureProvider.autoDispose<DealsFeed>((ref) {
  ref.watch(localeCodeProvider);
  ref.watch(isAuthenticatedProvider);
  final branchId = ref.watch(preferredBranchIdProvider);
  return ref.watch(dealsRepositoryProvider).feed(home: true, limitProducts: 1, branchId: branchId);
});

/// One deal and its products, page by page.
@immutable
class DealDetailState {
  const DealDetailState({
    required this.deal,
    required this.products,
    this.page = 1,
    this.hasMore = false,
    this.loadingMore = false,
    this.loadMoreError,
  });

  final Deal deal;
  final List<ProductSummary> products;
  final int page;
  final bool hasMore;
  final bool loadingMore;
  final Object? loadMoreError;

  /// The state of the product list, for the shared "load more" footer.
  PagedState<ProductSummary> get paged => PagedState<ProductSummary>(
    items: products,
    page: page,
    hasMore: hasMore,
    loadingMore: loadingMore,
    loadMoreError: loadMoreError,
  );

  DealDetailState copyWith({
    List<ProductSummary>? products,
    int? page,
    bool? hasMore,
    bool? loadingMore,
    Object? Function()? loadMoreError,
  }) => DealDetailState(
    deal: deal,
    products: products ?? this.products,
    page: page ?? this.page,
    hasMore: hasMore ?? this.hasMore,
    loadingMore: loadingMore ?? this.loadingMore,
    loadMoreError: loadMoreError != null ? loadMoreError() : this.loadMoreError,
  );
}

class DealDetailController extends AsyncNotifier<DealDetailState> {
  DealDetailController(this.dealId);
  final String dealId;

  @override
  Future<DealDetailState> build() async {
    ref.watch(localeCodeProvider);
    ref.watch(isAuthenticatedProvider);
    final branchId = ref.watch(preferredBranchIdProvider);
    final first = await ref.read(dealsRepositoryProvider).detail(dealId, branchId: branchId);
    return DealDetailState(
      deal: first.deal,
      products: first.deal.products,
      page: first.currentPage,
      hasMore: first.hasMore,
    );
  }

  Future<void> loadMore() async {
    final current = state.value;
    if (current == null || !current.hasMore || current.loadingMore || state.isLoading) return;
    state = AsyncData(current.copyWith(loadingMore: true, loadMoreError: () => null));
    try {
      final next = await ref
          .read(dealsRepositoryProvider)
          .detail(dealId, page: current.page + 1, branchId: ref.read(preferredBranchIdProvider));
      if (!ref.mounted) return;
      final seen = {for (final p in current.products) p.id};
      final fresh = [
        for (final p in next.deal.products)
          if (seen.add(p.id)) p,
      ];
      state = AsyncData(
        current.copyWith(
          products: [...current.products, ...fresh],
          page: next.currentPage,
          hasMore: next.hasMore && next.deal.products.isNotEmpty,
          loadingMore: false,
        ),
      );
    } catch (e) {
      if (!ref.mounted) return;
      state = AsyncData(current.copyWith(loadingMore: false, loadMoreError: () => e));
    }
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }
}

final dealDetailProvider = AsyncNotifierProvider.autoDispose.family<DealDetailController, DealDetailState, String>(
  DealDetailController.new,
);

/// Whether the dashboard's footer has a Deals item (anywhere in the bar): only then does the app look for live deals.
/// (The built-in footers never have one, so the dashboard's footer is the only place to look.)
final footerHasDealsProvider = Provider<bool>(
  (ref) => ref.watch(brandThemeProvider.select((b) => b.footer?.keys.contains('deals') ?? false)),
);

/// True while at least one deal is on sale: the small live dot on the Deals button of the footer.
///
/// It asks once a minute, only while the app is open on screen, and only when the footer has a Deals button (no request
/// and no timer otherwise).
class LiveDealsController extends Notifier<bool> {
  Timer? _timer;
  _Lifecycle? _observer;

  static const interval = Duration(minutes: 1);

  @override
  bool build() {
    ref.onDispose(_stop);
    if (!ref.watch(footerHasDealsProvider)) return false;
    // The footer is dropped from the tree while the keyboard is open and comes back after it: that must not throw the dot away (and
    // ask the server again). It lives as long as the footer has a Deals item; the rebuild of an edited footer lets it go (the
    // lifecycle watcher and the timer are stopped by [_stop] when it does).
    ref.keepAlive();
    ref.watch(localeCodeProvider);
    ref.watch(preferredBranchIdProvider);
    final binding = WidgetsBinding.instance;
    _observer = _Lifecycle(
      onResumed: () {
        unawaited(_poll());
        _start();
      },
      onAway: () => _timer?.cancel(),
    );
    binding.addObserver(_observer!);
    final state = binding.lifecycleState;
    if (state == null || state == AppLifecycleState.resumed) {
      unawaited(Future.microtask(_poll));
      _start();
    }
    return false;
  }

  void _start() {
    _timer?.cancel();
    _timer = Timer.periodic(interval, (_) => unawaited(_poll()));
  }

  void _stop() {
    _timer?.cancel();
    _timer = null;
    final observer = _observer;
    if (observer != null) WidgetsBinding.instance.removeObserver(observer);
    _observer = null;
  }

  Future<void> _poll() async {
    try {
      final feed = await ref
          .read(dealsRepositoryProvider)
          .feed(home: true, limitProducts: 1, branchId: ref.read(preferredBranchIdProvider));
      if (!ref.mounted) return;
      state = feed.hasLive;
    } catch (_) {
      // Offline or a server hiccup: keep what the dot shows now; the next minute asks again.
    }
  }
}

class _Lifecycle with WidgetsBindingObserver {
  _Lifecycle({required this.onResumed, required this.onAway});
  final VoidCallback onResumed;
  final VoidCallback onAway;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      onResumed();
    } else {
      onAway();
    }
  }
}

/// Auto-disposed, but kept alive while the footer has a Deals item (see [LiveDealsController.build]): the timer and the lifecycle watcher
/// live as long as the dashboard's footer shows the button.
final liveDealsProvider = NotifierProvider.autoDispose<LiveDealsController, bool>(LiveDealsController.new);
