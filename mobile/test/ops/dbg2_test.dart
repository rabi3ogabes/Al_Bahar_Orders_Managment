import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:albahar_customer/ops/driver/driver_tracker.dart';
import 'package:geolocator/geolocator.dart';
import 'ops_test_kit.dart';

void main() {
  testWidgets('dbg', (tester) async {
    final repo = FakeOps();
    final fixes = StreamController<Position>();
    final tracker = DriverTracker(repo, positions: (_) => fixes.stream);
    await tracker.start(title: 't', body: 'b');
    debugPrint('1');
    await tester.pump(const Duration(seconds: 1));
    debugPrint('2');
    await tracker.stop();
    debugPrint('3');
    await tester.pump(const Duration(seconds: 1));
    debugPrint('4');
    await tester.pump(const Duration(seconds: 130));
    debugPrint('5');
  });
}
