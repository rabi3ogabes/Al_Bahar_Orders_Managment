import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/scan/data/scan_repository.dart';
import 'package:albahar_customer/features/scan/presentation/state/scan_controller.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import 'scan_kit.dart';

/// The scanner's brain without a screen or a camera: what one scan does to the cart, what is ignored, what is told.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  var now = DateTime(2026, 11, 14, 10);
  void advance(Duration d) => now = now.add(d);

  setUp(() => now = DateTime(2026, 11, 14, 10));

  Future<({TestHarness h, ScanServer server, ScanController scan})> start({
    Map<String, Object>? catalog,
    Map<String, int>? lines,
    bool signedIn = true,
  }) async {
    final server = ScanServer(
      catalog:
          catalog ??
          {
            '6291000000011': scanAnswer('p1', name: 'Oak side table'),
            '6291000000028': scanAnswer('p2', variantId: 'v2', name: 'Linen cushion', amount: '100.00'),
          },
      lines: lines,
    );
    final base = customerBackend(ApiMode.data);
    final h = await TestHarness.create(
      tokens: signedIn ? signedInTokens : null,
      handler: (r) async => await server.handle(r) ?? await base(r),
      overrides: [scanClockProvider.overrideWithValue(() => now)],
    );
    h.container.listen(scanControllerProvider, (_, _) {}); // keeps the autoDispose controller alive
    if (signedIn) await h.container.read(cartControllerProvider.future);
    return (h: h, server: server, scan: h.container.read(scanControllerProvider.notifier));
  }

  ScanState stateOf(TestHarness h) => h.read(scanControllerProvider);

  group('adding', () {
    test('one scan puts exactly one unit in the cart and shows the card', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('6291000000011');

      expect(server.lookups, ['6291000000011']);
      expect(server.adds, [(variantId: 'v1', quantity: 1)]);
      expect(server.lines, {'v1': 1});
      final s = stateOf(h);
      expect(s.problem, isNull);
      expect(s.looking, isFalse);
      expect(s.added, 1);
      expect(s.entry!.product.name, 'Oak side table');
      expect(s.entry!.variantId, 'v1');
      expect(s.entry!.previousQuantity, 0);
      expect(s.entry!.maxQuantity, 5);
      expect(h.read(cartCountProvider), 1, reason: 'the badge');
      expect(h.read(cartControllerProvider).requireValue.subtotal.formatted, 'QAR 100.00');
    });

    test('the cart changes before the server answers (the same optimistic logic as every other add)', () async {
      final (:h, :server, :scan) = await start();
      server.holdAdd = Completer<void>();
      final done = scan.submit('6291000000011');
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(h.read(cartCountProvider), 1, reason: 'optimistic');
      expect(server.lines, isEmpty);
      server.holdAdd!.complete();
      await done;
      expect(server.lines, {'v1': 1});
    });

    test('the same code again within 2 seconds is ignored, even if it never leaves the frame', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('6291000000011');
      advance(const Duration(milliseconds: 600));
      await scan.submit('6291000000011');
      advance(const Duration(milliseconds: 1500)); // 2.1 s after the first, but only 1.5 s after the last sighting
      await scan.submit('6291000000011');

      expect(server.lookups, hasLength(1), reason: 'holding the phone on one barcode is one scan');
      expect(server.lines, {'v1': 1});
      expect(stateOf(h).added, 1);
    });

    test('a deliberate second scan after the code left the view adds another unit to the same line', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('6291000000011');
      advance(const Duration(milliseconds: 2100));
      await scan.submit('6291000000011');

      expect(server.lines, {'v1': 2});
      final cart = h.read(cartControllerProvider).requireValue;
      expect(cart.items, hasLength(1), reason: 'never a second line');
      expect(cart.items.single.quantity, 2);
      expect(stateOf(h).entry!.previousQuantity, 1);
      expect(stateOf(h).added, 2);
    });

    test('two different codes one after the other both add, with no waiting', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('6291000000011');
      await scan.submit('6291000000028');

      expect(server.lines, {'v1': 1, 'v2': 1});
      expect(h.read(cartCountProvider), 2);
      expect(stateOf(h).entry!.product.name, 'Linen cushion', reason: 'the card shows the latest');
    });

    test('a code that arrives while a lookup is running is dropped', () async {
      final (:h, :server, :scan) = await start();
      server.holdLookup = Completer<void>();
      final first = scan.submit('6291000000011');
      await Future<void>.delayed(const Duration(milliseconds: 20));
      expect(stateOf(h).looking, isTrue);
      await scan.submit('6291000000028');
      server.holdLookup!.complete();
      await first;

      expect(server.lookups, ['6291000000011']);
      expect(server.lines, {'v1': 1});
      expect(stateOf(h).looking, isFalse);
    });

    test('a typed code is always deliberate: no waiting period', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('6291000000011');
      await scan.submit('6291000000011', manual: true);

      expect(server.lines, {'v1': 2});
    });

    test('blank input does nothing', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('   ');
      await scan.submit('');

      expect(server.lookups, isEmpty);
      expect(stateOf(h).entry, isNull);
    });

    test('the code is trimmed before it is looked up', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('  6291000000011\n');

      expect(server.lookups, ['6291000000011']);
      expect(server.lines, {'v1': 1});
    });
  });

  group('problems, each in plain words with one clear next step', () {
    test('unknown code: not found, nothing added', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('0000000000000');

      final p = stateOf(h).problem!;
      expect(p.kind, ScanProblemKind.notFound);
      expect(p.code, '0000000000000');
      expect(server.adds, isEmpty);
      expect(stateOf(h).entry, isNull);
    });

    test('a code longer than the server accepts is "not found" without a request', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('9' * (ScanRepository.maxCodeLength + 1));

      expect(stateOf(h).problem!.kind, ScanProblemKind.notFound);
      expect(server.lookups, isEmpty);
    });

    test('out of stock: told, not added', () async {
      final (:h, :server, :scan) = await start(
        catalog: {'oos': scanAnswer('p3', name: 'Rattan chair', inStock: false, max: 0)},
      );
      await scan.submit('oos');

      expect(stateOf(h).problem!.kind, ScanProblemKind.outOfStock);
      expect(stateOf(h).problem!.product!.name, 'Rattan chair');
      expect(server.adds, isEmpty);
    });

    test('not sold at the shopper\'s branch: told, not added', () async {
      final (:h, :server, :scan) = await start(catalog: {'far': scanAnswer('p4', purchasable: false, max: 0)});
      await scan.submit('far');

      expect(stateOf(h).problem!.kind, ScanProblemKind.unavailable);
      expect(server.adds, isEmpty);
    });

    test('a product with several options is not guessed: the choice is asked for', () async {
      final (:h, :server, :scan) = await start(
        catalog: {'wardrobe': scanAnswer('p5', name: 'Wardrobe', needsChoice: true, matchedBy: 'product_barcode')},
      );
      await scan.submit('wardrobe');

      final p = stateOf(h).problem!;
      expect(p.kind, ScanProblemKind.needsChoice);
      expect(p.product!.id, 'p5');
      expect(server.adds, isEmpty);

      scan.clearChoice();
      expect(stateOf(h).problem, isNull);
    });

    test('the cart already holds everything that is available', () async {
      final (:h, :server, :scan) = await start(catalog: {'last': scanAnswer('p6', max: 2)}, lines: {'v1': 2});
      await h.container.read(cartControllerProvider.notifier).revalidate();
      await scan.submit('last');

      final p = stateOf(h).problem!;
      expect(p.kind, ScanProblemKind.limitReached);
      expect(p.max, 2);
      expect(server.adds, isEmpty);
      expect(server.lines, {'v1': 2});
    });

    test('offline: nothing is added or queued, and Try again works once the connection is back', () async {
      final (:h, :server, :scan) = await start();
      h.adapter.failConnection = (r) => r.path.startsWith('/products/by-code');
      await scan.submit('6291000000011');

      expect(stateOf(h).problem!.kind, ScanProblemKind.offline);
      expect(stateOf(h).problem!.code, '6291000000011');
      expect(server.lines, isEmpty);

      h.adapter.failConnection = null;
      await scan.retry();
      expect(stateOf(h).problem, isNull);
      expect(server.lines, {'v1': 1});
    });

    test('the server refuses the add: the cart goes back and the server\'s words are kept', () async {
      final (:h, :server, :scan) = await start();
      server.failAdd = true;
      await scan.submit('6291000000011');

      final p = stateOf(h).problem!;
      expect(p.kind, ScanProblemKind.failed);
      expect((p.error! as ApiException).message, 'This item is not available right now.');
      expect(h.read(cartCountProvider), 0);
      expect(stateOf(h).entry, isNull);
    });

    test('the lookup is rate limited: shown in the server\'s words', () async {
      final (:h, :server, :scan) = await start(
        catalog: {
          'busy': const FakeReply(429, {'message': 'Too many requests.', 'code': 'too_many_requests'}),
        },
      );
      await scan.submit('busy');

      expect(stateOf(h).problem!.kind, ScanProblemKind.failed);
    });

    test('a guest finds the product but cannot add it: asked to sign in, nothing sent to the cart', () async {
      final (:h, :server, :scan) = await start(signedIn: false);
      await scan.submit('6291000000011');

      final p = stateOf(h).problem!;
      expect(p.kind, ScanProblemKind.signInRequired);
      expect(p.product!.name, 'Oak side table');
      expect(server.adds, isEmpty);
    });
  });

  group('undo and keep scanning', () {
    test('undo of a brand new line removes the line', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('6291000000011');
      final done = await scan.undo();

      expect(done, isTrue);
      expect(server.deletes, ['v1']);
      expect(server.lines, isEmpty);
      expect(stateOf(h).entry, isNull);
      expect(stateOf(h).added, 0);
      expect(h.read(cartCountProvider), 0);
    });

    test('undo of a second scan goes back to the quantity before it', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('6291000000011');
      advance(const Duration(seconds: 3));
      await scan.submit('6291000000011');
      expect(server.lines, {'v1': 2});
      await scan.undo();

      expect(server.patches, ['v1:1']);
      expect(server.lines, {'v1': 1});
    });

    test('undo with nothing to undo does nothing', () async {
      final (:h, :server, :scan) = await start();
      expect(await scan.undo(), isFalse);
      expect(server.deletes, isEmpty);
    });

    test('keep scanning hides the card; the count of scanned units stays', () async {
      final (:h, :server, :scan) = await start();
      await scan.submit('6291000000011');
      scan.dismiss();

      expect(stateOf(h).entry, isNull);
      expect(stateOf(h).problem, isNull);
      expect(stateOf(h).added, 1);
      expect(server.lines, {'v1': 1}, reason: 'dismissing never touches the cart');
    });
  });
}
