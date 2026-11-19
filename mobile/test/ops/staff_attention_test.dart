import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

const _phone = Variant('390x1400 en', Size(390, 1400), kEn);
const _phoneAr = Variant('390x1400 ar', Size(390, 1400), kAr);

Future<void> _pump(WidgetTester tester, FakeOps repo, {Variant variant = _phone}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: variant, role: OpsRole.staff));
  await settle(tester);
}

Finder get _strip => find.byKey(const ValueKey('attention-strip'));

FakeOps _repo(List<Map<String, dynamic>> items, {Map<String, bool>? can, int? total}) => FakeOps()
  ..overviewData = staffOverview(can: can, fresh: 1, ready: 1, pickupReady: 1)
  ..attentionData = attentionOf(items, total: total)
  ..queues = {
    'new': [staffOrder(id: 'f1', number: 'AB100001', clock: clockJson('red', 20))],
    'ready': [
      staffOrder(
        id: 'f2',
        number: 'AB100002',
        status: 'ready',
        clock: clockJson('amber', 40, kind: 'ready', amberAt: 30, redAt: 60),
      ),
    ],
    'preparing': [
      staffOrder(
        id: 'f4',
        number: 'AB100004',
        status: 'preparing',
        orderId: 'o-cancel',
        cancellation: cancellationJson(),
        clock: clockJson('ok', 5, kind: 'preparing', amberAt: 45, redAt: 90),
      ),
    ],
  }
  ..pickupQueue = [
    staffOrder(
      id: 'f3',
      number: 'AB100003',
      type: 'pickup',
      status: 'pickup_ready',
      clock: clockJson('amber', 3000, kind: 'pickup', amberAt: 2880, redAt: 5760),
      pickup: {'status': 'ready', 'expired': true},
    ),
  ];

void main() {
  group('the strip', () {
    testWidgets('a manager always has it: "Needs attention (N)", with how many are late', (tester) async {
      await _pump(
        tester,
        _repo([
          attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1'),
          attentionItemJson(
            'undispatched:f2',
            'undispatched',
            target: 'assign_driver',
            state: 'amber',
            subject: 'AB100002',
            fulfillmentId: 'f2',
          ),
        ]),
      );
      expect(find.text('Needs attention (2)'), findsOneWidget);
      expect(find.text('1 late, 1 getting late'), findsOneWidget);
    });

    testWidgets('a manager with nothing waiting still has it, calm: "Needs attention (0)"', (tester) async {
      await _pump(tester, _repo(const []));
      expect(find.text('Needs attention (0)'), findsOneWidget);
    });

    testWidgets('someone who is not a manager and has nothing waiting does not see it', (tester) async {
      await _pump(tester, _repo(const [], can: const {'orders.approve': false}));
      expect(_strip, findsNothing);
    });

    testWidgets('someone who is not a manager sees it once something waits for them', (tester) async {
      await _pump(
        tester,
        _repo(
          [attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1')],
          can: const {'orders.approve': false},
        ),
      );
      expect(find.text('Needs attention (1)'), findsOneWidget);
    });

    testWidgets('while the list cannot be read the strip is simply not there, and the orders are fine', (tester) async {
      final repo = _repo(const [])..attentionError = Exception('down');
      await _pump(tester, repo);
      expect(_strip, findsNothing);
      expect(find.text('#AB100001'), findsOneWidget);
    });

    testWidgets('it is read again whenever an order moves or the screen is refreshed', (tester) async {
      final repo = _repo(const []);
      await _pump(tester, repo);
      final before = repo.attentionLoads;
      await tester.tap(find.byTooltip('Refresh'));
      await settle(tester);
      expect(repo.attentionLoads, greaterThan(before));
    });
  });

  group('the list', () {
    testWidgets(
      'each line says what, which order, how long, and has one button; a line the app cannot handle says so',
      (tester) async {
        await _pump(
          tester,
          _repo([
            attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1', phone: '+97455123456'),
            attentionItemJson(
              'refund:r1',
              'refund',
              target: 'dashboard',
              state: 'amber',
              age: 1500,
              subject: 'AB100009',
              hint: 'This is handled on the dashboard.',
            ),
          ]),
        );
        await tester.tap(_strip);
        await settle(tester);
        expect(find.text('Waiting to be accepted'), findsNWidgets(2));
        expect(find.text('#AB100001'), findsWidgets);
        expect(
          find.descendant(
            of: find.byKey(const ValueKey('attention-unaccepted:f1')),
            matching: find.text('Late · 20 min'),
          ),
          findsOneWidget,
        );
        expect(find.text('Getting late · 1 d 1 h'), findsOneWidget);
        expect(find.widgetWithText(FilledButton, 'Open order'), findsOneWidget);
        expect(find.text('This is handled on the dashboard.'), findsOneWidget);
        expect(find.byTooltip('Call #AB100001'), findsOneWidget);
      },
    );

    testWidgets('calm when it empties while it is open', (tester) async {
      final repo = _repo([attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1')]);
      await _pump(tester, repo);
      await tester.tap(_strip);
      await settle(tester);
      expect(find.text('All clear. Nothing needs you right now.'), findsNothing);
      repo.attentionData = attentionOf(const []);
      final container = ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
      container.invalidate(staffAttentionProvider);
      await settle(tester);
      expect(find.text('All clear. Nothing needs you right now.'), findsOneWidget);
    });

    testWidgets('a long list says the rest is on the dashboard', (tester) async {
      final repo = _repo([attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1')], total: 31);
      await _pump(tester, repo);
      await tester.tap(_strip);
      await settle(tester);
      expect(find.text('The most urgent lines are shown. The dashboard has the rest.'), findsOneWidget);
    });

    testWidgets('a list that cannot be read offers to try again', (tester) async {
      final repo = _repo([attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1')]);
      await _pump(tester, repo);
      repo.attentionError = Exception('down');
      await tester.tap(_strip);
      await settle(tester);
      expect(find.text('Try again'), findsOneWidget);
    });
  });

  group('every line leads to the right place', () {
    Future<void> tapLine(WidgetTester tester, String id, {String button = 'Open order'}) async {
      await tester.tap(_strip);
      await settle(tester);
      await tester.tap(
        find.descendant(of: find.byKey(ValueKey('attention-$id')), matching: find.widgetWithText(FilledButton, button)),
      );
      await settle(tester);
    }

    testWidgets('a new order opens its card in a window', (tester) async {
      await _pump(tester, _repo([attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1')]));
      await tapLine(tester, 'unaccepted:f1');
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.byType(BottomSheet), findsOneWidget);
    });

    testWidgets('a ready order with no driver opens the driver list at once', (tester) async {
      await _pump(
        tester,
        _repo([
          attentionItemJson(
            'undispatched:f2',
            'undispatched',
            target: 'assign_driver',
            subject: 'AB100002',
            fulfillmentId: 'f2',
          ),
        ]),
      );
      await tapLine(tester, 'undispatched:f2', button: 'Assign driver');
      expect(find.text('Choose a driver'), findsOneWidget);
      expect(find.text('For order #AB100002'), findsOneWidget);
    });

    testWidgets('a pickup pass that ran out asks about a new pass at once', (tester) async {
      final repo = _repo([
        attentionItemJson(
          'pickup_waiting:f3',
          'pickup_waiting',
          target: 'reissue_pass',
          subject: 'AB100003',
          fulfillmentId: 'f3',
        ),
      ]);
      await _pump(tester, repo);
      await tapLine(tester, 'pickup_waiting:f3', button: 'Re-issue pass');
      expect(find.text('Issue a new pass?'), findsOneWidget);
      await tester.tap(find.text('Issue new pass'));
      await settle(tester);
      expect(repo.calls, ['pickup-reissue:f3']);
    });

    testWidgets('a cancellation request opens the order by its order id, with the banner and the decision', (
      tester,
    ) async {
      await _pump(
        tester,
        _repo([attentionItemJson('cancellation:c1', 'cancellation', subject: 'AB100004', orderId: 'o-cancel')]),
      );
      await tapLine(tester, 'cancellation:c1');
      expect(find.text('Order #AB100004'), findsOneWidget);
      expect(find.byKey(const ValueKey('cancellation-approve')), findsOneWidget);
    });

    testWidgets('a line whose order is not in the list says so in plain words instead of doing nothing', (
      tester,
    ) async {
      await _pump(
        tester,
        _repo([attentionItemJson('unaccepted:zz', 'unaccepted', fulfillmentId: 'zz', subject: 'AB999999')]),
      );
      await tapLine(tester, 'unaccepted:zz');
      expect(find.text('That order is no longer in your queue.'), findsOneWidget);
      expect(find.byType(BottomSheet), findsNothing);
    });
  });

  group('every phone, language and theme', () {
    for (final v in variants) {
      testWidgets('the strip and the list fit, with 48 dp targets and labels: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(
          tester,
          _repo([
            attentionItemJson('unaccepted:f1', 'unaccepted', fulfillmentId: 'f1', phone: '+97455123456'),
            attentionItemJson(
              'refund:r1',
              'refund',
              target: 'dashboard',
              state: 'amber',
              subject: 'AB100009',
              hint: 'x',
            ),
          ]),
          variant: v,
        );
        await tester.tap(_strip);
        await settle(tester);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }

    testWidgets('Arabic: the strip and the list are Arabic', (tester) async {
      await _pump(tester, _repo(const []), variant: _phoneAr);
      expect(find.text('تحتاج متابعة (0)'), findsOneWidget);
      await tester.tap(_strip);
      await settle(tester);
      expect(find.text('كل شيء على ما يرام. لا شيء يحتاجك الآن.'), findsOneWidget);
    });
  });
}
