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

    final defaults = switch (resolved) {
      Flavor.dev => (api: 'http://10.0.2.2:8000/api/v1', host: '10.0.2.2', port: 8080, tls: false, key: 'albahar-dev'),
      Flavor.staging => (
        api: 'https://staging-api.albahar.qa/api/v1',
        host: 'staging-ws.albahar.qa',
        port: 443,
        tls: true,
        key: 'albahar-staging',
      ),
      Flavor.prod => (
        api: 'https://api.albahar.qa/api/v1',
        host: 'ws.albahar.qa',
        port: 443,
        tls: true,
        key: 'albahar',
      ),
    };

    return AppConfig(
      flavor: resolved,
      apiBaseUrl: apiOverride.isNotEmpty ? apiOverride : defaults.api,
      reverbAppKey: reverbKey.isNotEmpty ? reverbKey : defaults.key,
      reverbHost: reverbHost.isNotEmpty ? reverbHost : defaults.host,
      reverbPort: reverbPort != 0 ? reverbPort : defaults.port,
      reverbUseTls: reverbScheme.isNotEmpty ? reverbScheme == 'wss' || reverbScheme == 'https' : defaults.tls,
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
