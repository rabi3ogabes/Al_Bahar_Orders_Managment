import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What this employee may do with an order at the branch on screen (read once by the home screen; the server checks every request again).
@immutable
class StaffRights {
  const StaffRights({
    required this.autoDispatch,
    required this.canEdit,
    required this.canAssign,
    required this.canReturn,
    this.branchId,
    this.canPickupView = false,
    this.canPickupEdit = false,
    this.canPickupApprove = false,
    this.canDecideCancellation = false,
  });

  final bool autoDispatch;
  final bool canEdit;
  final bool canAssign;
  final bool canReturn;

  /// The branch on screen (a pass is checked against it).
  final String? branchId;

  /// Check a pass (scan), make a pickup ready and give a new pass, hand over (also without the pass: a manager).
  final bool canPickupView;
  final bool canPickupEdit;
  final bool canPickupApprove;

  /// Approve or decline the customer's request to cancel (orders.approve at this branch).
  final bool canDecideCancellation;
}

/// The rights of the signed-in employee at the branch on screen: what the server says for that branch, else what their permission list says.
final staffRightsProvider = Provider.autoDispose<StaffRights>((ref) {
  final o = ref.watch(staffOverviewProvider).value;
  final perms = ref.watch(staffPermissionsProvider).value ?? const <String>[];
  bool may(String key) => staffMay(key, perms: perms, overview: o);
  return StaffRights(
    autoDispatch: o?.autoDispatch ?? false,
    canEdit: may('orders.edit'),
    canAssign: may('deliveries.assign'),
    canReturn: may('deliveries.edit'),
    branchId: ref.watch(staffBranchIdProvider),
    canPickupView: may('pickup.view'),
    canPickupEdit: may('pickup.edit'),
    canPickupApprove: may('pickup.approve'),
    canDecideCancellation: may('orders.approve'),
  );
});
