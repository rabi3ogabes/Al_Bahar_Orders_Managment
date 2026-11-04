import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

@immutable
class WishlistEntry {
  const WishlistEntry({required this.id, required this.product, this.variantId, this.addedAt});
  factory WishlistEntry.fromJson(JsonMap json) => WishlistEntry(
    id: asString(json['id']),
    product: ProductSummary.fromJson(asMap(json['product'])),
    variantId: asStringOrNull(json['variant_id']),
    addedAt: asDate(json['added_at']),
  );
  final String id;
  final ProductSummary product;
  final String? variantId;
  final DateTime? addedAt;
}

class WishlistRepository {
  WishlistRepository(this._api);
  final ApiClient _api;

  /// `GET /me/wishlist` is a plain `{data: [...]}` list (not paginated). A row's
  /// `product` is `null` when the product is no longer presentable; such rows
  /// are skipped.
  Future<List<WishlistEntry>> list({String? branchId}) async => mapList(
    await _api.get('/me/wishlist', query: {'branch_id': branchId}),
    (j) => j,
  ).where((j) => j['product'] is Map).map(WishlistEntry.fromJson).toList();

  Future<void> add(String productId, {String? variantId}) async =>
      _api.post('/me/wishlist', data: {'product_id': productId, 'variant_id': ?variantId});

  Future<void> remove(String productId) async => _api.delete('/me/wishlist/$productId');
}

final wishlistRepositoryProvider = Provider<WishlistRepository>(
  (ref) => WishlistRepository(ref.watch(apiClientProvider)),
);
