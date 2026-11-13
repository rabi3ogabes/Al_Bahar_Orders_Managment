import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:albahar_customer/core/live_order/live_order_api.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

Future<(TestHarness, RestLiveOrderApi)> rig(FakeHandler handler) async {
  final h = await TestHarness.create(handler: handler, tokens: AuthTokens.fromJson(tokens('A', 'R')));
  return (h, RestLiveOrderApi(h.read(apiClientProvider), h.read(orderRepositoryProvider)));
}

Map<String, dynamic> summary(String id, String status, {String type = 'delivery'}) => {
  'id': id,
  'number': 'N-$id',
  'status': status,
  'fulfillment_type': type,
  'placed_at': '2026-11-13T17:00:00Z',
};

void main() {
  group('GET /orders/active-progress', () {
    test('reads the push-shaped payload, skipping rows it cannot use', () async {
      final (h, api) = await rig(
        routes({
          'GET /orders/active-progress': (r) => ok([
            {'order_id': 'o1', 'number': '1', 'phase': 'preparing', 'seq': 3, 'eta_label': 'Arrives at 9pm'},
            {'order_id': 'o2', 'type': 'pickup', 'phase': 'ready', 'seq': 1},
            {'number': 'no id'},
            'not a map',
          ]),
        }),
      );
      final list = (await api.activeProgress())!;
      expect(list.map((o) => o.orderId), ['o1', 'o2']);
      expect(list[0].phase, LiveOrderPhase.preparing);
      expect(list[0].etaLabel, 'Arrives at 9pm');
      expect(list[1].pickup, isTrue);
      expect(h.adapter.where('GET', '/orders/active-progress'), hasLength(1));
    });

    test('also reads a wrapped list, and an empty one means nothing is active', () async {
      final (_, wrapped) = await rig(
        routes({
          'GET /orders/active-progress': (r) => ok({
            'orders': [
              {'order_id': 'o1', 'step': 1},
            ],
          }),
        }),
      );
      expect((await wrapped.activeProgress())!.single.orderId, 'o1');
      final (_, empty) = await rig(routes({'GET /orders/active-progress': (r) => ok(<Object?>[])}));
      expect(await empty.activeProgress(), isEmpty);
    });

    test('a server error is raised (the controller keeps the cards and tries again)', () async {
      final (_, api) = await rig(routes({'GET /orders/active-progress': (r) => error(500, 'server_error')}));
      await expectLater(api.activeProgress(), throwsA(isA<ApiException>()));
    });
  });

  group('server without the endpoint', () {
    for (final status in [404, 405, 422]) {
      test('a $status builds the cards from the orders list, and does not ask for the endpoint again', () async {
        final (h, api) = await rig(
          routes({
            'GET /orders/active-progress': (r) => error(status, 'nope'),
            'GET /orders': (r) => page([
              summary('o1', 'confirmed'),
              summary('o2', 'processing'),
              summary('o3', 'ready_for_pickup', type: 'pickup'),
              summary('o4', 'cancellation_requested'),
            ]),
          }),
        );
        final first = (await api.activeProgress())!;
        expect(first.map((o) => (o.orderId, o.phase)), [
          ('o1', LiveOrderPhase.placed),
          ('o2', LiveOrderPhase.preparing),
          ('o3', LiveOrderPhase.ready),
        ]);
        expect(first[2].pickup, isTrue);
        expect(first[0].updatedAt, DateTime.utc(2026, 11, 13, 17));
        await api.activeProgress();
        expect(h.adapter.where('GET', '/orders/active-progress'), hasLength(1));
        expect(h.adapter.where('GET', '/orders'), hasLength(2));
      });
    }

    test('an order on the road gets its driver and estimate from the tracking endpoint', () async {
      final (_, api) = await rig(
        routes({
          'GET /orders/active-progress': (r) => error(404, 'not_found'),
          'GET /orders': (r) => page([summary('o1', 'out_for_delivery')]),
          'GET /orders/o1/tracking': (r) => ok({
            'tracking': true,
            'driver': {'name': 'Ahmed Ali', 'first_name': 'Ahmed', 'phone': '+97455500000', 'vehicle': 'Motorbike'},
            'eta': {'minutes': 12, 'updated_at': '2026-11-13T18:00:00Z'},
          }),
        }),
      );
      final o = (await api.activeProgress())!.single;
      expect(o.phase, LiveOrderPhase.onTheWay);
      expect(o.driverName, 'Ahmed');
      expect(o.driverPhone, '+97455500000');
      expect(o.vehicle, 'Motorbike');
      expect(o.etaTo, DateTime.utc(2026, 11, 13, 18, 12));
    });

    test('a failing tracking call does not hide the order', () async {
      final (_, api) = await rig(
        routes({
          'GET /orders/active-progress': (r) => error(404, 'not_found'),
          'GET /orders': (r) => page([summary('o1', 'out_for_delivery')]),
          'GET /orders/o1/tracking': (r) => error(500, 'server_error'),
        }),
      );
      final o = (await api.activeProgress())!.single;
      expect(o.phase, LiveOrderPhase.onTheWay);
      expect(o.etaTo, isNull);
      expect(o.driverName, isNull);
    });

    test('at most three orders on the road are looked up', () async {
      final (h, api) = await rig(
        routes({
          'GET /orders/active-progress': (r) => error(404, 'not_found'),
          'GET /orders': (r) => page([for (var i = 0; i < 5; i++) summary('o$i', 'out_for_delivery')]),
          'GET /orders/o0/tracking': (r) => ok({'tracking': true}),
          'GET /orders/o1/tracking': (r) => ok({'tracking': true}),
          'GET /orders/o2/tracking': (r) => ok({'tracking': true}),
        }),
      );
      expect(await api.activeProgress(), hasLength(5));
      expect(h.adapter.requests.where((r) => r.path.endsWith('/tracking')), hasLength(3));
    });
  });

  group('one order', () {
    Map<String, dynamic> order(String status, {Map<String, dynamic>? delivery, String type = 'delivery'}) => {
      'id': 'o1',
      'number': 'N-o1',
      'status': status,
      'fulfillment_type': type,
      'items': <Object?>[],
      'fulfillments': [
        {
          'id': 'f1',
          'number': 'F1',
          'branch': {'id': 'b1', 'name': 'Doha'},
          'type': type,
          'status': 'dispatched',
          'delivery': ?delivery,
        },
      ],
    };

    test('a delivered order is read as delivered', () async {
      final (_, api) = await rig(routes({'GET /orders/o1': (r) => ok(order('completed'))}));
      expect((await api.orderProgress('o1'))!.phase, LiveOrderPhase.delivered);
    });

    test('the delivery status, driver and estimate come from the fulfilment', () async {
      final (_, api) = await rig(
        routes({
          'GET /orders/o1': (r) => ok(
            order(
              'out_for_delivery',
              delivery: {
                'status': 'picked_up',
                'driver_name': 'Ahmed',
                'driver_phone': '+97455500000',
                'eta': '2026-11-13T18:35:00Z',
              },
            ),
          ),
        }),
      );
      final o = (await api.orderProgress('o1'))!;
      expect(o.phase, LiveOrderPhase.onTheWay);
      expect(o.driverName, 'Ahmed');
      expect(o.etaTo, DateTime.utc(2026, 11, 13, 18, 35));
    });

    test('a failed delivery, a cancelled order and a pickup are told apart; an order off the bar is null', () async {
      Future<LiveOrder?> read(Map<String, dynamic> data) async {
        final (_, api) = await rig(routes({'GET /orders/o1': (r) => ok(data)}));
        return api.orderProgress('o1');
      }

      expect((await read(order('out_for_delivery', delivery: {'status': 'failed'})))!.phase, LiveOrderPhase.failed);
      expect((await read(order('cancelled')))!.phase, LiveOrderPhase.cancelled);
      expect((await read(order('completed', type: 'pickup')))!.phase, LiveOrderPhase.collected);
      expect(await read(order('returned')), isNull);
    });
  });
}
