import 'package:albahar_customer/core/live_order/live_activity_platform.dart';
import 'package:albahar_customer/core/live_order/live_activity_registrar.dart';
import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:albahar_customer/core/live_order/live_order_controller.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/fakes.dart';
import 'live_activity_fakes.dart';
import 'live_order_fakes.dart';

final _t0 = DateTime.utc(2026, 11, 14, 15);

/// The controller as it is on an iPhone: no native card of its own (`unsupported`), a Live Activity next to it.
class _Rig {
  _Rig(this.prefs) {
    registrar = LiveActivityRegistrar(
      platform: activity,
      api: tokensApi,
      prefs: prefs,
      localeCode: () => locale,
      appVersion: () async => '1.0.0+1',
    );
    controller = LiveOrderController(
      platform: platform,
      api: api,
      prefs: prefs,
      localeCode: () => locale,
      fallback: local,
      activity: activity,
      activityTokens: registrar,
      now: () => _t0,
    );
  }

  final AppPreferences prefs;
  final platform = FakePlatform()..result = LiveOrderShowResult.unsupported;
  final api = FakeLiveOrderApi();
  final local = FakeLocalNotifier();
  final activity = FakeActivity();
  final tokensApi = FakeActivityApi();
  late final LiveActivityRegistrar registrar;
  late final LiveOrderController controller;
  String locale = 'en';

  static Future<_Rig> create() async {
    SharedPreferences.setMockInitialValues({});
    final rig = _Rig(AppPreferences(await SharedPreferences.getInstance()));
    addTearDown(rig.controller.dispose);
    return rig;
  }

  Future<void> settle() async {
    for (var i = 0; i < 4; i++) {
      await Future<void>.delayed(Duration.zero);
    }
  }
}

void main() {
  setUpAll(() => Future.wait([initializeDateFormatting('ar'), initializeDateFormatting('en')]));

  group('the order on the lock screen', () {
    test('an order just placed starts the Live Activity in the customer\'s language, and the plain notification stays away', () async {
      final rig = await _Rig.create();
      rig.controller.onSignedIn();
      await rig.settle();

      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');

      expect(rig.activity.shown, hasLength(1));
      final shown = rig.activity.shown.single;
      expect(shown.order.orderId, 'o1');
      expect(shown.order.phase, LiveOrderPhase.placed);
      expect(shown.locale, 'en');
      expect(shown.stageLabel, 'Placed');
      expect(shown.order.headline, isNotEmpty, reason: 'the texts are filled in before the activity is told');
      expect(rig.local.progress, isEmpty, reason: 'the activity carries the order: no plain notification on top of it');
      expect(
        rig.platform.shown,
        hasLength(1),
        reason: 'the native card is still asked (Android\'s side answers "unsupported" here)',
      );
    });

    test('an Arabic customer gets the Arabic stage name', () async {
      final rig = await _Rig.create();
      rig.locale = 'ar';
      rig.controller.onSignedIn();
      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');

      expect(rig.activity.shown.single.locale, 'ar');
      expect(rig.activity.shown.single.stageLabel, 'تم الطلب');
    });

    test('every change goes to the activity, a finished order too (it shows its last state and ends)', () async {
      final rig = await _Rig.create();
      rig.controller.onSignedIn();
      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');

      await rig.controller.ingestPush({
        'order_id': 'o1',
        'phase': 'preparing',
        'seq': '10',
        'title': 'Arrives at 6pm',
        'body': 'Being prepared.',
      });
      await rig.controller.ingestPush({'order_id': 'o1', 'phase': 'delivered', 'seq': '20', 'done': '1'});

      expect(rig.activity.shown.map((s) => s.order.phase), [
        LiveOrderPhase.placed,
        LiveOrderPhase.preparing,
        LiveOrderPhase.delivered,
      ]);
      expect(rig.activity.shown[1].order.headline, 'Arrives at 6pm');
      expect(rig.activity.shown.last.order.isFinal, isTrue);
      expect(rig.local.progress, isEmpty);
    });

    test('where there is no Live Activity the plain notification is used, as before', () async {
      final rig = await _Rig.create();
      rig.activity.result =
          LiveActivityResult.unavailable; // iOS 16.1, activities switched off, the widget not installed
      rig.controller.onSignedIn();

      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');

      expect(rig.local.progress, hasLength(1));
      expect(rig.local.progress.single['order_id'], 'o1');
    });

    test('a Live Activity that throws never reaches the order flow, and the plain notification takes over', () async {
      final rig = await _Rig.create();
      rig.activity.showError = StateError('ActivityKit exploded');
      rig.controller.onSignedIn();

      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');

      expect(rig.local.progress, hasLength(1));
      expect(rig.platform.shown, hasLength(1));
    });

    test(
      'an Android phone (a native card, no Live Activity) is not changed: no fallback, no activity call it depends on',
      () async {
        final rig = await _Rig.create();
        rig.platform.result = LiveOrderShowResult.posted;
        rig.activity.result = LiveActivityResult.unavailable;
        rig.controller.onSignedIn();

        await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');

        expect(rig.local.progress, isEmpty);
        expect(rig.platform.shown, hasLength(1));
      },
    );

    test('a controller built without a Live Activity works as it always did', () async {
      SharedPreferences.setMockInitialValues({});
      final prefs = AppPreferences(await SharedPreferences.getInstance());
      final platform = FakePlatform()..result = LiveOrderShowResult.unsupported;
      final local = FakeLocalNotifier();
      final controller = LiveOrderController(
        platform: platform,
        api: FakeLiveOrderApi(),
        prefs: prefs,
        localeCode: () => 'en',
        fallback: local,
        now: () => _t0,
      );
      addTearDown(controller.dispose);
      controller.onSignedIn();

      await controller.orderPlaced(orderId: 'o1', number: 'AB1');
      await controller.onSignedOut();

      expect(local.progress, hasLength(1));
    });

    test('a language change redraws the activity and reports the tokens again, in the new language', () async {
      final rig = await _Rig.create();
      rig.controller.onSignedIn();
      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');
      rig.activity.emit(activityToken('o1', 'AA11'));
      await rig.settle();
      rig.locale = 'ar';

      await rig.controller.refreshPresentation();
      await rig.settle();

      expect(rig.activity.shown.last.locale, 'ar');
      expect(rig.tokensApi.activities.map((a) => a['locale']), ['en', 'ar']);
    });

    test('an order the server no longer knows ends its activity', () async {
      final rig = await _Rig.create();
      rig.controller.onSignedIn();
      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');
      rig.api
        ..active = const []
        ..byId['o1'] = null;

      await rig.controller.resync(force: true);

      expect(rig.activity.ended, ['o1']);
    });
  });

  group('the tokens', () {
    test('are reported to the server only for the signed-in customer, and taken back at sign-out together with the activities', () async {
      final rig = await _Rig.create();
      rig.activity.emit(activityToken('o0', 'EARLY'));
      await rig.settle();
      expect(rig.tokensApi.activities, isEmpty, reason: 'nobody is signed in');

      rig.controller.onSignedIn();
      await rig.settle();
      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');
      rig.activity.emit(activityToken('o1', 'AA11', environment: 'sandbox'));
      rig.activity.emit(startToken('BB22'));
      await rig.settle();

      expect(rig.tokensApi.activities.single['token'], 'AA11');
      expect(rig.tokensApi.starts.single['token'], 'BB22');

      await rig.controller.onSignedOut();

      expect(rig.activity.endAllCalls, 1, reason: 'nothing of this customer stays on a lock screen');
      expect(rig.tokensApi.unregistered, [rig.prefs.installId]);
      rig.activity.emit(activityToken('o2', 'CC33'));
      await rig.settle();
      expect(rig.tokensApi.activities, hasLength(1), reason: 'after sign-out nothing more is reported');
      expect(rig.activity.listeners, 0);
    });

    test('a failing activity or server at sign-out never stops the sign-out', () async {
      final rig = await _Rig.create();
      rig.activity.endAllError = StateError('boom');
      rig.tokensApi.unregisterError = StateError('401');
      rig.controller.onSignedIn();
      await rig.controller.orderPlaced(orderId: 'o1', number: 'AB1');
      rig.activity.emit(activityToken('o1', 'AA11'));
      await rig.settle();

      await rig.controller.onSignedOut();

      expect(rig.platform.cancelAllCalls, 1);
      expect(rig.controller.trackedIds, isEmpty);
    });

    test('a token the server could not take is sent when the app comes back or the network does', () async {
      final rig = await _Rig.create();
      rig.tokensApi.error = Exception('offline');
      rig.controller.onSignedIn();
      rig.activity.emit(activityToken('o1', 'AA11'));
      await rig.settle();
      expect(rig.tokensApi.activities, isEmpty);

      rig.tokensApi.error = null;
      rig.controller.onResumed();
      await rig.settle();
      expect(rig.tokensApi.activities.single['token'], 'AA11');

      rig.tokensApi.error = Exception('offline');
      rig.activity.emit(activityToken('o2', 'BB22'));
      await rig.settle();
      rig.tokensApi.error = null;
      rig.controller.onOnline();
      await rig.settle();
      expect(rig.tokensApi.activities.map((a) => a['token']), ['AA11', 'BB22']);
    });
  });
}
