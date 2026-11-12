import 'package:albahar_customer/core/router/app_footer.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

GoRouter _router() => GoRouter(
  initialLocation: '/products/abc',
  routes: [
    for (final p in [
      '/home',
      '/categories',
      '/auctions',
      '/cart',
      '/account',
      '/products/abc',
      '/orders/1',
      '/login',
      '/splash',
    ])
      GoRoute(
        path: p,
        builder: (_, _) => Scaffold(body: Center(child: Text('page $p'))),
      ),
  ],
);

/// The look the app has before the server answers: no footer config, so the built-in footer shows.
class _FallbackTheme extends BrandThemeController {
  @override
  BrandTheme build() => BrandTheme.fallback;
}

Widget _app(GoRouter router) => ProviderScope(
  overrides: [cartCountProvider.overrideWithValue(2), brandThemeProvider.overrideWith(_FallbackTheme.new)],
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
    theme: AppTheme.light(const Locale('en')),
    builder: (context, child) => FooterScope(router: router, child: child ?? const SizedBox.shrink()),
  ),
);

void main() {
  test('every page maps to a footer tab, and only the first-run pages have no footer', () {
    expect(footerIndexFor('/home'), 0);
    expect(footerIndexFor('/products/abc'), 1);
    expect(footerIndexFor('/search'), 1);
    expect(footerIndexFor('/categories/x'), 1);
    expect(footerIndexFor('/auctions/lots/1'), 2);
    expect(footerIndexFor('/checkout'), 3);
    expect(footerIndexFor('/orders/9/track'), 4);
    expect(footerIndexFor('/settings/delete-account'), 4);
    for (final p in ['/splash', '/language', '/onboarding', '/login', '/login/password', '/']) {
      expect(showsFooter(p), isFalse, reason: p);
    }
    for (final p in ['/home', '/products/abc', '/checkout', '/orders/1', '/branches', '/pages/terms']) {
      expect(showsFooter(p), isTrue, reason: p);
    }
  });

  testWidgets('the footer menu shows on a page that is not a tab root, keeps the right tab lit, and navigates', (
    tester,
  ) async {
    final router = _router();
    await tester.pumpWidget(_app(router));
    await tester.pumpAndSettle();
    expect(find.text('page /products/abc'), findsOneWidget);
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex, 1);

    await tester.tap(find.text('Account'));
    await tester.pumpAndSettle();
    expect(find.text('page /account'), findsOneWidget);
    expect(tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex, 4);

    router.go('/orders/1');
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsOneWidget); // order page keeps the footer too
    expect(tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex, 4);
  });

  testWidgets('no footer on sign-in and the first-run pages', (tester) async {
    final router = _router();
    await tester.pumpWidget(_app(router));
    await tester.pumpAndSettle();
    router.go('/login');
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsNothing);
    router.go('/splash');
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsNothing);
  });
}
