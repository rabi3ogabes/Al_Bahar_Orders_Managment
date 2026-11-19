import 'dart:async';

import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

const _phone = Variant('390x1400 en', Size(390, 1400), kEn);

PusherEvent _say(String id, String number, {int seq = 1, String type = 'delivery', String status = 'pending'}) =>
    PusherEvent(
      channel: 'private-branches.b1',
      event: 'dispatch.updated',
      data: {
        'fulfillment_id': id,
        'fulfillment_type': type,
        'fulfillment_status': status,
        'kind': 'order',
        'seq': seq,
        'number': number,
        'order_id': 'o-$id',
      },
    );

Future<ProviderContainer> _pump(
  WidgetTester tester,
  FakeOps repo, {
  Stream<PusherEvent>? events,
  List<Override> overrides = const [],
}) async {
  useVariant(tester, _phone);
  await tester.pumpWidget(
    opsHost(const StaffHome(), repo, variant: _phone, role: OpsRole.staff, staffEvents: events, overrides: overrides),
  );
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
}

void main() {
  group('a new order', () {
    testWidgets('shows one alert however many times its message repeats, and the order is read in without a refresh', (
      tester,
    ) async {
      final events = StreamController<PusherEvent>.broadcast();
      addTearDown(events.close);
      final repo = FakeOps()
        ..queues = {
          'new': [staffOrder(id: 'f0', number: 'AB100001')],
        };
      await _pump(tester, repo, events: events.stream);
      repo.queues = {
        'new': [
          staffOrder(id: 'f0', number: 'AB100001'),
          staffOrder(id: 'f9', number: 'AB100009', clock: clockJson('ok', 0)),
        ],
      };
      events
        ..add(_say('f9', 'AB100009'))
        ..add(_say('f9', 'AB100009', seq: 2));
      await settle(tester);
      expect(find.text('New order #AB100009'), findsOneWidget);
      expect(find.text('#AB100009'), findsOneWidget, reason: 'the card is there without pulling to refresh');
    });

    testWidgets('an online order that is not paid yet never alerts', (tester) async {
      final events = StreamController<PusherEvent>.broadcast();
      addTearDown(events.close);
      await _pump(tester, FakeOps(), events: events.stream);
      events.add(
        const PusherEvent(
          channel: 'private-branches.b1',
          event: 'dispatch.updated',
          data: {
            'fulfillment_id': 'f7',
            'fulfillment_type': 'delivery',
            'fulfillment_status': 'pending',
            'order_status': 'pending_payment',
            'kind': 'order',
            'seq': 1,
            'number': 'AB100007',
            'order_id': 'o7',
          },
        ),
      );
      await settle(tester);
      expect(find.text('New order #AB100007'), findsNothing);
    });

    testWidgets('an order this phone already shows never alerts again (a cancellation request on it, a replay)', (
      tester,
    ) async {
      final events = StreamController<PusherEvent>.broadcast();
      addTearDown(events.close);
      await _pump(tester, FakeOps(), events: events.stream);
      events.add(_say('f0', 'AB100001', seq: 9));
      await settle(tester);
      expect(find.text('New order #AB100001'), findsNothing);
    });

    testWidgets('it appears while another tab is open, and the alert shows there too', (tester) async {
      final events = StreamController<PusherEvent>.broadcast();
      addTearDown(events.close);
      final repo = FakeOps();
      await _pump(tester, repo, events: events.stream);
      await tester.tap(find.byType(Tab).at(2));
      await settle(tester);
      repo.queues = {
        'new': [staffOrder(id: 'f9', number: 'AB100009')],
      };
      events.add(_say('f9', 'AB100009'));
      await settle(tester);
      expect(find.text('New order #AB100009'), findsOneWidget);
      await tester.tap(find.byType(Tab).at(0));
      await settle(tester);
      expect(find.text('#AB100009'), findsOneWidget);
    });

    testWidgets('coming back to the app reads the lists again, so an order that arrived meanwhile is there', (
      tester,
    ) async {
      final repo = FakeOps()
        ..queues = {
          'new': [staffOrder(id: 'f0', number: 'AB100001')],
        };
      await _pump(tester, repo);
      repo.queues = {
        'new': [staffOrder(id: 'f0', number: 'AB100001'), staffOrder(id: 'f8', number: 'AB100008')],
      };
      tester.binding
        ..handleAppLifecycleStateChanged(AppLifecycleState.inactive)
        ..handleAppLifecycleStateChanged(AppLifecycleState.hidden)
        ..handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await settle(tester);
      tester.binding
        ..handleAppLifecycleStateChanged(AppLifecycleState.hidden)
        ..handleAppLifecycleStateChanged(AppLifecycleState.inactive)
        ..handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(tester);
      expect(find.text('#AB100008'), findsOneWidget);
    });
  });

  group('the chime', () {
    testWidgets('off until switched on; remembered; switched on, a new order plays it once', (tester) async {
      final events = StreamController<PusherEvent>.broadcast();
      addTearDown(events.close);
      final chime = CountingChime();
      final prefs = await memoryPrefs();
      final container = await _pump(
        tester,
        FakeOps(),
        events: events.stream,
        overrides: [prefs, staffChimeProvider.overrideWithValue(chime)],
      );
      expect(container.read(staffSoundProvider), isFalse, reason: 'default OFF');

      events.add(_say('f8', 'AB100008'));
      await settle(tester);
      expect(find.text('New order #AB100008'), findsOneWidget);
      expect(chime.plays, 0);

      await tester.tap(find.byTooltip('Menu'));
      await settle(tester);
      expect(find.text('Sound for new orders'), findsOneWidget);
      await tester.tap(find.text('Sound for new orders'));
      await settle(tester);
      expect(container.read(staffSoundProvider), isTrue);
      expect((await SharedPreferences.getInstance()).getBool('staff_sound_new_orders'), isTrue);

      events.add(_say('f9', 'AB100009'));
      await settle(tester);
      expect(chime.plays, 1);
    });

    testWidgets('a phone that had it on starts with it on', (tester) async {
      final prefs = await memoryPrefs({'staff_sound_new_orders': true});
      final container = await _pump(tester, FakeOps(), overrides: [prefs]);
      expect(container.read(staffSoundProvider), isTrue);
      await tester.tap(find.byTooltip('Menu'));
      await settle(tester);
      expect(find.byIcon(Icons.done), findsOneWidget, reason: 'ticked in the menu');
    });

    testWidgets('a phone without storage still works (the chime stays off)', (tester) async {
      final container = await _pump(tester, FakeOps());
      expect(container.read(staffSoundProvider), isFalse);
      container.read(staffSoundProvider.notifier).set(true);
      expect(container.read(staffSoundProvider), isTrue);
    });
  });

  group('a tapped alert opens the right order', () {
    Map<String, dynamic> tap({
      String order = 'o-f0',
      String fulfillment = 'f0',
      String? branch,
      String event = 'staff.order_new',
    }) => {
      'event': event,
      'order_id': order,
      'fulfillment_id': fulfillment,
      'number': 'AB100001',
      'branch_id': ?branch,
      'deep_link': '/admin/orders?view=$order',
      'category': 'transactional',
    };

    Future<(PushService, ProviderContainer)> start(
      WidgetTester tester,
      FakeOps repo, {
      bool tapFirst = false,
      Map<String, dynamic>? first,
    }) async {
      final prefs = await memoryPrefs();
      final shared = await SharedPreferences.getInstance();
      final push = staffPushService(shared);
      if (tapFirst) push.debugHandleTap(first!);
      final container = await _pump(tester, repo, overrides: [prefs, pushServiceProvider.overrideWithValue(push)]);
      return (push, container);
    }

    testWidgets('while the app is open: the order\'s window opens', (tester) async {
      final repo = FakeOps();
      final (push, _) = await start(tester, repo);
      push.debugHandleTap(tap());
      await settle(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.byType(BottomSheet), findsOneWidget);
    });

    testWidgets('the reminder of an order that waits opens it the same way', (tester) async {
      final repo = FakeOps();
      final (push, _) = await start(tester, repo);
      push.debugHandleTap(tap(event: 'staff.order_reminder'));
      await settle(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
    });

    testWidgets('when the app was started by the tap: the order opens as soon as the screen is there', (tester) async {
      final repo = FakeOps();
      await start(tester, repo, tapFirst: true, first: tap());
      await settle(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
    });

    testWidgets('an order that is no longer in the list is said so, plainly', (tester) async {
      final repo = FakeOps();
      final (push, _) = await start(tester, repo);
      push.debugHandleTap(tap(order: 'o-gone', fulfillment: 'gone'));
      await settle(tester);
      expect(find.text('That order is no longer in your queue.'), findsOneWidget);
      expect(find.byType(BottomSheet), findsNothing);
    });

    testWidgets('an alert of another branch of a manager of several switches to that branch first', (tester) async {
      final repo = FakeOps()
        ..overviewData = staffOverview(
          branches: [
            {'id': 'b1', 'name': 'Markhiya'},
            {'id': 'b2', 'name': 'Wakra'},
          ],
        )
        ..overviewByBranch['b2'] = staffOverview(
          id: 'b2',
          name: 'Wakra',
          branches: [
            {'id': 'b1', 'name': 'Markhiya'},
            {'id': 'b2', 'name': 'Wakra'},
          ],
        );
      final (push, container) = await start(tester, repo);
      push.debugHandleTap(tap(branch: 'b2'));
      await settle(tester);
      await settle(tester);
      expect(container.read(staffBranchChoiceProvider), 'b2');
      expect(repo.overviewBranches.last, 'b2');
      expect(find.text('Order #AB100001'), findsOneWidget);
    });

    testWidgets('a customer-style location is not mistaken for an order of the branch', (tester) async {
      final repo = FakeOps();
      final (push, _) = await start(tester, repo);
      push.debugHandleTap({'event': 'order.ready_for_pickup', 'order_id': 'o1'});
      await settle(tester);
      expect(find.byType(BottomSheet), findsNothing);
    });
  });
}
