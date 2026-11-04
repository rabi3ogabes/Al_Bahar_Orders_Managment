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

  Future<List<WishlistEntry>> list() async =>
      (await _api.getPage('/me/wishlist', WishlistEntry.fromJson, query: {'per_page': 100})).items;

  Future<void> add(String productId, {String? variantId}) async =>
      _api.post('/me/wishlist', data: {'product_id': productId, 'variant_id': ?variantId});

  Future<void> remove(String productId) async => _api.delete('/me/wishlist/$productId');
}

final wishlistRepositoryProvider = Provider<WishlistRepository>((ref) => WishlistRepository(ref.watch(apiClientProvider)));
