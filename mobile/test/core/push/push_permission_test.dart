import 'package:albahar_customer/core/live_order/live_order_controller.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';
import 'package:albahar_customer/core/live_order/notification_permission.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/fakes.dart';
import '../live_order/live_order_fakes.dart';

/// The iPhone's notification question as the messaging SDK has it.
class _Sdk extends NoopPushMessaging {
  _Sdk(this.status);
  PushPermission status;
  PushPermission whenAllowed = PushPermission.granted;
  final asked = <bool>[];
  Object? failWith;

  @override
  Future<PushPermission> permissionStatus() async => status;

  @override
  Future<bool> requestPermission({bool provisional = false}) async {
    asked.add(provisional);
    final failure = failWith;
    if (failure != null) throw failure;
    status = whenAllowed;
    return status == PushPermission.granted;
  }
}

MessagingPermissionPlatform _platform(_Sdk sdk, {LiveOrderPlatform? inner, Future<bool> Function()? settings}) =>
    MessagingPermissionPlatform(inner ?? FakePlatform(), sdk, openSettingsPage: settings ?? () async => true);

void main() {
  group('what the customer can still do about notifications', () {
    test('every standing of the iPhone maps to something the sheet understands', () {
      expect(MessagingPermissionPlatform.standing(PushPermission.granted), LiveOrderPermission.granted);
      expect(MessagingPermissionPlatform.standing(PushPermission.provisional), LiveOrderPermission.denied);
      expect(MessagingPermissionPlatform.standing(PushPermission.notDetermined), LiveOrderPermission.denied);
      expect(MessagingPermissionPlatform.standing(PushPermission.denied), LiveOrderPermission.blocked);
      expect(
        MessagingPermissionPlatform.standing(PushPermission.unavailable),
        LiveOrderPermission.granted,
        reason: 'Firebase is not running: a sheet promising pushes would be a lie',
      );
    });

    test('is read from the messaging SDK, and a failing SDK never asks anything', () async {
      expect(await _platform(_Sdk(PushPermission.provisional)).permission(), LiveOrderPermission.denied);
      final broken = _Sdk(PushPermission.denied)..failWith = StateError('x');
      expect(await _platform(broken).requestPermission(), LiveOrderPermission.blocked);
    });
  });

  group('the question', () {
    test('quiet delivery is upgraded to alerts with the system dialog', () async {
      final sdk = _Sdk(PushPermission.provisional);
      expect(await _platform(sdk).requestPermission(), LiveOrderPermission.granted);
      expect(sdk.asked, [false], reason: 'a real question, not another quiet one');
    });

    test('a customer who has not been asked yet is asked', () async {
      final sdk = _Sdk(PushPermission.notDetermined);
      expect(await _platform(sdk).requestPermission(), LiveOrderPermission.granted);
      expect(sdk.asked, [false]);
    });

    test('no answer is forced: "don\'t allow" is blocked for good', () async {
      final sdk = _Sdk(PushPermission.notDetermined)..whenAllowed = PushPermission.denied;
      expect(await _platform(sdk).requestPermission(), LiveOrderPermission.blocked);
    });

    test('a question the system will never ask again is not asked', () async {
      final sdk = _Sdk(PushPermission.denied);
      expect(await _platform(sdk).requestPermission(), LiveOrderPermission.blocked);
      expect(sdk.asked, isEmpty);
    });

    test('already on: nothing to ask', () async {
      final sdk = _Sdk(PushPermission.granted);
      expect(await _platform(sdk).requestPermission(), LiveOrderPermission.granted);
      expect(sdk.asked, isEmpty);
    });

    test('the settings page is the one the platform opens; everything else of the card is the native side’s', () async {
      var opened = 0;
      final inner = FakePlatform();
      final platform = _platform(
        _Sdk(PushPermission.denied),
        inner: inner,
        settings: () async {
          opened++;
          return true;
        },
      );

      expect(await platform.openSettings(), isTrue);
      expect(opened, 1);
      inner.result = LiveOrderShowResult.unsupported;
      expect(await platform.show(liveOrder('o1', seq: 1), locale: 'en'), LiveOrderShowResult.unsupported);
      await platform.cancel('o1');
      await platform.cancelAll();
      expect(inner.cancelled, ['o1']);
      expect(inner.cancelAllCalls, 1);
      expect(inner.shown, hasLength(1));
      expect(await platform.areEnabled(), isTrue);
    });
  });

  group('the sheet on an iPhone', () {
    Future<(AppPreferences, LiveOrderController)> setUpFlow() async {
      SharedPreferences.setMockInitialValues({});
      final prefs = AppPreferences(await SharedPreferences.getInstance());
      return (
        prefs,
        LiveOrderController(platform: FakePlatform(), api: FakeLiveOrderApi(), prefs: prefs, localeCode: () => 'en'),
      );
    }

    Widget app(
      LiveOrderPlatform platform,
      AppPreferences prefs,
      LiveOrderController controller,
      void Function(bool) done,
    ) => wrapApp(
      Builder(
        builder: (context) => ElevatedButton(
          onPressed: () async => done(
            await promptForNotificationsIfNeeded(context, platform: platform, prefs: prefs, controller: controller),
          ),
          child: const Text('place order'),
        ),
      ),
    );

    testWidgets('a customer on quiet delivery sees the explanation after the first order, then the system dialog', (
      tester,
    ) async {
      final (prefs, controller) = await setUpFlow();
      final sdk = _Sdk(PushPermission.provisional);
      bool? outcome;
      await tester.pumpWidget(app(_platform(sdk), prefs, controller, (v) => outcome = v));

      await tester.tap(find.text('place order'));
      await tester.pumpAndSettle();
      expect(find.text('Follow your order live'), findsOneWidget);
      expect(sdk.asked, isEmpty, reason: 'the explanation comes first');

      await tester.tap(find.text('Allow notifications'));
      await tester.pumpAndSettle();
      expect(sdk.asked, [false]);
      expect(outcome, isTrue);
      expect(prefs.livePermissionPrompt.count, 1);
      await controller.dispose();
    });

    testWidgets('"not now" keeps the quiet delivery and does not ask again tomorrow', (tester) async {
      final (prefs, controller) = await setUpFlow();
      final sdk = _Sdk(PushPermission.provisional);
      await tester.pumpWidget(app(_platform(sdk), prefs, controller, (_) {}));

      await tester.tap(find.text('place order'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Not now'));
      await tester.pumpAndSettle();
      expect(sdk.asked, isEmpty);
      expect(sdk.status, PushPermission.provisional);

      await tester.tap(find.text('place order'));
      await tester.pumpAndSettle();
      expect(find.text('Follow your order live'), findsNothing);
      await controller.dispose();
    });

    testWidgets('a customer who said no to the system is offered the settings, not another dialog', (tester) async {
      final (prefs, controller) = await setUpFlow();
      var settingsOpened = 0;
      final sdk = _Sdk(PushPermission.denied);
      await tester.pumpWidget(
        app(
          _platform(
            sdk,
            settings: () async {
              settingsOpened++;
              return true;
            },
          ),
          prefs,
          controller,
          (_) {},
        ),
      );

      await tester.tap(find.text('place order'));
      await tester.pumpAndSettle();
      expect(find.text('Notifications are off'), findsOneWidget);
      await tester.tap(find.text('Open settings'));
      await tester.pumpAndSettle();
      expect(settingsOpened, 1);
      expect(sdk.asked, isEmpty);
      await controller.dispose();
    });

    testWidgets('a phone with notifications already on is not asked anything', (tester) async {
      final (prefs, controller) = await setUpFlow();
      bool? outcome;
      await tester.pumpWidget(app(_platform(_Sdk(PushPermission.granted)), prefs, controller, (v) => outcome = v));

      await tester.tap(find.text('place order'));
      await tester.pumpAndSettle();
      expect(find.text('Follow your order live'), findsNothing);
      expect(outcome, isTrue);
      await controller.dispose();
    });
  });
}
