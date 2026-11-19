import 'dart:async';

import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The one plain sentence for any error (see [DriverStrings.plainError]): never a status code, never English in an Arabic app.
String driverErrorText(BuildContext context, Object error) =>
    OpsStrings.of(context).plainError(error) ?? OpsStrings.of(context).errorFallback;

/// Runs one step of the driver's work; a failure shows as one plain sentence (a toast inside a window, a snack on a page) and the caller gets
/// `false`. Every driver step uses it, so no screen shows a raw server string.
Future<bool> driverGuarded(BuildContext context, Future<void> Function() action) async {
  try {
    await action();
    return true;
  } catch (e) {
    if (context.mounted) showInfoSnack(context, driverErrorText(context, e));
    return false;
  }
}

/// Where the driver stands, with how sure the phone is (metres; null when it did not say).
class DriverSpot {
  const DriverSpot(this.lat, this.lng, [this.accuracy]);
  final double lat;
  final double lng;
  final double? accuracy;
}

/// The driver's position for a step that can use it (the delivery proof, "I have arrived", a failed attempt), or null: a delivery never
/// waits for GPS and never fails without it. The tracker already knows where he is while he is on duty (its newest fix, when it is under
/// 90 seconds old); otherwise one quick look (3 seconds at most). The permission is asked only if the phone has not decided yet.
Future<DriverSpot?> currentSpot(WidgetRef ref) async {
  try {
    final fix = ref.read(driverTrackerProvider).lastFix;
    final seen = fix?.receivedAt;
    if (fix != null && seen != null && DateTime.now().difference(seen) < const Duration(seconds: 90)) {
      return DriverSpot(fix.lat, fix.lng, fix.accuracyM);
    }
    final location = ref.read(locationServiceProvider);
    if (await location.ensurePermission() != LocationAccess.granted) return null;
    final p = await location.currentPosition().timeout(const Duration(seconds: 3), onTimeout: () => null);
    return p == null ? null : DriverSpot(p.lat, p.lng);
  } catch (_) {
    return null;
  }
}
