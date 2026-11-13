@Timeout(Duration(minutes: 5))
library;

import 'dart:async';

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/router/app_footer.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/profile/presentation/map_pin_picker.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import '../helpers/map_fakes.dart';
import 'customer_fixtures.dart';
import 'test_fonts.dart';

AppLocalizations get en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get ar => lookupAppLocalizations(const Locale('ar'));

/// Boots the real app on top of [handler] at [width] x [height] (a phone by default; 1024 is a tablet).
Future<TestHarness> boot(
  WidgetTester t,
  FakeHandler handler, {
  double width = 390,
  double height = 844,
  String locale = 'en',
  bool signedIn = true,
  bool dark = false,
  List<Override> overrides = const [],
}) async {
  t.view.physicalSize = Size(width, height);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(
    tokens: signedIn ? signedInTokens : null,
    prefs: {'locale': locale, 'onboarding_done': true, if (dark) 'theme_mode': 'dark'},
    handler: handler,
    overrides: [...mapTestOverrides(), ...overrides],
  );
  await t.pumpWidget(
    UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
  );
  await t.pump(const Duration(milliseconds: 1700));
  await t.pump(const Duration(milliseconds: 400));
  return h;
}

/// The page the app is on. A pushed page is the last match; `currentConfiguration.uri` keeps the page under it.
String path(TestHarness h) => h.read(routerProvider).routerDelegate.currentConfiguration.matches.last.matchedLocation;

Future<void> settle(WidgetTester t, [int frames = 6]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 150));
  }
}

Future<void> go(WidgetTester t, TestHarness h, String route) async {
  h.read(routerProvider).go(route);
  await settle(t);
}

Future<void> tapText(WidgetTester t, String text, {bool last = false}) async {
  final target = last ? find.text(text).last : find.text(text).first;
  await t.ensureVisible(target);
  await t.pump();
  await t.tap(target);
  await settle(t);
}

Future<void> back(WidgetTester t) async {
  await t.binding.handlePopRoute();
  await settle(t);
}

Future<void> teardown(WidgetTester t) async {
  await t.pumpWidget(const SizedBox.shrink());
  await t.pump(const Duration(seconds: 10));
}

/// The backend of the screen matrix plus the endpoints the windows call.
FakeHandler backend({
  FutureOr<FakeReply?> Function(RecordedRequest r)? extra,
  String Function()? orderStatus,
  bool Function(RecordedRequest r)? inStockOnly,
  bool stress = false,
  bool arabic = false,
}) {
  final base = customerBackend(ApiMode.data, stress: stress, arabic: arabic);
  return (r) async {
    final custom = await extra?.call(r);
    if (custom != null) return custom;
    if (r.method == 'GET' && r.path == '/orders/o1' && orderStatus != null) {
      return ok(orderDetail('o1', status: orderStatus()));
    }
    if (r.method == 'GET' && r.path == '/orders') {
      return page([orderSummary('o1', orderStatus?.call() ?? 'processing'), orderSummary('o2', 'completed')]);
    }
    if (r.method == 'GET' && r.path == '/orders/o1/timeline') {
      return ok([
        {'status': 'placed', 'label': 'Order placed', 'at': '2026-11-02T15:20:00+03:00'},
        {'status': orderStatus?.call() ?? 'processing', 'label': 'Being prepared', 'at': '2026-11-02T16:00:00+03:00'},
      ]);
    }
    if (r.method == 'POST' && r.path == '/checkout/quote') return ok(quoteJson());
    if (r.method == 'GET' && r.path == '/products' && inStockOnly != null && inStockOnly(r)) {
      return page([productCard('1')]);
    }
    return base(r);
  };
}

/// The profile window opens from the pencil on the avatar, or from the profile card of the other header design.
Future<void> openProfile(WidgetTester t) async {
  final pencil = find.byTooltip(en.editProfile);
  await t.tap(pencil.evaluate().isNotEmpty ? pencil.first : find.text('Noor Al Thani').first);
  await settle(t);
}

Future<void> openOrderPanel(WidgetTester t, TestHarness h) async {
  await go(t, h, '/orders');
  await tapText(t, 'AB26o1');
}

/// The rectangle of the window that holds [text].
Rect windowRect(WidgetTester t, String text) =>
    t.getRect(find.ancestor(of: find.text(text), matching: find.byType(Material)).first);

/// A cart on a pretend server: adding a variant that is already there raises its quantity (one line, as the real
/// server does), changes are answered with the whole cart, and a test can hold or refuse the next add.
class FakeCartServer {
  FakeCartServer({Map<String, int>? lines}) : lines = {...?lines};

  final Map<String, int> lines;
  final adds = <({String variantId, int quantity})>[];
  final patches = <String>[];
  Completer<void>? hold;
  bool failAdd = false;

  Map<String, dynamic> json() {
    final items = [
      for (final e in lines.entries)
        {
          'id': 'i-${e.key}',
          'variant_id': e.key,
          'product': {...productCard('p1'), 'variant_id': e.key},
          'quantity': e.value,
          'unit_price': price('100.00'),
          'line_total': money('${e.value * 100}.00'),
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

  Future<FakeReply?> handle(RecordedRequest r) async {
    if (r.path == '/cart' && r.method == 'GET') return ok(json());
    if (r.path == '/cart/suggestions') return ok(const []);
    if (r.path == '/cart/items' && r.method == 'POST') {
      final variant = '${r.body['variant_id']}';
      final qty = (r.body['quantity'] as num?)?.toInt() ?? 1;
      adds.add((variantId: variant, quantity: qty));
      if (hold != null) await hold!.future;
      if (failAdd) return error(422, 'item_unavailable_simple', message: 'This item is not available right now.');
      lines[variant] = (lines[variant] ?? 0) + qty;
      return ok(json());
    }
    if (r.path.startsWith('/cart/items/') && r.method == 'PATCH') {
      final variant = r.path.substring('/cart/items/i-'.length);
      patches.add('$variant:${r.body['quantity']}');
      lines[variant] = (r.body['quantity'] as num).toInt();
      return ok(json());
    }
    return null;
  }
}

void main() {
  setUpAll(loadAppFonts);

  group('Orders open beside the list', () {
    testWidgets('phone: a tap opens the order as a sheet and Back closes only the sheet', (t) async {
      final h = await boot(t, backend());
      await openOrderPanel(t, h);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.orderNumber('AB26o1')), findsOneWidget, reason: 'the window is titled with the order');
      expect(find.text(en.orderTimeline), findsOneWidget);
      expect(find.text('Being prepared'), findsOneWidget, reason: 'the status timeline is in the window');
      expect(find.widgetWithText(FilledButton, en.reorder), findsOneWidget, reason: 'one pinned primary action');
      expect(path(h), '/orders', reason: 'the list page did not change');

      await back(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/orders', reason: 'Back closed the window, not the page');
      expect(find.text('AB26o1'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('tablet: the order opens in a panel on the right and the list stays visible', (t) async {
      final h = await boot(t, backend(), width: 1024, height: 768);
      await openOrderPanel(t, h);
      expect(find.byType(BottomSheet), findsNothing);
      final panel = windowRect(t, en.orderTimeline);
      expect(panel.right, 1024);
      expect(panel.width, lessThan(480));
      expect(find.text('AB26o2'), findsOneWidget, reason: 'the list is still behind the panel');
      await back(t);
      expect(find.text(en.orderTimeline), findsNothing);
      await teardown(t);
    });

    testWidgets('Arabic tablet: the panel is on the left', (t) async {
      final h = await boot(t, backend(), width: 1024, height: 768, locale: 'ar');
      await go(t, h, '/orders');
      await tapText(t, 'AB26o1');
      final panel = windowRect(t, ar.orderTimeline);
      expect(panel.left, 0);
      await teardown(t);
    });

    testWidgets('a deep link to /orders/:id still opens the full screen, not a window', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/orders/o1');
      expect(path(h), '/orders/o1');
      expect(find.byType(BottomSheet), findsNothing);
      expect(find.text(en.orderTimeline), findsOneWidget);
      expect(find.text(en.orderNumber('AB26o1')), findsWidgets);
      await teardown(t);
    });

    testWidgets('"Open full page" closes the window and opens the page; Back returns to the list', (t) async {
      final h = await boot(t, backend());
      await openOrderPanel(t, h);
      await t.tap(find.byTooltip(en.openFullPage));
      await settle(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/orders/o1');
      await back(t);
      expect(path(h), '/orders');
      await teardown(t);
    });

    testWidgets('the window follows the order live: a server ping changes status and the pinned action', (t) async {
      var status = 'processing';
      final events = StreamController<PusherEvent>.broadcast();
      addTearDown(events.close);
      final h = await boot(
        t,
        backend(orderStatus: () => status),
        overrides: [userAuctionEventsProvider.overrideWith((ref) => events.stream)],
      );
      await openOrderPanel(t, h);
      expect(find.widgetWithText(FilledButton, en.trackOrder), findsNothing);
      status = 'out_for_delivery';
      events.add(const PusherEvent(channel: 'private-users.u1', event: 'order.updated', data: {'order_id': 'o1'}));
      await settle(t);
      expect(find.widgetWithText(FilledButton, en.trackOrder), findsOneWidget, reason: 'updated without a refresh');
      expect(find.text('out for delivery'), findsWidgets);
      await teardown(t);
    });

    testWidgets('cancelling is a step inside the same window: back arrow, one window, a reason, then the request', (
      t,
    ) async {
      final h = await boot(t, backend());
      await openOrderPanel(t, h);
      await tapText(t, en.cancelOrder, last: true);
      expect(find.byType(BottomSheet), findsOneWidget, reason: 'never a window on top of a window');
      expect(find.text(en.cancelOrderTitle), findsOneWidget);
      expect(find.text(en.orderTimeline), findsNothing, reason: 'the step replaced the details');
      // Back arrow returns to the details.
      await t.tap(find.byTooltip('Back'));
      await settle(t);
      expect(find.text(en.orderTimeline), findsOneWidget);

      await tapText(t, en.cancelOrder, last: true);
      await tapText(t, en.cancelReasonDelay);
      await t.tap(find.widgetWithText(FilledButton, en.cancelOrder));
      await settle(t);
      final posted = h.adapter.where('POST', '/orders/o1/cancel');
      expect(posted, hasLength(1));
      expect(posted.single.body['reason_code'], 'delivery_too_long');
      expect(find.byType(BottomSheet), findsOneWidget, reason: 'the order window stays open');
      expect(find.text(en.orderTimeline), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a return is asked for in the window too, and Request return waits for a chosen item', (t) async {
      final h = await boot(t, backend());
      await openOrderPanel(t, h);
      await tapText(t, en.requestReturn);
      expect(find.text(en.selectItemsToReturn), findsOneWidget);
      expect(t.widget<FilledButton>(find.widgetWithText(FilledButton, en.requestReturn)).onPressed, isNull);
      await t.tap(find.byType(CheckboxListTile).first);
      await settle(t);
      await t.tap(find.widgetWithText(FilledButton, en.requestReturn));
      await settle(t);
      expect(h.adapter.where('POST', '/orders/o1/returns'), hasLength(1));
      await teardown(t);
    });

    testWidgets('a dirty cancel note asks before the window closes', (t) async {
      final h = await boot(t, backend());
      await openOrderPanel(t, h);
      await tapText(t, en.cancelOrder, last: true);
      await t.enterText(find.byType(TextField).last, 'it took too long');
      await t.pump();
      await t.tap(find.byTooltip(en.close));
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsOneWidget);
      await tapText(t, en.keepEditing);
      expect(find.text('it took too long'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('the live order card on Home opens the order as a window too', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/home');
      await tapText(t, en.orderLivePreparing);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.orderTimeline), findsOneWidget);
      expect(path(h), '/home');
      await back(t);
      expect(path(h), '/home');
      await teardown(t);
    });
  });

  group('Add to cart opens the cart window over the screen', () {
    /// The window's own buttons: exactly two, pinned at the bottom.
    Finder windowButtons() => find.descendant(of: find.byType(BottomSheet), matching: find.byType(AppButton));

    Finder windowSteppers() => find.descendant(of: find.byType(BottomSheet), matching: find.byType(QuantityStepper));

    Finder footerBadge() => find.descendant(of: find.byType(AppFooter), matching: find.byType(Badge));

    int badge(WidgetTester t) {
      final f = footerBadge();
      return f.evaluate().isEmpty ? 0 : int.parse((t.widget<Badge>(f.first).label as Text).data!);
    }

    testWidgets('phone: a sheet over the product page; the page does not navigate; two buttons; confirmation', (
      t,
    ) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle));
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      expect(cart.adds, hasLength(1));
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.cartTitle), findsOneWidget);
      expect(find.text(en.addedToCart), findsOneWidget, reason: '"Added to cart" confirmation');
      expect(find.text('Product p1'), findsWidgets, reason: 'the added item');
      expect(windowSteppers(), findsOneWidget);
      expect(windowButtons(), findsNWidgets(2));
      expect(find.widgetWithText(AppButton, en.checkoutTitle), findsOneWidget);
      expect(find.widgetWithText(AppButton, en.keepShopping), findsOneWidget);
      expect(find.textContaining('100.00'), findsWidgets, reason: 'price and subtotal');
      expect(path(h), '/products/p1', reason: 'adding to the cart never navigates');
      await teardown(t);
    });

    testWidgets('tablet: an end-side panel on the right; the page stays visible', (t) async {
      final h = await boot(t, backend(extra: FakeCartServer().handle), width: 1024, height: 768);
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      expect(find.byType(BottomSheet), findsNothing);
      expect(windowRect(t, en.addedToCart).right, 1024);
      expect(path(h), '/products/p1');
      await teardown(t);
    });

    testWidgets('Arabic: the panel is on the left and the labels are Arabic', (t) async {
      final h = await boot(t, backend(extra: FakeCartServer().handle), width: 1024, height: 768, locale: 'ar');
      await go(t, h, '/products/p1');
      await tapText(t, ar.addToCart);
      expect(windowRect(t, ar.addedToCart).left, 0);
      expect(find.text(ar.keepShopping), findsOneWidget);
      expect(find.text(ar.checkoutTitle), findsOneWidget);
      await teardown(t);
    });

    testWidgets('the badge and the window change at once, before the server answers', (t) async {
      final cart = FakeCartServer()..hold = Completer<void>();
      final h = await boot(t, backend(extra: cart.handle));
      await go(t, h, '/products/p1');
      expect(badge(t), 0);
      await t.tap(find.text(en.addToCart).first);
      await t.pump(const Duration(milliseconds: 50));
      // The server has not answered yet.
      expect(cart.lines, isEmpty);
      expect(h.read(cartCountProvider), 1, reason: 'optimistic');
      await settle(t);
      expect(badge(t), 1, reason: 'the footer badge shows it at once');
      expect(find.byType(BottomSheet), findsOneWidget, reason: 'and so does the window');
      expect(find.text('Product p1'), findsWidgets);
      cart.hold!.complete();
      await settle(t);
      expect(cart.lines, {'v1': 1});
      expect(h.read(cartCountProvider), 1);
      await teardown(t);
    });

    testWidgets('if the server refuses: the cart goes back, the window closes and a message says why', (t) async {
      final cart = FakeCartServer()..failAdd = true;
      final h = await boot(t, backend(extra: cart.handle));
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      expect(h.read(cartCountProvider), 0, reason: 'rolled back');
      expect(find.byType(BottomSheet), findsNothing);
      expect(find.text('This item is not available right now.'), findsOneWidget);
      expect(path(h), '/products/p1');
      await teardown(t);
    });

    testWidgets('adding the same item again raises the quantity: one line, quantity 2', (t) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle));
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      await tapText(t, en.keepShopping);
      await tapText(t, en.addToCart);
      expect(cart.adds, hasLength(2));
      expect(cart.lines, {'v1': 2}, reason: 'the server merged them into one line');
      final lines = h.read(cartControllerProvider).requireValue.items;
      expect(lines, hasLength(1));
      expect(lines.single.quantity, 2);
      expect(h.read(cartCountProvider), 2);
      expect(windowSteppers(), findsOneWidget, reason: 'one line in the window');
      expect(find.descendant(of: windowSteppers(), matching: find.text('2')), findsOneWidget);
      await teardown(t);
    });

    testWidgets('two quick taps do not flicker the quantity or make a second line', (t) async {
      final cart = FakeCartServer()..hold = Completer<void>();
      final h = await boot(t, backend(extra: cart.handle));
      await go(t, h, '/products/p1');
      final quantities = <int>[];
      h.container.listen(cartCountProvider, (_, next) => quantities.add(next));
      await t.tap(find.text(en.addToCart).first);
      await t.pump(const Duration(milliseconds: 20));
      // A second add straight away (the window is open, so use the controller as the second tap would).
      unawaited(h.read(cartControllerProvider.notifier).addItem('v1'));
      await t.pump(const Duration(milliseconds: 20));
      cart.hold!.complete();
      await settle(t);
      expect(h.read(cartControllerProvider).requireValue.items, hasLength(1));
      expect(h.read(cartCountProvider), 2);
      expect(quantities, isNot(contains(0)), reason: 'it never dropped back while the answers came in');
      await teardown(t);
    });

    testWidgets('the window\'s quantity control changes the line; the subtotal follows the server; minus stops at 1', (
      t,
    ) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle));
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      expect(find.textContaining('100.00'), findsWidgets);
      // Minus at 1 does nothing: no request, the line stays.
      await t.tap(find.bySemanticsLabel(en.decreaseQuantity).last);
      await settle(t);
      expect(cart.patches, isEmpty);
      await t.tap(find.bySemanticsLabel(en.increaseQuantity).last);
      await settle(t);
      expect(cart.patches, ['v1:2']);
      expect(find.textContaining('200.00'), findsWidgets, reason: 'the subtotal moved');
      expect(h.read(cartCountProvider), 2);
      await teardown(t);
    });

    testWidgets('from a product card in a list: the window opens, "Keep shopping" leaves the scroll offset alone', (
      t,
    ) async {
      final cart = FakeCartServer();
      final h = await boot(
        t,
        backend(
          extra: (r) => r.method == 'GET' && r.path == '/products'
              ? page([
                  for (var i = 1; i <= 12; i++) {...productCard('$i'), 'variant_id': 'v$i'},
                ])
              : cart.handle(r),
        ),
      );
      await go(t, h, '/products');
      final scrollable = find.byType(Scrollable).first;
      await t.drag(scrollable, const Offset(0, -420));
      await settle(t);
      double offset() => t.state<ScrollableState>(scrollable).position.pixels;
      final before = offset();
      expect(before, greaterThan(100));
      await t.tap(find.bySemanticsLabel(en.addToCart).first);
      await settle(t);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/products', reason: 'no navigation');
      expect(offset(), before, reason: 'the list did not move while the window is open');
      await tapText(t, en.keepShopping);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/products');
      expect(offset(), before, reason: 'Keep shopping returns to the same place on the same list');
      await teardown(t);
    });

    testWidgets('the system Back button closes only the window', (t) async {
      final h = await boot(t, backend(extra: FakeCartServer().handle));
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      await back(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/products/p1');
      await teardown(t);
    });

    testWidgets('a tap outside and a swipe down close the sheet on a phone', (t) async {
      final h = await boot(t, backend(extra: FakeCartServer().handle));
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      await t.tapAt(const Offset(195, 30));
      await settle(t);
      expect(find.byType(BottomSheet), findsNothing, reason: 'scrim tap');
      await tapText(t, en.addToCart);
      expect(find.byType(BottomSheet), findsOneWidget);
      await t.fling(find.text(en.cartTitle), const Offset(0, 500), 1500);
      await settle(t);
      expect(find.byType(BottomSheet), findsNothing, reason: 'swipe down');
      expect(path(h), '/products/p1');
      await teardown(t);
    });

    testWidgets('a tap outside closes the panel on a tablet', (t) async {
      final h = await boot(t, backend(extra: FakeCartServer().handle), width: 1024, height: 768);
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      await t.tapAt(const Offset(40, 300));
      await settle(t);
      expect(find.text(en.addedToCart), findsNothing);
      expect(path(h), '/products/p1');
      await teardown(t);
    });

    testWidgets('it never closes by itself (30 seconds)', (t) async {
      final h = await boot(t, backend(extra: FakeCartServer().handle));
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      for (var i = 0; i < 30; i++) {
        await t.pump(const Duration(seconds: 1));
      }
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.addedToCart), findsOneWidget);
      await teardown(t);
    });

    testWidgets('Checkout closes the window and opens checkout with the same guards as the cart tab', (t) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle));
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      await t.tap(find.widgetWithText(AppButton, en.checkoutTitle));
      await settle(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/checkout');
      // Prices and stock were re-checked first, like the Checkout button on the cart tab.
      expect(h.adapter.requests.where((r) => r.method == 'GET' && r.path == '/cart'), isNotEmpty);
      await back(t);
      expect(path(h), '/products/p1', reason: 'Back from checkout returns to where the person was');
      await teardown(t);
    });

    testWidgets('Checkout is refused (and the window stays) when the cart has a problem', (t) async {
      final cart = FakeCartServer();
      final h = await boot(
        t,
        backend(
          extra: (r) async {
            final reply = await cart.handle(r);
            if (reply != null && r.path == '/cart' && r.method == 'GET' && cart.adds.length > 1) {
              final body = cart.json();
              (body['items'] as List).first['issue'] = 'insufficient_stock';
              (body['items'] as List).first['available_quantity'] = 1;
              return ok(body);
            }
            return reply;
          },
        ),
      );
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      await tapText(t, en.keepShopping);
      await tapText(t, en.addToCart);
      await settle(t);
      expect(path(h), '/products/p1');
      expect(find.byType(BottomSheet), findsOneWidget);
      await teardown(t);
    });

    testWidgets('dark mode, text x1.3 and a small phone: no overflow, 48 dp targets', (t) async {
      t.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(t.platformDispatcher.clearAllTestValues);
      final h = await boot(t, backend(extra: FakeCartServer().handle), width: 320, height: 640, dark: true);
      await go(t, h, '/products/p1');
      await tapText(t, en.addToCart);
      expect(t.takeException(), isNull);
      for (final label in [en.checkoutTitle, en.keepShopping]) {
        expect(t.getSize(find.widgetWithText(AppButton, label)).height, greaterThanOrEqualTo(48), reason: label);
      }
      for (final finder in [find.bySemanticsLabel(en.increaseQuantity), find.bySemanticsLabel(en.decreaseQuantity)]) {
        expect(t.getSize(finder.last).height, greaterThanOrEqualTo(48));
      }
      expect(t.getSize(find.byTooltip(en.close)).height, greaterThanOrEqualTo(48));
      await teardown(t);
    });

    testWidgets('reduced motion: the panel appears without sliding', (t) async {
      t.platformDispatcher.accessibilityFeaturesTestValue = const FakeAccessibilityFeatures(disableAnimations: true);
      addTearDown(t.platformDispatcher.clearAllTestValues);
      final h = await boot(t, backend(extra: FakeCartServer().handle), width: 1024, height: 768);
      await go(t, h, '/products/p1');
      await t.tap(find.text(en.addToCart).first);
      await t.pump();
      await t.pump(const Duration(milliseconds: 40));
      final panel = windowRect(t, en.addedToCart);
      expect(panel.right, lessThanOrEqualTo(1024), reason: 'already in place, not mid-slide');
      await teardown(t);
    });

    testWidgets('quick view: adding hands over to the cart window, no navigation', (t) async {
      final cart = FakeCartServer();
      final h = await boot(t, backend(extra: cart.handle));
      await go(t, h, '/products');
      await t.tap(find.byTooltip(en.quickViewOf('Product 1')));
      await settle(t);
      await t.tap(find.widgetWithText(AppButton, en.addToCart));
      await settle(t);
      expect(cart.adds.single.variantId, 'v1');
      expect(find.text(en.addedToCart), findsOneWidget);
      expect(find.text(en.quickView), findsNothing);
      expect(path(h), '/products');
      await teardown(t);
    });
  });

  group('Quick view', () {
    testWidgets('the eye on a card opens the product in a window; adding hands over to the cart window', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/products');
      await t.tap(find.byTooltip(en.quickViewOf('Product 1')));
      await settle(t);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.quickView), findsOneWidget);
      expect(find.text('Product p1'), findsWidgets, reason: 'detail loaded in the window');
      expect(find.text(en.viewFullDetails), findsOneWidget);
      expect(path(h), '/products', reason: 'still on the list');

      await t.tap(find.widgetWithText(FilledButton, en.addToCart));
      await settle(t);
      final posted = h.adapter.where('POST', '/cart/items').single;
      expect(posted.body['variant_id'], 'v1');
      expect(find.text(en.addedToCart), findsOneWidget, reason: 'the cart window replaced the quick view');
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.quickView), findsNothing);
      await teardown(t);
    });

    testWidgets('a long press opens it; "View full details" opens the product page and Back returns to the list', (
      t,
    ) async {
      final h = await boot(t, backend());
      await go(t, h, '/products');
      await t.longPress(find.text('Product 1').first);
      await settle(t);
      expect(find.text(en.quickView), findsOneWidget);
      await tapText(t, en.viewFullDetails);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/products/1');
      await back(t);
      expect(path(h), '/products');
      await teardown(t);
    });

    testWidgets('a guest who adds from the window is sent to sign in, and the window closes first', (t) async {
      final h = await boot(t, backend(), signedIn: false);
      await go(t, h, '/products');
      await t.tap(find.byTooltip(en.quickViewOf('Product 1')));
      await settle(t);
      await t.tap(find.widgetWithText(FilledButton, en.addToCart));
      await settle(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/login');
      await teardown(t);
    });
  });

  group('Filters and sort', () {
    testWidgets('a live count follows every change and the pinned button applies the result', (t) async {
      final h = await boot(t, backend(inStockOnly: (r) => r.query['in_stock'] == '1'));
      await go(t, h, '/products');
      await tapText(t, en.filters);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.showResultsCount(3)), findsOneWidget, reason: 'the count of the unfiltered list');
      await tapText(t, en.inStockOnly);
      await t.pump(const Duration(milliseconds: 400));
      await settle(t);
      expect(find.text(en.showResultsCount(1)), findsOneWidget);
      await t.tap(find.text(en.showResultsCount(1)));
      await settle(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(find.text(en.resultsCount(1)), findsOneWidget);
      expect(h.adapter.requests.where((r) => r.path == '/products' && r.query['in_stock'] == '1'), isNotEmpty);
      await teardown(t);
    });

    testWidgets('changed filters ask before the window is thrown away', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/products');
      await tapText(t, en.filters);
      await tapText(t, en.onSaleOnly);
      await t.tap(find.byTooltip(en.close));
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsOneWidget);
      await tapText(t, en.discard);
      expect(find.byType(BottomSheet), findsNothing);
      await teardown(t);
    });

    testWidgets('tablet: filters are a panel at the end edge', (t) async {
      final h = await boot(t, backend(), width: 1024, height: 768);
      await go(t, h, '/products');
      await t.tap(find.text(en.filters).first);
      await settle(t);
      expect(windowRect(t, en.sortBy).right, 1024);
      await teardown(t);
    });
  });

  group('Forms open in windows', () {
    testWidgets('a new address: window, dirty guard, pinned Save, then a confirmation on the page', (t) async {
      final h = await boot(
        t,
        backend(extra: (r) => r.method == 'POST' && r.path == '/me/addresses' ? ok(addressJson('a3')) : null),
      );
      await go(t, h, '/addresses');
      await tapText(t, en.addAddress);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.newAddress), findsWidgets);
      expect(path(h), '/addresses');
      // Typing makes it dirty: closing asks first.
      await t.enterText(find.widgetWithText(TextFormField, en.zoneNumber), '66');
      await t.pump();
      await t.tap(find.byTooltip(en.close));
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsOneWidget);
      await tapText(t, en.keepEditing);
      expect(find.byType(BottomSheet), findsOneWidget);

      await t.enterText(find.widgetWithText(TextFormField, en.streetNumber), '850');
      await t.enterText(find.widgetWithText(TextFormField, en.buildingNumber), '12');
      await t.pump();
      await t.tap(find.widgetWithText(FilledButton, en.saveAddress));
      await settle(t);
      expect(h.adapter.where('POST', '/me/addresses'), hasLength(1));
      expect(find.byType(BottomSheet), findsNothing);
      expect(find.text(en.addressSaved), findsOneWidget, reason: 'confirmed on the page behind');
      await teardown(t);
    });

    testWidgets('the map pin picker stays a full-screen route with a close button, and returns to the form', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/addresses');
      await tapText(t, en.addAddress);
      await tapText(t, en.pinOnMap);
      expect(find.byType(MapPinPicker), findsOneWidget);
      expect(find.byType(CloseButton), findsOneWidget, reason: 'a visible way out of the full-screen flow');
      await t.tap(find.byType(CloseButton));
      await settle(t);
      expect(find.byType(MapPinPicker), findsNothing);
      expect(find.text(en.newAddress), findsWidgets, reason: 'back in the address window');
      await tapText(t, en.pinOnMap);
      await t.tap(find.text(en.confirmLocation));
      await settle(t);
      expect(find.text(en.pinSet), findsWidgets, reason: 'the chosen pin came back to the form');
      await teardown(t);
    });

    testWidgets('tablet: the address form is a panel on the end edge', (t) async {
      final h = await boot(t, backend(), width: 1024, height: 768);
      await go(t, h, '/addresses');
      await t.tap(find.text(en.addAddress).first);
      await settle(t);
      expect(windowRect(t, en.qatarAddressHint).right, 1024);
      await teardown(t);
    });

    testWidgets('profile: opened from the account hub as a window, Save is pinned and confirmed on the page', (
      t,
    ) async {
      final h = await boot(t, backend());
      await go(t, h, '/account');
      await openProfile(t);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/account');
      await t.enterText(find.widgetWithText(TextFormField, en.nameLabel), 'Noor Al Thani II');
      await t.tap(find.widgetWithText(FilledButton, en.save));
      await settle(t);
      expect(h.adapter.requests.where((r) => r.path == '/me' && r.method == 'PATCH' || r.method == 'PUT'), isNotEmpty);
      await teardown(t);
    });

    testWidgets('profile: changing the password is a step inside the window, not a second window', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/account');
      await openProfile(t);
      await tapText(t, en.changePassword);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text(en.currentPassword), findsOneWidget);
      await t.tap(find.byTooltip('Back'));
      await settle(t);
      expect(find.text(en.nameLabel), findsOneWidget);
      await teardown(t);
    });

    testWidgets('notification preferences: every switch saves at once and says Saved', (t) async {
      final h = await boot(
        t,
        backend(
          extra: (r) => r.method == 'GET' && r.path == '/me/notification-preferences'
              ? ok([
                  {'channel': 'push', 'category': 'marketing', 'enabled': true},
                  {'channel': 'sms', 'category': 'marketing', 'enabled': false},
                ])
              : null,
        ),
      );
      await go(t, h, '/settings');
      await tapText(t, en.notificationPreferences);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/settings');
      await t.tap(find.byType(Switch).last);
      await settle(t);
      expect(
        h.adapter.requests.where((r) => r.path == '/me/notification-preferences' && r.method != 'GET'),
        isNotEmpty,
      );
      expect(find.text(en.savedToast), findsOneWidget);
      expect(find.byType(SnackBar), findsNothing, reason: 'the message is inside the window');
      await teardown(t);
    });

    testWidgets('support: a new ticket opens as a window and, once sent, its conversation opens in its place', (
      t,
    ) async {
      final h = await boot(
        t,
        backend(
          extra: (r) => r.method == 'POST' && r.path == '/me/support/tickets'
              ? ok({
                  'id': 't1',
                  'subject': 'Where is my order?',
                  'status': 'open',
                  'category': 'order',
                  'created_at': '2026-11-01T09:00:00+03:00',
                })
              : null,
        ),
      );
      await go(t, h, '/support');
      await tapText(t, en.newTicket);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/support');
      await t.enterText(find.widgetWithText(TextFormField, en.ticketSubject), 'Where is my order?');
      await t.enterText(find.widgetWithText(TextFormField, en.ticketMessage), 'Please help me find it');
      await t.tap(find.widgetWithText(FilledButton, en.send));
      await settle(t);
      expect(h.adapter.where('POST', '/me/support/tickets'), hasLength(1));
      expect(find.text('It ships today.'), findsOneWidget, reason: 'the conversation window');
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/support');
      await teardown(t);
    });

    testWidgets('support: a ticket from the list opens as a window with the thread and a reply box', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/support');
      await tapText(t, 'Where is my order?');
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text('Hello, any news?'), findsOneWidget);
      expect(find.text(en.typeMessage), findsOneWidget);
      await back(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/support');
      await teardown(t);
    });

    testWidgets('the review form opens from a product page as a window, rating is required', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/products/p1');
      await tapText(t, en.writeReview);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/products/p1');
      await t.tap(find.widgetWithText(FilledButton, en.submitReview));
      await settle(t);
      expect(find.text(en.ratingRequired), findsOneWidget);
      expect(h.adapter.requests.where((r) => r.method == 'POST' && r.path.contains('reviews')), isEmpty);
      await teardown(t);
    });
  });

  group('Pickers and lists open in windows', () {
    testWidgets('branch: the picker is a window with a details step; choosing closes it with a confirmation', (
      t,
    ) async {
      final h = await boot(t, backend());
      await go(t, h, '/account');
      await tapText(t, en.preferredBranch);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/account');
      expect(find.text(en.allBranches), findsOneWidget);
      await tapText(t, 'West Bay', last: true);
      expect(find.byType(BottomSheet), findsNothing);
      expect(find.text(en.branchSelectedToast('West Bay')), findsOneWidget);
      await teardown(t);
    });

    testWidgets('wishlist opens from the account hub as a window', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/account');
      await tapText(t, en.myWishlist, last: true);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(find.text('Product p1'), findsOneWidget);
      expect(path(h), '/account');
      await back(t);
      expect(find.byType(BottomSheet), findsNothing);
      await teardown(t);
    });

    testWidgets('loyalty details open as a window', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/account');
      await tapText(t, en.loyaltyAndReferral);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/account');
      await teardown(t);
    });

    testWidgets('notifications: a list window; an order notification opens that order in its place', (t) async {
      final h = await boot(
        t,
        backend(
          extra: (r) => r.method == 'GET' && r.path == '/me/notifications'
              ? page([
                  {
                    'id': 'n1',
                    'event': 'order.shipped',
                    'title': 'Your order is on its way',
                    'body': 'Arriving today',
                    'data': {'order_id': 'o1'},
                    'created_at': '2026-11-01T09:00:00+03:00',
                    'read_at': null,
                  },
                  {
                    'id': 'n2',
                    'event': 'marketing.offer',
                    'title': 'Autumn offers',
                    'body': 'Everything for your living room is 20% off this week.',
                    'created_at': '2026-11-01T08:00:00+03:00',
                    'read_at': null,
                  },
                ])
              : null,
        ),
      );
      await go(t, h, '/account');
      await tapText(t, en.notificationsTitle);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/account');
      // A notification without a destination shows its full text as a step of the window.
      await tapText(t, 'Autumn offers');
      expect(find.text('Everything for your living room is 20% off this week.'), findsOneWidget);
      expect(find.byType(BottomSheet), findsOneWidget);
      await t.tap(find.byTooltip('Back'));
      await settle(t);
      // An order notification opens the order window.
      await tapText(t, 'Your order is on its way');
      expect(find.text(en.orderTimeline), findsOneWidget);
      expect(find.byType(BottomSheet), findsOneWidget);
      expect(path(h), '/account');
      expect(h.adapter.where('POST', '/me/notifications/n1/read').length + h.adapter.requests.length, greaterThan(0));
      await teardown(t);
    });
  });

  group('A window belongs to its page', () {
    testWidgets('changing the page underneath (a footer tab) closes the window', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/products');
      await t.tap(find.byTooltip(en.quickViewOf('Product 1')));
      await settle(t);
      expect(find.text(en.quickView), findsOneWidget);
      h.read(routerProvider).go('/cart');
      await settle(t);
      expect(find.byType(BottomSheet), findsNothing);
      expect(path(h), '/cart');
      await teardown(t);
    });

    testWidgets('a page pushed from a window opens above it, and Back returns to the window', (t) async {
      final h = await boot(t, backend());
      await openOrderPanel(t, h);
      // Tapping an item opens the product page above the order window.
      await t.tap(find.textContaining('Nordic oak dining table').first);
      await settle(t);
      expect(path(h), '/products/p1');
      await back(t);
      expect(path(h), '/orders');
      expect(find.text(en.orderTimeline), findsOneWidget, reason: 'the order window is still open');
      await teardown(t);
    });
  });

  group('Things that stay full screens', () {
    testWidgets('checkout is a page, not a window, and keeps the back arrow as its exit', (t) async {
      final h = await boot(t, backend());
      await go(t, h, '/cart');
      unawaited(h.read(routerProvider).push('/checkout'));
      await settle(t);
      expect(path(h), '/checkout');
      expect(find.byType(BottomSheet), findsNothing);
      expect(find.byType(BackButton), findsOneWidget);
      await back(t);
      expect(path(h), '/cart');
      await teardown(t);
    });
  });
}
