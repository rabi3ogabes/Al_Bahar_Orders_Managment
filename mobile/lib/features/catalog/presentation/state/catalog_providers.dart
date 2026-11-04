import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/catalog/data/catalog_repository.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Category tree (cached for the session; refreshes on locale change).
final categoriesProvider = FutureProvider<List<Category>>((ref) {
  ref.watch(localeCodeProvider);
  return ref.watch(catalogRepositoryProvider).categories();
});

final categoryDetailProvider = FutureProvider.autoDispose.family<CategoryDetail, String>((ref, id) {
  ref.watch(localeCodeProvider);
  return ref.watch(catalogRepositoryProvider).category(id);
});

final brandsProvider = FutureProvider<List<Brand>>((ref) {
  ref.watch(localeCodeProvider);
  return ref.watch(catalogRepositoryProvider).brands();
});

/// Finds a category anywhere in the tree (for titles / children).
Category? findCategory(List<Category> tree, String id) {
  for (final c in tree) {
    if (c.id == id || c.slug == id) return c;
    final inner = findCategory(c.children, id);
    if (inner != null) return inner;
  }
  return null;
}

/// Infinite product listing for a [ProductQuery], scoped to the preferred
/// branch and locale.
class ProductListController extends PagedNotifier<ProductSummary> {
  ProductListController(this.query);
  final ProductQuery query;

  @override
  void watchDependencies() {
    ref.watch(localeCodeProvider);
    ref.watch(preferredBranchIdProvider);
  }

  @override
  Future<Paginated<ProductSummary>> fetchPage(int page) => ref
      .read(catalogRepositoryProvider)
      .products(query, branchId: ref.read(preferredBranchIdProvider), page: page);
}

final productListProvider = AsyncNotifierProvider.autoDispose
    .family<ProductListController, PagedState<ProductSummary>, ProductQuery>(ProductListController.new);

final productDetailProvider = FutureProvider.autoDispose.family<ProductDetail, String>((ref, id) {
  ref.watch(localeCodeProvider);
  final branchId = ref.watch(preferredBranchIdProvider);
  return ref.watch(catalogRepositoryProvider).product(id, branchId: branchId);
});

final productAvailabilityProvider = FutureProvider.autoDispose.family<List<BranchStock>, String>((ref, id) {
  ref.watch(localeCodeProvider);
  final branchId = ref.watch(preferredBranchIdProvider);
  return ref.watch(catalogRepositoryProvider).availability(id, branchId: branchId);
});

class ReviewsController extends PagedNotifier<Review> {
  ReviewsController(this.productId);
  final String productId;

  @override
  void watchDependencies() => ref.watch(localeCodeProvider);

  @override
  Future<Paginated<Review>> fetchPage(int page) =>
      ref.read(catalogRepositoryProvider).reviews(productId, page: page);
}

final reviewsProvider = AsyncNotifierProvider.autoDispose.family<ReviewsController, PagedState<Review>, String>(
  ReviewsController.new,
);

final questionsProvider = FutureProvider.autoDispose.family<Paginated<ProductQuestion>, String>(
  (ref, id) => ref.watch(catalogRepositoryProvider).questions(id),
);

/// Recent searches (device-local, max 10).
class RecentSearches extends Notifier<List<String>> {
  @override
  List<String> build() => ref.watch(appPreferencesProvider).recentSearches;

  Future<void> add(String query) async {
    final q = query.trim();
    if (q.isEmpty) return;
    final next = [q, ...state.where((e) => e.toLowerCase() != q.toLowerCase())].take(10).toList();
    state = next;
    await ref.read(appPreferencesProvider).setRecentSearches(next);
  }

  Future<void> remove(String query) async {
    state = state.where((e) => e != query).toList();
    await ref.read(appPreferencesProvider).setRecentSearches(state);
  }

  Future<void> clear() async {
    state = const [];
    await ref.read(appPreferencesProvider).setRecentSearches(const []);
  }
}

final recentSearchesProvider = NotifierProvider<RecentSearches, List<String>>(RecentSearches.new);
