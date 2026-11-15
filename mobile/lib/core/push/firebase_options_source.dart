import 'dart:async';

import 'package:firebase_core/firebase_core.dart' show FirebaseOptions;
import 'package:flutter/foundation.dart';

import '../network/api_client.dart';
import '../update/app_update.dart';

/// Turns the answer of `GET /push-config?platform=…&app=…` into the options Firebase needs to start, or null when the
/// answer holds nothing usable (push switched off, not configured, this app or platform has no app id yet, a missing or
/// odd value). [data] is the unwrapped `data` object: `{enabled, web, native: {projectId, messagingSenderId, apiKey,
/// appId} | null}`. Fields it does not know are ignored. [iosBundleId] is the app's own bundle id (iOS only).
FirebaseOptions? parseFirebaseOptions(Object? data, {String? iosBundleId}) {
  if (data is! Map || data['enabled'] == false) return null;
  final native = data['native'];
  if (native is! Map) return null;

  String? text(String key) {
    final value = native[key];
    if (value is! String) return null;
    final trimmed = value.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  final apiKey = text('apiKey');
  final appId = text('appId');
  final messagingSenderId = text('messagingSenderId');
  final projectId = text('projectId');
  if (apiKey == null || appId == null || messagingSenderId == null || projectId == null) return null;
  return FirebaseOptions(
    apiKey: apiKey,
    appId: appId,
    messagingSenderId: messagingSenderId,
    projectId: projectId,
    iosBundleId: (iosBundleId == null || iosBundleId.isEmpty) ? null : iosBundleId,
  );
}

/// Where a phone app gets its Firebase settings when the build carries no `google-services.json` /
/// `GoogleService-Info.plist`: the dashboard, through the public `/push-config` route.
class FirebaseOptionsSource {
  FirebaseOptionsSource({required this.fetch, this.timeout = const Duration(seconds: 8)});

  /// The production source: `GET /push-config?platform=android|ios&app=customer|driver|staff` through the app's own
  /// client. The route is public, so no sign-in is needed.
  factory FirebaseOptionsSource.api(ApiClient api) => FirebaseOptionsSource(
    fetch: () => api.get('/push-config', query: {'platform': AppIdentity.platform, 'app': AppIdentity.app}),
  );

  /// Returns the unwrapped `data` object of the answer.
  final Future<Object?> Function() fetch;

  /// The most the app waits for the answer.
  final Duration timeout;

  /// The options, or null when there are none to use (see [parseFirebaseOptions]), the server cannot be reached in
  /// time, or it answers with an error. Never throws.
  Future<FirebaseOptions?> load({String? iosBundleId}) async {
    try {
      return parseFirebaseOptions(await fetch().timeout(timeout), iosBundleId: iosBundleId);
    } catch (e) {
      debugPrint('Push options not loaded (${e.runtimeType})');
      return null;
    }
  }
}
