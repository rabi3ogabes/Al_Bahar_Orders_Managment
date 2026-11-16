import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

/// A messaging SDK that is running, with one token, and a notification permission the test sets.
class _Sdk extends NoopPushMessaging {
  PushPermission status = PushPermission.granted;
  Object? statusFails;
  int reads = 0;

  @override
  Future<bool> initialize() async => true;

  @override
  Future<bool> requestPermission({bool provisional = false}) async {
    if (status == PushPermission.notDetermined) {
      status = provisional ? PushPermission.provisional : PushPermission.granted;
    }
    status = provisional ? PushPermission.provisional : PushPermission.granted;
    return status == PushPermission.granted || status == PushPermission.provisional;
  }

  @override
  Future<PushPermission> permissionStatus() async {
    reads++;
    final failure = statusFails;
    if (failure != null) throw failure;
    return status;
  }

  @override
  Future<String?> getToken() async => 'tok-1';
}

/// What the phone tells the server about its notification permission (`notification_permission` of `POST /me/devices`): with every
/// registration, right after the person answered the question, and when the app comes back and the answer is a new one.
void main() {
  late TestHarness h;
  late _Sdk sdk;

  PushService service({String platform = 'android', bool staff = false, String path = '/me/devices'}) => PushService(
    messaging: sdk,
    local: FakeLocalNotifier(),
    api: h.read(apiClientProvider),
    prefs: h.read(appPreferencesProvider),
    enabled: true,
    devicesPath: path,
    askPermissionOnRegister: staff,
    localeCode: () => 'en',
    platform: () => platform,
  );

  List<RecordedRequest> posts([String path = '/me/devices']) => h.adapter.where('POST', path);
  String? sent(RecordedRequest r) => r.body['notification_permission'] as String?;

  setUp(() async {
    h = await TestHarness.create(tokens: AuthTokens.fromJson(tokens('A', 'R')), handler: (r) => ok({'ok': true}));
    sdk = _Sdk();
  });

  group('what the phone says', () {
    test('every standing of the messaging SDK has one word, or none when the phone cannot say', () {
      expect(PushPermission.granted.wire, 'granted');
      expect(
        PushPermission.provisional.wire,
        'granted',
        reason: 'quiet delivery: the push is shown, in the notification centre',
      );
      expect(PushPermission.notDetermined.wire, 'not_asked');
      expect(PushPermission.denied.wire, 'denied');
      expect(PushPermission.unavailable.wire, isNull, reason: 'no Firebase here: nothing to report');
    });
  });

  group('every registration carries it', () {
    test('allowed', () async {
      await service().register();
      expect(sent(posts().single), 'granted');
    });

    test('never asked: the phone is registered all the same, and the server is told it was not asked', () async {
      sdk.status = PushPermission.notDetermined;
      await service().register();
      expect(posts(), hasLength(1));
      expect(sent(posts().single), 'not_asked');
      expect(posts().single.body, containsPair('push_token', 'tok-1'));
    });

    test('switched off', () async {
      sdk.status = PushPermission.denied;
      await service().register();
      expect(sent(posts().single), 'denied');
    });

    test('an iPhone on quiet delivery counts as on', () async {
      sdk.status = PushPermission.notDetermined;
      await service(platform: 'ios').register();
      expect(sent(posts().single), 'granted');
    });

    test('unknown is left out, not guessed', () async {
      sdk.status = PushPermission.unavailable;
      await service().register();
      expect(posts().single.body.containsKey('notification_permission'), isFalse);
      expect(posts().single.body, containsPair('push_token', 'tok-1'));
    });

    test('an SDK that cannot say leaves it out, and never stops the registration', () async {
      sdk.statusFails = StateError('no answer');
      await service().register();
      expect(posts(), hasLength(1));
      expect(posts().single.body.containsKey('notification_permission'), isFalse);
      expect(h.read(appPreferencesProvider).registeredPushToken, 'tok-1');
    });

    test('the driver and staff apps report it to their own endpoint', () async {
      await service(staff: true, path: '/admin/devices').register();
      expect(sent(posts('/admin/devices').single), 'granted');
    });

    test('a language change and a refreshed token carry the state of that moment', () async {
      sdk.status = PushPermission.notDetermined;
      final push = service();
      await push.register();
      sdk.status = PushPermission.granted;
      await push.onLocaleChanged();
      expect(sent(posts().last), 'granted');
    });
  });

  group('right after the person answered the question', () {
    test('the same token is sent again, with the new answer', () async {
      sdk.status = PushPermission.notDetermined;
      final push = service();
      await push.register();
      sdk.status = PushPermission.granted;

      await push.onPermissionChanged();

      expect(posts(), hasLength(2));
      expect(sent(posts().first), 'not_asked');
      expect(sent(posts().last), 'granted');
      expect(posts().last.body, containsPair('push_token', 'tok-1'));
      expect(posts().last.body['device_id'], posts().first.body['device_id']);
    });

    test('"don\'t allow" is told as well', () async {
      sdk.status = PushPermission.notDetermined;
      final push = service();
      await push.register();
      sdk.status = PushPermission.denied;
      await push.onPermissionChanged();
      expect(sent(posts().last), 'denied');
    });

    test('a phone with no token yet is registered now, with the answer', () async {
      sdk.status = PushPermission.denied;
      final push = service(platform: 'ios');
      await push.register();
      expect(posts(), isEmpty, reason: 'an iPhone that said no is not registered');

      sdk.status = PushPermission.granted;
      await push.onPermissionChanged();
      expect(sent(posts().single), 'granted');
    });

    test('nobody signed in: nothing is sent', () async {
      final push = service();
      await push.start();
      await push.onPermissionChanged();
      expect(posts(), isEmpty);

      await push.register();
      await push.onSignedOut();
      await push.onPermissionChanged();
      expect(posts(), hasLength(1));
    });

    test('a server that does not answer never breaks anything', () async {
      final push = service();
      await push.register();
      h.adapter.failConnection = (r) => r.method == 'POST';
      sdk.status = PushPermission.denied;

      await push.onPermissionChanged();

      expect(posts(), hasLength(2), reason: 'one attempt, which the server did not take');
      expect(h.read(appPreferencesProvider).registeredPushToken, 'tok-1');
    });

    test('an SDK that cannot say is not an error, and tells nothing new', () async {
      final push = service();
      await push.register();
      sdk.statusFails = StateError('no answer');
      await push.onPermissionChanged();
      expect(posts().last.body.containsKey('notification_permission'), isFalse);
    });
  });

  group('when the app comes back', () {
    test('only a change is sent', () async {
      final push = service();
      await push.register();
      await push.onResumed();
      await push.onResumed();
      expect(posts(), hasLength(1), reason: 'same answer as the last one sent');

      sdk.status = PushPermission.denied; // switched off in the phone settings while the app was away
      await push.onResumed();
      expect(posts(), hasLength(2));
      expect(sent(posts().last), 'denied');
      expect(posts().last.body, containsPair('push_token', 'tok-1'));

      await push.onResumed();
      expect(posts(), hasLength(2));

      sdk.status = PushPermission.granted;
      await push.onResumed();
      expect(posts(), hasLength(3));
      expect(sent(posts().last), 'granted');
    });

    test('an answer the phone cannot give is not sent, and does not forget the last one', () async {
      final push = service();
      await push.register();
      sdk.statusFails = StateError('no answer');
      await push.onResumed();
      expect(posts(), hasLength(1));

      sdk.statusFails = null;
      await push.onResumed();
      expect(posts(), hasLength(1), reason: 'granted is still what the server knows');
    });

    test('a send that failed is tried again at the next return', () async {
      final push = service();
      await push.register();
      var online = false;
      h.adapter.failConnection = (r) => !online && r.method == 'POST';
      sdk.status = PushPermission.denied;

      await push.onResumed();
      expect(posts(), hasLength(2), reason: 'one attempt, which the server did not take');

      online = true;
      await push.onResumed();
      expect(posts(), hasLength(3), reason: 'the failed send was not counted as told');
      expect(sent(posts().last), 'denied');
    });

    test('a phone still waiting for its token registers with the state instead of sending it twice', () async {
      var online = false;
      h.adapter.failConnection = (r) => !online && r.method == 'POST';
      final push = service();
      await expectLater(push.register(), throwsA(anything));

      online = true;
      await push.onResumed();
      expect(posts(), hasLength(2), reason: 'the failed first attempt, then the registration; no third send');
      expect(sent(posts().last), 'granted');
      expect(h.read(appPreferencesProvider).registeredPushToken, 'tok-1');
    });

    test('the staff apps report their change to their own endpoint', () async {
      final push = service(staff: true, path: '/admin/devices');
      await push.register();
      sdk.status = PushPermission.denied;
      await push.onResumed();
      expect(posts('/admin/devices'), hasLength(2));
      expect(sent(posts('/admin/devices').last), 'denied');
    });

    test('nobody signed in: nothing is sent', () async {
      final push = service();
      await push.start();
      sdk.status = PushPermission.denied;
      await push.onResumed();
      expect(posts(), isEmpty);
    });

    test('after signing out and in again the server is told afresh', () async {
      final push = service();
      await push.register();
      await push.onSignedOut();
      await push.register();
      expect(posts(), hasLength(2));
      expect(sent(posts().last), 'granted');
    });
  });
}
