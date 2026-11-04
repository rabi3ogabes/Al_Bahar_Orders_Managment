import 'dart:typed_data';

import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class OrderRepository {
  OrderRepository(this._api);
  final ApiClient _api;

  /// Max. backend pages scanned per call when filtering client-side.
  static const _maxScanPages = 5;

  /// `GET /orders?status=` accepts exactly one status. For a group of
  /// statuses the app pages through the unfiltered list and keeps matching
  /// rows, scanning ahead (up to [_maxScanPages]) until a page yields a match
  /// so infinite scroll never stalls on an empty page. The returned
  /// `currentPage` is the last backend page read, so `page + 1` continues.
  Future<Paginated<OrderSummary>> list({Set<String>? statuses, int page = 1}) async {
    if (statuses == null || statuses.isEmpty || statuses.length == 1) {
      return _api.getPage('/orders', OrderSummary.fromJson, query: {'status': statuses?.firstOrNull, 'page': page});
    }
    final matches = <OrderSummary>[];
    var next = page;
    late Paginated<OrderSummary> last;
    for (var i = 0; i < _maxScanPages; i++) {
      last = await _api.getPage('/orders', OrderSummary.fromJson, query: {'page': next, 'per_page': 50});
      matches.addAll(last.items.where((o) => statuses.contains(o.status)));
      if (matches.isNotEmpty || !last.hasMore) break;
      next = last.currentPage + 1;
    }
    return Paginated(
      items: matches,
      currentPage: last.currentPage,
      lastPage: last.lastPage,
      perPage: last.perPage,
      total: matches.length, // unknown for client-side groups
      extraMeta: last.extraMeta,
    );
  }

  Future<Order> get(String id) => _api.getObject('/orders/$id', Order.fromJson);

  Future<List<TimelineEntry>> timeline(String id) => _api.getList('/orders/$id/timeline', TimelineEntry.fromJson);

  /// Returns `approved` or `requested`.
  Future<String> cancel(String id, {required String reasonCode, String? reason}) async {
    final data = await _api.post(
      '/orders/$id/cancel',
      data: {'reason_code': reasonCode, if (reason != null && reason.isNotEmpty) 'reason': reason},
    );
    return asString(asMap(data)['status'], 'requested');
  }

  Future<ReorderResult> reorder(String id) async =>
      ReorderResult.fromJson(asMap(await _api.post('/orders/$id/reorder')));

  Future<Uint8List> invoice(String id) => _api.getBytes('/orders/$id/invoice');

  Future<PickupPass> pickupPass(String orderId, String fulfillmentId) =>
      _api.getObject('/orders/$orderId/fulfillments/$fulfillmentId/pickup-pass', PickupPass.fromJson);

  Future<void> requestReturn(
    String orderId, {
    required List<({String orderItemId, int quantity})> items,
    required String reasonCode,
    String? reason,
  }) async {
    await _api.post(
      '/orders/$orderId/returns',
      data: {
        'items': [
          for (final i in items) {'order_item_id': i.orderItemId, 'quantity': i.quantity},
        ],
        'reason_code': reasonCode,
        if (reason != null && reason.isNotEmpty) 'reason': reason,
      },
    );
  }
}

final orderRepositoryProvider = Provider<OrderRepository>((ref) => OrderRepository(ref.watch(apiClientProvider)));
