// Checkout fees v2 (docs/api/CHECKOUT_FEES_V2.md): no cash-on-delivery fee, no minimum order, and an extra delivery fee for bulky
// items shown under the delivery line (checkout totals and the cards of a split order).
import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/delivery_fee_row.dart';
import 'package:albahar_customer/features/checkout/presentation/extra_delivery_fee_row.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/checkout_backend.dart';
import '../../helpers/fakes.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

AppLocalizations get _en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get _ar => lookupAppLocalizations(const Locale('ar'));

/// The dashboard's "Alternative phone number" switch off, so the order is not waiting for an answer about it.
const _noAlternate = {'public_config': '{"alternate_phone_enabled":false}'};

Map<String, dynamic> _extra(
  String id,
  String name, {
  String mode = 'per_unit',
  int quantity = 1,
  String each = '20.00',
  String amount = '20.00',
  String? branch,
}) => {
  'product_id': id,
  'name': name,
  'mode': mode,
  'quantity': quantity,
  'fee_each': money(each),
  'amount': money(amount),
  'branch_id': ?branch,
};

/// Two bulky products: a table charged for every unit (2 × 20) and a mirror charged once (10), so 50 with the zone's 25 → 75 delivery.
final _tableAndMirror = [
  _extra('t1', 'Marble dining table', quantity: 2, amount: '40.00'),
  _extra('m1', 'Large wall mirror', mode: 'per_line', quantity: 3, each: '10.00', amount: '10.00'),
];

/// The checkout's backend, answering the way the quote engine of fees v2 does. [tweak] reshapes the quote the fake builds.
FakeHandler _shop({
  String delivery = '75.00',
  String? extra = '50.00',
  List<Map<String, dynamic>>? extras,
  bool coupon = false,
  String codFee = '0.00',
  String total = '175.00',
  bool alternate = true,
  Map<String, dynamic> Function(Map<String, dynamic> quote, RecordedRequest r)? tweak,
}) {
  final d = qDay(1);
  final b = CheckoutBackend(
    deliveryPublishesSlots: false,
    delivery: {
      'br1': {
        d: [slotRow('s-16', d, '16:00', '18:00')],
      },
    },
    pickup: {
      'br1': {
        d: [slotRow('p-10', d, '10:00', '12:00')],
      },
    },
  )..alternatePhoneEnabled = alternate;
  return (r) {
    if (r.method != 'POST' || r.path != '/checkout/quote') return b.handler(r);
    final quote = b.quoteFor(r);
    final pickup = quote['fulfillment_type'] == 'pickup';
    final fulfillment = (quote['fulfillments'] as List).first as Map<String, dynamic>;
    final shaped = {
      ...quote,
      'fulfillments': [
        {
          ...fulfillment,
          'delivery_fee': money(pickup ? '0.00' : delivery),
          if (!pickup && extra != null) 'extra_delivery_fee': money(extra),
          if (!pickup && extras != null) 'delivery_extras': extras,
        },
      ],
      'totals': {
        'subtotal': money('100.00'),
        'discount': money('0.00'),
        'delivery_fee': money(pickup ? '0.00' : delivery),
        if (!pickup && extra != null) 'extra_delivery_fee': money(extra),
        'service_fee': money('0.00'),
        'cod_fee': money(codFee),
        'grand_total': money(pickup ? '100.00' : (coupon ? '100.00' : total)),
      },
      if (!pickup && extras != null) 'delivery_extras': extras,
      'discounts': [
        if (coupon) {'label': 'Free delivery', 'code': 'FREESHIP', 'amount': money(delivery), 'free_delivery': true},
      ],
    };
    return ok(tweak == null ? shaped : tweak(shaped, r));
  };
}

Future<void> _settle(WidgetTester t, [int frames = 24]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

Future<TestHarness> _pump(
  WidgetTester t,
  FakeHandler handler, {
  Locale locale = const Locale('en'),
  Size size = const Size(900, 3200),
  double textScale = 1.0,
  Map<String, Object> prefs = const {},
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  t.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(t.view.reset);
  addTearDown(t.platformDispatcher.clearAllTestValues);
  final h = await TestHarness.create(
    tokens: _signedIn,
    handler: handler,
    prefs: {'locale': locale.languageCode, 'onboarding_done': true, ...prefs},
  );
  h.container.listen(checkoutControllerProvider, (_, _) {});
  h.container.listen(sessionControllerProvider, (_, _) {});
  await t.pumpWidget(
    UncontrolledProviderScope(
      container: h.container,
      child: MaterialApp(
        locale: locale,
        supportedLocales: supportedLocales,
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: AppTheme.light(locale),
        home: const CheckoutScreen(),
      ),
    ),
  );
  await _settle(t);
  return h;
}

final _extraRow = find.byKey(const ValueKey('extra-delivery-fee'));
final _toggle = find.byKey(const ValueKey('extra-delivery-fee-toggle'));
final _details = find.byKey(const ValueKey('extra-delivery-fee-details'));

/// The "Delivery" line of the totals when no coupon waived it.
Finder _plainDelivery() => find.widgetWithText(SummaryRow, _en.deliveryFee);

Future<void> _open(WidgetTester t) async {
  await t.ensureVisible(_toggle.first);
  await t.tap(_toggle.first);
  await _settle(t, 8);
}

void main() {
  group('Money.lenient', () {
    test('reads a money object, and a bare decimal from a server that sends one', () {
      expect(Money.lenient(money('50.00'))?.formatted, 'QAR 50.00');
      expect(Money.lenient('50.00')?.formatted, 'QAR 50.00');
      expect(Money.lenient(50)?.amount, '50.00');
      expect(Money.lenient(12.5)?.amount, '12.50');
      expect(Money.lenient('50.00', currency: 'USD')?.formatted, 'USD 50.00');
      expect(Money.lenient(null), isNull);
      expect(Money.lenient(''), isNull);
      expect(Money.lenient('soon'), isNull);
    });
  });

  group('the quote carries the extra delivery fee of bulky items', () {
    test('delivery_extras rows are read in full, one per product', () {
      final rows = DeliveryExtra.listFrom(_tableAndMirror);
      expect(rows, hasLength(2));
      expect(rows[0].name, 'Marble dining table');
      expect(rows[0].perUnit, isTrue);
      expect(rows[0].quantity, 2);
      expect(rows[0].feeEach?.formatted, 'QAR 20.00');
      expect(rows[0].amount.formatted, 'QAR 40.00');
      expect(rows[1].perUnit, isFalse, reason: 'per_line is charged once, whatever the quantity');
      expect(rows[1].quantity, 3);
    });

    test('a missing mode means every unit, a missing quantity means one, and a row that adds nothing is left out', () {
      final rows = DeliveryExtra.listFrom([
        {'product_id': 'a', 'name': 'A', 'amount': money('5.00')},
        {'product_id': 'b', 'name': 'B', 'amount': money('0.00')},
        'junk',
      ]);
      expect(rows, hasLength(1));
      expect(rows.single.perUnit, isTrue);
      expect(rows.single.quantity, 1);
      expect(rows.single.feeEach, isNull);
      expect(DeliveryExtra.listFrom(null), isEmpty);
      expect(DeliveryExtra.listFrom('nope'), isEmpty);
    });

    test('totals: the gross delivery fee stays whole, the extra part is read next to it', () {
      final t = OrderTotals.fromJson({
        'subtotal': money('100.00'),
        'delivery_fee': money('75.00'),
        'extra_delivery_fee': money('50.00'),
        'cod_fee': money('0.00'),
        'grand_total': money('175.00'),
      });
      expect(t.deliveryFee?.formatted, 'QAR 75.00');
      expect(t.extraDeliveryFee?.formatted, 'QAR 50.00');
      expect(t.codFee?.isZero, isTrue, reason: 'still parsed, never shown');
    });

    test('an older server sends none of it: no extras, and nothing breaks', () {
      final quote = Quote.fromJson({
        'quote_hash': 'h',
        'fulfillments': [
          {
            'branch': {'id': 'br1', 'name': 'West Bay'},
            'type': 'delivery',
            'delivery_fee': money('25.00'),
          },
        ],
        'totals': {'subtotal': money('100.00'), 'delivery_fee': money('25.00'), 'grand_total': money('125.00')},
      });
      expect(quote.extras, isNull);
      expect(quote.deliveryExtras, isEmpty);
      expect(quote.extrasOf(quote.fulfillments.single), isNull);
      expect(quote.totals.extraDeliveryFee, isNull);
    });

    test('a zero extra fee is none, a pickup never has one', () {
      Quote quote(String type, String extra) => Quote.fromJson({
        'quote_hash': 'h',
        'fulfillments': [
          {
            'branch': {'id': 'br1', 'name': 'West Bay'},
            'type': type,
            'delivery_fee': money('25.00'),
            'extra_delivery_fee': money(extra),
          },
        ],
        'totals': {
          'subtotal': money('100.00'),
          'delivery_fee': money('25.00'),
          'extra_delivery_fee': money(extra),
          'grand_total': money('125.00'),
        },
      });
      expect(quote('delivery', '0.00').extras, isNull);
      expect(quote('delivery', '10.00').extras?.fee.formatted, 'QAR 10.00');
      expect(quote('pickup', '10.00').extras, isNull);
      expect(quote('pickup', '10.00').extrasOf(quote('pickup', '10.00').fulfillments.single), isNull);
    });

    test('a split order: each plan shows its own extras, a plan whose own answer is zero shows none', () {
      final quote = Quote.fromJson({
        'quote_hash': 'h',
        'is_split': true,
        'fulfillments': [
          {
            'branch': {'id': 'br1', 'name': 'West Bay'},
            'type': 'delivery',
            'delivery_fee': money('55.00'),
            'extra_delivery_fee': money('30.00'),
            'delivery_extras': [_extra('t1', 'Table', amount: '30.00')],
          },
          {
            'branch': {'id': 'br2', 'name': 'Lusail'},
            'type': 'delivery',
            'delivery_fee': money('25.00'),
            'extra_delivery_fee': money('0.00'),
            'delivery_extras': <Object>[],
          },
        ],
        'totals': {
          'subtotal': money('200.00'),
          'delivery_fee': money('80.00'),
          'extra_delivery_fee': money('30.00'),
          'grand_total': money('280.00'),
        },
        'delivery_extras': [_extra('t1', 'Table', amount: '30.00', branch: 'br1')],
      });
      expect(quote.extrasOf(quote.fulfillments[0])?.fee.formatted, 'QAR 30.00');
      expect(quote.extrasOf(quote.fulfillments[0])?.items.single.name, 'Table');
      expect(quote.extrasOf(quote.fulfillments[1]), isNull, reason: 'its own answer is zero: never the order total');
      expect(quote.extras?.fee.formatted, 'QAR 30.00');
    });

    test('a split order whose plans carry no detail (an older server) shows the total only, never a wrong plan', () {
      final plan = {
        'branch': {'id': 'br1', 'name': 'West Bay'},
        'type': 'delivery',
        'delivery_fee': money('25.00'),
      };
      final quote = Quote.fromJson({
        'quote_hash': 'h',
        'is_split': true,
        'fulfillments': [
          plan,
          {
            ...plan,
            'branch': {'id': 'br2', 'name': 'Lusail'},
          },
        ],
        'totals': {
          'subtotal': money('200.00'),
          'delivery_fee': money('80.00'),
          'extra_delivery_fee': money('30.00'),
          'grand_total': money('280.00'),
        },
      });
      expect(quote.extras?.fee.formatted, 'QAR 30.00');
      expect(quote.extrasOf(quote.fulfillments[0]), isNull);
      expect(quote.extrasOf(quote.fulfillments[1]), isNull);
    });

    test('a single plan without its own detail falls back to the order total', () {
      final quote = Quote.fromJson({
        'quote_hash': 'h',
        'fulfillments': [
          {
            'branch': {'id': 'br1', 'name': 'West Bay'},
            'type': 'delivery',
            'delivery_fee': money('75.00'),
          },
        ],
        'totals': {
          'subtotal': money('100.00'),
          'delivery_fee': money('75.00'),
          'extra_delivery_fee': '50.00',
          'grand_total': money('175.00'),
        },
        'delivery_extras': _tableAndMirror,
      });
      final extras = quote.extrasOf(quote.fulfillments.single);
      expect(extras?.fee.formatted, 'QAR 50.00', reason: 'a bare decimal is read too');
      expect(extras?.items, hasLength(2));
    });
  });

  group('the delivery line of the checkout totals', () {
    testWidgets('keeps the whole fee and details the extra part under it', (t) async {
      await _pump(t, _shop(extras: _tableAndMirror));
      expect(find.descendant(of: _plainDelivery(), matching: find.text('QAR 75.00')), findsOneWidget);
      expect(_extraRow, findsOneWidget);
      expect(find.descendant(of: _extraRow, matching: find.text(_en.bulkyDeliveryFee)), findsOneWidget);
      expect(find.descendant(of: _extraRow, matching: find.text('QAR 50.00')), findsOneWidget);
      expect(_details, findsNothing, reason: 'closed until asked for');
      // It sits right after the delivery line, before the total.
      expect(t.getTopLeft(_extraRow).dy, greaterThan(t.getTopLeft(_plainDelivery()).dy));
      expect(t.getTopLeft(_extraRow).dy, lessThan(t.getTopLeft(find.widgetWithText(SummaryRow, _en.grandTotal)).dy));
      expect(find.text('QAR 175.00'), findsWidgets);
      expect(t.takeException(), isNull);
    });

    testWidgets('opening it lists each product: "×2 · QAR 20.00 each", "×3 · charged once", and the amounts', (
      t,
    ) async {
      await _pump(t, _shop(extras: _tableAndMirror));
      await _open(t);
      expect(_details, findsOneWidget);
      expect(find.descendant(of: _details, matching: find.text('Marble dining table')), findsOneWidget);
      expect(find.text('×2 · QAR 20.00 each'), findsOneWidget);
      expect(find.descendant(of: _details, matching: find.text('QAR 40.00')), findsOneWidget);
      expect(find.descendant(of: _details, matching: find.text('Large wall mirror')), findsOneWidget);
      expect(find.text('×3 · charged once'), findsOneWidget);
      expect(find.descendant(of: _details, matching: find.text('QAR 10.00')), findsOneWidget);
      expect(
        find.text(_en.bulkyDeliveryIncluded),
        findsOneWidget,
        reason: 'it is a part of the delivery fee, not an addition',
      );
      // The delivery line above did not change.
      expect(find.descendant(of: _plainDelivery(), matching: find.text('QAR 75.00')), findsOneWidget);

      await t.tap(_toggle);
      await _settle(t, 8);
      expect(_details, findsNothing, reason: 'a second tap closes it');
    });

    testWidgets('the list is the same in Arabic and reads right to left', (t) async {
      await _pump(t, _shop(extras: _tableAndMirror), locale: const Locale('ar'));
      expect(find.descendant(of: _extraRow, matching: find.text(_ar.bulkyDeliveryFee)), findsOneWidget);
      await _open(t);
      expect(find.text(_ar.bulkyDeliveryEach(2, 'QAR 20.00')), findsOneWidget);
      expect(find.text(_ar.bulkyDeliveryOnce(3)), findsOneWidget);
      expect(find.text(_ar.bulkyDeliveryIncluded), findsOneWidget);
      // Arabic starts on the right: the label sits to the right of its amount.
      final label = t.getTopRight(find.descendant(of: _extraRow, matching: find.text(_ar.bulkyDeliveryFee)));
      final amount = t.getTopLeft(find.descendant(of: _extraRow, matching: find.text('QAR 50.00')).first);
      expect(label.dx, greaterThan(amount.dx));
      expect(t.takeException(), isNull);
    });

    testWidgets('no extra fee: no extra line, as before', (t) async {
      await _pump(t, _shop(delivery: '25.00', extra: '0.00', total: '125.00'));
      expect(_extraRow, findsNothing);
      expect(find.text(_en.bulkyDeliveryFee), findsNothing);
      expect(find.descendant(of: _plainDelivery(), matching: find.text('QAR 25.00')), findsOneWidget);
    });

    testWidgets('an older server that sends nothing about it: no extra line', (t) async {
      await _pump(t, _shop(delivery: '25.00', extra: null, total: '125.00'));
      expect(_extraRow, findsNothing);
      expect(find.descendant(of: _plainDelivery(), matching: find.text('QAR 25.00')), findsOneWidget);
    });

    testWidgets('a fee without the list of products is a plain line that does not open', (t) async {
      await _pump(t, _shop());
      expect(_extraRow, findsOneWidget);
      expect(find.descendant(of: _extraRow, matching: find.text('QAR 50.00')), findsOneWidget);
      expect(_toggle, findsNothing);
    });

    testWidgets('pickup shows no delivery fee and no extra fee, even from a server that sent one', (t) async {
      final h = await _pump(
        t,
        _shop(
          extras: _tableAndMirror,
          tweak: (q, r) {
            // A pickup quote that wrongly still carries the extra part: the app never shows it.
            if (q['fulfillment_type'] != 'pickup') return q;
            return {
              ...q,
              'totals': {...(q['totals'] as Map<String, dynamic>), 'extra_delivery_fee': money('50.00')},
            };
          },
        ),
      );
      expect(_extraRow, findsOneWidget);
      unawaited(
        h
            .read(checkoutControllerProvider.notifier)
            .update((o) => o.copyWith(fulfillmentType: FulfillmentType.pickup, branchId: () => 'br1')),
      );
      await _settle(t);
      expect(_extraRow, findsNothing);
      expect(find.widgetWithText(SummaryRow, _en.deliveryFee), findsNothing);
      expect(find.byType(DeliveryFeeRow), findsNothing);
      expect(find.text(_en.bulkyDeliveryFee), findsNothing);
    });

    testWidgets(
      'a free-delivery coupon waives the whole fee: the delivery line is struck through and so is the extra part',
      (t) async {
        await _pump(t, _shop(extras: _tableAndMirror, coupon: true));
        final row = find.byType(DeliveryFeeRow);
        expect(row, findsOneWidget);
        final fee = t.widget<Text>(find.descendant(of: row, matching: find.text('QAR 75.00')));
        expect(fee.style?.decoration, TextDecoration.lineThrough, reason: 'the whole gross fee, extras included');
        expect(find.descendant(of: row, matching: find.text(_en.free)), findsOneWidget);
        expect(find.descendant(of: row, matching: find.text('FREESHIP')), findsOneWidget);
        final extra = t.widget<Text>(find.descendant(of: _extraRow, matching: find.text('QAR 50.00')));
        expect(extra.style?.decoration, TextDecoration.lineThrough);
        await _open(t);
        final line = t.widget<Text>(find.descendant(of: _details, matching: find.text('QAR 40.00')));
        expect(line.style?.decoration, TextDecoration.lineThrough);
        expect(find.text('QAR 100.00'), findsWidgets, reason: 'the total no longer holds any delivery');
        expect(t.takeException(), isNull);
      },
    );

    testWidgets('a screen reader hears the label and the amount, and that it opens', (t) async {
      final handle = t.ensureSemantics();
      await _pump(t, _shop(extras: _tableAndMirror));
      final label = '${_en.bulkyDeliveryFee}, QAR 50.00';
      expect(find.bySemanticsLabel(label), findsOneWidget);
      expect(t.getSemantics(find.bySemanticsLabel(label)).hint, _en.bulkyDeliveryShow);
      await _open(t);
      expect(t.getSemantics(find.bySemanticsLabel(label)).hint, _en.bulkyDeliveryHide);
      handle.dispose();
    });

    for (final locale in ['en', 'ar']) {
      testWidgets('$locale on a 320 px phone with text x1.3: the extra fee and its list fit', (t) async {
        await _pump(
          t,
          _shop(extras: _tableAndMirror),
          locale: Locale(locale),
          size: const Size(320, 3600),
          textScale: 1.3,
        );
        expect(_extraRow, findsOneWidget);
        await _open(t);
        expect(_details, findsOneWidget);
        expect(find.text('Marble dining table'), findsOneWidget);
        expect(t.takeException(), isNull);
      });
    }

    testWidgets('long product names wrap on a narrow phone instead of overflowing', (t) async {
      await _pump(
        t,
        _shop(
          extras: [
            _extra('t1', 'Extra-large solid oak marble top dining table with six matching chairs and a glass cabinet'),
          ],
          extra: '20.00',
        ),
        size: const Size(320, 3600),
        textScale: 1.3,
      );
      await _open(t);
      expect(t.takeException(), isNull);
    });
  });

  group('no cash-on-delivery fee', () {
    testWidgets('a quote that still carries a COD fee never shows it, and cash on delivery stays an option', (t) async {
      await _pump(t, _shop(delivery: '25.00', extra: '0.00', codFee: '5.00', total: '130.00'));
      expect(find.text(_en.codFee), findsNothing);
      expect(find.textContaining('Cash on delivery fee'), findsNothing);
      expect(find.text('QAR 5.00'), findsNothing);
      expect(find.text(_en.payCod), findsOneWidget, reason: 'paying cash is still offered, only its fee is gone');
    });

    testWidgets('the same in Arabic', (t) async {
      await _pump(
        t,
        _shop(delivery: '25.00', extra: '0.00', codFee: '5.00', total: '130.00'),
        locale: const Locale('ar'),
      );
      expect(find.text(_ar.codFee), findsNothing);
      expect(find.text('QAR 5.00'), findsNothing);
      expect(find.text(_ar.payCod), findsOneWidget);
    });

    testWidgets('choosing cash on delivery changes no total', (t) async {
      final h = await _pump(t, _shop(delivery: '25.00', extra: '0.00', total: '125.00'));
      unawaited(
        h.read(checkoutControllerProvider.notifier).update((o) => o.copyWith(paymentMethod: () => PaymentMethod.cod)),
      );
      await _settle(t);
      expect(find.text('QAR 125.00'), findsWidgets);
      expect(find.text(_en.codFee), findsNothing);
    });
  });

  group('no minimum order', () {
    testWidgets(
      'the app says nothing about a minimum, and an old server\'s below_min_order is an ordinary quote error',
      (t) async {
        final h = await _pump(
          t,
          _shop(
            delivery: '25.00',
            extra: '0.00',
            total: '125.00',
            alternate: false,
            tweak: (q, r) => {
              ...q,
              'can_place_order': false,
              'errors': [
                {'code': 'below_min_order', 'message': 'The minimum order for West Bay is QAR 200.00.'},
              ],
            },
          ),
          prefs: _noAlternate,
        );
        // The server's own words are shown like any other quote problem (no banner, no special button, no extra text from the app).
        expect(find.text('The minimum order for West Bay is QAR 200.00.'), findsOneWidget);
        expect(find.textContaining('more to'), findsNothing);
        expect(find.text(_en.cannotPlaceOrder), findsOneWidget, reason: 'the same plain hint as any other refusal');
        expect(h.read(checkoutControllerProvider).canPlace, isFalse);
      },
    );

    testWidgets('with no minimum error from the server the order simply goes ahead', (t) async {
      final h = await _pump(
        t,
        _shop(delivery: '25.00', extra: '0.00', total: '125.00', alternate: false),
        prefs: _noAlternate,
      );
      expect(h.read(checkoutControllerProvider).canPlace, isTrue);
      expect(find.textContaining('minimum', findRichText: true), findsNothing);
      expect(find.textContaining('Minimum', findRichText: true), findsNothing);
    });
  });

  group('the cards of a split order', () {
    Map<String, dynamic> splitQuote(Map<String, dynamic> q, {bool perPlan = true, bool coupon = false}) {
      final base = (q['fulfillments'] as List).first as Map<String, dynamic>;
      return {
        ...q,
        'is_split': true,
        'fulfillments': [
          {
            ...base,
            'branch': {'id': 'br1', 'name': 'West Bay'},
            'delivery_fee': money('55.00'),
            if (perPlan) 'extra_delivery_fee': money('30.00'),
            if (perPlan)
              'delivery_extras': [_extra('t1', 'Marble dining table', quantity: 3, amount: '30.00', each: '10.00')],
          },
          {
            ...base,
            'branch': {'id': 'br2', 'name': 'Lusail'},
            'delivery_fee': money('25.00'),
            if (perPlan) 'extra_delivery_fee': money('0.00'),
            if (perPlan) 'delivery_extras': <Object>[],
          },
        ],
        'totals': {
          'subtotal': money('200.00'),
          'discount': money('0.00'),
          'delivery_fee': money('80.00'),
          'extra_delivery_fee': money('30.00'),
          'service_fee': money('0.00'),
          'grand_total': money(coupon ? '200.00' : '280.00'),
        },
        'delivery_extras': [
          _extra('t1', 'Marble dining table', quantity: 3, amount: '30.00', each: '10.00', branch: 'br1'),
        ],
        'discounts': [
          if (coupon) {'label': 'Free delivery', 'code': 'FREESHIP', 'amount': money('80.00'), 'free_delivery': true},
        ],
      };
    }

    Finder card(String branch) =>
        find.ancestor(of: find.text(_en.fulfilledFrom(branch)), matching: find.byType(SurfaceCard)).first;

    testWidgets('the plan with a bulky product details its extra fee, the other plan does not', (t) async {
      await _pump(t, _shop(tweak: (q, r) => splitQuote(q)));
      // The card of the first plan and the order total each have their own line.
      expect(_extraRow, findsNWidgets(2));
      expect(find.descendant(of: card('West Bay'), matching: _extraRow), findsOneWidget);
      expect(find.descendant(of: card('West Bay'), matching: find.text('QAR 55.00')), findsOneWidget);
      expect(find.descendant(of: card('West Bay'), matching: find.text('QAR 30.00')), findsOneWidget);
      expect(find.descendant(of: card('Lusail'), matching: _extraRow), findsNothing);
      expect(find.descendant(of: card('Lusail'), matching: find.text('QAR 25.00')), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('a server that does not detail the plans still gets the order total line, and no wrong card', (
      t,
    ) async {
      await _pump(t, _shop(extra: null, tweak: (q, r) => splitQuote(q, perPlan: false)));
      expect(_extraRow, findsOneWidget, reason: 'the order total only');
      expect(find.descendant(of: card('West Bay'), matching: _extraRow), findsNothing);
    });

    testWidgets('with a free-delivery coupon each card strikes its delivery fee and its extra part', (t) async {
      await _pump(t, _shop(tweak: (q, r) => splitQuote(q, coupon: true)));
      final fee = t.widget<Text>(find.descendant(of: card('West Bay'), matching: find.text('QAR 55.00')));
      expect(fee.style?.decoration, TextDecoration.lineThrough);
      final extra = t.widget<Text>(
        find.descendant(
          of: find.descendant(of: card('West Bay'), matching: _extraRow),
          matching: find.text('QAR 30.00'),
        ),
      );
      expect(extra.style?.decoration, TextDecoration.lineThrough);
    });

    testWidgets('a small phone, Arabic and larger text: the cards and their extra line fit', (t) async {
      await _pump(
        t,
        _shop(tweak: (q, r) => splitQuote(q)),
        locale: const Locale('ar'),
        size: const Size(320, 4200),
        textScale: 1.3,
      );
      expect(_extraRow, findsNWidgets(2));
      expect(t.takeException(), isNull);
    });
  });

  testWidgets('the line alone: a closed line shows the label and the amount, a tap opens the products', (t) async {
    await t.pumpWidget(
      wrapApp(
        ExtraDeliveryFeeRow(
          extras: DeliveryExtras(fee: Money.lenient('50.00')!, items: DeliveryExtra.listFrom(_tableAndMirror)),
        ),
      ),
    );
    expect(find.text(_en.bulkyDeliveryFee), findsOneWidget);
    expect(find.text('QAR 50.00'), findsOneWidget);
    await t.tap(_toggle);
    await t.pumpAndSettle();
    expect(find.text('Marble dining table'), findsOneWidget);
  });
}
