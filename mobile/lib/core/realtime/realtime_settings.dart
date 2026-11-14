import 'dart:async';

import 'package:albahar_customer/core/config/app_config.dart';
import 'package:flutter/foundation.dart';

/// Where the live socket is and how to sign in to it, for this server right now.
@immutable
class RealtimeSettings {
  const RealtimeSettings({
    required this.uri,
    required this.authUrl,
    required this.pollInterval,
    required this.fromServer,
  });

  /// The full socket address (`wss://host:443/app/<key>?protocol=7…`).
  final Uri uri;

  /// Where private channels are authorised (`…/api/v1/broadcasting/auth`).
  final String authUrl;

  /// How often an app re-reads while the socket is down.
  final Duration pollInterval;

  /// False when the server could not be asked and the build's own settings are used (a dev machine, or a server that is down).
  final bool fromServer;
}

/// Finds the socket for the phone apps. The app key and address are NOT baked into the build: the server says where its own socket is
/// (`GET /api/v1/realtime-config`, public, nothing secret), so one build works against any server and a change of domain or key needs no
/// new release. When the server cannot be reached the build's own settings are used (derived from its API address) and the lookup is
/// tried again on the next connection attempt; a good answer is kept for [keepFor].
class RealtimeSettingsLoader {
  RealtimeSettingsLoader({
    required this.config,
    required this.fetch,
    this.keepFor = const Duration(minutes: 30),
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  final AppConfig config;

  /// Returns the `data` object of `GET /realtime-config`, or throws.
  final Future<Map<String, dynamic>> Function() fetch;
  final Duration keepFor;
  final DateTime Function() _clock;

  RealtimeSettings? _good;
  DateTime? _goodAt;
  Future<RealtimeSettings>? _inFlight;

  /// The own settings of the build: used before the server has answered and whenever it cannot.
  RealtimeSettings get fallback => RealtimeSettings(
    uri: config.reverbUri,
    authUrl: config.broadcastingAuthUrl,
    pollInterval: const Duration(seconds: 15),
    fromServer: false,
  );

  Future<RealtimeSettings> load({bool force = false}) {
    final good = _good;
    if (!force && good != null && _clock().difference(_goodAt!) < keepFor) return Future.value(good);
    return _inFlight ??= _ask().whenComplete(() => _inFlight = null);
  }

  Future<RealtimeSettings> _ask() async {
    try {
      final settings = parse(await fetch().timeout(const Duration(seconds: 8)), config);
      if (settings != null) {
        _good = settings;
        _goodAt = _clock();
        return settings;
      }
    } catch (_) {
      // Fall through: the server may be restarting; the socket will try the question again on its next attempt.
    }
    return _good ?? fallback;
  }

  /// Reads the server's answer. Null when it says the socket is switched off or the answer is unusable.
  @visibleForTesting
  static RealtimeSettings? parse(Map<String, dynamic> data, AppConfig config) {
    final key = data['key']?.toString() ?? '';
    if (data['enabled'] == false || key.isEmpty) return null;
    final socket = data['socket'];
    var scheme = config.reverbUseTls ? 'wss' : 'ws';
    var host = config.reverbHost;
    var port = config.reverbPort;
    var path = '/app';
    // A build with an explicit socket address (a dev machine, REVERB_HOST) keeps it: the server only knows how IT is reached.
    if (socket is Map && !config.reverbExplicit) {
      scheme = socket['scheme']?.toString() == 'ws' ? 'ws' : 'wss';
      host = (socket['host']?.toString() ?? '').isNotEmpty ? socket['host'].toString() : host;
      port = int.tryParse('${socket['port'] ?? ''}') ?? (scheme == 'wss' ? 443 : 80);
      path = (socket['path']?.toString() ?? '').isNotEmpty ? socket['path'].toString() : path;
    }
    final seconds = int.tryParse('${data['poll_seconds'] ?? ''}') ?? 15;
    return RealtimeSettings(
      uri: Uri(
        scheme: scheme,
        host: host,
        port: port,
        path: '${path.endsWith('/') ? path.substring(0, path.length - 1) : path}/$key',
        queryParameters: const {'protocol': '7', 'client': 'albahar-flutter', 'version': '1.0.0', 'flash': 'false'},
      ),
      authUrl: config.broadcastingAuthUrl,
      pollInterval: Duration(seconds: seconds.clamp(5, 120)),
      fromServer: true,
    );
  }
}
