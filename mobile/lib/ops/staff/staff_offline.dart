import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_clock_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The branch staff app keeps no queue of its own: a branch step (accept, ready, assign a driver, hand over, decide a cancellation) must reach the
/// server at once, because the person is at the counter and the customer or the driver is waiting on it. While the tablet has no connection the
/// actions are shown switched off, the main one named, with "Needs a connection"; the lists stay readable (the last good ones, with their time).
class StaffOnlineGate extends ConsumerWidget {
  const StaffOnlineGate({super.key, required this.label, required this.child, this.icon});

  /// The main action of the block, shown switched off.
  final String label;
  final IconData? icon;
  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (ref.watch(onlineProvider)) return child;
    return BlockedAction(
      key: const ValueKey('needs-connection'),
      label: label,
      icon: icon ?? Icons.cloud_off_rounded,
      reason: OpsStrings.of(context).needsConnection,
    );
  }
}
