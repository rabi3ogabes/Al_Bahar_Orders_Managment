import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/checkout/data/checkout_repository.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

@immutable
class CheckoutState {
  const CheckoutState({
    required this.options,
    this.quote,
    this.quoting = false,
    this.quoteError,
    this.placing = false,
    this.placeError,
    this.quoteChanged = false,
    this.previousTotal,
    this.notes = '',
  });

  final CheckoutOptions options;
  final Quote? quote;
  final bool quoting;
  final Object? quoteError;
  final bool placing;
  final Object? placeError;

  /// True after a 409 `quote_changed`: the UI must show the new totals and
  /// require an explicit re-confirmation.
  final bool quoteChanged;
  final Money? previousTotal;
  final String notes;

  /// Client-side precondition check only; the server decides via
  /// `can_place_order`.
  String? get missingRequirement {
    if (options.fulfillmentType == FulfillmentType.delivery && options.addressId == null) return 'address';
    if (options.fulfillmentType == FulfillmentType.pickup && options.branchId == null) return 'branch';
    if (options.paymentMethod == null) return 'payment';
    return null;
  }

  bool get canPlace =>
      quote != null && quote!.canPlaceOrder && !quoting && !placing && missingRequirement == null;

  CheckoutState copyWith({
    CheckoutOptions? options,
    Quote? Function()? quote,
    bool? quoting,
    Object? Function()? quoteError,
    bool? placing,
    Object? Function()? placeError,
    bool? quoteChanged,
    Money? Function()? previousTotal,
    String? notes,
  }) => CheckoutState(
    options: options ?? this.options,
    quote: quote != null ? quote() : this.quote,
    quoting: quoting ?? this.quoting,
    quoteError: quoteError != null ? quoteError() : this.quoteError,
    placing: placing ?? this.placing,
    placeError: placeError != null ? placeError() : this.placeError,
    quoteChanged: quoteChanged ?? this.quoteChanged,
    previousTotal: previousTotal != null ? previousTotal() : this.previousTotal,
    notes: notes ?? this.notes,
  );
}

/// Drives the checkout: options → server quote → place order.
class CheckoutController extends Notifier<CheckoutState> {
  int _quoteSeq = 0;
  final Map<String, String> _idempotencyKeys = {};

  @override
  CheckoutState build() {
    final cart = ref.read(cartControllerProvider).value;
    final address = ref.read(defaultAddressProvider);
    final branchId = ref.read(preferredBranchIdProvider);
    final type = FulfillmentType.parse(cart?.fulfillmentType) ?? FulfillmentType.delivery;
    return CheckoutState(
      options: CheckoutOptions(
        fulfillmentType: type,
        addressId: cart?.addressId ?? address?.id,
        branchId: cart?.branchId ?? branchId,
        couponCode: cart?.couponCode,
      ),
    );
  }

  CheckoutRepository get _repo => ref.read(checkoutRepositoryProvider);

  /// Fetches a fresh quote for the current options. Stale responses (from
  /// superseded option changes) are discarded. If the server's answer makes
  /// the chosen payment method / points invalid, options are corrected and
  /// re-quoted once so the displayed totals always match what will be sent.
  Future<void> refreshQuote({bool allowCorrection = true}) async {
    final seq = ++_quoteSeq;
    final quotedWith = state.options;
    state = state.copyWith(quoting: true, quoteError: () => null);
    try {
      final quote = await _repo.quote(quotedWith);
      if (!ref.mounted || seq != _quoteSeq) return;
      final corrected = _correct(quotedWith, quote);
      state = state.copyWith(quote: () => quote, quoting: false, options: corrected);
      if (allowCorrection && corrected != quotedWith) await refreshQuote(allowCorrection: false);
    } catch (e) {
      if (!ref.mounted || seq != _quoteSeq) return;
      state = state.copyWith(quoting: false, quoteError: () => e);
    }
  }

  static CheckoutOptions _correct(CheckoutOptions options, Quote quote) {
    var next = options;
    final pm = next.paymentMethod;
    if (pm != null && !(quote.option(pm)?.available ?? false)) {
      next = next.copyWith(paymentMethod: () => null);
    }
    if (next.paymentMethod == null) {
      final first = quote.paymentMethods.where((p) => p.available && p.method != null).firstOrNull;
      if (first != null) next = next.copyWith(paymentMethod: () => first.method);
    }
    final max = quote.loyalty?.maxRedeemablePoints;
    if (max != null && next.loyaltyPoints > max) next = next.copyWith(loyaltyPoints: max);
    return next;
  }

  Future<void> update(CheckoutOptions Function(CheckoutOptions o) change) async {
    final next = change(state.options);
    if (next == state.options) return;
    state = state.copyWith(options: next, quoteChanged: false, previousTotal: () => null);
    await refreshQuote();
  }

  void setNotes(String notes) => state = state.copyWith(notes: notes);

  /// Clears the "totals changed" banner once the customer has reviewed it.
  void acknowledgeQuoteChange() => state = state.copyWith(quoteChanged: false, previousTotal: () => null);

  /// Places the order. Returns null when the quote changed (state then holds
  /// the new quote with `quoteChanged = true`) or on error (`placeError`).
  Future<PlacedOrder?> placeOrder() async {
    final quote = state.quote;
    if (quote == null || state.placing) return null;
    state = state.copyWith(placing: true, placeError: () => null);
    // One key per confirmed quote: network retries of the same confirmation
    // reuse it (no duplicate orders); a changed quote gets a new key.
    final key = _idempotencyKeys.putIfAbsent(quote.quoteHash, Idempotency.newKey);
    try {
      final placed = await _repo.placeOrder(
        state.options,
        quoteHash: quote.quoteHash,
        idempotencyKey: key,
        customerNotes: state.notes,
      );
      if (!ref.mounted) return placed;
      state = state.copyWith(placing: false);
      // Cart was converted into the order server-side.
      ref.invalidate(cartControllerProvider);
      return placed;
    } on ApiException catch (e) {
      if (!ref.mounted) return null;
      if (e.statusCode == 409 && e.code == 'quote_changed') {
        final fresh = e.context['quote'];
        if (fresh is Map) {
          state = state.copyWith(
            placing: false,
            quote: () => Quote.fromJson(asMap(fresh)),
            quoteChanged: true,
            previousTotal: () => quote.totals.grandTotal,
          );
        } else {
          state = state.copyWith(placing: false, quoteChanged: true, previousTotal: () => quote.totals.grandTotal);
          await refreshQuote();
        }
        return null;
      }
      state = state.copyWith(placing: false, placeError: () => e);
      return null;
    } catch (e) {
      if (ref.mounted) state = state.copyWith(placing: false, placeError: () => e);
      return null;
    }
  }
}

final checkoutControllerProvider = NotifierProvider.autoDispose<CheckoutController, CheckoutState>(
  CheckoutController.new,
);
