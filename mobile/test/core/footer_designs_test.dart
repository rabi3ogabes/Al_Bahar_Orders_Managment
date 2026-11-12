import 'package:albahar_customer/core/router/app_footer.dart';
import 'package:albahar_customer/core/router/footer_config.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

const _routes = [
  '/home',
  '/categories',
  '/auctions',
  '/cart',
  '/account',
  '/orders',
  '/wishlist',
  '/search',
  '/support',
  '/notifications',
  '/products/abc',
];

GoRouter _router(String initial) => GoRouter(
  initialLocation: initial,
  routes: [
    for (final p in _routes)
      GoRoute(
        path: p,
        builder: (_, _) => Scaffold(body: Center(child: Text('page $p'))),
      ),
  ],
);

Map<String, Object?> _json({
  String design = 'classic',
  List<Map<String, Object?>>? items,
  Object? accent,
  bool showLabels = true,
  bool cartBadge = true,
}) => {
  'design': design,
  'show_labels': showLabels,
  'cart_badge': cartBadge,
  'accent': accent,
  'items':
      items ??
      [
        for (final k in ['home', 'categories', 'auctions', 'cart', 'account'])
          {'key': k, 'enabled': true, 'icon': null, 'label_en': null, 'label_ar': null},
      ],
};

/// A theme with no footer in it, as the app has before the server answers.
class _NoFooterTheme extends BrandThemeController {
  @override
  BrandTheme build() => BrandTheme.fallback;
}

Widget _app(GoRouter router, FooterConfig? config, {Locale locale = const Locale('en'), bool reduceMotion = false}) =>
    ProviderScope(
      overrides: [
        cartCountProvider.overrideWithValue(3),
        if (config != null)
          footerConfigProvider.overrideWithValue(config)
        else
          brandThemeProvider.overrideWith(_NoFooterTheme.new),
      ],
      child: MaterialApp.router(
        routerConfig: router,
        locale: locale,
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: AppTheme.light(locale),
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context).copyWith(disableAnimations: reduceMotion),
          child: FooterScope(router: router, child: child ?? const SizedBox.shrink()),
        ),
      ),
    );

Finder _selected(String label) => find.byWidgetPredicate(
  (w) => w is Semantics && w.properties.selected == true && (w.properties.label ?? '').startsWith(label),
);

void main() {
  group('config parsing', () {
    test('reads a valid payload and keeps only switched-on items in order', () {
      final c = FooterConfig.fromJson(
        _json(
          design: 'bubble',
          accent: '#11aa22',
          showLabels: false,
          items: [
            {'key': 'home', 'enabled': true, 'icon': 'star', 'label_en': 'Start', 'label_ar': 'ابدأ'},
            {'key': 'orders', 'enabled': true},
            {'key': 'support', 'enabled': false},
            {'key': 'cart', 'enabled': true},
          ],
        ),
      )!;
      expect(c.design, FooterDesign.bubble);
      expect(c.keys, ['home', 'orders', 'cart']);
      expect(c.showLabels, isFalse);
      expect(c.accent, const Color(0xFF11AA22));
      expect(c.items.first.icon, 'star');
    });

    test('rejects anything that could break the menu, so the built-in footer stays', () {
      Map<String, Object?> items(List<String> keys, {String? off}) => _json(
        items: [
          for (final k in keys) {'key': k, 'enabled': k != off},
        ],
      );
      expect(FooterConfig.fromJson(null), isNull);
      expect(FooterConfig.fromJson('x'), isNull);
      expect(FooterConfig.fromJson(_json(design: 'neon')), isNull);
      expect(FooterConfig.fromJson(items(['home', 'cart'])), isNull);
      expect(FooterConfig.fromJson(items(['home', 'cart', 'account'], off: 'account')), isNull);
      expect(FooterConfig.fromJson(items(['home', 'cart', 'account'], off: 'home')), isNull);
      expect(FooterConfig.fromJson(items(['categories', 'cart', 'account'])), isNull);
      expect(FooterConfig.fromJson(items(['home', 'cart', 'cart'])), isNull);
      expect(FooterConfig.fromJson(items(['home', 'cart', 'admin'])), isNull);
      expect(FooterConfig.fromJson(items(['home', 'categories', 'auctions', 'cart', 'account', 'orders'])), isNull);
    });

    test('ignores a bad colour or icon instead of failing', () {
      final c = FooterConfig.fromJson(
        _json(
          accent: 'red',
          items: [
            {'key': 'home', 'enabled': true, 'icon': 'skull'},
            {'key': 'cart', 'enabled': true},
            {'key': 'account', 'enabled': true},
          ],
        ),
      )!;
      expect(c.accent, isNull);
      expect(c.items.first.icon, isNull);
    });

    test('rides along in the theme payload; a broken footer leaves the theme and the fallback intact', () {
      Map<String, Object?> theme(Object? footer) => {
        'key': 'default',
        'kind': 'base',
        'colors': {
          'primary': '#8A1538',
          'primary_soft': '#A3294E',
          'accent': '#B3254F',
          'background': '#FCF9FA',
          'surface': '#FFFFFF',
          'ink': '#2A1A20',
        },
        'footer': footer,
      };
      expect(BrandTheme.fromJson(theme(_json(design: 'glass')))!.footer?.design, FooterDesign.glass);
      final broken = BrandTheme.fromJson(theme({'design': 'neon'}))!;
      expect(broken.footer, isNull);
      expect(BrandTheme.fromJson(theme(null))!.footer, isNull);
      expect(
        BrandTheme.fromJson(theme(_json(design: 'glass')))! == BrandTheme.fromJson(theme(_json(design: 'floating')))!,
        isFalse,
      );
    });
  });

  test('highlighting follows the configurable list and its parents', () {
    const custom = ['home', 'orders', 'wishlist', 'cart', 'support'];
    expect(footerIndexForKeys('/orders/9/track', custom), 1);
    expect(footerIndexForKeys('/wishlist', custom), 2);
    expect(footerIndexForKeys('/checkout', custom), 3);
    expect(footerIndexForKeys('/support/4', custom), 4);
    expect(footerIndexForKeys('/products/abc', custom), 0); // Shop is not in the list: falls back to Home
    expect(footerIndexForKeys('/profile/edit', custom), 0);
    expect(footerIndexForKeys('/orders', FooterConfig.fallback.keys), 4); // under Account in the built-in footer
    expect(footerIndexForKeys('/search', FooterConfig.fallback.keys), 1);
    expect(footerIndexForKeys('/nowhere', custom), 0);
    expect(footerIndexFor('/auctions/lots/1'), 2);
    expect(footerPaths, ['/home', '/categories', '/auctions', '/cart', '/account']);
  });

  for (final design in FooterDesign.values) {
    testWidgets('${design.name}: shows the items, lights the page, navigates, shows the cart count', (tester) async {
      final router = _router('/products/abc');
      final config = FooterConfig.fromJson(_json(design: design.name))!;
      await tester.pumpWidget(_app(router, config));
      await tester.pumpAndSettle();
      expect(find.text('page /products/abc'), findsOneWidget);
      expect(find.text('3'), findsOneWidget); // cart badge
      if (design == FooterDesign.classic) {
        expect(find.byType(NavigationBar), findsOneWidget);
        expect(tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex, 1);
      } else {
        expect(find.byType(NavigationBar), findsNothing);
        expect(_selected('Shop'), findsOneWidget);
      }
      if (design != FooterDesign.minimal) {
        expect(find.text('Auctions'), design == FooterDesign.bubble ? findsNothing : findsOneWidget);
      } else {
        expect(find.text('Auctions'), findsNothing); // icons only
      }
      // Every target is at least 48 x 48.
      final tapTarget = design == FooterDesign.classic ? find.byType(NavigationDestination) : find.byType(InkWell);
      for (final e in tapTarget.evaluate()) {
        expect(tester.getSize(find.byWidget(e.widget)).height, greaterThanOrEqualTo(48));
      }
      await tester.tap(find.bySemanticsLabel(RegExp('^Account')));
      await tester.pumpAndSettle();
      expect(find.text('page /account'), findsOneWidget);
      if (design != FooterDesign.classic) expect(_selected('Account'), findsOneWidget);
    });
  }

  testWidgets('custom items, labels, hidden labels, no badge and a custom accent', (tester) async {
    final router = _router('/orders');
    final config = FooterConfig.fromJson(
      _json(
        design: 'floating',
        accent: '#112233',
        cartBadge: false,
        items: [
          {'key': 'home', 'enabled': true, 'label_en': 'Start'},
          {'key': 'orders', 'enabled': true, 'icon': 'gift'},
          {'key': 'cart', 'enabled': true},
        ],
      ),
    )!;
    await tester.pumpWidget(_app(router, config));
    await tester.pumpAndSettle();
    expect(find.text('Start'), findsOneWidget);
    expect(find.text('Orders'), findsOneWidget);
    expect(find.text('3'), findsNothing);
    expect(_selected('Orders'), findsOneWidget);
    expect(find.byIcon(Icons.card_giftcard_rounded), findsOneWidget);
    await tester.tap(find.text('Start'));
    await tester.pumpAndSettle();
    expect(find.text('page /home'), findsOneWidget);

    final noLabels = FooterConfig.fromJson(_json(design: 'glass', showLabels: false))!;
    await tester.pumpWidget(_app(_router('/home'), noLabels));
    await tester.pumpAndSettle();
    expect(find.text('Home'), findsNothing);
    expect(_selected('Home'), findsOneWidget); // still announced to screen readers
  });

  testWidgets('Arabic: right-to-left, the dashboard\'s Arabic word, the app\'s own word otherwise', (tester) async {
    final config = FooterConfig.fromJson(
      _json(
        design: 'bubble',
        items: [
          {'key': 'home', 'enabled': true, 'label_ar': 'ابدأ', 'label_en': 'Start'},
          {'key': 'orders', 'enabled': true},
          {'key': 'cart', 'enabled': true},
        ],
      ),
    )!;
    await tester.pumpWidget(_app(_router('/home'), config, locale: const Locale('ar')));
    await tester.pumpAndSettle();
    expect(find.text('ابدأ'), findsOneWidget);
    expect(find.text('Start'), findsNothing);
    final home = tester.getCenter(find.text('ابدأ'));
    final cart = tester.getCenter(find.bySemanticsLabel(RegExp('^السلة')));
    expect(home.dx, greaterThan(cart.dx)); // first item sits on the right
  });

  testWidgets('five long labels fit a 320 px phone in every design with large text', (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    for (final design in FooterDesign.values) {
      final config = FooterConfig.fromJson(
        _json(
          design: design.name,
          items: [
            for (final k in ['home', 'notifications', 'wishlist', 'cart', 'support'])
              {'key': k, 'enabled': true, 'label_en': 'Very long label'},
          ],
        ),
      )!;
      await tester.pumpWidget(_app(_router('/notifications'), config));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull, reason: design.name);
    }
  });

  testWidgets('reduced motion: the bubble moves without animating', (tester) async {
    final router = _router('/home');
    final config = FooterConfig.fromJson(_json(design: 'bubble'))!;
    await tester.pumpWidget(_app(router, config, reduceMotion: true));
    await tester.pumpAndSettle();
    await tester.tap(find.bySemanticsLabel(RegExp('^Cart')));
    await tester.pump();
    await tester.pump();
    expect(_selected('Cart'), findsOneWidget);
    expect(find.text('Cart'), findsOneWidget);
  });

  testWidgets('without a config the built-in five-tab footer shows', (tester) async {
    final router = _router('/home');
    await tester.pumpWidget(_app(router, null));
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsOneWidget);
    for (final t in ['Home', 'Shop', 'Auctions', 'Cart', 'Account']) {
      expect(find.text(t), findsOneWidget);
    }
  });

  testWidgets('glass design is drawn in dark mode too', (tester) async {
    final router = _router('/home');
    final config = FooterConfig.fromJson(_json(design: 'glass'))!;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [cartCountProvider.overrideWithValue(0), footerConfigProvider.overrideWithValue(config)],
        child: MaterialApp.router(
          routerConfig: router,
          locale: const Locale('en'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          theme: ThemeData.dark(),
          builder: (context, child) => FooterScope(router: router, child: child ?? const SizedBox.shrink()),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(BackdropFilter), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
