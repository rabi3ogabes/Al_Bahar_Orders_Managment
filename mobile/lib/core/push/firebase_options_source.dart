import 'dart:async';

import 'package:firebase_core/firebase_core.dart' show FirebaseOptions;
import 'package:flutter/foundation.dart';

import '../network/api_client.dart';
import '../network/api_exception.dart';
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

/// Why an answer of `GET /push-config?platform=…&app=…` holds nothing to start Firebase with, as one of the codes the dashboard explains
/// (`push_off`: switched off or no server key; `app_not_set`: this app has no App ID or API key saved; `options_invalid`: anything else odd).
String diagnoseFirebaseOptions(Object? data) {
  if (data is! Map) return 'options_invalid';
  if (data['enabled'] == false) return 'push_off';
  return data['native'] is Map ? 'options_invalid' : 'app_not_set';
}

/// What [FirebaseOptionsSource.loadResult] found: the options, or why there are none.
class FirebaseOptionsResult {
  const FirebaseOptionsResult.found(FirebaseOptions this.options) : code = null, message = null;
  const FirebaseOptionsResult.missing(String this.code, [this.message]) : options = null;

  final FirebaseOptions? options;

  /// Why there are none (a code for the dashboard), null when [options] is set.
  final String? code;
  final String? message;
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
  Future<FirebaseOptions?> load({String? iosBundleId}) async => (await loadResult(iosBundleId: iosBundleId)).options;

  /// Like [load], and says why when there are no options: `server_unreachable` (no answer in time, or an error answer),
  /// `push_off`, `app_not_set` or `options_invalid`. Never throws.
  Future<FirebaseOptionsResult> loadResult({String? iosBundleId}) async {
    final Object? data;
    try {
      data = await fetch().timeout(timeout);
    } catch (e) {
      debugPrint('Push options not loaded (${e.runtimeType})');
      return FirebaseOptionsResult.missing('server_unreachable', _why(e));
    }
    try {
      final options = parseFirebaseOptions(data, iosBundleId: iosBundleId);
      return options != null
          ? FirebaseOptionsResult.found(options)
          : FirebaseOptionsResult.missing(diagnoseFirebaseOptions(data));
    } catch (e) {
      return FirebaseOptionsResult.missing('options_invalid', e.runtimeType.toString());
    }
  }

  /// What went wrong with the request, without anything the server said.
  static String _why(Object e) =>
      e is ApiException ? 'HTTP ${e.statusCode ?? 0} ${e.kind.name}' : e.runtimeType.toString();
}
