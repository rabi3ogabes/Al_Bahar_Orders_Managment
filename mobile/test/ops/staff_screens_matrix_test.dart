import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/scan/data/scan_source.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../features/scan/scan_kit.dart';
import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

/// A branch with something of everything: late and calm orders, a customer's request to cancel, pickups in every stage, a pass that ran out,
/// and a needs-attention list.
FakeOps _busy() => FakeOps()
  ..overviewData = staffOverview(
    fresh: 2,
    preparing: 1,
    ready: 1,
    pickupNew: 1,
    pickupPreparing: 1,
    pickupReady: 2,
    branches: const [
      {'id': 'b1', 'name': 'Al Markhiya Main Branch'},
      {'id': 'b2', 'name': 'Wakra'},
    ],
  )
  ..queues = {
    'new': [
      staffOrder(id: 'a', number: 'AB100001', clock: clockJson('red', 40), cancellation: cancellationJson()),
      staffOrder(id: 'b', number: 'AB100002', clock: clockJson('ok', 2)),
    ],
    'preparing': [
      staffOrder(
        id: 'c',
        number: 'AB100003',
        status: 'preparing',
        clock: clockJson('amber', 50, kind: 'preparing', amberAt: 45, redAt: 90),
      ),
    ],
    'ready': [
      staffOrder(
        id: 'd',
        number: 'AB100004',
        status: 'ready',
        clock: clockJson('amber', 35, kind: 'ready', amberAt: 30, redAt: 60),
      ),
    ],
  }
  ..pickupQueue = [
    staffOrder(
      id: 'p1',
      number: 'AB200001',
      type: 'pickup',
      status: 'pending',
      clock: clockJson('red', 22),
      method: 'pay_at_pickup',
      due: '120.00',
    ),
    staffOrder(
      id: 'p2',
      number: 'AB200002',
      type: 'pickup',
      status: 'preparing',
      clock: clockJson('ok', 30, kind: 'preparing', amberAt: 45, redAt: 90),
    ),
    staffOrder(
      id: 'p3',
      number: 'AB200003',
      type: 'pickup',
      status: 'pickup_ready',
      pickup: {'status': 'ready', 'expired': true},
      clock: clockJson('red', 6000, kind: 'pickup', amberAt: 2880, redAt: 5760),
    ),
    staffOrder(
      id: 'p4',
      number: 'AB200004',
      type: 'pickup',
      status: 'pickup_ready',
      pickup: {'status': 'ready', 'expired': false, 'token_expires_at': '2099-01-01T10:00:00+03:00'},
      cancellation: cancellationJson(),
    ),
  ]
  ..attentionData = attentionOf([
    attentionItemJson('unaccepted:a', 'unaccepted', fulfillmentId: 'a', phone: '+97455123456'),
    attentionItemJson(
      'refund:r',
      'refund',
      target: 'dashboard',
      state: 'amber',
      subject: 'AB100009',
      hint: 'This is handled on the dashboard.',
    ),
  ]);

Future<void> _pump(WidgetTester tester, FakeOps repo, Variant v, {List<Override> overrides = const []}) async {
  useVariant(tester, v);
  await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: v, role: OpsRole.staff, overrides: overrides));
  await settle(tester);
}

Future<void> _guidelines(WidgetTester tester) async {
  expect(tester.takeException(), isNull);
  await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
  await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
}

void main() {
  group('the orders screen under every phone, language, text size and theme', () {
    for (final v in variants) {
      testWidgets('deliveries: fits, 48 dp targets, labelled: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(tester, _busy(), v);
        await _guidelines(tester);
        handle.dispose();
      });

      testWidgets('pickups: fits, 48 dp targets, labelled: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(tester, _busy(), v);
        await tester.tap(find.text(v.rtl ? 'استلام · 4' : 'Pickup · 4'));
        await settle(tester);
        await _guidelines(tester);
        handle.dispose();
      });

      testWidgets('the order window of a request to cancel: fits, 48 dp targets, labelled: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(tester, _busy(), v);
        await tester.tap(find.text('#AB100001'));
        await settle(tester);
        await _guidelines(tester);
        handle.dispose();
      });
    }
  });

  group('the pickup counter under every phone, language, text size and theme', () {
    for (final v in variants) {
      testWidgets('scanner, hand-over with money, and the manager\'s hand-over: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        final source = FakeScanSource();
        final repo = _busy()..verifyAnswer = passOk(due: '300.00');
        await _pump(tester, repo, v, overrides: [scanSourceProvider.overrideWithValue(source)]);
        await tester.tap(find.byKey(const ValueKey('scan-pass')));
        await settle(tester);
        await _guidelines(tester);
        source.emit('ABPK1.token');
        await settle(tester);
        await _guidelines(tester);
        handle.dispose();
      });

      testWidgets('a camera that is not allowed: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(
          tester,
          _busy(),
          v,
          overrides: [scanSourceProvider.overrideWithValue(FakeScanSource(camera: ScanCameraState.permissionDenied))],
        );
        await tester.tap(find.byKey(const ValueKey('scan-pass')));
        await settle(tester);
        await _guidelines(tester);
        handle.dispose();
      });
    }
  });

  group('the connection', () {
    testWidgets('offline: a banner says so and offers to try again; back online it goes away', (tester) async {
      final repo = _busy();
      await _pump(tester, repo, const Variant('390x1400 en', Size(390, 1400), kEn));
      expect(find.byKey(const ValueKey('offline-banner')), findsNothing);
      final container = ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
      container.read(onlineProvider.notifier).set(false);
      await settle(tester);
      expect(find.text('You are offline. Showing the last list.'), findsOneWidget);
      expect(find.text('#AB100001'), findsOneWidget, reason: 'the last list stays');
      final before = repo.queueLoads;
      await tester.tap(
        find.descendant(of: find.byKey(const ValueKey('offline-banner')), matching: find.text('Try again')),
      );
      await settle(tester);
      expect(repo.queueLoads, greaterThan(before));
      container.read(onlineProvider.notifier).set(true);
      await settle(tester);
      expect(find.byKey(const ValueKey('offline-banner')), findsNothing);
    });

    testWidgets('a list that cannot be read keeps the last orders with the usual "Could not refresh" strip', (
      tester,
    ) async {
      final repo = _busy();
      await _pump(tester, repo, const Variant('390x1400 en', Size(390, 1400), kEn));
      final container = ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
      repo.queueError = Exception('down');
      container.invalidate(staffQueueProvider('new'));
      await settle(tester);
      expect(find.text('Could not refresh. Showing the last list.'), findsOneWidget);
      expect(find.text('#AB100002'), findsOneWidget);
    });

    testWidgets('Arabic: the offline banner is Arabic', (tester) async {
      await _pump(tester, _busy(), const Variant('390x1400 ar', Size(390, 1400), kAr));
      ProviderScope.containerOf(tester.element(find.byType(StaffHome))).read(onlineProvider.notifier).set(false);
      await settle(tester);
      expect(find.text('أنت غير متصل. تُعرض آخر قائمة.'), findsOneWidget);
    });
  });
}
