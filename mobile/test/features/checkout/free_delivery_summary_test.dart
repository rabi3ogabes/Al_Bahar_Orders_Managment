// Checkout totals: delivery always costs the zone fee (there is no "free above X" any more), so the delivery line is always there.
// The one way to waive it is a free-delivery coupon: the fee then stays on the line, struck through, with "Free" and the code.
import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/delivery_fee_row.dart';
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

/// The checkout's backend, with the quote answering the way the quote engine does: the zone [fee] stays in the totals and a free-delivery
/// coupon is listed as a discount flagged `free_delivery` (its amount is the fee it waived). [saved] is taken off the basket by an item
/// promotion. [total] is the grand total the server would send.
FakeHandler _shop({String fee = '25.00', bool coupon = false, bool saved = false, String total = '125.00'}) {
  final d = qDay(1);
  final backend = CheckoutBackend(
    deliveryPublishesSlots: false,
    delivery: {
      'br1': {
        d: [slotRow('s-16', d, '16:00', '18:00')],
      },
    },
  );
  return (r) {
    if (r.method != 'POST' || r.path != '/checkout/quote') return backend.handler(r);
    final quote = backend.quoteFor(r);
    final fulfillment = (quote['fulfillments'] as List).first as Map<String, dynamic>;
    return ok({
      ...quote,
      'fulfillments': [
        {...fulfillment, 'delivery_fee': money(fee)},
      ],
      'totals': {
        'subtotal': money('100.00'),
        'discount': money(saved ? '10.00' : '0.00'),
        'delivery_fee': money(fee),
        'service_fee': money('0.00'),
        'grand_total': money(total),
      },
      'discounts': [
        if (saved) {'label': 'Autumn sale', 'amount': money('10.00'), 'free_delivery': false},
        if (coupon) {'label': 'Free delivery', 'code': 'FREESHIP', 'amount': money(fee), 'free_delivery': true},
      ],
    });
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
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(
    tokens: _signedIn,
    handler: handler,
    prefs: {'locale': locale.languageCode, 'onboarding_done': true},
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

/// The "Delivery" line of the totals when no coupon waived it.
Finder _plainRow() => find.widgetWithText(SummaryRow, _en.deliveryFee);

/// [amount] on the "Total" line of the totals.
Finder _total(String amount) =>
    find.descendant(of: find.widgetWithText(SummaryRow, _en.grandTotal), matching: find.text(amount));

void main() {
  group('the quote says which discount is the free-delivery coupon', () {
    test('free_delivery is read from the discount, and is off when the server does not send it', () {
      final free = QuoteDiscount.fromJson({
        'label': 'Free delivery',
        'code': 'FREESHIP',
        'amount': money('25.00'),
        'free_delivery': true,
      });
      expect(free.freeDelivery, isTrue);
      expect(free.code, 'FREESHIP');
      final sale = QuoteDiscount.fromJson({'label': 'Autumn sale', 'amount': money('10.00')});
      expect(sale.freeDelivery, isFalse, reason: 'an older server sends no such flag');
    });

    test('the quote hands out the coupon that waived the fee, or nothing', () {
      final quote = Quote.fromJson({
        'quote_hash': 'h',
        'discounts': [
          {'label': 'Autumn sale', 'amount': money('10.00'), 'free_delivery': false},
          {'label': 'Free delivery', 'code': 'FREESHIP', 'amount': money('25.00'), 'free_delivery': true},
        ],
      });
      expect(quote.freeDeliveryDiscount?.code, 'FREESHIP');
      expect(Quote.fromJson({'quote_hash': 'h'}).freeDeliveryDiscount, isNull);
    });
  });

  group('the delivery line of the checkout totals', () {
    testWidgets('without a coupon the fee is always there, in full', (t) async {
      await _pump(t, _shop());
      expect(_plainRow(), findsOneWidget);
      expect(find.descendant(of: _plainRow(), matching: find.text('QAR 25.00')), findsOneWidget);
      expect(find.byType(DeliveryFeeRow), findsNothing);
      expect(_total('QAR 125.00'), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('a zone with no fee reads "Free", with nothing struck through', (t) async {
      await _pump(t, _shop(fee: '0.00', total: '100.00'));
      expect(_plainRow(), findsOneWidget);
      expect(find.descendant(of: _plainRow(), matching: find.text(_en.free)), findsOneWidget);
      expect(find.byType(DeliveryFeeRow), findsNothing);
    });

    testWidgets('a free-delivery coupon: the fee stays, struck through, followed by "Free" and the code', (t) async {
      await _pump(t, _shop(coupon: true, total: '100.00'));
      final row = find.byType(DeliveryFeeRow);
      expect(row, findsOneWidget);
      expect(_plainRow(), findsNothing, reason: 'the plain line is replaced, not repeated');

      final fee = t.widget<Text>(find.descendant(of: row, matching: find.text('QAR 25.00')));
      expect(fee.style?.decoration, TextDecoration.lineThrough, reason: 'the fee is struck through');
      expect(find.descendant(of: row, matching: find.text(_en.free)), findsOneWidget);
      expect(find.descendant(of: row, matching: find.text('FREESHIP')), findsOneWidget);
      expect(find.descendant(of: row, matching: find.text(_en.deliveryFee)), findsOneWidget);

      // The coupon is not listed a second time as "− fee": the line above already says it.
      expect(find.text('Free delivery (FREESHIP)'), findsNothing);
      expect(find.text('− QAR 25.00'), findsNothing);
      expect(_total('QAR 100.00'), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('a coupon on a zone with no fee: "Free" and the code, nothing to strike through', (t) async {
      await _pump(t, _shop(fee: '0.00', coupon: true, total: '100.00'));
      final row = find.byType(DeliveryFeeRow);
      expect(row, findsOneWidget);
      expect(find.descendant(of: row, matching: find.text(_en.free)), findsOneWidget);
      expect(find.descendant(of: row, matching: find.text('FREESHIP')), findsOneWidget);
      expect(find.descendant(of: row, matching: find.text('QAR 0.00')), findsNothing);
    });

    testWidgets('an item discount keeps its own line next to the free-delivery line', (t) async {
      await _pump(t, _shop(coupon: true, saved: true, total: '90.00'));
      expect(find.byType(DeliveryFeeRow), findsOneWidget);
      expect(find.text('Autumn sale'), findsOneWidget);
      expect(find.text('− QAR 10.00'), findsOneWidget);
      expect(find.text('Free delivery (FREESHIP)'), findsNothing);
    });

    testWidgets('a screen reader hears one sentence: free with the code, and what the fee was', (t) async {
      final handle = t.ensureSemantics();
      await _pump(t, _shop(coupon: true, total: '100.00'));
      final sentence =
          '${_en.deliveryFee}. ${_en.deliveryFreeWithCode('FREESHIP')}. ${_en.deliveryFeeWas('QAR 25.00')}';
      expect(find.bySemanticsLabel(sentence), findsOneWidget);
      handle.dispose();
    });

    testWidgets('Arabic on a small phone with larger text: the line fits and keeps its words', (t) async {
      t.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(t.platformDispatcher.clearAllTestValues);
      await _pump(
        t,
        _shop(coupon: true, total: '100.00'),
        locale: const Locale('ar'),
        size: const Size(320, 3200),
      );
      final row = find.byType(DeliveryFeeRow);
      expect(row, findsOneWidget);
      expect(find.descendant(of: row, matching: find.text(_ar.free)), findsOneWidget);
      expect(find.descendant(of: row, matching: find.text('FREESHIP')), findsOneWidget);
      expect(t.takeException(), isNull);
    });
  });
}
