import 'dart:async';
import 'dart:convert';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/auth/domain/auth_models.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:stream_channel/stream_channel.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

import '../helpers/fakes.dart';

Map<String, dynamic> _cart({int qty = 1, String branch = 'br1', String? issue}) => {
  'id': 'c1',
  'branch_id': branch,
  'fulfillment_type': 'delivery',
  'items': [
    {
      'id': 'i1',
      'variant_id': 'v1',
      'product': productCard('p1'),
      'quantity': qty,
      'unit_price': price('100.00'),
      'line_total': money('${qty * 100}.00'),
      'issue': issue,
    },
  ],
  'items_count': qty,
  'subtotal': money('${qty * 100}.00'),
};

Map<String, dynamic> _quote(String hash, String total, {bool codAvailable = true}) => {
  'quote_hash': hash,
  'fulfillments': [
    {
      'branch': {'id': 'br1', 'name': 'West Bay'},
      'type': 'delivery',
      'items': [],
      'delivery_fee': money('25.00'),
      'eta_minutes': 120,
    },
    {
      'branch': {'id': 'br2', 'name': 'Lusail'},
      'type': 'delivery',
      'items': [],
      'delivery_fee': money('0.00'),
    },
  ],
  'is_split': true,
  'lines': [],
  'totals': {'subtotal': money('100.00'), 'delivery_fee': money('25.00'), 'grand_total': money(total)},
  'payment_methods': [
    {'code': 'sadad', 'available': true},
    {'code': 'cod', 'available': codAvailable, 'reason': codAvailable ? null : 'exceeds_cod_limit'},
  ],
  'loyalty': {'balance': 1000, 'max_redeemable_points': 300, 'points_to_earn': 10},
  'warnings': [],
  'errors': [],
  'can_place_order': true,
};

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

void main() {
  group('Checkout', () {
    test('quote → 409 quote_changed shows new totals; re-confirm uses a new idempotency key', () async {
      var placeCalls = 0;
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+97455123456'}),
          'GET /cart': (_) => ok(_cart()),
          'GET /me/addresses': (_) => page([
            {'id': 'a1', 'recipient_name': 'Noor', 'recipient_phone': '+97455123456', 'is_default': true},
          ]),
          'POST /checkout/quote': (_) => ok(_quote('h1', '125.00')),
          'POST /checkout/orders': (r) {
            placeCalls++;
            if (r.body['quote_hash'] == 'h1') {
              return error(409, 'quote_changed', context: {'quote': _quote('h2', '140.00')});
            }
            return ok({
              'order': {'id': 'o1', 'number': 'AB1'},
              'payment': {'payment_id': 'p1', 'method': 'sadad', 'status': 'pending', 'action': 'none'},
            }, status: 201);
          },
        }),
      );
      // Keep providers alive for the test.
      h.container.listen(checkoutControllerProvider, (_, _) {});
      await h.container.read(cartControllerProvider.future);
      final c = h.read(checkoutControllerProvider.notifier);
      await c.update((o) => o.copyWith(addressId: () => 'a1'));

      var s = h.read(checkoutControllerProvider);
      expect(s.quote!.quoteHash, 'h1');
      expect(s.quote!.isSplit, isTrue);
      expect(s.options.paymentMethod, PaymentMethod.sadad, reason: 'first available method is preselected');
      expect(s.canPlace, isTrue);

      final first = await c.placeOrder();
      expect(first, isNull);
      s = h.read(checkoutControllerProvider);
      expect(s.quoteChanged, isTrue);
      expect(s.previousTotal!.amount, '125.00');
      expect(s.quote!.totals.grandTotal.amount, '140.00');

      c.acknowledgeQuoteChange();
      final placed = await c.placeOrder();
      expect(placed!.orderNumber, 'AB1');
      expect(placeCalls, 2);
      final keys = h.adapter.where('POST', '/checkout/orders').map((r) => r.header('Idempotency-Key')).toList();
      expect(keys[0], isNot(keys[1]), reason: 'a changed quote is a new confirmation');
    });

    test('network failure retry reuses the same Idempotency-Key', () async {
      var fail = true;
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+974'}),
          'GET /cart': (_) => ok(_cart()),
          'GET /me/addresses': (_) => page([]),
          'POST /checkout/quote': (_) => ok(_quote('h1', '125.00')),
          'POST /checkout/orders': (_) => ok({
            'order': {'id': 'o1', 'number': 'AB1'},
          }, status: 201),
        }),
      );
      h.adapter.failConnection = (r) => r.path == '/checkout/orders' && fail;
      h.container.listen(checkoutControllerProvider, (_, _) {});
      await h.container.read(cartControllerProvider.future);
      final c = h.read(checkoutControllerProvider.notifier);
      await c.update((o) => o.copyWith(addressId: () => 'a1'));

      expect(await c.placeOrder(), isNull);
      expect((h.read(checkoutControllerProvider).placeError! as ApiException).kind, ApiErrorKind.offline);
      fail = false;
      expect(await c.placeOrder(), isNotNull);
      final keys = h.adapter.where('POST', '/checkout/orders').map((r) => r.header('Idempotency-Key')).toSet();
      expect(keys, hasLength(1));
    });

    test('unavailable payment method is corrected and loyalty clamped, then re-quoted', () async {
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+974'}),
          'GET /cart': (_) => ok(_cart()),
          'GET /me/addresses': (_) => page([]),
          'POST /checkout/quote': (_) => ok(_quote('h1', '125.00', codAvailable: false)),
        }),
      );
      h.container.listen(checkoutControllerProvider, (_, _) {});
      await h.container.read(cartControllerProvider.future);
      final c = h.read(checkoutControllerProvider.notifier);
      await c.update(
        (o) => o.copyWith(addressId: () => 'a1', paymentMethod: () => PaymentMethod.cod, loyaltyPoints: 999),
      );
      final s = h.read(checkoutControllerProvider);
      expect(s.options.paymentMethod, PaymentMethod.sadad);
      expect(s.options.loyaltyPoints, 300);
      final lastBody = h.adapter.where('POST', '/checkout/quote').last.body as Map;
      expect(lastBody['payment_method'], 'sadad');
      expect(lastBody['loyalty_points'], 300);
    });
  });

  group('Cart', () {
    test('optimistic quantity update rolls back on error', () async {
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+974'}),
          'GET /cart': (_) => ok(_cart()),
          'PATCH /cart/items/i1': (_) => error(422, 'insufficient_stock', message: 'Only 1 left'),
        }),
      );
      h.container.listen(cartControllerProvider, (_, _) {});
      await h.container.read(cartControllerProvider.future);
      final ctrl = h.read(cartControllerProvider.notifier);
      final f = ctrl.updateQuantity('i1', 3);
      expect(h.read(cartControllerProvider).value!.items.single.quantity, 3, reason: 'optimistic');
      await expectLater(f, throwsA(isA<ApiException>()));
      expect(h.read(cartControllerProvider).value!.items.single.quantity, 1, reason: 'rolled back');
      expect(h.read(cartBusyItemsProvider), isEmpty);
    });

    test('guests have an empty cart and no requests are made', () async {
      final h = await TestHarness.create();
      final cart = await h.container.read(cartControllerProvider.future);
      expect(cart.isEmpty, isTrue);
      expect(h.adapter.requests, isEmpty);
    });

    test('issues block checkout', () async {
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+974'}),
          'GET /cart': (_) => ok(_cart(issue: 'unavailable_in_branch')),
        }),
      );
      final cart = await h.container.read(cartControllerProvider.future);
      expect(cart.hasIssues, isTrue);
      expect(cart.canCheckout, isFalse);
    });
  });

  group('Branch switching', () {
    test('persists, re-syncs the server cart and re-queries the catalog', () async {
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+974'}),
          'GET /cart': (_) => ok(_cart()),
          'PUT /cart': (r) => ok(_cart(branch: (r.body as Map)['branch_id'] as String)),
          'GET /products/p1': (r) => ok({...productCard('p1'), 'variants': []}),
        }),
      );
      h.container.listen(cartControllerProvider, (_, _) {});
      h.container.listen(productDetailProvider('p1'), (_, _) {});
      await h.container.read(cartControllerProvider.future);
      await h.container.read(productDetailProvider('p1').future);

      await h.read(preferredBranchProvider.notifier).select(const BranchRef(id: 'br2', name: 'Lusail'));
      await Future<void>.delayed(const Duration(milliseconds: 10));
      await h.container.read(productDetailProvider('p1').future);

      expect(h.read(appPreferencesProvider).preferredBranch!['id'], 'br2');
      expect(h.adapter.where('PUT', '/cart').single.body, {'branch_id': 'br2'});
      expect(h.read(cartControllerProvider).value!.branchId, 'br2');
      final productCalls = h.adapter.where('GET', '/products/p1');
      expect(productCalls.last.query['branch_id'], 'br2');
      expect(productCalls.length, greaterThanOrEqualTo(2));
    });
  });

  group('Session', () {
    test('sign-in stores tokens; logout unregisters, revokes and clears', () async {
      final h = await TestHarness.create(
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+97455123456', 'locale': 'en'}),
          'POST /auth/logout': (_) => ok({'logged_out': true}),
        }),
      );
      final session = h.read(sessionControllerProvider.notifier);
      expect(h.read(isAuthenticatedProvider), isFalse);
      await session.signIn(
        Authenticated(
          user: const AppUser(id: 'u1', name: 'Noor', phone: '+97455123456', locale: 'en'),
          tokens: AuthTokens.fromJson(tokens('A', 'R')),
        ),
      );
      expect(h.read(isAuthenticatedProvider), isTrue);
      expect(h.tokenStore.cached!.accessToken, 'A');
      await session.logout();
      expect(h.read(isAuthenticatedProvider), isFalse);
      expect(h.tokenStore.cached, isNull);
      expect(h.adapter.where('POST', '/auth/logout'), hasLength(1));
    });

    test('expiry signal signs the user out', () async {
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+974'}),
        }),
      );
      expect(h.read(isAuthenticatedProvider), isTrue);
      h.read(sessionExpirySignalProvider).fire();
      await Future<void>.delayed(Duration.zero);
      final s = h.read(sessionControllerProvider);
      expect(s.isAuthenticated, isFalse);
      expect(s.expired, isTrue);
    });
  });

  group('Live lot', () {
    Map<String, dynamic> lot(int seq, String current, {String? my}) => {
      'id': 'l1',
      'lot_number': 3,
      'title': 'Brass lamp',
      'status': 'open',
      'current_price': money(current),
      'min_next_bid': money('${int.parse(current.split('.').first) + 50}.00'),
      'bid_count': seq,
      'last_sequence': seq,
      'ends_at': '2030-01-01T00:00:00+03:00',
      'server_time': DateTime.now().toIso8601String(),
      'my_status': my,
      'is_watching': false,
    };

    test('duplicates are dropped, order applied, gaps replayed from /events', () async {
      final events = StreamController<String>.broadcast();
      final sent = <String>[];
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+974'}),
          'GET /auctions/lots/l1': (_) => ok(lot(5, '500.00', my: 'leading')),
          'GET /auctions/lots/l1/bids': (_) => page([]),
          'GET /auctions/lots/l1/events': (r) {
            final after = int.parse(r.query['after_sequence'] as String);
            // The server only has history from sequence 7 (6 arrives live).
            if (after < 6) return ok(<Object?>[]);
            return ok([
              for (final (seq, amt) in [(7, '600.00'), (8, '650.00')])
                if (seq > after)
                  {
                    'sequence': seq,
                    'type': 'bid.accepted',
                    'lot': lot(seq, amt),
                    'bid': {'bid_number': seq, 'amount': money(amt), 'bidder': 'Bidder 4'},
                  },
            ]);
          },
        }),
        overrides: [
          pusherClientProvider.overrideWith((ref) {
            final client = PusherClient(
              uri: Uri.parse('wss://ws.test/app/key'),
              connector: (_) => _FakeSocket(events.stream, sent.add),
            );
            ref.onDispose(client.dispose);
            return client;
          }),
        ],
      );
      final sub = h.container.listen(lotControllerProvider('l1'), (_, _) {});
      addTearDown(sub.close);
      await h.container.read(lotControllerProvider('l1').future);

      events.add(
        jsonEncode({
          'event': 'pusher:connection_established',
          'data': jsonEncode({'socket_id': '1.2', 'activity_timeout': 30}),
        }),
      );
      await Future<void>.delayed(const Duration(milliseconds: 10));
      expect(sent.any((m) => m.contains('pusher:subscribe') && m.contains('auction-lot.l1')), isTrue);

      void push(int seq, String amount, {String event = 'bid.accepted'}) => events.add(
        jsonEncode({
          'event': event,
          'channel': 'auction-lot.l1',
          'data': jsonEncode({
            'sequence': seq,
            'lot': lot(seq, amount),
            'bid': {'bid_number': seq, 'amount': money(amount), 'bidder': 'Bidder 4'},
          }),
        }),
      );

      push(6, '550.00');
      await Future<void>.delayed(const Duration(milliseconds: 10));
      var s = h.read(lotControllerProvider('l1')).value!;
      expect(s.lot.lastSequence, 6);
      expect(s.lot.currentPrice!.amount, '550.00');
      expect(s.lot.myStatus, 'leading', reason: 'public payload never overwrites my_* fields');
      expect(s.bids.first.bidNumber, 6);

      push(6, '550.00'); // duplicate
      push(5, '500.00'); // stale
      await Future<void>.delayed(const Duration(milliseconds: 10));
      expect(h.read(lotControllerProvider('l1')).value!.bids, hasLength(1));

      push(9, '700.00'); // gap: 7 and 8 missing → replay
      await Future<void>.delayed(const Duration(milliseconds: 50));
      s = h.read(lotControllerProvider('l1')).value!;
      expect(h.adapter.where('GET', '/auctions/lots/l1/events').last.query['after_sequence'], '6');
      expect(s.lot.lastSequence, 8);
      expect(s.lot.currentPrice!.amount, '650.00');

      // Server ping is answered.
      events.add(jsonEncode({'event': 'pusher:ping', 'data': '{}'}));
      await Future<void>.delayed(const Duration(milliseconds: 10));
      expect(sent.any((m) => m.contains('pusher:pong')), isTrue);
      await events.close();
    });

    test('countdown offset derives from server_time', () {
      final serverAhead = DateTime.now().add(const Duration(minutes: 2));
      final offset = offsetFromServerTime(serverAhead);
      expect(offset.inSeconds, inInclusiveRange(119, 120));
      final state = LotViewState(
        lot: Lot.fromJson({
          'id': 'l',
          'lot_number': 1,
          'title': 't',
          'status': 'open',
          'min_next_bid': money('1'),
          'last_sequence': 1,
          'ends_at': DateTime.now().add(const Duration(minutes: 5)).toIso8601String(),
        }),
        bids: const [],
        serverOffset: offset,
      );
      expect(state.remaining!.inMinutes, inInclusiveRange(2, 3), reason: 'device clock skew does not matter');
    });

    test('bid placement is idempotent across a network retry', () async {
      var fail = true;
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: routes({
          'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+974'}),
          'GET /auctions/lots/l1': (_) => ok(lot(5, '500.00')),
          'GET /auctions/lots/l1/bids': (_) => page([]),
          'POST /auctions/lots/l1/bids': (_) => ok({
            'bid': {'bid_number': 6, 'amount': money('550.00'), 'bidder': 'You'},
            'lot': lot(6, '550.00', my: 'leading'),
          }),
        }),
        overrides: [
          pusherClientProvider.overrideWith(
            (ref) => PusherClient(
              uri: Uri.parse('wss://x/app/k'),
              connector: (_) => _FakeSocket(const Stream.empty(), (_) {}),
            ),
          ),
        ],
      );
      h.adapter.failConnection = (r) => r.method == 'POST' && fail;
      final sub = h.container.listen(lotControllerProvider('l1'), (_, _) {});
      addTearDown(sub.close);
      await h.container.read(lotControllerProvider('l1').future);
      final ctrl = h.read(lotControllerProvider('l1').notifier);
      await expectLater(ctrl.placeBid('550'), throwsA(isA<ApiException>()));
      fail = false;
      await ctrl.placeBid('550');
      final keys = h.adapter.where('POST', '/auctions/lots/l1/bids').map((r) => r.header('Idempotency-Key')).toSet();
      expect(keys, hasLength(1));
      final s = h.read(lotControllerProvider('l1')).value!;
      expect(s.lot.myStatus, 'leading');
      expect(s.bids.first.isMine, isTrue);
    });
  });

  test('product list notifier pages and stops at last page', () async {
    final h = await TestHarness.create(
      handler: (r) {
        final p = int.parse(r.query['page'] as String? ?? '1');
        return page([productCard('p$p')], current: p, last: 2);
      },
    );
    const q = ProductQuery();
    final sub = h.container.listen(productListProvider(q), (_, _) {});
    addTearDown(sub.close);
    await h.container.read(productListProvider(q).future);
    await h.read(productListProvider(q).notifier).loadMore();
    final s = h.read(productListProvider(q)).value!;
    expect(s.items.map((e) => e.id), ['p1', 'p2']);
    expect(s.hasMore, isFalse);
    await h.read(productListProvider(q).notifier).loadMore();
    expect(h.adapter.requests, hasLength(2));
  });
}

/// Minimal WebSocketChannel double backed by a stream + sink callback.
class _FakeSocket extends StreamChannelMixin<dynamic> implements WebSocketChannel {
  _FakeSocket(this._incoming, this._onSend);
  final Stream<String> _incoming;
  final void Function(String) _onSend;

  @override
  Stream<dynamic> get stream => _incoming;

  @override
  WebSocketSink get sink => _FakeSink(_onSend);

  @override
  String? get protocol => null;
  @override
  int? get closeCode => null;
  @override
  String? get closeReason => null;
  @override
  Future<void> get ready => Future.value();
}

class _FakeSink implements WebSocketSink {
  _FakeSink(this._onSend);
  final void Function(String) _onSend;
  @override
  void add(dynamic data) => _onSend(data as String);
  @override
  Future<void> close([int? closeCode, String? closeReason]) async {}
  @override
  void addError(Object error, [StackTrace? stackTrace]) {}
  @override
  Future<void> addStream(Stream<dynamic> stream) async {}
  @override
  Future<void> get done => Future.value();
}
