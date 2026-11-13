import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

const _phone = Variant('390 en', Size(390, 844), kEn);
const _phoneAr = Variant('390 ar', Size(390, 844), kAr);
const _wide = Variant('1024 en', Size(1024, 768), kEn);
const _wideAr = Variant('1024 ar', Size(1024, 768), kAr);

List<Map<String, dynamic>> _items(int n) => [
  for (var i = 1; i <= n; i++)
    {'id': 'i$i', 'name': 'Product $i', 'variant_name': 'Colour $i', 'sku': 'SKU$i', 'quantity': i == 1 ? 2 : 1},
];

final _sampleItems = [
  {'id': 'i1', 'name': 'Linen Throw', 'variant_name': 'Sand / Large', 'sku': 'LT-1', 'quantity': 2},
  {'id': 'i2', 'name': 'Ceramic Vase', 'variant_name': null, 'sku': null, 'quantity': 1},
];

Future<void> _pumpDriver(WidgetTester tester, FakeOps repo, Variant v) async {
  useVariant(tester, v);
  await tester.pumpWidget(opsHost(const DriverHome(), repo, variant: v));
  await settle(tester);
  await tester.tap(find.text('#AB100008').first);
  await settle(tester);
}

Future<void> _pumpStaff(WidgetTester tester, FakeOps repo, Variant v) async {
  useVariant(tester, v);
  await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: v, role: OpsRole.staff));
  await settle(tester);
  await tester.tap(find.text('#AB100001'));
  await settle(tester);
}

FakeOps _driverRepo(Object? items) => FakeOps()..jobList = [FakeOps.job(items: items)];
FakeOps _staffRepo(Object? items) => FakeOps()
  ..queues = {
    'new': [FakeOps.order(id: 'f0', status: 'pending', number: 'AB100001', items: items)],
  };

Finder _inWindow(Finder f) => find.descendant(of: find.byType(SidePanelLayout), matching: f);

void main() {
  group('the items of a parcel (models)', () {
    test('a job and an order read items: name, variant, sku and the quantity still to deliver', () {
      final job = FakeOps.job(items: _sampleItems);
      expect(job.items, hasLength(2));
      expect(job.items.first.name, 'Linen Throw');
      expect(job.items.first.variantName, 'Sand / Large');
      expect(job.items.first.sku, 'LT-1');
      expect(job.items.first.quantity, 2);
      expect(job.items.last.variantName, isNull);
      expect(FakeOps.order(items: _sampleItems).items, hasLength(2));
    });

    test('an older server without the key gives an empty list and the count stays', () {
      expect(FakeOps.job().items, isEmpty);
      final order = FakeOps.order();
      expect(order.items, isEmpty);
      expect(order.itemsCount, 3);
    });

    test('a bad value is tolerated: not a list, entries that are not objects, a missing quantity', () {
      expect(FakeOps.job(items: 'nope').items, isEmpty);
      expect(FakeOps.job(items: null).items, isEmpty);
      final items = FakeOps.job(
        items: [
          'x',
          5,
          {'id': 'a', 'name': 'Only a name'},
        ],
      ).items;
      expect(items, hasLength(1));
      expect(items.single.quantity, 1);
      expect(items.single.variantName, isNull);
    });
  });

  group('driver delivery window', () {
    testWidgets('phone sheet: one line per product, the variant under it, "× quantity" at the end', (tester) async {
      await _pumpDriver(tester, _driverRepo(_sampleItems), _phone);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text('Items · 3'), findsOneWidget); // 2 + 1 units
      expect(find.text('Linen Throw'), findsOneWidget);
      expect(find.text('Sand / Large'), findsOneWidget);
      expect(find.text('Ceramic Vase'), findsOneWidget);
      expect(find.text('× 2'), findsOneWidget);
      expect(find.text('× 1'), findsOneWidget);
      // The variant sits under its product and the quantity at the end of the line.
      expect(
        tester.getTopLeft(find.text('Sand / Large')).dy,
        greaterThan(tester.getTopLeft(find.text('Linen Throw')).dy),
      );
      expect(tester.getTopLeft(find.text('× 2')).dx, greaterThan(tester.getTopLeft(find.text('Linen Throw')).dx));
      // A product without a variant shows no empty line.
      expect(tester.takeException(), isNull);
    });

    testWidgets('wide panel: the same list in the end panel', (tester) async {
      await _pumpDriver(tester, _driverRepo(_sampleItems), _wide);
      expect(find.byType(BottomSheet), findsNothing);
      expect(find.text('Items · 3'), findsOneWidget);
      expect(tester.getRect(find.byType(SidePanelLayout)).right, 1024);
      expect(tester.getTopLeft(find.text('× 2')).dx, greaterThan(tester.getTopLeft(find.text('Linen Throw')).dx));
    });

    testWidgets('Arabic: the panel is on the left and the quantity is at the END, which is the left of each line', (
      tester,
    ) async {
      await _pumpDriver(tester, _driverRepo(_sampleItems), _wideAr);
      expect(find.text('القطع · 3'), findsOneWidget);
      expect(tester.getRect(find.byType(SidePanelLayout)).left, 0);
      expect(tester.getTopLeft(find.text('× 2')).dx, lessThan(tester.getTopLeft(find.text('Linen Throw')).dx));
      expect(tester.takeException(), isNull);
    });

    testWidgets('Arabic phone sheet fits', (tester) async {
      await _pumpDriver(tester, _driverRepo(_sampleItems), _phoneAr);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text('القطع · 3'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('very long names wrap inside the window, no overflow, on the smallest phone with large text', (
      tester,
    ) async {
      final long = 'Extra large hand woven linen throw with fringe in a very long colour description ' * 3;
      final items = [
        {'id': 'i1', 'name': long, 'variant_name': 'Variant ${long.substring(0, 80)}', 'quantity': 12},
      ];
      await _pumpDriver(tester, _driverRepo(items), variants[2]); // 360x640 at 1.3x text
      expect(tester.takeException(), isNull);
      expect(find.text('× 12'), findsOneWidget);
      expect(tester.getSize(find.text(long)).height, greaterThan(60)); // several lines, not one clipped line
    });

    testWidgets('20 lines scroll inside the window while the pinned step button stays put', (tester) async {
      await _pumpDriver(tester, _driverRepo(_items(20)), _phone);
      final accept = find.descendant(
        of: find.byType(BottomSheet),
        matching: find.widgetWithText(FilledButton, 'Accept'),
      );
      final before = tester.getTopLeft(accept);
      expect(tester.getTopLeft(find.text('Product 20')).dy, greaterThan(844)); // below the visible window
      await tester.scrollUntilVisible(
        find.text('Product 20'),
        300,
        scrollable: _inWindow(find.byType(Scrollable)).first,
      );
      await settle(tester);
      expect(find.text('Product 20'), findsOneWidget);
      expect(tester.getBottomLeft(find.text('Product 20')).dy, lessThan(before.dy)); // above the pinned button
      expect(tester.getTopLeft(accept), before); // the button did not move
      await tester.tap(accept);
      await settle(tester);
    });

    testWidgets('a server without the key shows no items section and everything else works', (tester) async {
      await _pumpDriver(tester, _driverRepo(null), _phone);
      expect(find.textContaining('Items'), findsNothing);
      expect(find.text('Customer'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Accept'), findsWidgets);
    });

    testWidgets('an empty list also shows no items section', (tester) async {
      await _pumpDriver(tester, _driverRepo(<Object>[]), _phone);
      expect(find.textContaining('Items'), findsNothing);
    });

    for (final v in variants) {
      testWidgets('items fit, 48 dp targets and labels: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pumpDriver(tester, _driverRepo(_items(8)), v);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }
  });

  group('staff order window', () {
    testWidgets('phone sheet: the list replaces the "3 items" line', (tester) async {
      await _pumpStaff(tester, _staffRepo(_sampleItems), _phone);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text('Items · 3'), findsOneWidget);
      expect(find.text('Linen Throw'), findsOneWidget);
      expect(find.text('Sand / Large'), findsOneWidget);
      expect(find.text('Ceramic Vase'), findsOneWidget);
      expect(find.text('× 2'), findsOneWidget);
      expect(find.text('3 items'), findsNothing);
    });

    testWidgets('wide panel on the right; Arabic panel on the left with the quantity at the end', (tester) async {
      await _pumpStaff(tester, _staffRepo(_sampleItems), _wide);
      expect(find.byType(BottomSheet), findsNothing);
      expect(tester.getRect(find.byType(SidePanelLayout)).right, 1024);
      expect(tester.getTopLeft(find.text('× 2')).dx, greaterThan(tester.getTopLeft(find.text('Linen Throw')).dx));

      await tester.pumpWidget(const SizedBox.shrink());
      await _pumpStaff(tester, _staffRepo(_sampleItems), _wideAr);
      expect(find.text('القطع · 3'), findsOneWidget);
      expect(tester.getRect(find.byType(SidePanelLayout)).left, 0);
      expect(tester.getTopLeft(find.text('× 2')).dx, lessThan(tester.getTopLeft(find.text('Linen Throw')).dx));
    });

    testWidgets('Arabic phone sheet fits', (tester) async {
      await _pumpStaff(tester, _staffRepo(_sampleItems), _phoneAr);
      expect(find.text('القطع · 3'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('very long names wrap, no overflow, smallest phone with large text', (tester) async {
      final long = 'Extra large hand woven linen throw with fringe in a very long colour description ' * 3;
      final items = [
        {'id': 'i1', 'name': long, 'variant_name': 'Variant ${long.substring(0, 80)}', 'quantity': 12},
      ];
      await _pumpStaff(tester, _staffRepo(items), variants[2]);
      expect(tester.takeException(), isNull);
      expect(tester.getSize(find.text(long)).height, greaterThan(60));
    });

    testWidgets('20 lines scroll inside the window while Accept order stays pinned', (tester) async {
      await _pumpStaff(tester, _staffRepo(_items(20)), _phone);
      final accept = find.descendant(
        of: find.byType(BottomSheet),
        matching: find.widgetWithText(FilledButton, 'Accept order'),
      );
      final before = tester.getTopLeft(accept);
      expect(tester.getTopLeft(find.text('Product 20')).dy, greaterThan(844));
      await tester.scrollUntilVisible(
        find.text('Product 20'),
        300,
        scrollable: _inWindow(find.byType(Scrollable)).first,
      );
      await settle(tester);
      expect(tester.getBottomLeft(find.text('Product 20')).dy, lessThan(before.dy));
      expect(tester.getTopLeft(accept), before);
    });

    testWidgets('a server without the key falls back to the "3 items" line', (tester) async {
      await _pumpStaff(tester, _staffRepo(null), _phone);
      expect(find.text('Items'), findsOneWidget);
      expect(find.text('3 items'), findsOneWidget);
      expect(find.textContaining('×'), findsNothing);
    });

    testWidgets('an empty list falls back to the count line too', (tester) async {
      await _pumpStaff(tester, _staffRepo(<Object>[]), _phone);
      expect(find.text('3 items'), findsOneWidget);
    });

    testWidgets('the list follows the order: a colleague changes the quantity and the window updates', (tester) async {
      final repo = _staffRepo(_sampleItems);
      await _pumpStaff(tester, repo, _phone);
      expect(find.text('× 2'), findsOneWidget);
      repo.queues = {
        'new': [
          FakeOps.order(
            id: 'f0',
            status: 'pending',
            number: 'AB100001',
            items: [
              {'id': 'i1', 'name': 'Linen Throw', 'variant_name': 'Sand / Large', 'quantity': 1},
            ],
          ),
        ],
      };
      for (final s in kStaffStages) {
        tester.element(find.byType(BottomSheet));
        ProviderScope.containerOf(tester.element(find.byType(BottomSheet))).invalidate(staffQueueProvider(s));
      }
      await settle(tester);
      expect(find.text('× 2'), findsNothing);
      expect(find.text('Items · 1'), findsOneWidget);
    });

    for (final v in variants) {
      testWidgets('items fit, 48 dp targets and labels: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pumpStaff(tester, _staffRepo(_items(8)), v);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }
  });
}
