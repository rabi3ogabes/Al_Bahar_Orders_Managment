import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_suggestions.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/upsell_widgets.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

ProductSummary _p(String id, String name) => ProductSummary.fromJson({
  'id': id,
  'name': name,
  'variant_id': 'v-$id',
  'has_variants': false,
  'in_stock': true,
  'price': {
    'regular': {'amount': '45.00', 'currency': 'QAR', 'formatted': 'QAR 45.00'},
    'final': {'amount': '45.00', 'currency': 'QAR', 'formatted': 'QAR 45.00'},
  },
});

class _EmptyCart extends CartController {
  @override
  Future<Cart> build() async => Cart.empty;
}

Widget _host(Widget child, List<Override> overrides, {Locale locale = const Locale('en'), bool dark = false}) =>
    ProviderScope(
      key: UniqueKey(),
      overrides: [cartControllerProvider.overrideWith(_EmptyCart.new), ...overrides],
      child: MaterialApp(
        locale: locale,
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: dark ? AppTheme.dark(locale) : AppTheme.light(locale),
        home: Scaffold(body: SingleChildScrollView(child: child)),
      ),
    );

void main() {
  testWidgets('the carousel shows each suggested product with its name, price and an add button', (tester) async {
    await tester.pumpWidget(
      _host(
        UpsellSection(title: 'Complete your set', products: [_p('a', 'Brass lamp'), _p('b', 'Oak shelf')], scope: 't'),
        const [],
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Complete your set'), findsOneWidget);
    expect(find.text('Brass lamp'), findsOneWidget);
    expect(find.text('Oak shelf'), findsOneWidget);
    expect(find.byIcon(Icons.add_rounded), findsNWidgets(2));
  });

  testWidgets('no suggestions hides the whole section, title included', (tester) async {
    await tester.pumpWidget(_host(const UpsellSection(title: 'Complete your set', products: [], scope: 't'), const []));
    await tester.pumpAndSettle();
    expect(find.text('Complete your set'), findsNothing);
  });

  testWidgets('renders in Arabic RTL and in dark mode', (tester) async {
    await tester.pumpWidget(
      _host(
        UpsellSection(title: 'أكمل طقمك', products: [_p('a', 'مصباح')], scope: 't'),
        const [],
        locale: const Locale('ar'),
        dark: true,
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('أكمل طقمك'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the cart strip shows a skeleton while loading, then the suggestions', (tester) async {
    final completer = Future<List<ProductSummary>>.delayed(
      const Duration(milliseconds: 50),
      () => [_p('a', 'Brass lamp')],
    );
    await tester.pumpWidget(
      _host(const CartSuggestionsStrip(), [cartSuggestionsProvider.overrideWith((ref) => completer)]),
    );
    await tester.pump();
    expect(find.text('You may also need'), findsNothing); // skeleton only (labelled for screen readers)
    expect(find.bySemanticsLabel('You may also need'), findsOneWidget);
    await tester.pumpAndSettle(const Duration(milliseconds: 100));
    expect(find.text('You may also need'), findsOneWidget);
    expect(find.text('Brass lamp'), findsOneWidget);
  });

  testWidgets('the cart strip is hidden when there is nothing to suggest or the request fails', (tester) async {
    await tester.pumpWidget(
      _host(const CartSuggestionsStrip(), [cartSuggestionsProvider.overrideWith((ref) async => const [])]),
    );
    await tester.pumpAndSettle();
    expect(find.text('You may also need'), findsNothing);
    await tester.pumpWidget(
      _host(const CartSuggestionsStrip(), [
        cartSuggestionsProvider.overrideWith((ref) => Future.error(Exception('x'))),
      ]),
    );
    await tester.pumpAndSettle();
    expect(find.text('You may also need'), findsNothing);
  });

  test('the product detail keeps the upsell list from the API and defaults to empty', () {
    final base = {'id': 'p', 'name': 'Sofa'};
    expect(ProductDetail.fromJson({...base}).upsell, isEmpty);
    expect(
      ProductDetail.fromJson({
        ...base,
        'upsell': [
          {'id': 'u', 'name': 'Lamp'},
        ],
      }).upsell.single.id,
      'u',
    );
  });
}
