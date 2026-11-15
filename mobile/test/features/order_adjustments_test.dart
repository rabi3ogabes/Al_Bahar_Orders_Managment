@Timeout(Duration(minutes: 5))
library;

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/order_sections.dart';
import 'package:albahar_customer/features/orders/presentation/orders_screen.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import '../qa/customer_fixtures.dart';
import '../qa/test_fonts.dart';

AppLocalizations get en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get ar => lookupAppLocalizations(const Locale('ar'));

// ── A parcel of three lines that the branch corrected: 3 sofas became 2, the lamp was swapped, the cushion was dropped ──────────

Map<String, dynamic> _item(
  String id,
  String name,
  int qty,
  String unit, {
  String? variant,
  Map<String, dynamic>? adjustment,
  String? fulfillment = 'f1',
}) => {
  'id': id,
  'product_id': 'p-$id',
  'variant_id': 'v-$id',
  'sku': 'SKU-$id',
  'name': name,
  'variant_name': variant,
  'image_url': null,
  'quantity': qty,
  'unit_price': money(unit),
  'line_total': money((double.parse(unit) * qty).toStringAsFixed(2)),
  'fulfillment_id': fulfillment,
  'adjustment': adjustment,
};

Map<String, dynamic> _adjustedItems() => {
  'items': [
    _item(
      'oi1',
      'Oslo sofa',
      2,
      '1000.00',
      variant: 'Grey',
      adjustment: {
        'kind': 'reduced',
        'original_quantity': 3,
        'original_name': 'Oslo sofa',
        'reason_code': 'out_of_stock',
      },
    ),
    _item(
      'oi2',
      'Oslo lamp',
      0,
      '250.00',
      adjustment: {
        'kind': 'replaced',
        'original_quantity': 1,
        'original_name': 'Oslo lamp',
        'reason_code': 'out_of_stock',
        'replaced_by_name': 'Oslo lamp (large)',
      },
    ),
    _item(
      'oi3',
      'Oslo lamp (large)',
      1,
      '250.00',
      adjustment: {
        'kind': 'replacement',
        'original_quantity': 1,
        'original_name': 'Oslo lamp',
        'reason_code': 'out_of_stock',
        'replaces_name': 'Oslo lamp',
      },
    ),
    _item(
      'oi4',
      'Linen cushion',
      0,
      '50.00',
      variant: 'Sand',
      adjustment: {
        'kind': 'removed',
        'original_quantity': 2,
        'original_name': 'Linen cushion',
        'reason_code': 'out_of_stock',
      },
    ),
    _item('oi5', 'Brass tray', 1, '100.00'),
  ],
};

Map<String, dynamic> _revision({
  int number = 1,
  String reason = 'out_of_stock',
  String? note = 'Only two left on the shelf',
  bool withText = true,
}) => {
  'number': number,
  'at': '2026-11-02T16:10:00+03:00',
  'reason_code': reason,
  'reason_label': 'Out of stock',
  'note': note,
  'branch': 'West Bay',
  'lines': [
    {
      'order_item_id': 'oi1',
      'action': 'reduce',
      'name': 'Oslo sofa',
      'variant_name': 'Grey',
      'from_quantity': 3,
      'to_quantity': 2,
      'replacement': null,
      if (withText) 'text': 'Oslo sofa (Grey): 3 → 2',
    },
    {
      'order_item_id': 'oi2',
      'action': 'replace',
      'name': 'Oslo lamp',
      'variant_name': null,
      'from_quantity': 1,
      'to_quantity': 0,
      'replacement': {
        'variant_id': 'v9',
        'name': 'Oslo lamp (large)',
        'variant_name': null,
        'quantity': 1,
        'unit_price': money('250.00'),
      },
      if (withText) 'text': 'Oslo lamp replaced with Oslo lamp (large)',
    },
    {
      'order_item_id': 'oi4',
      'action': 'remove',
      'name': 'Linen cushion',
      'variant_name': 'Sand',
      'from_quantity': 2,
      'to_quantity': 0,
      'replacement': null,
      if (withText) 'text': 'Linen cushion (Sand) removed',
    },
  ],
  'difference': money('-200.00'),
  'total_before': money('2450.00'),
  'total_after': money('2250.00'),
  'order_cancelled': false,
};

Map<String, dynamic> adjustedOrder({List<Map<String, dynamic>>? revisions, String status = 'processing'}) => {
  ...orderDetail('o1', status: status),
  ..._adjustedItems(),
  'totals': {'subtotal': money('2200.00'), 'delivery_fee': money('50.00'), 'grand_total': money('2250.00')},
  'was_adjusted': true,
  'revisions': revisions ?? [_revision()],
};

void main() {
  setUpAll(loadAppFonts);

  group('what the server sends', () {
    test('an order from before the feature reads as it always did: nothing adjusted, nothing new', () {
      final o = Order.fromJson(orderDetail('o1'));
      expect(o.wasAdjusted, isFalse);
      expect(o.revisions, isEmpty);
      expect(o.items.single.adjustment, isNull);
      expect(o.items.single.isGone, isFalse);
      expect(o.items.single.isReduced, isFalse);
      expect(o.items.single.isReplacement, isFalse);
      expect(OrderSummary.fromJson(orderSummary('o1', 'processing')).wasAdjusted, isFalse);
    });

    test('the contract: was_adjusted, per line adjustment, revisions with lines and the difference', () {
      final o = Order.fromJson(adjustedOrder());
      expect(o.wasAdjusted, isTrue);
      final byId = {for (final i in o.items) i.id: i};
      expect(byId['oi1']!.isReduced, isTrue);
      expect(byId['oi1']!.adjustment!.originalQuantity, 3);
      expect(byId['oi1']!.quantity, 2);
      expect(byId['oi2']!.isGone, isTrue);
      expect(byId['oi3']!.isReplacement, isTrue);
      expect(byId['oi3']!.adjustment!.replacesName, 'Oslo lamp');
      expect(byId['oi4']!.isGone, isTrue);
      expect(byId['oi5']!.adjustment, isNull);

      final r = o.revisions.single;
      expect(r.number, 1);
      expect(r.reasonCode, 'out_of_stock');
      expect(r.note, 'Only two left on the shelf');
      expect(r.branch, 'West Bay');
      expect(r.lines, hasLength(3));
      expect(r.lines.first.action, 'reduce');
      expect(r.lines.first.fromQuantity, 3);
      expect(r.lines.first.toQuantity, 2);
      expect(r.lines.first.text, 'Oslo sofa (Grey): 3 → 2');
      expect(r.lines[1].replacementName, 'Oslo lamp (large)');
      expect(r.lines[1].replacementQuantity, 1);
      expect(r.difference!.formatted, 'QAR -200.00');
      expect(r.totalBefore!.amount, '2450.00');
      expect(r.totalAfter!.amount, '2250.00');
    });

    test('a swapped-out line knows what replaced it: from the server, from the new line, or from the revision', () {
      final o = Order.fromJson(adjustedOrder());
      final lamp = o.items.firstWhere((i) => i.id == 'oi2');
      expect(o.replacedByFor(lamp), 'Oslo lamp (large)');

      // Without replaced_by_name: found through the replacement line's replaces_name.
      final items = (adjustedOrder()['items'] as List).cast<Map<String, dynamic>>();
      items[1] = {
        ...items[1],
        'adjustment': {'kind': 'replaced', 'original_quantity': 1, 'original_name': 'Oslo lamp'},
      };
      final viaLine = Order.fromJson({...adjustedOrder(), 'items': items});
      expect(viaLine.replacedByFor(viaLine.items[1]), 'Oslo lamp (large)');

      // Without the replacement line: found in the revision.
      final onlyRevision = Order.fromJson({
        ...adjustedOrder(),
        'items': [items[0], items[1], items[3]],
      });
      expect(onlyRevision.replacedByFor(onlyRevision.items[1]), 'Oslo lamp (large)');

      // Nothing to say: null, never a crash.
      final alone = Order.fromJson({
        ...adjustedOrder(),
        'items': [
          {
            ...items[1],
            'adjustment': {'kind': 'replaced', 'original_quantity': 1},
          },
        ],
        'revisions': <Object>[],
      });
      expect(alone.replacedByFor(alone.items.single), isNull);
      expect(o.replacedByFor(o.items.first), isNull, reason: 'a reduced line was not swapped');
    });

    test(
      'revisions are read oldest first whatever order they arrive in; was_adjusted is implied by having revisions',
      () {
        final o = Order.fromJson({
          ...orderDetail('o1'),
          'revisions': [_revision(number: 2), _revision(number: 1)],
        });
        expect(o.revisions.map((r) => r.number), [1, 2]);
        expect(o.wasAdjusted, isTrue, reason: 'no was_adjusted flag, but it has revisions');
        expect(
          Order.fromJson({...orderDetail('o1'), 'was_adjusted': false, 'revisions': <Object>[]}).wasAdjusted,
          isFalse,
        );
      },
    );

    test('garbage in the new fields is read as nothing, never a crash', () {
      final o = Order.fromJson({
        ...orderDetail('o1'),
        'was_adjusted': 'yes',
        'revisions': 'nope',
        'items': [
          {..._item('a', 'X', 1, '1.00'), 'adjustment': 'nope'},
          {
            ..._item('b', 'Y', 1, '1.00'),
            'adjustment': {'kind': ''},
          },
          {
            ..._item('c', 'Z', 1, '1.00'),
            'adjustment': {'kind': 'reduced'},
          },
        ],
      });
      expect(o.revisions, isEmpty);
      expect(o.items[0].adjustment, isNull);
      expect(o.items[1].adjustment, isNull);
      expect(o.items[2].isReduced, isTrue);
      expect(o.items[2].adjustment!.originalQuantity, isNull);
      final r = OrderRevision.fromJson(const {});
      expect(r.number, 0);
      expect(r.lines, isEmpty);
      expect(r.note, isNull);
      expect(OrderRevision.fromJson({'note': '   '}).note, isNull);
    });

    test('the list: was_adjusted survives a live status change', () {
      final s = OrderSummary.fromJson({...orderSummary('o1', 'processing'), 'was_adjusted': true});
      expect(s.wasAdjusted, isTrue);
      expect(s.withLive(status: 'out_for_delivery', seq: 5).wasAdjusted, isTrue);
    });

    test('the order: the corrections survive a live status change', () {
      final o = Order.fromJson(adjustedOrder());
      final live = o.withLive(seq: 3, status: 'out_for_delivery');
      expect(live.wasAdjusted, isTrue);
      expect(live.revisions, hasLength(1));
      expect(live.items.first.adjustment!.kind, 'reduced');
    });

    test('the timeline entry of a change is read with its kind', () {
      final e = TimelineEntry.fromJson({
        'kind': 'items_changed',
        'status': 'processing',
        'label': 'Your order was updated',
        'at': '2026-11-02T16:10:00+03:00',
      });
      expect(e.kind, 'items_changed');
    });
  });

  group('the order page', () {
    Future<TestHarness> open(
      WidgetTester t,
      Map<String, dynamic> order, {
      String locale = 'en',
      bool dark = false,
      double width = 390,
      double scale = 1,
    }) async {
      t.view.physicalSize = Size(width, 2400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(
        tokens: signedInTokens,
        prefs: {'locale': locale, 'onboarding_done': true, if (dark) 'theme_mode': 'dark'},
        handler: (r) async {
          if (r.method == 'GET' && r.path == '/orders/o1') return ok(order);
          if (r.method == 'GET' && r.path == '/orders/o1/timeline') {
            return ok([
              {'kind': 'order', 'status': 'confirmed', 'label': 'Order confirmed', 'at': '2026-11-02T15:20:00+03:00'},
              if (order['was_adjusted'] == true)
                {
                  'kind': 'items_changed',
                  'status': 'processing',
                  'label': 'Your order was updated by West Bay',
                  'at': '2026-11-02T16:10:00+03:00',
                },
              {
                'kind': 'fulfillment',
                'status': 'preparing',
                'label': 'Being prepared',
                'at': '2026-11-02T16:30:00+03:00',
              },
            ]);
          }
          return customerBackend(ApiMode.data)(r);
        },
      );
      await t.pumpWidget(
        UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
      );
      await t.pump(const Duration(milliseconds: 1700));
      h.read(routerProvider).go('/orders/o1');
      for (var i = 0; i < 8; i++) {
        await t.pump(const Duration(milliseconds: 150));
      }
      return h;
    }

    Future<void> done(WidgetTester t) async {
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 10));
    }

    testWidgets('an order the branch did not touch looks exactly as before: no banner, the plain invoice button', (
      t,
    ) async {
      await open(t, orderDetail('o1'));
      expect(find.text(en.orderUpdatedTitle), findsNothing);
      expect(find.text(en.orderItemNotAvailable), findsNothing);
      expect(find.byType(OrderUpdatedBanner), findsNothing);
      expect(find.widgetWithText(AppButton, 'Download invoice (PDF)'), findsOneWidget);
      expect(find.widgetWithText(AppButton, en.downloadUpdatedInvoice), findsNothing);
      expect(t.takeException(), isNull);
      await done(t);
    });

    testWidgets(
      'the banner says the branch updated the order, why, what changed, the new total and that the invoice changed too',
      (t) async {
        await open(t, adjustedOrder());
        expect(find.text('Your order was updated by the branch'), findsOneWidget);
        expect(find.text('Some items ran out of stock at the branch.'), findsOneWidget);
        expect(find.text('Note from the branch: “Only two left on the shelf”'), findsOneWidget);
        expect(find.text('What changed'), findsOneWidget);
        expect(find.text('Oslo sofa (Grey): 3 → 2'), findsOneWidget);
        expect(find.text('Oslo lamp replaced with Oslo lamp (large)'), findsOneWidget);
        expect(find.text('Linen cushion (Sand) removed'), findsOneWidget);
        expect(find.text('New total'), findsOneWidget);
        expect(find.text('QAR 2250.00'), findsWidgets);
        expect(find.text('Before: QAR 2450.00'), findsOneWidget);
        expect(find.text('Your invoice was updated too.'), findsOneWidget);
        expect(t.takeException(), isNull);
        await done(t);
      },
    );

    testWidgets('without the server sentences the app words each change itself', (t) async {
      await open(t, adjustedOrder(revisions: [_revision(withText: false)]));
      expect(find.text('Oslo sofa (Grey): reduced from 3 to 2'), findsOneWidget);
      expect(find.text('Oslo lamp was replaced by Oslo lamp (large)'), findsOneWidget);
      expect(find.text('Linen cushion (Sand) was removed'), findsOneWidget);
      await done(t);
    });

    testWidgets('every reason has its own plain sentence; an unknown one shows the server label', (t) async {
      for (final (code, text) in [
        ('out_of_stock', 'Some items ran out of stock at the branch.'),
        ('not_found', 'The branch couldn’t find some of your items.'),
        ('damaged', 'Some items were found damaged.'),
        ('quality', 'Some items didn’t pass the branch’s quality check.'),
        ('other', 'The branch had to change some of your items.'),
        ('something_new', 'Out of stock'),
      ]) {
        await open(t, adjustedOrder(revisions: [_revision(reason: code, note: null)]));
        expect(find.text(text), findsWidgets, reason: code);
        expect(find.textContaining('Note from the branch'), findsNothing, reason: 'no note, no note line');
        await done(t);
      }
    });

    testWidgets(
      'the lines: reduced with "was", removed and swapped struck through and greyed, the replacement badged',
      (t) async {
        await open(t, adjustedOrder());
        // Reduced: the new quantity and what it was.
        expect(find.text('Reduced from 3'), findsOneWidget);
        expect(find.text('Grey · × 2'), findsOneWidget);
        // Removed: struck through, "Not available", nothing to pay.
        Text name(String s) => t.widget<Text>(find.text(s).last);
        expect(name('Linen cushion').style!.decoration, TextDecoration.lineThrough);
        expect(find.text('Not available'), findsOneWidget);
        // Swapped out: struck through and says by what.
        expect(name('Oslo lamp').style!.decoration, TextDecoration.lineThrough);
        expect(find.text('Replaced by Oslo lamp (large)'), findsOneWidget);
        // The replacement: badged, and says what it replaces; not struck through.
        expect(find.text('Replacement'), findsOneWidget);
        expect(find.text('Replaces Oslo lamp'), findsOneWidget);
        expect(name('Oslo lamp (large)').style!.decoration, isNot(TextDecoration.lineThrough));
        // An untouched line is as before.
        expect(find.text('Brass tray'), findsOneWidget);
        expect(find.text('QAR 100.00'), findsOneWidget);
        // Nothing to pay for the lines that are gone: their prices are not shown.
        expect(find.text('QAR 0.00'), findsNothing);
        await done(t);
      },
    );

    testWidgets('the totals are the server\'s own', (t) async {
      await open(t, adjustedOrder());
      expect(find.text('QAR 2200.00'), findsOneWidget, reason: 'subtotal');
      expect(find.text('QAR 2250.00'), findsWidgets, reason: 'grand total');
      await done(t);
    });

    testWidgets('the timeline shows the change with its own mark', (t) async {
      await open(t, adjustedOrder());
      expect(find.text('Your order was updated by West Bay'), findsOneWidget);
      expect(find.byIcon(Icons.edit_rounded), findsOneWidget);
      await done(t);
    });

    testWidgets('the invoice button says "updated"', (t) async {
      await open(t, adjustedOrder());
      expect(find.widgetWithText(AppButton, 'Download updated invoice (PDF)'), findsOneWidget);
      expect(find.widgetWithText(AppButton, 'Download invoice (PDF)'), findsNothing);
      await done(t);
    });

    testWidgets('several corrections: newest first, each with its number, time, reason and lines', (t) async {
      await open(
        t,
        adjustedOrder(
          revisions: [
            _revision(note: null),
            {
              ..._revision(number: 2, reason: 'damaged', note: 'The rug was torn'),
              'lines': [
                {
                  'order_item_id': 'oi5',
                  'action': 'remove',
                  'name': 'Brass tray',
                  'variant_name': null,
                  'from_quantity': 1,
                  'to_quantity': 0,
                  'replacement': null,
                  'text': 'Brass tray removed',
                },
              ],
              'total_before': money('2250.00'),
              'total_after': money('2150.00'),
            },
          ],
        ),
      );
      expect(find.textContaining('Update 2'), findsOneWidget);
      expect(find.textContaining('Update 1'), findsOneWidget);
      expect(
        t.getTopLeft(find.textContaining('Update 2')).dy,
        lessThan(t.getTopLeft(find.textContaining('Update 1')).dy),
      );
      expect(find.text('Some items were found damaged.'), findsOneWidget);
      expect(find.text('Note from the branch: “The rug was torn”'), findsOneWidget);
      expect(find.text('Brass tray removed'), findsOneWidget);
      expect(find.text('Before: QAR 2450.00'), findsOneWidget, reason: 'compared with what the customer first paid');
      await done(t);
    });

    testWidgets('an order whose every item was unavailable says it was cancelled', (t) async {
      await open(
        t,
        adjustedOrder(
          revisions: [
            {..._revision(), 'order_cancelled': true},
          ],
        ),
      );
      expect(find.text('Every item was unavailable, so the order was cancelled.'), findsOneWidget);
      await done(t);
    });

    testWidgets('an older server that sends the flag but no revisions: the changes are read from the lines', (t) async {
      await open(t, {...adjustedOrder(), 'revisions': <Object>[]});
      expect(find.text('Your order was updated by the branch'), findsOneWidget);
      expect(find.text('Oslo sofa: reduced from 3 to 2'), findsOneWidget);
      expect(find.text('Oslo lamp was replaced by Oslo lamp (large)'), findsOneWidget);
      expect(find.text('Linen cushion was removed'), findsOneWidget);
      expect(find.text('Some items ran out of stock at the branch.'), findsOneWidget);
      await done(t);
    });

    testWidgets('Arabic: the words, the direction, no overflow', (t) async {
      await open(t, adjustedOrder(), locale: 'ar');
      expect(find.text(ar.orderUpdatedTitle), findsOneWidget);
      expect(find.text(ar.orderUpdatedReasonOutOfStock), findsOneWidget);
      expect(find.text(ar.orderUpdatedNote('Only two left on the shelf')), findsOneWidget);
      expect(find.text(ar.orderItemNotAvailable), findsOneWidget);
      expect(find.text(ar.orderItemReplacement), findsOneWidget);
      expect(find.text(ar.orderItemReducedFrom(3)), findsOneWidget);
      expect(find.widgetWithText(AppButton, ar.downloadUpdatedInvoice), findsOneWidget);
      final banner = t.getRect(find.byType(OrderUpdatedBanner));
      expect(
        t.getTopRight(find.text(ar.orderUpdatedTitle)).dx,
        greaterThan(banner.center.dx),
        reason: 'reads from the right',
      );
      expect(t.takeException(), isNull);
      await done(t);
    });

    for (final (name, width, dark, scale) in const [
      ('320 wide', 320.0, false, 1.0),
      ('dark', 390.0, true, 1.0),
      ('tablet', 900.0, false, 1.0),
    ]) {
      testWidgets('lays out without overflow: $name', (t) async {
        await open(
          t,
          adjustedOrder(
            revisions: [
              _revision(),
              _revision(number: 2, reason: 'damaged'),
            ],
          ),
          width: width,
          dark: dark,
          scale: scale,
        );
        expect(t.takeException(), isNull);
        expect(find.byType(OrderUpdatedBanner), findsOneWidget);
        await done(t);
      });
    }
  });

  group('the orders list', () {
    Future<void> boot(WidgetTester t, {required bool adjusted, String locale = 'en'}) async {
      t.view.physicalSize = const Size(390, 900);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(
        tokens: signedInTokens,
        prefs: {'locale': locale, 'onboarding_done': true},
        handler: (r) async {
          if (r.method == 'GET' && r.path == '/orders') {
            return page([
              {...orderSummary('o1', 'processing'), 'was_adjusted': adjusted},
              orderSummary('o2', 'completed'),
            ]);
          }
          return customerBackend(ApiMode.data)(r);
        },
      );
      await t.pumpWidget(
        UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
      );
      await t.pump(const Duration(milliseconds: 1700));
      h.read(routerProvider).go('/orders');
      for (var i = 0; i < 8; i++) {
        await t.pump(const Duration(milliseconds: 150));
      }
    }

    testWidgets('a changed order carries a small "Updated" chip; the others do not', (t) async {
      await boot(t, adjusted: true);
      expect(find.byType(OrderCard), findsNWidgets(2));
      expect(find.text('Updated'), findsOneWidget);
      final card = find.ancestor(of: find.text('Updated'), matching: find.byType(OrderCard));
      expect(
        find.descendant(of: card, matching: find.text('AB26o1')),
        findsOneWidget,
        reason: 'on the changed order',
      );
      expect(t.takeException(), isNull);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 10));
    });

    testWidgets('no changed order: no chip, the list as before', (t) async {
      await boot(t, adjusted: false);
      expect(find.byType(OrderCard), findsNWidgets(2));
      expect(find.text('Updated'), findsNothing);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 10));
    });

    testWidgets('in Arabic', (t) async {
      await boot(t, adjusted: true, locale: 'ar');
      expect(find.text(ar.orderUpdatedChip), findsOneWidget);
      expect(t.takeException(), isNull);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 10));
    });
  });

  test('the banner and the chip come from one set of words in both languages', () {
    for (final l in [en, ar]) {
      expect(l.orderUpdatedTitle, isNotEmpty);
      expect(l.orderUpdatedChip, isNotEmpty);
      expect(l.downloadUpdatedInvoice, contains('PDF'));
      expect(l.orderUpdatedNote('x'), contains('x'));
      expect(l.orderUpdatedLineReduced('A', 3, 2), allOf(contains('A'), contains('3'), contains('2')));
      expect(l.orderItemReplacedBy('B'), contains('B'));
      expect(l.orderItemReplaces('B'), contains('B'));
      expect(l.orderItemReducedFrom(4), contains('4'));
    }
  });
}
