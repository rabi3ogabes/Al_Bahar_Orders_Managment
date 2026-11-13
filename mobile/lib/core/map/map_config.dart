import 'dart:convert';

import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:flutter/foundation.dart';
import 'package:latlong2/latlong.dart';

/// OpenStreetMap standard tiles: the built-in fallback until the server answers `GET /map-config` (and when it never does).
const String kFallbackTileUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const String kFallbackAttribution = '© OpenStreetMap contributors';

/// Where the map tiles come from, as served by `GET /api/v1/map-config`.
@immutable
class MapTiles {
  const MapTiles({
    required this.lightUrl,
    this.darkUrl,
    this.attribution = kFallbackAttribution,
    this.subdomains = const [],
    this.maxZoom = 19,
    this.retina = false,
  });

  /// Reads `{light_url, dark_url, attribution, subdomains, max_zoom, retina}`. A missing or unusable `light_url`
  /// gives null (the caller keeps its fallback); every other field falls back on its own.
  static MapTiles? tryParse(Object? raw) {
    if (raw is! Map) return null;
    final light = _template(raw['light_url'] ?? raw['url']);
    if (light == null) return null;
    final dark = _template(raw['dark_url']);
    final attribution = '${raw['attribution'] ?? ''}'.trim();
    final sub = raw['subdomains'];
    final maxZoom = raw['max_zoom'];
    return MapTiles(
      lightUrl: light,
      darkUrl: dark,
      attribution: attribution.isEmpty ? kFallbackAttribution : attribution,
      subdomains: sub is List
          ? [for (final s in sub) if ('$s'.trim().isNotEmpty) '$s'.trim()]
          : (sub is String && sub.trim().isNotEmpty ? sub.trim().split('') : const []),
      maxZoom: maxZoom is num && maxZoom >= 3 && maxZoom <= 22 ? maxZoom.round() : 19,
      retina: raw['retina'] == true,
    );
  }

  /// A tile URL template over HTTPS with the three coordinates; anything else (a plain-HTTP or half-written URL from a
  /// mistyped admin setting) is refused, since cleartext traffic is off on Android.
  static String? _template(Object? raw) {
    final s = '${raw ?? ''}'.trim();
    if (s.isEmpty) return null;
    final ok = s.startsWith('https://') && s.contains('{z}') && s.contains('{x}') && s.contains('{y}');
    return ok ? s : null;
  }

  final String lightUrl;

  /// A separate dark style; null when the server has none (the app then darkens the light tiles itself).
  final String? darkUrl;
  final String attribution;
  final List<String> subdomains;
  final int maxZoom;

  /// True when the URL template can serve sharper tiles (it carries `{r}`), asked for on high-density screens.
  final bool retina;

  bool get hasDarkStyle => darkUrl != null && darkUrl != lightUrl;

  String urlFor(Brightness brightness) => brightness == Brightness.dark && hasDarkStyle ? darkUrl! : lightUrl;

  /// Whether to tint the light tiles dark because the theme is dark and no dark style exists.
  bool needsDarkFilter(Brightness brightness) => brightness == Brightness.dark && !hasDarkStyle;

  Map<String, dynamic> toJson() => {
    'light_url': lightUrl,
    'dark_url': darkUrl,
    'attribution': attribution,
    'subdomains': subdomains,
    'max_zoom': maxZoom,
    'retina': retina,
  };

  @override
  bool operator ==(Object other) =>
      other is MapTiles &&
      other.lightUrl == lightUrl &&
      other.darkUrl == darkUrl &&
      other.attribution == attribution &&
      listEquals(other.subdomains, subdomains) &&
      other.maxZoom == maxZoom &&
      other.retina == retina;

  @override
  int get hashCode => Object.hash(lightUrl, darkUrl, attribution, Object.hashAll(subdomains), maxZoom, retina);
}

/// The server's map settings: tiles, the default centre and zoom, and the provider name (informational).
@immutable
class MapSettings {
  const MapSettings({
    required this.tiles,
    this.center = MapGeo.doha,
    this.zoom = 11,
    this.provider = 'osm',
    this.staleAfter = MapGeo.staleAfter,
  });

  /// OpenStreetMap and Doha: used until the server has answered once and whenever it cannot be reached.
  factory MapSettings.fallback({String? tileUrl}) => MapSettings(
    tiles: MapTiles(lightUrl: MapTiles._template(tileUrl) ?? kFallbackTileUrl),
  );

  /// Reads the `GET /map-config` data. Null when there is no usable tile URL.
  static MapSettings? tryParse(Object? raw) {
    if (raw is! Map) return null;
    final tiles = MapTiles.tryParse(raw['tiles']);
    if (tiles == null) return null;
    final c = raw['center'];
    final lat = c is Map && c['lat'] is num ? (c['lat'] as num).toDouble() : null;
    final lng = c is Map && c['lng'] is num ? (c['lng'] as num).toDouble() : null;
    final zoom = raw['zoom'];
    final stale = raw['stale_after_seconds'];
    return MapSettings(
      tiles: tiles,
      center: MapGeo.isFinitePair(lat, lng) ? LatLng(lat!, lng!) : MapGeo.doha,
      zoom: zoom is num && zoom >= 2 && zoom <= 20 ? zoom.toDouble() : 11,
      provider: '${raw['provider'] ?? 'osm'}',
      staleAfter: stale is num && stale >= 15 && stale <= 3600 ? Duration(seconds: stale.round()) : MapGeo.staleAfter,
    );
  }

  /// Reads the copy saved on the phone; null when it is missing or damaged.
  static MapSettings? fromCache(String? json) {
    if (json == null || json.isEmpty) return null;
    try {
      return tryParse(jsonDecode(json));
    } catch (_) {
      return null;
    }
  }

  String toCache() => jsonEncode({
    'tiles': tiles.toJson(),
    'center': {'lat': center.latitude, 'lng': center.longitude},
    'zoom': zoom,
    'provider': provider,
    'stale_after_seconds': staleAfter.inSeconds,
  });

  final MapTiles tiles;
  final LatLng center;
  final double zoom;
  final String provider;

  /// How old a driver position may get before it is shown as "last seen" (the server's `stale_after_seconds`).
  final Duration staleAfter;

  @override
  bool operator ==(Object other) =>
      other is MapSettings &&
      other.tiles == tiles &&
      other.center == center &&
      other.zoom == zoom &&
      other.provider == provider &&
      other.staleAfter == staleAfter;

  @override
  int get hashCode => Object.hash(tiles, center, zoom, provider, staleAfter);
}
