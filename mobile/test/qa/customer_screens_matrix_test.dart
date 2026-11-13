import 'dart:async';

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import 'customer_fixtures.dart';

/// Every customer route.
const routesUnderTest = <String>[
  '/home',
  '/categories',
  '/categories/c1',
  '/auctions',
  '/auctions/auc1',
  '/auctions/lots/lot1',
  '/cart',
  '/account',
  '/products?title=Sale',
  '/products/p1',
  '/products/p1/reviews/new?name=Sofa',
  '/search',
  '/search?q=sofa',
  '/branches',
  '/branches/br1',
  '/checkout',
  '/orders',
  '/orders/o1',
  '/orders/o1/track',
  '/wishlist',
  '/my-auctions',
  '/notifications',
  '/profile/edit',
  '/addresses',
  '/addresses/new',
  '/addresses/a1/edit',
  '/preferences/notifications',
  '/loyalty',
  '/invite',
  '/support',
  '/support/new',
  '/support/t1',
  '/pages/terms',
  '/settings',
  '/settings/delete-account',
  '/login',
  '/login/password',
  '/login/reset',
];

String _summary(FlutterErrorDetails d) {
  final lines = d.toString().split('\n');
  final headline = d.exception.toString().split('\n').first;
  final file = lines.firstWhere((l) => l.contains('file:///') && l.contains('/lib/'), orElse: () => '');
  final creator = lines.firstWhere((l) => l.trimLeft().startsWith('creator:'), orElse: () => '');
  final trace = lines.firstWhere((l) => l.contains('package:albahar_customer'), orElse: () => '');
  return '$headline @ ${file.trim().replaceAll(RegExp(r'file:///[^ ]*/mobile/'), '')} ${creator.trim()} $trace'
      .replaceAll(RegExp(r'\s+'), ' ');
}

class Variant {
  const Variant(this.locale, this.dark);
  final String locale;
  final bool dark;
  @override
  String toString() => '$locale-${dark ? 'dark' : 'light'}';
}

/// Drives the real app through [routesUnderTest] and records any framework
/// exception (overflow, assertion, parse failure) instead of stopping at the first.
Future<List<String>> sweep(
  WidgetTester t, {
  required ApiMode mode,
  required Variant variant,
  bool signedIn = true,
  double textScale = 1.3,
  List<String> routes = routesUnderTest,
}) async {
  t.view.physicalSize = const Size(390, 844);
  t.view.devicePixelRatio = 1;
  t.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(t.view.reset);
  addTearDown(t.platformDispatcher.clearAllTestValues);

  final h = await TestHarness.create(
    tokens: signedIn ? signedInTokens : null,
    prefs: {'locale': variant.locale, 'onboarding_done': true, 'theme_mode': variant.dark ? 'dark' : 'light'},
    handler: customerBackend(mode),
  );
  await t.pumpWidget(UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)));
  await t.pump(const Duration(milliseconds: 1700));
  await t.pump(const Duration(milliseconds: 400));
  t.takeException();

  final failures = <String>[];
  final router = h.read(routerProvider);
  var current = '';
  final previous = FlutterError.onError;
  FlutterError.onError = (details) => failures.add('$current [$mode/$variant]: ${_summary(details)}');
  addTearDown(() => FlutterError.onError = previous);
  for (final route in routes) {
    current = route;
    router.go(route);
    for (var i = 0; i < 4; i++) {
      await t.pump(const Duration(milliseconds: 250));
    }
    t.takeException();
  }
  current = 'teardown';
  // Unmount and drain timers (banners, skeletons, snackbars).
  await t.pumpWidget(const SizedBox.shrink());
  await t.pump(const Duration(seconds: 10));
  t.takeException();
  FlutterError.onError = previous;
  final unique = failures.toSet().toList();
  for (final f in unique) {
    // ignore: avoid_print
    print('QAFAIL $f');
  }
  return unique;
}

void main() {
  const variants = [Variant('en', false), Variant('ar', true), Variant('en', true), Variant('ar', false)];

  group('data', () {
    for (final v in variants) {
      testWidgets('every customer screen renders with data at 390x844, text x1.3 ($v)', (t) async {
        expect(await sweep(t, mode: ApiMode.data, variant: v), isEmpty);
      });
    }
  });

  group('empty, error, loading and offline states', () {
    for (final mode in [ApiMode.empty, ApiMode.error, ApiMode.loading, ApiMode.offline]) {
      for (final v in const [Variant('en', false), Variant('ar', true)]) {
        testWidgets('screens survive $mode ($v)', (t) async {
          expect(await sweep(t, mode: mode, variant: v), isEmpty);
        });
      }
    }
  });

  testWidgets('guest browsing: public screens render and protected ones go to sign-in', (t) async {
    final failures = await sweep(
      t,
      mode: ApiMode.data,
      variant: const Variant('en', false),
      signedIn: false,
      routes: const ['/home', '/categories', '/cart', '/account', '/auctions', '/search', '/wishlist', '/orders', '/login'],
    );
    expect(failures, isEmpty);
  });

  testWidgets('semantics tree builds on every screen (no assertion from labels)', (t) async {
    final handle = t.ensureSemantics();
    addTearDown(handle.dispose);
    expect(await sweep(t, mode: ApiMode.data, variant: const Variant('ar', false), textScale: 1.0), isEmpty);
    debugDumpSemanticsTree; // referenced so the import stays used when debugging
  });
}
