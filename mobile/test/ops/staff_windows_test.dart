import 'dart:async';

import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

const _phone = Variant('390 en', Size(390, 844), kEn);
const _phoneAr = Variant('390 ar', Size(390, 844), kAr);
const _wide = Variant('1024 en', Size(1024, 768), kEn);
const _wideAr = Variant('1024 ar', Size(1024, 768), kAr);

Future<ProviderContainer> _pump(WidgetTester tester, FakeOps repo, Variant variant) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: variant, role: OpsRole.staff));
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
}

Future<void> _openTab(WidgetTester tester, int index) async {
  final tab = find.byType(Tab).at(index);
  await tester.ensureVisible(tab);
  await tester.pump();
  await tester.tap(tab);
  await settle(tester);
}

Future<void> _back(WidgetTester tester) async {
  await tester.binding.handlePopRoute();
  await settle(tester);
}

Finder get _sheet => find.byType(BottomSheet);
Finder get _panelBody => find.byType(SidePanelLayout);

/// Every list reloads, like the 60 second poll or a real-time ping does; then the window gets its frame to close.
Future<void> _reloadAll(WidgetTester tester, ProviderContainer container) async {
  container.invalidate(staffOverviewProvider);
  for (final s in kStaffStages) {
    container.invalidate(staffQueueProvider(s));
  }
  container.invalidate(staffDriversProvider);
  await settle(tester);
  await settle(tester);
}

void main() {
  group('an order opens in a window beside the queue', () {
    testWidgets('phone: a tall sheet with the details; the queue (and its tab) stays behind', (tester) async {
      await _pump(tester, FakeOps(), _phone);
      expect(find.text('Order #AB100001'), findsNothing);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(_sheet, findsOneWidget);
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.text('Customer'), findsOneWidget);
      expect(find.text('Hamad'), findsWidgets);
      expect(find.text('Delivery address'), findsOneWidget);
      expect(find.text('3 items'), findsWidgets);
      expect(find.text('Cash on delivery'), findsWidgets);
      expect(find.text('QAR 120.00'), findsWidgets);
      expect(find.byType(StaffHome), findsOneWidget);
    });

    testWidgets('wide: an end panel on the right; Arabic: on the left', (tester) async {
      await _pump(tester, FakeOps(), _wide);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(_sheet, findsNothing);
      expect(tester.getRect(_panelBody).right, 1024);
      await _back(tester);
      expect(find.text('Order #AB100001'), findsNothing);

      await tester.pumpWidget(const SizedBox.shrink());
      await _pump(tester, FakeOps(), _wideAr);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(find.text('الطلب #AB100001'), findsOneWidget);
      expect(tester.getRect(_panelBody).left, 0);
    });

    testWidgets('Arabic phone: a sheet with Arabic words and no overflow', (tester) async {
      await _pump(tester, FakeOps(), _phoneAr);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(_sheet, findsOneWidget);
      expect(find.text('العميل'), findsOneWidget);
      expect(find.text('الطلب'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Back closes only the window; the tab the person was on stays', (tester) async {
      await _pump(tester, FakeOps(), _phone);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      await _back(tester);
      expect(find.text('Order #AB100001'), findsNothing);
      expect(find.byType(StaffHome), findsOneWidget);
      expect(find.text('#AB100001'), findsOneWidget);
    });

    testWidgets('the pinned step button works and the window follows the order to its next stage', (tester) async {
      final repo = FakeOps();
      final container = await _pump(tester, repo, _phone);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      final accept = find.descendant(of: _sheet, matching: find.widgetWithText(FilledButton, 'Accept order'));
      expect(tester.getBottomLeft(accept).dy, greaterThan(844 - 120));
      // The server moves the order from New to Preparing as it is accepted.
      repo.queues = {
        'preparing': [FakeOps.order(id: 'f0', status: 'accepted', number: 'AB100001')],
        'ready': [FakeOps.order(id: 'f1', status: 'ready', number: 'AB100009')],
      };
      await tester.tap(accept);
      await settle(tester);
      await settle(tester);
      expect(repo.calls, ['advance:f0:accepted']);
      // Still open (the order did not "leave"): it is now in Preparing and offers the next step.
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.descendant(of: _sheet, matching: find.text('Start preparing')), findsOneWidget);
      expect(find.descendant(of: _sheet, matching: find.text('Accept order')), findsNothing);
      await _reloadAll(tester, container);
      expect(find.text('Order #AB100001'), findsOneWidget);
    });

    testWidgets('an order in the middle of moving between stages is not mistaken for one that left the queue', (
      tester,
    ) async {
      final repo = FakeOps();
      final container = await _pump(tester, repo, _phone);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      // The New list has already reloaded without the order, the Preparing list has not answered yet.
      repo.queues = {
        'preparing': [FakeOps.order(id: 'f0', status: 'accepted', number: 'AB100001')],
      };
      repo.queueGates['preparing'] = Completer<void>();
      container.invalidate(staffOverviewProvider);
      for (final s in kStaffStages) {
        container.invalidate(staffQueueProvider(s));
      }
      await settle(tester);
      await settle(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.text('This order is no longer in the branch queue.'), findsNothing);
      repo.queueGates['preparing']!.complete();
      await settle(tester);
      await settle(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.descendant(of: _sheet, matching: find.text('Start preparing')), findsOneWidget);
    });

    testWidgets('a refused step (409) shows its message in the window and keeps it open', (tester) async {
      final repo = FakeOps()..advanceError = conflict('This action is not allowed while the status is "Accepted".');
      await _pump(tester, repo, _phone);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      await tester.tap(find.descendant(of: _sheet, matching: find.widgetWithText(FilledButton, 'Accept order')));
      await settle(tester);
      expect(find.text('This action is not allowed while the status is "Accepted".'), findsOneWidget);
      expect(find.text('Order #AB100001'), findsOneWidget);
    });

    testWidgets('when the order leaves the queue the window closes itself and says why', (tester) async {
      final repo = FakeOps();
      final container = await _pump(tester, repo, _phone);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      repo.queues = {};
      await _reloadAll(tester, container);
      expect(find.text('Order #AB100001'), findsNothing);
      expect(_sheet, findsNothing);
      expect(find.text('This order is no longer in the branch queue.'), findsOneWidget);
    });

    testWidgets('the person can only press what the server would allow', (tester) async {
      final repo = FakeOps()..perms = const ['orders.view'];
      await _pump(tester, repo, _phone);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.descendant(of: _sheet, matching: find.byType(FilledButton)), findsNothing);
      expect(find.text('Accept order'), findsNothing);
    });

    testWidgets('Call the customer and Copy address are in the window', (tester) async {
      final repo = FakeOps()
        ..queues = {
          'new': [
            QueueOrder.fromJson({
              'fulfillment_id': 'f0',
              'status': 'pending',
              'number': 'AB100001',
              'customer_name': 'Hamad',
              'customer_phone': '+97455000111',
              'items_count': 3,
              'payment_method': 'card',
              'grand_total': '120.00',
              'address': {'zone_number': '24', 'street_number': '9', 'notes': 'Blue gate'},
            }),
          ],
        };
      await _pump(tester, repo, _phone);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(find.text('Call Hamad'), findsOneWidget);
      expect(find.text('+97455000111'), findsOneWidget);
      expect(find.text('Blue gate'), findsOneWidget);
      expect(find.text('Online payment'), findsWidgets);
      expect(find.text('Copy address'), findsOneWidget);
    });

    testWidgets('a ready order: Assign driver is pinned; the driver picker stacks above it and Back closes only it', (
      tester,
    ) async {
      final repo = FakeOps();
      await _pump(tester, repo, _phone);
      await _openTab(tester, 2);
      await tester.tap(find.text('#AB100009'));
      await settle(tester);
      expect(find.text('Waiting for a driver'), findsWidgets);
      await tester.tap(find.descendant(of: _sheet, matching: find.widgetWithText(FilledButton, 'Assign driver')));
      await settle(tester);
      expect(find.text('Choose a driver'), findsOneWidget);
      expect(find.text('For order #AB100009'), findsOneWidget);
      // On duty drivers first.
      expect(tester.getTopLeft(find.text('Omar').last).dy, lessThan(tester.getTopLeft(find.text('Salem')).dy));
      await _back(tester);
      expect(find.text('Choose a driver'), findsNothing);
      expect(find.text('Order #AB100009'), findsOneWidget);
      expect(repo.calls, isEmpty);

      await tester.tap(find.descendant(of: _sheet, matching: find.widgetWithText(FilledButton, 'Assign driver')));
      await settle(tester);
      await tester.tap(find.text('Omar').last);
      await settle(tester);
      expect(repo.calls, ['assign:f1:u1']);
      expect(find.text('Choose a driver'), findsNothing);
      expect(find.text('Order #AB100009'), findsOneWidget); // the order window is still there
    });

    testWidgets('wide: the driver picker is an end panel; Arabic: on the left', (tester) async {
      final repo = FakeOps();
      await _pump(tester, repo, _wideAr);
      await _openTab(tester, 2);
      await tester.tap(find.text('#AB100009'));
      await settle(tester);
      await tester.tap(find.descendant(of: _panelBody, matching: find.widgetWithText(FilledButton, 'إسناد سائق')));
      await settle(tester);
      expect(find.text('اختر سائقاً'), findsOneWidget);
      expect(_sheet, findsNothing);
      expect(tester.getRect(find.byType(SidePanelLayout).last).left, 0);
    });

    testWidgets('a failed delivery: the window brings it back to the branch after a question', (tester) async {
      final repo = FakeOps()..perms = const ['orders.view', 'deliveries.edit'];
      await _pump(tester, repo, _phone);
      await _openTab(tester, 4);
      await tester.tap(find.text('#AB100010'));
      await settle(tester);
      expect(find.text('Delivery failed'), findsWidgets);
      await tester.tap(find.descendant(of: _sheet, matching: find.text('Return to branch')));
      await settle(tester);
      expect(find.text('Handed back at the branch?'), findsOneWidget);
      expect(tester.widget<TextButton>(find.widgetWithText(TextButton, 'Cancel')).autofocus, isTrue);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.calls, isEmpty);
      await tester.tap(find.descendant(of: _sheet, matching: find.text('Return to branch')));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm return'));
      await settle(tester);
      expect(repo.calls, ['return:dl-f2']);
    });
  });

  group('a driver opens in a window from the Drivers list', () {
    FleetDriver fleet(String id, {String? order, String? status, DateTime? seen}) => FleetDriver.fromJson({
      'id': id,
      'name': 'Omar',
      'on_duty': true,
      'lat': 25.28,
      'lng': 51.52,
      'last_seen_at': (seen ?? DateTime.now()).toIso8601String(),
      'open_jobs': 1,
      'vehicle': 'Honda PCX',
      'battery': 64,
      'job': order == null ? null : {'order_number': order, 'status': status ?? 'out_for_delivery'},
    });

    testWidgets('tapping a driver shows duty, the delivery in hand, last seen, vehicle and a pinned Call button', (
      tester,
    ) async {
      final repo = FakeOps()..fleetList = [fleet('u1', order: 'AB100008')];
      await _pump(tester, repo, _phone);
      await _openTab(tester, 5);
      await tester.tap(find.text('Omar'));
      await settle(tester);
      expect(_sheet, findsOneWidget);
      expect(find.text('On duty'), findsOneWidget);
      expect(find.text('Current delivery'), findsOneWidget);
      expect(find.text('Order AB100008 · On the way'), findsOneWidget);
      expect(find.text('Last seen'), findsOneWidget);
      expect(find.text('just now'), findsOneWidget);
      expect(find.text('Vehicle: Honda PCX'), findsOneWidget);
      expect(find.text('Battery 64%'), findsOneWidget);
      final call = find.descendant(of: _sheet, matching: find.widgetWithText(FilledButton, 'Call Omar'));
      expect(call, findsOneWidget);
      expect(tester.getBottomLeft(call).dy, greaterThan(844 - 120));
      await _back(tester);
      expect(_sheet, findsNothing);
      expect(find.byTooltip('Call Omar'), findsOneWidget); // still on the Drivers list
    });

    testWidgets('a driver with no phone has no Call button, and one without a delivery is "Free"', (tester) async {
      final repo = FakeOps()..fleetList = [fleet('u2')];
      await _pump(tester, repo, _phone);
      await _openTab(tester, 5);
      await tester.tap(find.text('Salem'));
      await settle(tester);
      expect(find.text('Off duty'), findsWidgets);
      expect(find.descendant(of: _sheet, matching: find.byType(FilledButton)), findsNothing);
    });

    testWidgets('wide: an end panel (left in Arabic); the row call button still calls without opening the window', (
      tester,
    ) async {
      final repo = FakeOps()..fleetList = [fleet('u1')];
      await _pump(tester, repo, _wideAr);
      await _openTab(tester, 5);
      await tester.tap(find.text('Omar'));
      await settle(tester);
      expect(_sheet, findsNothing);
      expect(tester.getRect(_panelBody).left, 0);
    });

    testWidgets('the driver leaving the branch closes the window with a message', (tester) async {
      final repo = FakeOps()..fleetList = [fleet('u1')];
      final container = await _pump(tester, repo, _phone);
      await _openTab(tester, 5);
      await tester.tap(find.text('Omar'));
      await settle(tester);
      repo.branchDrivers = const [BranchDriver(id: 'u2', name: 'Salem', phone: null, onDuty: false, openJobs: 2)];
      await _reloadAll(tester, container);
      expect(_sheet, findsNothing);
      expect(find.text('This driver is no longer in the branch list.'), findsOneWidget);
    });

    testWidgets('the window lays out under every phone, language, text size and theme', (tester) async {
      for (final v in variants) {
        final handle = tester.ensureSemantics();
        final repo = FakeOps()..fleetList = [fleet('u1', order: 'AB100008')];
        await _pump(tester, repo, v);
        await _openTab(tester, 5);
        await tester.tap(find.text('Omar'));
        await settle(tester);
        expect(tester.takeException(), isNull, reason: v.name);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
        await tester.pumpWidget(const SizedBox.shrink());
      }
    });
  });

  group('the order window under every phone, language, text size and theme', () {
    for (final v in variants) {
      testWidgets('fits, 48 dp targets, labelled: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(tester, FakeOps(), v);
        await tester.tap(find.text('#AB100001'));
        await settle(tester);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }
  });

  group('add product stays a full screen, with its pickers in windows', () {
    Future<void> openAdd(WidgetTester tester, Variant v) async {
      useVariant(tester, v);
      await tester.pumpWidget(
        opsHost(
          Scaffold(
            body: Builder(
              builder: (context) => FilledButton(
                onPressed: () => Navigator.of(context)
                    .push(MaterialPageRoute<bool>(fullscreenDialog: true, builder: (_) => const AddProductScreen())),
                child: const Text('open'),
              ),
            ),
          ),
          FakeOps(),
          variant: v,
          role: OpsRole.staff,
        ),
      );
      await tester.tap(find.text('open'));
      await settle(tester);
    }

    testWidgets('it has its exit at the top, the save button pinned, and the category list opens in a window', (
      tester,
    ) async {
      await openAdd(tester, _phone);
      expect(find.byTooltip('Close'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Save product'), findsOneWidget);
      await tester.tap(find.text('Choose a category'));
      await settle(tester);
      expect(_sheet, findsOneWidget);
      expect(find.text('Search categories'), findsOneWidget);
      // Back closes the category window only; the product form is still there.
      await _back(tester);
      expect(_sheet, findsNothing);
      expect(find.text('New product'), findsOneWidget);
      await tester.tap(find.text('Choose a category'));
      await settle(tester);
      await tester.tap(find.text('Living › Sofas'));
      await settle(tester);
      expect(_sheet, findsNothing);
      expect(find.text('Living › Sofas'), findsOneWidget);
    });

    testWidgets('wide: the category list is an end panel and the form keeps a readable width', (tester) async {
      await openAdd(tester, _wide);
      await tester.tap(find.text('Choose a category'));
      await settle(tester);
      expect(_sheet, findsNothing);
      expect(find.text('Search categories'), findsOneWidget);
      expect(tester.getRect(find.widgetWithText(ListTile, 'No category')).right, 1024);
      expect(tester.getRect(find.widgetWithText(ListTile, 'No category')).width, lessThanOrEqualTo(440));
      await _back(tester);
      expect(tester.getSize(find.byType(Form)).width, lessThanOrEqualTo(640));
    });

    testWidgets('typed input + the exit asks "Discard changes?" and Keep editing is focused', (tester) async {
      await openAdd(tester, _phone);
      await tester.enterText(find.widgetWithText(TextFormField, 'Title (English)'), 'Linen');
      await tester.tap(find.byTooltip('Close'));
      await settle(tester);
      expect(find.text('Discard changes?'), findsOneWidget);
      expect(tester.widget<TextButton>(find.widgetWithText(TextButton, 'Keep editing')).autofocus, isTrue);
    });
  });
}
