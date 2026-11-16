import 'dart:async';
import 'dart:io' show Platform;

import 'package:dio/dio.dart' show Options;
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../network/api_client.dart';
import '../network/api_exception.dart';
import '../network/interceptors.dart' show RequestFlags;
import '../storage/app_preferences.dart';
import '../update/app_update.dart';

/// The steps push goes through on a phone, in order. The server's dashboard (Push notifications, "What the phones report") groups the
/// reports by these and by the code below, and explains each in plain words, so the owner can fix the Firebase side without a phone in hand.
abstract final class PushStage {
  /// Reading the Firebase settings of this app from the server (`GET /push-config`).
  static const options = 'options';

  /// Starting Firebase with those settings.
  static const init = 'init';

  /// The person said no to notifications (or switched them off in the phone's settings).
  static const permission = 'permission';

  /// Asking Firebase for this phone's push token.
  static const token = 'token';

  /// Sending the token to the server (`POST /me/devices` or `/admin/devices`).
  static const register = 'register';

  /// All of it worked: the token is on the server.
  static const registered = 'registered';
}

/// Reports one thing that went wrong (or right) with push on this phone. Never throws and never waits for anything that matters.
typedef PushReporter = Future<void> Function(String stage, String code, [String? message]);

/// Hands a report to [report] without ever waiting for it or letting it throw (a reporter that is missing, slow or broken changes nothing).
void tellPush(PushReporter? report, String stage, String code, [String? message]) {
  if (report == null) return;
  try {
    unawaited(report(stage, code, message).catchError((Object _) {}));
  } catch (_) {}
}

/// Longest message sent (characters); the server refuses more.
const _messageMax = 200;

/// A machine code for a Firebase or platform error, from the fixed list the dashboard explains (see backend lang `push_health.codes`).
/// Firebase's errors reach Dart as one string ("[firebase_messaging/unknown] java.io.IOException: SERVICE_NOT_AVAILABLE"), so the code is read
/// from that text. Anything unknown becomes [fallback]; the scrubbed text goes along, so nothing is lost.
String classifyPushError(Object error, {String fallback = 'unknown'}) {
  if (error is TimeoutException) return 'timeout';
  final text = error.toString().toLowerCase();
  bool has(String part) => text.contains(part);
  if (has('api_key_android_app_blocked') ||
      has('android client application') && has('blocked') ||
      has('api key') && has('blocked') ||
      has('referer') && has('blocked')) {
    return 'api_key_blocked';
  }
  if (has('api key not valid') || has('api_key_invalid') || has('api key expired')) return 'api_key_invalid';
  if (has('has not been used in project') ||
      has('service_disabled') ||
      has('accessnotconfigured') ||
      has('api has not been enabled')) {
    return 'api_disabled';
  }
  if (has('invalid-app-id') || has('invalid_app_id') || has('application id') && has('invalid')) {
    return 'invalid_app_id';
  }
  if (has('apns-token-not-set')) return 'apns_missing';
  if (has('too_many_registrations')) return 'too_many_registrations';
  if (has('fis_auth_error') ||
      has('authentication_failed') ||
      has('bad_config') ||
      has('permission_denied') ||
      has('permission-denied') ||
      has('http 403')) {
    return 'fis_auth_error';
  }
  if (has('invalid_parameters') || has('invalid_argument') || has('invalid argument') || has('invalid-argument')) {
    return 'invalid_argument';
  }
  if (has('service_not_available') || has('installations service is unavailable') || has('unavailable')) {
    return 'service_not_available';
  }
  if (has('unknownhost') ||
      has('unable to resolve host') ||
      has('failed host lookup') ||
      has('network is unreachable') ||
      has('socketexception') ||
      has('no address associated')) {
    return 'no_network';
  }
  if (has('timeout') || has('timed out')) return 'timeout';
  return fallback;
}

/// The code for a failed `POST /me/devices` (or `/admin/devices`): the phone has its token, the server did not take it.
String classifyRegisterError(Object error) {
  if (error is ApiException) {
    return switch (error.kind) {
      ApiErrorKind.offline || ApiErrorKind.timeout => 'no_network',
      ApiErrorKind.unauthorized => 'unauthorized',
      ApiErrorKind.server => 'server_error',
      ApiErrorKind.forbidden ||
      ApiErrorKind.notFound ||
      ApiErrorKind.conflict ||
      ApiErrorKind.validation ||
      ApiErrorKind.rateLimited => 'rejected',
      _ => 'register_failed',
    };
  }
  return classifyPushError(error, fallback: 'register_failed');
}

/// A message safe to send: whitespace collapsed, anything that looks like an API key, an e-mail address, a long token or a long number
/// replaced, query strings cut, and the whole clipped to 200 characters. The server scrubs again (it does not trust a build).
String scrubPushText(String text, {int max = _messageMax}) {
  var out = text.replaceAll(RegExp(r'\s+'), ' ').trim();
  out = out.replaceAll(RegExp(r'AIza[0-9A-Za-z_-]{10,}'), '[key]');
  out = out.replaceAll(RegExp(r'[\w.+-]+@[\w-]+(?:\.[\w-]+)+'), '[email]');
  out = out.replaceAll(RegExp(r'\?\S*'), '?…');
  out = out.replaceAll(RegExp(r'[A-Za-z0-9_:-]{32,}'), '[token]');
  out = out.replaceAll(RegExp(r'\+?\d{8,}'), '[number]');
  out = out.trim();
  return out.length <= max ? out : out.substring(0, max);
}

/// The random id this install made for itself (the same one `POST /me/devices` carries as `device_id`). Not a person, not a device serial.
Future<String> ensureInstallId(AppPreferences prefs) async {
  final existing = prefs.installId;
  if (existing != null && existing.isNotEmpty) return existing;
  final id = const Uuid().v4();
  await prefs.setInstallId(id);
  return id;
}

/// Tells the server why push does not work on this phone (or that it does), so the dashboard can say what to fix in Firebase. The phone
/// has no other way to say it: with no token it cannot be pushed to. Everything about it is cautious:
///  * never throws, never delays start-up (callers do not wait for it);
///  * nothing personal goes: app, platform, step, a short code, a scrubbed message of at most 200 characters, app version, OS version and
///    the install id (no name, number, token, API key or address);
///  * each distinct problem is told at most once per run and once per day per install, and only marked as told once the server took it.
class PushDiagnostics {
  PushDiagnostics({
    required this.send,
    required this.prefs,
    DateTime Function()? now,
    String Function()? osVersion,
    String Function()? app,
    String Function()? platform,
    String? Function()? version,
    this.enabled = true,
  }) : _now = now ?? DateTime.now,
       _osVersion = osVersion ?? _defaultOsVersion,
       _app = app ?? (() => AppIdentity.app),
       _platform = platform ?? (() => AppIdentity.platform),
       _version = version ?? _defaultVersion;

  /// The production reporter: `POST /push-diagnostics` through the app's own client. The route is public, so no token is attached (a
  /// report must never start a token refresh or sign anybody out).
  factory PushDiagnostics.api(ApiClient api, AppPreferences prefs, {bool enabled = true}) => PushDiagnostics(
    prefs: prefs,
    enabled: enabled,
    send: (body) => api.post(
      '/push-diagnostics',
      data: body,
      options: Options(
        extra: {RequestFlags.skipAuth: true},
        sendTimeout: const Duration(seconds: 6),
        receiveTimeout: const Duration(seconds: 6),
      ),
    ),
  );

  /// Posts one report body.
  final Future<void> Function(Map<String, Object?> body) send;
  final AppPreferences prefs;

  /// False in a build that does not do push (the web build): nothing is reported.
  final bool enabled;

  final DateTime Function() _now;
  final String Function() _osVersion;
  final String Function() _app;
  final String Function() _platform;
  final String? Function() _version;
  final _tried = <String>{};

  static String _defaultOsVersion() {
    if (kIsWeb) return '';
    try {
      return Platform.operatingSystemVersion;
    } catch (_) {
      return '';
    }
  }

  static String? _defaultVersion() =>
      AppIdentity.version.isEmpty ? null : '${AppIdentity.version}+${AppIdentity.build}';

  /// The reporter handed to the code that starts Firebase and gets the token.
  PushReporter get reporter => report;

  Future<void> report(String stage, String code, [String? message]) async {
    try {
      if (!enabled || kIsWeb) return;
      final key = '$stage|$code';
      if (!_tried.add(key)) return; // once per run
      final today = _day();
      final told = prefs.pushReports;
      if (told[key] == today) return; // once per day
      final text = message == null ? '' : scrubPushText(message);
      final version = _version();
      final os = scrubPushText(_osVersion(), max: 64);
      await send({
        'app': _app(),
        'platform': _platform(),
        'stage': stage,
        'code': code,
        if (text.isNotEmpty) 'message': text,
        'app_version': ?version,
        if (os.isNotEmpty) 'os_version': os,
        'install_id': await ensureInstallId(prefs),
      }).timeout(const Duration(seconds: 10));
      final fresh = {...told}..removeWhere((_, day) => day != today);
      await prefs.setPushReports({...fresh, key: today});
    } catch (e) {
      // Whatever went wrong here must not hurt the app. It was not marked as told, so the next run tries again.
      debugPrint('Push report not sent (${e.runtimeType})');
    }
  }

  String _day() {
    final d = _now().toUtc();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${d.year}-${two(d.month)}-${two(d.day)}';
  }
}
