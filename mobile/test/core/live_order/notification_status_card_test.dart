import 'package:albahar_customer/core/live_order/live_order_controller.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';
import 'package:albahar_customer/core/live_order/notification_status_card.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:albahar_customer/features/profile/presentation/notification_prefs_screen.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/fakes.dart';
import 'live_order_fakes.dart';

const _title = 'Notifications are off on this phone';

/// The two notification preferences the server has for this customer.
class _Prefs extends NotificationPrefsController {
  @override
  Future<List<NotificationPreference>> build() async => const [
    NotificationPreference(channel: 'push', category: 'transactional', isEnabled: true),
    NotificationPreference(channel: 'push', category: 'marketing', isEnabled: false),
  ];
}

class _Rig {
  _Rig(this.prefs) {
    controller = LiveOrderController(platform: phone, api: FakeLiveOrderApi(), prefs: prefs, localeCode: () => 'en');
  }

  final AppPreferences prefs;
  final phone = FakePlatform();
  late final LiveOrderController controller;
  int answered = 0;

  static Future<_Rig> create(LiveOrderPermission state) async {
    SharedPreferences.setMockInitialValues({});
    final rig = _Rig(AppPreferences(await SharedPreferences.getInstance()));
    rig.phone.state = state;
    return rig;
  }

  List<Override> get overrides => [
    liveOrderPlatformProvider.overrideWithValue(phone),
    liveOrderControllerProvider.overrideWithValue(controller),
  ];

  Widget card({Locale locale = const Locale('en')}) => ProviderScope(
    overrides: overrides,
    child: wrapApp(NotificationStatusCard(onAnswered: () async => answered++), locale: locale),
  );

  Widget screen({bool embedded = false, Locale locale = const Locale('en')}) => ProviderScope(
    overrides: [...overrides, notificationPrefsProvider.overrideWith(_Prefs.new)],
    child: wrapApp(NotificationPrefsScreen(embedded: embedded), locale: locale),
  );
}

void main() {
  group('what the card says', () {
    testWidgets('never asked: it says so and offers to turn notifications on', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();

      expect(find.text(_title), findsOneWidget);
      expect(find.textContaining('have not allowed notifications yet'), findsOneWidget);
      expect(find.text('Turn on notifications'), findsOneWidget);
      expect(find.text('Open settings'), findsNothing);
    });

    testWidgets('switched off in the phone: it says so and offers the phone settings', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.blocked);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();

      expect(find.text(_title), findsOneWidget);
      expect(find.textContaining('switched off in this phone'), findsOneWidget);
      expect(find.text('Open settings'), findsOneWidget);
      expect(find.text('Turn on notifications'), findsNothing);
    });

    testWidgets('on: nothing is drawn at all', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.granted);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();

      expect(find.text(_title), findsNothing);
      expect(find.byType(ElevatedButton), findsNothing);
      expect(tester.getSize(find.byType(NotificationStatusCard)).height, 0);
    });

    testWidgets('a phone that cannot say draws nothing and breaks nothing', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            liveOrderPlatformProvider.overrideWithValue(_Broken()),
            liveOrderControllerProvider.overrideWithValue(rig.controller),
          ],
          child: wrapApp(const NotificationStatusCard()),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text(_title), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('in Arabic, right to left, in plain words', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      await tester.pumpWidget(rig.card(locale: const Locale('ar')));
      await tester.pumpAndSettle();

      expect(find.text('الإشعارات متوقفة على هذا الهاتف'), findsOneWidget);
      expect(find.text('تفعيل الإشعارات'), findsOneWidget);
      expect(Directionality.of(tester.element(find.text('تفعيل الإشعارات'))), TextDirection.rtl);

      rig.phone.state = LiveOrderPermission.blocked;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('فتح الإعدادات'), findsOneWidget);
    });

    for (final scale in [1.0, 2.0]) {
      testWidgets('nothing overflows and the button is at least 48 dp tall (text scale $scale)', (tester) async {
        final rig = await _Rig.create(LiveOrderPermission.blocked);
        await tester.pumpWidget(
          MediaQuery(
            data: MediaQueryData(textScaler: TextScaler.linear(scale)),
            child: rig.card(),
          ),
        );
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        final button = find
            .ancestor(of: find.text('Open settings'), matching: find.byWidgetPredicate((w) => w is ButtonStyleButton))
            .first;
        expect(tester.getSize(button).height, greaterThanOrEqualTo(48));
      });
    }
  });

  group('what the button does', () {
    testWidgets('not asked: the system question comes straight away (no second explanation), then the card goes', (
      tester,
    ) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();

      await tester.tap(find.text('Turn on notifications'));
      await tester.pumpAndSettle();
      expect(rig.phone.requestCalls, 1);
      expect(rig.answered, 1, reason: 'the server hears the answer');
      expect(find.text('Allow notifications'), findsNothing, reason: 'the card is the explanation');
      expect(find.text(_title), findsNothing);
    });

    testWidgets('"don\'t allow" in the system dialog: the card stays, now with the phone settings', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      rig.phone.dialogAnswer = LiveOrderPermission.blocked;
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();

      await tester.tap(find.text('Turn on notifications'));
      await tester.pumpAndSettle();
      expect(rig.answered, 1);
      expect(find.text(_title), findsOneWidget);
      expect(find.text('Open settings'), findsOneWidget);

      await tester.tap(find.text('Open settings'));
      await tester.pumpAndSettle();
      expect(rig.phone.openSettingsCalls, 1);
      expect(rig.phone.requestCalls, 1, reason: 'the system dialog is gone for good');
    });

    testWidgets('the same rule as the sheet: a "not now" a day ago does not make the button dead', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      await rig.prefs.setLivePermissionPrompt(DateTime.now().subtract(const Duration(days: 1)), 1);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();

      await tester.tap(find.text('Turn on notifications'));
      await tester.pumpAndSettle();
      expect(rig.phone.requestCalls, 1);
      expect(rig.prefs.livePermissionPrompt.count, 1, reason: 'a button the person pressed is not a prompt to count');
    });

    testWidgets('a double tap asks once', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();

      final button = find.text('Turn on notifications');
      await tester.tap(button);
      await tester.tap(button, warnIfMissed: false);
      await tester.pumpAndSettle();
      expect(rig.phone.requestCalls, 1);
    });

    testWidgets('a phone that fails when asked never breaks the screen', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      final broken = _AskFails()..state = LiveOrderPermission.denied;
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            liveOrderPlatformProvider.overrideWithValue(broken),
            liveOrderControllerProvider.overrideWithValue(rig.controller),
          ],
          child: wrapApp(const NotificationStatusCard()),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Turn on notifications'));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(find.text('Turn on notifications'), findsOneWidget, reason: 'and the button is usable again');
    });
  });

  group('coming back to the app', () {
    testWidgets('turned on in the phone settings meanwhile: the card is gone', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.blocked);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();
      expect(find.text(_title), findsOneWidget);

      rig.phone.state = LiveOrderPermission.granted;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text(_title), findsNothing);
    });

    testWidgets('switched off in the phone settings meanwhile: the card appears', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.granted);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();
      expect(find.text(_title), findsNothing);

      rig.phone.state = LiveOrderPermission.blocked;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text(_title), findsOneWidget);
      expect(find.text('Open settings'), findsOneWidget);
    });

    testWidgets('leaving the screen stops listening to the app', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.blocked);
      await tester.pumpWidget(rig.card());
      await tester.pumpAndSettle();
      await tester.pumpWidget(const SizedBox.shrink());

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      expect(tester.takeException(), isNull);
    });
  });

  group('on the notification settings screen', () {
    testWidgets('the card is the first thing, above the switches, when notifications are off', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      await tester.pumpWidget(rig.screen());
      await tester.pumpAndSettle();

      expect(find.text(_title), findsOneWidget);
      expect(find.text('Offers & inspiration'), findsWidgets);
      expect(
        tester.getTopLeft(find.text(_title)).dy,
        lessThan(tester.getTopLeft(find.text('Offers & inspiration').first).dy),
      );
    });

    testWidgets('and in the side window too', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.blocked);
      await tester.pumpWidget(rig.screen(embedded: true));
      await tester.pumpAndSettle();
      expect(find.text(_title), findsOneWidget);
      expect(find.text('Open settings'), findsOneWidget);
    });

    testWidgets('when they are on the screen is just the switches', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.granted);
      await tester.pumpWidget(rig.screen());
      await tester.pumpAndSettle();

      expect(find.text(_title), findsNothing);
      expect(find.byType(SwitchListTile), findsWidgets);
    });

    testWidgets('its button runs the question from the screen and the card goes', (tester) async {
      final rig = await _Rig.create(LiveOrderPermission.denied);
      await tester.pumpWidget(rig.screen());
      await tester.pumpAndSettle();

      await tester.tap(find.text('Turn on notifications'));
      await tester.pumpAndSettle();
      expect(rig.phone.requestCalls, 1);
      expect(find.text(_title), findsNothing);
      expect(find.byType(SwitchListTile), findsWidgets);
    });
  });
}

class _Broken extends FakePlatform {
  @override
  Future<LiveOrderPermission> permission() => throw StateError('no native side');
}

class _AskFails extends FakePlatform {
  @override
  Future<LiveOrderPermission> requestPermission() => throw StateError('dialog failed');
}
