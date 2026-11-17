// The middle button of the footer (docs/api/DEALS.md section 6): Auctions or Deals as the raised round button at the exact
// middle of the bar, the other items evenly on both sides, in the centre-action look whatever design is saved.
import 'package:albahar_customer/core/router/app_footer.dart';
import 'package:albahar_customer/core/router/footer_config.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/deals/presentation/state/deals_providers.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

Map<String, Object?> _json({
  String design = 'classic',
  Object? center = 'deals',
  List<String> keys = const ['home', 'categories', 'deals', 'cart', 'account'],
  List<String> off = const [],
}) => {
  'design': design,
  'show_labels': true,
  'cart_badge': true,
  'accent': null,
  'center': center,
  'items': [
    for (final k in keys) {'key': k, 'enabled': !off.contains(k), 'icon': null, 'label_en': null, 'label_ar': null},
  ],
};

class _Live extends LiveDealsController {
  _Live(this.live);
  final bool live;
  @override
  bool build() => live;
}

GoRouter _router([String initial = '/home']) => GoRouter(
  initialLocation: initial,
  routes: [
    for (final p in ['/home', '/categories', '/auctions', '/deals', '/deals/d1', '/cart', '/account', '/orders'])
      GoRoute(
        path: p,
        builder: (_, _) => Scaffold(body: Center(child: Text('page $p'))),
      ),
  ],
);

Widget _app(GoRouter router, FooterConfig config, {Locale locale = const Locale('en'), bool live = false}) =>
    ProviderScope(
      overrides: [
        cartCountProvider.overrideWithValue(2),
        footerConfigProvider.overrideWithValue(config),
        liveDealsProvider.overrideWith(() => _Live(live)),
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
        builder: (context, child) => FooterScope(router: router, child: child ?? const SizedBox.shrink()),
      ),
    );

void _phone(WidgetTester tester, {double width = 390}) {
  tester.view.physicalSize = Size(width, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Finder _item(String word) => find.bySemanticsLabel(RegExp('^$word'));

/// The small green live dot (11 dp circle).
Finder get _dot => find.byWidgetPredicate(
  (w) =>
      w is Container &&
      w.decoration is BoxDecoration &&
      (w.decoration! as BoxDecoration).shape == BoxShape.circle &&
      w.constraints?.maxWidth == 11,
);

void main() {
  group('parsing the middle button', () {
    test('auctions and deals are kept when the item is switched on', () {
      expect(FooterConfig.fromJson(_json(center: 'deals'))!.center, 'deals');
      expect(FooterConfig.fromJson(_json(center: 'auctions', keys: ['home', 'auctions', 'cart']))!.center, 'auctions');
    });

    test('none, a missing field, an unknown key and anything else read as no middle button', () {
      expect(FooterConfig.fromJson(_json(center: 'none'))!.center, isNull);
      expect(FooterConfig.fromJson(_json(center: null))!.center, isNull);
      expect(
        FooterConfig.fromJson(_json(center: 'home'))!.center,
        isNull,
        reason: 'only auctions or deals can be the button',
      );
      expect(FooterConfig.fromJson(_json(center: 'wishlist', keys: ['home', 'wishlist', 'cart']))!.center, isNull);
      expect(FooterConfig.fromJson(_json(center: 'hologram'))!.center, isNull);
      expect(FooterConfig.fromJson(_json(center: 7))!.center, isNull);
      final old = _json()..remove('center');
      expect(FooterConfig.fromJson(old)!.center, isNull, reason: 'an older server sends no centre');
    });

    test('the button must be among the switched-on items', () {
      expect(FooterConfig.fromJson(_json(center: 'deals', off: ['deals']))!.center, isNull);
      expect(FooterConfig.fromJson(_json(center: 'auctions'))!.center, isNull, reason: 'auctions is not in the list');
    });

    test('a middle button draws the centre-action bar whatever design is saved', () {
      for (final design in FooterDesign.values) {
        final c = FooterConfig.fromJson(_json(design: design.wire))!;
        expect(c.design, design);
        expect(c.effectiveDesign, FooterDesign.centerAction, reason: design.name);
      }
      final none = FooterConfig.fromJson(_json(design: 'glass', center: 'none'))!;
      expect(none.effectiveDesign, FooterDesign.glass, reason: 'without a middle button the saved design is used');
    });

    test('the middle button is found by key wherever it sits in the list', () {
      final c = FooterConfig.fromJson(_json(keys: ['deals', 'home', 'cart', 'account']))!;
      expect(c.centerIndex, 0);
      expect(FooterConfig.fromJson(_json())!.centerIndex, 2);
      expect(FooterConfig.fromJson(_json(center: 'none'))!.centerIndex, isNull);
    });

    test('it is part of the equality, so a change reaches the app', () {
      expect(FooterConfig.fromJson(_json(center: 'deals')) == FooterConfig.fromJson(_json(center: 'none')), isFalse);
      expect(FooterConfig.fromJson(_json(center: 'deals')) == FooterConfig.fromJson(_json(center: 'deals')), isTrue);
      expect(FooterConfig.fromJson(_json())!.hashCode, FooterConfig.fromJson(_json())!.hashCode);
    });

    test('the new items and icons are known', () {
      final c = FooterConfig.fromJson(
        _json(keys: ['home', 'deals', 'cart'])
          ..['items'] = [
            {'key': 'home', 'enabled': true, 'icon': 'crown'},
            {'key': 'deals', 'enabled': true, 'icon': 'bolt', 'label_en': 'Hot', 'label_ar': 'ساخن'},
            {'key': 'cart', 'enabled': true, 'icon': 'timer'},
          ],
      )!;
      expect(c.items[0].icons.$2, Icons.workspace_premium_rounded);
      expect(c.items[1].icons.$2, Icons.bolt_rounded);
      expect(c.items[2].icons.$1, Icons.timer_outlined);
      expect(footerIcons['flame'], isNotNull);
      expect(footerDestinations['deals']!.path, '/deals');
      expect(footerDestinations['deals']!.icon, Icons.local_fire_department_outlined);
    });

    test('a deals page lights the Deals item, or Home when the footer has none', () {
      const withDeals = ['home', 'categories', 'deals', 'cart', 'account'];
      expect(footerIndexForKeys('/deals', withDeals), 2);
      expect(footerIndexForKeys('/deals/d1', withDeals), 2);
      expect(footerIndexForKeys('/deals/d1', FooterConfig.fallback.keys), 0);
    });
  });

  group('the layout', () {
    Future<double> centreOf(WidgetTester tester, Finder f) async => tester.getCenter(f).dx;

    for (final width in [320.0, 390.0, 600.0]) {
      testWidgets(
        '5 items (2 + 2), $width dp: the button is at the exact middle and the others sit evenly on both sides',
        (tester) async {
          _phone(tester, width: width);
          final config = FooterConfig.fromJson(_json())!;
          await tester.pumpWidget(_app(_router(), config));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          final mid = width / 2;
          expect(await centreOf(tester, _item('Deals')), closeTo(mid, 0.6));
          final xs = {
            for (final w in ['Home', 'Shop', 'Cart', 'Account']) w: await centreOf(tester, _item(w)),
          };
          expect(xs['Home']!, lessThan(xs['Shop']!));
          expect(xs['Shop']!, lessThan(mid));
          expect(xs['Cart']!, greaterThan(mid));
          expect(xs['Cart']!, lessThan(xs['Account']!));
          // Mirror image: the distance from the middle matches on both sides.
          expect(mid - xs['Shop']!, closeTo(xs['Cart']! - mid, 0.6));
          expect(mid - xs['Home']!, closeTo(xs['Account']! - mid, 0.6));
          // The bar is the centre-action look: no navigation bar, the button is raised above the bar.
          expect(find.byType(NavigationBar), findsNothing);
          final button = tester.getRect(_item('Deals'));
          expect(button.top, lessThan(tester.getTopLeft(_item('Shop')).dy), reason: 'raised above the bar');
        },
      );
    }

    testWidgets('4 items (2 + 1): still at the exact middle', (tester) async {
      _phone(tester);
      await tester.pumpWidget(
        _app(_router(), FooterConfig.fromJson(_json(keys: ['home', 'categories', 'deals', 'account']))!),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(await centreOf(tester, _item('Deals')), closeTo(195, 0.6));
      final home = await centreOf(tester, _item('Home'));
      final shop = await centreOf(tester, _item('Shop'));
      final account = await centreOf(tester, _item('Account'));
      expect(home, lessThan(shop));
      expect(shop, lessThan(195));
      expect(account, greaterThan(195));
    });

    testWidgets('4 items with the button first in the list: still 2 + 1 around it', (tester) async {
      _phone(tester);
      await tester.pumpWidget(
        _app(_router(), FooterConfig.fromJson(_json(keys: ['deals', 'home', 'cart', 'account']))!),
      );
      await tester.pumpAndSettle();
      expect(await centreOf(tester, _item('Deals')), closeTo(195, 0.6));
      expect(await centreOf(tester, _item('Home')), lessThan(195));
      expect(await centreOf(tester, _item('Cart')), lessThan(195));
      expect(await centreOf(tester, _item('Account')), greaterThan(195));
    });

    testWidgets('3 items (1 + 1) with Auctions as the middle button', (tester) async {
      _phone(tester);
      final config = FooterConfig.fromJson(_json(center: 'auctions', keys: ['home', 'auctions', 'account']))!;
      await tester.pumpWidget(_app(_router(), config));
      await tester.pumpAndSettle();
      expect(await centreOf(tester, _item('Auctions')), closeTo(195, 0.6));
      expect(195 - await centreOf(tester, _item('Home')), closeTo(await centreOf(tester, _item('Account')) - 195, 0.6));
      // Auctions keeps its own gavel on the raised button.
      expect(find.byIcon(Icons.gavel_rounded), findsOneWidget);
    });

    testWidgets('right to left: the order mirrors and the button stays in the middle', (tester) async {
      _phone(tester);
      await tester.pumpWidget(_app(_router(), FooterConfig.fromJson(_json())!, locale: const Locale('ar')));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      const mid = 195.0;
      expect(await centreOf(tester, _item('العروض')), closeTo(mid, 0.6));
      final home = await centreOf(tester, _item('الرئيسية'));
      final account = await centreOf(tester, _item('حسابي'));
      expect(home, greaterThan(mid), reason: 'the first item is on the right in Arabic');
      expect(account, lessThan(mid));
    });

    testWidgets('the dashboard\'s own word and icon for the middle button are used', (tester) async {
      _phone(tester);
      final config = FooterConfig.fromJson(
        _json()
          ..['items'] = [
            {'key': 'home', 'enabled': true},
            {'key': 'categories', 'enabled': true},
            {'key': 'deals', 'enabled': true, 'icon': 'crown', 'label_en': 'Hot deals', 'label_ar': 'عروض ساخنة'},
            {'key': 'cart', 'enabled': true},
            {'key': 'account', 'enabled': true},
          ],
      )!;
      await tester.pumpWidget(_app(_router(), config));
      await tester.pumpAndSettle();
      expect(_item('Hot deals'), findsOneWidget);
      expect(find.byIcon(Icons.workspace_premium_rounded), findsOneWidget);
    });

    testWidgets('tapping the middle button goes to /deals, and the page lights it', (tester) async {
      _phone(tester);
      final router = _router();
      await tester.pumpWidget(_app(router, FooterConfig.fromJson(_json())!));
      await tester.pumpAndSettle();
      await tester.tap(_item('Deals'));
      await tester.pumpAndSettle();
      expect(find.text('page /deals'), findsOneWidget);
      router.go('/deals/d1');
      await tester.pumpAndSettle();
      expect(
        find.byWidgetPredicate(
          (w) => w is Semantics && w.properties.selected == true && (w.properties.label ?? '').startsWith('Deals'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('without a middle button the saved design is drawn as it always was', (tester) async {
      _phone(tester);
      await tester.pumpWidget(_app(_router(), FooterConfig.fromJson(_json(center: 'none', design: 'classic'))!));
      await tester.pumpAndSettle();
      expect(find.byType(NavigationBar), findsOneWidget);
    });

    testWidgets('five long words fit a 320 dp phone with large text', (tester) async {
      _phone(tester, width: 320);
      final config = FooterConfig.fromJson(
        _json()
          ..['items'] = [
            for (final k in ['home', 'categories', 'deals', 'cart', 'account'])
              {'key': k, 'enabled': true, 'label_en': 'Very long label'},
          ],
      )!;
      await tester.pumpWidget(_app(_router(), config));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });
  });

  group('the live dot', () {
    testWidgets('shows on the raised Deals button while a deal is live, and is spoken', (tester) async {
      _phone(tester);
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(_app(_router(), FooterConfig.fromJson(_json())!, live: true));
      await tester.pumpAndSettle();
      expect(_dot, findsOneWidget);
      expect(_item('Deals, On sale now'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('is not there when no deal is live', (tester) async {
      _phone(tester);
      await tester.pumpWidget(_app(_router(), FooterConfig.fromJson(_json())!));
      await tester.pumpAndSettle();
      expect(_dot, findsNothing);
      expect(_item('Deals, On sale now'), findsNothing);
    });

    testWidgets('shows on an ordinary Deals item too (no middle button), in any design', (tester) async {
      _phone(tester);
      for (final design in ['classic', 'floating', 'glass', 'bubble', 'minimal']) {
        await tester.pumpWidget(
          _app(_router(), FooterConfig.fromJson(_json(design: design, center: 'none'))!, live: true),
        );
        await tester.pumpAndSettle();
        expect(_dot, findsOneWidget, reason: design);
      }
    });

    testWidgets('no dot on the other buttons', (tester) async {
      _phone(tester);
      await tester.pumpWidget(
        _app(
          _router(),
          FooterConfig.fromJson(_json(keys: ['home', 'categories', 'cart', 'account'], center: 'none'))!,
          live: true,
        ),
      );
      await tester.pumpAndSettle();
      expect(_dot, findsNothing, reason: 'the footer has no Deals button');
    });
  });
}
