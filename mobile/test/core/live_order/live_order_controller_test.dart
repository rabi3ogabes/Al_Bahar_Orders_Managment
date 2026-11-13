import 'dart:async';
import 'dart:ui' show Color;

import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:albahar_customer/core/live_order/live_order_controller.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:fake_async/fake_async.dart';

import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/fakes.dart';
import 'live_order_fakes.dart';

final _t0 = DateTime.utc(2026, 11, 13, 18);

class _Rig {
  _Rig(this.prefs) {
    controller = LiveOrderController(
      platform: platform,
      api: api,
      prefs: prefs,
      localeCode: () => locale,
      fallback: local,
      now: () => now,
    );
  }

  final AppPreferences prefs;
  final platform = FakePlatform();
  final api = FakeLiveOrderApi();
  final local = FakeLocalNotifier();
  late final LiveOrderController controller;
  DateTime now = _t0;
  String locale = 'en';

  static Future<_Rig> create({Map<String, Object> saved = const {}}) async {
    SharedPreferences.setMockInitialValues(saved);
    return _Rig(AppPreferences(await SharedPreferences.getInstance()));
  }
}

PusherEvent event(Map<String, dynamic> data, {String name = 'order.updated'}) =>
    PusherEvent(channel: 'private-users.u1', event: name, data: data);

void main() {
  setUpAll(() => Future.wait([initializeDateFormatting('ar'), initializeDateFormatting('en')]));

  group('the order just placed', () {
    test('shows at once, with no server and no Firebase, then polls every minute until the order ends', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        expect(rig.api.activeCalls, 1, reason: 'sign-in reads the server once');
        expect(c.polling, isFalse, reason: 'nothing active, nothing to poll');

        unawaited(c.orderPlaced(orderId: 'o1', number: 'A-1'));
        async.flushMicrotasks();
        expect(rig.platform.phases, ['placed']);
        expect(rig.platform.last.order.headline, 'Order #A-1');
        expect(rig.platform.last.order.subtitle, 'Your order has been placed');
        expect(rig.platform.last.locale, 'en');
        expect(rig.prefs.liveOrders.keys, ['o1']);
        expect(c.polling, isTrue);

        // The estimate is asked for two seconds later (the server may need a moment).
        rig.api.active = [liveOrder('o1', seq: 1, etaLabel: 'Arrives at 9:20pm-9:35pm')];
        async.elapse(const Duration(seconds: 3));
        expect(rig.api.activeCalls, 2);
        expect(rig.platform.last.order.headline, 'Arrives at 9:20pm-9:35pm');

        // Then every minute.
        async.elapse(const Duration(seconds: 61));
        expect(rig.api.activeCalls, 3);
        async.elapse(const Duration(seconds: 60));
        expect(rig.api.activeCalls, 4);

        // Delivered: the card settles, polling stops, nothing is tracked.
        rig.api.active = [liveOrder('o1', seq: 9, phase: LiveOrderPhase.delivered, updatedAt: _t0)];
        async.elapse(const Duration(seconds: 60));
        expect(rig.platform.last.order.phase, LiveOrderPhase.delivered);
        expect(rig.platform.last.order.headline, startsWith('Delivered at'));
        expect(c.polling, isFalse);
        expect(c.trackedIds, isEmpty);
        expect(rig.prefs.liveOrders, isEmpty);
        final calls = rig.api.activeCalls;
        async.elapse(const Duration(minutes: 10));
        expect(rig.api.activeCalls, calls, reason: 'no polling once nothing is active');
      });
    });

    test('re-reads every 15 seconds while a driver is on the road, and every minute otherwise', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.orderPlaced(orderId: 'o1', number: 'A-1'));
        async.flushMicrotasks();

        // Preparing: the quiet pace.
        rig.api.active = [liveOrder('o1', seq: 1, phase: LiveOrderPhase.preparing)];
        async.elapse(const Duration(seconds: 3));
        final preparing = rig.api.activeCalls;
        async.elapse(const Duration(seconds: 30));
        expect(rig.api.activeCalls, preparing, reason: 'nothing to read more often while it is only being prepared');

        // The driver is on the road: the card follows closely.
        rig.api.active = [liveOrder('o1', seq: 2, phase: LiveOrderPhase.onTheWay)];
        async.elapse(const Duration(seconds: 31));
        final onTheWay = rig.api.activeCalls;
        async.elapse(const Duration(seconds: 61));
        expect(rig.api.activeCalls - onTheWay, greaterThanOrEqualTo(4), reason: 'every 15 s while on the road');

        // Delivered: the quick timer stops with the order.
        rig.api.active = [liveOrder('o1', seq: 9, phase: LiveOrderPhase.delivered, updatedAt: _t0)];
        async.elapse(const Duration(seconds: 16));
        expect(c.polling, isFalse);
      });
    });

    test('a pickup order is placed as a pickup', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.orderPlaced(orderId: 'p1', number: 'P-1', fulfillmentType: 'pickup'));
        async.flushMicrotasks();
        expect(rig.platform.last.order.pickup, isTrue);
      });
    });

    test('nothing is shown for a signed-out customer', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        unawaited(rig.controller.orderPlaced(orderId: 'o1', number: '1'));
        unawaited(rig.controller.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '1'}));
        async.flushMicrotasks();
        expect(rig.platform.shown, isEmpty);
      });
    });
  });

  group('pushes', () {
    Map<String, dynamic> push(String id, {int? seq, String step = '1', String done = '0'}) => {
      'kind': 'order_progress',
      'order_id': id,
      'number': 'N-$id',
      'step': step,
      'total': '4',
      'done': done,
      'title': 'Order #N-$id',
      'body': 'Server body',
      'seq': ?seq?.toString(),
    };

    test('are drawn, de-duplicated and kept in order by their version', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush(push('o1', seq: 5)));
        async.flushMicrotasks();
        expect(rig.platform.phases, ['preparing']);
        expect(rig.platform.last.order.subtitle, 'Server body', reason: 'server text is kept');

        // Same push again (foreground message and native receiver both saw it).
        unawaited(c.ingestPush(push('o1', seq: 5)));
        // An older one that arrived late.
        unawaited(c.ingestPush(push('o1', seq: 4, step: '0')));
        async.flushMicrotasks();
        expect(rig.platform.shown, hasLength(1));

        unawaited(c.ingestPush(push('o1', seq: 6, step: '2')));
        async.flushMicrotasks();
        expect(rig.platform.phases, ['preparing', 'on_the_way']);
      });
    });

    test('the current server payload has no version: it never goes back and never reopens a delivered order', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush(push('o1', step: '2')));
        unawaited(c.ingestPush(push('o1', step: '1')));
        unawaited(c.ingestPush(push('o1', step: '3', done: '1')));
        unawaited(c.ingestPush(push('o1', step: '2')));
        async.flushMicrotasks();
        expect(rig.platform.phases, ['on_the_way', 'delivered']);
        expect(c.trackedIds, isEmpty);
      });
    });

    test('two orders are followed side by side', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush(push('a', step: '1')));
        unawaited(c.ingestPush(push('b', step: '2')));
        async.flushMicrotasks();
        expect(c.active.map((o) => o.orderId), unorderedEquals(['a', 'b']));
        expect(c.trackedIds, {'a', 'b'});
        // One finishes: the other keeps polling.
        unawaited(c.ingestPush(push('a', step: '3', done: '1')));
        async.flushMicrotasks();
        expect(c.trackedIds, {'b'});
        expect(c.polling, isTrue);
      });
    });

    test('garbage is ignored', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush({'kind': 'order_progress'}));
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': ''}));
        async.flushMicrotasks();
        expect(rig.platform.shown, isEmpty);
      });
    });
  });

  group('fallback', () {
    test('a platform without the native renderer gets the plain progress notification', () async {
      final rig = await _Rig.create();
      rig.platform.result = LiveOrderShowResult.unsupported;
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.orderPlaced(orderId: 'o1', number: 'A-1'));
        async.flushMicrotasks();
        final data = rig.local.progress.single;
        expect(data['kind'], 'order_progress');
        expect(data['order_id'], 'o1');
        expect(data['step'], '0');
        expect(data['total'], '4');
        expect(data['done'], '0');
        expect(data['deep_link'], '/orders/o1');
        expect(data['title'], 'Order #A-1');
        expect(data['body'], 'Your order has been placed');
        expect(data['step_label'], 'Placed');
      });
    });

    test('nothing extra is drawn when the native renderer handled it, or when notifications are off', () async {
      final rig = await _Rig.create();
      for (final result in [
        LiveOrderShowResult.posted,
        LiveOrderShowResult.disabled,
        LiveOrderShowResult.dismissed,
        LiveOrderShowResult.skipped,
      ]) {
        rig.platform.result = result;
        fakeAsync((async) {
          final c = rig.controller..onSignedIn();
          async.flushMicrotasks();
          unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'x${result.name}', 'step': '1'}));
          async.flushMicrotasks();
        });
      }
      expect(rig.local.progress, isEmpty);
    });
  });

  group('resync with the server', () {
    test('updates the cards, and runs when the app resumes or the network is back', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        rig.api.active = [liveOrder('o1', seq: 2, phase: LiveOrderPhase.preparing)];
        // A resume right after sign-in is throttled; a few seconds later it goes through.
        c.onResumed();
        async.flushMicrotasks();
        expect(rig.api.activeCalls, 1);
        rig.now = rig.now.add(const Duration(seconds: 6));
        c.onResumed();
        async.flushMicrotasks();
        expect(rig.api.activeCalls, 2);
        expect(rig.platform.phases, ['preparing']);

        rig.api.active = [liveOrder('o1', seq: 3, phase: LiveOrderPhase.onTheWay)];
        c
          ..onOffline()
          ..onResumed();
        async.flushMicrotasks();
        expect(rig.api.activeCalls, 2, reason: 'offline: no request');
        c.onOnline();
        async.flushMicrotasks();
        expect(rig.api.activeCalls, 3);
        expect(rig.platform.phases, ['preparing', 'on_the_way']);
      });
    });

    test('a server error leaves the cards as they are and tries again on the next round', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '1'}));
        async.flushMicrotasks();
        rig.api.activeError = const ApiException(kind: ApiErrorKind.server, statusCode: 500);
        async.elapse(const Duration(seconds: 61));
        expect(rig.platform.shown, hasLength(1));
        expect(c.trackedIds, {'o1'});
        rig.api.activeError = null;
        rig.api.active = [liveOrder('o1', seq: 4, phase: LiveOrderPhase.onTheWay)];
        async.elapse(const Duration(seconds: 60));
        expect(rig.platform.phases, ['preparing', 'on_the_way']);
      });
    });

    test('every re-read carries a new version: the card is redrawn only when it says something new', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(
          c.ingestPush({
            'kind': 'order_progress',
            'order_id': 'o1',
            'phase': 'preparing',
            'seq': '100',
            'title': 'T',
            'body': 'B',
          }),
        );
        async.flushMicrotasks();
        expect(rig.platform.shown, hasLength(1));

        // The server restamps `seq` on every read.
        for (var i = 1; i <= 3; i++) {
          rig.api.active = [
            LiveOrder.tryParse({
              'order_id': 'o1',
              'phase': 'preparing',
              'seq': '${100 + i}',
              'title': 'T',
              'body': 'B',
            })!,
          ];
          async.elapse(const Duration(seconds: 61));
        }
        expect(rig.platform.shown, hasLength(1), reason: 'same words: no redraw');

        // An older push is still refused: the version moved on.
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'phase': 'on_the_way', 'seq': '102'}));
        async.flushMicrotasks();
        expect(rig.platform.shown, hasLength(1));

        rig.api.active = [
          LiveOrder.tryParse({'order_id': 'o1', 'phase': 'preparing', 'seq': '110', 'title': 'T', 'body': 'B2'})!,
        ];
        async.elapse(const Duration(seconds: 61));
        expect(rig.platform.shown, hasLength(2));
        expect(rig.platform.last.order.subtitle, 'B2');
      });
    });

    test('a finished order is not restamped by a later re-read', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(
          c.ingestPush({
            'kind': 'order_progress',
            'order_id': 'o1',
            'phase': 'delivered',
            'seq': '100',
            'updated_at': '2026-11-13T18:31:00Z',
          }),
        );
        async.flushMicrotasks();
        rig.api.active = [
          LiveOrder.tryParse({
            'order_id': 'o1',
            'phase': 'delivered',
            'seq': '900',
            'updated_at': '2026-11-13T18:50:00Z',
          })!,
        ];
        c.onOnline();
        async.flushMicrotasks();
        expect(rig.platform.shown, hasLength(1));
        expect(rig.platform.last.order.headline, contains('9:31'));
      });
    });

    test('an order that dropped out of the list is looked up: delivered becomes a final card', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '2'}));
        async.flushMicrotasks();
        rig.api.active = const [];
        rig.api.byId['o1'] = liveOrder('o1', phase: LiveOrderPhase.delivered);
        async.elapse(const Duration(seconds: 61));
        expect(rig.api.lookups, ['o1']);
        expect(rig.platform.last.order.phase, LiveOrderPhase.delivered);
        expect(c.trackedIds, isEmpty);
        expect(c.polling, isFalse);
      });
    });

    test('an order the server cannot place on the bar any more loses its card', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '1'}));
        async.flushMicrotasks();
        rig.api.byId['o1'] = null;
        async.elapse(const Duration(seconds: 61));
        expect(rig.platform.cancelled, ['o1']);
        expect(c.trackedIds, isEmpty);
      });
    });

    test('a failed lookup is retried, and a server that cannot say (null) leaves everything alone', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '1'}));
        async.flushMicrotasks();
        rig.api.lookupErrors['o1'] = const ApiException(kind: ApiErrorKind.offline);
        async.elapse(const Duration(seconds: 61));
        expect(rig.api.lookups, ['o1']);
        expect(c.trackedIds, {'o1'});
        rig.api.lookupErrors.clear();
        rig.api.active = null;
        async.elapse(const Duration(seconds: 60));
        expect(rig.api.lookups, ['o1'], reason: 'unknown list: no lookups');
      });
    });

    test('a finished order found long after the fact is not announced; one that ended moments ago is', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        rig.api.active = [
          liveOrder('old', seq: 1, phase: LiveOrderPhase.delivered, updatedAt: _t0.subtract(const Duration(hours: 5))),
          liveOrder(
            'fresh',
            seq: 1,
            phase: LiveOrderPhase.delivered,
            updatedAt: _t0.subtract(const Duration(minutes: 3)),
          ),
          liveOrder('undated', seq: 1, phase: LiveOrderPhase.cancelled),
        ];
        async.elapse(const Duration(seconds: 6));
        c.onOnline();
        async.flushMicrotasks();
        expect([for (final s in rig.platform.shown) s.order.orderId], ['fresh']);
        expect(rig.platform.cancelled, containsAll(['old', 'undated']));
      });
    });

    test('overlapping resyncs are merged into one more round', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        final before = rig.api.activeCalls;
        c.onOnline();
        c.onOnline();
        c.onOnline();
        async.flushMicrotasks();
        expect(rig.api.activeCalls - before, lessThanOrEqualTo(2));
      });
    });
  });

  group('realtime events', () {
    test('a status change advances the card, then the server is asked for the estimate', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final events = StreamController<PusherEvent>();
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        c.bindRealtime(events.stream);

        events.add(event({'order_id': 'o1', 'number': 'A-1', 'status': 'confirmed'}));
        async.flushMicrotasks();
        expect(rig.platform.phases, ['placed']);

        rig.api.active = [liveOrder('o1', seq: 8, phase: LiveOrderPhase.preparing, etaLabel: 'Arrives at 9pm')];
        events.add(event({'order_id': 'o1', 'number': 'A-1', 'status': 'processing', 'status_label': 'Processing'}));
        async.elapse(const Duration(seconds: 1));
        expect(rig.platform.phases, ['placed', 'preparing', 'preparing']);
        expect(rig.platform.last.order.headline, 'Arrives at 9pm');
        unawaited(events.close());
      });
    });

    test('a repeat of the stage on show keeps its estimate instead of wiping it', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final events = StreamController<PusherEvent>();
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        c.bindRealtime(events.stream);
        unawaited(
          c.ingestPush({
            'kind': 'order_progress',
            'order_id': 'o1',
            'phase': 'on_the_way',
            'seq': 4,
            'eta_label': 'Arrives at 9pm',
          }),
        );
        async.flushMicrotasks();
        events.add(event({'order_id': 'o1', 'status': 'out_for_delivery', 'delivery_status': 'picked_up'}));
        async.flushMicrotasks();
        expect(rig.platform.shown, hasLength(1));
        expect(rig.platform.last.order.headline, 'Arrives at 9pm');
        unawaited(events.close());
      });
    });

    test('a delivery status finishes the card; finished and unknown orders do not appear out of nowhere', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final events = StreamController<PusherEvent>();
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        c.bindRealtime(events.stream);
        events.add(event({'order_id': 'o1', 'status': 'out_for_delivery'}));
        async.flushMicrotasks();
        events.add(event({'order_id': 'o1', 'status': 'out_for_delivery', 'delivery_status': 'delivered'}));
        async.flushMicrotasks();
        expect(rig.platform.phases.take(2), ['on_the_way', 'delivered']);
        expect(c.trackedIds, isEmpty);

        // Other events and unusable ones.
        events
          ..add(event({'order_id': 'o2', 'status': 'returned'}))
          ..add(event({'status': 'confirmed'}))
          ..add(event({'order_id': 'o3', 'status': 'confirmed'}, name: 'auction.outbid'));
        async.flushMicrotasks();
        expect(rig.platform.shown.where((s) => s.order.orderId != 'o1'), isEmpty);
        unawaited(events.close());
      });
    });

    test('an event carrying the full progress payload is used as it is', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final events = StreamController<PusherEvent>();
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        c.bindRealtime(events.stream);
        events.add(
          event({'order_id': 'o1', 'phase': 'on_the_way', 'seq': 3, 'eta_label': 'Arrives at 9pm', 'sub': 0.5}),
        );
        async.flushMicrotasks();
        expect(rig.platform.last.order.etaLabel, 'Arrives at 9pm');
        expect(rig.platform.last.order.effectiveSub, 0.5);
        unawaited(events.close());
      });
    });

    test('rebinding replaces the old subscription, and null stops listening', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final first = StreamController<PusherEvent>.broadcast();
        final second = StreamController<PusherEvent>.broadcast();
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        c.bindRealtime(first.stream);
        c.bindRealtime(second.stream);
        async.flushMicrotasks();
        expect(first.hasListener, isFalse);
        expect(second.hasListener, isTrue);
        c.bindRealtime(null);
        async.flushMicrotasks();
        expect(second.hasListener, isFalse);
      });
    });
  });

  group('persistence and sign-out', () {
    test('a restart resumes the orders it was following: they are re-read, looked up if gone, polled', () async {
      final rig = await _Rig.create(saved: {'live_order_active': '{"o1":3,"o2":0}'});
      fakeAsync((async) {
        rig.api.active = [liveOrder('o1', seq: 4, phase: LiveOrderPhase.onTheWay)];
        rig.api.byId['o2'] = liveOrder('o2', phase: LiveOrderPhase.cancelled);
        final c = rig.controller..onSignedIn();
        expect(c.trackedIds, {'o1', 'o2'});
        expect(c.polling, isTrue);
        async.flushMicrotasks();
        expect(rig.platform.phases, ['on_the_way', 'cancelled']);
        expect(rig.api.lookups, ['o2']);
        expect(c.trackedIds, {'o1'});
        expect(rig.prefs.liveOrders.keys, ['o1']);
      });
    });

    test('a damaged saved list is ignored', () async {
      final rig = await _Rig.create(saved: {'live_order_active': 'not json'});
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        expect(c.trackedIds, isEmpty);
      });
    });

    test('signing out removes every card, forgets the orders and stops everything', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final events = StreamController<PusherEvent>.broadcast();
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        c.bindRealtime(events.stream);
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '1'}));
        async.flushMicrotasks();
        expect(c.polling, isTrue);

        unawaited(c.onSignedOut());
        async.flushMicrotasks();
        expect(rig.platform.cancelAllCalls, 1);
        expect(rig.prefs.liveOrders, isEmpty);
        expect(c.active, isEmpty);
        expect(c.polling, isFalse);
        expect(events.hasListener, isFalse);

        // Late messages for the old account are dropped.
        final shown = rig.platform.shown.length;
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '2'}));
        events.add(event({'order_id': 'o1', 'status': 'out_for_delivery'}));
        async.elapse(const Duration(minutes: 3));
        expect(rig.platform.shown.length, shown);
      });
    });

    test('a stale resync that finishes after sign-out draws nothing', () async {
      final rig = await _Rig.create();
      final slow = Completer<List<LiveOrder>?>();
      final c = LiveOrderController(
        platform: rig.platform,
        api: _SlowApi(slow.future),
        prefs: rig.prefs,
        localeCode: () => 'en',
        now: () => _t0,
      );
      c.onSignedIn();
      await Future<void>.delayed(Duration.zero);
      await c.onSignedOut();
      slow.complete([liveOrder('o1', seq: 1, phase: LiveOrderPhase.onTheWay)]);
      await Future<void>.delayed(Duration.zero);
      expect(rig.platform.shown, isEmpty);
      await c.dispose();
    });
  });

  group('presentation', () {
    test('language and brand changes draw the active cards again, forced past the native duplicate check', () async {
      final rig = await _Rig.create();
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '1'}));
        async.flushMicrotasks();
        expect(rig.platform.last.force, isFalse);
        expect(rig.platform.last.order.subtitle, 'We are preparing your order');

        rig.locale = 'ar';
        unawaited(c.refreshPresentation());
        async.flushMicrotasks();
        expect(rig.platform.shown, hasLength(2));
        expect(rig.platform.last.force, isTrue);
        expect(rig.platform.last.locale, 'ar');
        expect(rig.platform.last.order.subtitle, 'نقوم بتحضير طلبك');
        expect(rig.platform.last.order.headline, 'الطلب رقم o1');
      });
    });

    test('permission granted later draws the cards that could not be drawn before', () async {
      final rig = await _Rig.create();
      rig.platform.result = LiveOrderShowResult.disabled;
      fakeAsync((async) {
        final c = rig.controller..onSignedIn();
        async.flushMicrotasks();
        unawaited(c.orderPlaced(orderId: 'o1', number: '1'));
        async.flushMicrotasks();
        rig.platform.result = LiveOrderShowResult.posted;
        unawaited(c.onPermissionGranted());
        async.flushMicrotasks();
        expect(rig.platform.last.force, isTrue);
        expect(rig.platform.shown, hasLength(2));
      });
    });

    test('the brand colour from the dashboard tints the card unless the payload brings its own', () async {
      final rig = await _Rig.create();
      final c = LiveOrderController(
        platform: rig.platform,
        api: rig.api,
        prefs: rig.prefs,
        localeCode: () => 'en',
        brand: () => (primary: const Color(0xFF112233), accent: const Color(0xFF445566)),
        now: () => _t0,
      );
      fakeAsync((async) {
        c.onSignedIn();
        async.flushMicrotasks();
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'a', 'step': '1'}));
        unawaited(c.ingestPush({'kind': 'order_progress', 'order_id': 'b', 'step': '1', 'brand_primary': '#AA0000'}));
        async.flushMicrotasks();
        expect(rig.platform.shown[0].order.brandPrimary, const Color(0xFF112233));
        expect(rig.platform.shown[1].order.brandPrimary, const Color(0xFFAA0000));
      });
    });

    test('a platform that throws never breaks the order flow', () async {
      final rig = await _Rig.create();
      final c = LiveOrderController(
        platform: _ThrowingPlatform(),
        api: rig.api,
        prefs: rig.prefs,
        localeCode: () => 'en',
        now: () => _t0,
      );
      fakeAsync((async) {
        c.onSignedIn();
        async.flushMicrotasks();
        unawaited(c.orderPlaced(orderId: 'o1', number: '1'));
        async.flushMicrotasks();
        expect(c.trackedIds, {'o1'});
      });
    });
  });
}

class _SlowApi extends FakeLiveOrderApi {
  _SlowApi(this._answer);
  final Future<List<LiveOrder>?> _answer;

  @override
  Future<List<LiveOrder>?> activeProgress() => _answer;
}

class _ThrowingPlatform extends FakePlatform {
  @override
  Future<LiveOrderShowResult> show(
    LiveOrder order, {
    required String locale,
    bool force = false,
    bool arabicDigits = false,
  }) => throw StateError('boom');
}
