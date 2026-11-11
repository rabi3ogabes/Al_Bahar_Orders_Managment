import 'dart:async';

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

Map<String, dynamic> _home() => {
  'sections': [
    {
      'id': 's1',
      'type': 'banners',
      'items': [
        {'id': 'b1', 'title': 'The Autumn Edit', 'subtitle': 'New season', 'image_url': null},
      ],
    },
    {'id': 's2', 'type': 'unknown_future_section', 'items': []},
    {
      'id': 's3',
      'type': 'new_arrivals',
      'title': 'New in',
      'items': [productCard('1'), productCard('2')],
    },
  ],
};

void main() {
  Future<TestHarness> boot(WidgetTester t, {String locale = 'en'}) async {
    final h = await TestHarness.create(
      prefs: {'locale': locale, 'onboarding_done': true},
      handler: routes({
        'GET /home': (_) => ok(_home()),
        'GET /categories': (_) => ok([]),
        'GET /products/1': (_) => ok({
          ...productCard('1'),
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
          'description': '<p>Solid oak.</p>',
        }),
        'GET /products/1/reviews': (_) => page([]),
        'GET /products/1/questions': (_) => page([]),
      }),
    );
    await t.pumpWidget(
      UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
    );
    // Splash → home.
    await t.pump(const Duration(milliseconds: 1600));
    await t.pump(const Duration(milliseconds: 500));
    return h;
  }

  testWidgets('boots to a server-driven home and opens a product (EN)', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    final h = await boot(t);
    expect(find.text('The Autumn Edit'), findsOneWidget);
    expect(find.text('New in'), findsOneWidget);
    expect(find.text('Product 1'), findsOneWidget);
    expect(find.text('Home'), findsWidgets, reason: 'bottom navigation');

    unawaited(h.read(routerProvider).push('/products/1'));
    await t.pump(const Duration(milliseconds: 600));
    await t.pump(const Duration(milliseconds: 600));
    expect(find.text('Add to cart'), findsOneWidget);
    expect(h.adapter.where('GET', '/products/1'), hasLength(1));
    await _teardown(t);
  });

  testWidgets('Arabic boot renders RTL shell with Arabic labels', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await boot(t, locale: 'ar');
    expect(find.text('الرئيسية'), findsWidgets);
    final dir = Directionality.of(t.element(find.text('The Autumn Edit')));
    expect(dir, TextDirection.rtl);
    await _teardown(t);
  });

  testWidgets('deep link to a protected route redirects guests to sign-in', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    final h = await boot(t);
    h.read(routerProvider).go('/orders/o1');
    await t.pump(const Duration(milliseconds: 400));
    expect(h.read(routerProvider).routerDelegate.currentConfiguration.uri.path, '/login');
    expect(find.text('Send code'), findsOneWidget);
    await _teardown(t);
  });
}

/// Unmounts the app and lets pending timers (banner auto-advance, image
/// fades, snackbars) complete so the test binding sees a clean state.
Future<void> _teardown(WidgetTester t) async {
  await t.pumpWidget(const SizedBox.shrink());
  await t.pump(const Duration(seconds: 10));
}
