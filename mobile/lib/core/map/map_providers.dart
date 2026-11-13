import 'dart:async';

import 'package:albahar_customer/core/map/map_config.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/update/app_update.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

const _kCacheKey = 'map_config_v1';

/// The map settings every map in the app uses. It answers at once with the copy saved on the phone (or OpenStreetMap
/// and Doha on the very first launch) and then asks the server for `GET /map-config` in the background: a changed tile
/// provider or centre applies without an app update, and a failed request just keeps what is already there.
class MapConfigController extends Notifier<MapSettings> {
  bool _asked = false;

  @override
  MapSettings build() {
    final fallback = MapSettings.fallback(tileUrl: ref.read(appConfigProvider).mapTileUrlTemplate);
    MapSettings? cached;
    try {
      cached = MapSettings.fromCache(ref.read(sharedPreferencesProvider).getString(_kCacheKey));
    } catch (_) {
      // Preferences not available (tests, very first frame): the fallback is fine.
    }
    return cached ?? fallback;
  }

  /// Asks the server once per app start (more often on request). Safe to call from every map.
  Future<void> ensureFresh({bool force = false}) async {
    if (_asked && !force) return;
    _asked = true;
    try {
      final data = await ref.read(apiClientProvider).get('/map-config');
      if (!ref.mounted) return;
      final parsed = MapSettings.tryParse(data);
      if (parsed == null) return;
      if (parsed != state) state = parsed;
      try {
        await ref.read(sharedPreferencesProvider).setString(_kCacheKey, parsed.toCache());
      } catch (_) {
        // Saving is a convenience; the next launch simply asks again.
      }
    } catch (_) {
      // Offline or an older server without the endpoint: keep the saved copy or the fallback.
    }
  }
}

final mapConfigProvider = NotifierProvider<MapConfigController, MapSettings>(MapConfigController.new);

/// Builds the tile downloader for [userAgent]. Tests replace it with an in-memory one so no map ever touches the network.
typedef MapTileProviderFactory = TileProvider Function(String userAgent);

final mapTileProviderFactoryProvider = Provider<MapTileProviderFactory>(
  // `NetworkTileProvider` already keeps a disk cache of the tiles (honouring the server's cache headers), retries failed
  // requests and cancels tiles that scrolled out of view, so no extra caching package is needed.
  (ref) =>
      (userAgent) => NetworkTileProvider(headers: {'User-Agent': userAgent}),
);

/// The package name that identifies this app to the tile servers (the OpenStreetMap policy asks for a real identifier).
String mapUserAgentPackage() => switch (AppIdentity.app) {
  'driver' => 'qa.albahar.driver',
  'staff' => 'qa.albahar.staff',
  _ => 'qa.albahar.customer',
};

/// `Al Bahar/1.4.0 (qa.albahar.driver) flutter_map`, sent as the User-Agent of every tile request.
String mapUserAgent() {
  final v = AppIdentity.version;
  return 'AlBahar${v.isEmpty ? '' : '/$v'} (${mapUserAgentPackage()}) flutter_map';
}
