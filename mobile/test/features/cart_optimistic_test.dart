import 'dart:async';

import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import '../qa/customer_fixtures.dart';
import '../qa/side_windows_test.dart' show FakeCartServer, backend;

/// The cart changes before the server answers, never grows a second line for the same variant, and goes back when the
/// server refuses.
void main() {
  Future<({TestHarness h, FakeCartServer server})> start({Map<String, int>? lines}) async {
    final server = FakeCartServer(lines: lines);
    final h = await TestHarness.create(
      tokens: signedInTokens,
      handler: backend(extra: server.handle),
    );
    await h.container.read(cartControllerProvider.future);
    return (h: h, server: server);
  }

  final product = ProductSummary.fromJson(productCard('p1'));

  test('a new variant gets a line at once and the real one when the server answers', () async {
    final (:h, :server) = await start();
    server.hold = Completer<void>();
    final done = h.read(cartControllerProvider.notifier).addItem('v1', quantity: 2, product: product);
    await Future<void>.delayed(Duration.zero);
    var cart = h.read(cartControllerProvider).requireValue;
    expect(cart.itemsCount, 2, reason: 'the badge moved before the answer');
    expect(cart.items.single.id, startsWith('pending-'));
    expect(h.read(cartBusyItemsProvider), isNotEmpty, reason: 'the pending line cannot be edited yet');
    server.hold!.complete();
    await done;
    cart = h.read(cartControllerProvider).requireValue;
    expect(cart.items.single.id, 'i-v1');
    expect(cart.itemsCount, 2);
    expect(h.read(cartBusyItemsProvider), isEmpty);
  });

  test('a variant already in the cart gets a higher quantity, never a second line', () async {
    final (:h, :server) = await start(lines: {'v1': 1});
    server.hold = Completer<void>();
    final done = h.read(cartControllerProvider.notifier).addItem('v1');
    await Future<void>.delayed(Duration.zero);
    var cart = h.read(cartControllerProvider).requireValue;
    expect(cart.items, hasLength(1));
    expect(cart.items.single.quantity, 2);
    expect(cart.itemsCount, 2);
    server.hold!.complete();
    await done;
    cart = h.read(cartControllerProvider).requireValue;
    expect(cart.items, hasLength(1), reason: 'the server merged it as well');
    expect(cart.items.single.quantity, 2);
    expect(server.lines, {'v1': 2});
  });

  test('a refused add puts the cart back exactly as it was and throws', () async {
    final (:h, :server) = await start(lines: {'v2': 1});
    server.failAdd = true;
    final before = h.read(cartControllerProvider).requireValue;
    await expectLater(h.read(cartControllerProvider.notifier).addItem('v1', product: product), throwsA(anything));
    final after = h.read(cartControllerProvider).requireValue;
    expect(after.itemsCount, before.itemsCount);
    expect(after.items.map((i) => i.variantId), ['v2']);
    expect(h.read(cartBusyItemsProvider), isEmpty);
  });

  test('changing a quantity moves the badge count at once; the server\'s answer is kept', () async {
    final (:h, :server) = await start(lines: {'v1': 1});
    final done = h.read(cartControllerProvider.notifier).updateQuantity('i-v1', 4);
    await Future<void>.delayed(Duration.zero);
    expect(h.read(cartControllerProvider).requireValue.itemsCount, 4);
    await done;
    expect(server.patches, ['v1:4']);
    expect(h.read(cartControllerProvider).requireValue.items.single.quantity, 4);
  });
}
