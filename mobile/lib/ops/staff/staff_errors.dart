import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/widgets.dart';

/// Tells the person what went wrong in plain words: the staff sentence for the answers the counter meets (an order that is not paid yet, a
/// customer's cancellation waiting for a manager, an order a colleague already moved, a right they do not have here), and for anything else
/// the server's own message, which already comes in the app's language.
void showStaffError(BuildContext context, Object error) {
  final text = OpsStrings.of(context).errorFor(error);
  if (text == null) {
    showErrorSnack(context, error);
  } else {
    showInfoSnack(context, text);
  }
}

/// Runs an action and says in plain words when it fails; true when it worked.
Future<bool> staffGuarded(BuildContext context, Future<void> Function() action) async {
  try {
    await action();
    return true;
  } catch (e) {
    if (context.mounted) showStaffError(context, e);
    return false;
  }
}
