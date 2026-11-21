import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/checkout/domain/delivery_promise.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "When will I get it?" The server's answer for one product, or for a basket (signed in or not). Only identifiers and quantities go out.
class PromiseRepository {
  PromiseRepository(this._api);
  final ApiClient _api;

  Future<BasketPromise> product(
    String productId, {
    String? variantId,
    int quantity = 1,
    bool assembly = false,
    String? branchId,
    String type = 'delivery',
  }) async => BasketPromise.fromJson(
    asMap(
      await _api.get(
        '/products/$productId/delivery-promise',
        query: {
          'variant_id': variantId,
          'quantity': quantity,
          if (assembly && type == 'delivery') 'assembly': 1,
          'branch_id': branchId,
          'fulfillment_type': type,
        },
      ),
    ),
  );

  Future<BasketPromise> basket(
    List<({String variantId, int quantity, bool assembly})> lines, {
    String type = 'delivery',
    String? branchId,
    String? addressId,
  }) async => BasketPromise.fromJson(
    asMap(
      await _api.post(
        '/cart/promise',
        data: {
          'lines': [
            for (final l in lines)
              {'variant_id': l.variantId, 'quantity': l.quantity, if (l.assembly) 'assembly': true},
          ],
          'fulfillment_type': type,
          'branch_id': ?branchId,
          'address_id': ?addressId,
        },
      ),
    ),
  );
}

final promiseRepositoryProvider = Provider<PromiseRepository>((ref) => PromiseRepository(ref.watch(apiClientProvider)));
