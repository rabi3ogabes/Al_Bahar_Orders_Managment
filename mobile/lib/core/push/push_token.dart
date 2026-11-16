import 'dart:async';

import 'package:flutter/services.dart' show MissingPluginException;

import 'push_diagnostics.dart';

/// Gets this install's push token from Firebase and says why when it cannot. The SDK calls are handed in, so the order of events can be tested
/// without a phone.
///
/// The token the server needs is the FCM *registration token* (`message.token` of the FCM HTTP v1 API), which the SDK's `getToken()` returns.
/// firebase_messaging 17 deprecates that call for `register()` + `onRegistered`, but `onRegistered` delivers the Firebase *installation id*
/// (the first 22 characters of the token, "FID") and a failed registration shows there only as a time-out. So `getToken()` goes first: it also
/// throws Firebase's own reason (API key blocked, a Google API switched off, Firebase unreachable ...), which is classified and reported so the
/// dashboard can say what to fix. The installation id is the second choice, tried only when `getToken()` gave nothing without saying why, has
/// been removed from the plugin, or the iPhone has no Apple token yet. Null when neither delivers.
class PushTokenFetcher {
  const PushTokenFetcher({
    required this.registrationToken,
    required this.register,
    required this.registered,
    this.report,
    this.timeout = const Duration(seconds: 20),
  });

  /// The SDK's `getToken()`.
  final Future<String?> Function() registrationToken;

  /// The SDK's `register()`.
  final Future<void> Function() register;

  /// The SDK's `onRegistered` (installation ids).
  final Stream<String> Function() registered;

  /// Told why no token came. Never waited for.
  final PushReporter? report;

  /// The most each of the two ways waits.
  final Duration timeout;

  Future<String?> fetch() async {
    var trySecond = false;
    try {
      final token = await registrationToken().timeout(timeout);
      if (token != null && token.isNotEmpty) return token;
      tellPush(report, PushStage.token, 'no_token', 'Firebase gave an empty token');
      trySecond = true;
    } on TimeoutException {
      tellPush(report, PushStage.token, 'timeout', 'No token from Firebase within ${timeout.inSeconds} seconds');
    } catch (e) {
      final code = classifyPushError(e, fallback: 'token_failed');
      tellPush(report, PushStage.token, code, e.toString());
      trySecond = code == 'apns_missing' || e is MissingPluginException || e is UnimplementedError;
    }
    return trySecond ? _installationId() : null;
  }

  Future<String?> _installationId() async {
    // Listening before register() asks, so an answer that comes quickly is not missed, and an error (a time-out, a stream that ends) is
    // caught at once instead of surfacing later as an error nobody handles.
    final answer = registered().first
        .timeout(timeout)
        .then<({String? id, Object? error})>(
          (id) => (id: id, error: null),
          onError: (Object e) => (id: null, error: e),
        );
    try {
      await register();
    } catch (e) {
      tellPush(report, PushStage.token, classifyPushError(e, fallback: 'token_failed'), e.toString());
      return null;
    }
    final result = await answer;
    final error = result.error;
    if (error == null) return result.id;
    if (error is TimeoutException) {
      tellPush(
        report,
        PushStage.token,
        'timeout',
        'No installation id from Firebase within ${timeout.inSeconds} seconds',
      );
    } else {
      tellPush(report, PushStage.token, classifyPushError(error, fallback: 'token_failed'), error.toString());
    }
    return null;
  }
}
