import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/router/app_footer.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/router/footer_config.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/contrast.dart';
import 'package:albahar_customer/core/theme/design_tokens.dart';
import 'package:albahar_customer/core/widgets/fresh.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

Map<String, Object?> _theme({Object? layout, Object? tokens, Object? footer}) => {
  'key': 'default',
  'kind': 'base',
  'layout': layout,
  'tokens': tokens,
  'footer': footer,
  'design': {'key': 'fresh', 'name_en': 'Fresh', 'name_ar': 'منعش', 'name': 'Fresh'},
  'colors': {
    'primary': '#1F6B3A',
    'primary_soft': '#DDEFE2',
    'accent': '#C9A24D',
    'background': '#FBF8EF',
    'surface': '#FFFFFF',
    'ink': '#1B2A21',
  },
};

const _freshTokens = {
  'radius': 'xl',
  'card': 'soft',
  'header': 'brand',
  'nav': 'center_action',
  'background': 'gradient',
  'density': 'comfortable',
};

Widget _host(Widget child, ThemeData theme, {Locale locale = const Locale('en')}) => MaterialApp(
  locale: locale,
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    AppLocalizations.delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  theme: theme,
  home: child,
);

void main() {
  group('token parsing', () {
    test('missing, null or garbage means classic with defaults', () {
      for (final raw in [null, 'x', 42, <String, Object?>{}]) {
        final t = DesignTokens.fromJson(raw, raw);
        expect(t, DesignTokens.classic);
        expect(t.isFresh, isFalse);
      }
      expect(DesignTokens.fromJson('neon', {'radius': 'huge', 'card': 7}), DesignTokens.classic);
    });

    test('fresh layout without tokens gets the fresh defaults; sent tokens override them', () {
      expect(DesignTokens.fromJson('fresh', null), DesignTokens.fresh);
      final t = DesignTokens.fromJson('fresh', {
        'radius': 'md',
        'nav': 'classic',
        'density': 'compact',
        'card': 'bogus',
      });
      expect(t.radius, DesignRadius.md);
      expect(t.nav, DesignNav.classic);
      expect(t.density, DesignDensity.compact);
      expect(t.card, DesignCardStyle.soft);
      expect(t.header, DesignHeader.brand);
    });

    test('radius maps to 14 / 20 / 28 and density to spacing', () {
      expect(const DesignTokens(radius: DesignRadius.md).cardRadius, 14);
      expect(const DesignTokens(radius: DesignRadius.lg).cardRadius, 20);
      expect(const DesignTokens(radius: DesignRadius.xl).cardRadius, 28);
      expect(const DesignTokens(density: DesignDensity.compact).spacing, lessThan(1));
      expect(const DesignTokens().visualDensity, VisualDensity.standard);
    });
  });

  group('BrandTheme parsing', () {
    test('a payload with no layout or tokens is classic', () {
      final b = BrandTheme.fromJson(_theme())!;
      expect(b.design, DesignTokens.classic);
      expect(b.footer, isNull);
    });

    test('a fresh payload carries layout, tokens and design name', () {
      final b = BrandTheme.fromJson(_theme(layout: 'fresh', tokens: _freshTokens))!;
      expect(b.design.isFresh, isTrue);
      expect(b.design.nav, DesignNav.centerAction);
      expect(b.design.cardRadius, 28);
      expect(b.designKey, 'fresh');
      expect(b.designName, 'Fresh');
    });

    test('an invalid layout or tokens map falls back to classic without dropping the colours', () {
      final b = BrandTheme.fromJson(_theme(layout: 'weird', tokens: 'nope'))!;
      expect(b.design, DesignTokens.classic);
      expect(b.primary, const Color(0xFF1F6B3A));
    });

    test('the design takes part in equality so a live switch rebuilds the theme', () {
      expect(BrandTheme.fromJson(_theme())! == BrandTheme.fromJson(_theme(layout: 'fresh'))!, isFalse);
    });
  });

  group('ThemeData mapping', () {
    test('fresh tokens reach card, input and button shapes', () {
      final brand = BrandTheme.fromJson(_theme(layout: 'fresh', tokens: _freshTokens))!;
      final light = AppTheme.light(const Locale('en'), brand: brand);
      expect(light.extension<DesignTokens>()!.isFresh, isTrue);
      final card = light.cardTheme.shape! as RoundedRectangleBorder;
      expect(card.borderRadius, BorderRadius.circular(28));
      expect(light.filledButtonTheme.style!.shape!.resolve({}), isA<StadiumBorder>());
      expect(light.appBarTheme.backgroundColor, Colors.transparent);
    });

    test('classic keeps the rounded-rectangle button and a plain app bar', () {
      final light = AppTheme.light(const Locale('en'), brand: BrandTheme.fromJson(_theme())!);
      expect(light.filledButtonTheme.style!.shape!.resolve({}), isA<RoundedRectangleBorder>());
      expect(light.cardTheme.shape, isNot(BorderRadius.circular(28)));
      expect(DesignTokens.classic.pillButtons, isFalse);
    });

    test('outlined and flat card styles drop the shadow', () {
      for (final card in ['outlined', 'flat']) {
        final brand = BrandTheme.fromJson(_theme(layout: 'fresh', tokens: {..._freshTokens, 'card': card}))!;
        expect(AppTheme.light(const Locale('en'), brand: brand).cardTheme.elevation, 0);
      }
    });

    test('dark fresh keeps the primary readable on the night surface (AA)', () {
      final brand = BrandTheme.fromJson(_theme(layout: 'fresh', tokens: _freshTokens))!;
      final dark = AppTheme.dark(const Locale('en'), brand: brand);
      expect(Contrast.ratio(dark.colorScheme.primary, dark.colorScheme.surface), greaterThanOrEqualTo(4.5));
      expect(Contrast.ratio(dark.colorScheme.onPrimary, dark.colorScheme.primary), greaterThanOrEqualTo(4.5));
    });
  });

  group('FreshScaffold and FreshHeader', () {
    final fresh = AppTheme.light(
      const Locale('en'),
      brand: BrandTheme.fromJson(_theme(layout: 'fresh', tokens: _freshTokens))!,
    );
    final classic = AppTheme.light(const Locale('en'), brand: BrandTheme.fromJson(_theme())!);

    Widget screen() => FreshScaffold(
      appBar: AppBar(title: const Text('Classic bar')),
      header: const FreshHeader(title: 'Basket', tagline: 'Everything you picked'),
      body: const Center(child: Text('body')),
    );

    testWidgets('fresh paints the backdrop and swaps the app bar for the brand header', (t) async {
      await t.pumpWidget(_host(screen(), fresh));
      expect(find.byType(FreshBackground), findsOneWidget);
      expect(find.text('Basket'), findsOneWidget);
      expect(find.text('Everything you picked'), findsOneWidget);
      expect(find.text('Classic bar'), findsNothing);
      expect(find.byType(FreshLogo), findsOneWidget);
      expect(find.byWidgetPredicate((w) => w is CustomPaint && w.painter is FreshBackdropPainter), findsOneWidget);
    });

    testWidgets('classic keeps its app bar and draws no backdrop', (t) async {
      await t.pumpWidget(_host(screen(), classic));
      expect(find.byType(FreshBackground), findsNothing);
      expect(find.byType(FreshHeader), findsNothing);
      expect(find.text('Classic bar'), findsOneWidget);
    });

    testWidgets('header actions are 48dp and labelled', (t) async {
      await t.pumpWidget(
        _host(
          FreshScaffold(
            header: FreshHeader(
              title: 'Hi',
              trailing: [
                FreshHeaderAction(
                  icon: const Icon(Icons.notifications_none_rounded),
                  tooltip: 'Alerts',
                  onPressed: () {},
                  badge: 2,
                ),
              ],
            ),
            body: const SizedBox(),
          ),
          fresh,
        ),
      );
      final size = t.getSize(find.byTooltip('Alerts'));
      expect(size.width, greaterThanOrEqualTo(48));
      expect(size.height, greaterThanOrEqualTo(48));
    });

    test('backdrop repaints only when its inputs change', () {
      const a = FreshBackdropPainter(base: Colors.white, tint: Colors.green, dark: false, rtl: false);
      expect(
        a.shouldRepaint(const FreshBackdropPainter(base: Colors.white, tint: Colors.green, dark: false, rtl: false)),
        isFalse,
      );
      expect(
        a.shouldRepaint(const FreshBackdropPainter(base: Colors.white, tint: Colors.green, dark: true, rtl: false)),
        isTrue,
      );
    });
  });

  group('footer selection', () {
    ProviderContainer container(Object? layoutTokens, Object? footer, {String layout = 'fresh'}) {
      final c = ProviderContainer(
        overrides: [
          brandThemeProvider.overrideWith(
            () => _FixedTheme(BrandTheme.fromJson(_theme(layout: layout, tokens: layoutTokens, footer: footer))!),
          ),
        ],
      );
      addTearDown(c.dispose);
      return c;
    }

    test('nav center_action with no custom footer uses the centre-action bar', () {
      expect(container(_freshTokens, null).read(footerConfigProvider).design, FooterDesign.centerAction);
    });

    test('an explicit admin footer design always wins', () {
      final footer = {
        'design': 'glass',
        'show_labels': true,
        'cart_badge': true,
        'accent': null,
        'items': [
          for (final k in ['home', 'categories', 'cart']) {'key': k, 'enabled': true},
        ],
      };
      expect(container(_freshTokens, footer).read(footerConfigProvider).design, FooterDesign.glass);
    });

    test('classic nav keeps the built-in footer', () {
      expect(container(null, null, layout: 'classic').read(footerConfigProvider), FooterConfig.fallback);
    });
  });

  group('home sections', () {
    test('sections without data are dropped when the feed is parsed', () {
      final feed = HomeFeed.fromJson({
        'sections': [
          {'id': 'a', 'type': 'banners', 'items': []},
          {'id': 'b', 'type': 'categories', 'items': []},
          {'id': 'c', 'type': 'best_sellers', 'items': []},
          {
            'id': 'd',
            'type': 'new_arrivals',
            'items': [productCard('1')],
          },
        ],
      });
      expect(feed.sections.map((s) => s.id), ['d']);
    });
  });

  group('app in both layouts at 390x844', () {
    Future<TestHarness> boot(
      WidgetTester t, {
      required bool fresh,
      required String locale,
      String path = '/home',
    }) async {
      final h = await TestHarness.create(
        prefs: {'locale': locale, 'onboarding_done': true},
        handler: routes({
          'GET /theme': (_) => ok(_theme(layout: fresh ? 'fresh' : 'classic', tokens: fresh ? _freshTokens : null)),
          'GET /home': (_) => ok({
            'sections': [
              {
                'id': 's1',
                'type': 'banners',
                'items': [
                  {'id': 'b1', 'title': 'Autumn', 'subtitle': 'New', 'image_url': null},
                ],
              },
              {'id': 's2', 'type': 'categories', 'items': []},
              {
                'id': 's3',
                'type': 'best_sellers',
                'title': 'Top picks',
                'items': [productCard('1'), productCard('2')],
              },
            ],
          }),
          'GET /categories': (_) => ok([]),
        }),
      );
      await t.pumpWidget(
        UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
      );
      await t.pump(const Duration(milliseconds: 1600));
      await t.pump(const Duration(milliseconds: 500));
      if (path != '/home') {
        h.read(routerProvider).go(path);
        await t.pump(const Duration(milliseconds: 600));
        await t.pump(const Duration(milliseconds: 600));
      }
      return h;
    }

    Future<void> teardown(WidgetTester t) async {
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 10));
    }

    for (final fresh in [false, true]) {
      for (final locale in ['en', 'ar']) {
        for (final path in ['/home', '/cart', '/account']) {
          testWidgets('${fresh ? 'fresh' : 'classic'} $locale $path renders without overflow', (t) async {
            await t.binding.setSurfaceSize(const Size(390, 844));
            addTearDown(() => t.binding.setSurfaceSize(null));
            await boot(t, fresh: fresh, locale: locale, path: path);
            expect(t.takeException(), isNull);
            expect(find.byType(FreshBackground), fresh ? findsOneWidget : findsNothing);
            expect(find.byType(FreshHeader), fresh ? findsOneWidget : findsNothing);
            final dir = Directionality.of(t.element(find.byType(Scaffold).first));
            expect(dir, locale == 'ar' ? TextDirection.rtl : TextDirection.ltr);
            if (path == '/home') {
              expect(find.text('Top picks'), findsOneWidget);
              // The empty categories section is not drawn.
              expect(find.text('Categories'), findsNothing);
            }
            await teardown(t);
            expect(t.takeException(), isNull);
          });
        }
      }
    }

    testWidgets('fresh shows the raised centre action footer; classic shows the navigation bar', (t) async {
      await t.binding.setSurfaceSize(const Size(390, 844));
      addTearDown(() => t.binding.setSurfaceSize(null));
      await boot(t, fresh: true, locale: 'en');
      expect(find.byType(NavigationBar), findsNothing);
      expect(find.bySemanticsLabel(RegExp('^Shop')), findsOneWidget);
      await teardown(t);
      await boot(t, fresh: false, locale: 'en');
      expect(find.byType(NavigationBar), findsOneWidget);
      await teardown(t);
    });
  });
}

class _FixedTheme extends BrandThemeController {
  _FixedTheme(this.fixed);
  final BrandTheme fixed;

  @override
  BrandTheme build() => fixed;
}
