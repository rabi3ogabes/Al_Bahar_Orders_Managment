// A deal at checkout (the quote's words for each line, the "Deal savings" row, `409 deal_changed`) and on a placed order.
import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/router/navigation_lock.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/widgets/common.dart' show SummaryRow;
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_line_text.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/order_sections.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/checkout_backend.dart';
import '../../helpers/fakes.dart';
import 'deals_fixtures.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

Map<String, dynamic> _line({int quantity = 3, Object? deal}) => {
  'variant_id': 'v1',
  'name': 'Oak table',
  'quantity': quantity,
  'unit_price': price('100.00'),
  'line_subtotal': money('300.00'),
  'line_total': money('240.00'),
  'deal': deal,
};

Map<String, dynamic> _lineDeal({int deal = 2, int regular = 1, String? note = 'limit_per_order'}) => {
  'deal_id': 'd1',
  'name': 'Weekend Furniture Drop',
  'units_at_deal_price': deal,
  'units_at_regular_price': regular,
  'savings': money('60.00'),
  'ends_at': iso(dealsNow.add(const Duration(hours: 5))),
  'note': note,
};

/// The quote the server sends for the same basket: [total] is the grand total, [dealSavings] what the deal takes off.
Map<String, dynamic> _quote(
  CheckoutBackend backend,
  RecordedRequest r, {
  required String hash,
  required String total,
  String dealSavings = '60.00',
  bool promotion = false,
  bool deals = true,
  Object? lineDeal,
}) {
  final base = backend.quoteFor(r);
  return {
    ...base,
    'quote_hash': hash,
    'lines': [_line(deal: deals ? (lineDeal ?? _lineDeal()) : null)],
    'totals': {
      'subtotal': money('300.00'),
      'discount': money(deals ? (promotion ? '65.00' : dealSavings) : '5.00'),
      if (deals) 'deal_savings': money(dealSavings),
      'delivery_fee': money('25.00'),
      'grand_total': money(total),
    },
    'discounts': [
      if (deals) {'source': 'deal', 'label': 'Weekend Furniture Drop', 'amount': money(dealSavings)},
      if (promotion || !deals) {'source': 'promotion', 'label': 'Autumn sale', 'amount': money('5.00')},
    ],
  };
}

class _Shop {
  _Shop({this.promotion = false, this.changedContext = true, this.deals = true})
    : backend = CheckoutBackend(deliveryPublishesSlots: false);

  final CheckoutBackend backend;
  final bool promotion;

  /// False: the quote of an older server, with no deal fields at all.
  final bool deals;

  /// Whether the 409 carries the fresh quote (the real server always does).
  final bool changedContext;

  /// The hash of the quote the customer is shown now; the first quote is `h1`, after a `deal_changed` it is `h2`.
  var hash = 'h1';
  var total = '125.00';
  var placeCalls = 0;
  var quoteCalls = 0;

  FakeHandler get handler => (r) {
    if (r.method == 'POST' && r.path == '/checkout/quote') {
      quoteCalls++;
      return ok(_quote(backend, r, hash: hash, total: total, promotion: promotion, deals: deals));
    }
    if (r.method == 'POST' && r.path == '/checkout/orders') {
      placeCalls++;
      if (r.body['quote_hash'] == 'h1') {
        // A deal ran out while the customer was paying: the quote now is h2 with less of the deal.
        hash = 'h2';
        total = '140.00';
        return error(
          409,
          'deal_changed',
          context: {
            'deal_id': 'd1',
            'product_id': 'p1',
            if (changedContext)
              'quote': _quote(
                backend,
                r,
                hash: 'h2',
                total: '140.00',
                dealSavings: '30.00',
                lineDeal: _lineDeal(deal: 1, regular: 2),
              ),
          },
        );
      }
      return ok({
        'order': {'id': 'o1', 'number': 'AB1'},
        'payment': {'payment_id': 'p1', 'method': 'cod', 'status': 'pending', 'action': 'none'},
      }, status: 201);
    }
    return backend.handler(r);
  };
}

/// A [_Shop] answering through [replace] (a test that changes what the server sends).
class _ShopWith extends _Shop {
  _ShopWith(_Shop inner, this.replace) : super(promotion: inner.promotion, deals: inner.deals);
  final FakeHandler replace;

  @override
  FakeHandler get handler => replace;
}

Future<void> _settle(WidgetTester t, [int frames = 24]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

Future<TestHarness> _pumpCheckout(
  WidgetTester t,
  _Shop shop, {
  Locale locale = const Locale('en'),
  Size size = const Size(900, 3200),
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(
    tokens: _signedIn,
    handler: shop.handler,
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

AppLocalizations get _en => lookupAppLocalizations(const Locale('en'));

void main() {
  group('the checkout summary', () {
    testWidgets('each line says what its deal did, and the deals are one "Deal savings" row', (t) async {
      await _pumpCheckout(t, _Shop(promotion: true));
      expect(find.text('Weekend Furniture Drop · Deal price on 2 of 3 · limit 2 per order'), findsOneWidget);
      expect(find.text('Deal savings'), findsOneWidget);
      expect(find.text('− QAR 60.00'), findsOneWidget);
      // The deal is not listed a second time among the discounts; a promotion still is.
      expect(find.text('Weekend Furniture Drop'), findsNothing);
      expect(find.text('Autumn sale'), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('totals.discount already includes the deals: it is never shown next to the "Deal savings" row', (
      t,
    ) async {
      final shop = _Shop();
      final inner = shop.handler;
      // A quote whose `discounts` list is empty but whose totals carry the deal savings (and the discount that includes them).
      FutureOr<FakeReply> handler(RecordedRequest r) async {
        final reply = await inner(r);
        if (r.path != '/checkout/quote') return reply;
        final body = reply.body! as Map<String, dynamic>;
        final quote = Map<String, dynamic>.from(body['data'] as Map<String, dynamic>)..['discounts'] = <Object?>[];
        return ok(quote);
      }

      await _pumpCheckout(t, _ShopWith(shop, handler));
      expect(find.text('Deal savings'), findsOneWidget);
      expect(find.text('Discount'), findsNothing);
    });

    testWidgets('a quote with no deal fields (an older server) draws nothing about deals', (t) async {
      await _pumpCheckout(t, _Shop(deals: false));
      expect(find.textContaining('Deal price'), findsNothing);
      expect(find.text('Deal savings'), findsNothing);
      expect(find.text('Autumn sale'), findsOneWidget, reason: 'the other discounts are listed as they always were');
      expect(t.takeException(), isNull);
    });

    testWidgets('Arabic on a 320 dp phone', (t) async {
      await _pumpCheckout(t, _Shop(), locale: const Locale('ar'), size: const Size(320, 3200));
      expect(find.textContaining('سعر العرض على 2 من 3'), findsOneWidget);
      expect(find.text('وفّرت من العروض'), findsOneWidget);
      expect(t.takeException(), isNull);
    });
  });

  group('409 deal_changed', () {
    Future<(TestHarness, _Shop, CheckoutController)> start({bool context = true}) async {
      final shop = _Shop(changedContext: context);
      final h = await TestHarness.create(tokens: _signedIn, handler: shop.handler);
      h.container.listen(checkoutControllerProvider, (_, _) {});
      await h.container.read(cartControllerProvider.future);
      final c = h.read(checkoutControllerProvider.notifier);
      c.setAlternateDeclined(true);
      await c.update((o) => o.copyWith(addressId: () => 'a1'));
      return (h, shop, c);
    }

    test(
      'the fresh quote replaces the old one, the old total is remembered, and nothing is sent again by itself',
      () async {
        final (h, shop, c) = await start();
        expect(h.read(checkoutControllerProvider).quote!.totals.grandTotal.amount, '125.00');
        final placed = await c.placeOrder();
        expect(placed, isNull);
        final s = h.read(checkoutControllerProvider);
        expect(s.dealChanged, isTrue);
        expect(s.quoteChanged, isFalse, reason: 'the calm banner, not the "totals changed" dialog');
        expect(s.placing, isFalse);
        expect(s.previousTotal!.amount, '125.00');
        expect(s.quote!.totals.grandTotal.amount, '140.00');
        expect(s.quote!.totals.dealSavings!.amount, '30.00');
        expect(s.quote!.lines.single.deal!.units.unitsAtDealPrice, 1);
        expect(s.placeError, isNull);
        await Future<void>.delayed(const Duration(milliseconds: 50));
        expect(shop.placeCalls, 1, reason: 'never auto-resubmitted');
        expect(s.canPlace, isTrue, reason: 'the customer can confirm the new total');
      },
    );

    test('confirming again places the order with the new quote and its own key; the banner is gone', () async {
      final (h, shop, c) = await start();
      await c.placeOrder();
      expect(h.read(checkoutControllerProvider).dealChanged, isTrue);
      final placed = await c.placeOrder();
      expect(placed!.orderNumber, 'AB1');
      expect(shop.placeCalls, 2);
      expect(h.read(checkoutControllerProvider).dealChanged, isFalse);
      final posts = h.adapter.where('POST', '/checkout/orders');
      expect(posts[0].body['quote_hash'], 'h1');
      expect(posts[1].body['quote_hash'], 'h2');
      expect(posts[0].header('Idempotency-Key'), isNot(posts[1].header('Idempotency-Key')));
    });

    test('a 409 without the quote asks the server for a fresh one', () async {
      final (h, shop, c) = await start(context: false);
      final before = shop.quoteCalls;
      await c.placeOrder();
      await Future<void>.delayed(const Duration(milliseconds: 50));
      final s = h.read(checkoutControllerProvider);
      expect(s.dealChanged, isTrue);
      expect(shop.quoteCalls, greaterThan(before));
      expect(s.quote!.totals.grandTotal.amount, '140.00');
      expect(shop.placeCalls, 1);
    });

    test('dismissing the banner, or changing anything, clears it', () async {
      final (h, _, c) = await start();
      await c.placeOrder();
      c.acknowledgeDealChange();
      expect(h.read(checkoutControllerProvider).dealChanged, isFalse);
      expect(h.read(checkoutControllerProvider).previousTotal, isNull);
      await c.placeOrder().then((_) {});
      final (h2, _, c2) = await start();
      await c2.placeOrder();
      await c2.update((o) => o.copyWith(loyaltyPoints: 0, addressId: () => 'a2'));
      expect(h2.read(checkoutControllerProvider).dealChanged, isFalse);
    });

    test(
      'an order on its way outlives its screen: the cart is refreshed, the menu is locked meanwhile and unlocked after',
      () async {
        final hold = Completer<void>();
        final shop = _Shop();
        final inner = shop.handler;
        final h = await TestHarness.create(
          tokens: _signedIn,
          handler: (r) async {
            if (r.method == 'POST' && r.path == '/checkout/orders') {
              await hold.future;
              return ok({
                'order': {'id': 'o1', 'number': 'AB1'},
                'payment': {'payment_id': 'p1', 'method': 'cod', 'status': 'pending', 'action': 'none'},
              }, status: 201);
            }
            return inner(r);
          },
        );
        final screen = h.container.listen(checkoutControllerProvider, (_, _) {});
        await h.container.read(cartControllerProvider.future);
        final c = h.read(checkoutControllerProvider.notifier);
        c.setAlternateDeclined(true);
        await c.update((o) => o.copyWith(addressId: () => 'a1'));
        final cartReads = h.adapter.where('GET', '/cart').length;
        expect(h.read(navigationLockProvider), isFalse);

        final placing = c.placeOrder();
        await Future<void>.delayed(const Duration(milliseconds: 20));
        expect(h.read(navigationLockProvider), isTrue, reason: 'the footer menu waits while the order is sent');
        // The customer walks off the page (it is the last listener of the controller).
        screen.close();
        await Future<void>.delayed(const Duration(milliseconds: 50));
        expect(
          h.container.exists(checkoutControllerProvider),
          isTrue,
          reason: 'the controller is kept alive while placing',
        );

        hold.complete();
        final placed = await placing;
        expect(placed!.orderNumber, 'AB1');
        await Future<void>.delayed(const Duration(milliseconds: 50));
        expect(
          h.adapter.where('GET', '/cart').length,
          greaterThan(cartReads),
          reason: 'the cart was converted into the order: it is read again',
        );
        expect(h.read(navigationLockProvider), isFalse, reason: 'the menu works again');
      },
    );

    test('another 409 is still an ordinary error', () async {
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: (r) => r.path == '/checkout/orders'
            ? error(409, 'stock_changed')
            : CheckoutBackend(deliveryPublishesSlots: false).handler(r),
      );
      h.container.listen(checkoutControllerProvider, (_, _) {});
      await h.container.read(cartControllerProvider.future);
      final c = h.read(checkoutControllerProvider.notifier);
      c.setAlternateDeclined(true);
      await c.update((o) => o.copyWith(addressId: () => 'a1'));
      await c.placeOrder();
      final s = h.read(checkoutControllerProvider);
      expect(s.dealChanged, isFalse);
      expect((s.placeError! as ApiException).code, 'stock_changed');
    });

    testWidgets('on the screen: a calm banner with the old total struck through, no dialog, one request', (t) async {
      final shop = _Shop();
      final h = await _pumpCheckout(t, shop);
      h.read(checkoutControllerProvider.notifier).setAlternateDeclined(true);
      await _settle(t);
      await t.tap(find.text(_en.placeOrder));
      await _settle(t);
      expect(find.text('A deal changed, your total was updated'), findsOneWidget);
      expect(find.text('Previously QAR 125.00'), findsOneWidget);
      expect(find.byType(AlertDialog), findsNothing);
      expect(shop.placeCalls, 1);
      expect(find.text('QAR 140.00'), findsWidgets, reason: 'the new total is on screen');
      expect(t.takeException(), isNull);
      // Closing the banner.
      await t.tap(find.byTooltip(_en.close));
      await _settle(t);
      expect(find.text('A deal changed, your total was updated'), findsNothing);
      expect(shop.placeCalls, 1);
    });
  });

  group('a placed order', () {
    Order order({Object? deal, String? dealSavings = '60.00', bool promotion = false}) => Order.fromJson({
      'id': 'o1',
      'number': 'AB1',
      'status': 'processing',
      'items': [
        {
          'id': 'i1',
          'product_id': 'p1',
          'name': 'Oak table',
          'quantity': 3,
          'unit_price': money('100.00'),
          'line_total': money('240.00'),
          'deal': deal,
        },
      ],
      'fulfillments': <Object?>[],
      'totals': {
        'subtotal': money('300.00'),
        'discount': money(promotion ? '65.00' : '60.00'),
        'deal_savings': dealSavings == null ? null : money(dealSavings),
        'delivery_fee': money('25.00'),
        'grand_total': money('265.00'),
      },
      'discounts': [
        {'source': 'deal', 'label': 'Weekend Furniture Drop', 'amount': money('60.00')},
        if (promotion) {'source': 'promotion', 'label': 'Autumn sale', 'amount': money('5.00')},
      ],
    });

    Future<void> pumpOrder(
      WidgetTester t,
      Order o, {
      Locale locale = const Locale('en'),
      Size size = const Size(390, 2400),
    }) async {
      t.view.physicalSize = size;
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({'GET /orders/o1/timeline': (_) => ok(const [])}),
      );
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
            home: Scaffold(
              body: SingleChildScrollView(
                child: OrderSections(order: o, actions: const SizedBox.shrink()),
              ),
            ),
          ),
        ),
      );
      await _settle(t, 8);
    }

    final saved = {'deal_id': 'd1', 'name': 'Weekend Furniture Drop', 'units': 2, 'unit_savings': money('30.00')};

    testWidgets(
      'the line names the deal, the units at the deal price and what each saved; the totals show "Deal savings"',
      (t) async {
        await pumpOrder(t, order(deal: saved));
        expect(find.text('Weekend Furniture Drop · Deal price on 2 of 3 · saved QAR 30.00 each'), findsOneWidget);
        expect(find.text('Deal savings'), findsOneWidget);
        expect(find.text('− QAR 60.00'), findsOneWidget);
        expect(find.text('Discount'), findsNothing, reason: 'the deal row replaces the single discount row');
        expect(t.takeException(), isNull);
      },
    );

    testWidgets('a promotion on top of a deal is listed by its own name', (t) async {
      await pumpOrder(t, order(deal: saved, promotion: true));
      expect(find.text('Deal savings'), findsOneWidget);
      expect(find.text('Autumn sale'), findsOneWidget);
      expect(find.text('− QAR 5.00'), findsOneWidget);
    });

    testWidgets('an order without a deal is as it was: the single discount row, no deal words', (t) async {
      await pumpOrder(t, order(dealSavings: '0.00'));
      expect(find.textContaining('Deal price'), findsNothing);
      expect(find.text('Deal savings'), findsNothing);
      expect(find.text('Discount'), findsOneWidget);
    });

    /// The totals card as (label, number) rows: a "−" in front of the amount is a deduction.
    List<(String, double)> totalsRows(WidgetTester t) => [
      for (final r in t.widgetList<SummaryRow>(find.byType(SummaryRow)))
        (r.label, double.parse(r.value.replaceAll(RegExp(r'[^0-9.]'), '')) * (r.value.startsWith('−') ? -1 : 1)),
    ];

    /// Subtotal plus every middle row (a deduction counts negative) must be the total: the card adds up.
    void expectAddsUp(WidgetTester t) {
      final rows = totalsRows(t);
      final subtotal = rows.first.$2;
      final total = rows.last.$2;
      final middle = rows.sublist(1, rows.length - 1).fold<double>(0, (a, r) => a + r.$2);
      expect(subtotal + middle, closeTo(total, 0.001), reason: rows.toString());
    }

    Order moneyOrder({
      required String discount,
      String? dealSavings,
      required String total,
      required List<Map<String, dynamic>> discounts,
    }) => Order.fromJson({
      'id': 'o1',
      'number': 'AB1',
      'status': 'processing',
      'items': <Object?>[],
      'fulfillments': <Object?>[],
      'totals': {
        'subtotal': money('500.00'),
        'discount': money(discount),
        'deal_savings': dealSavings == null ? null : money(dealSavings),
        'delivery_fee': money('30.00'),
        'grand_total': money(total),
      },
      'discounts': discounts,
    });

    final dealEntry = {'source': 'deal', 'label': 'Weekend Furniture Drop', 'amount': money('20.00')};
    final freeDelivery = {
      'source': 'promotion',
      'label': 'Free delivery',
      'code': 'FREESHIP',
      'amount': money('30.00'),
      'free_delivery': true,
    };

    testWidgets('a deal and a free-delivery coupon: every deduction is listed, so the rows add up to the total', (
      t,
    ) async {
      // discount_total counts the waived delivery: 20 (deal) + 30 (delivery) = 50; the delivery line is the full 30.
      await pumpOrder(
        t,
        moneyOrder(discount: '50.00', dealSavings: '20.00', total: '480.00', discounts: [dealEntry, freeDelivery]),
      );
      expect(find.text('Deal savings'), findsOneWidget);
      expect(find.text('Free delivery (FREESHIP)'), findsOneWidget);
      expect(find.text('− QAR 30.00'), findsOneWidget);
      expectAddsUp(t);
      expect(totalsRows(t).last.$2, 480);
    });

    testWidgets('a deal without a coupon adds up', (t) async {
      await pumpOrder(t, moneyOrder(discount: '20.00', dealSavings: '20.00', total: '510.00', discounts: [dealEntry]));
      expect(find.text('Deal savings'), findsOneWidget);
      expectAddsUp(t);
    });

    testWidgets('a free-delivery coupon without a deal adds up (the one discount row, as before)', (t) async {
      await pumpOrder(t, moneyOrder(discount: '30.00', total: '500.00', discounts: [freeDelivery]));
      expect(find.text('Deal savings'), findsNothing);
      expect(find.text('Discount'), findsOneWidget);
      expectAddsUp(t);
    });

    testWidgets('an older server (no deal fields at all)', (t) async {
      await pumpOrder(t, order(dealSavings: null));
      expect(find.text('Deal savings'), findsNothing);
      expect(find.text('Discount'), findsOneWidget);
    });

    testWidgets('Arabic on a 320 dp phone', (t) async {
      await pumpOrder(
        t,
        order(deal: saved),
        locale: const Locale('ar'),
        size: const Size(320, 2400),
      );
      expect(find.textContaining('سعر العرض على 2 من 3'), findsOneWidget);
      expect(find.text('وفّرت من العروض'), findsOneWidget);
      expect(t.takeException(), isNull);
    });
  });

  group('the banner widget', () {
    testWidgets('says it calmly, shows the previous total and closes', (t) async {
      var closed = false;
      await t.pumpWidget(
        MaterialApp(
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: Scaffold(
            body: DealChangedBanner(
              previousTotal: const Money(amount: '125.00', currency: 'QAR', formatted: 'QAR 125.00'),
              onDismiss: () => closed = true,
            ),
          ),
        ),
      );
      expect(find.text('A deal changed, your total was updated'), findsOneWidget);
      expect(find.text('Previously QAR 125.00'), findsOneWidget);
      await t.tap(find.byType(IconButton));
      expect(closed, isTrue);
    });
  });
}
