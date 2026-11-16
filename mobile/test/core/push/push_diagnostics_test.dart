import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/push/push_diagnostics.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:fake_async/fake_async.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// What the phone tells the server about its push set-up: the code for each kind of failure, the scrubbing of the words that go along, and
/// the reporter that sends each problem once a day, never throws and never carries anything personal.

void main() {
  group('classifyPushError turns Firebase\'s words into a code the dashboard explains', () {
    const cases = {
      '[firebase_messaging/unknown] java.io.IOException: SERVICE_NOT_AVAILABLE': 'service_not_available',
      '[firebase_messaging/unknown] java.util.concurrent.ExecutionException: com.google.firebase.installations.FirebaseInstallationsException: Firebase Installations Service is unavailable. Please try again later.':
          'service_not_available',
      '[firebase_messaging/unknown] java.io.IOException: AUTHENTICATION_FAILED': 'fis_auth_error',
      '[firebase_messaging/unknown] FIS_AUTH_ERROR': 'fis_auth_error',
      '[firebase_messaging/unknown] Requests from this Android client application <empty> are blocked.':
          'api_key_blocked',
      'API_KEY_ANDROID_APP_BLOCKED': 'api_key_blocked',
      'The API key is blocked for this referer': 'api_key_blocked',
      'API key not valid. Please pass a valid API key.': 'api_key_invalid',
      'Firebase Installations API has not been used in project 704229358509 before or it is disabled.': 'api_disabled',
      'SERVICE_DISABLED': 'api_disabled',
      '[core/invalid-app-id] The application ID is invalid': 'invalid_app_id',
      '[firebase_messaging/apns-token-not-set] APNS token has not been received on the device yet.': 'apns_missing',
      'java.io.IOException: TOO_MANY_REGISTRATIONS': 'too_many_registrations',
      'java.io.IOException: INVALID_PARAMETERS': 'invalid_argument',
      'INVALID_ARGUMENT: request contains an invalid argument': 'invalid_argument',
      'java.net.UnknownHostException: Unable to resolve host "fcmregistrations.googleapis.com"': 'no_network',
      'SocketException: Failed host lookup': 'no_network',
      'Something nobody has seen before': 'the fallback',
    };
    cases.forEach((text, code) {
      test(text.length > 70 ? '${text.substring(0, 70)}…' : text, () {
        expect(classifyPushError(Exception(text), fallback: 'the fallback'), code);
      });
    });

    test('a time-out is a time-out', () {
      expect(classifyPushError(TimeoutException('x')), 'timeout');
      expect(classifyPushError(StateError('Connection timed out')), 'timeout');
    });

    test('without a fallback an unknown error is "unknown"', () {
      expect(classifyPushError(StateError('odd')), 'unknown');
    });
  });

  group('classifyRegisterError names why the server did not take a token it was given', () {
    test('by what the server or the network did', () {
      ApiException e(ApiErrorKind kind, [int? status]) => ApiException(kind: kind, statusCode: status);
      expect(classifyRegisterError(e(ApiErrorKind.offline)), 'no_network');
      expect(classifyRegisterError(e(ApiErrorKind.timeout)), 'no_network');
      expect(classifyRegisterError(e(ApiErrorKind.unauthorized, 401)), 'unauthorized');
      expect(classifyRegisterError(e(ApiErrorKind.server, 500)), 'server_error');
      expect(classifyRegisterError(e(ApiErrorKind.validation, 422)), 'rejected');
      expect(classifyRegisterError(e(ApiErrorKind.forbidden, 403)), 'rejected');
      expect(classifyRegisterError(e(ApiErrorKind.rateLimited, 429)), 'rejected');
      expect(classifyRegisterError(e(ApiErrorKind.unknown)), 'register_failed');
      expect(classifyRegisterError(StateError('odd')), 'register_failed');
    });
  });

  group('scrubPushText keeps secrets and people out of what the phone says', () {
    test('an API key, an e-mail address, a token, a number and a query string are replaced', () {
      const dirty =
          'API key AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R failed for a@b.example token dQw4w9WgXcQ:APA91bHun4MxP5egoKMwt2KZFBaFUH call +97455123456 at https://x.example/v1?key=abc123&token=zzz';
      final clean = scrubPushText(dirty);
      expect(clean, isNot(contains('AIzaSy')));
      expect(clean, isNot(contains('a@b.example')));
      expect(clean, isNot(contains('APA91b')));
      expect(clean, isNot(contains('97455123456')));
      expect(clean, isNot(contains('abc123')));
      expect(clean, allOf(contains('[key]'), contains('[email]'), contains('[token]'), contains('[number]')));
    });

    test('whitespace is collapsed and the text is cut at 200 characters', () {
      expect(scrubPushText('  a \n\n b\t c  '), 'a b c');
      expect(scrubPushText('ab ' * 200).length, 200);
      expect(scrubPushText('ab ' * 200, max: 64).length, 64);
    });

    test('ordinary Firebase words survive', () {
      const text = 'java.io.IOException: SERVICE_NOT_AVAILABLE';
      expect(scrubPushText(text), text);
    });
  });

  group('the reporter', () {
    late AppPreferences prefs;
    late List<Map<String, Object?>> sent;
    late DateTime clock;
    Object? failure;

    PushDiagnostics make({bool enabled = true, AppPreferences? with_}) => PushDiagnostics(
      prefs: with_ ?? prefs,
      enabled: enabled,
      now: () => clock,
      osVersion: () => 'Linux 6.1.75-android14 #1 SMP',
      app: () => 'driver',
      platform: () => 'android',
      version: () => '1.0.0+12',
      send: (body) async {
        if (failure != null) throw failure!;
        sent.add(body);
      },
    );

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      prefs = AppPreferences(await SharedPreferences.getInstance());
      sent = [];
      failure = null;
      clock = DateTime.utc(2026, 11, 16, 9);
    });

    test('sends the app, platform, step, code, words, versions and the install id, and nothing else', () async {
      await make().report(PushStage.token, 'api_key_blocked', 'Requests from this client are blocked.');

      expect(sent, hasLength(1));
      expect(sent.single.keys.toSet(), {
        'app',
        'platform',
        'stage',
        'code',
        'message',
        'app_version',
        'os_version',
        'install_id',
      });
      expect(sent.single, containsPair('app', 'driver'));
      expect(sent.single, containsPair('platform', 'android'));
      expect(sent.single, containsPair('stage', 'token'));
      expect(sent.single, containsPair('code', 'api_key_blocked'));
      expect(sent.single, containsPair('app_version', '1.0.0+12'));
      expect(sent.single['install_id'], prefs.installId);
      expect((sent.single['install_id']! as String).length, greaterThanOrEqualTo(8));
    });

    test('the words are scrubbed before they leave the phone', () async {
      await make().report(
        PushStage.token,
        'token_failed',
        'key AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R for a@b.example',
      );
      final message = sent.single['message']! as String;
      expect(message, isNot(contains('AIza')));
      expect(message, isNot(contains('a@b.example')));
      expect(message.length, lessThanOrEqualTo(200));
    });

    test('words and version are left out when there are none', () async {
      final d = PushDiagnostics(
        prefs: prefs,
        osVersion: () => '',
        app: () => 'staff',
        platform: () => 'ios',
        version: () => null,
        send: (b) async => sent.add(b),
      );
      await d.report(PushStage.init, 'init_failed');
      expect(sent.single.keys.toSet(), {'app', 'platform', 'stage', 'code', 'install_id'});
    });

    test('the same problem is told once per run, even if the server did not take it', () async {
      failure = StateError('offline');
      final d = make();
      await d.report(PushStage.token, 'timeout');
      failure = null;
      await d.report(PushStage.token, 'timeout');
      expect(sent, isEmpty, reason: 'tried once this run; the next run tries again');

      final next = make();
      await next.report(PushStage.token, 'timeout');
      expect(sent, hasLength(1));
    });

    test('a problem the server took is not told again that day, by any later run, but is tomorrow', () async {
      await make().report(PushStage.token, 'timeout');
      await make().report(PushStage.token, 'timeout');
      await make().report(PushStage.token, 'service_not_available'); // another problem: told
      expect(sent.map((b) => b['code']), ['timeout', 'service_not_available']);

      clock = clock.add(const Duration(days: 1));
      await make().report(PushStage.token, 'timeout');
      expect(sent.map((b) => b['code']), ['timeout', 'service_not_available', 'timeout']);
    });

    test('yesterday\'s notes are dropped, so the list stays short', () async {
      await make().report(PushStage.token, 'timeout');
      clock = clock.add(const Duration(days: 1));
      await make().report(PushStage.options, 'push_off');
      expect(prefs.pushReports.keys, ['options|push_off']);
    });

    test('the day is the UTC day, written yyyy-mm-dd', () async {
      await make().report(PushStage.registered, 'ok');
      expect(prefs.pushReports, {'registered|ok': '2026-11-16'});
    });

    test('it never throws, whatever goes wrong', () async {
      for (final boom in <Object>[StateError('x'), TimeoutException('y'), 'a string', Exception('z')]) {
        failure = boom;
        await make().report(PushStage.token, 'timeout'); // completes normally
      }
      final syncThrow = PushDiagnostics(prefs: prefs, send: (_) => throw StateError('sync'));
      await syncThrow.report(PushStage.token, 'timeout');
    });

    test('a server that never answers does not hold it for ever', () {
      fakeAsync((async) {
        final d = PushDiagnostics(prefs: prefs, send: (_) => Completer<void>().future);
        var done = false;
        unawaited(d.report(PushStage.token, 'timeout').then((_) => done = true));
        async.elapse(const Duration(seconds: 9));
        expect(done, isFalse);
        async.elapse(const Duration(seconds: 2));
        expect(done, isTrue, reason: 'gave up after its own time-out');
      });
    });

    test('a build that does not do push reports nothing', () async {
      await make(enabled: false).report(PushStage.token, 'timeout');
      expect(sent, isEmpty);
    });

    test('the install id is made once and kept', () async {
      final a = await ensureInstallId(prefs);
      final b = await ensureInstallId(prefs);
      expect(a, b);
      expect(prefs.installId, a);
    });
  });
}
