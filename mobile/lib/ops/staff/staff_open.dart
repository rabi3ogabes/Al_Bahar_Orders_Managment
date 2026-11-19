import 'dart:async';

import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_panels.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_rights.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Which order a tapped alert (or a line of the needs-attention list) points at: the part (preferred: it is the branch's own row), else the order.
@immutable
class StaffOrderRef {
  const StaffOrderRef({this.fulfillmentId, this.orderId, this.branchId});
  final String? fulfillmentId;
  final String? orderId;
  final String? branchId;

  bool matches(QueueOrder o) {
    final f = fulfillmentId;
    if (f != null && f.isNotEmpty) return o.fulfillmentId == f;
    final id = orderId;
    return id != null && id.isNotEmpty && o.orderId == id;
  }
}

/// The in-app location of a tapped `staff.order_new` / `staff.order_reminder` push (see `notificationLocation`): `/staff/orders/{orderId}` with the
/// part and the branch as query values. Null for any other location (a customer link, the notification centre).
StaffOrderRef? staffOrderFromLocation(String location) {
  final uri = Uri.tryParse(location);
  if (uri == null) return null;
  final segments = uri.pathSegments;
  if (segments.length != 3 || segments[0] != 'staff' || segments[1] != 'orders' || segments[2].isEmpty) return null;
  String? value(String key) {
    final v = uri.queryParameters[key];
    return v == null || v.isEmpty ? null : v;
  }

  return StaffOrderRef(orderId: segments[2], fulfillmentId: value('fulfillment'), branchId: value('branch'));
}

/// Waits (at most [timeout]) until every list of the screen is up to date, so an order that is merely not read yet is not mistaken for one that is gone.
Future<LiveList<QueueOrder>> _settledLookup(WidgetRef ref, {Duration timeout = const Duration(seconds: 12)}) async {
  final done = Completer<LiveList<QueueOrder>>();
  final sub = ref.listenManual<LiveList<QueueOrder>>(staffOrderLookupProvider, (_, next) {
    if (next.settled && !done.isCompleted) done.complete(next);
  }, fireImmediately: true);
  try {
    return await done.future.timeout(timeout, onTimeout: () => sub.read());
  } finally {
    sub.close();
  }
}

/// Opens an order's window by its ids: switches to its branch when it is another of the person's, waits for the lists to be read, then opens it.
/// Says in plain words when the order is not in the person's list (a colleague finished it, or it is beyond the first page). [assign] / [reissue]
/// start the driver list / the "new pass" question at once. True when the window was opened.
Future<bool> openStaffOrder(
  BuildContext context,
  WidgetRef ref,
  StaffOrderRef target, {
  bool assign = false,
  bool reissue = false,
}) async {
  final branch = target.branchId;
  if (branch != null && branch.isNotEmpty && branch != ref.read(staffBranchIdProvider)) {
    final mine = ref.read(staffOverviewProvider).value?.branches ?? const <StaffBranchRef>[];
    if (mine.any((b) => b.id == branch)) ref.read(staffBranchChoiceProvider.notifier).choose(branch);
  }
  final lookup = await _settledLookup(ref);
  final order = lookup.items.where(target.matches).firstOrNull;
  if (!context.mounted) return false;
  if (order == null) {
    final t = OpsStrings.of(context);
    showInfoSnack(context, lookup.settled ? t.orderNoLongerHere : t.orderNotInList);
    return false;
  }
  unawaited(
    showOrderPanel(
      context,
      order: order,
      rights: ref.read(staffRightsProvider),
      autoAssign: assign,
      autoReissue: reissue,
    ),
  );
  return true;
}
