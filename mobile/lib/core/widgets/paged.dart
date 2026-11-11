import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/paginated.dart';
import '../theme/tokens.dart';
import 'state_views.dart';

/// State for infinite-scroll lists.
@immutable
class PagedState<T> {
  const PagedState({
    this.items = const [],
    this.page = 0,
    this.hasMore = true,
    this.loadingMore = false,
    this.loadMoreError,
    this.total,
    this.meta = const {},
  });

  final List<T> items;
  final int page;
  final bool hasMore;
  final bool loadingMore;
  final Object? loadMoreError;
  final int? total;
  final Map<String, dynamic> meta;

  PagedState<T> copyWith({
    List<T>? items,
    int? page,
    bool? hasMore,
    bool? loadingMore,
    Object? Function()? loadMoreError,
    int? total,
    Map<String, dynamic>? meta,
  }) => PagedState<T>(
    items: items ?? this.items,
    page: page ?? this.page,
    hasMore: hasMore ?? this.hasMore,
    loadingMore: loadingMore ?? this.loadingMore,
    loadMoreError: loadMoreError != null ? loadMoreError() : this.loadMoreError,
    total: total ?? this.total,
    meta: meta ?? this.meta,
  );
}

/// Base notifier for paginated endpoints. Subclasses implement [fetchPage]
/// and (optionally) watch other providers in [build] via [watchDependencies].
abstract class PagedNotifier<T> extends AsyncNotifier<PagedState<T>> {
  Future<Paginated<T>> fetchPage(int page);

  /// Hook for subclasses to `ref.watch` inputs (locale, branch, filters).
  void watchDependencies() {}

  @override
  Future<PagedState<T>> build() async {
    watchDependencies();
    final first = await fetchPage(1);
    return PagedState<T>(
      items: first.items,
      page: first.currentPage,
      hasMore: first.hasMore,
      total: first.total,
      meta: first.extraMeta,
    );
  }

  Future<void> loadMore() async {
    final current = state.value;
    if (current == null || !current.hasMore || current.loadingMore || state.isLoading) return;
    state = AsyncData(current.copyWith(loadingMore: true, loadMoreError: () => null));
    try {
      final next = await fetchPage(current.page + 1);
      if (!ref.mounted) return;
      state = AsyncData(
        current.copyWith(
          items: [...current.items, ...next.items],
          page: next.currentPage,
          hasMore: next.hasMore && next.items.isNotEmpty,
          loadingMore: false,
          total: next.total,
          meta: next.extraMeta,
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

  /// Locally replace items (e.g. after marking a notification read).
  void updateItems(List<T> Function(List<T> items) update) {
    final current = state.value;
    if (current == null) return;
    state = AsyncData(current.copyWith(items: update(current.items)));
  }
}

/// Footer that triggers [onLoadMore] when it becomes visible.
class LoadMoreFooter extends StatelessWidget {
  const LoadMoreFooter({super.key, required this.state, required this.onLoadMore});

  final PagedState<Object?> state;
  final VoidCallback onLoadMore;

  @override
  Widget build(BuildContext context) {
    if (state.loadMoreError != null) {
      return Padding(
        padding: const EdgeInsets.all(AppSpace.md),
        child: ErrorState(error: state.loadMoreError, onRetry: onLoadMore, compact: true),
      );
    }
    if (!state.hasMore) return const SizedBox(height: AppSpace.xl);
    // Trigger after the frame to avoid setState during build.
    WidgetsBinding.instance.addPostFrameCallback((_) => onLoadMore());
    return const Padding(
      padding: EdgeInsets.all(AppSpace.lg),
      child: Center(child: SizedBox(width: 24, height: 24, child: CircularProgressIndicator(strokeWidth: 2))),
    );
  }
}

/// Scroll listener helper: calls [onLoadMore] near the end of the list.
bool handleLoadMoreScroll(ScrollNotification n, VoidCallback onLoadMore) {
  if (n.metrics.extentAfter < 600) onLoadMore();
  return false;
}
