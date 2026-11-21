@Timeout(Duration(minutes: 5))
library;

// A signed-out customer has a cart too (guest cart in the app): it is kept on the phone, it survives a restart, it is read again from the shop's
// public product pages, and the moment they sign in it is folded into their account without duplicating a line or losing a limit, with one plain
// message about anything that is not as they left it. Checkout is where sign-in is asked, and they land back on checkout.
import 'dart:async';
import 'dart:convert';

import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/features/auth/domain/auth_models.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/cart/domain/guest_cart.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/cart/presentation/state/guest_cart_notice.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show FakeCartServer, backend, boot, en, settle, teardown;
import '../../qa/test_fonts.dart';

Map<String, dynamic> _price(String v) => {'regular': money(v), 'final': money(v), 'sale': null};

/// The shop's product page for p1 (a sofa with assembly): price, stock and the assembly fee are what a test changes.
Map<String, dynamic> _sofa({String price = '100.00', int available = 9, String? fee = '75.00', bool inStock = true}) =>
    {
      ...productDetail('p1'),
      'assembly_fee': fee,
      'variants': [
        {
          'id': 'v1',
          'name': 'Oak',
          'attribute_value_ids': <String>[],
          'price': _price(price),
          'available_in_branch': true,
          'in_stock': inStock,
          'available_quantity': available,
        },
      ],
    };

ProductSummary _sofaCard() => ProductSummary.fromJson({...productCard('p1'), 'assembly_fee': '75.00'});

/// The account's cart as the server answers it: [lines] is variant id -> quantity (each a 100.00 line), with assembly ticked on [assembled].
Map<String, dynamic> _accountCart(
  Map<String, int> lines, {
  Set<String> assembled = const {},
  Map<String, String> issues = const {},
}) {
  final items = [
    for (final e in lines.entries)
      {
        'id': 'i-${e.key}',
        'variant_id': e.key,
        'product': {...productCard('p1'), 'variant_id': e.key, 'assembly_fee': '75.00'},
        'quantity': e.value,
        'unit_price': _price('100.00'),
        'line_total': money('${e.value * 100}.00'),
        'issue': issues[e.key],
        'available': !issues.containsKey(e.key),
        'assembly': {
          'selected': assembled.contains(e.key),
          'fee_each': money('75.00'),
          'amount': assembled.contains(e.key) ? money('${e.value * 75}.00') : null,
        },
      },
  ];
  final count = lines.values.fold<int>(0, (a, b) => a + b);
  return {
    'id': 'c1',
    'branch_id': 'br1',
    'items': items,
    'items_count': count,
    'subtotal': money('${count * 100}.00'),
    'warnings': <String>[],
  };
}

/// A shop that answers the cart, the product page and the sign-in, recording what the app sent.
class _Shop {
  _Shop({Map<String, int>? account, this.sofa, this.refuse = const {}}) : account = {...?account};

  final Map<String, int> account;
  final Set<String> assembled = {};
  Map<String, dynamic>? sofa;
  final Set<String> refuse;
  final adds = <({String variant, int quantity, bool? assembly})>[];
  final patches = <Map<String, dynamic>>[];

  /// The most units of a line the shop would keep (a stock cap or a limit of its own), by variant.
  final caps = <String, int>{};

  FakeHandler get handler => backend(
    extra: (r) {
      if (r.method == 'GET' && r.path == '/products/p1') return ok(sofa ?? _sofa());
      if (r.method == 'GET' && r.path == '/cart') return ok(_accountCart(account, assembled: assembled));
      if (r.method == 'GET' && r.path == '/cart/suggestions') return ok(const []);
      if (r.method == 'POST' && r.path == '/cart/items') {
        final variant = '${r.body['variant_id']}';
        final qty = (r.body['quantity'] as num).toInt();
        adds.add((variant: variant, quantity: qty, assembly: r.body['assembly'] as bool?));
        if (refuse.contains(variant)) {
          return error(422, 'item_unavailable_simple', message: 'This item is not available right now.');
        }
        account[variant] = ((account[variant] ?? 0) + qty).clamp(1, caps[variant] ?? 99);
        if (r.body['assembly'] == true) assembled.add(variant);
        return ok(_accountCart(account, assembled: assembled), status: 201);
      }
      if (r.method == 'PATCH' && r.path.startsWith('/cart/items/')) {
        patches.add(Map<String, dynamic>.from(r.body as Map));
        if (r.body['assembly'] == true) assembled.add(r.path.substring('/cart/items/i-'.length));
        return ok(_accountCart(account, assembled: assembled));
      }
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
      return null;
    },
  );
}

Authenticated _noor() => Authenticated(
  user: const AppUser(id: 'u1', name: 'Noor', phone: '+97455123456', locale: 'en'),
  tokens: signedInTokens,
);

Future<TestHarness> _harness(_Shop shop, {Map<String, Object> prefs = const {}, bool signedIn = false}) =>
    TestHarness.create(
      handler: shop.handler,
      tokens: signedIn ? signedInTokens : null,
      prefs: {'locale': 'en', 'onboarding_done': true, ...prefs},
    );

String _saved(TestHarness h) =>
    h.read(appPreferencesProvider).guestCart.isEmpty ? '' : jsonEncode(h.read(appPreferencesProvider).guestCart);

Future<void> _settle(TestHarness h) async {
  for (var i = 0; i < 6; i++) {
    await Future<void>.delayed(const Duration(milliseconds: 5));
  }
}

String _location(TestHarness h) => currentAppLocation(h.read(routerProvider));

void main() {
  setUpAll(loadAppFonts);

  group('the cart of a signed-out customer', () {
    test('they can add, change, tick assembly and remove, and the cart is kept on the phone', () async {
      final h = await _harness(_Shop());
      await h.read(cartControllerProvider.future);
      final cart = h.read(cartControllerProvider.notifier);

      await cart.addItem('v1', product: _sofaCard(), quantity: 2, variantName: 'Oak');
      expect(h.read(cartCountProvider), 2);
      var c = h.read(cartControllerProvider).value!;
      expect(c.items.single.id, 'guest-v1');
      expect(c.items.single.assembly!.selected, isFalse, reason: 'offered, not ticked');
      expect(c.assemblyTotal, isNull);

      await cart.setAssembly('guest-v1', true);
      c = h.read(cartControllerProvider).value!;
      expect(c.assemblyTotal!.amount, '150.00');
      expect(c.subtotal.amount, '200.00', reason: 'the card showed 100.00 for one');

      await cart.updateQuantity('guest-v1', 3);
      expect(h.read(cartControllerProvider).value!.assemblyTotal!.amount, '225.00');
      expect(h.read(cartControllerProvider).value!.subtotal.amount, '300.00');
      expect(
        h.adapter.requests.where((r) => r.path.startsWith('/cart')),
        isEmpty,
        reason: 'a guest cart never calls the server cart',
      );

      await cart.removeItem('guest-v1');
      expect(h.read(cartCountProvider), 0);
      expect(h.read(appPreferencesProvider).guestCart, isEmpty);
    });

    test('it survives closing the app: a new start reads the same cart', () async {
      final shop = _Shop();
      final first = await _harness(shop);
      await first.read(cartControllerProvider.future);
      await first
          .read(cartControllerProvider.notifier)
          .addItem('v1', product: _sofaCard(), quantity: 2, assembly: true);
      final saved = _saved(first);
      expect(saved, isNotEmpty);
      first.container.dispose();

      final second = await _harness(shop, prefs: {'guest_cart': saved});
      final cart = await second.read(cartControllerProvider.future);
      expect(cart.itemsCount, 2);
      expect(cart.items.single.assembly!.selected, isTrue);
      expect(cart.items.single.product.name, isNotEmpty);
    });

    test('a damaged copy on the phone never breaks the cart', () async {
      final h = await _harness(_Shop(), prefs: {'guest_cart': '{not json'});
      expect((await h.read(cartControllerProvider.future)).isEmpty, isTrue);
    });

    test('the shop\'s prices and stock are read again, and a change is said once', () async {
      final shop = _Shop(sofa: _sofa(price: '90.00', available: 2));
      final h = await _harness(shop);
      await h.read(cartControllerProvider.future);
      await h
          .read(cartControllerProvider.notifier)
          .addItem('v1', product: _sofaCard(), quantity: 5, unitPrice: EffectivePrice.fromJson(_price('100.00')));

      final cart = await h.read(cartControllerProvider.notifier).revalidate();

      expect(h.adapter.where('GET', '/products/p1'), isNotEmpty, reason: 'read from the public product page');
      final line = cart.items.single;
      expect(line.quantity, 2, reason: 'only 2 are on the shelf');
      expect(line.unitPrice!.finalPrice.amount, '90.00');
      expect(line.priceChanged, isTrue);
      expect(line.previousPrice!.amount, '100.00');
      expect(cart.subtotal.amount, '180.00');
      final said = h.read(guestCartNoticeProvider).map((c) => c.kind).toSet();
      expect(said, {GuestChangeKind.priceChanged, GuestChangeKind.reduced});

      h.read(guestCartNoticeProvider.notifier).clear();
      await h.read(cartControllerProvider.notifier).revalidate();
      expect(h.read(guestCartNoticeProvider), isEmpty, reason: 'told once');
    });

    test('a shop that cannot be reached leaves the cart as it was', () async {
      final shop = _Shop();
      final h = await _harness(shop);
      await h.read(cartControllerProvider.future);
      await h.read(cartControllerProvider.notifier).addItem('v1', product: _sofaCard(), quantity: 2);
      h.adapter.failConnection = (r) => r.path.startsWith('/products');

      final cart = await h.read(cartControllerProvider.notifier).revalidate();

      expect(cart.itemsCount, 2);
      expect(h.read(guestCartNoticeProvider), isEmpty);
    });
  });

  group('signing in folds the guest cart into the account', () {
    test('the guest\'s lines are added once, assembly goes with them, and the phone\'s copy is cleared', () async {
      final shop = _Shop();
      final h = await _harness(shop);
      await h.read(cartControllerProvider.future);
      await h.read(cartControllerProvider.notifier).addItem('v1', product: _sofaCard(), quantity: 3, assembly: true);

      await h.read(sessionControllerProvider.notifier).signIn(_noor());
      final cart = await h.read(cartControllerProvider.future);

      expect(shop.adds, [(variant: 'v1', quantity: 3, assembly: true)]);
      expect(cart.items.single.quantity, 3);
      expect(cart.items.single.assembly!.selected, isTrue);
      expect(h.read(appPreferencesProvider).guestCart, isEmpty);
      expect(h.read(guestCartNoticeProvider), isEmpty, reason: 'everything arrived as left');

      // Building the cart again (the next start, a refresh) adds nothing more.
      h.container.invalidate(cartControllerProvider);
      await h.read(cartControllerProvider.future);
      expect(shop.adds, hasLength(1));
    });

    test('a line the account already has is topped up to the larger quantity, never doubled', () async {
      final shop = _Shop(account: {'v1': 2});
      final h = await _harness(shop);
      await h.read(cartControllerProvider.future);
      await h.read(cartControllerProvider.notifier).addItem('v1', product: _sofaCard(), quantity: 3);

      await h.read(sessionControllerProvider.notifier).signIn(_noor());
      final cart = await h.read(cartControllerProvider.future);

      expect(shop.adds.single.quantity, 1, reason: 'the guest had 3 and the account 2: one more, not three more');
      expect(cart.items.single.quantity, 3);
      expect(cart.items, hasLength(1), reason: 'one line, not two');
    });

    test('the account already holds enough: nothing is added, and only a missing assembly tick is sent', () async {
      final shop = _Shop(account: {'v1': 4});
      final h = await _harness(shop);
      await h.read(cartControllerProvider.future);
      await h.read(cartControllerProvider.notifier).addItem('v1', product: _sofaCard(), quantity: 2, assembly: true);

      await h.read(sessionControllerProvider.notifier).signIn(_noor());
      final cart = await h.read(cartControllerProvider.future);

      expect(shop.adds, isEmpty);
      expect(shop.patches, [
        {'assembly': true},
      ]);
      expect(cart.items.single.quantity, 4);
      expect(cart.items.single.assembly!.selected, isTrue);
    });

    test('a stock cap or a limit the server applies is told plainly, and the other lines still arrive', () async {
      final shop = _Shop(refuse: {'v2'})..caps['v1'] = 2;
      final h = await _harness(shop);
      await h.read(cartControllerProvider.future);
      final cart = h.read(cartControllerProvider.notifier);
      await cart.addItem('v1', product: _sofaCard(), quantity: 5);
      await cart.addItem(
        'v2',
        product: ProductSummary.fromJson({...productCard('p1'), 'variant_id': 'v2', 'name': 'Oak stool'}),
        quantity: 1,
      );
      await cart.addItem(
        'v3',
        product: ProductSummary.fromJson({...productCard('p1'), 'variant_id': 'v3', 'name': 'Oak shelf'}),
        quantity: 1,
      );

      await h.read(sessionControllerProvider.notifier).signIn(_noor());
      final merged = await h.read(cartControllerProvider.future);

      expect(merged.items.map((i) => i.variantId).toSet(), {
        'v1',
        'v3',
      }, reason: 'the refused line is not there, the others are');
      expect(merged.items.firstWhere((i) => i.variantId == 'v1').quantity, 2);
      final changes = h.read(guestCartNoticeProvider);
      expect(changes.map((c) => c.kind), containsAll([GuestChangeKind.reduced, GuestChangeKind.unavailable]));
      expect(changes.firstWhere((c) => c.kind == GuestChangeKind.reduced).available, 2);
      expect(changes.firstWhere((c) => c.kind == GuestChangeKind.unavailable).name, 'Oak stool');
      expect(h.read(appPreferencesProvider).guestCart, isEmpty, reason: 'every line was tried: the phone\'s copy goes');
    });

    test('a customer who signs in with an empty guest cart simply gets their own cart', () async {
      final shop = _Shop(account: {'v1': 1});
      final h = await _harness(shop);
      await h.read(cartControllerProvider.future);
      await h.read(sessionControllerProvider.notifier).signIn(_noor());
      final cart = await h.read(cartControllerProvider.future);
      expect(shop.adds, isEmpty);
      expect(cart.itemsCount, 1);
    });

    test('adding from the account side still goes to the server, and assembly is sent only when ticked', () async {
      final shop = _Shop();
      final h = await _harness(shop, signedIn: true);
      await h.read(cartControllerProvider.future);
      await h.read(cartControllerProvider.notifier).addItem('v1', product: _sofaCard(), quantity: 1);
      await h.read(cartControllerProvider.notifier).addItem('v1', product: _sofaCard(), quantity: 1, assembly: true);
      expect(shop.adds.map((a) => a.assembly), [null, true]);
      await h.read(cartControllerProvider.notifier).setAssembly('i-v1', true);
      expect(shop.patches.last, {'assembly': true});
      await _settle(h);
    });

    test('a refusal on a signed-in add still rolls the cart back', () async {
      final shop = _Shop(refuse: {'v1'});
      final h = await _harness(shop, signedIn: true);
      await h.read(cartControllerProvider.future);
      await expectLater(
        h.read(cartControllerProvider.notifier).addItem('v1', product: _sofaCard()),
        throwsA(isA<ApiException>()),
      );
      expect(h.read(cartCountProvider), 0);
    });
  });

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
      expect(currentAppLocation(router), '/products/7?hero=x');
    });
  });

  group('in the real app, signed out', () {
    testWidgets(
      'Add to cart needs no sign-in: the cart window opens over the product, nothing goes to the server, and the cart is kept',
      (t) async {
        final shop = _Shop();
        final h = await boot(t, shop.handler, signedIn: false);
        unawaited(h.read(routerProvider).push('/products/p1'));
        await settle(t);
        expect(_location(h), '/products/p1');

        final add = find.text(en.addToCart).first;
        await t.ensureVisible(add);
        await t.pump();
        await t.tap(add);
        await settle(t);

        expect(_location(h), '/products/p1', reason: 'no sign-in screen: they stay on the product');
        expect(find.byType(BottomSheet), findsOneWidget, reason: 'the cart window opened over the page');
        expect(find.text(en.addedToCart), findsOneWidget);
        expect(h.read(cartCountProvider), 1);
        expect(shop.adds, isEmpty, reason: 'a guest cart is on the phone, not on the server');
        expect(h.read(appPreferencesProvider).guestCart, hasLength(1));
        await teardown(t);
      },
    );

    testWidgets(
      'Checkout is where sign-in is asked: they sign in, land on checkout, and their cart is in their account',
      (t) async {
        final shop = _Shop();
        final h = await boot(t, shop.handler, signedIn: false);
        unawaited(h.read(routerProvider).push('/products/p1'));
        await settle(t);
        final add = find.text(en.addToCart).first;
        await t.ensureVisible(add);
        await t.pump();
        await t.tap(add);
        await settle(t);

        await t.tap(find.text(en.checkoutTitle).last);
        await settle(t, 8);
        final here = Uri.parse(_location(h));
        expect(here.path, '/login', reason: 'sign-in is asked now');
        expect(here.queryParameters['from'], '/checkout', reason: 'and it remembers where they were going');

        await t.enterText(find.byType(AppTextField).first, '55123456');
        await t.tap(find.text(en.sendCode));
        await settle(t);
        await t.enterText(find.byType(AppTextField).first, '123456');
        for (var i = 0; i < 14; i++) {
          await t.pump(const Duration(milliseconds: 150));
        }
        await settle(t, 8);

        expect(h.read(isAuthenticatedProvider), isTrue);
        expect(_location(h), '/checkout', reason: 'straight to checkout, not back to the product');
        expect(shop.adds.map((a) => (a.variant, a.quantity)), [
          ('v1', 1),
        ], reason: 'the guest cart reached the account, once');
        expect(h.read(appPreferencesProvider).guestCart, isEmpty);
        await teardown(t);
      },
    );

    testWidgets(
      'the cart tab shows a signed-out customer their cart, with a note that sign-in comes at checkout and no coupon box',
      (t) async {
        final shop = _Shop();
        final h = await boot(t, shop.handler, signedIn: false);
        await h.read(cartControllerProvider.future);
        await h
            .read(cartControllerProvider.notifier)
            .addItem(
              'v1',
              product: _sofaCard(),
              quantity: 2,
              assembly: true,
              unitPrice: EffectivePrice.fromJson(_price('100.00')),
            );
        h.read(routerProvider).go('/cart');
        await settle(t);

        expect(find.text('Product p1'), findsWidgets);
        expect(find.text(en.guestCheckoutNote), findsOneWidget);
        expect(find.text(en.assemblyRow), findsOneWidget, reason: 'the assembly row in the summary');
        expect(find.byKey(const ValueKey('assembly-choice')), findsOneWidget);
        expect(find.textContaining(en.couponCode), findsNothing, reason: 'a code is applied once they are signed in');
        expect(find.text(en.signInRequiredTitle), findsNothing, reason: 'no "sign in to see your cart" wall any more');
        await teardown(t);
      },
    );
  });

  group('a leftover from before', () {
    test('the cart controller of a guest never asks the server for a cart', () async {
      final shop = _Shop();
      final h = await _harness(shop);
      await h.read(cartControllerProvider.future);
      expect(h.adapter.requests.where((r) => r.path == '/cart'), isEmpty);
      expect(FakeCartServer().lines, isEmpty);
    });
  });
}
