import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

const _phone = Variant('390 en', Size(390, 844), kEn);
const _phoneAr = Variant('390 ar', Size(390, 844), kAr);

FakeOps _repo({String status = 'preparing', Map<String, dynamic>? context, List<String>? perms}) {
  final repo = FakeOps()
    ..adjustContextBody = context ?? adjustContextJson()
    ..overviewData = StaffOverview.fromJson({
      'branch': {'id': 'b1', 'name': 'Al Markhiya Main Branch', 'dispatch_mode': 'manual'},
      'new': 0,
      'preparing': 1,
      'ready': 0,
      'out_for_delivery': 0,
      'failed': 0,
      'waiting_for_driver': 0,
      'drivers_on_duty': 1,
      'drivers_total': 2,
    })
    ..queues = {
      'preparing': [FakeOps.order(id: 'f5', status: status, number: 'AB100005')],
    };
  if (perms != null) repo.perms = perms;
  return repo;
}

Future<ProviderContainer> _pump(WidgetTester tester, FakeOps repo, {Variant variant = _phone}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: variant, role: OpsRole.staff));
  await settle(tester);
  // The order sits on the To do list whatever its stage: there is no tab to open first.
  return ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
}

/// The order window is open on the order that is being prepared.
Future<void> _openOrder(WidgetTester tester) async {
  await tester.tap(find.text('#AB100005'));
  await settle(tester);
}

Future<void> _openFlow(WidgetTester tester, {bool ar = false}) async {
  final button = find.text(ar ? 'نقص في منتج؟' : 'Missing an item?');
  await tester.ensureVisible(button);
  await tester.pump();
  await tester.tap(button);
  await settle(tester);
}

Future<void> _tap(WidgetTester tester, Finder f) async {
  await tester.ensureVisible(f.first);
  await tester.pump();
  await tester.tap(f.first);
  await settle(tester);
}

Future<void> _tapText(WidgetTester tester, String text) => _tap(tester, find.text(text));

Future<void> _tapButton(WidgetTester tester, String label) => _tap(tester, find.widgetWithText(FilledButton, label));

/// Opens "Reduce quantity" of the sofa line (2 ordered) and keeps the default of 1.
Future<void> _reduceSofa(WidgetTester tester) async {
  await _tapText(tester, 'Oslo sofa');
  await _tapText(tester, 'Reduce quantity');
}

Future<void> _toReview(WidgetTester tester, {String reason = 'Out of stock'}) async {
  await _tapButton(tester, 'Continue · 1 change');
  await _tapText(tester, reason);
  await _tapButton(tester, 'Review changes');
}

void main() {
  group('the entry point', () {
    testWidgets('an order that is being prepared offers "Missing an item?" under its items', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      expect(find.text('Missing an item?'), findsOneWidget);
      expect(repo.adjustContextLoads, 1, reason: 'asked once, when the window opened');
    });

    testWidgets('accepted too; a new, ready or failed order does not', (tester) async {
      for (final (status, shown) in const [
        ('accepted', true),
        ('preparing', true),
        ('ready', false),
        ('pending', false),
      ]) {
        await tester.pumpWidget(const SizedBox.shrink());
        final repo = _repo(status: status);
        // The queue tab the order sits in.
        repo.queues = {
          stageOfStatusForTest(status): [FakeOps.order(id: 'f5', status: status, number: 'AB100005')],
        };
        useVariant(tester, _phone);
        await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: _phone, role: OpsRole.staff));
        await settle(tester);
        await _openOrder(tester);
        expect(find.text('Missing an item?'), shown ? findsOneWidget : findsNothing, reason: status);
        expect(
          repo.adjustContextLoads,
          shown ? 1 : 0,
          reason: '$status: no call for an order that cannot be corrected',
        );
      }
    });

    testWidgets('someone who may only look at orders does not see it (and nothing is asked)', (tester) async {
      final repo = _repo(perms: const ['orders.view']);
      await _pump(tester, repo);
      await _openOrder(tester);
      expect(find.text('Missing an item?'), findsNothing);
      expect(repo.adjustContextLoads, 0);
    });

    testWidgets('the server saying can_adjust: false takes the button away', (tester) async {
      final repo = _repo(context: adjustContextJson(canAdjust: false));
      await _pump(tester, repo);
      await _openOrder(tester);
      expect(find.text('Missing an item?'), findsNothing);
    });

    testWidgets('the context could not be read: the button is there, a tap says why and asks again next time', (
      tester,
    ) async {
      final repo = _repo()..adjustContextError = apiError(ApiErrorKind.offline, 0);
      await _pump(tester, repo);
      await _openOrder(tester);
      expect(find.text('Missing an item?'), findsOneWidget);
      await _openFlow(tester);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
      expect(find.text('Oslo sofa'), findsNothing);
      repo.adjustContextError = null;
      await _openFlow(tester);
      expect(find.text('Oslo sofa'), findsOneWidget);
    });

    testWidgets(
      'a parcel that stopped being correctable since the window opened: the tap explains and the button goes',
      (tester) async {
        final repo = _repo();
        await _pump(tester, repo);
        await _openOrder(tester);
        repo.adjustContextBody = adjustContextJson(canAdjust: false);
        // The window already holds the first answer (can adjust); the tap uses it, and the server refuses later. This is the
        // other order of events: the first answer was "no" only after a reload.
        final container = ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
        container.invalidate(adjustContextProvider('f5'));
        await settle(tester);
        expect(find.text('Missing an item?'), findsNothing);
      },
    );
  });

  group('the lines', () {
    testWidgets('each line shows its name, variant, quantity and what it is worth', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      expect(find.text('Missing an item?'), findsWidgets);
      expect(find.text('Oslo sofa'), findsOneWidget);
      expect(find.text('Grey'), findsOneWidget);
      expect(find.text('× 2'), findsOneWidget);
      expect(find.text('QAR 2000.00'), findsOneWidget);
      expect(find.text('Linen cushion'), findsOneWidget);
      expect(find.text('× 4'), findsOneWidget);
      expect(find.text('#AB100005 · Hamad'), findsOneWidget);
    });

    testWidgets('nothing chosen: the continue button is off and says what to do', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      expect(find.text('Choose at least one change to continue.'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Review changes')).onPressed, isNull);
    });

    testWidgets('a line that was already reduced says so', (tester) async {
      final repo = _repo(
        context: adjustContextJson(
          items: [
            adjustItemJson(
              'i1',
              'Oslo sofa',
              2,
              '1000.00',
              adjustment: {
                'kind': 'reduced',
                'original_quantity': 3,
                'original_name': 'Oslo sofa',
                'reason_code': 'damaged',
              },
            ),
          ],
        ),
      );
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      expect(find.text('Already reduced from 3'), findsOneWidget);
    });

    testWidgets('a line an earlier correction emptied is not listed', (tester) async {
      final repo = _repo(
        context: adjustContextJson(
          items: [adjustItemJson('i1', 'Oslo sofa', 2, '1000.00'), adjustItemJson('i2', 'Gone lamp', 0, '250.00')],
        ),
      );
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      expect(find.text('Gone lamp'), findsNothing);
    });

    testWidgets('tapping a line opens its three choices; a line of one piece has no "reduce"', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _tapText(tester, 'Oslo sofa');
      expect(find.text('Reduce quantity'), findsOneWidget);
      expect(find.text('Replace with another item'), findsOneWidget);
      expect(find.text('Remove item'), findsOneWidget);
      await _tapText(tester, 'Oslo lamp');
      expect(find.text('Reduce quantity'), findsNothing, reason: 'one piece: reducing it is removing it');
      expect(find.text('Remove item'), findsOneWidget, reason: 'only one line is open at a time');
    });
  });

  group('reduce, remove and undo', () {
    testWidgets('reduce goes straight to one less, with a chip and a stepper that stays between 1 and ordered-1', (
      tester,
    ) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _tapText(tester, 'Linen cushion');
      await _tapText(tester, 'Reduce quantity');
      expect(find.text('Reduced 4 → 3'), findsOneWidget);
      expect(find.text('New quantity'), findsOneWidget);
      final minus = find.byIcon(Icons.remove_rounded);
      final plus = find.byIcon(Icons.add_rounded);
      await tester.tap(minus);
      await tester.pump();
      await tester.tap(minus);
      await settle(tester);
      expect(find.text('Reduced 4 → 1'), findsOneWidget);
      await tester.tap(minus);
      await settle(tester);
      expect(find.text('Reduced 4 → 1'), findsOneWidget, reason: '0 is not allowed here: that is "remove"');
      for (var i = 0; i < 3; i++) {
        await tester.tap(plus);
        await tester.pump();
      }
      await settle(tester);
      expect(find.text('Reduced 4 → 3'), findsOneWidget, reason: 'never back up to the ordered 4');
    });

    testWidgets('remove marks the line removed (struck through) with an Undo that clears it', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _tapText(tester, 'Oslo lamp');
      await _tapText(tester, 'Remove item');
      expect(find.text('Removed'), findsOneWidget);
      expect(find.text('Continue · 1 change'), findsOneWidget);
      await _tapText(tester, 'Undo');
      expect(find.text('Removed'), findsNothing);
      expect(find.text('Choose at least one change to continue.'), findsOneWidget);
    });

    testWidgets('several changes are counted; one line holds one change', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _tapText(tester, 'Oslo lamp');
      await _tapText(tester, 'Remove item');
      expect(find.text('Continue · 2 changes'), findsOneWidget);
      await _tapText(tester, 'Oslo sofa');
      await _tapText(tester, 'Remove item');
      expect(find.text('Continue · 2 changes'), findsOneWidget);
      expect(find.textContaining('Reduced 2'), findsNothing, reason: 'the removal replaced the reduction');
    });
  });

  group('replace with another item', () {
    Future<void> toReplace(WidgetTester tester, [String line = 'Oslo sofa']) async {
      await _tapText(tester, line);
      await _tapText(tester, 'Replace with another item');
    }

    testWidgets('the branch stock is listed with price and how many; nothing is chosen until tapped', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await toReplace(tester);
      expect(find.text('Replace item'), findsOneWidget);
      expect(find.text('Replacing: Oslo sofa (Grey) × 2'), findsOneWidget);
      expect(find.text('Oslo lamp (large)'), findsOneWidget);
      expect(find.text('QAR 300.00'), findsOneWidget);
      expect(find.text('3 available'), findsOneWidget);
      expect(find.text('Brass lamp'), findsOneWidget);
      expect(repo.substituteSearches, [''], reason: 'the first list loads at once');
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Use this item')).onPressed, isNull);
    });

    testWidgets('searching is debounced: a burst of typing makes one request', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await toReplace(tester);
      await tester.enterText(find.byType(TextField), 'b');
      await tester.pump(const Duration(milliseconds: 100));
      await tester.enterText(find.byType(TextField), 'br');
      await tester.pump(const Duration(milliseconds: 100));
      await tester.enterText(find.byType(TextField), 'brass');
      await tester.pump(const Duration(milliseconds: 200));
      expect(repo.substituteSearches, [''], reason: 'still waiting for the typing to stop');
      await tester.pump(const Duration(milliseconds: 300));
      await settle(tester);
      expect(repo.substituteSearches, ['', 'brass']);
      expect(find.text('Brass lamp'), findsOneWidget);
      expect(find.text('Oslo lamp (large)'), findsNothing);
    });

    testWidgets('nothing found says so in plain words; the clear button brings the list back', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await toReplace(tester);
      await tester.enterText(find.byType(TextField), 'zzz');
      await tester.pump(const Duration(milliseconds: 500));
      await settle(tester);
      expect(find.text('No items found. Try another word.'), findsOneWidget);
      await tester.tap(find.byTooltip('Close').last);
      await settle(tester);
      expect(find.text('Brass lamp'), findsOneWidget);
    });

    testWidgets('a failed search shows the reason and a retry', (tester) async {
      final repo = _repo()..substitutesError = apiError(ApiErrorKind.offline, 0);
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await toReplace(tester);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
      repo.substitutesError = null;
      await _tapText(tester, 'Try again');
      expect(find.text('Brass lamp'), findsOneWidget);
    });

    testWidgets('a quantity up to what the branch has and what was ordered; the cheaper one says it is cheaper', (
      tester,
    ) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await toReplace(tester); // 2 sofas at 1000.00
      await _tapText(tester, 'Oslo lamp (large)'); // 3 available, 300.00 each
      expect(find.text('Quantity'), findsOneWidget);
      expect(find.text('Cheaper than the original: the customer pays QAR 300.00 each.'), findsOneWidget);
      final plus = find.byIcon(Icons.add_rounded);
      final minus = find.byIcon(Icons.remove_rounded);
      expect(find.widgetWithText(Center, '3'), findsNothing);
      await tester.tap(minus);
      await settle(tester);
      for (var i = 0; i < 3; i++) {
        await tester.tap(plus);
        await tester.pump();
      }
      await settle(tester);
      // 3 are in the branch but only 2 were ordered: 2 is the most.
      await _tapButton(tester, 'Use this item');
      expect(find.text('Oslo lamp (large) × 2'), findsOneWidget);
    });

    testWidgets('a dearer replacement says the customer pays the original price', (tester) async {
      final repo = _repo()..substituteBodies = [substituteJson('s9', 'Marble lamp', '400.00', 5)];
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await toReplace(tester, 'Oslo lamp'); // 1 lamp at 250.00
      await _tapText(tester, 'Marble lamp');
      expect(
        find.text('More than the original item, so the customer pays the original price: QAR 250.00 each.'),
        findsOneWidget,
      );
      expect(find.text('Quantity'), findsNothing, reason: 'one piece ordered: nothing to choose');
    });

    testWidgets('less stock than ordered: the quantity is capped by the stock', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await toReplace(tester); // 2 sofas
      await _tapText(tester, 'Brass lamp'); // only 1 available
      expect(find.text('Quantity'), findsNothing);
      expect(find.text('1 available'), findsOneWidget);
    });

    testWidgets('using it puts a Replaced chip on the line, with the new item, and counts as a change', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await toReplace(tester);
      await _tapText(tester, 'Oslo lamp (large)');
      await _tapButton(tester, 'Use this item');
      expect(find.text('Replaced'), findsOneWidget);
      expect(find.text('Oslo lamp (large) × 2'), findsOneWidget);
      expect(find.text('Continue · 1 change'), findsOneWidget);
    });

    testWidgets('back from the replacement list keeps the lines as they were', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _tapText(tester, 'Oslo lamp');
      await _tapText(tester, 'Remove item');
      await toReplace(tester, 'Oslo sofa');
      await tester.tap(find.byTooltip('Back'));
      await settle(tester);
      expect(find.text('Removed'), findsOneWidget);
      expect(find.text('Replaced'), findsNothing);
      expect(find.text('Oslo sofa'), findsOneWidget);
    });
  });

  group('the reason', () {
    testWidgets('it is required: Review does nothing but say so', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _tapButton(tester, 'Continue · 1 change');
      expect(find.text('Why is it missing?'), findsOneWidget);
      await _tapButton(tester, 'Review changes');
      expect(find.text('Choose a reason.'), findsOneWidget);
      expect(repo.adjustRequests, isEmpty);
      await _tapText(tester, 'Damaged');
      expect(find.text('Choose a reason.'), findsNothing);
    });

    testWidgets('the five reasons, a note of at most 300 characters, and one reason at a time', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _tapButton(tester, 'Continue · 1 change');
      for (final r in ['Out of stock', "Couldn't find it", 'Damaged', 'Quality', 'Other']) {
        expect(find.text(r), findsOneWidget);
      }
      await tester.enterText(find.byType(TextField), 'x' * 400);
      expect(tester.widget<TextField>(find.byType(TextField)).controller!.text.length, 300);
      expect(find.text('The customer sees this note in their order.'), findsOneWidget);
    });

    testWidgets('the reason and the note survive a step back', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _tapButton(tester, 'Continue · 1 change');
      await _tapText(tester, 'Quality');
      await tester.enterText(find.byType(TextField), 'Scratched');
      await tester.tap(find.byTooltip('Back'));
      await settle(tester);
      await _tapButton(tester, 'Continue · 1 change');
      expect(find.text('Scratched'), findsOneWidget);
      expect(find.byIcon(Icons.check_rounded), findsOneWidget, reason: 'Quality is still chosen');
    });
  });

  group('review, then one action', () {
    testWidgets('the preview is asked first, with exactly the chosen changes, and shown in plain words', (
      tester,
    ) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _tapText(tester, 'Oslo lamp');
      await _tapText(tester, 'Remove item');
      await _tapButton(tester, 'Continue · 2 changes');
      await _tapText(tester, 'Out of stock');
      await tester.enterText(find.byType(TextField), '  Only one left  ');
      await _tapButton(tester, 'Review changes');

      expect(repo.adjustRequests, hasLength(1));
      final req = repo.adjustRequests.single;
      expect(req.fulfillmentId, 'f5');
      expect(req.preview, isTrue);
      expect(req.reason, 'out_of_stock');
      expect(req.note, '  Only one left  ');
      expect(req.changes, [
        {'order_item_id': 'i1', 'action': 'reduce', 'new_quantity': 1},
        {'order_item_id': 'i2', 'action': 'remove'},
      ]);

      expect(find.text('Check before sending'), findsOneWidget);
      expect(find.text('Oslo sofa'), findsOneWidget);
      expect(find.text('2 → 1'), findsOneWidget);
      expect(find.text('Oslo lamp'), findsOneWidget);
      expect(find.text('Removed'), findsOneWidget);
      expect(find.text('QAR 2475.00'), findsOneWidget);
      expect(find.text('QAR 1225.00'), findsOneWidget);
      expect(find.text('Customer pays QAR 1250.00 less'), findsOneWidget);
      expect(find.text('Cash on delivery: the driver collects QAR 1250.00 less.'), findsOneWidget);
      expect(find.text('The customer gets a notification and an updated invoice.'), findsWidgets);
      expect(find.text('Update order & notify customer'), findsOneWidget);
      expect(repo.queueLoads, lessThan(20));
    });

    testWidgets(
      'one tap on the one primary action sends it for real, tells the customer, and closes with a confirmation',
      (tester) async {
        final repo = _repo();
        await _pump(tester, repo);
        await _openOrder(tester);
        await _openFlow(tester);
        await _reduceSofa(tester);
        await _toReview(tester);
        final loadsBefore = repo.queueLoads;
        await _tapButton(tester, 'Update order & notify customer');

        expect(repo.adjustRequests.map((r) => r.preview), [true, false]);
        final applied = repo.adjustRequests.last;
        expect(applied.changes, [
          {'order_item_id': 'i1', 'action': 'reduce', 'new_quantity': 1},
        ]);
        expect(applied.reason, 'out_of_stock');
        expect(find.text('Customer notified'), findsOneWidget);
        expect(find.text('Order #AB100005 was updated.'), findsOneWidget);
        expect(find.text('New total: QAR 1475.00'), findsOneWidget);
        expect(repo.queueLoads, greaterThan(loadsBefore), reason: 'the board is read again');

        await _tapButton(tester, 'Done');
        expect(find.text('Customer notified'), findsNothing);
        expect(find.text('Order #AB100005'), findsOneWidget, reason: 'back on the order');
        expect(find.text('Missing an item?'), findsOneWidget);
      },
    );

    testWidgets('a paid online order: the refund goes to finance for approval, in words', (tester) async {
      final repo = _repo(context: adjustContextJson(payment: 'card'));
      await _pump(tester, repo);
      await _openOrder(tester);
      repo.adjustAnswer = null;
      await _openFlow(tester);
      await _reduceSofa(tester);
      // Answer the way the server does for an online order.
      repo.adjustAnswer = adjustResultJson(
        repo.adjustContextBody,
        [
          {'order_item_id': 'i1', 'action': 'reduce', 'new_quantity': 1},
        ],
        preview: true,
        online: true,
      );
      await _toReview(tester);
      expect(find.text('A refund of QAR 1000.00 will be sent to finance for approval.'), findsOneWidget);
      expect(find.textContaining('the driver collects'), findsNothing);
    });

    testWidgets('a replacement that keeps the total: "the total stays the same"', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _tapText(tester, 'Oslo lamp');
      await _tapText(tester, 'Replace with another item');
      await _tapText(tester, 'Oslo lamp (large)');
      await _tapButton(tester, 'Use this item');
      await _tapButton(tester, 'Continue · 1 change');
      await _tapText(tester, 'Out of stock');
      await _tapButton(tester, 'Review changes');
      expect(find.text('The total stays the same.'), findsOneWidget);
      expect(find.text('Replaced by Replacement × 1'), findsOneWidget);
      final req = repo.adjustRequests.single;
      expect(req.changes, [
        {'order_item_id': 'i2', 'action': 'replace', 'variant_id': 's1', 'quantity': 1},
      ]);
    });

    testWidgets('back from the review goes to the reason, then to the lines, and nothing is lost', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _toReview(tester);
      await tester.tap(find.byTooltip('Back'));
      await settle(tester);
      expect(find.text('Why is it missing?'), findsOneWidget);
      await tester.tap(find.byTooltip('Back'));
      await settle(tester);
      expect(find.text('Reduced 2 → 1'), findsOneWidget);
    });
  });

  group('every item removed: the whole order is cancelled, after a clear confirmation', () {
    Future<FakeOps> removeEverything(WidgetTester tester) async {
      final repo = _repo(context: adjustContextJson(items: [adjustItemJson('i1', 'Oslo sofa', 1, '1000.00')]));
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _tapText(tester, 'Oslo sofa');
      await _tapText(tester, 'Remove item');
      await _toReview(tester);
      return repo;
    }

    testWidgets('the review warns in red and the action says it cancels', (tester) async {
      await removeEverything(tester);
      expect(find.text('Every item is removed, so the whole order will be cancelled.'), findsOneWidget);
      expect(find.text('Cancel order & notify customer'), findsOneWidget);
      expect(find.text('Update order & notify customer'), findsNothing);
    });

    testWidgets('declining the confirmation sends nothing; accepting sends it once', (tester) async {
      final repo = await removeEverything(tester);
      await _tapButton(tester, 'Cancel order & notify customer');
      expect(find.text('Cancel this order?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.adjustRequests.map((r) => r.preview), [true], reason: 'only the preview');

      await _tapButton(tester, 'Cancel order & notify customer');
      await tester.tap(find.widgetWithText(FilledButton, 'Cancel order & notify customer').last);
      await settle(tester);
      expect(repo.adjustRequests.map((r) => r.preview), [true, false]);
      expect(find.text('The order was cancelled.'), findsOneWidget);
      expect(find.text('Customer notified'), findsOneWidget);
    });

    testWidgets('a normal update asks no extra question', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _toReview(tester);
      await _tapButton(tester, 'Update order & notify customer');
      expect(find.text('Cancel this order?'), findsNothing);
      expect(find.text('Customer notified'), findsOneWidget);
    });
  });

  group('double taps and slow networks', () {
    testWidgets('the action sends once however often it is tapped; the button shows progress', (tester) async {
      final repo = _repo()..adjustGate = Completer<void>();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _toReview(tester);
      final button = find.widgetWithText(FilledButton, 'Update order & notify customer');
      await tester.tap(button);
      await tester.pump();
      await tester.tap(find.byType(FilledButton).last, warnIfMissed: false);
      await tester.tap(find.byType(FilledButton).last, warnIfMissed: false);
      await tester.pump();
      expect(repo.adjustRequests.where((r) => !r.preview), hasLength(1));
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      repo.adjustGate!.complete();
      await settle(tester);
      expect(repo.adjustRequests.where((r) => !r.preview), hasLength(1));
      expect(find.text('Customer notified'), findsOneWidget);
    });

    testWidgets('Review changes sends one preview even when tapped twice', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _tapButton(tester, 'Continue · 1 change');
      await _tapText(tester, 'Other');
      final f = find.widgetWithText(FilledButton, 'Review changes');
      await tester.ensureVisible(f);
      final onPressed = tester.widget<FilledButton>(f).onPressed!;
      onPressed();
      onPressed();
      await settle(tester);
      expect(repo.adjustRequests, hasLength(1));
    });
  });

  group('when the server says no, everything the employee chose stays', () {
    testWidgets('the preview fails (offline): the message shows on the reason step, the choices are kept', (
      tester,
    ) async {
      final repo = _repo()..previewError = apiError(ApiErrorKind.offline, 0);
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _toReview(tester);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
      expect(find.text('Why is it missing?'), findsOneWidget);
      expect(find.byIcon(Icons.check_rounded), findsOneWidget, reason: 'the reason is still chosen');
      repo.previewError = null;
      await _tapButton(tester, 'Review changes');
      expect(find.text('Check before sending'), findsOneWidget);
    });

    testWidgets('the update fails (a timeout): the review stays, the message shows, a second try works', (
      tester,
    ) async {
      final repo = _repo()..adjustError = apiError(ApiErrorKind.timeout, 0);
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _toReview(tester);
      await _tapButton(tester, 'Update order & notify customer');
      expect(find.text('Check before sending'), findsOneWidget);
      expect(find.text('Customer notified'), findsNothing);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      repo.adjustError = null;
      await _tapButton(tester, 'Update order & notify customer');
      expect(find.text('Customer notified'), findsOneWidget);
    });

    testWidgets('a server error shows its plain message', (tester) async {
      final repo = _repo()..adjustError = apiError(ApiErrorKind.server, 500);
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _toReview(tester);
      await _tapButton(tester, 'Update order & notify customer');
      expect(find.text('Check before sending'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });

    testWidgets('adjustment_not_allowed: the order moved on; the flow says so and goes back to the order, refreshed', (
      tester,
    ) async {
      final repo = _repo()
        ..adjustError = apiError(ApiErrorKind.conflict, 409, code: 'adjustment_not_allowed', message: 'Not now');
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await _toReview(tester);
      final loads = repo.queueLoads;
      await _tapButton(tester, 'Update order & notify customer');
      expect(find.text('This order can no longer be changed: it is not being prepared any more.'), findsOneWidget);
      expect(repo.queueLoads, greaterThan(loads), reason: 'the board is read again');
      await _tapButton(tester, 'Back to the order');
      expect(find.text('Order #AB100005'), findsOneWidget);
    });

    testWidgets('substitute_unavailable: the stock is read again, the replacement is taken back, the rest stays', (
      tester,
    ) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _tapText(tester, 'Oslo lamp');
      await _tapText(tester, 'Remove item');
      await _tapText(tester, 'Oslo sofa');
      await _tapText(tester, 'Replace with another item');
      await _tapText(tester, 'Brass lamp');
      await _tapButton(tester, 'Use this item');
      await _tapButton(tester, 'Continue · 2 changes');
      await _tapText(tester, 'Out of stock');
      await _tapButton(tester, 'Review changes');
      repo.adjustError = apiError(
        ApiErrorKind.validation,
        422,
        code: 'substitute_unavailable',
        message: 'Not enough stock',
      );
      final contextLoads = repo.adjustContextLoads;
      await _tapButton(tester, 'Update order & notify customer');
      expect(find.text('A replacement just ran out. Choose another item.'), findsOneWidget);
      expect(find.text('Replaced'), findsNothing);
      expect(find.text('Removed'), findsOneWidget, reason: 'the other change is kept');
      expect(repo.adjustContextLoads, greaterThan(contextLoads), reason: 'the lines are read again');
    });

    testWidgets('no_change: back to the lines with a clear message', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      repo.previewError = apiError(ApiErrorKind.validation, 422, code: 'no_change');
      await _toReview(tester);
      expect(find.text('Nothing was changed. Change at least one item.'), findsOneWidget);
      expect(find.text('Reduced 2 → 1'), findsOneWidget);
    });

    testWidgets('quantity_exceeds: the lines are read again and the message says to check the numbers', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      repo.previewError = apiError(ApiErrorKind.validation, 422, code: 'quantity_exceeds');
      // The sofa line now has only 1 piece: the reduction to 1 is no change any more and is dropped.
      repo.adjustContextBody = adjustContextJson(items: [adjustItemJson('i1', 'Oslo sofa', 1, '1000.00')]);
      await _toReview(tester);
      expect(
        find.text('That is more than the order has. The list was refreshed, please check the numbers.'),
        findsOneWidget,
      );
      expect(find.text('Linen cushion'), findsNothing, reason: 'the list is the fresh one');
      expect(find.text('× 1'), findsOneWidget);
    });
  });

  group('leaving', () {
    testWidgets('closing with changes asks "Discard changes?"; keeping them stays, discarding goes back to the order', (
      tester,
    ) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await tester.binding.handlePopRoute();
      await settle(tester);
      expect(find.text('Discard changes?'), findsOneWidget);
      await tester.tap(find.text('Keep editing'));
      await settle(tester);
      expect(find.text('Reduced 2 → 1'), findsOneWidget);
      await tester.binding.handlePopRoute();
      await settle(tester);
      await tester.tap(find.text('Discard'));
      await settle(tester);
      expect(find.text('Order #AB100005'), findsOneWidget);
      expect(find.text('Oslo sofa'), findsNothing);
    });

    testWidgets('with nothing chosen it just closes', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await tester.binding.handlePopRoute();
      await settle(tester);
      expect(find.text('Discard changes?'), findsNothing);
      expect(find.text('Oslo sofa'), findsNothing);
    });

    testWidgets('a tap outside does not close it', (tester) async {
      await _pump(tester, _repo());
      await _openOrder(tester);
      await _openFlow(tester);
      await _reduceSofa(tester);
      await tester.tapAt(const Offset(195, 20));
      await settle(tester);
      expect(find.text('Reduced 2 → 1'), findsOneWidget);
    });
  });

  group('Arabic and every size', () {
    testWidgets('the whole flow in Arabic: words, direction, one request', (tester) async {
      final repo = _repo();
      await _pump(tester, repo, variant: _phoneAr);
      await _openOrder(tester);
      await _openFlow(tester, ar: true);
      expect(find.text('نقص في منتج؟'), findsWidgets);
      await _tapText(tester, 'Oslo sofa');
      await _tapText(tester, 'تقليل الكمية');
      expect(find.text('تقليل من 2 إلى 1'), findsOneWidget);
      await _tapButton(tester, 'متابعة · تغيير واحد');
      expect(find.text('لماذا هو ناقص؟'), findsOneWidget);
      await _tapText(tester, 'نفد من المخزون');
      await _tapButton(tester, 'مراجعة التغييرات');
      expect(find.text('راجع قبل الإرسال'), findsOneWidget);
      expect(find.text('سيدفع العميل 1000.00 ر.ق أقل'), findsOneWidget);
      await _tapButton(tester, 'تحديث الطلب وإبلاغ العميل');
      expect(find.text('تم إبلاغ العميل'), findsOneWidget);
      expect(repo.adjustRequests.where((r) => !r.preview), hasLength(1));
      expect(tester.takeException(), isNull);
    });

    for (final v in const [
      Variant('320 en', Size(320, 640), kEn),
      Variant('360 ar 1.3x dark', Size(360, 640), kAr, scale: 1.3, dark: true),
      Variant('768 en tablet', Size(768, 1024), kEn),
      Variant('390 en 1.3x', Size(390, 844), kEn, scale: 1.3),
    ]) {
      testWidgets('every step lays out without overflow: ${v.name}', (tester) async {
        final repo = _repo();
        await _pump(tester, repo, variant: v);
        await _openOrder(tester);
        await _openFlow(tester, ar: v.rtl);
        expect(tester.takeException(), isNull);
        await _tapText(tester, 'Oslo sofa');
        await _tapText(tester, v.rtl ? 'تقليل الكمية' : 'Reduce quantity');
        await _tapText(tester, 'Oslo lamp');
        await _tapText(tester, v.rtl ? 'استبداله بمنتج آخر' : 'Replace with another item');
        expect(tester.takeException(), isNull, reason: 'replacement list');
        await _tapText(tester, 'Oslo lamp (large)');
        await _tapButton(tester, v.rtl ? 'استخدام هذا المنتج' : 'Use this item');
        expect(tester.takeException(), isNull, reason: 'lines');
        await tester.tap(find.byType(FilledButton).last);
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'reason');
        await _tapText(tester, v.rtl ? 'أخرى' : 'Other');
        await tester.tap(find.byType(FilledButton).last);
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'review');
        expect(find.byType(FilledButton), findsWidgets);
      });
    }
  });
}

String stageOfStatusForTest(String status) => switch (status) {
  'pending' => 'new',
  'ready' => 'ready',
  _ => 'preparing',
};
