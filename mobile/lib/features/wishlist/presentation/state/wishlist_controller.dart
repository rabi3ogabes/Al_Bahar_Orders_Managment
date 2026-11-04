import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/wishlist/data/wishlist_repository.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class WishlistController extends AsyncNotifier<List<WishlistEntry>> {
  @override
  Future<List<WishlistEntry>> build() async {
    if (!ref.watch(isAuthenticatedProvider)) return const [];
    ref.watch(localeCodeProvider);
    ref.watch(preferredBranchIdProvider);
    return ref.read(wishlistRepositoryProvider).list();
  }

  bool contains(String productId) => state.value?.any((e) => e.product.id == productId) ?? false;

  /// Optimistic toggle. Returns the new "is saved" value.
  Future<bool> toggle(ProductSummary product, {String? variantId}) async {
    final repo = ref.read(wishlistRepositoryProvider);
    final previous = state.value ?? const [];
    final saved = previous.any((e) => e.product.id == product.id);
    state = AsyncData(
      saved
          ? previous.where((e) => e.product.id != product.id).toList()
          : [WishlistEntry(id: 'local-${product.id}', product: product, variantId: variantId, addedAt: DateTime.now()), ...previous],
    );
    try {
      if (saved) {
        await repo.remove(product.id);
      } else {
        await repo.add(product.id, variantId: variantId);
      }
      return !saved;
    } catch (_) {
      if (ref.mounted) state = AsyncData(previous);
      rethrow;
    }
  }
}

final wishlistControllerProvider = AsyncNotifierProvider<WishlistController, List<WishlistEntry>>(WishlistController.new);

final wishlistIdsProvider = Provider<Set<String>>(
  (ref) => {for (final e in ref.watch(wishlistControllerProvider).value ?? const <WishlistEntry>[]) e.product.id},
);
