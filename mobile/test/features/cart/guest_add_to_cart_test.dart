@Timeout(Duration(minutes: 5))
library;

// A guest taps "Add to cart" on a product: they sign in, land back on the same product page (not the home page), and the add is done for
// them with the usual confirmation. Nothing is added when they carry on as a guest or leave the sign-in.
import 'dart:async';

import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/features/auth/domain/auth_models.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/cart/domain/cart_addition.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/cart/presentation/state/pending_cart_add.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show FakeCartServer, backend, boot, en, settle, teardown;
import '../../qa/test_fonts.dart';

String _location(TestHarness h) => currentAppLocation(h.read(routerProvider));

/// The `from` the sign-in screen was opened with: where the person goes back to.
String? _from(TestHarness h) {
  final here = Uri.parse(_location(h));
  return here.path.startsWith('/login') ? here.queryParameters['from'] : null;
}

/// The phone-code sign-in on a pretend server, next to the cart's.
FakeHandler _shop(FakeCartServer cart) => backend(
  extra: (r) {
    if (r.method == 'POST' && r.path == '/auth/otp/request') {
      return ok({'sent': true, 'expires_in': 300, 'resend_in': 60});
    }
    if (r.method == 'POST' && r.path == '/auth/otp/verify') {
      return ok({
        'status': 'authenticated',
        'user': {'id': 'u1', 'name': 'Noor', 'phone': '+97455123456', 'locale': 'en'},
        'tokens': tokens('A', 'R'),
      });
    }
    return cart.handle(r);
  },
);

/// Types the phone number and the code on the real sign-in screens, as a customer would.
Future<void> _signInThroughTheScreens(WidgetTester t) async {
  await t.enterText(find.byType(AppTextField).first, '55123456');
  await t.tap(find.text(en.sendCode));
  await settle(t);
  await t.enterText(find.byType(AppTextField).first, '123456');
  await settle(t, 10);
}

/// Signs in directly and leaves the sign-in screens, as the screens do after a verified code.
Future<void> _signIn(WidgetTester t, TestHarness h) async {
  await h
      .read(sessionControllerProvider.notifier)
      .signIn(
        Authenticated(
          user: const AppUser(id: 'u1', name: 'Noor', phone: '+97455123456', locale: 'en'),
          tokens: signedInTokens,
        ),
      );
  leaveSignIn(rootNavigatorKey.currentContext!, null);
  await settle(t);
}

Future<void> _openProductAsGuest(WidgetTester t, TestHarness h) async {
  // Like a tap on a product card: pushed over the home page.
  unawaited(h.read(routerProvider).push('/products/p1'));
  await settle(t);
}

Future<void> _tapAdd(WidgetTester t) async {
  final target = find.text(en.addToCart).first;
  await t.ensureVisible(target);
  await t.pump();
  await t.tap(target);
  await settle(t);
}

ProductSummary _product() => ProductSummary.fromJson(productCard('p1'));

void main() {
  setUpAll(loadAppFonts);

  group('where the person really is', () {
    testWidgets('a page opened with push is the location, not the page below it', (t) async {
      final router = GoRouter(
        initialLocation: '/home',
        routes: [
          GoRoute(path: '/home', builder: (_, _) => const Text('home')),
          GoRoute(path: '/products/:id', builder: (_, s) => Text('product ${s.pathParameters['id']}')),
        ],
      );
      await t.pumpWidget(MaterialApp.router(routerConfig: router));
      await t.pumpAndSettle();
      expect(currentAppLocation(router), '/home');

      unawaited(router.push('/products/7?hero=x'));
      await t.pumpAndSettle();
      expect(
        router.routerDelegate.currentConfiguration.uri.toString(),
        '/home',
        reason: 'what the router alone says (the bug)',
      );
      expect(currentAppLocation(router), '/products/7?hero=x');

      router.go('/products/9');
      await t.pumpAndSettle();
      expect(currentAppLocation(router), '/products/9');
    });
  });

  group('a remembered add', () {
    test('lives ten minutes, is handed over once, and the latest add wins', () {
      final container = ProviderContainer();
      addTearDown(container.dispose);
      final pending = container.read(pendingCartAddProvider.notifier);
      expect(container.read(pendingCartAddProvider), isNull);
      pending.remember([CartAddition(variantId: 'v1', product: _product(), quantity: 2)]);
      pending.remember([CartAddition(variantId: 'v2', product: _product())]);
      final taken = pending.take()!;
      expect(taken.additions.single.variantId, 'v2');
      expect(pending.take(), isNull, reason: 'once');
      pending.remember(const []);
      expect(container.read(pendingCartAddProvider), isNull);

      final old = PendingCartAdd([
        CartAddition(variantId: 'v1', product: _product()),
      ], at: DateTime.now().subtract(const Duration(minutes: 11)));
      expect(old.isFresh(), isFalse);
      expect(PendingCartAdd(const [], at: DateTime.now().subtract(const Duration(minutes: 9))).isFresh(), isTrue);
    });
  });

  group('the customer taps Add to cart before signing in', () {
    testWidgets('they come back to the same product page and the add is done with the usual confirmation', (t) async {
      final cart = FakeCartServer();
      final h = await boot(t, _shop(cart), signedIn: false);
      await _openProductAsGuest(t, h);
      expect(_location(h), '/products/p1');

      await _tapAdd(t);
      expect(_from(h), '/products/p1', reason: 'sign-in returns to the product, not to /home');
      expect(cart.adds, isEmpty, reason: 'nothing is added while they are signed out');
      expect(h.read(pendingCartAddProvider), isNotNull);

      await _signInThroughTheScreens(t);
      for (var i = 0; i < 12; i++) {
        await t.pump(const Duration(milliseconds: 150));
      }
      await settle(t, 8);

      expect(h.read(isAuthenticatedProvider), isTrue);
      expect(
        _location(h),
        '/products/p1',
        reason: 'the sign-in screens are gone: back on the product page they came from',
      );
      expect(cart.adds, hasLength(1), reason: 'the add they asked for was done');
      expect(cart.adds.single.variantId, 'v1');
      expect(find.byType(BottomSheet), findsOneWidget, reason: 'the cart window opened over the product');
      expect(find.text(en.addedToCart), findsOneWidget, reason: 'the usual "Added to cart" confirmation');
      expect(h.read(cartCountProvider), 1);
      expect(h.read(pendingCartAddProvider), isNull);

      // Back closes the window, then the product page, and the home page is still underneath: nothing was thrown away.
      await t.binding.handlePopRoute();
      await settle(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(_location(h), '/products/p1');
      await teardown(t);
    });

    testWidgets('the add is done once: reading the cart again, or the next sign-in, adds nothing more', (t) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle), signedIn: false);
      await _openProductAsGuest(t, h);
      await _tapAdd(t);
      await _signIn(t, h);
      for (var i = 0; i < 20; i++) {
        await t.pump(const Duration(milliseconds: 150));
      }
      expect(cart.adds, hasLength(1));
      expect(h.read(pendingCartAddProvider), isNull);

      // The cart is built again (what the next sign-in does): nothing is waiting any more.
      h.container.invalidate(cartControllerProvider);
      for (var i = 0; i < 20; i++) {
        await t.pump(const Duration(milliseconds: 150));
      }
      expect(cart.adds, hasLength(1), reason: 'the add was done once');
      await teardown(t);
    });

    testWidgets('leaving the sign-in screen drops the add, so a sign-in later never puts it in the cart', (t) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle), signedIn: false);
      await _openProductAsGuest(t, h);
      await _tapAdd(t);
      expect(h.read(pendingCartAddProvider), isNotNull);

      await t.binding.handlePopRoute(); // Back from the sign-in screen
      await settle(t);
      expect(_location(h), '/products/p1');
      expect(h.read(pendingCartAddProvider), isNull, reason: 'they backed out: the add is forgotten');

      await _signIn(t, h);
      for (var i = 0; i < 20; i++) {
        await t.pump(const Duration(milliseconds: 150));
      }
      expect(cart.adds, isEmpty, reason: 'no surprise item in the cart');
      await teardown(t);
    });

    testWidgets('Browse as guest returns to the product and forgets the add', (t) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle), signedIn: false);
      await _openProductAsGuest(t, h);
      await _tapAdd(t);

      await t.tap(find.text(en.continueAsGuest));
      await settle(t);
      expect(_location(h), '/products/p1', reason: 'the product page, not the home page');
      expect(h.read(pendingCartAddProvider), isNull);
      await teardown(t);
    });

    testWidgets('signed in already: the add is done at once and nothing is remembered', (t) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle));
      await _openProductAsGuest(t, h);
      await _tapAdd(t);
      expect(cart.adds, hasLength(1));
      expect(h.read(pendingCartAddProvider), isNull);
      await teardown(t);
    });
  });
}
