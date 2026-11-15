import 'dart:async';

import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/push/firebase_options_source.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/update/app_update.dart';
import 'package:fake_async/fake_async.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

const _native = {
  'projectId': 'al-bahar-home',
  'messagingSenderId': '123456789012',
  'apiKey': 'AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R',
  'appId': '1:123456789012:android:0a1b2c3d4e5f6a7b',
};

Map<String, dynamic> _answer({Object? native = _native, Object? enabled = true}) => {
  'enabled': ?enabled,
  'web': null,
  'native': native,
};

FirebaseOptions _expected({String? iosBundleId}) => FirebaseOptions(
  apiKey: _native['apiKey']!,
  appId: _native['appId']!,
  messagingSenderId: _native['messagingSenderId']!,
  projectId: _native['projectId']!,
  iosBundleId: iosBundleId,
);

void main() {
  group('parseFirebaseOptions', () {
    test('a valid answer becomes the four options Firebase needs', () {
      expect(parseFirebaseOptions(_answer()), _expected());
    });

    test('the iOS bundle id is added when given, and left out when empty', () {
      expect(
        parseFirebaseOptions(_answer(), iosBundleId: 'qa.albahar.customer'),
        _expected(iosBundleId: 'qa.albahar.customer'),
      );
      expect(parseFirebaseOptions(_answer(), iosBundleId: '')?.iosBundleId, isNull);
      expect(parseFirebaseOptions(_answer())?.iosBundleId, isNull);
    });

    test('native null, absent or not an object: nothing to start with', () {
      expect(parseFirebaseOptions(_answer(native: null)), isNull);
      expect(parseFirebaseOptions({'enabled': true, 'web': null}), isNull);
      expect(parseFirebaseOptions(_answer(native: 'x')), isNull);
      expect(parseFirebaseOptions(_answer(native: [_native])), isNull);
    });

    test('push switched off: nothing, even if a native block is there', () {
      expect(parseFirebaseOptions(_answer(enabled: false)), isNull);
    });

    test('a missing enabled flag is tolerated', () {
      expect(parseFirebaseOptions(_answer(enabled: null)), _expected());
    });

    test('extra fields at any level are ignored', () {
      final answer = _answer(native: {..._native, 'storageBucket': 'x.appspot.com', 'extra': 1})
        ..['web'] = {'apiKey': 'web-key'}
        ..['version'] = 3;
      expect(parseFirebaseOptions(answer), _expected());
    });

    test('values are trimmed', () {
      final answer = _answer(native: {..._native, 'apiKey': '  AIza-key  '});
      expect(parseFirebaseOptions(answer)?.apiKey, 'AIza-key');
    });

    test('wrong types give nothing', () {
      for (final key in _native.keys) {
        for (final bad in <Object?>[
          123,
          true,
          null,
          ['a'],
          {'a': 1},
        ]) {
          expect(parseFirebaseOptions(_answer(native: {..._native, key: bad})), isNull, reason: '$key = $bad');
        }
      }
    });

    test('missing or empty keys give nothing', () {
      for (final key in _native.keys) {
        expect(parseFirebaseOptions(_answer(native: {..._native}..remove(key))), isNull, reason: 'no $key');
        expect(parseFirebaseOptions(_answer(native: {..._native, key: ''})), isNull, reason: 'empty $key');
        expect(parseFirebaseOptions(_answer(native: {..._native, key: '   '})), isNull, reason: 'blank $key');
      }
    });

    test('an answer that is not an object gives nothing', () {
      for (final bad in <Object?>[null, 'oops', 42, <Object?>[], true]) {
        expect(parseFirebaseOptions(bad), isNull, reason: '$bad');
      }
    });
  });

  group('FirebaseOptionsSource.load', () {
    test('returns the options from a good answer', () async {
      final source = FirebaseOptionsSource(fetch: () async => _answer());
      expect(await source.load(iosBundleId: 'qa.albahar.customer'), _expected(iosBundleId: 'qa.albahar.customer'));
    });

    test('returns null (never throws) when the call fails in any way', () async {
      final failures = <Future<Object?> Function()>[
        () async => throw Exception('offline'),
        () async => throw TimeoutException('slow'),
        () => throw StateError('thrown before the future'),
        () async => throw 'a plain string',
      ];
      for (final fail in failures) {
        expect(await FirebaseOptionsSource(fetch: fail).load(), isNull);
      }
    });

    test('returns null when the answer is switched off or empty', () async {
      expect(await FirebaseOptionsSource(fetch: () async => _answer(native: null)).load(), isNull);
      expect(await FirebaseOptionsSource(fetch: () async => null).load(), isNull);
    });

    test('gives up after the timeout when the server never answers', () {
      fakeAsync((clock) {
        FirebaseOptions? result;
        var done = false;
        FirebaseOptionsSource(fetch: () => Completer<Object?>().future).load().then((v) {
          result = v;
          done = true;
        });
        clock.elapse(const Duration(seconds: 7, milliseconds: 900));
        expect(done, isFalse, reason: 'still waiting inside the 8 s limit');
        clock.elapse(const Duration(milliseconds: 200));
        expect(done, isTrue);
        expect(result, isNull);
      });
    });
  });

  group('FirebaseOptionsSource.api', () {
    late String savedApp;
    setUp(() => savedApp = AppIdentity.app);
    tearDown(() {
      AppIdentity.app = savedApp;
      debugDefaultTargetPlatformOverride = null;
    });

    ApiClient client(FakeAdapter adapter) => ApiClient.create(
      config: testConfig,
      tokenStore: MemoryTokenStore(),
      localeCode: () => 'en',
      onSessionExpired: () async {},
      adapter: adapter,
    );

    test('asks GET /push-config?platform=…&app=… and reads the data object', () async {
      final adapter = FakeAdapter((_) => ok(_answer()));
      AppIdentity.app = 'driver';
      debugDefaultTargetPlatformOverride = TargetPlatform.android;

      final options = await FirebaseOptionsSource.api(client(adapter)).load();

      final request = adapter.requests.single;
      expect(request.method, 'GET');
      expect(request.path, '/push-config');
      expect(request.query, {'platform': 'android', 'app': 'driver'});
      expect(request.header('Authorization'), isNull, reason: 'the route is public');
      expect(options, _expected());
    });

    test('says ios on an iPhone and uses the app the build is', () async {
      final adapter = FakeAdapter((_) => ok(_answer()));
      AppIdentity.app = 'staff';
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;

      await FirebaseOptionsSource.api(client(adapter)).load();

      expect(adapter.requests.single.query, {'platform': 'ios', 'app': 'staff'});
    });

    test('a server error, a missing route or no network all give null', () async {
      for (final reply in [error(500, 'server_error'), error(404, 'not_found'), error(429, 'too_many')]) {
        final source = FirebaseOptionsSource.api(client(FakeAdapter((_) => reply)));
        expect(await source.load(), isNull);
      }
      final offline = FakeAdapter((_) => ok(_answer()))..failConnection = (_) => true;
      expect(await FirebaseOptionsSource.api(client(offline)).load(), isNull);
    });
  });
}
