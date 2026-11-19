import 'package:albahar_customer/features/checkout/domain/payment_reasons.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';

/// The kind sentence for a payment method that is not offered, in the customer's language, with the numbers when there are some
/// ("Cash on delivery is available up to QAR 5,000.00. Your basket is QAR 8,200.00.").
String paymentReasonText(AppLocalizations l10n, PaymentReason r) => switch (r.kind) {
  PaymentReasonKind.codMax => l10n.paymentReasonCodMax(r.limit!.formatted, r.total!.formatted),
  PaymentReasonKind.codMin => l10n.paymentReasonCodMin(r.limit!.formatted, r.total!.formatted),
  PaymentReasonKind.codOutside => l10n.paymentReasonCodOutside,
  PaymentReasonKind.onlineDown => l10n.paymentReasonOnlineDown,
  PaymentReasonKind.deliveryOnly => l10n.paymentReasonDeliveryOnly,
  PaymentReasonKind.pickupOnly => l10n.paymentReasonPickupOnly,
  PaymentReasonKind.notOfferedInArea => l10n.paymentReasonNotInArea,
  PaymentReasonKind.notOfferedAtBranch => l10n.paymentReasonNotAtBranch,
  PaymentReasonKind.unavailable => l10n.paymentUnavailable,
};
