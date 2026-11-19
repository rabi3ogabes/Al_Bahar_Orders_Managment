import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';

/// Why a payment method is not offered, as one of a few kinds the screen turns into a kind sentence. The quote names the reason
/// (`payment_methods[].reason`); every one the server can send is here (see QuoteService::paymentMethods), plus the older
/// `exceeds_cod_limit`. A reason from a newer server becomes [unavailable] ("Not available for this order"), never its code.
enum PaymentReasonKind {
  /// Cash on delivery: the basket is above the highest amount it takes. [PaymentReason.limit] is that amount.
  codMax,

  /// Cash on delivery: the basket is below the lowest amount it takes. [PaymentReason.limit] is that amount.
  codMin,

  /// Cash on delivery does not take a basket of this size (the server did not say the limit).
  codOutside,
  onlineDown,
  deliveryOnly,
  pickupOnly,
  notOfferedInArea,
  notOfferedAtBranch,
  unavailable,
}

class PaymentReason {
  const PaymentReason(this.kind, {this.limit, this.total});
  final PaymentReasonKind kind;

  /// The limit that was crossed (codMax / codMin).
  final Money? limit;

  /// The basket total the limit was checked against (codMax / codMin).
  final Money? total;
}

/// The reason behind an unavailable [option]. [total] is the quote's grand total, which the cash-on-delivery limits are checked against.
PaymentReason paymentReasonOf(PaymentOption option, {Money? total}) {
  switch (option.reason) {
    case 'outside_cod_limits':
    case 'exceeds_cod_limit':
      final amount = total?.displayValue;
      final max = option.limitMax;
      final min = option.limitMin;
      if (total != null && amount != null && max != null && amount > max.displayValue) {
        return PaymentReason(PaymentReasonKind.codMax, limit: max, total: total);
      }
      if (total != null && amount != null && min != null && amount < min.displayValue) {
        return PaymentReason(PaymentReasonKind.codMin, limit: min, total: total);
      }
      return const PaymentReason(PaymentReasonKind.codOutside);
    case 'temporarily_unavailable':
      return const PaymentReason(PaymentReasonKind.onlineDown);
    case 'delivery_only':
      return const PaymentReason(PaymentReasonKind.deliveryOnly);
    case 'pickup_only':
      return const PaymentReason(PaymentReasonKind.pickupOnly);
    case 'not_offered_in_area':
      return const PaymentReason(PaymentReasonKind.notOfferedInArea);
    case 'not_offered_at_branch':
      return const PaymentReason(PaymentReasonKind.notOfferedAtBranch);
    default:
      return const PaymentReason(PaymentReasonKind.unavailable);
  }
}
