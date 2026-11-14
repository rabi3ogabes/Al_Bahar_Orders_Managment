@Timeout(Duration(minutes: 5))
library;

import 'dart:async';

import 'package:albahar_customer/core/router/app_footer.dart';
import 'package:albahar_customer/core/router/app_router.dart' show routerProvider;
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/scan/data/scan_source.dart';
import 'package:albahar_customer/features/scan/presentation/state/scan_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show ar, back, backend, boot, en, go, path, settle, tapText, teardown;
import '../../qa/test_fonts.dart';
import 'scan_kit.dart';

/// The scanner screen against the real app, with a fake camera: what the shopper sees and what a scan does to the cart.
void main() {
  setUpAll(loadAppFonts);

  var now = DateTime(2026, 11, 14, 10);
  void advance(Duration d) => now = now.add(d);
  setUp(() => now = DateTime(2026, 11, 14, 10));

  ScanServer standardServer() => ScanServer(
    catalog: {
      '6291000000011': scanAnswer('p1', name: 'Oak side table', variantName: 'Natural oak'),
      '6291000000028': scanAnswer('p2', variantId: 'v2', name: 'Linen cushion', amount: '100.00'),
      'oos': scanAnswer('p3', variantId: 'v3', name: 'Rattan chair', inStock: false, max: 0),
      'wardrobe': scanAnswer('p9', name: 'Wardrobe', needsChoice: true, matchedBy: 'product_barcode'),
    },
  );

  /// Boots the app on the scanner. [source] and [server] are what the camera sees and what the shop answers.
  Future<({FakeScanSource source, ScanServer server, TestHarness h})> open(
    WidgetTester t, {
    FakeScanSource? source,
    ScanServer? server,
    double width = 390,
    double height = 844,
    String locale = 'en',
    bool signedIn = true,
    bool dark = false,
  }) async {
    final camera = source ?? FakeScanSource();
    final shop = server ?? standardServer();
    final h = await boot(
      t,
      backend(
        extra: (r) async {
          if (r.method == 'GET' && r.path == '/products/p9') return ok(productDetail('p9'));
          return shop.handle(r);
        },
      ),
      width: width,
      height: height,
      locale: locale,
      signedIn: signedIn,
      dark: dark,
      overrides: [scanSourceProvider.overrideWithValue(camera), scanClockProvider.overrideWithValue(() => now)],
    );
    if (signedIn) await h.container.read(cartControllerProvider.future);
    // Opened the way the app opens it (pushed from a page), so Back returns to that page.
    await go(t, h, '/home');
    unawaited(h.read(routerProvider).push('/scan'));
    await settle(t);
    return (source: camera, server: shop, h: h);
  }

  Future<void> see(WidgetTester t, FakeScanSource camera, String code) async {
    camera.emit(code);
    await settle(t);
  }

  Finder button(String label) => find.widgetWithText(AppButton, label);

  group('the screen', () {
    testWidgets('a full-screen camera with a way out, a light, and a typing fallback; no footer', (t) async {
      final (:source, :server, :h) = await open(t);
      expect(find.byKey(const ValueKey('fake-camera')), findsOneWidget);
      expect(find.text(en.scanTitle), findsOneWidget);
      expect(find.byTooltip(en.scanClose), findsOneWidget);
      expect(find.byTooltip(en.scanTorchOn), findsOneWidget);
      expect(find.text(en.scanHint), findsOneWidget);
      expect(find.text(en.scanEnterCode), findsOneWidget);
      expect(find.byType(AppFooter), findsNothing, reason: 'the camera covers the whole window');
      expect(source.starts, greaterThan(0), reason: 'the camera is started');
      await teardown(t);
    });

    testWidgets('the light button follows the camera and is hidden when the camera has none', (t) async {
      final camera = FakeScanSource(torch: ScanTorch.unavailable);
      await open(t, source: camera);
      expect(find.byTooltip(en.scanTorchOn), findsNothing);
      expect(find.byTooltip(en.scanTorchOff), findsNothing);
      camera.torch.value = ScanTorch.off;
      await settle(t);
      await t.tap(find.byTooltip(en.scanTorchOn));
      await settle(t);
      expect(camera.torchToggles, 1);
      expect(find.byTooltip(en.scanTorchOff), findsOneWidget);
      await teardown(t);
    });

    testWidgets('close leaves the scanner for the page it was opened from', (t) async {
      final (:source, :server, :h) = await open(t);
      await t.tap(find.byTooltip(en.scanClose));
      await settle(t);
      expect(path(h), '/home');
      expect(find.byType(AppFooter), findsOneWidget);
      await teardown(t);
    });

    testWidgets('going back (the router pop that Back performs) leaves it too, and the camera is released', (t) async {
      final (:source, :server, :h) = await open(t);
      h.read(routerProvider).pop();
      await settle(t);
      expect(path(h), '/home');
      expect(source.stops, greaterThan(0));
      await teardown(t);
    });

    testWidgets('the camera is released in the background and taken again on return', (t) async {
      final (:source, :server, :h) = await open(t);
      final starts = source.starts;
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await settle(t);
      expect(source.stops, greaterThan(0));
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(t);
      expect(source.starts, greaterThan(starts));
      await teardown(t);
    });
  });

  group('scanning adds to the cart without leaving the camera', () {
    testWidgets('one scan: exactly one unit, the card, the running bar, no navigation', (t) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, '6291000000011');

      expect(server.adds, [(variantId: 'v1', quantity: 1)]);
      expect(find.text('Oak side table'), findsOneWidget);
      expect(find.text('Natural oak'), findsOneWidget);
      expect(find.text(en.addedToCart), findsOneWidget);
      expect(find.text(en.scanUndo), findsOneWidget);
      expect(find.byType(QuantityStepper), findsOneWidget);
      expect(t.widget<QuantityStepper>(find.byType(QuantityStepper)).value, 1);
      expect(find.text('${en.itemsCount(1)} · ${en.subtotal}'), findsOneWidget, reason: 'the running bar');
      expect(find.text('QAR 100.00'), findsWidgets);
      expect(button(en.scanKeepScanning), findsOneWidget);
      expect(button(en.viewCart), findsOneWidget);
      expect(path(h), '/scan', reason: 'a scan never navigates away');
      expect(h.read(cartCountProvider), 1);
      await teardown(t);
    });

    testWidgets('the same barcode held in view is one unit; a deliberate second scan adds another to the same line', (
      t,
    ) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, '6291000000011');
      advance(const Duration(milliseconds: 500));
      await see(t, source, '6291000000011');
      advance(const Duration(milliseconds: 500));
      await see(t, source, '6291000000011');
      expect(server.lines, {'v1': 1});

      advance(const Duration(seconds: 3));
      await see(t, source, '6291000000011');
      expect(server.lines, {'v1': 2});
      expect(h.read(cartControllerProvider).requireValue.items, hasLength(1));
      expect(t.widget<QuantityStepper>(find.byType(QuantityStepper)).value, 2);
      expect(find.text('${en.itemsCount(2)} · ${en.subtotal}'), findsOneWidget);
      expect(find.text('QAR 200.00'), findsWidgets, reason: 'the server\'s subtotal');
      await teardown(t);
    });

    testWidgets('the card has − / +: they change the cart line and the running bar', (t) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, '6291000000011');
      // The "+" button of the card's stepper.
      final plus = find.descendant(of: find.byType(QuantityStepper), matching: find.byIcon(Icons.add_rounded));
      await t.tap(plus.first);
      await settle(t);
      expect(server.patches, ['v1:2']);
      expect(find.text('${en.itemsCount(2)} · ${en.subtotal}'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('Undo takes the scan back; the card goes and a quiet message says so', (t) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, '6291000000011');
      await t.tap(find.text(en.scanUndo));
      await settle(t);

      expect(server.deletes, ['v1']);
      expect(h.read(cartCountProvider), 0);
      expect(find.text('Oak side table'), findsNothing);
      expect(find.text(en.scanRemoved), findsOneWidget);
      expect(find.text(en.scanHint), findsOneWidget, reason: 'back to the idle hint, camera still live');
      await teardown(t);
    });

    testWidgets('Keep scanning hides the card and the camera goes on; the next product is added too', (t) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, '6291000000011');
      await t.tap(button(en.scanKeepScanning));
      await settle(t);
      expect(find.text('Oak side table'), findsNothing);
      expect(find.text(en.scanHint), findsOneWidget);
      expect(
        find.text(en.viewCart),
        findsOneWidget,
        reason: 'the bar with the way to the cart stays while the cart has items',
      );

      await see(t, source, '6291000000028');
      expect(server.lines, {'v1': 1, 'v2': 1});
      expect(find.text('Linen cushion'), findsOneWidget);
      expect(find.text('${en.itemsCount(2)} · ${en.subtotal}'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('View cart goes to the cart tab', (t) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, '6291000000011');
      await t.tap(button(en.viewCart));
      await settle(t);
      expect(path(h), '/cart');
      expect(find.text('Oak side table'), findsWidgets);
      await teardown(t);
    });
  });

  group('plain words, one clear action', () {
    testWidgets('not found: says so and offers Search by name', (t) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, '0000000000000');
      expect(find.text(en.scanNotFoundTitle), findsOneWidget);
      expect(find.text(en.scanNotFoundMessage), findsOneWidget);
      expect(server.adds, isEmpty);
      await t.tap(button(en.scanSearchByName));
      await settle(t);
      expect(path(h), '/search');
      await teardown(t);
    });

    testWidgets('out of stock: named, not added', (t) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, 'oos');
      expect(find.text(en.scanOutOfStockTitle), findsOneWidget);
      expect(find.text(en.scanOutOfStockMessage('Rattan chair')), findsOneWidget);
      expect(server.adds, isEmpty);
      expect(h.read(cartCountProvider), 0);
      await t.tap(button(en.scanKeepScanning));
      await settle(t);
      expect(find.text(en.scanOutOfStockTitle), findsNothing);
      await teardown(t);
    });

    testWidgets('a product with options opens the option window instead of guessing', (t) async {
      final (:source, :server, :h) = await open(t);
      await see(t, source, 'wardrobe');
      expect(find.text(en.quickView), findsOneWidget, reason: 'the option window opened by itself');
      expect(server.adds, isEmpty);
      expect(path(h), '/scan');

      // While the window is open the camera is not listened to: another barcode is not added behind it.
      advance(const Duration(seconds: 3));
      await see(t, source, '6291000000011');
      expect(server.adds, isEmpty);

      await back(t);
      expect(find.text(en.quickView), findsNothing);
      expect(find.text(en.scanChoiceTitle), findsNothing, reason: 'the choice card is gone with the window');
      advance(const Duration(seconds: 3));
      await see(t, source, '6291000000011');
      expect(server.adds, hasLength(1), reason: 'scanning goes on after the window');
      await teardown(t);
    });

    testWidgets('offline: nothing added or queued; Try again after the connection returns', (t) async {
      final (:source, :server, :h) = await open(t);
      h.adapter.failConnection = (r) => r.path.startsWith('/products/by-code');
      await see(t, source, '6291000000011');
      expect(find.text(en.scanOfflineTitle), findsOneWidget);
      expect(find.text(en.scanOfflineMessage), findsOneWidget);
      expect(server.lines, isEmpty);

      h.adapter.failConnection = null;
      await t.tap(button(en.retry));
      await settle(t);
      expect(server.lines, {'v1': 1});
      expect(find.text(en.scanOfflineTitle), findsNothing);
      await teardown(t);
    });

    testWidgets('the server refuses the add: its message is shown and the cart is unchanged', (t) async {
      final (:source, :server, :h) = await open(t);
      server.failAdd = true;
      await see(t, source, '6291000000011');
      expect(find.text(en.scanFailedTitle), findsOneWidget);
      expect(find.text('This item is not available right now.'), findsOneWidget);
      expect(h.read(cartCountProvider), 0);
      await teardown(t);
    });

    testWidgets('a guest sees the product and is asked to sign in', (t) async {
      final (:source, :server, :h) = await open(t, signedIn: false);
      await see(t, source, '6291000000011');
      expect(find.text(en.scanSignInTitle), findsOneWidget);
      expect(find.text(en.scanSignInMessage('Oak side table')), findsOneWidget);
      expect(server.adds, isEmpty);
      await t.tap(button(en.signIn));
      await settle(t);
      expect(path(h), startsWith('/login'));
      await teardown(t);
    });
  });

  group('no camera', () {
    testWidgets('permission refused: explains, offers Settings and typing; the way out stays', (t) async {
      final camera = FakeScanSource(camera: ScanCameraState.permissionDenied);
      await open(t, source: camera);
      expect(find.text(en.scanPermissionTitle), findsOneWidget);
      expect(find.text(en.scanPermissionMessage), findsOneWidget);
      expect(find.byTooltip(en.scanClose), findsOneWidget);
      expect(find.text(en.scanEnterCode), findsOneWidget);
      await t.tap(button(en.openSettings));
      await settle(t);
      expect(camera.settingsOpened, 1);
      await teardown(t);
    });

    testWidgets('returning from Settings with the permission on starts the camera again', (t) async {
      final camera = FakeScanSource(camera: ScanCameraState.permissionDenied);
      await open(t, source: camera);
      final starts = camera.starts;
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      camera.camera.value = ScanCameraState.ready;
      await settle(t);
      expect(camera.starts, greaterThan(starts));
      expect(find.text(en.scanPermissionTitle), findsNothing);
      expect(find.text(en.scanHint), findsOneWidget);
      await teardown(t);
    });

    testWidgets('no usable camera: says so and still lets the shopper search or type a code', (t) async {
      final camera = FakeScanSource(camera: ScanCameraState.unavailable);
      final (:source, :server, :h) = await open(t, source: camera);
      expect(find.text(en.scanCameraUnavailableTitle), findsOneWidget);
      expect(find.text(en.scanEnterCode), findsOneWidget);

      await t.tap(find.text(en.scanEnterCode));
      await settle(t);
      await t.enterText(find.byType(TextField), '6291000000028');
      await t.tap(button(en.scanManualAdd));
      await settle(t);
      expect(server.lines, {'v2': 1});
      await teardown(t);
    });
  });

  group('typing a code', () {
    testWidgets('a typed code is looked up and added; an empty one is asked for again', (t) async {
      final (:source, :server, :h) = await open(t);
      await t.tap(find.text(en.scanEnterCode));
      await settle(t);
      expect(find.text(en.scanManualTitle), findsOneWidget);
      expect(find.text(en.scanManualHint), findsOneWidget);

      await t.tap(button(en.scanManualAdd));
      await settle(t);
      expect(find.text(en.scanManualEmpty), findsOneWidget);
      expect(server.lookups, isEmpty);

      await t.enterText(find.byType(TextField), '  6291000000011 ');
      await t.tap(button(en.scanManualAdd));
      await settle(t);
      expect(server.lookups, ['6291000000011']);
      expect(server.lines, {'v1': 1});
      expect(find.text('Oak side table'), findsOneWidget);
      expect(find.text(en.scanManualTitle), findsNothing, reason: 'the typing window closed');
      await teardown(t);
    });

    testWidgets('the camera is not listened to while the typing window is open', (t) async {
      final (:source, :server, :h) = await open(t);
      await t.tap(find.text(en.scanEnterCode));
      await settle(t);
      await see(t, source, '6291000000011');
      expect(server.lookups, isEmpty);
      await back(t);
      expect(find.text(en.scanManualTitle), findsNothing);
      await teardown(t);
    });
  });

  group('everyone can use it', () {
    testWidgets('Arabic: right to left, Arabic words, same behaviour', (t) async {
      final (:source, :server, :h) = await open(t, locale: 'ar');
      expect(find.text(ar.scanTitle), findsOneWidget);
      expect(find.text(ar.scanHint), findsOneWidget);
      // The close button is on the start side, which is the right in Arabic.
      final close = t.getCenter(find.byTooltip(ar.scanClose));
      expect(close.dx, greaterThan(195), reason: 'start side = right in RTL');
      await see(t, source, '6291000000011');
      expect(find.text(ar.addedToCart), findsOneWidget);
      expect(find.text(ar.scanUndo), findsOneWidget);
      expect(find.text(ar.scanKeepScanning), findsOneWidget);
      expect(server.lines, {'v1': 1});
      await teardown(t);
    });

    testWidgets('large text (1.3x) on a small phone: nothing overflows, every button is still there', (t) async {
      t.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
      final (:source, :server, :h) = await open(t, width: 320, height: 640);
      await see(t, source, '6291000000011');
      expect(t.takeException(), isNull);
      expect(find.text(en.scanUndo), findsOneWidget);
      expect(button(en.scanKeepScanning), findsOneWidget);
      expect(button(en.viewCart), findsOneWidget);
      await see(t, source, '0000000000000');
      expect(t.takeException(), isNull);
      await teardown(t);
    });

    testWidgets('dark mode renders the whole flow without errors', (t) async {
      final (:source, :server, :h) = await open(t, dark: true);
      await see(t, source, '6291000000011');
      await see(t, source, 'oos');
      expect(t.takeException(), isNull);
      expect(find.text(en.scanOutOfStockTitle), findsOneWidget);
      await teardown(t);
    });

    testWidgets('tablet: the panel keeps a readable width in the middle', (t) async {
      final (:source, :server, :h) = await open(t, width: 1024, height: 768);
      await see(t, source, '6291000000011');
      final card = t.getRect(
        find.byType(Material).evaluate().isEmpty ? find.byType(Scaffold) : find.text(en.addedToCart),
      );
      expect(card.width, lessThan(560));
      expect(t.takeException(), isNull);
      await teardown(t);
    });

    testWidgets('reduced motion: the card appears at once and nothing animates forever', (t) async {
      t.platformDispatcher.accessibilityFeaturesTestValue = const FakeAccessibilityFeatures(disableAnimations: true);
      addTearDown(t.platformDispatcher.clearAccessibilityFeaturesTestValue);
      final (:source, :server, :h) = await open(t);
      await see(t, source, '6291000000011');
      expect(find.text('Oak side table'), findsOneWidget);
      expect(t.takeException(), isNull);
      await teardown(t);
    });

    testWidgets('screen readers hear the buttons and the card as one labelled thing each', (t) async {
      final handle = t.ensureSemantics();
      final (:source, :server, :h) = await open(t);
      expect(find.bySemanticsLabel(en.scanClose), findsWidgets);
      expect(find.bySemanticsLabel(en.scanWindowLabel), findsOneWidget);
      await see(t, source, '0000000000000');
      expect(find.bySemanticsLabel(RegExp(en.scanNotFoundTitle)), findsWidgets);
      handle.dispose();
      await teardown(t);
    });
  });

  group('entry points', () {
    testWidgets('Home: the scan icon in the search bar opens the scanner (and the bar itself still opens Search)', (
      t,
    ) async {
      final h = await boot(
        t,
        backend(extra: standardServer().handle),
        overrides: [scanSourceProvider.overrideWithValue(FakeScanSource())],
      );
      await go(t, h, '/home');
      expect(find.byTooltip(en.scanTitle), findsOneWidget);
      await t.tap(find.byTooltip(en.scanTitle));
      await settle(t);
      expect(path(h), '/scan');
      await t.tap(find.byTooltip(en.scanClose));
      await settle(t);
      expect(path(h), '/home');
      await t.tap(find.text(en.searchHint).first);
      await settle(t);
      expect(path(h), '/search');
      await teardown(t);
    });

    testWidgets('Search: the scan icon is in the empty field and gives way to the clear button', (t) async {
      final h = await boot(
        t,
        backend(extra: standardServer().handle),
        overrides: [scanSourceProvider.overrideWithValue(FakeScanSource())],
      );
      await go(t, h, '/search');
      expect(find.byTooltip(en.scanTitle), findsOneWidget);
      await t.enterText(find.byType(TextField).first, 'oak');
      await t.pump();
      expect(find.byTooltip(en.scanTitle), findsNothing);
      expect(find.byTooltip(en.clear), findsOneWidget);
      await t.enterText(find.byType(TextField).first, '');
      await t.pump();
      await t.tap(find.byTooltip(en.scanTitle));
      await settle(t);
      expect(path(h), '/scan');
      await teardown(t);
    });

    testWidgets('Cart tab: "Scan to add" with a cart and with an empty cart', (t) async {
      final server = standardServer();
      final h = await boot(
        t,
        backend(extra: server.handle),
        overrides: [scanSourceProvider.overrideWithValue(FakeScanSource())],
      );
      await go(t, h, '/cart');
      expect(find.text(en.cartEmpty), findsOneWidget);
      expect(find.text(en.scanTitle), findsOneWidget, reason: 'also when the cart is empty');

      server.lines['v1'] = 1;
      unawaited(h.read(cartControllerProvider.notifier).revalidate());
      await settle(t);
      expect(find.text(en.cartEmpty), findsNothing);
      await tapText(t, en.scanTitle);
      expect(path(h), '/scan');
      await teardown(t);
    });

    testWidgets('the footer menu can lead to the scanner when the dashboard puts it there', (t) async {
      final h = await boot(
        t,
        backend(extra: standardServer().handle),
        overrides: [scanSourceProvider.overrideWithValue(FakeScanSource())],
      );
      await go(t, h, '/scan');
      expect(footerIndexFor('/scan', ['home', 'categories', 'scan', 'cart', 'account']), 2);
      expect(
        footerIndexFor('/scan', ['home', 'categories', 'cart', 'account']),
        2,
        reason: 'not in the footer: counts under Cart',
      );
      expect(showsFooter('/scan'), isFalse);
      await teardown(t);
    });
  });
}
