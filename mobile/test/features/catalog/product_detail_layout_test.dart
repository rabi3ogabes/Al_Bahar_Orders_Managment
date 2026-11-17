import 'dart:async';

import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/page_backdrop_config.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/product_detail_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_gallery.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_header.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import '../../qa/test_fonts.dart';
import 'product_page_host.dart';

SliverAppBar _bar(WidgetTester t) => t.widget<SliverAppBar>(find.byType(SliverAppBar));
Finder get _scroll => find.byType(CustomScrollView).first;
double _headerHeight(WidgetTester t) => t.getSize(find.byType(FlexibleSpaceBar)).height;
Opacity _barTitle(WidgetTester t) => t.widget<Opacity>(find.byKey(const ValueKey('product-bar-title')));
SystemUiOverlayStyle _statusStyle(WidgetTester t) =>
    t.widget<AnnotatedRegion<SystemUiOverlayStyle>>(find.byKey(const ValueKey('product-status-style'))).value;

Future<void> _collapse(WidgetTester t) async {
  await t.drag(_scroll, const Offset(0, -900));
  await settle(t);
}

void main() {
  setUpAll(loadAppFonts);

  group('the page is one scroll view with a collapsing photo header', () {
    testWidgets('CustomScrollView + pinned, stretching SliverAppBar, FlexibleSpaceBar with the gallery', (t) async {
      await pumpProductPage(t);
      final scroll = t.widget<CustomScrollView>(_scroll);
      expect(scroll.physics, isA<BouncingScrollPhysics>());
      expect(scroll.slivers.first, isA<ProductSliverHeader>());
      final bar = _bar(t);
      expect(bar.pinned, isTrue);
      expect(bar.stretch, isTrue);
      expect(bar.expandedHeight, closeTo(390 * 0.9, 0.01));
      final space = t.widget<FlexibleSpaceBar>(find.byType(FlexibleSpaceBar));
      expect(space.collapseMode, CollapseMode.parallax);
      expect(space.stretchModes, [StretchMode.zoomBackground]);
      // The gallery is the space's background, so it collapses and stretches with it.
      expect(find.descendant(of: find.byType(FlexibleSpaceBar), matching: find.byType(ProductGallery)), findsOneWidget);
      expect(find.descendant(of: find.byType(FlexibleSpaceBar), matching: find.byType(PageView)), findsOneWidget);
      expect(_headerHeight(t), closeTo(bar.expandedHeight!, 0.01));
    });

    testWidgets('bouncing physics on Android too: the scrollable really uses them', (t) async {
      debugDefaultTargetPlatformOverride = TargetPlatform.android;
      try {
        await pumpProductPage(t);
        final scrollable = t.state<ScrollableState>(
          find.descendant(of: _scroll, matching: find.byType(Scrollable)).first,
        );
        expect(scrollable.position.physics, isA<BouncingScrollPhysics>());
        // Always scrollable: even a short page can be pulled.
        expect(scrollable.position.physics.allowUserScrolling, isTrue);
      } finally {
        debugDefaultTargetPlatformOverride = null;
      }
    });

    for (final (size, height) in [
      (const Size(390, 844), 351.0),
      (const Size(320, 700), 288.0),
      (const Size(300, 700), 280.0),
      (const Size(1000, 1000), 440.0),
      (const Size(800, 360), 220.0),
    ]) {
      testWidgets('header height at $size is $height (0.9 x width, 280..440, shorter on a landscape phone)', (t) async {
        await pumpProductPage(t, size: size);
        expect(_bar(t).expandedHeight, closeTo(height, 0.01));
        expect(_headerHeight(t), closeTo(height, 0.01));
      });
    }

    testWidgets('scrolling collapses the photo to the bar; the product name fades in only then', (t) async {
      await pumpProductPage(t);
      expect(_barTitle(t).opacity, 0);
      await t.drag(_scroll, const Offset(0, -120));
      await settle(t);
      expect(_barTitle(t).opacity, 0, reason: 'still mostly photo');
      await _collapse(t);
      expect(_barTitle(t).opacity, 1);
      expect(_headerHeight(t), kToolbarHeight);
      // The bar keeps the name, the round back button and the buy bar stays.
      expect(
        find.descendant(of: find.byKey(const ValueKey('product-bar-title')), matching: find.text('Product p1')),
        findsOneWidget,
      );
      expect(find.byTooltip('Back'), findsOneWidget);
      expect(find.text('Add to cart'), findsOneWidget);
      // And back to the top: the photo returns, the name hides again.
      await t.drag(_scroll, const Offset(0, 1500));
      await settle(t);
      expect(_headerHeight(t), closeTo(390 * 0.9, 0.01));
      expect(_barTitle(t).opacity, 0);
    });

    testWidgets('pulling down at the top stretches the photo, and it springs back', (t) async {
      await pumpProductPage(t);
      final base = _headerHeight(t);
      final gesture = await t.startGesture(const Offset(200, 600));
      for (var i = 0; i < 4; i++) {
        await gesture.moveBy(const Offset(0, 60));
        await t.pump(const Duration(milliseconds: 16));
      }
      expect(_headerHeight(t), greaterThan(base + 20), reason: 'the photo grew while pulled');
      await gesture.up();
      await settle(t);
      expect(_headerHeight(t), closeTo(base, 0.01));
    });

    testWidgets('pulling down stretches on Android as well (no clamped, dead end)', (t) async {
      debugDefaultTargetPlatformOverride = TargetPlatform.android;
      try {
        await pumpProductPage(t);
        final base = _headerHeight(t);
        final gesture = await t.startGesture(const Offset(200, 600));
        for (var i = 0; i < 4; i++) {
          await gesture.moveBy(const Offset(0, 60));
          await t.pump(const Duration(milliseconds: 16));
        }
        expect(_headerHeight(t), greaterThan(base + 20));
        await gesture.up();
        await settle(t);
      } finally {
        debugDefaultTargetPlatformOverride = null;
      }
    });

    testWidgets('status bar icons are white over the photo and follow the theme once the bar is collapsed', (t) async {
      await pumpProductPage(t);
      expect(_statusStyle(t).statusBarIconBrightness, Brightness.light);
      await _collapse(t);
      expect(_statusStyle(t).statusBarIconBrightness, Brightness.dark);
    });

    testWidgets('dark theme: light icons over the photo and on the dark bar', (t) async {
      await pumpProductPage(t, dark: true);
      expect(_statusStyle(t).statusBarIconBrightness, Brightness.light);
      await _collapse(t);
      expect(_statusStyle(t).statusBarIconBrightness, Brightness.light);
    });

    testWidgets('the back, share and wishlist buttons are round, 48dp targets over the photo', (t) async {
      await pumpProductPage(t);
      for (final tooltip in ['Back', 'Share', 'Add to wishlist']) {
        final rect = t.getRect(find.byTooltip(tooltip));
        expect(rect.width, greaterThanOrEqualTo(48), reason: tooltip);
        expect(rect.height, greaterThanOrEqualTo(48), reason: tooltip);
        expect(rect.top, greaterThanOrEqualTo(0), reason: tooltip);
      }
      // Back at the start edge, share and wishlist at the end edge.
      expect(t.getRect(find.byTooltip('Back')).left, lessThan(40));
      expect(t.getRect(find.byTooltip('Add to wishlist')).right, greaterThan(390 - 20));
    });
  });

  group('page background', () {
    testWidgets('with the dashboard backdrop on, the bar and the sheet stay opaque in the page colour', (t) async {
      await pumpProductPage(t, backdrop: const PageBackdropConfig(color: Color(0xFFF4EBEF)));
      final context = t.element(find.byType(ProductDetailScreen));
      final theme = Theme.of(context);
      expect(theme.scaffoldBackgroundColor.a, 0, reason: 'the scaffold is transparent over the backdrop');
      final page = theme.colorScheme.surfaceContainerLowest;
      expect(page.a, 1);
      expect(_bar(t).backgroundColor, page);
      final sheet = t.widget<Material>(
        find.ancestor(of: find.text('Product p1').last, matching: find.byType(Material)).first,
      );
      expect(sheet.color, page);
    });
  });

  group('dashboard looks', () {
    final fresh = BrandTheme.fromJson({
      'key': 'default',
      'kind': 'base',
      'tokens': {'radius': 'xl', 'card': 'soft', 'header': 'brand', 'nav': 'center_action', 'background': 'gradient'},
      'design': {'key': 'fresh', 'name_en': 'Fresh', 'name_ar': 'منعش', 'name': 'Fresh'},
      'colors': {
        'primary': '#1F6B3A',
        'primary_soft': '#DDEFE2',
        'accent': '#C9A24D',
        'background': '#FBF8EF',
        'surface': '#FFFFFF',
        'ink': '#1B2A21',
      },
    })!;

    for (final dark in [false, true]) {
      testWidgets(
        'the "fresh" design, ${dark ? 'dark' : 'light'}: the bar and the sheet are opaque and nothing overflows',
        (t) async {
          await pumpProductPage(
            t,
            brand: fresh,
            dark: dark,
            detail: richProductJson(),
            size: const Size(320, 640),
            textScale: 1.3,
          );
          final context = t.element(find.byType(ProductDetailScreen));
          final page = Theme.of(context).colorScheme.surfaceContainerLowest;
          expect(page.a, 1);
          expect(_bar(t).backgroundColor, page);
          for (var i = 0; i < 4; i++) {
            await t.drag(_scroll, const Offset(0, -500));
            await settle(t, 4);
          }
          expect(t.takeException(), isNull);
          expect(_barTitle(t).opacity, 1);
        },
      );
    }
  });

  group('loading and error use the same header', () {
    testWidgets('loading: a pinned, stretching bar over the list photo (it flies in) and a skeleton sheet', (t) async {
      final gate = Completer<FakeReply>();
      final preview = ProductSummary.fromJson({
        ...productCard('p1'),
        'image': {'url': 'https://img.test/a.jpg'},
      });
      final host = await pumpProductPage(t, detailReply: (_) => gate.future, preview: preview);
      expect(t.widget<CustomScrollView>(_scroll).physics, isA<BouncingScrollPhysics>());
      expect(_bar(t).pinned, isTrue);
      expect(_bar(t).stretch, isTrue);
      expect(find.byType(ProductGallery), findsNothing);
      final heroTag = preview.heroTag('test');
      expect(find.byWidgetPredicate((w) => w is Hero && w.tag == heroTag), findsOneWidget);
      expect(find.text('Product p1'), findsOneWidget, reason: 'the name comes from the list card');
      expect(find.byType(Skeleton), findsWidgets);
      final loadingHeight = _headerHeight(t);

      gate.complete(ok(productJson()));
      await settle(t);
      expect(find.byType(ProductGallery), findsOneWidget);
      expect(find.byWidgetPredicate((w) => w is Hero && w.tag == heroTag), findsOneWidget);
      expect(_headerHeight(t), loadingHeight, reason: 'the layout does not move when the product arrives');
      expect(host.requests('GET', '/products/p1'), hasLength(1));
    });

    testWidgets('loading without a list photo shows a skeleton header, not a blank page', (t) async {
      final gate = Completer<FakeReply>();
      await pumpProductPage(t, detailReply: (_) => gate.future);
      expect(find.descendant(of: find.byType(FlexibleSpaceBar), matching: find.byType(Skeleton)), findsOneWidget);
      expect(find.byTooltip('Back'), findsOneWidget);
      gate.complete(ok(productJson()));
      await settle(t);
    });

    testWidgets('error: the bar stays with a back button, the message and Retry; Retry loads the page', (t) async {
      var fail = true;
      final host = await pumpProductPage(t, detailReply: (_) => fail ? error(500, 'server_error') : ok(productJson()));
      expect(find.byType(ErrorState), findsOneWidget);
      expect(find.byTooltip('Back'), findsOneWidget);
      expect(t.widget<CustomScrollView>(_scroll).physics, isA<BouncingScrollPhysics>());
      expect(_bar(t).pinned, isTrue);
      fail = false;
      await t.tap(find.text('Try again'));
      await settle(t);
      expect(find.byType(ErrorState), findsNothing);
      expect(find.byType(ProductGallery), findsOneWidget);
      expect(host.requests('GET', '/products/p1').length, greaterThanOrEqualTo(2));
    });

    testWidgets('error: back returns to the previous page', (t) async {
      await pumpProductPage(t, detailReply: (_) => error(404, 'not_found'));
      await t.tap(find.byTooltip('Back'));
      await settle(t);
      expect(find.text('HOME PAGE'), findsOneWidget);
    });
  });

  group('photos and transitions', () {
    testWidgets('a product without photos shows the brand tile, no dots, and nothing to open', (t) async {
      await pumpProductPage(
        t,
        detail: productJson(images: const [], extra: {'image': null}),
      );
      expect(find.byType(PageView), findsNothing);
      expect(find.byType(PagerPill), findsNothing);
      expect(
        find.descendant(of: find.byType(FlexibleSpaceBar), matching: find.byType(AppNetworkImage)),
        findsOneWidget,
      );
      await t.tapAt(const Offset(195, 150));
      await settle(t, 3);
      expect(find.byType(ProductZoomViewer), findsNothing);
      expect(t.takeException(), isNull);
    });

    testWidgets('the list card photo flies into the gallery and back, with no error', (t) async {
      await pumpProductPage(t, homeHero: true);
      // The product is open: the gallery's first photo wears the card's tag.
      expect(
        find.byWidgetPredicate((w) => w is Hero && w.tag == 'product-image-test-p1', skipOffstage: false),
        findsNWidgets(2),
        reason: 'card + gallery',
      );
      // Back, and watch the photo fly home frame by frame.
      await t.tap(find.byTooltip('Back'));
      for (var i = 0; i < 12; i++) {
        await t.pump(const Duration(milliseconds: 40));
        expect(t.takeException(), isNull);
      }
      await settle(t);
      expect(find.byType(ProductDetailScreen), findsNothing);
    });

    testWidgets(
      'opening the page while the product loads flies the card photo, then swaps in the gallery without error',
      (t) async {
        final gate = Completer<FakeReply>();
        final preview = ProductSummary.fromJson({
          ...productCard('p1'),
          'image': {'url': 'https://img.test/a.jpg'},
        });
        await pumpProductPage(t, homeHero: true, detailReply: (_) => gate.future, preview: preview);
        expect(find.byType(ProductGallery), findsNothing);
        gate.complete(ok(productJson()));
        for (var i = 0; i < 10; i++) {
          await t.pump(const Duration(milliseconds: 50));
          expect(t.takeException(), isNull);
        }
        expect(find.byType(ProductGallery), findsOneWidget);
      },
    );
  });

  group('accessibility', () {
    for (final collapsed in [false, true]) {
      testWidgets('tap targets are 48dp and labelled, ${collapsed ? 'collapsed bar' : 'photo open'}', (t) async {
        final handle = t.ensureSemantics();
        await pumpProductPage(t, detail: richProductJson(), signedIn: true);
        if (collapsed) {
          await t.drag(_scroll, const Offset(0, -900));
          await settle(t);
        }
        await expectLater(t, meetsGuideline(androidTapTargetGuideline));
        await expectLater(t, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }

    testWidgets('the photo says which one it is, and that it opens full screen; the bar title is silent while hidden', (
      t,
    ) async {
      final handle = t.ensureSemantics();
      await pumpProductPage(t);
      expect(find.bySemanticsLabel('Image 1 of 3'), findsWidgets);
      expect(t.getSemantics(find.byType(PageView)), isNotNull);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('product-bar-title')),
          matching: find.byType(Text),
          skipOffstage: false,
        ),
        findsOneWidget,
      );
      handle.dispose();
    });
  });

  group('page reuse', () {
    testWidgets('the same page opened for another product starts clean: default size, quantity 1', (t) async {
      t.view
        ..physicalSize = const Size(390, 844)
        ..devicePixelRatio = 1;
      addTearDown(t.view.reset);
      Map<String, dynamic> sized(String id) => {...sizedProductJson(), 'id': id};
      final h = await TestHarness.create(
        handler: routes({
          'GET /products/p1': (_) => ok(sized('p1')),
          'GET /products/p2': (_) => ok(sized('p2')),
          'GET /products/p1/reviews': (_) => page(const []),
          'GET /products/p2/reviews': (_) => page(const []),
          'GET /cart': (_) => ok(const {}),
        }),
      );
      Widget app(String id) => UncontrolledProviderScope(
        container: h.container,
        child: MaterialApp(
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          theme: AppTheme.light(const Locale('en')),
          home: ProductDetailScreen(productId: id),
        ),
      );
      await t.pumpWidget(app('p1'));
      await settle(t);
      await t.tap(find.text('Medium'));
      await t.tap(find.byIcon(Icons.add_rounded));
      await settle(t);
      expect(find.text('QAR 120.00'), findsOneWidget);
      expect(find.text('2'), findsOneWidget);
      await t.pumpWidget(app('p2'));
      await settle(t);
      expect(find.text('QAR 100.00'), findsOneWidget, reason: 'back to the default size');
      expect(find.text('1'), findsOneWidget, reason: 'quantity is back to 1');
      expect(h.adapter.where('GET', '/products/p2'), isNotEmpty);
    });
  });
}
