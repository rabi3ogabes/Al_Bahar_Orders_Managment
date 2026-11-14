import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_live.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart' show testConfig;
import '../../ops/ops_test_kit.dart';

PusherEvent _ev(String event, Map<String, dynamic> data) => PusherEvent(channel: 'c', event: event, data: data);

Map<String, dynamic> _order({String id = 'o1', String status = 'processing', int seq = 3}) => {
  'id': id,
  'number': 'AB1',
  'status': status,
  'status_label': 'Processing',
  'seq': seq,
  'items': <Object>[],
  'fulfillments': [
    {
      'id': 'f1',
      'number': 'F1',
      'type': 'delivery',
      'status': 'preparing',
      'branch': {'id': 'b1', 'name': 'Al Markhiya'},
      'delivery': {'status': 'assigned'},
    },
  ],
  'can_cancel': true,
};

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  group('customer orders orchestrator', () {
    test(
      'a message changes the open order and the list at once; an older message never overwrites a newer one',
      () async {
        final events = StreamController<PusherEvent>();
        addTearDown(events.close);
        final container = ProviderContainer(
          overrides: [
            appConfigProvider.overrideWithValue(testConfig),
            userAuctionEventsProvider.overrideWith((ref) => events.stream),
            orderDetailProvider('o1').overrideWith((ref) async => Order.fromJson(_order())),
            activeOrdersProvider.overrideWith((ref) async => [OrderSummary.fromJson(_order())]),
          ],
        );
        addTearDown(container.dispose);
        container.listen(orderLiveProvider, (_, _) {});
        container.listen(orderDetailViewProvider('o1'), (_, _) {});
        await container.read(orderDetailProvider('o1').future);
      container.listen(activeOrdersViewProvider, (_, _) {});
      await container.read(activeOrdersProvider.future);
        expect(container.read(orderDetailViewProvider('o1')).value!.status, 'processing');

        events.add(
          _ev('order.updated', {
            'order_id': 'o1',
            'status': 'out_for_delivery',
            'status_label': 'On the way',
            'seq': 5,
            'fulfillments': [
              {'id': 'f1', 'status': 'dispatched', 'delivery_status': 'picked_up'},
            ],
          }),
        );
        await Future<void>.delayed(Duration.zero);
        final live = container.read(orderDetailViewProvider('o1')).value!;
        expect(
          [
            live.status,
            live.statusLabel,
            live.seq,
            live.fulfillments.single.status,
            live.fulfillments.single.delivery?.status,
            live.canCancel,
          ],
          ['out_for_delivery', 'On the way', 5, 'dispatched', 'picked_up', false],
        );
        expect(container.read(activeOrdersViewProvider).value!.single.status, 'out_for_delivery');

        // An older message (seq 4) arrives late: nothing changes.
        events.add(_ev('order.updated', {'order_id': 'o1', 'status': 'processing', 'seq': 4}));
        await Future<void>.delayed(Duration.zero);
        expect(container.read(orderDetailViewProvider('o1')).value!.status, 'out_for_delivery');

        // The order completes: it leaves the active list but stays readable.
        events.add(_ev('order.updated', {'order_id': 'o1', 'status': 'completed', 'seq': 6}));
        await Future<void>.delayed(Duration.zero);
        expect(container.read(activeOrdersViewProvider).value, isEmpty);
      },
    );
  });

  group('driver app orchestrator', () {
    test('a message moves the job at once and a stale one is ignored; a job taken away leaves the list', () async {
      final events = StreamController<PusherEvent>();
      addTearDown(events.close);
      final repo = FakeOps()..jobList = [FakeOps.job(id: 'd1', status: 'assigned')];
      final container = ProviderContainer(
        overrides: [
          appConfigProvider.overrideWithValue(testConfig),
          opsRepositoryProvider.overrideWithValue(repo),
          driverEventsProvider.overrideWith((ref) => events.stream),
        ],
      );
      addTearDown(container.dispose);
      container.listen(driverLiveProvider, (_, _) {});
      container.listen(driverJobsViewProvider(false), (_, _) {});
      await container.read(driverJobsProvider(false).future);
      expect(container.read(driverJobsViewProvider(false)).value!.single.status, 'assigned');

      events.add(
        _ev('dispatch.updated', {
          'delivery_id': 'd1',
          'status': 'accepted',
          'assigned': true,
          'seq': 4,
          'number': 'AB1',
        }),
      );
      await Future<void>.delayed(Duration.zero);
      expect(container.read(driverJobsViewProvider(false)).value!.single.status, 'accepted');

      events.add(
        _ev('dispatch.updated', {
          'delivery_id': 'd1',
          'status': 'assigned',
          'assigned': true,
          'seq': 3,
          'number': 'AB1',
        }),
      );
      await Future<void>.delayed(Duration.zero);
      expect(container.read(driverJobsViewProvider(false)).value!.single.status, 'accepted');

      events.add(
        _ev('dispatch.updated', {
          'delivery_id': 'd1',
          'status': 'unassigned',
          'assigned': false,
          'seq': 5,
          'number': 'AB1',
        }),
      );
      await Future<void>.delayed(Duration.zero);
      expect(container.read(driverJobsViewProvider(false)).value, isEmpty);
    });
  });

  group('branch staff app orchestrator', () {
    test('a message moves an order between stages and the counts at once; a new order is read in; a stale message is ignored', () async {
      final events = StreamController<PusherEvent>();
      addTearDown(events.close);
      final repo = FakeOps()
        ..queues = {
          'new': [FakeOps.order(id: 'f0', status: 'pending', number: 'AB100001')],
          'ready': [FakeOps.order(id: 'f1', status: 'ready', number: 'AB100009')],
        };
      final container = ProviderContainer(
        overrides: [
          appConfigProvider.overrideWithValue(testConfig),
          opsRepositoryProvider.overrideWithValue(repo),
          staffEventsProvider.overrideWith((ref) => events.stream),
        ],
      );
      addTearDown(container.dispose);
      container.listen(staffLiveProvider, (_, _) {});
      for (final s in kStaffStages) {
        container.listen(staffQueueViewProvider(s), (_, _) {});
        await container.read(staffQueueProvider(s).future);
      }
      container.listen(staffOverviewViewProvider, (_, _) {});
      await container.read(staffOverviewProvider.future);
      List<String> numbers(String stage) =>
          container.read(staffQueueViewProvider(stage)).value!.map((r) => r.number).toList();
      expect(numbers('new'), ['AB100001']);

      events.add(
        _ev('dispatch.updated', {
          'fulfillment_id': 'f0',
          'fulfillment_type': 'delivery',
          'fulfillment_status': 'accepted',
          'kind': 'order',
          'seq': 7,
          'number': 'AB100001',
          'order_id': 'o0',
        }),
      );
      await Future<void>.delayed(Duration.zero);
      expect(numbers('new'), isEmpty);
      expect(numbers('preparing'), ['AB100001']);
      expect(container.read(staffOverviewViewProvider).value!.fresh, 0);
      expect(container.read(staffOverviewViewProvider).value!.preparing, 1);

      events.add(
        _ev('dispatch.updated', {
          'fulfillment_id': 'f0',
          'fulfillment_type': 'delivery',
          'fulfillment_status': 'pending',
          'kind': 'order',
          'seq': 6,
          'number': 'AB100001',
        }),
      );
      await Future<void>.delayed(Duration.zero);
      expect(numbers('preparing'), ['AB100001']); // the stale message changed nothing

      final loadsBefore = repo.queueLoads;
      events.add(
        _ev('dispatch.updated', {
          'fulfillment_id': 'fNEW',
          'fulfillment_type': 'delivery',
          'fulfillment_status': 'pending',
          'kind': 'order',
          'seq': 1,
          'number': 'AB100777',
        }),
      );
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(
        repo.queueLoads,
        greaterThan(loadsBefore),
        reason: 'an order this phone has never seen is read from the server at once',
      );
    });
  });
}
