import 'dart:async';

import 'package:albahar_customer/core/config/app_config.dart';
import 'package:albahar_customer/core/realtime/live_gate.dart';
import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/realtime/realtime_settings.dart';
import 'package:albahar_customer/ops/ops_board.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:fake_async/fake_async.dart';
import 'package:flutter_test/flutter_test.dart';

DriverJob _job(String id, String status, {int seq = 1}) => DriverJob.fromJson({
  'id': id,
  'status': status,
  'seq': seq,
  'order_id': 'o-$id',
  'order_number': 'AB$id',
  'customer_name': 'Noor',
  'address': <String, dynamic>{},
  'payment_method': 'cod',
  'cod_amount': '0',
});

QueueOrder _row(String id, String status, {int seq = 1, String? driver}) => QueueOrder.fromJson({
  'fulfillment_id': id,
  'status': status,
  'seq': seq,
  'order_id': 'o-$id',
  'number': 'AB$id',
  'customer_name': 'Noor',
  'address': <String, dynamic>{},
  'payment_method': 'cod',
  'grand_total': '10',
  'items_count': 1,
  if (driver != null)
    'delivery': {
      'id': 'd-$id',
      'status': 'assigned',
      'driver': {'id': 'u1', 'name': driver},
    },
});

PusherEvent _event(Map<String, dynamic> data) => PusherEvent(channel: 'c', event: 'dispatch.updated', data: data);

void main() {
  group('SeqGate', () {
    test('ignores an older version, lets an equal or newer one through, accepts messages without a version', () {
      final gate = SeqGate();
      expect(gate.accept('o1', 5), isTrue);
      expect(gate.accept('o1', 4), isFalse); // stale
      expect(gate.accept('o1', 5), isTrue); // repeat of the same state
      expect(gate.accept('o1', 6), isTrue);
      expect(gate.accept('o1', null), isTrue);
      gate.seen('o2', 9);
      expect(gate.accept('o2', 8), isFalse); // a read already showed 9
    });
  });

  group('LiveSync', () {
    test('polls every 15 s only while the socket is down, reads on reconnect, resume and network back, folds a burst into one read', () {
      fakeAsync((async) {
        final states = StreamController<RealtimeState>.broadcast();
        var current = RealtimeState.connected;
        final reads = <LiveResync>[];
        var probes = 0;
        var reconnects = 0;
        final sync = LiveSync(
          readState: () => current,
          states: states.stream,
          resync: reads.add,
          probeSocket: () => probes++,
          reconnectSocket: () => reconnects++,
        )..start();
        expect(sync.link, LiveLink.live);
        async.elapse(const Duration(minutes: 2));
        expect(reads, isEmpty, reason: 'nothing is polled while the socket is connected');
        expect(sync.polling, isFalse);

        // A burst of messages is confirmed by ONE read.
        sync
          ..eventArrived()
          ..eventArrived()
          ..eventArrived();
        async.elapse(const Duration(seconds: 1));
        expect(reads, [LiveResync.event]);
        reads.clear();

        // The socket drops: a 15 s poll starts.
        current = RealtimeState.reconnecting;
        states.add(current);
        async.flushMicrotasks();
        expect(sync.link, LiveLink.reconnecting);
        async.elapse(const Duration(seconds: 31));
        expect(reads, [LiveResync.poll, LiveResync.poll]);
        reads.clear();

        // It comes back: one read at once, and the poll stops.
        current = RealtimeState.connected;
        states.add(current);
        async.flushMicrotasks();
        expect(reads, [LiveResync.reconnect]);
        expect(sync.link, LiveLink.live);
        async.elapse(const Duration(minutes: 1));
        expect(reads, [LiveResync.reconnect]);
        expect(sync.polling, isFalse);

        // Back from the background: the socket is checked and the screen reads again. Network back: reconnect and read.
        sync.resumed();
        expect(probes, 1);
        expect(reads.last, LiveResync.resume);
        sync.network(online: false);
        expect(sync.link, LiveLink.offline);
        sync.network(online: true);
        expect(reconnects, 1);
        expect(reads.last, LiveResync.network);

        // In the background nothing is polled even when the socket is down.
        reads.clear();
        sync.paused();
        current = RealtimeState.reconnecting;
        states.add(current);
        async.flushMicrotasks();
        async.elapse(const Duration(minutes: 1));
        expect(reads, isEmpty);
        sync.dispose();
        unawaited(states.close());
      });
    });
  });

  group('driver job overlay', () {
    JobPatch patch(String id, String status, int seq, {bool assigned = true}) => JobPatch.fromEvent(
      _event({'delivery_id': id, 'order_id': 'o', 'number': 'AB1', 'status': status, 'seq': seq, 'assigned': assigned}),
    )!;

    test('a newer message moves the job, a delivered job lands in the history, a stale message changes nothing', () {
      final active = [_job('d1', 'assigned'), _job('d2', 'accepted', seq: 4)];
      final moved = mergeJobs(
        active: active,
        history: const [],
        patches: {'d1': patch('d1', 'accepted', 2)},
        wantHistory: false,
      );
      expect(moved.map((j) => j.status), ['accepted', 'accepted']);

      final stale = mergeJobs(
        active: active,
        history: const [],
        patches: {'d2': patch('d2', 'assigned', 3)},
        wantHistory: false,
      );
      expect(stale.last.status, 'accepted');

      final delivered = {'d1': patch('d1', 'delivered', 3)};
      expect(mergeJobs(active: active, history: const [], patches: delivered, wantHistory: false).map((j) => j.id), [
        'd2',
      ]);
      expect(
        mergeJobs(
          active: active,
          history: [_job('d0', 'delivered')],
          patches: delivered,
          wantHistory: true,
        ).map((j) => j.id),
        ['d1', 'd0'],
      );
    });

    test('a job taken away leaves every list', () {
      final taken = {'d1': patch('d1', 'unassigned', 3, assigned: false)};
      expect(
        mergeJobs(active: [_job('d1', 'assigned')], history: const [], patches: taken, wantHistory: false),
        isEmpty,
      );
    });
  });

  group('branch queue overlay', () {
    PartPatch part(String id, String status, int seq, {String? driver}) => PartPatch.fromEvent(
      _event({
        'fulfillment_id': id,
        'fulfillment_type': 'delivery',
        'fulfillment_status': status,
        'kind': 'order',
        'order_id': 'o',
        'number': 'AB1',
        'seq': seq,
        if (driver != null) ...{
          'driver_id': 'u1',
          'driver_name': driver,
          'delivery_id': 'dd',
          'delivery_status': 'assigned',
        },
      }),
    )!;

    test('an order moves from one stage to the next at once and the counts follow', () {
      final byStage = {
        'new': [_row('f1', 'pending')],
        'preparing': <QueueOrder>[],
        'ready': [_row('f2', 'ready')],
      };
      final patches = {'f1': part('f1', 'accepted', 2), 'f2': part('f2', 'dispatched', 3, driver: 'Omar')};
      expect(mergeStage('new', byStage, patches), isEmpty);
      expect(mergeStage('preparing', byStage, patches).map((r) => r.fulfillmentId), ['f1']);
      expect(mergeStage('ready', byStage, patches), isEmpty);
      expect(mergeStage('out', byStage, patches).single.driverName, 'Omar');
      final o = StaffOverview.fromJson({
        'branch': {'id': 'b', 'name': 'B', 'dispatch_mode': 'manual'},
        'new': 1,
        'preparing': 0,
        'ready': 1,
        'out_for_delivery': 0,
        'failed': 0,
        'waiting_for_driver': 1,
        'drivers_on_duty': 1,
        'drivers_total': 1,
      });
      final adjusted = adjustOverview(o, byStage, patches);
      expect(
        [adjusted.fresh, adjusted.preparing, adjusted.ready, adjusted.out, adjusted.waitingForDriver],
        [0, 1, 0, 1, 0],
      );
    });

    test('a finished order leaves every stage; a stale message and a pickup part are ignored', () {
      final byStage = {
        'out': [_row('f1', 'out_for_delivery', seq: 5)],
      };
      expect(mergeStage('out', byStage, {'f1': part('f1', 'delivered', 6)}), isEmpty);
      expect(mergeStage('out', byStage, {'f1': part('f1', 'failed', 4)}).single.status, 'out_for_delivery');
      expect(
        PartPatch.fromEvent(
          _event({'fulfillment_id': 'f9', 'fulfillment_type': 'pickup', 'fulfillment_status': 'pending'}),
        ),
        isNull,
      );
    });
  });

  group('RealtimeSettingsLoader', () {
    const prod = AppConfig(
      flavor: Flavor.prod,
      apiBaseUrl: 'https://albahar.atharqatar.net/api/v1',
      reverbAppKey: 'albahar',
      reverbHost: 'albahar.atharqatar.net',
      reverbPort: 443,
      reverbUseTls: true,
      pushEnabled: false,
      mapTileUrlTemplate: 'x',
    );

    test('takes the key and the address from the server, so a release build needs no REVERB_* settings', () {
      final s = RealtimeSettingsLoader.parse({
        'enabled': true,
        'key': 'live-key',
        'poll_seconds': 15,
        'socket': {'scheme': 'wss', 'host': 'albahar.atharqatar.net', 'port': 443, 'path': '/app'},
      }, prod)!;
      expect(s.uri.toString(), startsWith('wss://albahar.atharqatar.net:443/app/live-key?'));
      expect(s.pollInterval, const Duration(seconds: 15));
      expect(RealtimeSettingsLoader.parse({'enabled': false, 'key': null}, prod), isNull);
    });

    test('falls back to the build when the server cannot be asked, and asks again next time', () async {
      var calls = 0;
      final loader = RealtimeSettingsLoader(
        config: prod,
        fetch: () async {
          calls++;
          throw StateError('down');
        },
      );
      final first = await loader.load();
      expect(first.fromServer, isFalse);
      expect(first.uri.host, 'albahar.atharqatar.net');
      await loader.load();
      expect(calls, 2);
    });

    test('a release build points its socket at the API host without any REVERB_* define', () {
      final c = AppConfig.fromEnvironment(flavor: Flavor.prod);
      expect(c.reverbHost, 'api.albahar.qa'); // the default API host: the socket follows whatever API_BASE_URL says
      expect(c.reverbUseTls, isTrue);
    });
  });
}
