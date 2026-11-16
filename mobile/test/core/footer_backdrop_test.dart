// The footer menu sits on the page colour: nothing about it may ever show the empty window (black on Android night mode,
// iOS and the web) behind a floating bar, around the corners of the centre-action bar or in the system gesture area.
import 'package:albahar_customer/core/router/app_footer.dart';
import 'package:albahar_customer/core/router/footer_config.dart';
import 'package:albahar_customer/core/theme/app_chrome.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/page_backdrop.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

const _en = Locale('en');
const _size = Size(390, 844);
const _inset = 34.0;

GoRouter _router() => GoRouter(
  initialLocation: '/home',
  routes: [
    for (final p in ['/home', '/categories', '/auctions', '/cart', '/account'])
      GoRoute(
        path: p,
        builder: (_, _) => Scaffold(body: Center(child: Text('page $p'))),
      ),
  ],
);

FooterConfig _config(FooterDesign design) => FooterConfig.fromJson({
  'design': design.wire,
  'show_labels': true,
  'cart_badge': true,
  'accent': null,
  'items': [
    for (final k in ['home', 'categories', 'auctions', 'cart', 'account'])
      {'key': k, 'enabled': true, 'icon': null, 'label_en': null, 'label_ar': null},
  ],
})!;

/// The app as the customer app builds it around the router: [FooterScope] under the theme. [chrome] adds [AppChrome] too.
Widget _app(
  GoRouter router,
  FooterDesign design, {
  bool dark = false,
  PageBackdropConfig? backdrop,
  bool chrome = false,
  Key? boundary,
}) {
  final footer = ProviderScope(
    overrides: [cartCountProvider.overrideWithValue(3), footerConfigProvider.overrideWithValue(_config(design))],
    child: MaterialApp.router(
      routerConfig: router,
      locale: _en,
      supportedLocales: const [_en, Locale('ar')],
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: AppTheme.light(_en, backdrop: backdrop),
      darkTheme: AppTheme.dark(_en, backdrop: backdrop),
      themeMode: dark ? ThemeMode.dark : ThemeMode.light,
      builder: (context, child) {
        final scope = FooterScope(router: router, child: child ?? const SizedBox.shrink());
        return chrome ? AppChrome(child: scope) : scope;
      },
    ),
  );
  return boundary == null ? footer : RepaintBoundary(key: boundary, child: footer);
}

void _phone(WidgetTester tester) {
  tester.view.physicalSize = _size;
  tester.view.devicePixelRatio = 1;
  tester.view.padding = const FakeViewPadding(bottom: _inset);
  tester.view.viewPadding = const FakeViewPadding(bottom: _inset);
  addTearDown(tester.view.reset);
}

Color _pageColor({required bool dark}) =>
    (dark ? AppTheme.dark(_en) : AppTheme.light(_en)).colorScheme.surfaceContainerLowest;

/// The colour of pixel ([x], [y]) of the whole window, as an opaque or transparent [Color].
Future<Color> _pixel(WidgetTester tester, GlobalKey key, int x, int y) async {
  final boundary = tester.renderObject<RenderRepaintBoundary>(find.byKey(key));
  final data = await tester.runAsync(() async {
    final image = await boundary.toImage();
    return image.toByteData();
  });
  final at = (y * boundary.size.width.toInt() + x) * 4;
  return Color.fromARGB(data!.getUint8(at + 3), data.getUint8(at), data.getUint8(at + 1), data.getUint8(at + 2));
}

/// [actual] is painted (not the unpainted window) and is [expected] give or take a shadow's tint.
void _expectPainted(Color actual, Color expected, String reason, {double tolerance = 40}) {
  expect(actual.a, 1.0, reason: 'painted: $reason');
  for (final (a, e) in [(actual.r, expected.r), (actual.g, expected.g), (actual.b, expected.b)]) {
    expect((a - e).abs() * 255, lessThanOrEqualTo(tolerance), reason: reason);
  }
}

void main() {
  for (final design in FooterDesign.values) {
    for (final dark in [false, true]) {
      testWidgets(
        '${design.name}, ${dark ? 'dark' : 'light'}: the page colour is behind the whole footer region down to the screen edge',
        (tester) async {
          final window = GlobalKey();
          _phone(tester);
          await tester.pumpWidget(_app(_router(), design, dark: dark, boundary: window));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);

          final backdrop = find.byKey(kFooterBackdropKey);
          expect(backdrop, findsOneWidget);
          final colour = tester.widget<ColoredBox>(backdrop).color;
          expect(colour, _pageColor(dark: dark));
          expect(colour.a, 1.0, reason: 'opaque');
          expect(colour, isNot(Colors.black));

          final region = tester.getRect(backdrop);
          final footer = tester.getRect(find.byType(AppFooter));
          expect(region.left, 0);
          expect(region.right, _size.width);
          expect(region.bottom, _size.height, reason: 'down to the bottom edge, system gesture area included');
          // The inset is inside the region: the bar itself starts at least one inset above the bottom edge.
          expect(region.height, greaterThanOrEqualTo(_inset + 48));
          if (design == FooterDesign.centerAction) {
            // The raised round button rises above the bar over the page: the region is the flat bar and the inset only.
            expect(region.height, kCenterBarHeight + _inset);
            expect(region.top, footer.top + kCenterButtonRise);
            expect(region.bottom, footer.bottom);
          } else {
            expect(region.top, lessThanOrEqualTo(footer.top));
            expect(region.height, greaterThanOrEqualTo(footer.height));
          }
          if (design == FooterDesign.glass) {
            expect(find.byType(BackdropFilter), findsOneWidget, reason: 'the frosted blur still works');
          }
          // And really painted, the gesture area at the very bottom edge included (an unpainted window would be transparent).
          for (final x in [1, 120, 195, 270, 388]) {
            final c = await _pixel(tester, window, x, 843);
            expect(c.a, 1.0, reason: '${design.name} at x=$x');
          }
        },
      );
    }
  }

  // The proof in pixels, on the bars that leave bare window around themselves. Without the page colour behind them these
  // pixels are not painted at all (transparent), which is the black bar of the report.
  for (final dark in [false, true]) {
    testWidgets(
      'floating, ${dark ? 'dark' : 'light'}: the strip under and beside the bar is painted in the page colour',
      (tester) async {
        final window = GlobalKey();
        _phone(tester);
        await tester.pumpWidget(_app(_router(), FooterDesign.floating, dark: dark, boundary: window));
        await tester.pumpAndSettle();
        final expected = _pageColor(dark: dark);
        _expectPainted(
          await _pixel(tester, window, 195, 843),
          expected,
          'under the bar, in the gesture area',
          tolerance: 12,
        );
        _expectPainted(await _pixel(tester, window, 2, 843), expected, 'bottom left corner', tolerance: 12);
        _expectPainted(await _pixel(tester, window, 387, 843), expected, 'bottom right corner', tolerance: 12);
      },
    );

    testWidgets(
      'centre action, ${dark ? 'dark' : 'light'}: the corners of the bar and the gesture area are painted in the page colour',
      (tester) async {
        final window = GlobalKey();
        _phone(tester);
        await tester.pumpWidget(_app(_router(), FooterDesign.centerAction, dark: dark, boundary: window));
        await tester.pumpAndSettle();
        final expected = _pageColor(dark: dark);
        const barTop = 844 - kCenterBarHeight - _inset;
        // The two top corners of the bar are rounded: the page colour shows in the notches.
        _expectPainted(await _pixel(tester, window, 1, barTop.toInt() + 1), expected, 'top left notch');
        _expectPainted(await _pixel(tester, window, 388, barTop.toInt() + 1), expected, 'top right notch');
      },
    );
  }

  group('with the dashboard page background on', () {
    for (final design in [FooterDesign.floating, FooterDesign.centerAction, FooterDesign.classic]) {
      testWidgets('${design.name}: the picture stands on the footer menu, and the footer keeps the plain page colour', (
        tester,
      ) async {
        _phone(tester);
        await tester.pumpWidget(_app(_router(), design, backdrop: const PageBackdropConfig()));
        await tester.pumpAndSettle();
        expect(find.byType(PageBackdrop), findsOneWidget);
        final backdrop = tester.getRect(find.byType(PageBackdrop));
        final region = tester.getRect(find.byKey(kFooterBackdropKey));
        expect(backdrop.top, 0);
        expect(backdrop.bottom, closeTo(region.top, 0.01));
        expect(find.text('page /home'), findsOneWidget);
        // The pages are transparent over it; the region under the footer is the same colour as the page.
        expect(tester.widget<ColoredBox>(find.byKey(kFooterBackdropKey)).color, _pageColor(dark: false));
      });
    }

    testWidgets('a dark page background: the dark page colour behind the footer, the skyline in the dark primary', (
      tester,
    ) async {
      _phone(tester);
      await tester.pumpWidget(_app(_router(), FooterDesign.floating, dark: true, backdrop: const PageBackdropConfig()));
      await tester.pumpAndSettle();
      expect(tester.widget<ColoredBox>(find.byKey(kFooterBackdropKey)).color, AppPalette.nightBg);
      final painter =
          tester
                  .widget<CustomPaint>(find.byWidgetPredicate((w) => w is CustomPaint && w.painter is SkylinePainter))
                  .painter!
              as SkylinePainter;
      expect(painter.tint, AppPalette.nightNavy);
    });

    testWidgets('the admin\'s page colour (light mode) is the colour behind the footer too', (tester) async {
      _phone(tester);
      const colour = Color(0xFFFFF3E0);
      await tester.pumpWidget(
        _app(
          _router(),
          FooterDesign.floating,
          backdrop: const PageBackdropConfig(style: BackdropStyle.none, color: colour),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.widget<ColoredBox>(find.byKey(kFooterBackdropKey)).color, colour);
      // The same setting in dark mode keeps the dark page colour.
      await tester.pumpWidget(
        _app(
          _router(),
          FooterDesign.floating,
          dark: true,
          backdrop: const PageBackdropConfig(style: BackdropStyle.none, color: colour),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.widget<ColoredBox>(find.byKey(kFooterBackdropKey)).color, AppPalette.nightBg);
    });
  });

  group('AppChrome: the window and the Android navigation bar', () {
    test('the navigation bar style: the page colour, no divider, no scrim, icons that read on it', () {
      final light = AppChrome.navigationBarStyle(AppPalette.ivory);
      expect(light.systemNavigationBarColor, AppPalette.ivory);
      expect(light.systemNavigationBarDividerColor, Colors.transparent);
      expect(light.systemNavigationBarContrastEnforced, isFalse);
      expect(light.systemNavigationBarIconBrightness, Brightness.dark);
      final night = AppChrome.navigationBarStyle(AppPalette.nightBg);
      expect(night.systemNavigationBarColor, AppPalette.nightBg);
      expect(night.systemNavigationBarIconBrightness, Brightness.light);
      // The status bar is left to each page's app bar, as before.
      expect(light.statusBarColor, isNull);
      expect(light.statusBarIconBrightness, isNull);
      expect(light.statusBarBrightness, isNull);
      expect(light.systemStatusBarContrastEnforced, isNull);
    });

    for (final dark in [false, true]) {
      testWidgets(
        '${dark ? 'dark' : 'light'}: paints the page colour under everything and annotates the navigation bar',
        (tester) async {
          _phone(tester);
          await tester.pumpWidget(_app(_router(), FooterDesign.floating, dark: dark, chrome: true));
          await tester.pumpAndSettle();
          final page = _pageColor(dark: dark);
          final root = tester.widget<ColoredBox>(
            find.descendant(of: find.byType(AppChrome), matching: find.byType(ColoredBox)).first,
          );
          expect(root.color, page);
          expect(
            tester.getRect(find.descendant(of: find.byType(AppChrome), matching: find.byType(ColoredBox)).first).size,
            _size,
          );
          final region = tester.widget<AnnotatedRegion<SystemUiOverlayStyle>>(
            find
                .descendant(of: find.byType(AppChrome), matching: find.byType(AnnotatedRegion<SystemUiOverlayStyle>))
                .first,
          );
          expect(region.value.systemNavigationBarColor, page);
        },
      );
    }

    testWidgets(
      'what Android is told: the page colour for the navigation bar, even under an app bar that asks for black',
      (tester) async {
        final told = <Map<Object?, Object?>>[];
        tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
          if (call.method == 'SystemChrome.setSystemUIOverlayStyle') told.add(call.arguments as Map<Object?, Object?>);
          return null;
        });
        addTearDown(
          () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null),
        );

        Widget app({required bool chrome}) => MaterialApp(
          theme: AppTheme.light(_en),
          builder: (context, child) => chrome ? AppChrome(child: child!) : child!,
          home: Scaffold(
            appBar: AppBar(title: const Text('page')),
            body: const SizedBox.expand(),
          ),
        );

        // Without it, the app bar's own style paints the navigation bar black (the first half of the black bar).
        await tester.pumpWidget(app(chrome: false));
        await tester.pump();
        expect(told, isNotEmpty);
        expect(told.last['systemNavigationBarColor'], const Color(0xFF000000).toARGB32());

        told.clear();
        await tester.pumpWidget(app(chrome: true));
        await tester.pump();
        expect(told, isNotEmpty);
        final style = told.last;
        expect(style['systemNavigationBarColor'], AppPalette.ivory.toARGB32());
        expect(style['systemNavigationBarDividerColor'], Colors.transparent.toARGB32());
        expect(style['systemNavigationBarContrastEnforced'], false);
        expect(style['systemNavigationBarIconBrightness'], Brightness.dark.toString());
        // The status bar still follows the app bar of the page.
        expect(style['statusBarIconBrightness'], Brightness.dark.toString());
        expect(style['statusBarBrightness'], Brightness.light.toString());
      },
    );
  });
}
