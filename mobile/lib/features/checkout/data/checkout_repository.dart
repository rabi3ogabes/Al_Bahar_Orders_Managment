import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class CheckoutRepository {
  CheckoutRepository(this._api);
  final ApiClient _api;

  /// [extra] carries what is not part of the priced options, such as the customer's second phone number for the driver.
  Future<Quote> quote(CheckoutOptions options, {JsonMap extra = const {}}) async =>
      Quote.fromJson(asMap(await _api.post('/checkout/quote', data: {...options.toJson(), ...extra})));

  /// Throws `ApiException(409, quote_changed)` with `context.quote` when the
  /// totals differ from [quoteHash]. Same [idempotencyKey] ⇒ same order.
  Future<PlacedOrder> placeOrder(
    CheckoutOptions options, {
    required String quoteHash,
    required String idempotencyKey,
    String? customerNotes,
    JsonMap extra = const {},
  }) async {
    final data = await _api.post(
      '/checkout/orders',
      data: {
        ...options.toJson(),
        ...extra,
        'quote_hash': quoteHash,
        if (customerNotes != null && customerNotes.trim().isNotEmpty) 'customer_notes': customerNotes.trim(),
      },
      options: Idempotency.options(idempotencyKey),
    );
    return PlacedOrder.fromJson(asMap(data));
  }

  Future<PaymentStatus> payment(String paymentId) => _api.getObject('/payments/$paymentId', PaymentStatus.fromJson);

  Future<PaymentAction> retryPayment(String orderId, {required String idempotencyKey, String? method}) async {
    final data = await _api.post(
      '/orders/$orderId/payments',
      data: {'method': ?method},
      options: Idempotency.options(idempotencyKey),
    );
    return PaymentAction.fromJson(asMap(data));
  }
}

final checkoutRepositoryProvider = Provider<CheckoutRepository>(
  (ref) => CheckoutRepository(ref.watch(apiClientProvider)),
);
