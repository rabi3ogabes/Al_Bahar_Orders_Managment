import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

/// A messaging SDK the test drives: permission answers, tokens, and every question it was asked.
class _Messaging implements PushMessaging {
  PushPermission status = PushPermission.granted;
  String? token = 'tok-1';
  bool grantWhenAsked = true;
  Object? failToken;
  final asked = <bool>[]; // `provisional` of every requestPermission call
  int tokenCalls = 0;
  final refresh = StreamController<String>.broadcast();
  final foreground = StreamController<PushMessage>.broadcast();
  final opened = StreamController<PushMessage>.broadcast();
  PushMessage? initial;

  @override
  Future<bool> initialize() async => true;

  @override
  Future<bool> requestPermission({bool provisional = false}) async {
    asked.add(provisional);
    if (status == PushPermission.denied || !grantWhenAsked) return false;
    if (status == PushPermission.notDetermined) {
      status = provisional ? PushPermission.provisional : PushPermission.granted;
    }
    return true;
  }

  @override
  Future<PushPermission> permissionStatus() async => status;

  @override
  Future<String?> getToken() async {
    tokenCalls++;
    final failure = failToken;
    if (failure != null) throw failure;
    return token;
  }

  @override
  Stream<String> get onTokenRefresh => refresh.stream;

  @override
  Stream<PushMessage> get onForegroundMessage => foreground.stream;

  @override
  Stream<PushMessage> get onMessageOpenedApp => opened.stream;

  @override
  Future<PushMessage?> getInitialMessage() async => initial;
}

void main() {
  late TestHarness h;
  late _Messaging messaging;
  late FakeLocalNotifier local;
  var language = 'ar';

  PushService service({
    String platform = 'android',
    bool staffApp = false,
    String devicesPath = '/me/devices',
    String Function()? locale,
  }) => PushService(
    messaging: messaging,
    local: local,
    api: h.read(apiClientProvider),
    prefs: h.read(appPreferencesProvider),
    enabled: true,
    devicesPath: devicesPath,
    askPermissionOnRegister: staffApp,
    localeCode: locale ?? () => language,
    platform: () => platform,
  );

  List<RecordedRequest> posts([String path = '/me/devices']) => h.adapter.where('POST', path);

  setUp(() async {
    language = 'ar';
    h = await TestHarness.create(tokens: AuthTokens.fromJson(tokens('A', 'R')), handler: (r) => ok({'ok': true}));
    messaging = _Messaging();
    local = FakeLocalNotifier();
  });

  group('the language of this phone', () {
    test('goes to the server with the token, so cards and messages are written in it', () async {
      final push = service();
      await push.register();

      expect(posts().single.body, containsPair('locale', 'ar'));
      expect(posts().single.body, containsPair('push_token', 'tok-1'));
      expect(posts().single.body['device_id'], isNotEmpty);
    });

    test('is reported again when the customer switches language, with the same token', () async {
      final push = service();
      await push.register();
      language = 'en';
      await push.onLocaleChanged();

      expect(posts(), hasLength(2));
      expect(posts().last.body, containsPair('locale', 'en'));
      expect(posts().last.body, containsPair('push_token', 'tok-1'));
      expect(posts().last.body['device_id'], posts().first.body['device_id']);
    });

    test('is not reported for a phone nobody is signed in on, or one without a token', () async {
      final push = service();
      await push.start();
      language = 'en';
      await push.onLocaleChanged(); // never registered
      expect(posts(), isEmpty);

      await push.register();
      await push.onSignedOut();
      await push.onLocaleChanged();
      expect(posts(), hasLength(1));
    });
  });

  group('the system question', () {
    test(
      'Android customers are asked nothing here: a token needs no permission, the sheet at the first order asks',
      () async {
        await service().register();
        expect(messaging.asked, isEmpty);
        expect(posts(), hasLength(1));
      },
    );

    test('iPhone customers ask for quiet delivery, which shows no dialog', () async {
      messaging.status = PushPermission.notDetermined;
      await service(platform: 'ios').register();

      expect(messaging.asked, [true]);
      expect(messaging.status, PushPermission.provisional);
      expect(posts(), hasLength(1));
    });

    test('an iPhone that said no gets no token registered, and is not asked again at every start', () async {
      messaging.status = PushPermission.denied;
      final push = service(platform: 'ios');
      await push.register();

      expect(posts(), isEmpty);
      expect(messaging.tokenCalls, 0);
    });

    test('the driver and staff apps ask the normal question, at once', () async {
      messaging.status = PushPermission.notDetermined;
      await service(staffApp: true, devicesPath: '/admin/devices').register();

      expect(messaging.asked, [false]);
      expect(posts('/admin/devices'), hasLength(1));
    });

    test('a customer who allowed notifications in the system settings is registered when the app comes back', () async {
      messaging.status = PushPermission.denied;
      final push = service(platform: 'ios');
      await push.register();
      expect(posts(), isEmpty);

      messaging.status = PushPermission.granted;
      await push.onResumed();

      expect(posts().single.body, containsPair('push_token', 'tok-1'));
      await push.onResumed();
      expect(posts(), hasLength(1), reason: 'registered now: coming back again does nothing');
    });

    test('a signed-in phone whose registration failed (no network) tries again when the app comes back', () async {
      var online = false;
      h.adapter.failConnection = (r) => !online && r.method == 'POST';
      final push = service();
      await expectLater(push.register(), throwsA(anything));
      expect(h.read(appPreferencesProvider).registeredPushToken, isNull);

      online = true;
      await push.onResumed();
      expect(h.read(appPreferencesProvider).registeredPushToken, 'tok-1');
    });

    test('the staff apps do not ask again at every return to the app while the permission is off', () async {
      messaging.status = PushPermission.denied;
      final push = service(staffApp: true, devicesPath: '/admin/devices');
      await push.register();
      expect(messaging.asked, hasLength(1));

      await push.onResumed();
      await push.onResumed();
      expect(messaging.asked, hasLength(1));
      expect(posts('/admin/devices'), isEmpty);
    });

    test('nobody signed in: coming back to the app registers nothing', () async {
      final push = service();
      await push.start();
      await push.onResumed();
      expect(posts(), isEmpty);
    });
  });

  group('the token', () {
    test('overlapping registrations share one', () async {
      final push = service();
      await Future.wait([push.register(), push.register(), push.register()]);

      expect(messaging.tokenCalls, 1);
      expect(posts(), hasLength(1));
    });

    test(
      'the install’s last token is used when the SDK has nothing new to say (the next person to sign in on this phone)',
      () async {
        final push = service();
        await push.register();
        await push.unregister();
        expect(h.adapter.where('DELETE', '/me/devices').single.body, {'push_token': 'tok-1'});

        messaging.token = null; // the SDK reports nothing: the registration is already done
        await push.register();

        expect(posts(), hasLength(2));
        expect(posts().last.body, containsPair('push_token', 'tok-1'));
      },
    );

    test('a first sign-in on a phone whose SDK reports nothing registers nothing, and does not fail', () async {
      messaging.token = null;
      await service().register();
      expect(posts(), isEmpty);
    });

    test('a refreshed token is sent, also after a first send that failed', () async {
      var failing = true;
      h.adapter.handler = (r) => failing ? error(500, 'server_error') : ok({'ok': true});
      final push = service();
      await expectLater(push.register(), throwsA(anything));

      failing = false;
      messaging.refresh.add('tok-2');
      await pumpEventQueue();

      expect(posts().last.body, containsPair('push_token', 'tok-2'));
      expect(h.read(appPreferencesProvider).registeredPushToken, 'tok-2');
    });

    test('stops being sent after sign-out', () async {
      final push = service();
      await push.register();
      await push.onSignedOut();
      messaging.refresh.add('tok-2');
      await pumpEventQueue();

      expect(posts(), hasLength(1));
    });

    test('an SDK that throws surfaces to the caller, which the app catches', () async {
      messaging.failToken = StateError('APNs token not ready');
      await expectLater(service(platform: 'ios').register(), throwsStateError);
      expect(posts(), isEmpty);
    });
  });

  group('one phone, one person', () {
    test('signing out removes the token on the server and forgets the person’s banner preferences', () async {
      final prefs = h.read(appPreferencesProvider);
      await prefs.setPushCategoryPrefs({'marketing': false});
      final push = service();
      await push.register();

      await push.unregister();

      expect(h.adapter.where('DELETE', '/me/devices'), hasLength(1));
      expect(prefs.registeredPushToken, isNull);
      expect(prefs.pushCategoryPrefs, isEmpty);
    });

    test('a session that ended cleans the phone without asking the server (it cannot be asked any more)', () async {
      final prefs = h.read(appPreferencesProvider);
      final push = service();
      await push.register();
      await prefs.setPushCategoryPrefs({'marketing': false, 'transactional': false});
      h.adapter.requests.clear();

      await push.onSignedOut();

      expect(h.adapter.requests, isEmpty);
      expect(prefs.registeredPushToken, isNull);
      expect(prefs.pushCategoryPrefs, isEmpty);
    });

    test('the next person on this phone shows every banner until their own preferences are loaded', () async {
      final prefs = h.read(appPreferencesProvider);
      await prefs.setPushCategoryPrefs({'marketing': false, 'transactional': false}); // the person before
      final push = service();
      await push.register();
      await push.onSignedOut();

      push.debugHandleForeground(
        const PushMessage(data: {'event': 'order.confirmed', 'notification_id': 'n1'}, title: 'Order'),
      );
      await pumpEventQueue();
      expect(local.shown.map((m) => m.title), ['Order']);
    });

    test('a person who signs out and in again is registered again', () async {
      final push = service();
      await push.register();
      await push.unregister();
      await push.register();

      expect(posts(), hasLength(2));
      expect(h.read(appPreferencesProvider).registeredPushToken, 'tok-1');
    });
  });

  group('banners while the app is open', () {
    test('the same notification delivered twice is shown once', () async {
      final push = service();
      const message = PushMessage(
        data: {'event': 'order.confirmed', 'notification_id': 'n-1', 'log_id': 'l-1'},
        title: 'Order',
      );
      push
        ..debugHandleForeground(message)
        ..debugHandleForeground(message);
      await pumpEventQueue();
      expect(local.shown, hasLength(1));

      push.debugHandleForeground(
        const PushMessage(data: {'event': 'order.confirmed', 'notification_id': 'n-2'}, title: 'Order 2'),
      );
      await pumpEventQueue();
      expect(local.shown.map((m) => m.title), ['Order', 'Order 2']);
    });

    test('messages without any id (a test push) are never taken for a repeat', () async {
      final push = service();
      const message = PushMessage(data: {'event': 'push.test'}, title: 'Test');
      push
        ..debugHandleForeground(message)
        ..debugHandleForeground(message);
      await pumpEventQueue();
      expect(local.shown, hasLength(2));
    });

    test('the category the server names decides the preference, whatever the event is called', () async {
      await h.read(appPreferencesProvider).setPushCategoryPrefs({'marketing': false});
      final push = service();
      push
        ..debugHandleForeground(
          const PushMessage(
            data: {'event': 'journey.abandoned_cart', 'category': 'marketing', 'log_id': 'a'},
            title: 'Cart',
          ),
        )
        ..debugHandleForeground(
          const PushMessage(data: {'event': 'staff.message', 'delivery_id': 'b'}, title: 'Hello'),
        ) // an older server: no category
        ..debugHandleForeground(
          const PushMessage(
            data: {'event': 'order.confirmed', 'category': 'transactional', 'log_id': 'c'},
            title: 'Order',
          ),
        );
      await pumpEventQueue();

      expect(local.shown.map((m) => m.title), ['Order']);
    });

    test('the unread badge is told about every message that reaches the inbox, even one the banner skips', () async {
      await h.read(appPreferencesProvider).setPushCategoryPrefs({'marketing': false});
      var told = 0;
      final push = service()..onReceived = () => told++;
      push
        ..debugHandleForeground(
          const PushMessage(data: {'event': 'campaign', 'category': 'marketing', 'log_id': 'a'}, title: 'Sale'),
        )
        ..debugHandleForeground(const PushMessage(data: {'event': 'order.confirmed', 'log_id': 'b'}, title: 'Order'))
        ..debugHandleForeground(
          const PushMessage(data: {'kind': 'order_progress', 'order_id': 'o1'}),
        ); // a card is not an inbox message

      expect(told, 2);
    });

    test('opening a notification marks it read and opens its place', () async {
      final push = service();
      final opened = <String>[];
      final visited = <String>[];
      push
        ..onOpened = opened.add
        ..onNavigate = visited.add;

      push.debugHandleTap({'event': 'order.confirmed', 'deep_link': 'albahar://orders/o1', 'notification_id': 'n1'});
      push.debugHandleTap({'event': 'referral.rewarded', 'deep_link': 'albahar://referral', 'notification_id': 'n2'});

      expect(opened, ['n1', 'n2']);
      expect(visited, ['/orders/o1', '/invite']);
    });
  });

  group('helpers', () {
    test('a push is filed by the category the server names, else by its event', () {
      expect(pushCategory({'category': 'auction', 'event': 'order.x'}), 'auction');
      expect(pushCategory({'category': 'bogus', 'event': 'stock.back_in_stock'}), 'marketing');
      expect(pushCategory({'event': 'auction.outbid'}), 'auction');
      expect(pushCategory({'event': 'order.confirmed'}), 'transactional');
      expect(pushCategory({}), 'transactional');
    });

    test('Android channels follow the category, with the default for anything else', () {
      expect(PushChannels.forCategory('transactional'), 'albahar_default');
      expect(PushChannels.forCategory('marketing'), 'albahar_offers');
      expect(PushChannels.forCategory('auction'), 'albahar_auctions');
      expect(PushChannels.forCategory('whatever'), 'albahar_default');
    });

    test('a notification is told apart by its inbox entry, else its delivery, else its send', () {
      expect(pushKey({'notification_id': 'n', 'delivery_id': 'd', 'log_id': 'l'}), 'notification_id:n');
      expect(pushKey({'notification_id': '', 'delivery_id': 'd'}), 'delivery_id:d');
      expect(pushKey({'log_id': 'l'}), 'log_id:l');
      expect(pushKey({'event': 'x'}), isNull);
    });

    test('the id of a repeated notification is the same on every run, and fits the system’s range', () {
      expect(stableId('notification_id:abc'), stableId('notification_id:abc'));
      expect(stableId('notification_id:abc'), isNot(stableId('notification_id:abd')));
      expect(
        stableId('notification_id:abc'),
        1531099340,
      ); // fixed: a changing value would stack a second card after an update of the app
      for (final key in ['a', 'log_id:0190c2a0-1b2c-7d3e-8f40-123456789abc', 'ا' * 200]) {
        expect(stableId(key), inInclusiveRange(0, 0x7fffffff));
      }
    });
  });
}
