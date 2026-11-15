import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

/// A messaging SDK that starts only when told to; counts every attempt.
class _ScriptedMessaging implements PushMessaging {
  bool canStart = false;
  bool throwOnStart = false;
  Completer<void>? holdStart;
  int attempts = 0;
  int foregroundListeners = 0;
  final foreground = StreamController<PushMessage>.broadcast();

  @override
  Future<bool> initialize() async {
    attempts++;
    await holdStart?.future;
    if (throwOnStart) throw StateError('SDK blew up');
    return canStart;
  }

  @override
  Future<bool> requestPermission({bool provisional = false}) async => true;

  @override
  Future<PushPermission> permissionStatus() async => PushPermission.granted;

  @override
  Future<String?> getToken() async => 'tok-1';

  @override
  Stream<String> get onTokenRefresh => const Stream.empty();

  @override
  Stream<PushMessage> get onForegroundMessage => Stream<PushMessage>.multi((c) {
    foregroundListeners++;
    final sub = foreground.stream.listen(c.add);
    c.onCancel = () {
      foregroundListeners--;
      return sub.cancel();
    };
  });

  @override
  Stream<PushMessage> get onMessageOpenedApp => const Stream.empty();

  @override
  Future<PushMessage?> getInitialMessage() async => null;
}

class _Clock {
  DateTime now = DateTime.utc(2026, 1, 1, 9);
  void advance(Duration d) => now = now.add(d);
}

const _almostTenMinutes = Duration(minutes: 9, seconds: 59);
const _tenMinutes = Duration(minutes: 10);

void main() {
  late TestHarness h;
  late _ScriptedMessaging messaging;
  late FakeLocalNotifier local;
  late _Clock clock;

  PushService service({bool enabled = true}) => PushService(
    messaging: messaging,
    local: local,
    api: h.read(apiClientProvider),
    prefs: h.read(appPreferencesProvider),
    enabled: enabled,
    now: () => clock.now,
  );

  setUp(() async {
    h = await TestHarness.create(tokens: AuthTokens.fromJson(tokens('A', 'R')), handler: (r) => ok({'ok': true}));
    messaging = _ScriptedMessaging();
    local = FakeLocalNotifier();
    clock = _Clock();
  });

  group('a start that failed is tried again', () {
    test('not at the next call inside ten minutes, but at the first one after', () async {
      final push = service();
      await push.start();
      expect(push.available, isFalse);
      expect(messaging.attempts, 1);

      clock.advance(_almostTenMinutes);
      await push.start();
      await push.register();
      await push.onResumed();
      expect(messaging.attempts, 1, reason: 'throttled: under ten minutes since the last attempt');

      clock.advance(const Duration(seconds: 1));
      await push.start();
      expect(messaging.attempts, 2, reason: 'ten minutes passed');
      expect(push.available, isFalse);
    });

    test('never more than one attempt per ten minutes, however often it is asked', () async {
      final push = service();
      await push.start();
      for (var i = 0; i < 5; i++) {
        await push.register();
        await push.onResumed();
        clock.advance(const Duration(minutes: 2));
      }
      // The calls above ran at 0, 2, 4, 6 and 8 minutes: all inside the ten minutes since the first attempt.
      expect(messaging.attempts, 1);
      await push.onResumed(); // at 10 minutes
      expect(messaging.attempts, 2);
      await push.register(); // same minute
      await push.onResumed();
      expect(messaging.attempts, 2);
      clock.advance(_tenMinutes);
      await push.register();
      expect(messaging.attempts, 3);
    });

    test('register after sign-in starts push once the settings are there, and sends the token', () async {
      final push = service();
      await push.start(); // app launch: the server had no settings yet
      expect(push.available, isFalse);

      messaging.canStart = true; // the owner saved the settings in the dashboard
      clock.advance(const Duration(minutes: 30));
      await push.register(); // the customer signs in

      expect(push.available, isTrue);
      expect(h.adapter.where('POST', '/me/devices').single.body['push_token'], 'tok-1');
    });

    test('coming back to the app finishes a registration that was waiting for push', () async {
      final push = service();
      await push.register(); // signed in, but push cannot start yet
      expect(h.adapter.where('POST', '/me/devices'), isEmpty);

      messaging.canStart = true;
      clock.advance(_almostTenMinutes);
      await push.onResumed();
      expect(h.adapter.where('POST', '/me/devices'), isEmpty, reason: 'throttled');

      clock.advance(const Duration(seconds: 1));
      await push.onResumed();
      expect(push.available, isTrue);
      expect(h.adapter.where('POST', '/me/devices').single.body['push_token'], 'tok-1');
    });

    test('coming back to the app without a sign-in only starts push; nothing is registered', () async {
      final push = service();
      await push.start();
      messaging.canStart = true;
      clock.advance(_tenMinutes);
      await push.onResumed();
      expect(push.available, isTrue);
      expect(h.adapter.where('POST', '/me/devices'), isEmpty);
    });

    test('signing out cancels a waiting registration', () async {
      final push = service();
      await push.register();
      await push.unregister();

      messaging.canStart = true;
      clock.advance(_tenMinutes);
      await push.onResumed();
      expect(push.available, isTrue);
      expect(h.adapter.where('POST', '/me/devices'), isEmpty);
    });

    test('messages that arrive after a late start are handled', () async {
      final push = service();
      await push.start();
      messaging.canStart = true;
      clock.advance(_tenMinutes);
      await push.onResumed();

      messaging.foreground.add(const PushMessage(data: {'event': 'order.confirmed'}, title: 'Order'));
      await pumpEventQueue();
      expect(local.shown.map((m) => m.title), ['Order']);
    });

    test('two callers at once share one attempt', () async {
      final push = service();
      await push.start();
      messaging
        ..canStart = true
        ..holdStart = Completer<void>();
      clock.advance(_tenMinutes);

      final a = push.onResumed();
      final b = push.register();
      await pumpEventQueue();
      expect(messaging.attempts, 2, reason: 'one retry, not two');
      messaging.holdStart!.complete();
      await Future.wait([a, b]);
      expect(push.available, isTrue);
      expect(messaging.attempts, 2);
    });

    test('a clock that jumps back does not block retries for ever', () async {
      final push = service();
      await push.start();
      clock.advance(const Duration(hours: -3));
      await push.onResumed();
      expect(messaging.attempts, 2);
    });
  });

  group('a start that worked stays started', () {
    test('later calls never start it again or listen twice', () async {
      messaging.canStart = true;
      final push = service();
      await push.start();
      expect(push.available, isTrue);

      for (var i = 0; i < 3; i++) {
        clock.advance(const Duration(hours: 1));
        await push.start();
        await push.register();
        await push.onResumed();
      }
      expect(messaging.attempts, 1);
      expect(messaging.foregroundListeners, 1);
    });
  });

  group('a failed start never throws into the caller', () {
    test('the SDK throwing counts as unavailable, at launch and on every retry', () async {
      messaging.throwOnStart = true;
      final push = service();
      await push.start();
      await push.register();
      clock.advance(_tenMinutes);
      await push.onResumed();
      await push.start();
      expect(push.available, isFalse);
      expect(messaging.attempts, 2);
      expect(h.adapter.where('POST', '/me/devices'), isEmpty);
    });

    test('the local notifier still starts when push does not', () async {
      final push = service();
      await push.start();
      expect(local.onTap, isNotNull);
    });
  });

  group('push switched off in the build', () {
    test('the SDK is never touched, whatever is called', () async {
      final push = service(enabled: false);
      await push.start();
      await push.register();
      clock.advance(const Duration(hours: 1));
      await push.onResumed();
      await push.start();
      expect(messaging.attempts, 0);
      expect(h.adapter.where('POST', '/me/devices'), isEmpty);
    });
  });
}
