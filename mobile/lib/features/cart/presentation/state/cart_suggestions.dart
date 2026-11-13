import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/cart/data/cart_repository.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "You may also need": the shop's suggestions for what is in the bag. Reloads when the bag's contents change.
/// A failure just hides the strip; it is never worth an error screen.
final cartSuggestionsProvider = FutureProvider.autoDispose<List<ProductSummary>>((ref) async {
  if (!ref.watch(isAuthenticatedProvider)) return const [];
  ref.watch(localeCodeProvider);
  ref.watch(cartControllerProvider.select((c) => c.value?.items.map((i) => i.variantId).join(',') ?? ''));
  try {
    return await ref.read(cartRepositoryProvider).suggestions();
  } on ApiException {
    return const [];
  }
});
