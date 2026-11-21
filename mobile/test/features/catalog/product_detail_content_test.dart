import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_gallery.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_header.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_page_sections.dart';
import 'package:albahar_customer/l10n/generated/app_localizations_ar.dart';
import 'package:albahar_customer/l10n/generated/app_localizations_en.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import '../../qa/test_fonts.dart';
import 'product_page_host.dart';

Finder get _scroll => find.byType(CustomScrollView).first;
Finder get _pager => find.byType(PageView);
Finder get _pill => find.byType(PagerPill);
double _headerHeight(WidgetTester t) => t.getSize(find.byType(FlexibleSpaceBar)).height;

List<AppNetworkImage> _galleryImages(WidgetTester t) =>
    t.widgetList<AppNetworkImage>(find.descendant(of: _pager, matching: find.byType(AppNetworkImage))).toList();

String? _firstPhoto(WidgetTester t) => _galleryImages(t).first.url;
int _pillIndex(WidgetTester t) => t.widget<PagerPill>(_pill).index;

void main() {
  setUpAll(loadAppFonts);

  group('gallery', () {
    testWidgets('swipes between the photos; the dots follow', (t) async {
      await pumpProductPage(t);
      expect(_firstPhoto(t), kImages[0]);
      expect(t.widget<PagerPill>(_pill).count, 3);
      expect(_pillIndex(t), 0);
      await t.drag(_pager, const Offset(-300, 0));
      await settle(t);
      expect(_pillIndex(t), 1);
      expect(_firstPhoto(t), kImages[1]);
      await t.drag(_pager, const Offset(300, 0));
      await settle(t);
      expect(_pillIndex(t), 0);
    });

    testWidgets('a single photo has no dots; many photos show a counter instead of dots', (t) async {
      await pumpProductPage(t, detail: productJson(images: const ['https://img.test/only.jpg']));
      expect(_pill, findsNothing);
      expect(_pager, findsOneWidget);
    });

    testWidgets('PagerPill: dots up to seven photos, "3 / 12" beyond', (t) async {
      await t.pumpWidget(
        wrapApp(const Column(children: [PagerPill(index: 1, count: 3), PagerPill(index: 2, count: 12)])),
      );
      expect(find.text('3 / 12'), findsOneWidget);
      expect(find.byType(AnimatedContainer), findsNWidgets(3));
    });

    testWidgets('tapping a photo opens the full-screen viewer on that photo; back closes it', (t) async {
      await pumpProductPage(t);
      await t.drag(_pager, const Offset(-300, 0));
      await settle(t);
      await t.tap(_pager);
      await settle(t);
      expect(find.byType(ProductZoomViewer), findsOneWidget);
      expect(find.text('2 / 3'), findsOneWidget);
      await t.tap(find.descendant(of: find.byType(ProductZoomViewer), matching: find.byType(BackButton)));
      await settle(t);
      expect(find.byType(ProductZoomViewer), findsNothing);
      expect(find.byType(ProductGallery), findsOneWidget);
    });

    testWidgets('the viewer zooms on double tap, locks swiping while zoomed, and zooms out again', (t) async {
      await pumpProductPage(t);
      await t.tap(_pager);
      await settle(t);
      final viewer = find.byType(InteractiveViewer);
      double zoom() => t.widget<InteractiveViewer>(viewer).transformationController!.value.getMaxScaleOnAxis();
      expect(zoom(), 1);
      Future<void> doubleTap() async {
        await t.tapAt(const Offset(195, 400));
        await t.pump(const Duration(milliseconds: 60));
        await t.tapAt(const Offset(195, 400));
        await settle(t, 6);
      }

      await doubleTap();
      expect(zoom(), closeTo(2.5, 0.01));
      // Panning around a zoomed photo must not flip to the next one.
      final viewerPages = find.descendant(of: find.byType(ProductZoomViewer), matching: find.byType(PageView));
      await t.drag(viewerPages, const Offset(-300, 0));
      await settle(t);
      expect(find.text('1 / 3'), findsOneWidget);
      await doubleTap();
      expect(zoom(), closeTo(1, 0.01));
      await t.drag(viewerPages, const Offset(-300, 0));
      await settle(t);
      expect(find.text('2 / 3'), findsOneWidget);
    });

    testWidgets('photos are decoded at screen size, never at full resolution', (t) async {
      await pumpProductPage(t, size: const Size(390, 844));
      for (final image in _galleryImages(t)) {
        expect(image.memCacheWidth, 390);
      }
      await t.tap(_pager);
      await settle(t);
      final viewerImages = t.widgetList<AppNetworkImage>(
        find.descendant(of: find.byType(ProductZoomViewer), matching: find.byType(AppNetworkImage)),
      );
      expect(viewerImages, isNotEmpty);
      for (final image in viewerImages) {
        expect(image.memCacheWidth, 780, reason: 'twice the screen, so a 2x zoom stays sharp');
      }
    });

    testWidgets('the first photo carries the list card hero tag; every hero tag on the page is unique', (t) async {
      await pumpProductPage(t, detail: richProductJson());
      final tags = t.widgetList<Hero>(find.byType(Hero)).map((h) => h.tag).toList();
      expect(tags, contains('product-image-test-p1'));
      expect(
        tags.toSet().length,
        tags.length,
        reason: 'duplicate hero tags would assert during the route change: $tags',
      );
    });
  });

  group('colours and sizes', () {
    testWidgets('choosing another size changes the photos and the price; the header does not move', (t) async {
      await pumpProductPage(t, detail: sizedProductJson());
      expect(find.text('QAR 100.00'), findsOneWidget);
      expect(_firstPhoto(t), 'https://img.test/s1.jpg');
      final headerBefore = _headerHeight(t);
      final topBefore = t.getTopLeft(find.text('Product p1').last).dy;

      // Look at the second photo first, then change the size: the gallery starts again from the first photo.
      await t.drag(_pager, const Offset(-300, 0));
      await settle(t);
      expect(_pillIndex(t), 1);
      await t.tap(find.text('Medium'));
      await settle(t);
      expect(find.text('QAR 120.00'), findsOneWidget);
      expect(find.text('QAR 100.00'), findsNothing);
      expect(_firstPhoto(t), 'https://img.test/m1.jpg');
      expect(_pillIndex(t), 0);
      expect(_headerHeight(t), headerBefore);
      expect(t.getTopLeft(find.text('Product p1').last).dy, topBefore, reason: 'the title did not jump');

      await t.tap(find.text('Small'));
      await settle(t);
      expect(find.text('QAR 100.00'), findsOneWidget);
      expect(_firstPhoto(t), 'https://img.test/s1.jpg');
    });

    testWidgets('a size that is out of stock turns the buy bar into "notify me"; another size brings it back', (
      t,
    ) async {
      await pumpProductPage(t, detail: sizedProductJson(mOutOfStock: true));
      expect(find.text('Add to cart'), findsOneWidget);
      expect(find.byIcon(Icons.add_rounded), findsOneWidget);
      await t.tap(find.text('Medium'));
      await settle(t);
      expect(find.text('Notify me when available'), findsOneWidget);
      expect(find.text('Add to cart'), findsNothing);
      expect(find.byIcon(Icons.add_rounded), findsNothing, reason: 'no quantity for something that cannot be bought');
      expect(find.text('Out of stock at Your branch'), findsOneWidget);
      await t.tap(find.text('Small'));
      await settle(t);
      expect(find.text('Add to cart'), findsOneWidget);
      expect(find.text('Available at Your branch'), findsOneWidget);
    });

    testWidgets('a product in several colours: per-colour quantities and an "add to bag" button with the count', (
      t,
    ) async {
      await pumpProductPage(t, detail: colourProductJson());
      expect(find.text('Colour'), findsOneWidget);
      expect(find.byType(QuantityStepper), findsNWidgets(2), reason: 'one per colour, none in the buy bar');
      final add = find.byIcon(Icons.add_rounded);
      await t.tap(add.first);
      await settle(t, 3);
      await t.tap(add.at(1));
      await settle(t, 3);
      expect(find.text('Add 2 to bag'), findsOneWidget);
    });

    testWidgets('colours cannot be bought when the product is not sold at the branch (even if a colour has stock)', (
      t,
    ) async {
      await pumpProductPage(t, detail: colourProductJson(purchasableAtBranch: false));
      expect(find.text('Notify me when available'), findsOneWidget);
      expect(find.textContaining('Add '), findsNothing);
    });
  });

  group('buy bar', () {
    testWidgets('quantity stops at 10 and at 1', (t) async {
      await pumpProductPage(t);
      final plus = find.byIcon(Icons.add_rounded);
      final minus = find.byIcon(Icons.remove_rounded);
      await t.tap(minus);
      await settle(t, 3);
      expect(find.text('1'), findsOneWidget);
      for (var i = 0; i < 14; i++) {
        await t.tap(plus);
        await t.pump(const Duration(milliseconds: 300));
      }
      await settle(t, 3);
      expect(find.text('10'), findsOneWidget);
      expect(find.text('11'), findsNothing);
    });

    testWidgets('stays on screen while the page scrolls', (t) async {
      await pumpProductPage(t);
      expect(find.text('Add to cart'), findsOneWidget);
      final before = t.getTopLeft(find.text('Add to cart'));
      await t.drag(_scroll, const Offset(0, -900));
      await settle(t);
      expect(t.getTopLeft(find.text('Add to cart')), before);
    });
  });

  group('wishlist and share', () {
    testWidgets('guest: the heart asks to sign in', (t) async {
      await pumpProductPage(t);
      await t.tap(find.byTooltip('Add to wishlist'));
      await settle(t);
      expect(find.text('LOGIN PAGE'), findsOneWidget);
    });

    testWidgets('signed in: the heart saves the product and flips, then removes it', (t) async {
      final host = await pumpProductPage(t, signedIn: true);
      await t.tap(find.byTooltip('Add to wishlist'));
      await settle(t);
      final posts = host.requests('POST', '/me/wishlist');
      expect(posts, hasLength(1));
      expect(posts.single.body, {'product_id': 'p1', 'variant_id': 'v1'});
      expect(find.byTooltip('Remove from wishlist'), findsOneWidget);
      await t.tap(find.byTooltip('Remove from wishlist'));
      await settle(t);
      expect(host.requests('DELETE', '/me/wishlist/p1'), hasLength(1));
      expect(find.byTooltip('Add to wishlist'), findsOneWidget);
    });

    testWidgets('the buttons keep working while the header is collapsed', (t) async {
      final host = await pumpProductPage(t, signedIn: true);
      await t.drag(_scroll, const Offset(0, -900));
      await settle(t);
      await t.tap(find.byTooltip('Share'));
      await settle(t, 3);
      expect(host.shared, hasLength(1));
      await t.tap(find.byTooltip('Add to wishlist'));
      await settle(t);
      expect(host.requests('POST', '/me/wishlist'), hasLength(1));
    });

    testWidgets('share sends the name, the price and the plain link', (t) async {
      final host = await pumpProductPage(t);
      await t.tap(find.byTooltip('Share'));
      await settle(t, 3);
      expect(host.shared, hasLength(1));
      expect(host.shared.single.subject, 'Product p1');
      expect(host.shared.single.text, startsWith('Take a look at this at Al Bahar Home & Living:\n\n*Product p1'));
      expect(host.shared.single.text, contains('Price: QAR 100.00'));
      expect(host.shared.single.text, contains('\nhttps://'));
      expect(host.shared.single.text, contains('p-p1'));
      final origin = host.shared.single.sharePositionOrigin;
      expect(origin, isNotNull, reason: 'iPads anchor the share sheet to the button');
      expect(origin!.size, const Size(48, 48));
      expect(origin.center, t.getCenter(find.byTooltip('Share')));
    });

    testWidgets('a share sheet that fails shows a message instead of an unhandled error', (t) async {
      final host = await pumpProductPage(t, shareFails: true);
      await t.tap(find.byTooltip('Share'));
      await settle(t, 3);
      expect(t.takeException(), isNull);
      expect(find.byType(SnackBar), findsOneWidget);
      expect(host.shared, isEmpty);
    });
  });

  group('back', () {
    testWidgets('closes the page', (t) async {
      await pumpProductPage(t);
      await t.tap(find.byTooltip('Back'));
      await settle(t);
      expect(find.text('HOME PAGE'), findsOneWidget);
    });

    testWidgets('opened from a link with nothing behind it, back goes home', (t) async {
      await pumpProductPage(t, deepLink: true);
      expect(find.text('HOME PAGE'), findsNothing);
      await t.tap(find.byTooltip('Back'));
      await settle(t);
      expect(find.text('HOME PAGE'), findsOneWidget);
    });
  });

  group('extra delivery fee note', () {
    final en = AppLocalizationsEn();
    final ar = AppLocalizationsAr();
    const fee = {'amount': '50.00', 'currency': 'QAR', 'formatted': 'QAR 50.00'};
    Finder note() => find.byKey(const ValueKey('extra-delivery-note'));

    testWidgets('per item', (t) async {
      await pumpProductPage(
        t,
        detail: productJson(extra: {'extra_delivery_fee': fee, 'extra_delivery_fee_mode': 'per_unit'}),
      );
      expect(note(), findsOneWidget);
      expect(find.text('Extra delivery fee QAR 50.00 per item'), findsOneWidget);
      expect(find.text(en.extraDeliveryFeePerUnit('QAR 50.00')), findsOneWidget);
    });

    testWidgets('once, whatever the quantity', (t) async {
      await pumpProductPage(
        t,
        detail: productJson(extra: {'extra_delivery_fee': fee, 'extra_delivery_fee_mode': 'per_line'}),
      );
      expect(find.text('Extra delivery fee QAR 50.00 for this item, whatever the quantity'), findsOneWidget);
    });

    testWidgets('no mode means per item; a plain amount is shown as QAR', (t) async {
      await pumpProductPage(t, detail: productJson(extra: {'extra_delivery_fee': '35.5'}));
      expect(find.text('Extra delivery fee QAR 35.50 per item'), findsOneWidget);
    });

    for (final (name, extra) in <(String, Map<String, dynamic>)>[
      ('zero', {'extra_delivery_fee': '0.00'}),
      (
        'a zero money object',
        {
          'extra_delivery_fee': {'amount': '0.00', 'currency': 'QAR', 'formatted': 'QAR 0.00'},
        },
      ),
      ('missing', <String, dynamic>{}),
      ('null', {'extra_delivery_fee': null}),
      ('negative', {'extra_delivery_fee': '-5'}),
      ('unreadable', {'extra_delivery_fee': 'free'}),
      (
        'a product that is not delivered',
        {
          'extra_delivery_fee': fee,
          'fulfillment': {'delivery': false, 'pickup': true},
        },
      ),
    ]) {
      testWidgets('hidden for $name', (t) async {
        await pumpProductPage(t, detail: productJson(extra: extra));
        expect(note(), findsNothing);
        expect(find.textContaining('Extra delivery fee'), findsNothing);
      });
    }

    testWidgets('is part of the delivery card, under the delivery chips', (t) async {
      await pumpProductPage(t, detail: productJson(extra: {'extra_delivery_fee': fee}));
      expect(find.descendant(of: find.byType(ProductAvailabilityCard), matching: note()), findsOneWidget);
      final chips = t.getBottomLeft(find.text('Home delivery')).dy;
      expect(t.getTopLeft(note()).dy, greaterThan(chips));
    });

    testWidgets('Arabic wording', (t) async {
      await pumpProductPage(
        t,
        locale: const Locale('ar'),
        detail: productJson(extra: {'extra_delivery_fee': fee, 'extra_delivery_fee_mode': 'per_unit'}),
      );
      expect(find.text(ar.extraDeliveryFeePerUnit('QAR 50.00')), findsOneWidget);
      expect(ar.extraDeliveryFeePerLine('QAR 50.00'), contains('مهما كانت الكمية'));
    });
  });

  group('every look fits the narrowest phone (320 px, text x1.3)', () {
    for (final (locale, dark) in [('en', false), ('ar', false), ('en', true), ('ar', true)]) {
      testWidgets('rich product, $locale ${dark ? 'dark' : 'light'}: top to bottom and back, no overflow', (t) async {
        await pumpProductPage(
          t,
          detail: richProductJson(
            fee: {'amount': '50.00', 'currency': 'QAR', 'formatted': 'QAR 50.00'},
            mode: 'per_line',
          ),
          size: const Size(320, 640),
          textScale: 1.3,
          locale: Locale(locale),
          dark: dark,
          reviews: [
            {
              'id': 'r1',
              'rating': 5,
              'title': 'Exactly what we needed for the majlis, beautiful finish',
              'body': 'Delivered on time and assembled by the team. The brass is lovely and the oak feels solid.',
              'author': 'Mohammed',
              'is_verified_purchase': true,
            },
          ],
        );
        expect(t.takeException(), isNull);
        for (var i = 0; i < 8; i++) {
          await t.drag(_scroll, const Offset(0, -500));
          await settle(t, 4);
          expect(t.takeException(), isNull, reason: 'scrolling down, step $i');
        }
        for (var i = 0; i < 8; i++) {
          await t.drag(_scroll, const Offset(0, 500));
          await settle(t, 4);
          expect(t.takeException(), isNull, reason: 'scrolling up, step $i');
        }
        await settle(t, 12);
        expect(_headerHeight(t), closeTo(288, 0.01));
      });
    }

    testWidgets('sized product with the cart and the other buttons at 320 px, text x1.3', (t) async {
      await pumpProductPage(t, detail: sizedProductJson(), size: const Size(320, 640), textScale: 1.3);
      expect(t.takeException(), isNull);
      await t.tap(find.text('Medium'));
      await settle(t);
      expect(t.takeException(), isNull);
    });

    testWidgets('loading and error pages at 320 px, text x1.3, Arabic dark', (t) async {
      await pumpProductPage(
        t,
        detailReply: (_) => error(500, 'server_error'),
        size: const Size(320, 640),
        textScale: 1.3,
        locale: const Locale('ar'),
        dark: true,
      );
      expect(t.takeException(), isNull);
      expect(find.byType(ProductSliverHeader), findsOneWidget);
    });
  });
}
