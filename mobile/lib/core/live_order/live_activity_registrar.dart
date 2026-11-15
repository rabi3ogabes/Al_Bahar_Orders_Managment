import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:uuid/uuid.dart';

import '../network/api_client.dart';
import '../storage/app_preferences.dart';
import 'live_activity_platform.dart';

/// Where the app reports its Live Activity tokens (`/me/live-activities`, see docs/guides/IOS_LIVE_ACTIVITY.md).
abstract class LiveActivityApi {
  /// The push token of a running activity for one of the customer's orders.
  Future<void> registerActivity({
    required String orderId,
    required String token,
    required String environment,
    required String deviceId,
    String? locale,
    String? appVersion,
  });

  /// The phone's push-to-start token (iOS 17.2+).
  Future<void> registerStart({
    required String token,
    required String environment,
    required String deviceId,
    String? locale,
    String? appVersion,
  });

  /// Everything this phone reported (sign-out).
  Future<void> unregisterDevice(String deviceId);
}

class RestLiveActivityApi implements LiveActivityApi {
  RestLiveActivityApi(this._api);

  final ApiClient _api;

  static const path = '/me/live-activities';

  Map<String, Object> _common(String token, String environment, String deviceId, String? locale, String? appVersion) =>
      {
        'push_token': token,
        'environment': environment,
        'device_id': deviceId,
        'locale': ?locale,
        'app_version': ?appVersion,
      };

  @override
  Future<void> registerActivity({
    required String orderId,
    required String token,
    required String environment,
    required String deviceId,
    String? locale,
    String? appVersion,
  }) async {
    await _api.post(path, data: {'order_id': orderId, ..._common(token, environment, deviceId, locale, appVersion)});
  }

  @override
  Future<void> registerStart({
    required String token,
    required String environment,
    required String deviceId,
    String? locale,
    String? appVersion,
  }) async {
    await _api.post('$path/start-token', data: _common(token, environment, deviceId, locale, appVersion));
  }

  @override
  Future<void> unregisterDevice(String deviceId) async {
    await _api.delete(path, data: {'device_id': deviceId});
  }
}

/// Reports the Live Activity tokens Apple gives this phone, for the signed-in customer only, and takes them back at sign-out.
///
/// The server cannot see these tokens: only the app can read them. A token is reported when it appears and again, once, when it
/// changes; one that could not be reported (offline, the server is down) is kept and sent at the next [flush] (the app coming back,
/// the network coming back). Nothing is reported while nobody is signed in, and a token that arrives in that time is kept for the
/// next sign-in only until sign-out clears everything. Never throws.
class LiveActivityRegistrar {
  LiveActivityRegistrar({
    required this.platform,
    required this.api,
    required this.prefs,
    required this.localeCode,
    this.appVersion,
  });

  final LiveActivityPlatform platform;
  final LiveActivityApi api;
  final AppPreferences prefs;
  final String Function() localeCode;

  /// The app's version for the server's records; defaults to the package info.
  final Future<String?> Function()? appVersion;

  bool _signedIn = false;
  StreamSubscription<LiveActivityTokenEvent>? _sub;
  Future<void>? _tail;

  /// The newest token per slot (one per order, one per phone), whether or not it reached the server yet.
  final _known = <String, LiveActivityTokenEvent>{};

  /// What the server has, per slot: the token that was reported successfully.
  final _reported = <String, LiveActivityTokenEvent>{};

  @visibleForTesting
  Set<String> get reportedSlots => _reported.keys.toSet();

  /// The customer is signed in: listen to Apple's tokens and report the ones that are already here.
  void onSignedIn() {
    if (_signedIn) return;
    _signedIn = true;
    _sub = platform.tokens.listen(_onToken, onError: (Object _) {});
    unawaited(
      _enqueue(() async {
        try {
          for (final e in await platform.pendingTokens()) {
            _known[e.slot] = e;
          }
        } catch (_) {
          // Nothing waiting: the stream delivers whatever comes next.
        }
        await _send();
      }),
    );
  }

  /// Signed out: no token of this customer stays on the server, and none is kept here for the next person.
  Future<void> onSignedOut() async {
    if (!_signedIn && _known.isEmpty && _reported.isEmpty) return;
    _signedIn = false;
    await _sub?.cancel();
    _sub = null;
    final hadReported = _reported.isNotEmpty || _known.isNotEmpty;
    await _enqueue(() async {
      _known.clear();
      _reported.clear();
      if (!hadReported) return;
      try {
        await api.unregisterDevice(await _deviceId());
      } catch (_) {
        // The session is gone or the phone is offline: the server drops the tokens when their activities end.
      }
    });
  }

  /// Sends the tokens that did not reach the server yet (the app came back to the foreground, the network came back).
  Future<void> flush() => _enqueue(_send);

  /// The app's language changed: the server writes each activity in the language its token was reported with, so they are reported again.
  Future<void> onLocaleChanged() => _enqueue(() async {
    _reported.clear(); // the push-to-start token carries a language too
    await _send();
  });

  void _onToken(LiveActivityTokenEvent e) {
    _known[e.slot] = e;
    if (_signedIn) unawaited(_enqueue(_send));
  }

  Future<void> _send() async {
    if (!_signedIn) return;
    final pending = [
      for (final e in _known.values)
        if (_reported[e.slot] != e) e,
    ];
    if (pending.isEmpty) return;
    final deviceId = await _deviceId();
    final version = await _version();
    final locale = localeCode();
    for (final e in pending) {
      if (!_signedIn) return;
      try {
        if (e.isStart) {
          await api.registerStart(
            token: e.token,
            environment: e.environment,
            deviceId: deviceId,
            locale: locale,
            appVersion: version,
          );
        } else {
          await api.registerActivity(
            orderId: e.orderId!,
            token: e.token,
            environment: e.environment,
            deviceId: deviceId,
            locale: locale,
            appVersion: version,
          );
        }
        _reported[e.slot] = e;
      } catch (err) {
        // Kept in _known: the next flush tries again.
        debugPrint('Live activity token not reported yet (${err.runtimeType})');
      }
    }
  }

  /// The install id the push token is registered with, made now if the customer has not got one yet.
  Future<String> _deviceId() async {
    var id = prefs.installId;
    if (id == null) {
      id = const Uuid().v4();
      await prefs.setInstallId(id);
    }
    return id;
  }

  Future<String?> _version() async {
    try {
      if (appVersion != null) return await appVersion!();
      final info = await PackageInfo.fromPlatform();
      return '${info.version}+${info.buildNumber}';
    } catch (_) {
      return null;
    }
  }

  Future<void> dispose() async {
    await _sub?.cancel();
    _sub = null;
  }

  /// Jobs run one after the other; a job that fails is reported to the log and never to the caller.
  Future<void> _enqueue(Future<void> Function() job) {
    final next = (_tail ?? Future<void>.value()).then((_) => job()).catchError((Object e) {
      debugPrint('Live activity registration failed (${e.runtimeType})');
    });
    _tail = next;
    return next;
  }
}
