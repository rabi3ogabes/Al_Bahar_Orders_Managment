import 'dart:async';

import 'package:albahar_customer/core/live_order/live_order_controller.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';
import 'package:albahar_customer/core/live_order/notification_permission.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/fakes.dart';
import 'live_order_fakes.dart';

final _now = DateTime.utc(2026, 11, 13, 18);

class _Flow {
  _Flow(this.prefs) {
    controller = LiveOrderController(
      platform: platform,
      api: FakeLiveOrderApi(),
      prefs: prefs,
      localeCode: () => 'en',
      now: () => now,
    );
  }

  final AppPreferences prefs;
  final platform = FakePlatform();
  late final LiveOrderController controller;
  DateTime now = _now;
  bool? outcome;

  static Future<_Flow> create({Map<String, Object> saved = const {}}) async {
    SharedPreferences.setMockInitialValues(saved);
    return _Flow(AppPreferences(await SharedPreferences.getInstance()));
  }

  Widget app({Locale locale = const Locale('en'), double textScale = 1.0}) => MediaQuery(
    data: MediaQueryData(textScaler: TextScaler.linear(textScale)),
    child: wrapApp(
      Builder(
        builder: (context) => Center(
          child: ElevatedButton(
            onPressed: () async => outcome = await promptForNotificationsIfNeeded(
              context,
              platform: platform,
              prefs: prefs,
              controller: controller,
              now: () => now,
            ),
            child: const Text('place order'),
          ),
        ),
      ),
      locale: locale,
    ),
  );
}

Future<void> open(WidgetTester tester) async {
  await tester.tap(find.text('place order'));
  await tester.pumpAndSettle();
}

void main() {
  group('who is asked, and when', () {
    NotificationPromptKind kind(LiveOrderPermission p, {DateTime? last, int count = 0}) =>
        notificationPromptFor(permission: p, lastAt: last, count: count, now: _now);
    DateTime ago(int days) => _now.subtract(Duration(days: days));

    test('nothing to ask when notifications are on', () {
      expect(kind(LiveOrderPermission.granted), NotificationPromptKind.none);
      expect(kind(LiveOrderPermission.granted, count: 2, last: ago(40)), NotificationPromptKind.none);
    });

    test('the first order asks, with the system dialog when it can still be shown', () {
      expect(kind(LiveOrderPermission.denied), NotificationPromptKind.ask);
    });

    test('after a "not now" the sheet waits two weeks and is shown three times at most', () {
      expect(kind(LiveOrderPermission.denied, count: 1, last: ago(1)), NotificationPromptKind.none);
      expect(kind(LiveOrderPermission.denied, count: 1, last: ago(13)), NotificationPromptKind.none);
      expect(kind(LiveOrderPermission.denied, count: 1, last: ago(14)), NotificationPromptKind.ask);
      expect(kind(LiveOrderPermission.denied, count: 2, last: ago(20)), NotificationPromptKind.ask);
      expect(kind(LiveOrderPermission.denied, count: 3, last: ago(90)), NotificationPromptKind.none);
    });

    test('denied for good: the settings, once, and again after a month at most once more', () {
      expect(kind(LiveOrderPermission.blocked), NotificationPromptKind.settings);
      expect(kind(LiveOrderPermission.blocked, count: 1, last: ago(5)), NotificationPromptKind.none);
      expect(kind(LiveOrderPermission.blocked, count: 1, last: ago(31)), NotificationPromptKind.settings);
      expect(kind(LiveOrderPermission.blocked, count: 2, last: ago(90)), NotificationPromptKind.none);
    });

    test('a count without a date never asks again (a damaged record must not nag)', () {
      expect(kind(LiveOrderPermission.denied, count: 1), NotificationPromptKind.none);
      expect(kind(LiveOrderPermission.blocked, count: 1), NotificationPromptKind.none);
    });
  });

  group('the sheet', () {
    testWidgets('explains why, in English', (tester) async {
      final flow = await _Flow.create();
      flow.platform.state = LiveOrderPermission.denied;
      await tester.pumpWidget(flow.app());
      await open(tester);
      expect(find.text('Follow your order live'), findsOneWidget);
      expect(find.textContaining('lock screen'), findsOneWidget);
      expect(find.text('Allow notifications'), findsOneWidget);
      expect(find.text('Not now'), findsOneWidget);
    });

    testWidgets('explains why, in Arabic, right to left', (tester) async {
      final flow = await _Flow.create();
      flow.platform.state = LiveOrderPermission.denied;
      await tester.pumpWidget(flow.app(locale: const Locale('ar')));
      await open(tester);
      expect(find.text('تابع طلبك لحظة بلحظة'), findsOneWidget);
      expect(find.text('السماح بالإشعارات'), findsOneWidget);
      expect(find.text('ليس الآن'), findsOneWidget);
      expect(Directionality.of(tester.element(find.text('السماح بالإشعارات'))), TextDirection.rtl);
    });

    testWidgets('the settings variant says notifications are off and offers the settings', (tester) async {
      final flow = await _Flow.create();
      flow.platform.state = LiveOrderPermission.blocked;
      await tester.pumpWidget(flow.app());
      await open(tester);
      expect(find.text('Notifications are off'), findsOneWidget);
      expect(find.text('Open settings'), findsOneWidget);
      expect(find.text('Allow notifications'), findsNothing);
    });

    for (final scale in [1.0, 2.0]) {
      testWidgets('buttons are at least 48 dp tall and nothing overflows (text scale $scale)', (tester) async {
        final flow = await _Flow.create();
        flow.platform.state = LiveOrderPermission.denied;
        await tester.pumpWidget(flow.app(textScale: scale));
        await open(tester);
        expect(tester.takeException(), isNull);
        for (final label in ['Allow notifications', 'Not now']) {
          final button = find
              .ancestor(of: find.text(label), matching: find.byWidgetPredicate((w) => w is ButtonStyleButton))
              .first;
          expect(tester.getSize(button).height, greaterThanOrEqualTo(48), reason: label);
        }
      });
    }
  });

  group('the flow', () {
    testWidgets('allow: asks the system, draws the cards that were waiting, remembers it asked', (tester) async {
      final flow = await _Flow.create();
      flow.platform.state = LiveOrderPermission.denied;
      flow.controller.onSignedIn();
      await tester.pumpWidget(flow.app());
      unawaited(flow.controller.orderPlaced(orderId: 'o1', number: '1'));
      await tester.pump();
      final drawn = flow.platform.shown.length;

      await open(tester);
      await tester.tap(find.text('Allow notifications'));
      await tester.pumpAndSettle();
      expect(flow.platform.requestCalls, 1);
      expect(flow.outcome, isTrue);
      expect(flow.platform.shown.length, drawn + 1, reason: 'the waiting card is drawn now');
      expect(flow.platform.last.force, isTrue);
      expect(flow.prefs.livePermissionPrompt.count, 1);
      expect(flow.prefs.livePermissionPrompt.at!.isAtSameMomentAs(_now), isTrue);
      expect(find.text('Follow your order live'), findsNothing);
      await flow.controller.dispose(); // the poll timer of the followed order must not outlive the test
    });

    testWidgets('allow, then "don\'t allow" in the system dialog: no card is forced', (tester) async {
      final flow = await _Flow.create();
      flow.platform
        ..state = LiveOrderPermission.denied
        ..dialogAnswer = LiveOrderPermission.blocked;
      await tester.pumpWidget(flow.app());
      await open(tester);
      await tester.tap(find.text('Allow notifications'));
      await tester.pumpAndSettle();
      expect(flow.outcome, isFalse);
      expect(flow.platform.shown, isEmpty);
    });

    testWidgets('"not now" does not ask the system, and the sheet does not come back the next day', (tester) async {
      final flow = await _Flow.create();
      flow.platform.state = LiveOrderPermission.denied;
      await tester.pumpWidget(flow.app());
      await open(tester);
      await tester.tap(find.text('Not now'));
      await tester.pumpAndSettle();
      expect(flow.platform.requestCalls, 0);
      expect(flow.outcome, isFalse);
      expect(flow.prefs.livePermissionPrompt.count, 1);

      flow.now = _now.add(const Duration(days: 1));
      await open(tester);
      expect(find.text('Follow your order live'), findsNothing);

      flow.now = _now.add(const Duration(days: 15));
      await open(tester);
      expect(find.text('Follow your order live'), findsOneWidget);
    });

    testWidgets('blocked: the button opens the system settings', (tester) async {
      final flow = await _Flow.create();
      flow.platform.state = LiveOrderPermission.blocked;
      await tester.pumpWidget(flow.app());
      await open(tester);
      await tester.tap(find.text('Open settings'));
      await tester.pumpAndSettle();
      expect(flow.platform.openSettingsCalls, 1);
      expect(flow.platform.requestCalls, 0);
      expect(flow.outcome, isFalse);
    });

    testWidgets('already granted: no sheet at all', (tester) async {
      final flow = await _Flow.create();
      await tester.pumpWidget(flow.app());
      await open(tester);
      expect(find.byType(NotificationPermissionSheet), findsNothing);
      expect(flow.outcome, isTrue);
      expect(flow.prefs.livePermissionPrompt.count, 0);
    });

    testWidgets('a platform that fails never breaks the order flow', (tester) async {
      SharedPreferences.setMockInitialValues({});
      final prefs = AppPreferences(await SharedPreferences.getInstance());
      final flow = _Flow(prefs);
      await tester.pumpWidget(
        wrapApp(
          Builder(
            builder: (context) => ElevatedButton(
              onPressed: () async => flow.outcome = await promptForNotificationsIfNeeded(
                context,
                platform: _BrokenPlatform(),
                prefs: prefs,
                controller: flow.controller,
              ),
              child: const Text('place order'),
            ),
          ),
        ),
      );
      await open(tester);
      expect(flow.outcome, isFalse);
      expect(tester.takeException(), isNull);
    });

    testWidgets('after the order page is up, the prompt runs on the next frame', (tester) async {
      final flow = await _Flow.create();
      flow.platform.state = LiveOrderPermission.denied;
      await tester.pumpWidget(
        wrapApp(
          Builder(
            builder: (context) => ElevatedButton(
              onPressed: () => promptForNotificationsAfterOrder(
                context,
                platform: flow.platform,
                prefs: flow.prefs,
                controller: flow.controller,
              ),
              child: const Text('place order'),
            ),
          ),
        ),
      );
      await tester.tap(find.text('place order'));
      await tester.pumpAndSettle();
      expect(find.text('Follow your order live'), findsOneWidget);
    });
  });
}

class _BrokenPlatform extends FakePlatform {
  @override
  Future<LiveOrderPermission> permission() => throw StateError('no native side');
}
