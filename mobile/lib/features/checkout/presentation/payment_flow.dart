import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

/// Opens SADAD in a secure in-app browser (Custom Tabs on Android,
/// SFSafariViewController on iOS). The redirect itself never proves payment;
/// the status screen polls `GET /payments/{id}`.
Future<bool> openSecurePayment(PaymentAction action) async {
  final url = action.redirectUrl;
  if (url == null) return false;
  final uri = Uri.parse(url);
  try {
    final ok = await launchUrl(uri, mode: LaunchMode.inAppBrowserView);
    if (ok) return true;
  } catch (_) {}
  return launchUrl(uri, mode: LaunchMode.externalApplication);
}

/// After an order is created: SADAD → browser + status polling; otherwise
/// straight to the order with a confirmation.
Future<void> continueAfterOrder(BuildContext context, WidgetRef ref, PlacedOrder placed) async {
  final payment = placed.payment;
  final statusLocation = Uri(
    path: '/payments/${payment?.paymentId ?? 'none'}/return',
    queryParameters: {'order_id': placed.orderId, 'number': placed.orderNumber},
  ).toString();

  if (payment != null && payment.requiresRedirect) {
    final opened = await openSecurePayment(payment);
    if (!context.mounted) return;
    if (!opened) showErrorSnack(context, StateError('launch'));
    context.go(statusLocation);
    return;
  }
  context.go('/orders/${placed.orderId}?placed=1');
}
