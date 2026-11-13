import 'dart:convert';
import 'dart:typed_data';

import 'package:albahar_customer/core/map/map_config.dart';
import 'package:albahar_customer/core/map/map_providers.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';

/// A 1x1 transparent PNG: the "tile" every fake tile provider returns, so no test touches the network.
final Uint8List kTransparentPng = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAXpeqz8AAAAASUVORK5CYII=',
);

/// Serves the transparent tile for every request and remembers the User-Agent it was built with.
class FakeTileProvider extends TileProvider {
  FakeTileProvider(String userAgent, {this.fail = false}) : super(headers: {'User-Agent': userAgent});

  /// Makes every tile fail, to test the "tiles unavailable" note.
  final bool fail;
  final requested = <String>[];

  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) {
    requested.add(getTileUrl(coordinates, options));
    return fail ? _FailingImage() : MemoryImage(kTransparentPng);
  }
}

class _FailingImage extends ImageProvider<_FailingImage> {
  @override
  Future<_FailingImage> obtainKey(ImageConfiguration configuration) => Future.value(this);

  @override
  ImageStreamCompleter loadImage(_FailingImage key, ImageDecoderCallback decode) =>
      OneFrameImageStreamCompleter(Future<ImageInfo>.error(StateError('no tile')));
}

/// A map config that never asks the server: the given settings, always.
class StaticMapConfig extends MapConfigController {
  StaticMapConfig([this.settings]);
  final MapSettings? settings;

  @override
  MapSettings build() =>
      settings ??
      const MapSettings(
        tiles: MapTiles(
          lightUrl: 'https://tiles.test/{z}/{x}/{y}.png',
          darkUrl: 'https://tiles.test/dark/{z}/{x}/{y}.png',
        ),
      );

  @override
  Future<void> ensureFresh({bool force = false}) async {}
}

/// Providers that keep every map in a test off the network (tile provider and server settings).
List<Override> mapTestOverrides({MapSettings? settings, FakeTileProvider Function(String ua)? tiles}) => [
  mapConfigProvider.overrideWith(() => StaticMapConfig(settings)),
  mapTileProviderFactoryProvider.overrideWithValue(tiles ?? FakeTileProvider.new),
];

/// A material app around [child] with the app theme, both languages, light or dark, any text scale, and the map
/// providers faked. `ThemeMode` can change between pumps to test the live theme switch.
Widget mapHost(
  Widget child, {
  Locale locale = const Locale('en'),
  ThemeMode mode = ThemeMode.light,
  double textScale = 1,
  bool reduceMotion = false,
  List<Override> overrides = const [],
  bool bare = false,
}) => ProviderScope(
  // Pass `mapTestOverrides(...)` yourself to change the tiles or the settings; the plain ones are used otherwise.
  overrides: overrides.isEmpty ? mapTestOverrides() : overrides,
  child: MaterialApp(
    locale: locale,
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: const [
      AppLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    theme: AppTheme.light(locale),
    darkTheme: AppTheme.dark(locale),
    themeMode: mode,
    builder: (context, c) => MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(textScale), disableAnimations: reduceMotion),
      child: c!,
    ),
    home: bare ? child : Scaffold(body: child),
  ),
);
