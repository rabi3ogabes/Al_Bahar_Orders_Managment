// "Waiting for the customer": a replacement the customer was asked about. The branch sees it on the order card and in the order window with a
// live clock, and is told that the rest can be prepared while this part cannot be marked ready.

import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

const _phone = Variant('390x1200 en', Size(390, 1200), kEn);
const _phoneAr = Variant('390x1200 ar', Size(390, 1200), kAr);

Future<void> _pump(WidgetTester tester, FakeOps repo, {Variant variant = _phone, DateTime Function()? now}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(
    opsHost(
      const StaffHome(),
      repo,
      variant: variant,
      role: OpsRole.staff,
      overrides: [staffClockSourceProvider.overrideWithValue(now ?? DateTime.now)],
    ),
  );
  await settle(tester);
}

Map<String, dynamic> _waiting(DateTime now, {int count = 1, int seconds = 754}) => {
  'count': count,
  'since': now.subtract(const Duration(minutes: 18)).toUtc().toIso8601String(),
  'deadline_at': now.add(Duration(seconds: seconds)).toUtc().toIso8601String(),
};

void main() {
  group('reading the row', () {
    test('the open questions are read, and a row without any (or from an older server) has none', () {
      final now = DateTime.now();
      final o = staffOrder(status: 'preparing', waiting: _waiting(now, count: 2));
      expect(o.waiting!.count, 2);
      expect(o.waiting!.since, isNotNull);
      expect(o.waiting!.secondsLeft(now), inInclusiveRange(750, 754));
      expect(o.waiting!.secondsLeft(now.add(const Duration(hours: 1))), 0, reason: 'never below zero');
      expect(staffOrder(status: 'preparing').waiting, isNull);
      expect(staffOrder(waiting: {'count': 0}).waiting, isNull);
      expect(o.withState(status: 'preparing').waiting!.count, 2, reason: 'a live update keeps it');
    });
  });

  group('on the order card and in the window', () {
    testWidgets('shows "Waiting for the customer" with the time left, counting down, and the hint in the window', (
      tester,
    ) async {
      var now = DateTime.now();
      final repo = FakeOps()
        ..queues = {
          'preparing': [staffOrder(status: 'preparing', waiting: _waiting(now))],
        };
      await _pump(tester, repo, now: () => now);
      expect(find.byKey(const ValueKey('waiting-for-customer')), findsOneWidget);
      expect(find.textContaining('Waiting for the customer · 12:'), findsOneWidget);
      now = now.add(const Duration(minutes: 1));
      await tester.pump(const Duration(seconds: 31));
      expect(find.textContaining('Waiting for the customer · 11:'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('card-header-f1')));
      await settle(tester);
      expect(find.byKey(const ValueKey('waiting-clock')), findsOneWidget);
      expect(find.textContaining('keep preparing the rest'), findsOneWidget);
      expect(find.text('1 replacement waits for the customer\'s answer'), findsOneWidget);
    });

    testWidgets('an order with nothing to wait for shows no banner', (tester) async {
      final repo = FakeOps()
        ..queues = {
          'preparing': [staffOrder(status: 'preparing')],
        };
      await _pump(tester, repo);
      expect(find.byKey(const ValueKey('waiting-for-customer')), findsNothing);
    });

    testWidgets('Arabic is Arabic and fits', (tester) async {
      final now = DateTime.now();
      final ar = FakeOps()
        ..queues = {
          'preparing': [staffOrder(status: 'preparing', waiting: _waiting(now, count: 3))],
        };
      await _pump(tester, ar, variant: _phoneAr, now: () => now);
      expect(find.textContaining('بانتظار رد العميل'), findsWidgets);
      expect(tester.takeException(), isNull);
    });

    testWidgets('says "Time is up" once the deadline passed', (tester) async {
      final now = DateTime.now();
      final repo = FakeOps()
        ..queues = {
          'preparing': [staffOrder(status: 'preparing', waiting: _waiting(now, seconds: -30))],
        };
      await _pump(tester, repo, now: () => now);
      expect(find.textContaining('Time is up'), findsOneWidget);
    });
  });
}
