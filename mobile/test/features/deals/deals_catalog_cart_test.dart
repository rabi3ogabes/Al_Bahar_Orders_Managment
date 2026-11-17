// A deal on the product card, on the product page and on a cart line (docs/api/DEALS.md section 5).
import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/widgets/product_card.dart';
import 'package:albahar_customer/features/cart/presentation/cart_screen.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_widget.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import '../../qa/test_fonts.dart';
import '../catalog/product_page_host.dart';
import 'deals_fixtures.dart';

Widget _card(
  Map<String, dynamic> json, {
  Locale locale = const Locale('en'),
  double width = 164,
  double? height,
  double textScale = 1,
}) {
  return MaterialApp(
    locale: locale,
    supportedLocales: supportedLocales,
    localizationsDelegates: const [
      AppLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    theme: AppTheme.light(locale),
    builder: (context, c) => MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(textScale)),
      child: c!,
    ),
    home: Scaffold(
      body: Center(
        child: SizedBox(
          width: width,
          height: height,
          child: ProductCardView(product: ProductSummary.fromJson(json)),
        ),
      ),
    ),
  );
}

Map<String, dynamic> _inDeal(Map<String, dynamic> deal) => dealProductJson('p1', deal: deal);

void main() {
  setUpAll(() async {
    await loadAppFonts();
    dealsBase = DateTime.now;
  });
  tearDownAll(() => dealsBase = () => dealsNow);

  group('the product card', () {
    testWidgets('a live deal: the deal price, the regular price struck through, "-30%" and the stock', (t) async {
      await t.pumpWidget(_card(_inDeal(dealBlockJson())));
      expect(find.text('QAR 70.00'), findsOneWidget);
      expect(find.text('QAR 100.00'), findsOneWidget);
      final struck = t.widget<Text>(find.text('QAR 100.00'));
      expect(struck.style?.decoration, TextDecoration.lineThrough);
      expect(find.text('-30%'), findsOneWidget);
      expect(find.text('Only 12 left'), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('a screen reader hears the deal price and the offer', (t) async {
      final handle = t.ensureSemantics();
      await t.pumpWidget(_card(_inDeal(dealBlockJson())));
      expect(find.bySemanticsLabel(RegExp(r'Product p1, QAR 70\.00, 30% off')), findsOneWidget);
      handle.dispose();
    });

    testWidgets('no deal, or a deal this customer cannot use: the normal price, as always', (t) async {
      for (final json in [
        productCard('p1'),
        _inDeal(dealBlockJson(customerRemaining: 0)),
        _inDeal(dealBlockJson(soldOut: true, phase: 'sold_out')),
        _inDeal(dealBlockJson(phase: 'scheduled', startsAt: DateTime.now().add(const Duration(hours: 1)))),
        _inDeal(dealBlockJson(phase: 'ended')),
      ]) {
        await t.pumpWidget(_card(json));
        expect(find.text('QAR 100.00'), findsOneWidget, reason: '${json['deal']?['phase']}');
        expect(find.text('QAR 70.00'), findsNothing);
        expect(find.text('-30%'), findsNothing);
        expect(find.textContaining('left'), findsNothing);
        expect(t.widget<Text>(find.text('QAR 100.00')).style?.decoration, isNot(TextDecoration.lineThrough));
      }
    });

    testWidgets('on a screen with a server clock the card follows it: the deal price goes when the deal ends', (
      t,
    ) async {
      var now = dealsNow;
      final json = _inDeal(
        dealBlockJson(
          startsAt: dealsNow.subtract(const Duration(hours: 1)),
          endsAt: dealsNow.add(const Duration(seconds: 5)),
        ),
      );
      Widget scoped() => ProviderScope(
        key: UniqueKey(),
        overrides: [dealDeviceNowProvider.overrideWithValue(() => now)],
        child: DealClockHost(child: _card(json)),
      );
      await t.pumpWidget(scoped());
      expect(find.text('QAR 70.00'), findsOneWidget);
      now = dealsNow.add(const Duration(seconds: 6));
      await t.pumpWidget(scoped());
      expect(find.text('QAR 70.00'), findsNothing, reason: 'the server clock says it ended');
      expect(find.text('QAR 100.00'), findsOneWidget);
    });

    testWidgets('the design can leave the stock out, and unlimited stock has no stock line', (t) async {
      await t.pumpWidget(_card(_inDeal(dealBlockJson(design: designJson(stockBar: false)))));
      expect(find.textContaining('left'), findsNothing);
      expect(find.text('QAR 70.00'), findsOneWidget);
      await t.pumpWidget(_card(_inDeal(dealBlockJson(remaining: null, remainingPercent: null))));
      expect(find.textContaining('left'), findsNothing);
    });

    testWidgets('an amount-off deal has no percent badge but still the deal price', (t) async {
      await t.pumpWidget(_card(_inDeal(dealBlockJson(percent: null))));
      expect(find.text('QAR 70.00'), findsOneWidget);
      expect(find.textContaining('%'), findsNothing);
    });

    testWidgets('in a bounded grid cell, with large text, in Arabic and on a narrow phone: no overflow', (t) async {
      t.view.physicalSize = const Size(320, 700);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      for (final locale in const [Locale('en'), Locale('ar')]) {
        await t.pumpWidget(_card(_inDeal(dealBlockJson()), locale: locale, width: 140, height: 280, textScale: 1.6));
        expect(t.takeException(), isNull, reason: locale.languageCode);
        expect(find.text('Only 12 left'), locale.languageCode == 'en' ? findsOneWidget : findsNothing);
      }
    });
  });

  group('the product page', () {
    Map<String, dynamic> withDeal(Map<String, dynamic> deal, [Map<String, dynamic>? base]) => {
      ...(base ?? productJson()),
      'deal': deal,
    };

    testWidgets('a live deal: its price, the strip with the countdown and stock, and what the customer may buy', (
      t,
    ) async {
      await pumpProductPage(t, detail: withDeal(dealBlockJson(customerRemaining: 3)), signedIn: true);
      await settle(t);
      expect(find.text('QAR 70.00'), findsOneWidget);
      expect(find.text('QAR 100.00'), findsOneWidget);
      expect(find.byType(DealWidget), findsOneWidget);
      expect(find.text('Weekend Furniture Drop'), findsOneWidget);
      expect(find.text('Only 12 left'), findsOneWidget);
      expect(find.text('Limit 2 per order · You can buy 3 more at the deal price'), findsOneWidget);
      expect(find.text('Shop the deal'), findsNothing, reason: 'the shopper is already here');
      expect(t.takeException(), isNull);
    });

    testWidgets('a guest sees the account limit as a plain limit', (t) async {
      await pumpProductPage(t, detail: withDeal(dealBlockJson()));
      await settle(t);
      expect(find.text('Limit 2 per order · Limit 3 per customer'), findsOneWidget);
    });

    testWidgets('a customer who used the limit: one calm line and the normal price', (t) async {
      await pumpProductPage(t, detail: withDeal(dealBlockJson(customerRemaining: 0)), signedIn: true);
      await settle(t);
      expect(find.text('You reached your limit for this deal'), findsOneWidget);
      expect(find.text('The regular price applies to this product.'), findsOneWidget);
      expect(find.byType(DealWidget), findsNothing);
      expect(find.text('QAR 100.00'), findsOneWidget);
      expect(find.text('QAR 70.00'), findsNothing);
    });

    testWidgets('a sold-out deal says so; the normal price shows', (t) async {
      await pumpProductPage(
        t,
        detail: withDeal(dealBlockJson(phase: 'sold_out', soldOut: true, remaining: 0, remainingPercent: 0)),
      );
      await settle(t);
      expect(find.text('This deal is sold out'), findsOneWidget);
      expect(find.text('QAR 100.00'), findsOneWidget);
      expect(find.text('QAR 70.00'), findsNothing);
    });

    testWidgets('a deal that has not started counts down to its start; the price is the normal one', (t) async {
      await pumpProductPage(
        t,
        detail: withDeal(
          dealBlockJson(
            phase: 'scheduled',
            startsAt: DateTime.now().add(const Duration(hours: 3)),
            endsAt: DateTime.now().add(const Duration(days: 1)),
          ),
        ),
      );
      await settle(t);
      expect(find.text('Starts in'), findsOneWidget);
      expect(find.text('QAR 100.00'), findsOneWidget);
      expect(find.text('QAR 70.00'), findsNothing);
    });

    testWidgets('a product in no deal looks exactly as before', (t) async {
      await pumpProductPage(t);
      await settle(t);
      expect(find.byType(DealWidget), findsNothing);
      expect(find.text('QAR 100.00'), findsOneWidget);
    });

    testWidgets('variants: each size shows its own deal price, and a size with no deal shows none', (t) async {
      final base = sizedProductJson();
      final variants = (base['variants'] as List).cast<Map<String, dynamic>>();
      final json = withDeal(dealBlockJson(dealPrice: '70.00', regular: '100.00'), {
        ...base,
        'variants': [
          {...variants[0], 'deal_price': money('70.00')},
          {...variants[1], 'deal_price': null},
        ],
      });
      await pumpProductPage(t, detail: json);
      await settle(t);
      // The default size (Small) is the cheapest: the block as it is.
      expect(find.text('QAR 70.00'), findsOneWidget);
      expect(find.text('-30%'), findsWidgets);
      await t.drag(find.byType(CustomScrollView).first, const Offset(0, -420));
      await settle(t);
      await t.tap(find.text('Medium'));
      await settle(t);
      expect(find.byType(DealWidget), findsNothing, reason: 'Medium is not in the deal');
      expect(find.text('QAR 120.00'), findsWidgets);
      expect(find.text('QAR 70.00'), findsNothing);
    });

    testWidgets(
      'variants: a size with another deal price shows it, struck against its own price, without the percent',
      (t) async {
        final base = sizedProductJson();
        final variants = (base['variants'] as List).cast<Map<String, dynamic>>();
        final json = withDeal(dealBlockJson(dealPrice: '70.00', regular: '100.00'), {
          ...base,
          'variants': [
            {...variants[0], 'deal_price': money('70.00')},
            {...variants[1], 'deal_price': money('84.00')},
          ],
        });
        await pumpProductPage(t, detail: json);
        await settle(t);
        await t.drag(find.byType(CustomScrollView).first, const Offset(0, -420));
        await settle(t);
        await t.tap(find.text('Medium'));
        await settle(t);
        expect(find.text('QAR 84.00'), findsOneWidget);
        expect(find.text('QAR 120.00'), findsWidgets);
        expect(find.text('-30%'), findsNothing, reason: 'the percent is the cheapest size\'s');
        expect(find.byType(DealWidget), findsOneWidget);
      },
    );

    testWidgets('Arabic on a 320 dp phone with large text: no overflow', (t) async {
      await pumpProductPage(
        t,
        detail: withDeal(dealBlockJson(customerRemaining: 3, name: 'عرض نهاية الأسبوع للأثاث')),
        signedIn: true,
        size: const Size(320, 700),
        textScale: 1.6,
        locale: const Locale('ar'),
      );
      await settle(t);
      expect(t.takeException(), isNull);
      expect(find.byType(DealWidget), findsOneWidget);
    });
  });

  group('the cart', () {
    final signedIn = AuthTokens.fromJson(tokens('A', 'R'));

    Map<String, dynamic> cart({Object? lineDeal, Object? savings}) => {
      'id': 'c1',
      'branch_id': 'br1',
      'fulfillment_type': 'delivery',
      'address_id': 'a1',
      'items': [
        {
          'id': 'i1',
          'variant_id': 'v1',
          'product': productCard('p1'),
          'quantity': 3,
          'unit_price': price('100.00'),
          'line_total': money('300.00'),
          'deal': lineDeal,
        },
        {
          'id': 'i2',
          'variant_id': 'v2',
          'product': productCard('p2'),
          'quantity': 1,
          'unit_price': price('50.00'),
          'line_total': money('50.00'),
        },
      ],
      'items_count': 4,
      'subtotal': money('350.00'),
      'deal_savings': savings,
    };

    Future<void> pumpCart(
      WidgetTester t,
      Map<String, dynamic> body, {
      String locale = 'en',
      Size size = const Size(390, 1800),
    }) async {
      t.view.physicalSize = size;
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(
        tokens: signedIn,
        prefs: {'locale': locale, 'onboarding_done': true},
        handler: routes({'GET /cart': (_) => ok(body), 'GET /cart/suggestions': (_) => ok(const [])}),
      );
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: MaterialApp(
            locale: Locale(locale),
            supportedLocales: supportedLocales,
            localizationsDelegates: const [
              AppLocalizations.delegate,
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            theme: AppTheme.light(Locale(locale)),
            home: const CartScreen(),
          ),
        ),
      );
      for (var i = 0; i < 16; i++) {
        await t.pump(const Duration(milliseconds: 50));
      }
    }

    final lineDeal = {
      ...dealBlockJson(),
      'units_at_deal_price': 2,
      'units_at_regular_price': 1,
      'note': 'limit_per_order',
    };

    testWidgets('a line in a deal says how many units get the deal price, and the cart shows the deal savings', (
      t,
    ) async {
      await pumpCart(t, cart(lineDeal: lineDeal, savings: money('60.00')));
      expect(find.text('Weekend Furniture Drop · Deal price on 2 of 3 · limit 2 per order'), findsOneWidget);
      expect(find.text('Deal savings'), findsOneWidget);
      expect(find.text('− QAR 60.00'), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('variants of one product share the limit: the words use the real limit, never "limit 0 per order"', (
      t,
    ) async {
      Map<String, dynamic> variantLine(String id, int units, int regular) => {
        'id': id,
        'variant_id': 'v$id',
        'product': productCard('p1'),
        'quantity': units + regular,
        'unit_price': price('100.00'),
        'line_total': money('${(units + regular) * 100}.00'),
        'deal': {
          ...dealBlockJson(limitPerOrder: 2),
          'units_at_deal_price': units,
          'units_at_regular_price': regular,
          'note': 'limit_per_order',
        },
      };
      final body = cart();
      body['items'] = [variantLine('a', 1, 1), variantLine('b', 1, 0), variantLine('c', 0, 2)];
      await pumpCart(t, body);
      expect(find.text('Weekend Furniture Drop · Deal price on 1 of 2 · limit 2 per order'), findsOneWidget);
      expect(find.text('Weekend Furniture Drop · Deal price on all 1 · limit 2 per order'), findsOneWidget);
      expect(find.text('Weekend Furniture Drop · per-order limit reached'), findsOneWidget);
      expect(find.textContaining('limit 0'), findsNothing);
      expect(find.textContaining('limit 1'), findsNothing);
    });

    testWidgets('all units at the deal price', (t) async {
      await pumpCart(
        t,
        cart(lineDeal: {...dealBlockJson(), 'units_at_deal_price': 3, 'units_at_regular_price': 0, 'note': null}),
      );
      expect(find.text('Weekend Furniture Drop · Deal price on all 3'), findsOneWidget);
    });

    testWidgets('a limit already reached', (t) async {
      await pumpCart(
        t,
        cart(
          lineDeal: {
            ...dealBlockJson(),
            'units_at_deal_price': 0,
            'units_at_regular_price': 3,
            'note': 'limit_per_customer',
          },
        ),
      );
      expect(find.text('Weekend Furniture Drop · You reached your limit for this deal'), findsOneWidget);
    });

    testWidgets('no deal at all: nothing is added', (t) async {
      await pumpCart(t, cart());
      expect(find.textContaining('Deal price'), findsNothing);
      expect(find.text('Deal savings'), findsNothing);
    });

    testWidgets('zero savings add no row', (t) async {
      await pumpCart(t, cart(savings: money('0.00')));
      expect(find.text('Deal savings'), findsNothing);
    });

    testWidgets('a deal that has not started adds nothing to the line', (t) async {
      await pumpCart(
        t,
        cart(
          lineDeal: {
            ...dealBlockJson(phase: 'scheduled', startsAt: DateTime.now().add(const Duration(hours: 1))),
            'units_at_deal_price': 0,
            'units_at_regular_price': 3,
          },
        ),
      );
      expect(find.textContaining('Weekend'), findsNothing);
    });

    testWidgets('Arabic on a 320 dp phone: no overflow', (t) async {
      await pumpCart(
        t,
        cart(lineDeal: lineDeal, savings: money('60.00')),
        locale: 'ar',
        size: const Size(320, 1800),
      );
      expect(find.textContaining('سعر العرض على 2 من 3'), findsOneWidget);
      expect(find.text('وفّرت من العروض'), findsOneWidget);
      expect(t.takeException(), isNull);
    });
  });
}
