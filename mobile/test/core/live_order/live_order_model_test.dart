import 'dart:ui' show Color;

import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:flutter_test/flutter_test.dart';

/// The push the server sends today (no phase, no version): the app must keep reading it.
const _legacyPush = <String, dynamic>{
  'kind': 'order_progress',
  'event': 'order.updated',
  'order_id': 'o1',
  'number': 'A-100',
  'step': '2',
  'total': '4',
  'step_label': 'On the way',
  'done': '0',
  'deep_link': '/orders/o1',
  'title': 'Order #A-100',
  'body': 'Your order is on the way',
};

LiveOrder parse(Map<String, dynamic> data) => LiveOrder.tryParse(data)!;

void main() {
  group('parsing a push', () {
    test('the current server payload (step only) becomes a delivery on the way', () {
      final o = parse(_legacyPush);
      expect(o.orderId, 'o1');
      expect(o.number, 'A-100');
      expect(o.phase, LiveOrderPhase.onTheWay);
      expect(o.step, 2);
      expect(o.pickup, isFalse);
      expect(o.seq, 0);
      expect(o.title, 'Order #A-100');
      expect(o.body, 'Your order is on the way');
      expect(o.location, '/orders/o1');
    });

    test('the full payload keeps every field', () {
      final o = parse({
        'kind': 'order_progress',
        'order_id': 'o9',
        'number': '77',
        'type': 'delivery',
        'step': 2,
        'total': 4,
        'sub': '0.6',
        'phase': 'on_the_way',
        'title': 'Order 77',
        'body': 'On its way',
        'eta_label': 'Arrives at 9:20pm-9:35pm',
        'eta_from': '2026-11-13T21:20:00+03:00',
        'eta_to': '2026-11-13T18:35:00Z',
        'driver_name': 'Ahmed',
        'driver_phone': '+97455500000',
        'vehicle': 'Motorbike',
        'seq': '12',
        'updated_at': '2026-11-13T18:00:00Z',
        'deep_link': 'albahar://orders/o9',
        'done': '0',
        'brand': {'primary': '#8A1538', 'accent': '#B3254F'},
      });
      expect(o.phase, LiveOrderPhase.onTheWay);
      expect(o.sub, 0.6);
      expect(o.effectiveSub, 0.6);
      expect(o.etaLabel, 'Arrives at 9:20pm-9:35pm');
      expect(o.etaFrom!.toUtc(), DateTime.utc(2026, 11, 13, 18, 20));
      expect(o.etaTo!.toUtc(), DateTime.utc(2026, 11, 13, 18, 35));
      expect(o.driverName, 'Ahmed');
      expect(o.driverPhone, '+97455500000');
      expect(o.vehicle, 'Motorbike');
      expect(o.seq, 12);
      expect(o.updatedAt!.toUtc(), DateTime.utc(2026, 11, 13, 18));
      expect(o.location, '/orders/o9');
      expect(o.brandPrimary, const Color(0xFF8A1538));
      expect(o.brandAccent, const Color(0xFFB3254F));
    });

    test('FCM sends brand as a JSON string, or as flat keys', () {
      expect(parse({'order_id': 'o', 'brand': '{"primary":"#112233","accent":"#445566"}'}).brandPrimary, const Color(0xFF112233));
      expect(parse({'order_id': 'o', 'brand': '{"primary":"#112233","accent":"#445566"}'}).brandAccent, const Color(0xFF445566));
      expect(parse({'order_id': 'o', 'brand_primary': '#ABCDEF'}).brandPrimary, const Color(0xFFABCDEF));
      expect(parse({'order_id': 'o', 'brand_accent': 'ABCDEF'}).brandAccent, const Color(0xFFABCDEF));
    });

    test('missing and malformed fields fall back safely', () {
      expect(LiveOrder.tryParse({'kind': 'order_progress', 'step': '1'}), isNull, reason: 'no order id');
      expect(LiveOrder.tryParse({'order_id': ''}), isNull);
      expect(LiveOrder.tryParse({'order_id': 'o', 'kind': 'something_else'}), isNull);

      final o = parse({
        'order_id': 7,
        'step': 'x',
        'total': 'many',
        'sub': 'NaN',
        'seq': '-4',
        'eta_from': 'soon',
        'brand': '{broken',
        'brand_primary': 'red',
        'title': {'nested': true},
        'driver_name': '   ',
        'deep_link': 'https://evil.example/phish',
      });
      expect(o.orderId, '7');
      expect(o.number, '7');
      expect(o.phase, LiveOrderPhase.placed);
      expect(o.sub, isNull);
      expect(o.effectiveSub, LiveOrder.defaultSub);
      expect(o.seq, 0);
      expect(o.etaFrom, isNull);
      expect(o.brandPrimary, isNull);
      expect(o.title, isNull);
      expect(o.driverName, isNull);
      expect(o.location, '/orders/7', reason: 'a link that is not the app\'s own falls back to the order page');
    });

    test('long text is cut and sub-progress is clamped', () {
      final o = parse({'order_id': 'o', 'title': 'x' * 900, 'sub': 7, 'phase': 'preparing'});
      expect(o.title!.length, 200);
      expect(o.sub, 1.0);
      expect(parse({'order_id': 'o', 'sub': -3}).sub, 0.0);
    });
  });

  group('step and sub-progress mapping', () {
    test('a step maps to a phase; pickup orders end at ready and collected', () {
      LiveOrderPhase phase(int step, {bool pickup = false, bool done = false}) => parse({
        'order_id': 'o',
        'step': step,
        if (pickup) 'type': 'pickup',
        if (done) 'done': '1',
      }).phase;
      expect(phase(0), LiveOrderPhase.placed);
      expect(phase(1), LiveOrderPhase.preparing);
      expect(phase(2), LiveOrderPhase.onTheWay);
      expect(phase(2, pickup: true), LiveOrderPhase.ready);
      expect(phase(3), LiveOrderPhase.delivered);
      expect(phase(3, pickup: true), LiveOrderPhase.collected);
      expect(phase(1, done: true), LiveOrderPhase.delivered, reason: 'done wins');
      expect(phase(99), LiveOrderPhase.delivered);
      expect(phase(-5), LiveOrderPhase.placed);
    });

    test('the phase decides the step when both are sent', () {
      expect(parse({'order_id': 'o', 'phase': 'preparing', 'step': 3}).step, 1);
      expect(parse({'order_id': 'o', 'phase': 'delivered'}).step, 3);
    });

    test('a server that counts five stages is scaled onto the four-stage bar', () {
      expect(parse({'order_id': 'o', 'step': 4, 'total': 5}).step, 3);
      expect(parse({'order_id': 'o', 'step': 2, 'total': 5}).phase, LiveOrderPhase.onTheWay);
      expect(parse({'order_id': 'o', 'step': 1, 'total': 3}).phase, LiveOrderPhase.onTheWay);
    });

    test('sub-progress defaults to a first hint of progress, ready is half full, an ended order is full', () {
      expect(parse({'order_id': 'o', 'phase': 'placed'}).effectiveSub, LiveOrder.defaultSub);
      expect(parse({'order_id': 'o', 'phase': 'ready'}).effectiveSub, LiveOrder.defaultSubReady);
      expect(parse({'order_id': 'o', 'phase': 'delivered', 'sub': 0.2}).effectiveSub, 1.0);
      expect(parse({'order_id': 'o', 'phase': 'on_the_way', 'sub': 0.8}).effectiveSub, 0.8);
    });

    test('a stopped order keeps the stage it reached', () {
      final o = parse({'order_id': 'o', 'phase': 'cancelled', 'step': 2});
      expect(o.isFinal, isTrue);
      expect(o.isStopped, isTrue);
      expect(o.step, 2);
      expect(parse({'order_id': 'o', 'phase': 'failed'}).step, 0);
      expect(parse({'order_id': 'o', 'phase': 'failed', 'step': 9}).step, 3);
    });
  });

  group('order of updates', () {
    LiveOrder o(Map<String, dynamic> extra) => parse({'order_id': 'o1', ...extra});

    test('versions decide: older and equal are dropped, newer wins even when it looks like a step back', () {
      final prev = o({'seq': 3, 'step': 1});
      expect(o({'seq': 2, 'step': 2}).supersedes(prev), isFalse);
      expect(o({'seq': 3, 'step': 2}).supersedes(prev), isFalse);
      expect(o({'seq': 4, 'step': 0}).supersedes(prev), isTrue);
    });

    test('without a version the timestamp decides', () {
      final prev = o({'updated_at': '2026-11-13T10:00:00Z', 'step': 1});
      expect(o({'updated_at': '2026-11-13T09:59:00Z', 'step': 2}).supersedes(prev), isFalse);
      expect(o({'updated_at': '2026-11-13T10:01:00Z', 'step': 2}).supersedes(prev), isTrue);
    });

    test('unversioned updates never go backwards, never repeat, never reopen a finished order', () {
      final prev = o({'step': 1});
      expect(o({'step': 1}).supersedes(prev), isFalse, reason: 'duplicate');
      expect(o({'step': 0}).supersedes(prev), isFalse, reason: 'backwards');
      expect(o({'step': 2}).supersedes(prev), isTrue);
      expect(o({'phase': 'cancelled'}).supersedes(prev), isTrue);
      final done = o({'step': 3, 'done': '1'});
      expect(o({'step': 1}).supersedes(done), isFalse);
      expect(o({'step': 2}).supersedes(null), isTrue);
    });

    test('a driver or estimate change at the same stage is a new state', () {
      final prev = o({'step': 2});
      expect(o({'step': 2, 'eta_label': 'Arrives at 9pm'}).supersedes(prev), isTrue);
      expect(o({'step': 2, 'sub': 0.9}).supersedes(prev), isTrue);
    });
  });

  group('status codes', () {
    String? phaseOf(String status, {bool pickup = false, String? delivery}) =>
        LiveOrder.phaseForStatus(status, pickup: pickup, deliveryStatus: delivery)?.wire;

    test('order status ladder', () {
      expect(phaseOf('pending_payment'), 'placed');
      expect(phaseOf('confirmed'), 'placed');
      expect(phaseOf('processing'), 'preparing');
      expect(phaseOf('partially_fulfilled'), 'preparing');
      expect(phaseOf('out_for_delivery'), 'on_the_way');
      expect(phaseOf('ready_for_pickup', pickup: true), 'ready');
      expect(phaseOf('completed'), 'delivered');
      expect(phaseOf('completed', pickup: true), 'collected');
      expect(phaseOf('cancelled'), 'cancelled');
    });

    test('delivery status overrides the order status', () {
      expect(phaseOf('out_for_delivery', delivery: 'delivered'), 'delivered');
      expect(phaseOf('out_for_delivery', delivery: 'failed'), 'failed');
      expect(phaseOf('processing', delivery: 'picked_up'), 'on_the_way');
      expect(phaseOf('completed', delivery: 'out_for_delivery'), 'delivered');
    });

    test('statuses that do not belong on the bar are ignored', () {
      expect(phaseOf('cancellation_requested'), isNull);
      expect(phaseOf('returned'), isNull);
      expect(phaseOf('partially_cancelled'), isNull);
      expect(phaseOf('nonsense'), isNull);
    });

    test('fromStatus guesses pickup from a ready_for_pickup order and drops the driver of other phases', () {
      final ready = LiveOrder.fromStatus(orderId: 'o', number: '1', status: 'ready_for_pickup')!;
      expect(ready.pickup, isTrue);
      expect(ready.phase, LiveOrderPhase.ready);
      final onWay = LiveOrder.fromStatus(
        orderId: 'o',
        number: '1',
        status: 'out_for_delivery',
        driverName: 'Ahmed',
        etaTo: DateTime.utc(2026, 11, 13, 18, 35),
      )!;
      expect(onWay.driverName, 'Ahmed');
      expect(onWay.etaTo, isNotNull);
      final placed = LiveOrder.fromStatus(orderId: 'o', number: '1', status: 'confirmed', driverName: 'Ahmed')!;
      expect(placed.driverName, isNull);
      expect(LiveOrder.fromStatus(orderId: 'o', number: '1', status: 'returned'), isNull);
    });
  });

  group('what is sent to the platform', () {
    test('the channel map carries the push keys, the normalised stage and the colour as an int', () {
      final o = parse({..._legacyPush, 'brand_primary': '#8A1538', 'sub': '0.4', 'seq': '5'});
      final map = o.copyWith(headline: 'Arrives at 9pm', subtitle: 'On its way').toChannelMap(locale: 'ar', force: true);
      expect(map['order_id'], 'o1');
      expect(map['type'], 'delivery');
      expect(map['phase'], 'on_the_way');
      expect(map['step'], 2);
      expect(map['total'], 4);
      expect(map['sub'], 0.4);
      expect(map['seq'], 5);
      expect(map['deep_link'], '/orders/o1');
      expect(map['brand_primary'], 0xFF8A1538);
      expect(map['headline'], 'Arrives at 9pm');
      expect(map['subtitle'], 'On its way');
      expect(map['locale'], 'ar');
      expect(map['force'], isTrue);
    });

    test('the plain progress data keeps the keys the old notification reads', () {
      final data = parse(_legacyPush).copyWith(headline: 'H', subtitle: 'S').toLegacyProgress(stageLabel: 'On the way');
      expect(data, {
        'kind': 'order_progress',
        'event': 'order.updated',
        'order_id': 'o1',
        'number': 'A-100',
        'step': '2',
        'total': '4',
        'step_label': 'On the way',
        'done': '0',
        'deep_link': '/orders/o1',
        'title': 'H',
        'body': 'S',
      });
      expect(parse({..._legacyPush, 'step': '3', 'done': '1'}).toLegacyProgress()['done'], '1');
    });
  });
}
