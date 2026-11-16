import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/router/app_footer.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/theme/app_chrome.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/page_backdrop.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/fresh.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import '../helpers/home_slider_fakes.dart';

const _en = Locale('en');
const _ar = Locale('ar');

/// A complete `backdrop` block as the server sends it, with the given values replaced.
Map<String, Object?> _block([Map<String, Object?> change = const {}]) => {
  'style': 'skyline',
  'image_url': null,
  'fit': 'bottom',
  'opacity': 0.08,
  'color': null,
  'tint': null,
  ...change,
};

Map<String, Object?> _theme({Object? backdrop, bool withBackdrop = true}) => {
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
  if (withBackdrop) 'backdrop': backdrop,
};

Widget _host(Widget home, ThemeData light, {ThemeData? dark, bool darkMode = false, Locale locale = _en}) => MaterialApp(
  locale: locale,
  supportedLocales: const [_en, _ar],
  localizationsDelegates: const [
    AppLocalizations.delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  theme: light,
  darkTheme: dark ?? light,
  themeMode: darkMode ? ThemeMode.dark : ThemeMode.light,
  home: home,
);

/// The RGBA bytes of pixel ([x], [y]) of the repaint boundary under [key].
Future<List<int>> _pixel(WidgetTester tester, GlobalKey key, int x, int y) async {
  final boundary = tester.renderObject<RenderRepaintBoundary>(find.byKey(key));
  final bytes = await tester.runAsync(() async {
    final image = await boundary.toImage();
    return image.toByteData();
  });
  final offset = (y * boundary.size.width.toInt() + x) * 4;
  return [for (var i = 0; i < 4; i++) bytes!.getUint8(offset + i)];
}

void main() {
  group('parsing the backdrop block', () {
    test('missing, null or garbage means off: nothing changes anywhere', () {
      for (final raw in [null, 'x', 42, <Object?>[], <String, Object?>{}, {'opacity': 0.1}]) {
        final c = PageBackdropConfig.fromJson(raw);
        expect(c, PageBackdropConfig.off, reason: '$raw');
        expect(c.isActive, isFalse);
        expect(c.hasPicture, isFalse);
      }
    });

    test('the defaults when the admin never saved anything', () {
      final c = PageBackdropConfig.fromJson({'style': 'skyline'});
      expect(c, const PageBackdropConfig());
      expect(c.style, BackdropStyle.skyline);
      expect(c.fit, BackdropFit.bottom);
      expect(c.opacity, 0.08);
      expect(c.color, isNull);
      expect(c.tint, isNull);
      expect(c.isActive, isTrue);
    });

    test('reads every field, in any letter case, and keeps the opacity inside the range', () {
      final c = PageBackdropConfig.fromJson(
        _block({'style': ' Image ', 'image_url': 'https://cdn.test/bg.jpg', 'fit': 'COVER', 'opacity': 0.2, 'color': '#fff3e0', 'tint': '#8A1538'}),
      );
      expect(c.style, BackdropStyle.image);
      expect(c.imageUrl, 'https://cdn.test/bg.jpg');
      expect(c.fit, BackdropFit.cover);
      expect(c.opacity, 0.2);
      expect(c.color, const Color(0xFFFFF3E0));
      expect(c.tint, const Color(0xFF8A1538));
      expect(PageBackdropConfig.fromJson(_block({'opacity': 0.02})).opacity, 0.02);
      expect(PageBackdropConfig.fromJson(_block({'opacity': 0.5})).opacity, 0.5);
      expect(PageBackdropConfig.fromJson(_block({'opacity': 0.5000000001})).opacity, 0.5);
      expect(PageBackdropConfig.fromJson(_block({'opacity': '0.1'})).opacity, 0.1);
      expect(PageBackdropConfig.fromJson({'style': 'none'}).isActive, isFalse);
    });

    test('a value outside the allowed set means off (an older or newer server), never a half-drawn page', () {
      final bad = <Map<String, Object?>>[
        {'style': 'neon'},
        {'style': 5},
        {'style': null},
        {'fit': 'stretch'},
        {'fit': 3},
        {'opacity': 0.9},
        {'opacity': 0.01},
        {'opacity': 0},
        {'opacity': 'lots'},
        {'color': 'red'},
        {'color': '#FFF'},
        {'color': 12},
        {'tint': '#GG0000'},
      ];
      for (final change in bad) {
        expect(PageBackdropConfig.fromJson(_block(change)), PageBackdropConfig.off, reason: '$change');
      }
    });

    test('an image without a usable https address is the built-in skyline, as the server answers; a stray address is ignored', () {
      for (final url in [null, '', 'http://cdn.test/a.jpg', '/relative.jpg', 'ftp://x/y.png', 'https://']) {
        final c = PageBackdropConfig.fromJson(_block({'style': 'image', 'image_url': url}));
        expect(c.style, BackdropStyle.skyline, reason: '$url');
        expect(c.imageUrl, isNull, reason: '$url');
      }
      expect(PageBackdropConfig.fromJson(_block({'image_url': 'https://cdn.test/a.jpg'})).imageUrl, isNull);
      expect(PageBackdropConfig.fromJson(_block({'style': 'none', 'image_url': 'https://cdn.test/a.jpg'})).imageUrl, isNull);
    });

    test('"none" is only active when it brings a page colour', () {
      expect(PageBackdropConfig.fromJson(_block({'style': 'none'})).isActive, isFalse);
      final colourOnly = PageBackdropConfig.fromJson(_block({'style': 'none', 'color': '#FFF3E0'}));
      expect(colourOnly.isActive, isTrue);
      expect(colourOnly.hasPicture, isFalse);
    });

    test('a page colour is used only while the theme text stays readable on it (3:1)', () {
      const ink = Color(0xFF2A1A20);
      expect(const PageBackdropConfig(color: Color(0xFFFFF3E0)).pageColorFor(ink), const Color(0xFFFFF3E0));
      expect(const PageBackdropConfig(color: Color(0xFF3A2A30)).pageColorFor(ink), isNull);
      expect(const PageBackdropConfig().pageColorFor(ink), isNull);
    });

    test('value equality, so a live change rebuilds the theme', () {
      PageBackdropConfig read([Map<String, Object?> change = const {}]) => PageBackdropConfig.fromJson(_block(change));
      expect(read(), read());
      expect(read().hashCode, read().hashCode);
      for (final change in <Map<String, Object?>>[
        {'style': 'none'},
        {'opacity': 0.3},
        {'color': '#FFF3E0'},
        {'tint': '#112233'},
        {'fit': 'cover'},
        {'style': 'image', 'image_url': 'https://cdn.test/a.jpg'},
      ]) {
        expect(read() == read(change), isFalse, reason: '$change');
      }
    });

    test('it rides along in the theme payload; a broken block leaves the theme intact and the page background off', () {
      expect(BrandTheme.fromJson(_theme(backdrop: _block({'opacity': 0.12})))!.backdrop.opacity, 0.12);
      expect(BrandTheme.fromJson(_theme(withBackdrop: false))!.backdrop, PageBackdropConfig.off);
      final broken = BrandTheme.fromJson(_theme(backdrop: {'style': 'neon'}))!;
      expect(broken.backdrop, PageBackdropConfig.off);
      expect(broken.primary, const Color(0xFF8A1538));
      expect(BrandTheme.fromJson(_theme(backdrop: _block()))! == BrandTheme.fromJson(_theme(backdrop: _block({'opacity': 0.2})))!, isFalse);
      expect(BrandTheme.fromJson(_theme(backdrop: _block()))! == BrandTheme.fromJson(_theme(backdrop: _block()))!, isTrue);
      expect(BrandTheme.fallback.backdrop, PageBackdropConfig.off);
    });
  });

  group('the theme', () {
    test('without a backdrop it is what it always was (the driver and staff apps)', () {
      for (final t in [
        AppTheme.light(_en),
        AppTheme.light(_en, backdrop: PageBackdropConfig.off),
        AppTheme.light(_ar, backdrop: null),
      ]) {
        expect(t.scaffoldBackgroundColor, AppPalette.ivory);
        expect(t.canvasColor, AppPalette.ivory);
        expect(t.pageTransitionsTheme.builders.keys, unorderedEquals([TargetPlatform.android, TargetPlatform.iOS]));
        expect(t.pageTransitionsTheme.builders[TargetPlatform.android], isA<FadeForwardsPageTransitionsBuilder>());
        expect(t.pageTransitionsTheme.builders[TargetPlatform.iOS], isA<CupertinoPageTransitionsBuilder>());
      }
      expect(AppTheme.dark(_en).scaffoldBackgroundColor, AppPalette.nightBg);
      expect(AppTheme.dark(_en, backdrop: PageBackdropConfig.off).scaffoldBackgroundColor, AppPalette.nightBg);
      final brand = BrandTheme.fromJson(_theme())!;
      expect(AppTheme.light(_en, brand: brand).scaffoldBackgroundColor, brand.background);
      expect(AppTheme.light(_en, brand: brand, backdrop: PageBackdropConfig.off).scaffoldBackgroundColor, brand.background);
    });

    test('a colour-only backdrop in dark mode, or a colour the text cannot be read on, changes nothing', () {
      const colourOnly = PageBackdropConfig(style: BackdropStyle.none, color: Color(0xFFFFF3E0));
      expect(AppTheme.dark(_en, backdrop: colourOnly).scaffoldBackgroundColor, AppPalette.nightBg);
      expect(AppTheme.dark(_en, backdrop: colourOnly).pageTransitionsTheme.builders.length, 2);
      const unreadable = PageBackdropConfig(style: BackdropStyle.none, color: Color(0xFF3A2A30));
      final t = AppTheme.light(_en, backdrop: unreadable);
      expect(t.scaffoldBackgroundColor, AppPalette.ivory);
      expect(t.pageTransitionsTheme.builders.length, 2);
    });

    test('with a picture the scaffold is transparent and every platform carries the backdrop; the page colour stays opaque', () {
      for (final t in [
        AppTheme.light(_en, backdrop: const PageBackdropConfig()),
        AppTheme.dark(_en, backdrop: const PageBackdropConfig()),
        AppTheme.light(_ar, brand: BrandTheme.fromJson(_theme()), backdrop: const PageBackdropConfig(opacity: 0.3)),
      ]) {
        expect(t.scaffoldBackgroundColor, Colors.transparent);
        expect(t.canvasColor.a, 1.0);
        expect(t.colorScheme.surfaceContainerLowest, t.canvasColor);
        expect(t.appBarTheme.backgroundColor!.a, 1.0);
        expect(t.pageTransitionsTheme.builders.keys.toSet(), TargetPlatform.values.toSet());
        for (final b in t.pageTransitionsTheme.builders.values) {
          expect(b, isA<BackdropPageTransitionsBuilder>());
        }
      }
      expect(AppTheme.light(_en, backdrop: const PageBackdropConfig()).canvasColor, AppPalette.ivory);
      expect(AppTheme.dark(_en, backdrop: const PageBackdropConfig()).canvasColor, AppPalette.nightBg);
    });

    test('the dashboard\'s page colour replaces the light background only', () {
      const config = PageBackdropConfig(color: Color(0xFFFFF3E0));
      final light = AppTheme.light(_en, backdrop: config);
      expect(light.canvasColor, const Color(0xFFFFF3E0));
      expect(light.colorScheme.surfaceContainerLowest, const Color(0xFFFFF3E0));
      expect(light.appBarTheme.backgroundColor, const Color(0xFFFFF3E0));
      expect(light.scaffoldBackgroundColor, Colors.transparent);
      final dark = AppTheme.dark(_en, backdrop: config);
      expect(dark.canvasColor, AppPalette.nightBg);
      expect(dark.colorScheme.surfaceContainerLowest, AppPalette.nightBg);
      // A colour the theme's text cannot be read on is ignored, the picture stays.
      const hard = PageBackdropConfig(color: Color(0xFF3A2A30));
      expect(AppTheme.light(_en, backdrop: hard).canvasColor, AppPalette.ivory);
      expect(AppTheme.light(_en, backdrop: hard).scaffoldBackgroundColor, Colors.transparent);
    });

    test('the builder keeps the duration and the delegated transition of the transition it wraps', () {
      const c = PageBackdropConfig();
      for (final platform in TargetPlatform.values) {
        final b = BackdropPageTransitionsBuilder(platform: platform, config: c, page: AppPalette.ivory);
        final inner = switch (platform) {
          TargetPlatform.android => const FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS => const CupertinoPageTransitionsBuilder(),
          _ => const ZoomPageTransitionsBuilder(),
        };
        expect(b.transitionDuration, inner.transitionDuration, reason: '$platform');
        expect(b.reverseTransitionDuration, inner.reverseTransitionDuration, reason: '$platform');
        expect(b.delegatedTransition == null, inner.delegatedTransition == null, reason: '$platform');
      }
      final a = BackdropPageTransitionsBuilder(platform: TargetPlatform.android, config: c, page: AppPalette.ivory);
      expect(a == BackdropPageTransitionsBuilder(platform: TargetPlatform.android, config: c, page: AppPalette.ivory), isTrue);
      expect(a == BackdropPageTransitionsBuilder(platform: TargetPlatform.iOS, config: c, page: AppPalette.ivory), isFalse);
    });
  });

  group('the skyline', () {
    test('stands on the bottom edge, as wide as the page but never narrower than 760, and keeps the 1600:340 shape', () {
      final wide = SkylinePainter.boxFor(const Size(1000, 800));
      expect(wide.left, 0);
      expect(wide.width, 1000);
      expect(wide.bottom, 800);
      expect(wide.height, closeTo(1000 * 340 / 1600, 1e-9));
      final phone = SkylinePainter.boxFor(const Size(390, 800));
      expect(phone.width, 760);
      expect(phone.left, closeTo(-185, 1e-9)); // the middle shows, both ends are cropped
      expect(phone.right, closeTo(575, 1e-9));
      expect(phone.bottom, 800);
      expect(phone.height, closeTo(760 * 340 / 1600, 1e-9));
    });

    test('repaints only when the colour or the opacity change', () {
      const p = SkylinePainter(tint: Color(0xFF8A1538), opacity: 0.08);
      expect(p.shouldRepaint(const SkylinePainter(tint: Color(0xFF8A1538), opacity: 0.08)), isFalse);
      expect(p.shouldRepaint(const SkylinePainter(tint: Color(0xFF112233), opacity: 0.08)), isTrue);
      expect(p.shouldRepaint(const SkylinePainter(tint: Color(0xFF8A1538), opacity: 0.2)), isTrue);
    });

    testWidgets('is filled in the tint at the opacity, as one path clipped to the page', (tester) async {
      await tester.pumpWidget(
        _host(
          const Align(
            alignment: Alignment.topLeft,
            child: SizedBox(
              width: 400,
              height: 300,
              child: PageBackdropLayer(config: PageBackdropConfig(opacity: 0.3, tint: Color(0xFF112233))),
            ),
          ),
          AppTheme.light(_en),
        ),
      );
      final paint = find.byWidgetPredicate((w) => w is CustomPaint && w.painter is SkylinePainter);
      expect(paint, findsOneWidget);
      expect(tester.renderObject(paint), paints..path(color: const Color(0xFF112233).withValues(alpha: 0.3)));
    });

    for (final dark in [false, true]) {
      testWidgets('without a tint it takes the theme primary (${dark ? 'dark mode: the dark primary' : 'light mode'})', (tester) async {
        await tester.pumpWidget(
          _host(
            const SizedBox(width: 400, height: 300, child: PageBackdropLayer(config: PageBackdropConfig())),
            AppTheme.light(_en),
            dark: AppTheme.dark(_en),
            darkMode: dark,
          ),
        );
        final paint = find.byWidgetPredicate((w) => w is CustomPaint && w.painter is SkylinePainter);
        final painter = tester.widget<CustomPaint>(paint).painter! as SkylinePainter;
        expect(painter.tint, dark ? AppPalette.nightNavy : AppPalette.navy);
        expect(painter.opacity, 0.08);
      });
    }

    testWidgets('really puts pixels on the page: the tint over the page colour on the ground strip, nothing above', (tester) async {
      final key = GlobalKey();
      await tester.pumpWidget(
        MaterialApp(
          theme: ThemeData(
            colorScheme: const ColorScheme.light(primary: Color(0xFF000000), surfaceContainerLowest: Color(0xFFFFFFFF)),
          ),
          home: Align(
            alignment: Alignment.topLeft,
            child: RepaintBoundary(
              key: key,
              child: const SizedBox(
                width: 400,
                height: 300,
                child: PageBackdrop(config: PageBackdropConfig(opacity: 0.5), child: SizedBox.expand()),
              ),
            ),
          ),
        ),
      );
      final ground = await _pixel(tester, key, 200, 296); // inside the 18 px ground strip of the drawing
      for (final channel in ground.take(3)) {
        expect(channel, inInclusiveRange(126, 129), reason: 'half black on white');
      }
      expect(ground[3], 255);
      expect(await _pixel(tester, key, 200, 20), [255, 255, 255, 255], reason: 'above the skyline it is the page colour');
    });
  });

  group('PageBackdrop', () {
    Widget page({PageBackdropConfig config = const PageBackdropConfig(), BackdropImageBuilder? images, Widget? child}) =>
        PageBackdrop(
          config: config,
          imageBuilder: images ?? defaultBackdropImage,
          child: child ?? const Scaffold(body: Center(child: Text('content'))),
        );

    for (final style in BackdropStyle.values) {
      for (final dark in [false, true]) {
        for (final locale in [_en, _ar]) {
          testWidgets('${style.name}, ${dark ? 'dark' : 'light'}, ${locale.languageCode}: builds, draws under the content, no exception', (
            tester,
          ) async {
            final fake = await fakePictures(tester);
            final config = PageBackdropConfig(
              style: style,
              imageUrl: style == BackdropStyle.image ? 'https://cdn.test/bg.jpg' : null,
            );
            await tester.pumpWidget(
              _host(page(config: config, images: fake), AppTheme.light(_en), dark: AppTheme.dark(_en), darkMode: dark, locale: locale),
            );
            expect(tester.takeException(), isNull);
            expect(find.text('content'), findsOneWidget);
            expect(find.byType(PageBackdropLayer), style == BackdropStyle.none ? findsNothing : findsOneWidget);
            expect(find.byType(Image), style == BackdropStyle.image ? findsOneWidget : findsNothing);
            expect(
              find.byWidgetPredicate((w) => w is CustomPaint && w.painter is SkylinePainter),
              style == BackdropStyle.skyline ? findsOneWidget : findsNothing,
            );
            // The colour under the picture is the opaque page colour of the mode in use.
            final colour = tester.widget<ColoredBox>(
              find.descendant(of: find.byType(PageBackdrop), matching: find.byType(ColoredBox)).first,
            );
            expect(colour.color, dark ? AppPalette.nightBg : AppPalette.ivory);
          });
        }
      }
    }

    testWidgets('does not change the size the page gets, is invisible to touch and to screen readers', (tester) async {
      var taps = 0;
      final content = Scaffold(
        body: Center(
          child: TextButton(onPressed: () => taps++, child: const Text('tap me')),
        ),
      );
      await tester.pumpWidget(_host(content, AppTheme.light(_en)));
      final plain = tester.getSize(find.byType(Scaffold));
      await tester.pumpWidget(_host(page(child: content), AppTheme.light(_en)));
      expect(tester.getSize(find.byType(Scaffold)), plain);
      await tester.tap(find.text('tap me'));
      expect(taps, 1);
      final layer = find.byType(PageBackdropLayer);
      expect(find.descendant(of: layer, matching: find.byType(IgnorePointer)), findsWidgets);
      expect(find.descendant(of: layer, matching: find.byType(ExcludeSemantics)), findsWidgets);
      expect(find.descendant(of: layer, matching: find.byType(RepaintBoundary)), findsWidgets);
      final handle = tester.ensureSemantics();
      expect(find.bySemanticsLabel(RegExp('skyline|backdrop|background', caseSensitive: false)), findsNothing);
      handle.dispose();
    });

    testWidgets('colour only: just the page colour, no picture', (tester) async {
      await tester.pumpWidget(
        _host(page(config: const PageBackdropConfig(style: BackdropStyle.none, color: Color(0xFFFFF3E0))), AppTheme.light(_en)),
      );
      expect(find.byType(PageBackdropLayer), findsNothing);
      expect(find.text('content'), findsOneWidget);
    });

    testWidgets('an uploaded picture shows at its opacity, standing on the bottom edge (bottom) or filling the page (cover)', (tester) async {
      final key = GlobalKey();
      final fake = await fakePictures(tester); // 16 x 9, colour D9B99B
      Future<void> show(BackdropFit fit) => tester.pumpWidget(
        MaterialApp(
          theme: ThemeData(colorScheme: const ColorScheme.light(surfaceContainerLowest: Color(0xFFFFFFFF))),
          home: Align(
            alignment: Alignment.topLeft,
            child: RepaintBoundary(
              key: key,
              child: SizedBox(
                width: 400,
                height: 300,
                child: PageBackdrop(
                  config: PageBackdropConfig(style: BackdropStyle.image, imageUrl: 'https://cdn.test/bg.jpg', fit: fit, opacity: 0.5),
                  imageBuilder: fake,
                  child: const SizedBox.expand(),
                ),
              ),
            ),
          ),
        ),
      );
      await show(BackdropFit.bottom);
      await tester.pump();
      // 16:9 at 400 wide is 225 tall: it starts at y = 75.
      final inside = await _pixel(tester, key, 200, 290);
      expect(inside[0], inInclusiveRange(235, 237), reason: 'half of D9 over white'); // (0xD9 + 0xFF) / 2 = 236
      expect(await _pixel(tester, key, 200, 20), [255, 255, 255, 255], reason: 'above a bottom picture it is the page colour');
      await show(BackdropFit.cover);
      await tester.pump();
      expect((await _pixel(tester, key, 200, 20))[0], inInclusiveRange(235, 237), reason: 'cover fills the page');
    });

    testWidgets('a picture that cannot load draws nothing and raises no error', (tester) async {
      await tester.pumpWidget(
        _host(
          page(
            config: const PageBackdropConfig(style: BackdropStyle.image, imageUrl: 'https://cdn.test/missing.jpg'),
            images: (_) => const BrokenPicture(),
          ),
          AppTheme.light(_en),
        ),
      );
      await tester.pump();
      await tester.pump();
      expect(tester.takeException(), isNull);
      expect(find.text('content'), findsOneWidget);
    });
  });

  group('pages of an app', () {
    Widget app(ThemeData theme, {ThemeData? dark, bool darkMode = false}) => MaterialApp(
      theme: theme,
      darkTheme: dark ?? theme,
      themeMode: darkMode ? ThemeMode.dark : ThemeMode.light,
      home: Builder(
        builder: (context) => Scaffold(
          body: Center(
            child: TextButton(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(builder: (_) => const Scaffold(body: Center(child: Text('second page')))),
              ),
              child: const Text('go'),
            ),
          ),
        ),
      ),
    );

    testWidgets('every page carries its own backdrop on every platform, and none without one', (tester) async {
      await tester.pumpWidget(app(AppTheme.light(_en, backdrop: const PageBackdropConfig())));
      expect(find.byType(PageBackdrop), findsOneWidget);
      expect(find.descendant(of: find.byType(PageBackdrop), matching: find.byType(Scaffold)), findsOneWidget);
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();
      expect(find.text('second page'), findsOneWidget);
      // The page below is kept (offstage) with its own backdrop; the new one has its own: two pages, two backdrops.
      expect(find.byType(PageBackdrop, skipOffstage: false), findsNWidgets(2));
      expect(find.byType(PageBackdrop), findsOneWidget);

      // The same app with the page background switched off (the theme change animates, then it is settled).
      await tester.pumpWidget(app(AppTheme.light(_en)));
      await tester.pumpAndSettle();
      expect(find.byType(PageBackdrop, skipOffstage: false), findsNothing);
      expect(find.byType(PageBackdropLayer, skipOffstage: false), findsNothing);
    }, variant: TargetPlatformVariant.all());

    testWidgets('the page is transparent over its own opaque backdrop, so a transition never shows the page below', (tester) async {
      await tester.pumpWidget(app(AppTheme.light(_en, backdrop: const PageBackdropConfig())));
      expect(Theme.of(tester.element(find.byType(Scaffold).first)).scaffoldBackgroundColor, Colors.transparent);
      await tester.tap(find.text('go'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 120)); // part of the way through the slide
      expect(find.byType(PageBackdrop), findsNWidgets(2), reason: 'both pages are on screen during the transition');
      for (final backdrop in find.byType(PageBackdrop).evaluate()) {
        final box = tester.widget<ColoredBox>(
          find.descendant(of: find.byWidget(backdrop.widget), matching: find.byType(ColoredBox)).first,
        );
        expect(box.color, AppPalette.ivory, reason: 'each page owns an opaque colour');
      }
      await tester.pumpAndSettle();
    });

    testWidgets('dark mode: the dark page colour with the skyline in the dark primary', (tester) async {
      await tester.pumpWidget(
        app(AppTheme.light(_en, backdrop: const PageBackdropConfig()), dark: AppTheme.dark(_en, backdrop: const PageBackdropConfig()), darkMode: true),
      );
      final colour = tester.widget<ColoredBox>(find.descendant(of: find.byType(PageBackdrop), matching: find.byType(ColoredBox)).first);
      expect(colour.color, AppPalette.nightBg);
      final painter =
          tester.widget<CustomPaint>(find.byWidgetPredicate((w) => w is CustomPaint && w.painter is SkylinePainter)).painter!
              as SkylinePainter;
      expect(painter.tint, AppPalette.nightNavy);
    });

    testWidgets('context.pageColor stays opaque when the scaffold is transparent', (tester) async {
      await tester.pumpWidget(app(AppTheme.light(_en, backdrop: const PageBackdropConfig())));
      final context = tester.element(find.byType(Scaffold).first);
      expect(context.pageColor, AppPalette.ivory);
      expect(context.pageColor.a, 1.0);
      expect(Theme.of(context).scaffoldBackgroundColor.a, 0.0);
    });
  });

  group('FreshScaffold with the gradient design', () {
    final freshBrand = BrandTheme.fromJson({
      ..._theme(),
      'layout': 'fresh',
      'tokens': {'radius': 'xl', 'card': 'soft', 'header': 'brand', 'nav': 'center_action', 'background': 'gradient'},
    })!;

    Widget screen() => const FreshScaffold(header: FreshHeader(title: 'Basket'), body: Center(child: Text('body')));

    testWidgets('puts the picture on top of the gradient wash and under the page', (tester) async {
      await tester.pumpWidget(_host(screen(), AppTheme.light(_en, brand: freshBrand, backdrop: const PageBackdropConfig())));
      expect(tester.takeException(), isNull);
      expect(find.byType(FreshBackground), findsOneWidget);
      expect(find.text('Basket'), findsOneWidget);
      // The page's own backdrop (under the wash, which hides it) and the layer FreshScaffold adds on top of the wash.
      expect(find.byType(PageBackdropLayer), findsNWidgets(2));
      final stack = find.ancestor(of: find.byType(FreshBackground), matching: find.byType(Stack)).first;
      final order = tester.widget<Stack>(stack).children.map((w) => w is Positioned ? w.child.runtimeType : w.runtimeType).toList();
      expect(order, [FreshBackground, PageBackdropLayer, Scaffold]);
    });

    testWidgets('without a backdrop it is the gradient alone, exactly as before', (tester) async {
      await tester.pumpWidget(_host(screen(), AppTheme.light(_en, brand: freshBrand)));
      expect(find.byType(FreshBackground), findsOneWidget);
      expect(find.byType(PageBackdropLayer), findsNothing);
      expect(find.text('Basket'), findsOneWidget);
    });

    testWidgets('a colour-only backdrop adds no picture on the gradient page', (tester) async {
      const config = PageBackdropConfig(style: BackdropStyle.none, color: Color(0xFFFFF3E0));
      await tester.pumpWidget(_host(screen(), AppTheme.light(_en, brand: freshBrand, backdrop: config)));
      expect(find.byType(PageBackdropLayer), findsNothing);
      expect(find.text('Basket'), findsOneWidget);
    });
  });

  group('the customer app', () {
    Future<TestHarness> boot(WidgetTester t, {Object? backdrop, bool sendBackdrop = true}) async {
      final h = await TestHarness.create(
        prefs: {'locale': 'en', 'onboarding_done': true},
        handler: routes({
          'GET /theme': (_) => ok(_theme(backdrop: backdrop, withBackdrop: sendBackdrop)),
          'GET /home': (_) => ok({'sections': <Object?>[]}),
          'GET /categories': (_) => ok([]),
        }),
      );
      await t.binding.setSurfaceSize(const Size(400, 900));
      addTearDown(() => t.binding.setSurfaceSize(null));
      await t.pumpWidget(
        UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
      );
      await t.pump(const Duration(milliseconds: 1600));
      await t.pump(const Duration(milliseconds: 500));
      return h;
    }

    Future<void> teardown(WidgetTester t) async {
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 10));
    }

    testWidgets('with the page background on: one backdrop behind the navigator, standing on the footer menu, pages transparent', (t) async {
      final h = await boot(t, backdrop: _block({'opacity': 0.1}));
      expect(t.takeException(), isNull);
      expect(find.byType(AppChrome), findsOneWidget);
      expect(find.byType(PageBackdrop), findsOneWidget);
      final painter =
          t.widget<CustomPaint>(find.byWidgetPredicate((w) => w is CustomPaint && w.painter is SkylinePainter)).painter!
              as SkylinePainter;
      expect(painter.opacity, 0.1);
      expect(painter.tint, AppPalette.navy);
      // The scaffolds let it show through, and it stands on the footer menu.
      expect(Theme.of(t.element(find.byType(Scaffold).first)).scaffoldBackgroundColor, Colors.transparent);
      final backdrop = t.getRect(find.byType(PageBackdrop));
      expect(backdrop.top, 0);
      expect(backdrop.width, 400);
      expect(backdrop.bottom, t.getRect(find.byKey(kFooterBackdropKey)).top);
      // Moving between pages keeps the one backdrop.
      h.read(routerProvider).go('/cart');
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(milliseconds: 600));
      expect(find.byType(PageBackdrop), findsOneWidget);
      expect(find.byType(NavigationBar), findsOneWidget);
      await teardown(t);
    });

    testWidgets('an older server (no block) or a broken one: the app looks as it always did', (t) async {
      await boot(t, sendBackdrop: false);
      expect(find.byType(PageBackdrop, skipOffstage: false), findsNothing);
      expect(Theme.of(t.element(find.byType(Scaffold).first)).scaffoldBackgroundColor.a, 1.0);
      await teardown(t);
      await boot(t, backdrop: {'style': 'neon'});
      expect(find.byType(PageBackdrop, skipOffstage: false), findsNothing);
      await teardown(t);
    });
  });
}
