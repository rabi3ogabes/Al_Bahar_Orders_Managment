// The cart page under checkout fees v2: no minimum-order or cash-on-delivery words, and nothing about delivery that is not known yet
// (the delivery fee is quoted at checkout), on small phones, in Arabic and with larger text.
import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/cart/presentation/cart_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

AppLocalizations _l10n(String code) => lookupAppLocalizations(Locale(code));

Map<String, dynamic> _cart({List<String> warnings = const []}) => {
  'id': 'c1',
  'branch_id': 'br1',
  'fulfillment_type': 'delivery',
  'address_id': 'a1',
  'items': [
    {
      'id': 'i1',
      'variant_id': 'v1',
      'product': {
        ...productCard('p1'),
        'name': 'Extra-large solid oak marble top dining table with six matching chairs',
      },
      'variant_name': 'Natural oak / Carrara marble / 240 cm',
      'quantity': 2,
      'unit_price': price('1250.00'),
      'line_total': money('2500.00'),
    },
    {
      'id': 'i2',
      'variant_id': 'v2',
      'product': productCard('p2'),
      'quantity': 1,
      'unit_price': price('100.00'),
      'line_total': money('100.00'),
    },
  ],
  'items_count': 3,
  'subtotal': money('2600.00'),
  'warnings': warnings,
};

Future<void> _settle(WidgetTester t, [int frames = 16]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pump(
  WidgetTester t, {
  String locale = 'en',
  Size size = const Size(390, 1800),
  double textScale = 1.0,
  List<String> warnings = const [],
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  t.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(t.view.reset);
  addTearDown(t.platformDispatcher.clearAllTestValues);
  final h = await TestHarness.create(
    tokens: _signedIn,
    prefs: {'locale': locale, 'onboarding_done': true},
    handler: routes({'GET /cart': (_) => ok(_cart(warnings: warnings)), 'GET /cart/suggestions': (_) => ok(const [])}),
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
  await _settle(t);
}

void main() {
  for (final code in ['en', 'ar']) {
    group('the cart page in $code', () {
      testWidgets('shows the subtotal and the one note about delivery, and nothing about a minimum or a cash fee', (
        t,
      ) async {
        await _pump(t, locale: code);
        final l10n = _l10n(code);
        expect(find.text('QAR 2600.00'), findsWidgets);
        expect(
          find.text(l10n.totalsCalculatedAtCheckout),
          findsOneWidget,
          reason: 'delivery is quoted at checkout, the page says so',
        );
        final words = RegExp(
          'minimum|min\\. order|cash on delivery|COD|add .* more|الحد الأدنى|رسوم الدفع|الدفع عند',
          caseSensitive: false,
        );
        expect(find.textContaining(words, findRichText: true), findsNothing);
        // No delivery fee or "free delivery" is promised before the quote exists.
        expect(find.text(l10n.free), findsNothing);
        expect(find.textContaining(RegExp('QAR [0-9.,]+ delivery', caseSensitive: false)), findsNothing);
        expect(t.takeException(), isNull);
      });

      testWidgets('a small phone with larger text and a long product name fit without overflow', (t) async {
        await _pump(t, locale: code, size: const Size(320, 2600), textScale: 1.3);
        expect(find.byType(CartLine), findsNWidgets(2));
        expect(find.text(_l10n(code).proceedToCheckout), findsOneWidget);
        expect(t.takeException(), isNull);
      });
    });
  }

  testWidgets('a warning from the server is shown as it is, with no special minimum-order banner', (t) async {
    await _pump(t, warnings: ['Price of Marble table changed from QAR 1,200.00 to QAR 1,250.00']);
    expect(find.text('Price of Marble table changed from QAR 1,200.00 to QAR 1,250.00'), findsOneWidget);
    expect(find.textContaining('minimum', findRichText: true), findsNothing);
  });
}
