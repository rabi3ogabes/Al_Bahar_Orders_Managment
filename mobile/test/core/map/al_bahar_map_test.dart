import 'package:albahar_customer/core/map/al_bahar_map.dart';
import 'package:albahar_customer/core/map/map_config.dart';
import 'package:albahar_customer/core/map/map_layers.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';

import '../../helpers/map_fakes.dart';

const _light = 'https://tiles.test/{z}/{x}/{y}.png';
const _dark = 'https://tiles.test/dark/{z}/{x}/{y}.png';
const _doha = LatLng(25.2854, 51.5310);
const _west = LatLng(25.3200, 51.5200);
const _south = LatLng(25.2200, 51.5600);

MapSettings _settings({
  String? dark = _dark,
  String attribution = '© OpenStreetMap contributors',
  bool retina = false,
}) => MapSettings(
  tiles: MapTiles(lightUrl: _light, darkUrl: dark, attribution: attribution, retina: retina),
);

void _size(WidgetTester t, {double w = 400, double h = 700}) {
  t.view.physicalSize = Size(w, h);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
}

Future<void> _settle(WidgetTester t) async {
  await t.pump();
  await t.pump(const Duration(milliseconds: 120));
}

void main() {
  group('tiles', () {
    testWidgets('the tile URL follows the theme live, light to dark and back', (t) async {
      _size(t);
      FakeTileProvider? provider;
      final overrides = mapTestOverrides(settings: _settings(), tiles: (ua) => provider = FakeTileProvider(ua));
      Widget host(ThemeMode mode) => mapHost(
        const AlBaharMap(controls: MapControlsConfig.none),
        mode: mode,
        overrides: overrides,
      );

      await t.pumpWidget(host(ThemeMode.light));
      await _settle(t);
      expect(provider!.requested, isNotEmpty);
      expect(provider!.requested.every((u) => !u.contains('/dark/')), isTrue);

      provider!.requested.clear();
      await t.pumpWidget(host(ThemeMode.dark));
      await t.pump(const Duration(milliseconds: 400));
      expect(provider!.requested, isNotEmpty, reason: 'the tiles are fetched again from the dark style');
      expect(provider!.requested.every((u) => u.contains('/dark/')), isTrue);

      provider!.requested.clear();
      await t.pumpWidget(host(ThemeMode.light));
      await t.pump(const Duration(milliseconds: 400));
      expect(provider!.requested.every((u) => !u.contains('/dark/')), isTrue);
    });

    testWidgets('no dark style: the light tiles are darkened with a colour filter, only in the dark theme', (t) async {
      _size(t);
      final overrides = mapTestOverrides(settings: _settings(dark: null));
      Finder night() => find.byWidgetPredicate((w) => w is ColorFiltered && w.colorFilter == kNightTileFilter);

      await t.pumpWidget(
        mapHost(
          const AlBaharMap(controls: MapControlsConfig.none),
          mode: ThemeMode.light,
          overrides: overrides,
        ),
      );
      await _settle(t);
      expect(night(), findsNothing);

      await t.pumpWidget(
        mapHost(
          const AlBaharMap(controls: MapControlsConfig.none),
          mode: ThemeMode.dark,
          overrides: overrides,
        ),
      );
      await _settle(t);
      expect(night(), findsWidgets);
    });

    testWidgets('a real dark style needs no filter', (t) async {
      _size(t);
      await t.pumpWidget(
        mapHost(
          const AlBaharMap(controls: MapControlsConfig.none),
          mode: ThemeMode.dark,
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      expect(find.byWidgetPredicate((w) => w is ColorFiltered && w.colorFilter == kNightTileFilter), findsNothing);
    });

    testWidgets('tile requests carry the app identity in the User-Agent', (t) async {
      _size(t);
      FakeTileProvider? provider;
      await t.pumpWidget(
        mapHost(
          const AlBaharMap(controls: MapControlsConfig.none),
          overrides: mapTestOverrides(settings: _settings(), tiles: (ua) => provider = FakeTileProvider(ua)),
        ),
      );
      await _settle(t);
      expect(
        provider!.headers['User-Agent'],
        allOf(startsWith('AlBahar'), contains('qa.albahar.'), contains('flutter_map')),
      );
    });

    testWidgets('failing tiles: a soft background and one "Map tiles unavailable" note that goes away and stays away', (
      t,
    ) async {
      _size(t);
      await t.pumpWidget(
        mapHost(
          const AlBaharMap(controls: MapControlsConfig.none),
          overrides: mapTestOverrides(settings: _settings(), tiles: (ua) => FakeTileProvider(ua, fail: true)),
        ),
      );
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      expect(find.text('Map tiles unavailable'), findsOneWidget);
      // It disappears on its own...
      await t.pump(const Duration(seconds: 8));
      expect(find.text('Map tiles unavailable'), findsNothing);
      // ...and a map that keeps failing does not nag again.
      await t.pump(const Duration(seconds: 3));
      expect(find.text('Map tiles unavailable'), findsNothing);
      expect(t.takeException(), isNull);
    });

    testWidgets('working tiles show no note', (t) async {
      _size(t);
      await t.pumpWidget(
        mapHost(
          const AlBaharMap(controls: MapControlsConfig.none),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      expect(find.text('Map tiles unavailable'), findsNothing);
    });
  });

  group('credit line', () {
    testWidgets('always visible, labelled, 48 dp tall, and a tap opens the full text with its link', (t) async {
      _size(t);
      final handle = t.ensureSemantics();
      await t.pumpWidget(
        mapHost(
          const AlBaharMap(controls: MapControlsConfig.none),
          overrides: mapTestOverrides(
            settings: _settings(
              attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            ),
          ),
        ),
      );
      await _settle(t);
      expect(find.text('© OpenStreetMap contributors'), findsOneWidget);
      expect(t.getSize(find.byType(InkWell).first).height, greaterThanOrEqualTo(48));
      expect(find.bySemanticsLabel(RegExp('Map data: ')), findsOneWidget);

      await t.tap(find.text('© OpenStreetMap contributors'));
      await t.pumpAndSettle();
      expect(find.text('Map data'), findsOneWidget);
      expect(find.text('Open OpenStreetMap'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('it sits above a bottom sheet, not behind it', (t) async {
      _size(t);
      await t.pumpWidget(
        mapHost(
          const AlBaharMap(controls: MapControlsConfig.none, bottomInset: 200),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      final bottom = t.getRect(find.text('© OpenStreetMap contributors')).bottom;
      expect(bottom, lessThanOrEqualTo(700 - 200));
    });
  });

  group('controls', () {
    const all = MapControlsConfig(follow: true, zoom: true, myLocation: true);

    testWidgets('labelled, 48 dp, with the right accessibility (EN and AR, text x1.3, dark)', (t) async {
      for (final (locale, scale, mode) in [
        (const Locale('en'), 1.0, ThemeMode.light),
        (const Locale('ar'), 1.3, ThemeMode.dark),
      ]) {
        _size(t, w: 360, h: 640);
        final handle = t.ensureSemantics();
        await t.pumpWidget(
          mapHost(
            AlBaharMap(controls: all, onMyLocation: () {}),
            locale: locale,
            textScale: scale,
            mode: mode,
            overrides: mapTestOverrides(settings: _settings()),
          ),
        );
        await _settle(t);
        expect(t.takeException(), isNull);
        await expectLater(t, meetsGuideline(androidTapTargetGuideline));
        await expectLater(t, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
        await t.pumpWidget(const SizedBox.shrink());
      }
    });

    testWidgets('the English labels', (t) async {
      _size(t);
      final handle = t.ensureSemantics();
      await t.pumpWidget(
        mapHost(
          AlBaharMap(controls: all, onMyLocation: () {}),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      for (final label in ['Zoom in', 'Zoom out', 'My location', 'Follow live position', 'Show everything']) {
        expect(find.bySemanticsLabel(label), findsOneWidget, reason: label);
      }
      handle.dispose();
    });

    testWidgets('tooltips exist where there is an Overlay (inside a route)', (t) async {
      _size(t);
      await t.pumpWidget(mapHost(const AlBaharMap(), overrides: mapTestOverrides(settings: _settings())));
      await _settle(t);
      expect(find.byTooltip('Show everything'), findsOneWidget);
    });

    testWidgets('no Overlay above the map (a bare widget tree): no tooltip, no crash', (t) async {
      _size(t);
      await t.pumpWidget(
        ProviderScope(
          overrides: mapTestOverrides(settings: _settings()),
          child: Directionality(
            textDirection: TextDirection.ltr,
            child: MediaQuery(
              data: const MediaQueryData(size: Size(400, 700)),
              child: Theme(
                data: ThemeData(),
                child: Localizations(
                  locale: const Locale('en'),
                  delegates: const [
                    DefaultWidgetsLocalizations.delegate,
                    DefaultMaterialLocalizations.delegate,
                    AppLocalizations.delegate,
                  ],
                  child: const Material(child: SizedBox(width: 400, height: 700, child: AlBaharMap())),
                ),
              ),
            ),
          ),
        ),
      );
      await _settle(t);
      expect(t.takeException(), isNull);
      expect(find.byType(Tooltip), findsNothing);
    });

    testWidgets('the buttons sit at the end of the map: right in English, left in Arabic', (t) async {
      for (final (locale, onRight) in [(const Locale('en'), true), (const Locale('ar'), false)]) {
        _size(t);
        await t.pumpWidget(
          mapHost(
            const AlBaharMap(),
            locale: locale,
            overrides: mapTestOverrides(settings: _settings()),
          ),
        );
        await _settle(t);
        final x = t.getCenter(find.byIcon(Icons.center_focus_strong_rounded)).dx;
        expect(x > 200, onRight, reason: '$locale');
        // The credit line is at the opposite corner.
        final c = t.getCenter(find.text('© OpenStreetMap contributors')).dx;
        expect(c > 200, !onRight, reason: '$locale');
        await t.pumpWidget(const SizedBox.shrink());
      }
    });

    testWidgets('the compass appears only while the map is turned, and puts it back to north', (t) async {
      _size(t);
      final c = AlBaharMapController();
      addTearDown(c.dispose);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(controller: c, rotatable: true),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      expect(find.byIcon(Icons.explore_rounded), findsNothing);
      c.raw.rotate(40);
      await t.pump();
      expect(find.byIcon(Icons.explore_rounded), findsOneWidget);
      await t.tap(find.byIcon(Icons.explore_rounded));
      await t.pump();
      expect(c.raw.camera.rotation, 0);
      expect(find.byIcon(Icons.explore_rounded), findsNothing);
    });
  });

  group('camera', () {
    testWidgets('a fit keeps every point clear of the bottom sheet and the top inset', (t) async {
      _size(t, w: 400, h: 700);
      final c = AlBaharMapController();
      addTearDown(c.dispose);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(controller: c, bottomInset: 300, topInset: 60, controls: MapControlsConfig.none),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      c.fit([_west, _south, _doha], animate: false);
      await t.pump();
      final cam = c.camera!;
      for (final p in [_west, _south, _doha]) {
        // The offset from the pixel origin is the position on the map's own screen.
        final screen = cam.getOffsetFromOrigin(p);
        expect(screen.dy, inInclusiveRange(60, 700 - 300), reason: '$p must be above the sheet');
        expect(screen.dx, inInclusiveRange(0, 400));
      }
    });

    testWidgets('one point centres at street level; nothing to fit does nothing', (t) async {
      _size(t);
      final c = AlBaharMapController();
      addTearDown(c.dispose);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(controller: c, controls: MapControlsConfig.none),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      c.fit([_doha], animate: false);
      await t.pump();
      expect(c.camera!.zoom, 16);
      final before = c.camera!.center;
      c.fit(const [], animate: false);
      await t.pump();
      expect(c.camera!.center, before);
    });

    testWidgets('a sheet growing re-frames the markers while the person has not touched the map', (t) async {
      _size(t, w: 400, h: 700);
      final c = AlBaharMapController();
      addTearDown(c.dispose);
      Widget host(double inset) => mapHost(
        AlBaharMap(controller: c, bottomInset: inset, controls: MapControlsConfig.none),
        overrides: mapTestOverrides(settings: _settings()),
      );
      await t.pumpWidget(host(0));
      await _settle(t);
      c.fit([_west, _south], animate: false);
      await t.pump();
      final low = c.camera!.getOffsetFromOrigin(_south).dy;
      await t.pumpWidget(host(320));
      await t.pump();
      final high = c.camera!.getOffsetFromOrigin(_south).dy;
      expect(high, lessThan(low), reason: 'the southern point moved up to stay above the sheet');
      expect(high, lessThanOrEqualTo(700 - 320));
    });

    testWidgets('after the person moves the map, a sheet change no longer re-frames it; "show everything" does', (
      t,
    ) async {
      _size(t, w: 400, h: 700);
      final c = AlBaharMapController();
      addTearDown(c.dispose);
      Widget host(double inset) => mapHost(
        AlBaharMap(controller: c, bottomInset: inset),
        overrides: mapTestOverrides(settings: _settings()),
      );
      await t.pumpWidget(host(0));
      await _settle(t);
      c.fit([_west, _south], animate: false);
      await t.pump();
      expect(c.userMoved, isFalse);
      await t.dragFrom(const Offset(200, 300), const Offset(-120, -60));
      await t.pump(const Duration(milliseconds: 400));
      expect(c.userMoved, isTrue);
      final moved = c.camera!.center;
      await t.pumpWidget(host(200));
      await t.pump();
      expect(c.camera!.center, moved);

      await t.tap(find.byIcon(Icons.center_focus_strong_rounded));
      await t.pump(const Duration(seconds: 2));
      expect(c.userMoved, isFalse);
      expect(c.camera!.center.latitude, closeTo((_west.latitude + _south.latitude) / 2, 0.02));
    });

    testWidgets('flyTo glides (not a jump) and arrives; with reduced motion it jumps at once', (t) async {
      _size(t);
      final c = AlBaharMapController();
      addTearDown(c.dispose);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(controller: c, controls: MapControlsConfig.none, initialCenter: _doha, initialZoom: 12),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      c.flyTo(_south, zoom: 15);
      await t.pump();
      await t.pump(const Duration(milliseconds: 200));
      final mid = c.camera!.center;
      expect(mid, isNot(_doha));
      expect(mid, isNot(_south));
      await t.pump(const Duration(seconds: 2));
      expect(c.camera!.center.latitude, closeTo(_south.latitude, 1e-6));
      expect(c.camera!.zoom, 15);

      final c2 = AlBaharMapController();
      addTearDown(c2.dispose);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(
            key: UniqueKey(),
            controller: c2,
            controls: MapControlsConfig.none,
            initialCenter: _doha,
            initialZoom: 12,
          ),
          reduceMotion: true,
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      c2.flyTo(_south, zoom: 15);
      await t.pump();
      expect(c2.camera!.center.latitude, closeTo(_south.latitude, 1e-6));
    });

    testWidgets('following: the camera tracks the moving point, and a drag by the person ends it', (t) async {
      _size(t);
      final c = AlBaharMapController();
      addTearDown(c.dispose);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(
            controller: c,
            controls: const MapControlsConfig(follow: true),
            initialCenter: _doha,
            initialZoom: 13,
          ),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      c.followPoint(_south); // not following yet: nothing happens
      await t.pump(const Duration(seconds: 1));
      expect(c.camera!.center.latitude, closeTo(_doha.latitude, 1e-6));

      await t.tap(find.byIcon(Icons.navigation_outlined));
      await t.pump();
      expect(c.following, isTrue);
      c.followPoint(_south);
      await t.pump(); // the first frame starts the glide
      await t.pump(const Duration(seconds: 2));
      expect(c.camera!.center.latitude, closeTo(_south.latitude, 1e-5));
      expect(c.camera!.zoom, greaterThanOrEqualTo(15.5));

      await t.dragFrom(const Offset(200, 300), const Offset(60, 60));
      await t.pump(const Duration(milliseconds: 400));
      expect(c.following, isFalse);
      expect(find.byIcon(Icons.navigation_outlined), findsOneWidget);
    });

    testWidgets('calls made before the first layout wait for it; calls after the map is gone do nothing', (t) async {
      _size(t);
      final c = AlBaharMapController();
      c.fit([_west, _south]); // queued: the map does not exist yet
      c.flyTo(_doha);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(controller: c, controls: MapControlsConfig.none),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(c.camera, isNotNull);
      await t.pumpWidget(const SizedBox.shrink());
      c.fit([_doha]);
      c.dispose();
      c.flyTo(_south); // after dispose: ignored
    });
  });

  group('layers and pins', () {
    testWidgets('a pin sits with its tip on the coordinate', (t) async {
      _size(t);
      final c = AlBaharMapController();
      addTearDown(c.dispose);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(
            controller: c,
            controls: MapControlsConfig.none,
            initialCenter: _doha,
            initialZoom: 15,
            children: [
              MarkerLayer(
                markers: [pinMarker(_doha, kind: PinKind.destination, label: 'Home')],
              ),
            ],
          ),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      await _settle(t);
      final pin = t.getRect(find.byType(MapPin));
      // The pin's bottom centre is the map centre (the camera is centred on the point).
      expect(pin.bottomCenter.dx, closeTo(200, 1));
      expect(pin.bottomCenter.dy, closeTo(350, 1));
    });

    testWidgets('the route draws a trail and a dashed remaining line; fewer than two points draw nothing', (t) async {
      _size(t);
      late BuildContext ctx;
      await t.pumpWidget(
        mapHost(
          Builder(
            builder: (context) {
              ctx = context;
              return const SizedBox();
            },
          ),
          overrides: mapTestOverrides(settings: _settings()),
        ),
      );
      expect(routeLayers(ctx), isEmpty);
      expect(routeLayers(ctx, remaining: [_doha]), isEmpty);
      expect(routeLayers(ctx, remaining: [_doha, _south]), hasLength(1));
      expect(routeLayers(ctx, remaining: [_doha, _south], trail: [_west, _doha]), hasLength(2));
    });

    test('the trail keeps the last N points and ignores repeats', () {
      var trail = <LatLng>[];
      for (var i = 0; i < 60; i++) {
        trail = appendTrail(trail, LatLng(25 + i * 0.001, 51), max: 40);
      }
      expect(trail, hasLength(40));
      expect(trail.last.latitude, closeTo(25.059, 1e-9));
      final same = appendTrail(trail, trail.last);
      expect(same, hasLength(40));
      expect(identical(same, trail), isTrue);
    });
  });
}
