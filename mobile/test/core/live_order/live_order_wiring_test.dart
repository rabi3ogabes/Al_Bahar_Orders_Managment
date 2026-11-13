import 'dart:async';

import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:albahar_customer/core/live_order/live_order_controller.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import 'dart:ui' show Locale;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import '../../helpers/fakes.dart';
import 'live_order_fakes.dart';

/// A realtime client whose channels the test feeds.
class _FakePusher extends PusherClient {
  _FakePusher() : super(uri: Uri.parse('wss://realtime.test/app/key'));
  final opened = <String>[];
  final streams = <String, StreamController<PusherEvent>>{};

  @override
  Stream<PusherEvent> channel(String channelName) {
    opened.add(channelName);
    return (streams[channelName] ??= StreamController<PusherEvent>.broadcast()).stream;
  }
}

class _Flag extends Notifier<bool> {
  @override
  bool build() => false;
  void set(bool v) => state = v;
}

final _signedIn = NotifierProvider<_Flag, bool>(_Flag.new);

class _FakeMessaging extends NoopPushMessaging {
  _FakeMessaging({this.allow = true});
  final bool allow;
  int permissionRequests = 0;

  @override
  Future<bool> initialize() async => true;
  @override
  Future<bool> requestPermission() async {
    permissionRequests++;
    return allow;
  }

  @override
  Future<String?> getToken() async => 'token-1';
}

Future<void> settle() => pumpEventQueue();

void main() {
  setUpAll(() => Future.wait([initializeDateFormatting('ar'), initializeDateFormatting('en')]));

  late FakePlatform platform;
  late FakeLiveOrderApi api;
  late _FakePusher pusher;

  Future<TestHarness> boot({bool signedIn = false}) async {
    platform = FakePlatform();
    api = FakeLiveOrderApi();
    pusher = _FakePusher();
    final h = await TestHarness.create(
      overrides: [
        liveOrderPlatformProvider.overrideWithValue(platform),
        liveOrderApiProvider.overrideWithValue(api),
        pusherClientProvider.overrideWithValue(pusher),
        isAuthenticatedProvider.overrideWith((ref) => ref.watch(_signedIn)),
        currentUserIdProvider.overrideWith((ref) => ref.watch(_signedIn) ? 'u1' : null),
      ],
    );
    if (signedIn) h.container.read(_signedIn.notifier).set(true);
    // The app widget watches it for its whole life; a listener keeps it (and its own listeners) from pausing.
    h.container.listen(liveOrderBootProvider, (_, _) {});
    await settle();
    return h;
  }

  group('the app wiring', () {
    test('sign-in reads the server and follows the customer\'s private channel; sign-out clears everything', () async {
      final h = await boot();
      expect(api.activeCalls, 0, reason: 'signed out: nothing to ask');
      expect(pusher.opened, isEmpty);

      h.container.read(_signedIn.notifier).set(true);
      await settle();
      expect(api.activeCalls, 1);
      expect(pusher.opened, ['private-users.u1']);

      // A realtime event reaches the card.
      pusher.streams['private-users.u1']!.add(
        PusherEvent(
          channel: 'private-users.u1',
          event: 'order.updated',
          data: {'order_id': 'o1', 'number': '1', 'status': 'processing'},
        ),
      );
      await settle();
      expect(platform.phases, ['preparing']);

      h.container.read(_signedIn.notifier).set(false);
      await settle();
      expect(platform.cancelAllCalls, 1);
      expect(h.read(liveOrderControllerProvider).active, isEmpty);
      expect(pusher.streams['private-users.u1']!.hasListener, isFalse);
    });

    test('already signed in at start: the orders are read at once', () async {
      await boot(signedIn: true);
      expect(api.activeCalls, 1);
      expect(pusher.opened, ['private-users.u1']);
    });

    test('a push the app sees while open goes through the controller (not the plain notification)', () async {
      final h = await boot(signedIn: true);
      final push = h.read(pushServiceProvider);
      push.debugHandleForeground(
        const PushMessage(data: {'kind': 'order_progress', 'order_id': 'o1', 'step': '2', 'total': '4'}),
      );
      await settle();
      expect(platform.phases, ['on_the_way']);
      expect((h.read(localNotifierProvider) as FakeLocalNotifier).progress, isEmpty);
    });

    test('network back resyncs; a language change draws the cards again', () async {
      final h = await boot(signedIn: true);
      api.active = [liveOrder('o1', seq: 1, phase: LiveOrderPhase.preparing)];
      h.container.read(onlineProvider.notifier).set(false);
      await settle();
      h.container.read(onlineProvider.notifier).set(true);
      await settle();
      expect(api.activeCalls, 2);
      expect(platform.phases, ['preparing']);

      await h.container.read(localeControllerProvider.notifier).setLocale(const Locale('ar'));
      await settle();
      expect(platform.shown, hasLength(2));
      expect(platform.last.force, isTrue);
      expect(platform.last.locale, 'ar');
    });

    test('the controller takes the brand colour from the dashboard theme', () async {
      final h = await boot(signedIn: true);
      h.read(liveOrderControllerProvider).onResumed();
      unawaited(
        h.read(liveOrderControllerProvider).ingestPush({'kind': 'order_progress', 'order_id': 'o1', 'step': '1'}),
      );
      await settle();
      expect(platform.last.order.brandPrimary, isNotNull);
    });
  });

  group('PushService', () {
    Future<(PushService, _FakeMessaging, TestHarness)> service({required bool ask, bool allow = true}) async {
      final h = await TestHarness.create(
        tokens: AuthTokens.fromJson(tokens('A', 'R')),
        handler: (r) => ok({'ok': true}),
      );
      final messaging = _FakeMessaging(allow: allow);
      final push = PushService(
        messaging: messaging,
        local: FakeLocalNotifier(),
        api: h.read(apiClientProvider),
        prefs: h.read(appPreferencesProvider),
        enabled: true,
        askPermissionOnRegister: ask,
      );
      return (push, messaging, h);
    }

    test(
      'the customer app registers its token without showing the system dialog on Android (it asks in context)',
      () async {
        final (push, messaging, h) = await service(ask: false);
        await push.register();
        expect(messaging.permissionRequests, 0);
        expect(h.adapter.where('POST', '/me/devices'), hasLength(1));
      },
    );

    test('the driver and staff apps still ask when they register', () async {
      final (push, messaging, h) = await service(ask: true);
      await push.register();
      expect(messaging.permissionRequests, 1);
      expect(h.adapter.where('POST', '/me/devices'), hasLength(1));
    });

    test('a refusal at registration still stops the token from being sent', () async {
      final (push, messaging, h) = await service(ask: true, allow: false);
      await push.register();
      expect(messaging.permissionRequests, 1);
      expect(h.adapter.where('POST', '/me/devices'), isEmpty);
    });

    test('iOS always asks at registration', () async {
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      addTearDown(() => debugDefaultTargetPlatformOverride = null);
      final (push, messaging, _) = await service(ask: false);
      await push.register();
      expect(messaging.permissionRequests, 1);
    });

    test('the customer provider turns the dialog off, the ops providers keep it', () async {
      final customer = await TestHarness.create();
      expect(customer.read(pushServiceProvider).askPermissionOnRegister, isFalse);
      final driver = await TestHarness.create(overrides: [pushDevicesPathProvider.overrideWithValue('/admin/devices')]);
      expect(driver.read(pushServiceProvider).askPermissionOnRegister, isTrue);
    });

    test('order progress goes to the live order handler when there is one, else to the plain notification', () async {
      final (push, _, _) = await service(ask: false);
      final local = push.local as FakeLocalNotifier;
      const message = PushMessage(data: {'kind': 'order_progress', 'order_id': 'o1', 'step': '1'});
      push.debugHandleForeground(message);
      await settle();
      expect(local.progress, hasLength(1));

      final seen = <Map<String, dynamic>>[];
      push.onOrderProgress = seen.add;
      push.debugHandleForeground(message);
      await settle();
      expect(seen.single['order_id'], 'o1');
      expect(local.progress, hasLength(1), reason: 'not drawn twice');
    });

    test(
      'with the app closed on Android the Dart background handler leaves order progress to the Kotlin receiver',
      () async {
        // Would otherwise try to draw a second card through the notifications plugin.
        await firebaseMessagingBackgroundHandler(
          const RemoteMessage(data: {'kind': 'order_progress', 'order_id': 'o1'}),
        );
        await firebaseMessagingBackgroundHandler(const RemoteMessage(data: {'event': 'auction.outbid'}));
      },
    );
  });
}
