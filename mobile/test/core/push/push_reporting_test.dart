import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/firebase_options_source.dart';
import 'package:albahar_customer/core/push/firebase_starter.dart';
import 'package:albahar_customer/core/push/push_diagnostics.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/push/push_token.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:fake_async/fake_async.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

/// Every way push can fail on a phone says so to the server (one short code each), and not a single one of those reports can hurt the app:
/// they are never waited for, never thrown, and a broken reporter changes nothing about registering.

/// Collects what is reported, in order, as `stage|code`.
class _Reports {
  final calls = <(String, String, String?)>[];
  List<String> get keys => [for (final c in calls) '${c.$1}|${c.$2}'];

  Future<void> call(String stage, String code, [String? message]) async => calls.add((stage, code, message));
}

const _answer = {
  'enabled': true,
  'native': {
    'projectId': 'al-bahar-home',
    'messagingSenderId': '123456789012',
    'apiKey': 'AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R',
    'appId': '1:123456789012:android:0a1b2c3d4e5f6a7b',
  },
};

void main() {
  // ── The token ────────────────────────────────────────────────────────────────────────────────────────────────────

  group('the push token', () {
    late _Reports reports;
    late StreamController<String> installationIds;
    var registerCalls = 0;
    Object? registerFails;

    PushTokenFetcher fetcher(
      Future<String?> Function() registrationToken, {
      Duration timeout = const Duration(seconds: 20),
    }) => PushTokenFetcher(
      registrationToken: registrationToken,
      register: () async {
        registerCalls++;
        final failure = registerFails;
        if (failure != null) throw failure;
        installationIds.add('fid-22-characters-long');
      },
      registered: () => installationIds.stream,
      report: reports.call,
      timeout: timeout,
    );

    setUp(() {
      reports = _Reports();
      installationIds = StreamController<String>.broadcast();
      registerCalls = 0;
      registerFails = null;
    });

    tearDown(() => installationIds.close());

    test('is the registration token from getToken(), not the installation id, and nothing is reported', () async {
      final token = await fetcher(() async => 'fid22:APA91bTheWholeRegistrationToken').fetch();
      expect(token, 'fid22:APA91bTheWholeRegistrationToken');
      expect(registerCalls, 0, reason: 'register() only hands out the installation id');
      expect(reports.calls, isEmpty);
    });

    test('Firebase refusing is reported with Firebase\'s own reason, and the app does not wait for it', () async {
      final token = await fetcher(
        () async => throw Exception('[firebase_messaging/unknown] java.io.IOException: SERVICE_NOT_AVAILABLE'),
      ).fetch();
      expect(token, isNull);
      expect(reports.keys, ['token|service_not_available']);
      expect(reports.calls.single.$3, contains('SERVICE_NOT_AVAILABLE'));
      expect(registerCalls, 0, reason: 'the same registration would fail the same way');
    });

    test('a blocked API key is told as such', () async {
      await fetcher(() async => throw Exception('Requests from this Android client application <empty> are blocked.'))
          .fetch();
      expect(reports.keys, ['token|api_key_blocked']);
    });

    test('an error nobody knows is still reported, as token_failed', () async {
      await fetcher(() async => throw StateError('something new')).fetch();
      expect(reports.keys, ['token|token_failed']);
    });

    test('no answer in time is a time-out, not silence', () {
      fakeAsync((async) {
        String? result = 'unset';
        unawaited(fetcher(() => Completer<String?>().future).fetch().then((t) => result = t));
        async.elapse(const Duration(seconds: 21));
        expect(result, isNull);
        expect(reports.keys, ['token|timeout']);
        expect(reports.calls.single.$3, contains('20 seconds'));
      });
    });

    test('an empty token is reported, then the installation id is the second choice', () async {
      final token = await fetcher(() async => '').fetch();
      expect(reports.keys, ['token|no_token']);
      expect(token, 'fid-22-characters-long');
      expect(registerCalls, 1);
    });

    test('a plugin without getToken() falls back to the installation id', () async {
      final token = await fetcher(() async => throw MissingPluginException('getToken')).fetch();
      expect(token, 'fid-22-characters-long');
      expect(registerCalls, 1);
    });

    test('an iPhone without its Apple token yet says so, then tries the installation id', () async {
      final token = await fetcher(
        () async => throw Exception('[firebase_messaging/apns-token-not-set] APNS token has not been received'),
      ).fetch();
      expect(reports.keys, ['token|apns_missing']);
      expect(token, 'fid-22-characters-long');
    });

    test('the second choice failing is reported too, and a late time-out is not an uncaught error', () {
      fakeAsync((async) {
        registerFails = StateError('register failed');
        String? result = 'unset';
        unawaited(fetcher(() async => '').fetch().then((t) => result = t));
        async.elapse(const Duration(seconds: 30));
        expect(result, isNull);
        expect(reports.keys, ['token|no_token', 'token|token_failed']);
      });
    });

    test('the installation id never arriving is a time-out as well', () {
      fakeAsync((async) {
        final never = StreamController<String>();
        final silent = PushTokenFetcher(
          registrationToken: () async => '',
          register: () async {},
          registered: () => never.stream,
          report: reports.call,
        );
        String? result = 'unset';
        unawaited(silent.fetch().then((t) => result = t));
        async.elapse(const Duration(seconds: 30));
        expect(result, isNull);
        expect(reports.keys, ['token|no_token', 'token|timeout']);
      });
    });

    test('an installation-id stream that ends or errors is reported and does not throw', () async {
      final ended = PushTokenFetcher(
        registrationToken: () async => '',
        register: () async {},
        registered: () => const Stream<String>.empty(),
        report: reports.call,
      );
      expect(await ended.fetch(), isNull);
      expect(reports.keys, ['token|no_token', 'token|token_failed']);
    });

    test('a reporter that throws (or does not exist) changes nothing', () async {
      final noisy = PushTokenFetcher(
        registrationToken: () async => throw StateError('boom'),
        register: () async {},
        registered: () => const Stream<String>.empty(),
        report: (a, b, [c]) => throw StateError('reporter broke'),
      );
      expect(await noisy.fetch(), isNull);
      final quiet = PushTokenFetcher(
        registrationToken: () async => throw StateError('boom'),
        register: () async {},
        registered: () => const Stream<String>.empty(),
      );
      expect(await quiet.fetch(), isNull);
    });
  });

  // ── Reading the settings and starting Firebase ───────────────────────────────────────────────────────────────────

  group('reading the Firebase settings from the server', () {
    Future<FirebaseOptionsResult> load(Object? answer) =>
        FirebaseOptionsSource(fetch: () async => answer is Exception ? throw answer : answer).loadResult();

    test('good settings come back as options, with no code', () async {
      final r = await load(_answer);
      expect(r.options?.projectId, 'al-bahar-home');
      expect(r.code, isNull);
    });

    test('each reason there are none has its own code', () async {
      expect((await load({'enabled': false, 'native': null})).code, 'push_off');
      expect((await load({'enabled': true, 'native': null})).code, 'app_not_set');
      expect((await load({'enabled': true})).code, 'app_not_set');
      expect((await load({'enabled': true, 'native': <String, Object?>{}})).code, 'options_invalid');
      expect((await load('nonsense')).code, 'options_invalid');
      expect((await load(null)).code, 'options_invalid');
    });

    test('no answer, or an error answer, is "server_unreachable" and says what kind', () async {
      final down = await load(Exception('offline'));
      expect(down.code, 'server_unreachable');
      final broken = await load(const ApiException(kind: ApiErrorKind.server, statusCode: 502));
      expect(broken.code, 'server_unreachable');
      expect(broken.message, 'HTTP 502 server');
    });

    test('load() still answers with the options or null, as before', () async {
      expect(await FirebaseOptionsSource(fetch: () async => _answer).load(), isNotNull);
      expect(await FirebaseOptionsSource(fetch: () async => {'enabled': false}).load(), isNull);
    });
  });

  group('starting Firebase', () {
    FirebaseStarter starter(
      Object? answer, {
      required _Reports reports,
      Future<void> Function(FirebaseOptions)? fromOptions,
      Future<void> Function()? fromFiles,
      PushReporter? report,
    }) => FirebaseStarter(
      platform: TargetPlatform.android,
      isStarted: () => false,
      options: FirebaseOptionsSource(fetch: () async => answer is Exception ? throw answer : answer),
      fromNativeFiles: fromFiles ?? () async => throw StateError('no google-services.json'),
      fromOptions: fromOptions ?? (_) async {},
      report: report ?? reports.call,
    );

    test('a build with the native files is silent, and so is a start that works', () async {
      final r1 = _Reports();
      expect(await starter(_answer, reports: r1, fromFiles: () async {}).start(), isTrue);
      final r2 = _Reports();
      expect(await starter(_answer, reports: r2).start(), isTrue);
      expect(r1.calls, isEmpty);
      expect(r2.calls, isEmpty);
    });

    test('settings that cannot be had are reported at the "options" step with the reason', () async {
      for (final (answer, code) in <(Object?, String)>[
        ({'enabled': false}, 'push_off'),
        ({'enabled': true, 'native': null}, 'app_not_set'),
        (Exception('offline'), 'server_unreachable'),
        ('junk', 'options_invalid'),
      ]) {
        final reports = _Reports();
        expect(await starter(answer, reports: reports).start(), isFalse, reason: code);
        expect(reports.keys, ['options|$code']);
      }
    });

    test('Firebase refusing the settings is reported at the "init" step, classified', () async {
      final reports = _Reports();
      final started = await starter(
        _answer,
        reports: reports,
        fromOptions: (_) async => throw Exception('API key not valid. Please pass a valid API key.'),
      ).start();
      expect(started, isFalse);
      expect(reports.keys, ['init|api_key_invalid']);

      final other = _Reports();
      await starter(_answer, reports: other, fromOptions: (_) async => throw StateError('rejected')).start();
      expect(other.keys, ['init|init_failed']);
    });

    test('a reporter that never finishes, or throws, does not hold start-up or change its answer', () async {
      final never = Completer<void>();
      final stuck = starter({'enabled': false}, reports: _Reports(), report: (a, b, [c]) => never.future);
      expect(await stuck.start().timeout(const Duration(seconds: 2)), isFalse);

      final broken = starter(
        {'enabled': false},
        reports: _Reports(),
        report: (a, b, [c]) => throw StateError('reporter broke'),
      );
      expect(await broken.start(), isFalse);
    });

    test('without a reporter nothing changes (the old constructor still works)', () async {
      final plain = FirebaseStarter(
        platform: TargetPlatform.android,
        isStarted: () => false,
        options: FirebaseOptionsSource(fetch: () async => {'enabled': false}),
        fromNativeFiles: () async => throw StateError('none'),
      );
      expect(await plain.start(), isFalse);
    });
  });

  // ── Registering the token ────────────────────────────────────────────────────────────────────────────────────────

  group('registering this phone', () {
    late TestHarness h;
    late _Reports reports;
    late _Phone messaging;
    late FakeLocalNotifier local;
    var serverReply = const FakeReply(201, {
      'data': {'registered': true},
    });
    var offline = false;

    PushService service({PushReporter? report, bool askPermission = false}) => PushService(
      messaging: messaging,
      local: local,
      api: h.read(apiClientProvider),
      prefs: h.read(appPreferencesProvider),
      enabled: true,
      askPermissionOnRegister: askPermission,
      report: report ?? reports.call,
    );

    setUp(() async {
      serverReply = const FakeReply(201, {
        'data': {'registered': true},
      });
      offline = false;
      h = await TestHarness.create(
        tokens: AuthTokens.fromJson(tokens('A', 'R')),
        handler: (r) => r.path == '/me/devices' ? serverReply : ok({'ok': true}),
      );
      h.adapter.failConnection = (r) => offline && r.path == '/me/devices';
      reports = _Reports();
      messaging = _Phone();
      local = FakeLocalNotifier();
    });

    test('a phone that registered says so once, with nothing personal', () async {
      await service().register();
      expect(reports.keys, ['registered|ok']);
      expect(reports.calls.single.$3, isNull);
    });

    test('a server that fails while saving the token is reported, and the failure still reaches the caller', () async {
      serverReply = error(500, 'server_error');
      await expectLater(service().register(), throwsA(isA<ApiException>()));
      expect(reports.keys, ['register|server_error']);
    });

    test('each way the server can refuse has its own code', () async {
      for (final (reply, code) in <(FakeReply, String)>[
        (error(422, 'validation_failed'), 'rejected'),
        (error(403, 'forbidden'), 'rejected'),
        (error(429, 'too_many'), 'rejected'),
        (error(503, 'down'), 'server_error'),
      ]) {
        reports = _Reports();
        serverReply = reply;
        await expectLater(service().register(), throwsA(isA<ApiException>()), reason: code);
        expect(reports.keys, ['register|$code']);
      }
    });

    test('no network while saving the token is reported as such', () async {
      offline = true;
      await expectLater(service().register(), throwsA(isA<ApiException>()));
      expect(reports.keys, ['register|no_network']);
    });

    test('a person who refuses notifications (staff apps ask at once) is reported at the "permission" step', () async {
      messaging
        ..status = PushPermission.denied
        ..grantWhenAsked = false;
      await service(askPermission: true).register();
      expect(reports.keys, ['permission|permission_denied']);
      expect(h.adapter.where('POST', '/me/devices'), isEmpty);
    });

    test(
      'a customer whose notifications are off in the phone settings still registers, and the dashboard is told',
      () async {
        messaging.status = PushPermission.denied;
        await service().register();
        expect(reports.keys, ['permission|permission_denied', 'registered|ok']);
        expect(h.adapter.where('POST', '/me/devices'), hasLength(1));
      },
    );

    test(
      'a customer who has not been asked yet registers, and the dashboard is told why a push may stay silent',
      () async {
        messaging.status = PushPermission.notDetermined;
        await service().register();
        expect(reports.keys, ['permission|permission_not_asked', 'registered|ok']);
        expect(h.adapter.where('POST', '/me/devices'), hasLength(1));
      },
    );

    test('a broken reporter changes nothing about registering', () async {
      await service(report: (a, b, [c]) => throw StateError('reporter broke')).register();
      expect(h.adapter.where('POST', '/me/devices'), hasLength(1));
      await service(report: (a, b, [c]) => Completer<void>().future).register();
      expect(h.adapter.where('POST', '/me/devices'), hasLength(2));
    });

    test(
      'a phone with no token asks again later, 30 seconds, 2 minutes and 10 minutes, then leaves it to the next return',
      () {
        fakeAsync((async) {
          messaging.token = null;
          final push = service();
          unawaited(push.register());
          async.flushMicrotasks();
          expect(messaging.tokenCalls, 1);

          async.elapse(const Duration(seconds: 29));
          expect(messaging.tokenCalls, 1);
          async.elapse(const Duration(seconds: 2));
          expect(messaging.tokenCalls, 2, reason: 'after 30 seconds');
          async.elapse(const Duration(minutes: 2, seconds: 1));
          expect(messaging.tokenCalls, 3, reason: 'then 2 minutes');
          async.elapse(const Duration(minutes: 10, seconds: 1));
          expect(messaging.tokenCalls, 4, reason: 'then 10 minutes');
          async.elapse(const Duration(hours: 5));
          expect(messaging.tokenCalls, 4, reason: 'and no more while the app stays open');
          expect(h.adapter.where('POST', '/me/devices'), isEmpty);
        });
      },
    );

    test('the second try gets the token and registers, and nothing more is scheduled', () {
      fakeAsync((async) {
        messaging.token = null;
        final push = service();
        unawaited(push.register());
        async.flushMicrotasks();
        messaging.token = 'tok-2';
        async.elapse(const Duration(seconds: 31));
        async.flushMicrotasks();
        expect(h.adapter.where('POST', '/me/devices').single.body['push_token'], 'tok-2');
        expect(reports.keys, ['registered|ok']);
        async.elapse(const Duration(hours: 1));
        expect(messaging.tokenCalls, 2);
      });
    });

    test('a failed send is tried again too', () {
      fakeAsync((async) {
        serverReply = error(500, 'server_error');
        final push = service();
        unawaited(push.register().catchError((Object _) {}));
        async.elapse(const Duration(seconds: 1));
        expect(h.adapter.where('POST', '/me/devices'), hasLength(1));
        serverReply = const FakeReply(201, {
          'data': {'registered': true},
        });
        async.elapse(const Duration(seconds: 31));
        async.flushMicrotasks();
        expect(h.adapter.where('POST', '/me/devices'), hasLength(2));
        expect(reports.keys, ['register|server_error', 'registered|ok']);
      });
    });

    test('signing out stops the waiting retries', () {
      fakeAsync((async) {
        messaging.token = null;
        final push = service();
        unawaited(push.register());
        async.flushMicrotasks();
        unawaited(push.onSignedOut());
        async.flushMicrotasks();
        async.elapse(const Duration(hours: 1));
        expect(messaging.tokenCalls, 1);
      });
    });
  });
}

/// A messaging SDK the test drives.
class _Phone implements PushMessaging {
  PushPermission status = PushPermission.granted;
  bool grantWhenAsked = true;
  String? token = 'tok-1';
  int tokenCalls = 0;

  @override
  Future<bool> initialize() async => true;

  @override
  Future<bool> requestPermission({bool provisional = false}) async => grantWhenAsked && status != PushPermission.denied;

  @override
  Future<PushPermission> permissionStatus() async => status;

  @override
  Future<String?> getToken() async {
    tokenCalls++;
    return token;
  }

  final refresh = StreamController<String>.broadcast();

  @override
  Stream<String> get onTokenRefresh => refresh.stream;

  @override
  Stream<PushMessage> get onForegroundMessage => const Stream.empty();

  @override
  Stream<PushMessage> get onMessageOpenedApp => const Stream.empty();

  @override
  Future<PushMessage?> getInitialMessage() async => null;
}
