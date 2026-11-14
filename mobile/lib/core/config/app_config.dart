import 'package:flutter/foundation.dart';

/// Build flavour. Selected with `--dart-define=FLAVOR=dev|staging|prod` or by
/// using one of the `main_<flavor>.dart` entry points.
enum Flavor {
  dev,
  staging,
  prod;

  static Flavor parse(String? value) => switch (value?.toLowerCase()) {
    'prod' || 'production' => Flavor.prod,
    'staging' || 'stage' => Flavor.staging,
    _ => Flavor.dev,
  };
}

/// Immutable runtime configuration resolved from compile-time dart-defines.
///
/// Every value can be overridden with `--dart-define=KEY=value` (or
/// `--dart-define-from-file=config/dev.json`). Defaults are per flavour.
@immutable
class AppConfig {
  const AppConfig({
    required this.flavor,
    required this.apiBaseUrl,
    required this.reverbAppKey,
    required this.reverbHost,
    required this.reverbPort,
    required this.reverbUseTls,
    this.reverbExplicit = false,
    required this.pushEnabled,
    required this.mapTileUrlTemplate,
    this.appLinkHost = 'albahar.qa',
    this.appLinkPathPrefix = '/app',
    this.deepLinkScheme = 'albahar',
    this.connectTimeout = const Duration(seconds: 15),
    this.receiveTimeout = const Duration(seconds: 30),
  });

  factory AppConfig.fromEnvironment({Flavor? flavor}) {
    final resolved = flavor ?? Flavor.parse(const String.fromEnvironment('FLAVOR'));
    const apiOverride = String.fromEnvironment('API_BASE_URL');
    const reverbKey = String.fromEnvironment('REVERB_APP_KEY');
    const reverbHost = String.fromEnvironment('REVERB_HOST');
    const reverbPort = int.fromEnvironment('REVERB_PORT');
    const reverbScheme = String.fromEnvironment('REVERB_SCHEME');
    const push = String.fromEnvironment('PUSH_ENABLED');
    const tiles = String.fromEnvironment('MAP_TILE_URL');

    // The socket is on the same host as the API unless the build says otherwise: a release build needs no REVERB_* settings at all, and
    // the live app key comes from the server (see RealtimeSettingsLoader), so the built-in key below is only the last resort.
    final apiUrl = apiOverride.isNotEmpty
        ? apiOverride
        : switch (resolved) {
            Flavor.dev => 'http://10.0.2.2:8000/api/v1',
            Flavor.staging => 'https://staging-api.albahar.qa/api/v1',
            Flavor.prod => 'https://api.albahar.qa/api/v1',
          };
    // The web build has a relative API address (/api/v1): its socket is on the page's own host.
    final parsed = Uri.tryParse(apiUrl);
    final api = parsed != null && parsed.hasAuthority ? parsed : (kIsWeb ? Uri.base : null);
    final sameHost = api != null && api.hasAuthority && resolved != Flavor.dev;
    final tls = sameHost ? api.scheme == 'https' : false;
    final defaults = switch (resolved) {
      Flavor.dev => (host: '10.0.2.2', port: 8080, tls: false, key: 'albahar-dev'),
      Flavor.staging => (
        host: sameHost ? api.host : 'staging-ws.albahar.qa',
        port: sameHost ? (api.hasPort ? api.port : (tls ? 443 : 80)) : 443,
        tls: sameHost ? tls : true,
        key: 'albahar-staging',
      ),
      Flavor.prod => (
        host: sameHost ? api.host : 'ws.albahar.qa',
        port: sameHost ? (api.hasPort ? api.port : (tls ? 443 : 80)) : 443,
        tls: sameHost ? tls : true,
        key: 'albahar',
      ),
    };

    return AppConfig(
      flavor: resolved,
      apiBaseUrl: apiUrl,
      reverbAppKey: reverbKey.isNotEmpty ? reverbKey : defaults.key,
      reverbHost: reverbHost.isNotEmpty ? reverbHost : defaults.host,
      reverbPort: reverbPort != 0 ? reverbPort : defaults.port,
      reverbUseTls: reverbScheme.isNotEmpty ? reverbScheme == 'wss' || reverbScheme == 'https' : defaults.tls,
      reverbExplicit: resolved == Flavor.dev || reverbHost.isNotEmpty,
      // Push is opt-out: it is attempted unless PUSH_ENABLED=false. Firebase
      // initialisation itself is defensive (no config files => disabled).
      pushEnabled: push.isEmpty ? true : push == 'true' || push == '1',
      mapTileUrlTemplate: tiles.isNotEmpty ? tiles : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    );
  }

  final Flavor flavor;
  final String apiBaseUrl;
  final String reverbAppKey;
  final String reverbHost;
  final int reverbPort;
  final bool reverbUseTls;

  /// True when the build names its own socket (a dev machine or REVERB_HOST): the server's answer then only supplies the app key.
  final bool reverbExplicit;
  final bool pushEnabled;
  final String mapTileUrlTemplate;
  final String appLinkHost;
  final String appLinkPathPrefix;
  final String deepLinkScheme;
  final Duration connectTimeout;
  final Duration receiveTimeout;

  bool get isProd => flavor == Flavor.prod;

  /// Debug logging of HTTP traffic (redacted) is only ever enabled in debug
  /// builds of non-production flavours.
  bool get httpLoggingEnabled => kDebugMode && !isProd;

  /// Laravel broadcasting auth endpoint for private channels.
  /// Assumption: served under the API prefix (see docs/API_ASSUMPTIONS.md).
  String get broadcastingAuthUrl => '$apiBaseUrl/broadcasting/auth';

  Uri get reverbUri => Uri(
    scheme: reverbUseTls ? 'wss' : 'ws',
    host: reverbHost,
    port: reverbPort,
    path: '/app/$reverbAppKey',
    queryParameters: const {'protocol': '7', 'client': 'albahar-flutter', 'version': '1.0.0', 'flash': 'false'},
  );

  String get displayName => switch (flavor) {
    Flavor.dev => 'Al Bahar (Dev)',
    Flavor.staging => 'Al Bahar (Staging)',
    Flavor.prod => 'Al Bahar',
  };
}
