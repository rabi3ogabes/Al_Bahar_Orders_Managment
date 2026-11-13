import 'package:albahar_customer/core/map/map_attribution.dart';
import 'package:albahar_customer/core/map/map_config.dart';
import 'package:albahar_customer/core/map/map_providers.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/update/app_update.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';

import '../../helpers/fakes.dart';

/// What `GET /api/v1/map-config` answers today (see `App\Support\MapConfig`).
Map<String, dynamic> serverConfig({String subdomains = 'abcd'}) => {
  'provider': 'carto',
  'tiles': {
    'light_url': 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    'dark_url': 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
    'attribution': '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
    'attribution_text': '© OpenStreetMap contributors © CARTO',
    'subdomains': subdomains,
    'max_zoom': 20,
    'retina': true,
  },
  'center': {'lat': 25.3, 'lng': 51.5},
  'zoom': 12,
  'stale_after_seconds': 120,
};

void main() {
  group('MapTiles.tryParse', () {
    test('the server payload: light and dark URLs, subdomains given as one string, retina, zoom', () {
      final tiles = MapTiles.tryParse(serverConfig()['tiles'])!;
      expect(tiles.lightUrl, contains('voyager'));
      expect(tiles.darkUrl, contains('dark_all'));
      expect(tiles.subdomains, ['a', 'b', 'c', 'd']);
      expect(tiles.maxZoom, 20);
      expect(tiles.retina, isTrue);
      expect(tiles.hasDarkStyle, isTrue);
    });

    test('subdomains as a list work as well', () {
      final tiles = MapTiles.tryParse({
        'light_url': 'https://t.example/{z}/{x}/{y}.png',
        'subdomains': ['one', 'two'],
      })!;
      expect(tiles.subdomains, ['one', 'two']);
    });

    test('no light URL, plain HTTP, a missing coordinate or a non-map value gives null (the fallback stays)', () {
      expect(MapTiles.tryParse(null), isNull);
      expect(MapTiles.tryParse('x'), isNull);
      expect(MapTiles.tryParse(<String, dynamic>{}), isNull);
      expect(MapTiles.tryParse({'light_url': 'http://t.example/{z}/{x}/{y}.png'}), isNull);
      expect(MapTiles.tryParse({'light_url': 'https://t.example/{z}/{x}.png'}), isNull);
      expect(MapTiles.tryParse({'light_url': ''}), isNull);
    });

    test('a bad dark URL is dropped on its own; the zoom is kept sane', () {
      final t = MapTiles.tryParse({
        'light_url': 'https://t.example/{z}/{x}/{y}.png',
        'dark_url': 'ftp://nope',
        'max_zoom': 99,
      })!;
      expect(t.darkUrl, isNull);
      expect(t.maxZoom, 19);
    });

    test('light and dark follow the theme, and a missing dark style asks for the night filter', () {
      final both = MapTiles.tryParse(serverConfig()['tiles'])!;
      expect(both.urlFor(Brightness.light), both.lightUrl);
      expect(both.urlFor(Brightness.dark), both.darkUrl);
      expect(both.needsDarkFilter(Brightness.dark), isFalse);
      const lightOnly = MapTiles(lightUrl: 'https://t.example/{z}/{x}/{y}.png');
      expect(lightOnly.urlFor(Brightness.dark), lightOnly.lightUrl);
      expect(lightOnly.needsDarkFilter(Brightness.dark), isTrue);
      expect(lightOnly.needsDarkFilter(Brightness.light), isFalse);
      // A dark URL that equals the light one is no dark style.
      const same = MapTiles(
        lightUrl: 'https://t.example/{z}/{x}/{y}.png',
        darkUrl: 'https://t.example/{z}/{x}/{y}.png',
      );
      expect(same.hasDarkStyle, isFalse);
    });
  });

  group('MapSettings', () {
    test('parses the whole answer, including the staleness setting', () {
      final s = MapSettings.tryParse(serverConfig())!;
      expect(s.center, const LatLng(25.3, 51.5));
      expect(s.zoom, 12);
      expect(s.provider, 'carto');
      expect(s.staleAfter, const Duration(seconds: 120));
    });

    test('missing or wild values fall back one by one (Doha, zoom 11, 90 s)', () {
      final s = MapSettings.tryParse({
        'tiles': {'light_url': 'https://t.example/{z}/{x}/{y}.png'},
        'center': {'lat': 'x'},
        'zoom': 99,
        'stale_after_seconds': 1,
      })!;
      expect(s.center, const LatLng(25.2854, 51.5310));
      expect(s.zoom, 11);
      expect(s.staleAfter, const Duration(seconds: 90));
    });

    test('an answer without usable tiles is refused as a whole', () {
      expect(
        MapSettings.tryParse({
          'tiles': {},
          'center': {'lat': 25.3, 'lng': 51.5},
        }),
        isNull,
      );
    });

    test('survives the round trip through the saved copy; a damaged copy reads as nothing', () {
      final s = MapSettings.tryParse(serverConfig())!;
      expect(MapSettings.fromCache(s.toCache()), s);
      expect(MapSettings.fromCache('{not json'), isNull);
      expect(MapSettings.fromCache(''), isNull);
      expect(MapSettings.fromCache(null), isNull);
    });

    test('the fallback is OpenStreetMap over Doha, or the configured template', () {
      final osm = MapSettings.fallback();
      expect(osm.tiles.lightUrl, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
      expect(osm.center, const LatLng(25.2854, 51.5310));
      expect(
        MapSettings.fallback(tileUrl: 'https://my.tiles/{z}/{x}/{y}.png').tiles.lightUrl,
        'https://my.tiles/{z}/{x}/{y}.png',
      );
      expect(MapSettings.fallback(tileUrl: 'http://insecure/{z}/{x}/{y}.png').tiles.lightUrl, kFallbackTileUrl);
    });
  });

  group('the saved copy and the server', () {
    test(
      'first launch: the fallback at once; the server answer replaces it and is saved for the next launch',
      () async {
        final h = await TestHarness.create(
          handler: routes({'GET /map-config': (_) => ok(serverConfig())}),
          prefs: const {},
        );
        expect(
          h.read(mapConfigProvider).tiles.lightUrl,
          'https://tiles.test/{z}/{x}/{y}.png',
        ); // the test config's template
        await h.read(mapConfigProvider.notifier).ensureFresh();
        expect(h.read(mapConfigProvider).provider, 'carto');
        expect(h.read(mapConfigProvider).tiles.subdomains, ['a', 'b', 'c', 'd']);
        final saved = h.read(sharedPreferencesProvider).getString('map_config_v1');
        expect(saved, isNotNull);
        expect(MapSettings.fromCache(saved)!.provider, 'carto');
      },
    );

    test('the next launch starts from the saved copy, with no request needed to paint', () async {
      final h = await TestHarness.create(
        handler: routes({}),
        prefs: {'map_config_v1': MapSettings.tryParse(serverConfig())!.toCache()},
      );
      expect(h.read(mapConfigProvider).provider, 'carto');
      expect(h.read(mapConfigProvider).center, const LatLng(25.3, 51.5));
    });

    test('a server error, an old server without the endpoint or rubbish keeps what is there', () async {
      for (final reply in [
        error(500, 'server_error'),
        const FakeReply(404, {'code': 'not_found'}),
        ok('rubbish'),
        ok({'tiles': {}}),
      ]) {
        final h = await TestHarness.create(handler: routes({'GET /map-config': (_) => reply}), prefs: const {});
        final before = h.read(mapConfigProvider);
        await h.read(mapConfigProvider.notifier).ensureFresh();
        expect(h.read(mapConfigProvider), before);
        expect(h.read(sharedPreferencesProvider).getString('map_config_v1'), isNull);
      }
    });

    test('offline keeps the saved copy and does not throw', () async {
      final h = await TestHarness.create(
        handler: routes({}),
        prefs: {'map_config_v1': MapSettings.tryParse(serverConfig())!.toCache()},
      );
      h.adapter.failConnection = (_) => true;
      await h.read(mapConfigProvider.notifier).ensureFresh();
      expect(h.read(mapConfigProvider).provider, 'carto');
    });

    test('the server is asked once per launch, unless forced', () async {
      final h = await TestHarness.create(
        handler: routes({'GET /map-config': (_) => ok(serverConfig())}),
        prefs: const {},
      );
      final n = h.read(mapConfigProvider.notifier);
      await n.ensureFresh();
      await n.ensureFresh();
      expect(h.adapter.where('GET', '/map-config'), hasLength(1));
      await n.ensureFresh(force: true);
      expect(h.adapter.where('GET', '/map-config'), hasLength(2));
    });

    test('without preferences (a screen shown before start-up finished) the fallback is used and nothing throws', () {
      final c = ProviderContainer(overrides: [appConfigProvider.overrideWithValue(testConfig)]);
      addTearDown(c.dispose);
      expect(c.read(mapConfigProvider).tiles.lightUrl, 'https://tiles.test/{z}/{x}/{y}.png');
    });

    test('tile requests identify the app: package name and version', () {
      final before = (AppIdentity.app, AppIdentity.version);
      addTearDown(() {
        AppIdentity.app = before.$1;
        AppIdentity.version = before.$2;
      });
      AppIdentity.version = '1.4.0';
      AppIdentity.app = 'driver';
      expect(mapUserAgentPackage(), 'qa.albahar.driver');
      expect(mapUserAgent(), 'AlBahar/1.4.0 (qa.albahar.driver) flutter_map');
      AppIdentity.app = 'staff';
      expect(mapUserAgentPackage(), 'qa.albahar.staff');
      AppIdentity.app = 'customer';
      AppIdentity.version = '';
      expect(mapUserAgent(), 'AlBahar (qa.albahar.customer) flutter_map');
    });
  });

  group('attribution', () {
    test('HTML credit lines become plain text with their https links listed', () {
      final a = MapAttribution.parse(serverConfig()['tiles']['attribution'] as String);
      expect(a.text, '© OpenStreetMap contributors © CARTO');
      expect(a.links.map((l) => l.label), ['OpenStreetMap', 'CARTO']);
      expect(a.links.first.url.toString(), 'https://www.openstreetmap.org/copyright');
    });

    test('plain OpenStreetMap text gets the copyright link the licence asks for', () {
      final a = MapAttribution.parse('© OpenStreetMap contributors');
      expect(a.links.single.url.toString(), 'https://www.openstreetmap.org/copyright');
    });

    test('non-https links and script tags never get through', () {
      final a = MapAttribution.parse('<a href="javascript:alert(1)">x</a><script>alert(1)</script> Maps');
      expect(a.links, isEmpty);
      expect(a.text, isNot(contains('<')));
    });

    test('an empty credit line falls back to the OpenStreetMap credit', () {
      expect(MapAttribution.parse('  ').text, '© OpenStreetMap contributors');
    });
  });
}
