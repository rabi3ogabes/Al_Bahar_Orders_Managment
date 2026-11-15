import 'package:albahar_customer/core/push/firebase_options_source.dart';
import 'package:albahar_customer/core/push/firebase_starter.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

const _answer = {
  'enabled': true,
  'native': {
    'projectId': 'al-bahar-home',
    'messagingSenderId': '123456789012',
    'apiKey': 'AIzaSyB2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R',
    'appId': '1:123456789012:android:0a1b2c3d4e5f6a7b',
  },
};

/// A starter with every outside step replaced by a recorder, so the order of the steps can be read back.
class _Rig {
  _Rig({
    this.filesWork = false,
    this.serverAnswer = _answer,
    this.optionsWork = true,
    this.started = false,
    this.platform = TargetPlatform.android,
    this.bundleId = 'qa.albahar.customer',
  });

  final bool filesWork;
  final Object? serverAnswer;
  final bool optionsWork;
  final bool started;
  final TargetPlatform platform;
  final String? bundleId;

  final calls = <String>[];
  FirebaseOptions? startedWith;

  late final starter = FirebaseStarter(
    platform: platform,
    isStarted: () => started,
    options: FirebaseOptionsSource(
      fetch: () async {
        calls.add('fetch');
        if (serverAnswer is Exception) throw serverAnswer!;
        return serverAnswer;
      },
    ),
    fromNativeFiles: () async {
      calls.add('files');
      if (!filesWork) throw StateError('no google-services.json');
    },
    fromOptions: (o) async {
      calls.add('options');
      startedWith = o;
      if (!optionsWork) throw StateError('rejected');
    },
    bundleId: () async => bundleId,
  );
}

void main() {
  test('the native config files come first, and then the server is not asked at all', () async {
    final rig = _Rig(filesWork: true);
    expect(await rig.starter.start(), isTrue);
    expect(rig.calls, ['files']);
  });

  test('Firebase already running: nothing is done', () async {
    final rig = _Rig(started: true);
    expect(await rig.starter.start(), isTrue);
    expect(rig.calls, isEmpty);
  });

  test('no files: the options from the server start Firebase (Android, no bundle id)', () async {
    final rig = _Rig();
    expect(await rig.starter.start(), isTrue);
    expect(rig.calls, ['files', 'fetch', 'options']);
    expect(rig.startedWith?.projectId, 'al-bahar-home');
    expect(rig.startedWith?.appId, '1:123456789012:android:0a1b2c3d4e5f6a7b');
    expect(rig.startedWith?.iosBundleId, isNull);
  });

  test('iPhone: the app\'s own bundle id is added to the options', () async {
    final rig = _Rig(platform: TargetPlatform.iOS, bundleId: 'qa.albahar.driver');
    expect(await rig.starter.start(), isTrue);
    expect(rig.startedWith?.iosBundleId, 'qa.albahar.driver');
  });

  test('iPhone: an unknown bundle id is simply left out', () async {
    final rig = _Rig(platform: TargetPlatform.iOS, bundleId: null);
    expect(await rig.starter.start(), isTrue);
    expect(rig.startedWith?.iosBundleId, isNull);
  });

  test('no files and nothing on the server: push is unavailable, in that order', () async {
    for (final answer in <Object?>[
      {'enabled': true, 'native': null},
      {'enabled': false},
      null,
      Exception('offline'),
    ]) {
      final rig = _Rig(serverAnswer: answer);
      expect(await rig.starter.start(), isFalse, reason: '$answer');
      expect(rig.calls, ['files', 'fetch'], reason: '$answer');
    }
  });

  test('options that Firebase refuses: push is unavailable, and nothing throws', () async {
    final rig = _Rig(optionsWork: false);
    expect(await rig.starter.start(), isFalse);
    expect(rig.calls, ['files', 'fetch', 'options']);
  });

  test('a platform the server has no phone settings for is never asked', () async {
    final rig = _Rig(platform: TargetPlatform.macOS);
    expect(await rig.starter.start(), isFalse);
    expect(rig.calls, ['files']);
  });
}
