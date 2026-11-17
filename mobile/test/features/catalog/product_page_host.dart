import 'dart:async';

import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/page_backdrop_config.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/product_detail_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';

const kImages = ['https://img.test/a.jpg', 'https://img.test/b.jpg', 'https://img.test/c.jpg'];

/// A product the way `GET /products/p1` sends it: three photos, one variant, in stock.
Map<String, dynamic> productJson({
  String id = 'p1',
  List<String> images = kImages,
  Map<String, dynamic> extra = const {},
}) => {
  ...productCard(id),
  'description': '<p>Solid oak, hand finished.</p>',
  'images': [
    for (final url in images) {'url': url},
  ],
  'variants': [
    {
      'id': 'v1',
      'name': 'Oak',
      'attribute_value_ids': <String>[],
      'price': price('100.00'),
      'available_in_branch': true,
      'in_stock': true,
    },
  ],
  'default_variant_id': 'v1',
  ...extra,
};

/// A product sold in two sizes: size M costs more, is out of stock when [mOutOfStock], and has its own photos.
Map<String, dynamic> sizedProductJson({bool mOutOfStock = false}) => productJson(
  extra: {
    'images': [
      {'url': 'https://img.test/s1.jpg', 'variant_id': 'v1'},
      {'url': 'https://img.test/s2.jpg', 'variant_id': 'v1'},
      {'url': 'https://img.test/m1.jpg', 'variant_id': 'v2'},
    ],
    'attributes': [
      {
        'code': 'size',
        'name': 'Size',
        'type': 'select',
        'values': [
          {'id': 'sz-s', 'code': 's', 'name': 'Small'},
          {'id': 'sz-m', 'code': 'm', 'name': 'Medium'},
        ],
      },
    ],
    'variants': [
      {
        'id': 'v1',
        'name': 'Small',
        'attribute_value_ids': ['sz-s'],
        'price': price('100.00'),
        'available_in_branch': true,
        'in_stock': true,
      },
      {
        'id': 'v2',
        'name': 'Medium',
        'attribute_value_ids': ['sz-m'],
        'price': price('120.00'),
        'available_in_branch': true,
        'in_stock': !mOutOfStock,
      },
    ],
  },
);

class ProductPageHost {
  ProductPageHost(this.harness, this.router, this.shared);
  final TestHarness harness;
  final GoRouter router;

  /// What the page handed to the share sheet.
  final List<ShareParams> shared;

  List<RecordedRequest> requests(String method, String path) => harness.adapter.where(method, path);
}

/// Lets the page finish its work (requests, route and size animations) without waiting for endless skeleton shimmers.
Future<void> settle(WidgetTester tester, [int frames = 12]) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

/// Opens `/products/p1` in a small router app (home → product, a login page for guests) over a fake backend.
///
/// [detailReply] replaces the product response (hold it with a Completer to stay on the loading page, or send an error).
Future<ProductPageHost> pumpProductPage(
  WidgetTester tester, {
  Map<String, dynamic>? detail,
  FutureOr<FakeReply> Function(RecordedRequest r)? detailReply,
  Size size = const Size(390, 844),
  double textScale = 1.0,
  Locale locale = const Locale('en'),
  bool dark = false,
  bool signedIn = false,
  bool deepLink = false,
  ProductSummary? preview,
  PageBackdropConfig? backdrop,
  List<Map<String, dynamic>> reviews = const [],
  bool homeHero = false,
  bool shareFails = false,
  BrandTheme? brand,
  List<Override> overrides = const [],
}) async {
  tester.view
    ..physicalSize = size
    ..devicePixelRatio = 1;
  tester.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(tester.view.reset);
  addTearDown(tester.platformDispatcher.clearAllTestValues);

  final shared = <ShareParams>[];
  final h = await TestHarness.create(
    tokens: signedIn ? signedInTokens : null,
    prefs: {'locale': locale.languageCode, 'onboarding_done': true},
    overrides: [
      shareActionProvider.overrideWithValue((params) async {
        if (shareFails) throw StateError('no share sheet');
        shared.add(params);
      }),
      ...overrides,
    ],
    handler: routes({
      'GET /products/p1': detailReply ?? (_) => ok(detail ?? productJson()),
      'GET /products/p1/reviews': (_) => page(reviews),
      'GET /me': (_) => ok(userJson()),
      'GET /me/wishlist': (_) => ok(const []),
      'POST /me/wishlist': (_) => ok(const {}),
      'DELETE /me/wishlist/p1': (_) => ok(const {}),
      'GET /cart': (_) => ok(cartJson()),
    }),
  );

  final router = GoRouter(
    initialLocation: deepLink ? '/products/p1' : '/home',
    routes: [
      GoRoute(
        path: '/home',
        builder: (_, _) => Scaffold(
          body: Center(
            child: homeHero
                ? const Hero(
                    tag: 'product-image-test-p1',
                    child: SizedBox(width: 120, height: 120, child: ColoredBox(color: Colors.brown)),
                  )
                : const Text('HOME PAGE'),
          ),
        ),
      ),
      GoRoute(
        path: '/login',
        builder: (_, _) => const Scaffold(body: Center(child: Text('LOGIN PAGE'))),
      ),
      GoRoute(
        path: '/products/:id',
        builder: (_, s) => ProductDetailScreen(
          productId: s.pathParameters['id']!,
          heroScope: s.uri.queryParameters['hero'] ?? '',
          preview: s.extra is ProductSummary ? s.extra! as ProductSummary : null,
        ),
      ),
    ],
  );
  addTearDown(router.dispose);

  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: h.container,
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
        theme: AppTheme.light(locale, brand: brand, backdrop: backdrop),
        darkTheme: AppTheme.dark(locale, brand: brand, backdrop: backdrop),
        themeMode: dark ? ThemeMode.dark : ThemeMode.light,
      ),
    ),
  );
  await tester.pump();
  if (!deepLink) {
    unawaited(router.push('/products/p1?hero=test', extra: preview));
  }
  await settle(tester);
  return ProductPageHost(h, router, shared);
}

/// A product sold in two colours, each bought in its own quantity.
Map<String, dynamic> colourProductJson({bool purchasableAtBranch = true}) => productJson(
  extra: {
    'attributes': [
      {
        'code': 'color',
        'name': 'Colour',
        'type': 'color',
        'values': [
          {'id': 'c1', 'code': 'red', 'name': 'Red', 'color_hex': '#8A1538'},
          {'id': 'c2', 'code': 'white', 'name': 'White', 'color_hex': '#FFFFFF'},
        ],
      },
    ],
    'variants': [
      {
        'id': 'v1',
        'name': 'Red',
        'attribute_value_ids': ['c1'],
        'price': price('100.00'),
        'available_in_branch': true,
        'in_stock': true,
      },
      {
        'id': 'v2',
        'name': 'White',
        'attribute_value_ids': ['c2'],
        'price': price('100.00'),
        'available_in_branch': true,
        'in_stock': true,
      },
    ],
    if (!purchasableAtBranch) 'availability': {'purchasable': false, 'reason': 'not_in_branch'},
  },
);

/// Everything a real product page can hold, with long texts: for overflow checks on the narrowest phones.
Map<String, dynamic> richProductJson({Object? fee, String? mode}) => {
  ...colourProductJson(),
  'name': 'Hand-woven Omani frankincense burner with a solid brass base and a very long product name',
  'brand': {'id': 'b1', 'name': 'Al Bahar Heritage Collection'},
  'rating': {'average': 4.6, 'count': 128},
  'short_description': 'A centrepiece for every majlis, finished by hand in Muscat from sustainably sourced oak.',
  'specifications': [
    {'label': 'Material', 'value': 'Solid oak and polished brass'},
    {'label': 'Finish', 'value': 'Hand-rubbed natural oil'},
  ],
  'dimensions': {'length': 120, 'width': 60.5, 'height': 45, 'unit': 'cm'},
  'weight': {'value': 18.5, 'unit': 'kg'},
  'warranty': {'duration_months': 24, 'provider': 'Al Bahar', 'terms': 'Covers manufacturing defects.'},
  'requires_assembly': true,
  'assembly_service_available': true,
  'is_oversized': true,
  'fulfillment': {'delivery': true, 'pickup': true},
  'related': [productCard('r1'), productCard('r2')],
  'upsell': [productCard('u1')],
  'extra_delivery_fee': ?fee,
  'extra_delivery_fee_mode': ?mode,
};
