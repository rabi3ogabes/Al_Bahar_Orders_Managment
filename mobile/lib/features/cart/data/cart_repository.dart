import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Server-side cart. The client only sends identifiers and quantities —
/// prices, stock, discounts and fees always come back from the API.
class CartRepository {
  CartRepository(this._api);
  final ApiClient _api;

  Future<Cart> fetch({String? branchId}) async =>
      Cart.fromJson(asMap(await _api.get('/cart', query: {'branch_id': branchId})));

  /// Products the shop suggests for what is in the bag (never what is already in it).
  Future<List<ProductSummary>> suggestions() => _api.getList('/cart/suggestions', ProductSummary.fromJson);

  Future<Cart?> addItem(String variantId, int quantity) async =>
      _cartOrNull(await _api.post('/cart/items', data: {'variant_id': variantId, 'quantity': quantity}));

  Future<Cart?> updateItem(String itemId, int quantity) async =>
      _cartOrNull(await _api.patch('/cart/items/$itemId', data: {'quantity': quantity}));

  Future<Cart?> removeItem(String itemId) async => _cartOrNull(await _api.delete('/cart/items/$itemId'));

  /// `PUT /cart` — pass only the keys to change. `coupon_code: null` removes it.
  Future<Cart?> update(JsonMap changes) async => _cartOrNull(await _api.put('/cart', data: changes));

  /// `PUT /cart` stores any `coupon_code` without validating it; the backend
  /// reports coupon problems only as quote warnings (`code: coupon_*`). This
  /// asks `POST /checkout/quote` (cart defaults) and returns that issue, or
  /// null when the coupon is fine or could not be evaluated.
  Future<QuoteIssue?> couponIssue(String code) async {
    final quote = Quote.fromJson(asMap(await _api.post('/checkout/quote', data: {'coupon_code': code})));
    return quote.couponIssue;
  }

  Future<Cart?> merge(String guestToken) async =>
      _cartOrNull(await _api.post('/cart/merge', data: {'guest_token': guestToken}));

  /// Mutations are assumed to return the updated Cart; if a backend returns
  /// something else (e.g. 204 or the item), callers re-fetch.
  static Cart? _cartOrNull(Object? data) {
    final map = asMapOrNull(data);
    if (map == null) return null;
    if (map['items'] is List) return Cart.fromJson(map);
    if (map['cart'] is Map) return Cart.fromJson(asMap(map['cart']));
    return null;
  }
}

final cartRepositoryProvider = Provider<CartRepository>((ref) => CartRepository(ref.watch(apiClientProvider)));
