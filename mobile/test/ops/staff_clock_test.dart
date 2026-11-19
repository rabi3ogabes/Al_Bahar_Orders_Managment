import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:albahar_customer/ops/staff/staff_open.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

const _phone = Variant('390x1200 en', Size(390, 1200), kEn);
const _phoneAr = Variant('390x1200 ar', Size(390, 1200), kAr);

Future<ProviderContainer> _pump(
  WidgetTester tester,
  FakeOps repo, {
  Variant variant = _phone,
  List<Override> overrides = const [],
}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(
    opsHost(const StaffHome(), repo, variant: variant, role: OpsRole.staff, overrides: overrides),
  );
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
}

void main() {
  group('the clock of a row', () {
    test('reads the server\'s clock, and an older server\'s row without one has none', () {
      final o = staffOrder(clock: clockJson('amber', 7), placedAt: '2026-11-19T10:00:00+03:00');
      expect(o.clock!.state, OrderClockState.amber);
      expect(o.clock!.ageMinutes, 7);
      expect(o.clock!.amberAt, 5);
      expect(o.type, 'delivery');
      expect(o.placedAt, isNotNull);
      final old = QueueOrder.fromJson({
        'fulfillment_id': 'f',
        'status': 'pending',
        'number': 'AB1',
        'grand_total': '1.00',
      });
      expect(old.clock, isNull);
      expect(old.cancellation, isNull);
      expect(old.type, 'delivery');
      expect(old.isPickup, isFalse);
    });

    test('moves on by itself between two reads, and turns amber then red at the limits', () {
      final read = DateTime(2026, 11, 19, 12);
      final clock = OrderClock.maybeFrom(clockJson('ok', 2), now: read)!;
      expect(clock.at(read).ageMinutes, 2);
      expect(clock.at(read.add(const Duration(minutes: 2, seconds: 50))).ageMinutes, 4);
      final amber = clock.at(read.add(const Duration(minutes: 3)));
      expect((amber.ageMinutes, amber.state), (5, OrderClockState.amber));
      final red = clock.at(read.add(const Duration(minutes: 13)));
      expect((red.ageMinutes, red.state), (15, OrderClockState.red));
    });

    test('stands still while the branch is closed, and never turns a wait nobody acts on amber', () {
      final read = DateTime(2026, 11, 19, 12);
      final paused = OrderClock.maybeFrom(clockJson('ok', 60, paused: true), now: read)!;
      expect(paused.at(read.add(const Duration(hours: 5))).ageMinutes, 60);
      final none = OrderClock.maybeFrom(clockJson('ok', 25, kind: 'none', amberAt: null, redAt: null), now: read)!;
      expect(none.at(read.add(const Duration(hours: 5))).state, OrderClockState.ok);
    });

    test('a state the server called red is never made better locally', () {
      final read = DateTime(2026, 11, 19, 12);
      final clock = OrderClock.maybeFrom(clockJson('red', 40, amberAt: 100, redAt: 200), now: read)!;
      expect(clock.at(read.add(const Duration(minutes: 3))).state, OrderClockState.red);
    });

    test('a row from an older server is measured from when it was placed, never amber or red', () {
      final now = DateTime(2026, 11, 19, 12);
      final c = OrderClock.since(now.subtract(const Duration(hours: 3)), now)!;
      expect((c.ageMinutes, c.state), (180, OrderClockState.ok));
      expect(OrderClock.since(null, now), isNull);
    });

    test('the ages are written in plain words in both languages', () {
      const en = OpsStrings('en');
      const ar = OpsStrings('ar');
      expect(
        [
          for (final m in [0, 1, 12, 60, 65, 1440, 1500, 3 * 1440 + 4 * 60]) en.ageText(m),
        ],
        ['just now', '1 min', '12 min', '1 h', '1 h 05 min', '1 d', '1 d 1 h', '3 d 4 h'],
      );
      expect(ar.ageText(12), '12 د');
      expect(ar.ageText(65), '1 س 05 د');
      expect(en.clockChip(OrderClockState.ok, 12), 'Waiting 12 min');
      expect(en.clockChip(OrderClockState.amber, 22), 'Getting late · 22 min');
      expect(en.clockChip(OrderClockState.red, 40), 'Late · 40 min');
      expect(ar.clockChip(OrderClockState.red, 40), 'متأخر · 40 د');
      expect(en.ageWords(125), '2 hours and 5 minutes');
      expect(ar.ageWords(2), 'دقيقتان');
      expect(ar.ageWords(12), '12 دقيقة');
      expect(ar.ageWords(7), '7 دقائق');
    });
  });

  group('other fields of the staff API', () {
    test('the overview carries the pickup counts, the branches and what the person may do here', () {
      final o = staffOverview(
        pickupNew: 2,
        pickupPreparing: 1,
        pickupReady: 3,
        branches: [
          {'id': 'b1', 'name': 'Markhiya'},
          {'id': 'b2', 'name': 'Wakra'},
        ],
        can: {'pickup.view': true, 'orders.approve': false},
      );
      expect((o.pickupNew, o.pickupPreparing, o.pickupReady), (2, 1, 3));
      expect(o.branches.map((b) => b.name), ['Markhiya', 'Wakra']);
      expect(o.allows('orders.approve'), isFalse);
      expect(o.allows('pickup.view'), isTrue);
      expect(o.allows('nothing'), isNull);
      expect(o.pickupVisible, 6);
      final hidden = staffOverview(pickupNew: 2, can: {'pickup.view': false});
      expect(hidden.pickupVisible, 0);
      expect(hidden.pickupNewVisible, 0);
      // an older server: no such keys
      final old = StaffOverview.fromJson({
        'branch': {'id': 'b', 'name': 'B'},
      });
      expect(old.branches, isEmpty);
      expect(old.can, isNull);
      expect(old.pickupNew, 0);
    });

    test('a row has its cancellation request and its pickup pass', () {
      final o = staffOrder(
        status: 'pickup_ready',
        type: 'pickup',
        cancellation: cancellationJson(),
        pickup: {'status': 'ready', 'token_expires_at': '2026-11-19T10:00:00+03:00', 'expired': true},
        due: '120.00',
        method: 'pay_at_pickup',
      );
      expect(o.isPickup, isTrue);
      expect(o.cancellationOpen, isTrue);
      expect(o.cancellation!.reason, 'Changed my mind');
      expect(o.pickup!.isExpired(DateTime(2020)), isTrue);
      expect(o.dueAmount, 120);
      expect(staffOrder(cancellation: cancellationJson(status: 'rejected')).cancellationOpen, isFalse);
      // the next step of a pickup being prepared is its own button, not the delivery's "ready"
      expect(staffOrder(status: 'preparing', type: 'pickup').nextStep, isNull);
      expect(staffOrder(status: 'preparing').nextStep, 'ready');
    });

    test('a scanned pass: the answer, the lines with what is left, and the money to collect', () {
      final ok = PickupCheck.fromJson({
        'result': 'success',
        'reservation_id': 'rv1',
        'order': {
          'number': 'AB1',
          'customer_name': 'Noura',
          'payment_method': 'pay_at_pickup',
          'payment_status': 'pending',
          'amount_due': {'amount': '300.00', 'currency': 'QAR', 'formatted': 'QAR 300.00'},
        },
        'items': [
          {'id': 'i1', 'name': 'Sofa', 'variant': 'Grey', 'sku': 'S', 'quantity': 3, 'handed_over': 1},
        ],
      });
      expect(ok.ok, isTrue);
      expect(ok.collects, isTrue);
      expect(ok.items.single.left, 2);
      final refused = PickupCheck.fromJson({
        'result': 'expired',
        'reservation_id': null,
        'order': null,
        'items': <Object>[],
      });
      expect((refused.ok, refused.collects), (false, false));
      expect(const PickupCollection(tender: 'cash', amount: '300.00', receiptReference: ' R1 ').toJson(), {
        'tender': 'cash',
        'amount': '300.00',
        'receipt_reference': 'R1',
      });
      expect(const PickupCollection(tender: 'cash', amount: '1.00').toJson().containsKey('receipt_reference'), isFalse);
    });

    test('a needs-attention line says where it leads', () {
      final s = attentionOf([
        attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1'),
        attentionItemJson(
          'refund:r1',
          'refund',
          target: 'dashboard',
          state: 'amber',
          hint: 'This is handled on the dashboard.',
        ),
      ], total: 7);
      expect(s.items.first.target, AttentionTarget.openOrder);
      expect(s.items.last.inApp, isFalse);
      expect((s.total, s.red, s.amber), (7, 1, 1));
      expect(s.worst, OrderClockState.red);
      expect(const AttentionSummary().worst, OrderClockState.ok);
    });
  });

  group('where an alert leads', () {
    test('a staff alert opens the order\'s own window, a customer\'s alert is not touched', () {
      final location = notificationLocation({
        'event': 'staff.order_new',
        'order_id': 'o1',
        'fulfillment_id': 'f1',
        'branch_id': 'b1',
        'deep_link': '/admin/orders?view=o1',
      });
      expect(location, '/staff/orders/o1?fulfillment=f1&branch=b1');
      final ref = staffOrderFromLocation(location)!;
      expect((ref.orderId, ref.fulfillmentId, ref.branchId), ('o1', 'f1', 'b1'));
      expect(notificationLocation({'event': 'staff.order_reminder', 'order_id': 'o2'}), '/staff/orders/o2');
      expect(staffOrderFromLocation('/staff/orders/o2')!.fulfillmentId, isNull);
      expect(notificationLocation({'event': 'order.ready_for_pickup', 'order_id': 'o3'}), '/orders/o3');
      expect(staffOrderFromLocation('/orders/o3'), isNull);
      expect(staffOrderFromLocation('/notifications'), isNull);
      expect(staffOrderFromLocation('/staff/orders/'), isNull);
    });
  });

  group('the age on every card', () {
    testWidgets('green, amber and red, each with its words; the oldest wait is first inside a stage', (tester) async {
      final repo = FakeOps()
        ..queues = {
          'new': [
            staffOrder(id: 'a', number: 'AB100001', clock: clockJson('ok', 2)),
            staffOrder(id: 'b', number: 'AB100002', clock: clockJson('red', 20)),
            staffOrder(id: 'c', number: 'AB100003', clock: clockJson('amber', 7)),
          ],
        };
      await _pump(tester, repo);
      expect(find.text('Waiting 2 min'), findsOneWidget);
      expect(find.text('Getting late · 7 min'), findsOneWidget);
      expect(find.text('Late · 20 min'), findsOneWidget);
      double top(String n) => tester.getTopLeft(find.text('#$n')).dy;
      expect(top('AB100002'), lessThan(top('AB100003')));
      expect(top('AB100003'), lessThan(top('AB100001')));
    });

    testWidgets('the card header turns amber or red when the wait does, and stays calm when it is fine', (
      tester,
    ) async {
      final repo = FakeOps()
        ..queues = {
          'new': [
            staffOrder(id: 'a', number: 'AB100001', clock: clockJson('ok', 2)),
            staffOrder(id: 'b', number: 'AB100002', clock: clockJson('red', 20)),
            staffOrder(id: 'c', number: 'AB100003', clock: clockJson('amber', 7)),
          ],
        };
      await _pump(tester, repo);
      Color? tint(String id) => (tester.widget<Container>(find.byKey(ValueKey('card-header-$id'))).color);
      expect(tint('a'), isNull);
      expect(tint('c'), isNotNull);
      expect(tint('b'), isNotNull);
      expect(tint('c'), isNot(tint('b')));
    });

    testWidgets('the age keeps counting while the screen is open, without asking the server', (tester) async {
      var now = DateTime.now();
      final repo = FakeOps()
        ..queues = {
          'new': [staffOrder(clock: clockJson('ok', 3))],
        };
      await _pump(tester, repo, overrides: [staffClockSourceProvider.overrideWithValue(() => now)]);
      expect(find.text('Waiting 3 min'), findsOneWidget);
      final loads = repo.queueLoads;
      now = now.add(const Duration(minutes: 2, seconds: 20));
      await tester.pump(const Duration(seconds: 31));
      expect(find.text('Getting late · 5 min'), findsOneWidget);
      expect(repo.queueLoads, loads, reason: 'no read of the server for that');
    });

    testWidgets('a part nobody acts on says so calmly, an older server\'s row shows how long since it was placed', (
      tester,
    ) async {
      final repo = FakeOps()
        ..queues = {
          'new': [
            staffOrder(
              id: 'a',
              number: 'AB100001',
              clock: clockJson('ok', 25, kind: 'none', amberAt: null, redAt: null),
            ),
          ],
          'preparing': [
            QueueOrder.fromJson({
              'fulfillment_id': 'b',
              'status': 'preparing',
              'number': 'AB100002',
              'customer_name': 'Hamad',
              'placed_at': DateTime.now().subtract(const Duration(minutes: 9)).toUtc().toIso8601String(),
              'grand_total': '10.00',
            }),
          ],
        };
      await _pump(tester, repo);
      expect(find.text('In progress · 25 min'), findsOneWidget);
      expect(find.text('Placed 9 min ago'), findsOneWidget);
    });

    testWidgets('Arabic: the words and the sentence are Arabic, nothing overflows', (tester) async {
      final repo = FakeOps()
        ..queues = {
          'new': [staffOrder(clock: clockJson('red', 40), cancellation: cancellationJson())],
        };
      await _pump(tester, repo, variant: _phoneAr);
      expect(find.text('متأخر · 40 د'), findsOneWidget);
      expect(find.text('طلب العميل إلغاء الطلب. يجب أن يقرر المدير قبل أن يغادر الطلب الفرع.'), findsWidgets);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a screen reader hears the state in words, not just a colour', (tester) async {
      final handle = tester.ensureSemantics();
      final repo = FakeOps()
        ..queues = {
          'new': [staffOrder(clock: clockJson('red', 40))],
        };
      await _pump(tester, repo);
      expect(find.bySemanticsLabel(RegExp('Late, waiting 40 minutes')), findsOneWidget);
      handle.dispose();
    });
  });
}
