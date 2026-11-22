import 'dart:typed_data';

import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:dio/dio.dart';
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
  /// Orders still in progress (newest first), for the Home widget.
  Future<List<OrderSummary>> active() async {
    final page = await _api.getPage(
      '/orders',
      OrderSummary.fromJson,
      query: {'status': _activeStatuses.join(','), 'per_page': 5},
    );
    return page.items;
  }

  static const _activeStatuses = [
    'pending_payment',
    'confirmed',
    'processing',
    'partially_fulfilled',
    'ready_for_pickup',
    'out_for_delivery',
  ];

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

  Future<OrderTracking> tracking(String id) => _api.getObject('/orders/$id/tracking', OrderTracking.fromJson);

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

  /// Sends a return or damage claim as multipart (photos up to [maxClaimPhotos]). The same [idempotencyKey] sent twice answers with the claim
  /// already made, so a double tap or a retry after a lost answer never makes a second one.
  Future<ReturnRequestInfo?> requestReturn(
    String orderId, {
    required List<({String orderItemId, int quantity})> items,
    required String reasonCode,
    String? reason,
    List<PickedPhoto> photos = const [],
    String? idempotencyKey,
  }) async {
    final form = FormData();
    for (var i = 0; i < items.length; i++) {
      form.fields
        ..add(MapEntry('items[$i][order_item_id]', items[i].orderItemId))
        ..add(MapEntry('items[$i][quantity]', '${items[i].quantity}'));
    }
    form.fields.add(MapEntry('reason_code', reasonCode));
    if (reason != null && reason.isNotEmpty) form.fields.add(MapEntry('reason', reason));
    for (final p in photos) {
      form.files.add(MapEntry('photos[]', _photo(p)));
    }
    final data = await _api.post(
      '/orders/$orderId/returns',
      data: form,
      options: idempotencyKey == null ? null : Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return ReturnRequestInfo.maybe(data);
  }

  /// The customer's answer to a replacement question: accept it, or have the item removed and refunded. Answering twice the same way is harmless.
  /// Returns the order as it is now.
  Future<Order> answerSubstitution(String orderId, String requestId, {required bool accept}) async {
    final data = asMap(
      await _api.post('/orders/$orderId/substitutions/$requestId', data: {'answer': accept ? 'accept' : 'decline'}),
    );
    return Order.fromJson(asMap(data['order']));
  }

  /// The customer's pick of one of the times offered after a failed delivery. The same [idempotencyKey] sent twice is the same answer (a retry after a
  /// lost reply never books twice). Returns the order as it is now.
  Future<Order> chooseDeliveryTime(
    String orderId,
    String offerId, {
    required String slotId,
    required String date,
    required String idempotencyKey,
  }) async {
    final data = asMap(
      await _api.post(
        '/orders/$orderId/slot-offers/$offerId/choose',
        data: {'slot_id': slotId, 'date': date},
        options: Idempotency.options(idempotencyKey),
      ),
    );
    return Order.fromJson(asMap(data['order']));
  }

  /// More photos and/or a note on a claim that still waits for the team.
  Future<ReturnRequestInfo?> addClaimEvidence(
    String claimId, {
    List<PickedPhoto> photos = const [],
    String? note,
  }) async {
    final form = FormData();
    if (note != null && note.trim().isNotEmpty) form.fields.add(MapEntry('reason', note.trim()));
    for (final p in photos) {
      form.files.add(MapEntry('photos[]', _photo(p)));
    }
    return ReturnRequestInfo.maybe(await _api.post('/returns/$claimId/evidence', data: form));
  }

  /// Withdraws a claim before the team has decided it. Cancelling twice is the same as once.
  Future<ReturnRequestInfo?> cancelClaim(String claimId) async =>
      ReturnRequestInfo.maybe(await _api.post('/returns/$claimId/cancel'));

  /// One photo of the customer's own claim (a private file: it needs the sign-in, so it is never a plain link).
  Future<Uint8List> claimPhoto(String path) => _api.getBytes('/$path', accept: 'image/*');

  MultipartFile _photo(PickedPhoto p) =>
      MultipartFile.fromBytes(p.bytes, filename: p.name, contentType: DioMediaType.parse(p.mimeType ?? 'image/jpeg'));
}

final orderRepositoryProvider = Provider<OrderRepository>((ref) => OrderRepository(ref.watch(apiClientProvider)));
