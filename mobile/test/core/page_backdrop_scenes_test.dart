import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/page_backdrop.dart';
import 'package:albahar_customer/core/theme/qatar_art_paths.dart';
import 'package:albahar_customer/core/theme/qatar_skyline_paths.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/home_slider_fakes.dart';

/// The built-in artworks of the page background: the `scene` the dashboard chooses (docs/api/PAGE_BACKDROP.md, Models).

const _en = Locale('en');
const _ar = Locale('ar');
const _burgundy = Color(0xFF8A1538);

/// The scenes that stand on the bottom edge, and the one that tiles.
const _standing = ['doha', 'dhow', 'souq', 'dunes', 'museum'];
const _tile = 'arabesque';

/// A complete `backdrop` block as the server sends it, with the given values replaced.
Map<String, Object?> _block([Map<String, Object?> change = const {}]) => {
  'style': 'skyline',
  'scene': 'doha',
  'image_url': null,
  'fit': 'bottom',
  'opacity': 0.08,
  'color': null,
  'tint': null,
  ...change,
};

Widget _host(Widget home, ThemeData light, {ThemeData? dark, bool darkMode = false, Locale locale = _en}) =>
    MaterialApp(
      locale: locale,
      supportedLocales: const [_en, _ar],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: light,
      darkTheme: dark ?? light,
      themeMode: darkMode ? ThemeMode.dark : ThemeMode.light,
      home: home,
    );

/// Pixels of a picture, in straight (not premultiplied) RGBA, so a tint at half opacity reads as its colour with alpha 128.
class _Raster {
  const _Raster(this.width, this.height, this._bytes);

  final int width;
  final int height;
  final ByteData _bytes;

  List<int> at(int x, int y) => [for (var i = 0; i < 4; i++) _bytes.getUint8((y * width + x) * 4 + i)];

  int alpha(int x, int y) => _bytes.getUint8((y * width + x) * 4 + 3);

  /// The bytes of a rectangle, to compare one cell of a pattern with another.
  List<int> block(int left, int top, int w, int h) => [
    for (var y = top; y < top + h; y++)
      for (var x = left; x < left + w; x++) ...at(x, y),
  ];
}

/// Paints [painter] on a canvas of [size] and reads the pixels of an image of [imageSize] (the canvas size by default).
/// A bigger image shows whether anything is painted outside the canvas the painter was given.
Future<_Raster> _render(CustomPainter painter, Size size, {Size? imageSize}) async {
  final recorder = ui.PictureRecorder();
  painter.paint(Canvas(recorder), size);
  final picture = recorder.endRecording();
  final target = imageSize ?? size;
  final image = await picture.toImage(target.width.round(), target.height.round());
  final data = await image.toByteData(format: ui.ImageByteFormat.rawStraightRgba);
  final raster = _Raster(image.width, image.height, data!);
  image.dispose();
  picture.dispose();
  return raster;
}

/// How far (of 255) the soft edge of the same cell may differ between two places on a page: the rasteriser's anti-aliasing
/// is not exactly the same everywhere. A cell that is cut in the wrong place, shifted or squeezed is off by the 128 of
/// the tint.
const _edgeNoise = 24;

/// How many pixels of two pictures (as the bytes of [_Raster.block]) differ in their alpha by more than [tolerance] steps
/// of 255. The same cell lands on the same pixels, but a different offset can round a soft edge a little the other way
/// (the rasteriser's anti-aliasing is not the same everywhere on a page); the colour of a nearly clear pixel is rounding
/// noise in straight RGBA, so only the alpha is compared (the colour has its own checks). A wrongly cut, shifted or
/// squeezed cell differs by the full 128 of the tint, far above any tolerance used here.
int _differences(List<int> a, List<int> b, {int tolerance = 1}) {
  expect(a.length, b.length);
  var count = 0;
  for (var i = 3; i < a.length; i += 4) {
    if ((a[i] - b[i]).abs() > tolerance) count++;
  }
  return count;
}

/// Where a point of a bottom-standing drawing (1600 x 340) lands on a canvas of [size], worked out here on its own (the
/// painter's own `boxFor` has its own test) as a whole pixel.
(int, int) _landing(Size size, double x, double y) {
  final width = size.width < 760 ? 760.0 : size.width;
  final scale = width / 1600;
  return (((size.width - width) / 2 + x * scale).floor(), (size.height - 340 * scale + y * scale).floor());
}

/// Points of each drawing (drawing coordinates) that are well inside the artwork (four units from any edge), and points that
/// are well outside it. Picked from the polygons by a point-in-polygon scan.
const _inside = <String, List<(double, double)>>{
  'dhow': [(360, 246), (200, 335)],
  'souq': [(970, 106), (210, 335)],
  'dunes': [(1230, 30), (1100, 150)],
  'museum': [(790, 30), (340, 335)],
};
const _outside = <String, List<(double, double)>>{
  'dhow': [(360, 232), (800, 10)],
  'souq': [(970, 92), (800, 10)],
  'dunes': [(1230, 16), (800, 8)],
  'museum': [(790, 16), (800, 8)],
};

/// One inside point per drawing whose x is in the middle 450..1150, the part that a phone shows.
const _insideMiddle = <String, (double, double)>{
  'dhow': (600, 280),
  'souq': (800, 334),
  'dunes': (1100, 150),
  'museum': (650, 290),
};

void main() {
  group('the scene in the backdrop block', () {
    test('an older server sends no scene: the Doha skyline, as before', () {
      final c = PageBackdropConfig.fromJson({'style': 'skyline'});
      expect(c.scene, 'doha');
      expect(c, const PageBackdropConfig());
      expect(const PageBackdropConfig().scene, 'doha');
      expect(PageBackdropConfig.defaultScene, 'doha');
      expect(PageBackdropConfig.off.scene, 'doha');
      expect(PageBackdropConfig.fromJson(_block()).scene, 'doha');
    });

    test('every allowed key is read, in any letter case and with stray spaces', () {
      expect(PageBackdropConfig.scenes, ['doha', 'dhow', 'souq', 'dunes', 'museum', 'arabesque']);
      for (final key in PageBackdropConfig.scenes) {
        expect(PageBackdropConfig.fromJson(_block({'scene': key})).scene, key);
        expect(PageBackdropConfig.fromJson(_block({'scene': ' ${key.toUpperCase()} '})).scene, key);
      }
    });

    test('the list of scenes is the Doha skyline plus every generated artwork, nothing missing and nothing extra', () {
      expect(PageBackdropConfig.scenes.toSet(), {PageBackdropConfig.defaultScene, ...kQatarArt.keys});
      expect(PageBackdropConfig.scenes.toSet().length, PageBackdropConfig.scenes.length);
    });

    test('an unknown or mistyped scene is the Doha skyline and does not switch the feature off', () {
      for (final raw in <Object?>[
        'atlantis',
        '',
        '   ',
        'doha2',
        'Dhows',
        5,
        1.5,
        true,
        false,
        null,
        ['dhow'],
        {'a': 1},
      ]) {
        final c = PageBackdropConfig.fromJson(_block({'scene': raw, 'opacity': 0.2}));
        expect(c.scene, 'doha', reason: '$raw');
        expect(c.style, BackdropStyle.skyline, reason: '$raw');
        expect(c.opacity, 0.2, reason: '$raw');
        expect(c.isActive, isTrue, reason: '$raw');
      }
    });

    test('the other rules stay as they were: a bad opacity or style is still off, whatever the scene', () {
      expect(PageBackdropConfig.fromJson(_block({'scene': 'dunes', 'opacity': 0.9})), PageBackdropConfig.off);
      expect(PageBackdropConfig.fromJson(_block({'scene': 'dunes', 'style': 'neon'})), PageBackdropConfig.off);
      expect(PageBackdropConfig.fromJson(_block({'scene': 'dunes', 'tint': 'red'})), PageBackdropConfig.off);
    });

    test('"none" and an uploaded picture keep their meaning; the scene is carried but nothing draws it', () {
      final none = PageBackdropConfig.fromJson(_block({'style': 'none', 'scene': 'dunes'}));
      expect(none.isActive, isFalse);
      expect(none.hasPicture, isFalse);
      final image = PageBackdropConfig.fromJson(
        _block({'style': 'image', 'scene': 'souq', 'image_url': 'https://cdn.test/bg.jpg'}),
      );
      expect(image.style, BackdropStyle.image);
      expect(image.imageUrl, 'https://cdn.test/bg.jpg');
    });

    test('value equality, hash code and text include the scene', () {
      PageBackdropConfig read(String scene) => PageBackdropConfig.fromJson(_block({'scene': scene}));
      expect(read('dunes'), read('dunes'));
      expect(read('dunes').hashCode, read('dunes').hashCode);
      expect(read('dunes') == read('souq'), isFalse);
      expect(read('dunes') == read('doha'), isFalse);
      expect(read('arabesque').toString(), contains('scene: arabesque'));
      // Two different artworks never collide in a set, so a live change of scene rebuilds whatever depends on it.
      expect({for (final key in PageBackdropConfig.scenes) read(key)}.length, PageBackdropConfig.scenes.length);
      expect(const PageBackdropConfig(scene: 'museum').scene, 'museum');
    });

    test('it rides along in the theme payload, and a new scene is a changed theme', () {
      Map<String, Object?> payload(String scene) => {
        'key': 'default',
        'kind': 'base',
        'colors': {
          'primary': '#8A1538',
          'primary_soft': '#A3294E',
          'accent': '#B3254F',
          'background': '#FCF9FA',
          'surface': '#FFFFFF',
          'ink': '#2A1A20',
        },
        'backdrop': _block({'scene': scene}),
      };
      expect(BrandTheme.fromJson(payload('museum'))!.backdrop.scene, 'museum');
      expect(BrandTheme.fromJson(payload('nonsense'))!.backdrop.scene, 'doha');
      expect(BrandTheme.fromJson(payload('museum'))! == BrandTheme.fromJson(payload('dhow'))!, isFalse);
      expect(BrandTheme.fromJson(payload('museum'))! == BrandTheme.fromJson(payload('museum'))!, isTrue);
      // The app theme hands the same scene to the pages.
      final theme = AppTheme.light(_en, backdrop: PageBackdropConfig.fromJson(_block({'scene': 'souq'})));
      expect(theme.extension<PageBackdropTheme>()!.config.scene, 'souq');
      expect(
        const PageBackdropTheme(PageBackdropConfig(scene: 'souq')) ==
            const PageBackdropTheme(PageBackdropConfig(scene: 'dunes')),
        isFalse,
      );
    });
  });

  group('the built-in artworks', () {
    test('the five generated artworks resolve, build a real outline, and sit in their drawing box', () {
      expect(kQatarArt.keys, unorderedEquals(['dhow', 'souq', 'dunes', 'museum', 'arabesque']));
      for (final entry in kQatarArt.entries) {
        final data = entry.value;
        expect(data.key, entry.key);
        final art = BackdropArt.of(entry.key);
        expect(art.key, entry.key);
        expect(art.tile, data.tile);
        expect((art.width, art.height), (data.width, data.height));
        expect(art.path.fillType, PathFillType.nonZero, reason: entry.key);
        final bounds = art.path.getBounds();
        expect(bounds.isEmpty, isFalse, reason: entry.key);
        if (data.tile) {
          // A cell reaches its edges; its corner and edge ornaments spill over them and are cut by the clip of each cell.
          expect(bounds.left, lessThanOrEqualTo(0), reason: entry.key);
          expect(bounds.top, lessThanOrEqualTo(0), reason: entry.key);
          expect(bounds.right, greaterThanOrEqualTo(data.width), reason: entry.key);
          expect(bounds.bottom, greaterThanOrEqualTo(data.height), reason: entry.key);
          expect(bounds.left, greaterThan(-40), reason: entry.key);
          expect(bounds.right, lessThan(data.width + 40), reason: entry.key);
        } else {
          // A scene stays in its box give or take the few units its smoothed curves overshoot (the page clips them).
          const slack = 8;
          expect(bounds.left, greaterThan(-slack), reason: entry.key);
          expect(bounds.top, greaterThanOrEqualTo(0), reason: entry.key);
          expect(bounds.right, lessThan(data.width + slack), reason: entry.key);
          expect(bounds.bottom, lessThan(data.height + slack), reason: entry.key);
          expect(bounds.width, greaterThan(data.width * 0.95), reason: '${entry.key} spans the width');
          expect(bounds.bottom, greaterThan(data.height - 5), reason: '${entry.key} stands on the bottom edge');
        }
      }
    });

    test('the scenes are 1600 x 340 and stand on the bottom edge, the pattern is one 200 x 200 tile', () {
      for (final key in _standing) {
        final art = BackdropArt.of(key);
        expect((art.width, art.height, art.tile), (1600.0, 340.0, false), reason: key);
      }
      final tile = BackdropArt.of(_tile);
      expect((tile.width, tile.height, tile.tile), (200.0, 200.0, true));
    });

    test('the Doha skyline is the generated one, and an unknown key is the Doha skyline', () {
      final doha = BackdropArt.of('doha');
      expect(doha.key, 'doha');
      expect((doha.width, doha.height), (kSkylineWidth, kSkylineHeight));
      expect(doha.path.getBounds(), buildQatarSkylinePath().getBounds());
      expect(identical(BackdropArt.of('atlantis'), doha), isTrue);
      expect(identical(BackdropArt.of(''), doha), isTrue);
    });

    test('every outline is built once for the whole app', () {
      for (final key in PageBackdropConfig.scenes) {
        expect(identical(BackdropArt.of(key), BackdropArt.of(key)), isTrue, reason: key);
        expect(identical(BackdropArt.of(key).path, BackdropArt.of(key).path), isTrue, reason: key);
      }
      expect(
        {for (final key in PageBackdropConfig.scenes) BackdropArt.of(key)}.length,
        PageBackdropConfig.scenes.length,
        reason: 'six different artworks',
      );
    });
  });

  group('the painter', () {
    test(
      'a scene stands on the bottom edge as wide as the page (never under 760), centred; the pattern covers the page',
      () {
        for (final key in _standing) {
          final wide = SkylinePainter.boxFor(const Size(1000, 800), scene: key);
          expect((wide.left, wide.width, wide.bottom), (0.0, 1000.0, 800.0), reason: key);
          expect(wide.height, closeTo(1000 * 340 / 1600, 1e-9), reason: key);
          final phone = SkylinePainter.boxFor(const Size(390, 800), scene: key);
          expect(phone.width, 760, reason: key);
          expect(phone.left, closeTo(-185, 1e-9), reason: key);
          expect(phone.bottom, 800, reason: key);
        }
        expect(SkylinePainter.boxFor(const Size(390, 800)), SkylinePainter.boxFor(const Size(390, 800), scene: 'doha'));
        expect(SkylinePainter.boxFor(const Size(390, 800), scene: _tile), const Rect.fromLTWH(0, 0, 390, 800));
        expect(
          SkylinePainter.boxFor(const Size(1000, 800), scene: 'atlantis'),
          SkylinePainter.boxFor(const Size(1000, 800)),
        );
      },
    );

    test('the pattern needs ceil(width / 168) by ceil(height / 168) cells', () {
      expect(SkylinePainter.tileSize, 168);
      expect(SkylinePainter.tilesFor(const Size(168, 168)), (1, 1));
      expect(SkylinePainter.tilesFor(const Size(169, 167)), (2, 1));
      expect(SkylinePainter.tilesFor(const Size(390, 844)), (3, 6));
      expect(SkylinePainter.tilesFor(const Size(1280, 800)), (8, 5));
      expect(SkylinePainter.tilesFor(const Size(2732, 2048)), (17, 13));
    });

    test('repaints only when the colour, the opacity or the scene change', () {
      const p = SkylinePainter(tint: _burgundy, opacity: 0.08, scene: 'dunes');
      expect(p.shouldRepaint(const SkylinePainter(tint: _burgundy, opacity: 0.08, scene: 'dunes')), isFalse);
      expect(p.shouldRepaint(const SkylinePainter(tint: Color(0xFF112233), opacity: 0.08, scene: 'dunes')), isTrue);
      expect(p.shouldRepaint(const SkylinePainter(tint: _burgundy, opacity: 0.2, scene: 'dunes')), isTrue);
      expect(p.shouldRepaint(const SkylinePainter(tint: _burgundy, opacity: 0.08, scene: 'souq')), isTrue);
      expect(p.shouldRepaint(const SkylinePainter(tint: _burgundy, opacity: 0.08)), isTrue);
      expect(const SkylinePainter(tint: _burgundy, opacity: 0.08).scene, 'doha');
    });

    test('a canvas with no area paints nothing and raises nothing', () {
      for (final key in PageBackdropConfig.scenes) {
        for (final size in const [Size.zero, Size(0, 300), Size(300, 0)]) {
          final canvas = _CountingCanvas();
          SkylinePainter(tint: _burgundy, opacity: 0.5, scene: key).paint(canvas, size);
          expect((canvas.paths, canvas.clips, canvas.saves), (0, 0, 0), reason: '$key $size');
        }
      }
    });

    for (final key in _standing.where((k) => k != 'doha')) {
      test('$key: pixels inside the artwork are tinted at the opacity, pixels outside are not', () async {
        const size = Size(1600, 400); // the drawing at its own size, 60 px from the top
        final raster = await _render(SkylinePainter(tint: _burgundy, opacity: 0.5, scene: key), size);
        for (final (x, y) in _inside[key]!) {
          final (px, py) = _landing(size, x, y);
          expect(raster.alpha(px, py), inInclusiveRange(127, 128), reason: '$key inside ($x, $y)');
          final rgba = raster.at(px, py);
          expect(rgba[0], closeTo(0x8A, 2), reason: 'the tint, red');
          expect(rgba[1], closeTo(0x15, 2), reason: 'the tint, green');
          expect(rgba[2], closeTo(0x38, 2), reason: 'the tint, blue');
        }
        for (final (x, y) in _outside[key]!) {
          final (px, py) = _landing(size, x, y);
          expect(raster.alpha(px, py), 0, reason: '$key outside ($x, $y)');
        }
        expect(raster.alpha(800, 10), 0, reason: 'above the drawing box nothing is painted');
        expect(raster.alpha(5, 55), 0);
      });

      test('$key: scaled to a tablet and cropped to a phone, the same spots are tinted', () async {
        for (final size in const [Size(800, 600), Size(390, 800)]) {
          final raster = await _render(SkylinePainter(tint: _burgundy, opacity: 0.5, scene: key), size);
          final (x, y) = _insideMiddle[key]!;
          final (px, py) = _landing(size, x, y);
          expect(px, inInclusiveRange(0, size.width - 1), reason: '$key $size is visible');
          expect(raster.alpha(px, py), inInclusiveRange(127, 128), reason: '$key $size inside ($x, $y)');
          final (ox, oy) = _landing(size, x, 12);
          expect(raster.alpha(ox, oy), 0, reason: '$key $size above the drawing');
          expect(raster.alpha(ox, 2), 0, reason: '$key $size top of the page');
        }
      });
    }

    test('doha: still drawn from its own outline, on the ground strip and nowhere above the skyline', () async {
      const size = Size(1600, 400);
      final raster = await _render(const SkylinePainter(tint: _burgundy, opacity: 0.5), size);
      final (gx, gy) = _landing(size, 800, 335);
      expect(buildQatarSkylinePath().contains(const Offset(800, 335)), isTrue);
      expect(raster.alpha(gx, gy), inInclusiveRange(127, 128));
      expect(raster.alpha(800, 20), 0);
      final explicit = await _render(const SkylinePainter(tint: _burgundy, opacity: 0.5, scene: 'doha'), size);
      expect(explicit.block(0, 0, 1600, 400), raster.block(0, 0, 1600, 400), reason: 'doha is the default scene');
      final unknown = await _render(const SkylinePainter(tint: _burgundy, opacity: 0.5, scene: 'atlantis'), size);
      expect(unknown.block(0, 0, 1600, 400), raster.block(0, 0, 1600, 400), reason: 'an unknown scene is doha');
    });

    for (final key in PageBackdropConfig.scenes.where((k) => k != _tile)) {
      test('$key: a grid of pixels agrees with the outline itself', () async {
        const size = Size(1600, 340);
        final raster = await _render(SkylinePainter(tint: _burgundy, opacity: 0.5, scene: key), size);
        final path = BackdropArt.of(key).path;
        var inside = 0;
        var outside = 0;
        var disagree = 0;
        for (var y = 3; y < 337; y += 11) {
          for (var x = 3; x < 1597; x += 13) {
            // Only where the point and its four neighbours two pixels away agree: clear of every edge.
            final spots = [(x, y), (x - 2, y), (x + 2, y), (x, y - 2), (x, y + 2)];
            final hits = [for (final (sx, sy) in spots) path.contains(Offset(sx + 0.5, sy + 0.5))];
            if (hits.any((h) => h != hits.first)) continue;
            hits.first ? inside++ : outside++;
            // Half of the full alpha (128) is "painted". The drawings hold hairline seams (wound the other way) that
            // thin a pixel on them, so a rare pixel on one is not counted against the picture.
            if ((raster.alpha(x, y) > 64) != hits.first) disagree++;
          }
        }
        expect(inside, greaterThan(200), reason: '$key has plenty of artwork in the sample');
        expect(outside, greaterThan(200), reason: '$key has plenty of sky in the sample');
        expect(disagree / (inside + outside), lessThan(0.005), reason: '$key: $disagree of ${inside + outside}');
      });
    }

    test('nothing is painted outside the page, even where the artwork overshoots its drawing box', () async {
      // Dunes dip 5 units under the bottom, souq pokes 4 units past the right edge: the image is bigger than the canvas.
      for (final key in ['dunes', 'souq', 'dhow']) {
        const canvas = Size(1600, 340);
        final raster = await _render(
          SkylinePainter(tint: _burgundy, opacity: 0.5, scene: key),
          canvas,
          imageSize: const Size(1620, 360),
        );
        for (var x = 0; x < 1620; x += 7) {
          expect(raster.alpha(x, 345), 0, reason: '$key under the page at x=$x');
          expect(raster.alpha(x, 359), 0, reason: '$key under the page at x=$x');
        }
        for (var y = 0; y < 360; y++) {
          expect(raster.alpha(1605, y), 0, reason: '$key right of the page at y=$y');
          expect(raster.alpha(1619, y), 0, reason: '$key right of the page at y=$y');
        }
      }
    });
  });

  group('the pattern (arabesque)', () {
    const painter = SkylinePainter(tint: _burgundy, opacity: 0.5, scene: _tile);
    // 3 whole cells across and 2 down, then a strip of 16 px and one of 64 px that the page cuts off.
    const size = Size(520, 400);

    test('repeats every 168 px, across and down, cell for cell', () async {
      final raster = await _render(painter, size);
      final first = raster.block(0, 0, 168, 168);
      var tinted = 0;
      var clear = 0;
      for (var y = 0; y < 168; y++) {
        for (var x = 0; x < 168; x++) {
          raster.alpha(x, y) > 0 ? tinted++ : clear++;
        }
      }
      expect(tinted, greaterThan(168 * 168 * 0.1), reason: 'the pattern has real weight');
      expect(clear, greaterThan(168 * 168 * 0.1), reason: 'and real gaps: it is not a flat wash');
      for (final (column, row) in const [(1, 0), (2, 0), (0, 1), (1, 1), (2, 1)]) {
        expect(
          _differences(raster.block(column * 168, row * 168, 168, 168), first),
          0,
          reason: 'cell ($column, $row) is the same drawing as cell (0, 0)',
        );
      }
    });

    test('starts at the top-left corner of the page, and the cells the page cuts are cut, not squeezed', () async {
      final raster = await _render(painter, size);
      expect(
        raster.alpha(1, 1),
        inInclusiveRange(127, 128),
        reason: 'the centre of a corner ornament sits on the origin',
      );
      // Right strip: the first 16 px of a cell. Bottom strip: the first 64 rows of a cell.
      expect(_differences(raster.block(504, 0, 16, 168), raster.block(0, 0, 16, 168), tolerance: _edgeNoise), 0);
      expect(_differences(raster.block(0, 336, 168, 64), raster.block(0, 0, 168, 64), tolerance: _edgeNoise), 0);
      expect(_differences(raster.block(504, 336, 16, 64), raster.block(0, 0, 16, 64), tolerance: _edgeNoise), 0);
    });

    test('a point near the edge of a cell continues in the next one, with no seam and no double painting', () async {
      final raster = await _render(painter, size);
      // The ornaments on a cell edge are one shape cut in two: half in this cell, half in the next.
      for (final y in [1, 2]) {
        for (final x in [164, 166, 167, 168, 169, 171]) {
          expect(raster.alpha(x, y), inInclusiveRange(127, 128), reason: 'across the corner at ($x, $y)');
        }
      }
      for (final y in [83, 84, 85]) {
        for (final x in [160, 164, 166, 167, 168, 169, 171, 175]) {
          expect(raster.alpha(x, y), inInclusiveRange(127, 128), reason: 'across the edge at ($x, $y)');
        }
      }
      for (final x in [82, 84, 86]) {
        for (final y in [166, 167, 168, 169]) {
          expect(raster.alpha(x, y), inInclusiveRange(127, 128), reason: 'across the bottom edge at ($x, $y)');
        }
      }
      // The same joins one cell further on.
      for (final x in [334, 335, 336, 337]) {
        expect(raster.alpha(x, 84), inInclusiveRange(127, 128), reason: 'across the second edge at x=$x');
      }
    });

    test('every pixel of a grid agrees with the outline of one cell, whichever cell it is in', () async {
      const area = Size(504, 336);
      final raster = await _render(painter, area);
      final path = BackdropArt.of(_tile).path;
      const scale = 168 / 200;
      Offset local(int x, int y) => Offset(((x % 168) + 0.5) / scale, ((y % 168) + 0.5) / scale);
      var inside = 0;
      var outside = 0;
      for (var y = 2; y < 334; y += 7) {
        for (var x = 2; x < 502; x += 7) {
          final spots = [(x, y), (x - 2, y), (x + 2, y), (x, y - 2), (x, y + 2)];
          final hits = [for (final (sx, sy) in spots) path.contains(local(sx, sy))];
          if (hits.any((h) => h != hits.first)) continue;
          expect(raster.alpha(x, y) > 100, hits.first, reason: 'pattern ($x, $y)');
          hits.first ? inside++ : outside++;
        }
      }
      expect(inside, greaterThan(100));
      expect(outside, greaterThan(500));
    });

    test('draws each cell as one clipped path: one path per cell, one clip per cell and one for the page', () async {
      for (final (area, columns, rows) in const [
        (Size(168, 168), 1, 1),
        (Size(390, 844), 3, 6),
        (Size(1280, 800), 8, 5),
        (Size(2732, 2048), 17, 13),
      ]) {
        final canvas = _CountingCanvas();
        painter.paint(canvas, area);
        expect(canvas.paths, columns * rows, reason: '$area');
        expect(canvas.clips, columns * rows + 1, reason: '$area: every cell and the page');
        expect(canvas.saves, columns * rows + 1, reason: '$area');
        expect(canvas.saves, canvas.restores, reason: '$area: the canvas is left as it was found');
      }
    });

    test('a bottom-standing scene is still exactly one path, clipped once, whatever the scene', () {
      for (final key in _standing) {
        final canvas = _CountingCanvas();
        SkylinePainter(tint: _burgundy, opacity: 0.2, scene: key).paint(canvas, const Size(1280, 800));
        expect((canvas.paths, canvas.clips, canvas.saves), (1, 1, 1), reason: key);
        expect(canvas.saves, canvas.restores, reason: key);
      }
    });
  });

  group('PageBackdropLayer with a scene', () {
    Finder painters() => find.byWidgetPredicate((w) => w is CustomPaint && w.painter is SkylinePainter);

    SkylinePainter painterOf(WidgetTester tester) => tester.widget<CustomPaint>(painters()).painter! as SkylinePainter;

    for (final key in PageBackdropConfig.scenes) {
      for (final dark in [false, true]) {
        for (final locale in [_en, _ar]) {
          testWidgets('$key, ${dark ? 'dark' : 'light'}, ${locale.languageCode}: draws it in the theme colour', (
            tester,
          ) async {
            await tester.pumpWidget(
              _host(
                SizedBox(
                  width: 400,
                  height: 300,
                  child: PageBackdropLayer(config: PageBackdropConfig(scene: key, opacity: 0.2)),
                ),
                AppTheme.light(locale),
                dark: AppTheme.dark(locale),
                darkMode: dark,
                locale: locale,
              ),
            );
            expect(tester.takeException(), isNull);
            expect(painters(), findsOneWidget);
            final painter = painterOf(tester);
            expect(painter.scene, key);
            expect(painter.opacity, 0.2);
            expect(painter.tint, dark ? AppPalette.nightNavy : AppPalette.navy);
            // Quiet by construction: no touch, no screen reader, painted on its own layer.
            expect(find.ancestor(of: painters(), matching: find.byType(IgnorePointer)), findsWidgets);
            expect(find.ancestor(of: painters(), matching: find.byType(ExcludeSemantics)), findsWidgets);
            expect(find.ancestor(of: painters(), matching: find.byType(RepaintBoundary)), findsWidgets);
          });
        }
      }
    }

    testWidgets('an explicit tint wins over the theme colour for every scene', (tester) async {
      for (final key in PageBackdropConfig.scenes) {
        await tester.pumpWidget(
          _host(
            SizedBox(
              width: 400,
              height: 300,
              child: PageBackdropLayer(
                config: PageBackdropConfig(scene: key, tint: const Color(0xFF112233)),
              ),
            ),
            AppTheme.light(_en),
          ),
        );
        expect(painterOf(tester).tint, const Color(0xFF112233), reason: key);
        expect(painterOf(tester).scene, key);
      }
    });

    testWidgets('changing the scene in the dashboard changes the picture on the next build', (tester) async {
      Future<void> show(String scene) => tester.pumpWidget(
        _host(
          Align(
            alignment: Alignment.topLeft,
            child: SizedBox(
              width: 520,
              height: 400,
              child: PageBackdropLayer(config: PageBackdropConfig(scene: scene)),
            ),
          ),
          AppTheme.light(_en),
        ),
      );
      await show('doha');
      expect(tester.renderObject(painters()), paintsExactlyCountTimes(#drawPath, 1));
      await show(_tile);
      expect(painterOf(tester).scene, _tile);
      expect(
        tester.renderObject(painters()),
        paintsExactlyCountTimes(#drawPath, 4 * 3),
        reason: '520 x 400 is 4 by 3 cells',
      );
      await show('dunes');
      expect(painterOf(tester).scene, 'dunes');
      expect(tester.renderObject(painters()), paintsExactlyCountTimes(#drawPath, 1));
    });

    testWidgets('a big tablet is 17 by 13 cells and still one paint of the page', (tester) async {
      tester.view
        ..physicalSize = const Size(2732, 2048)
        ..devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        _host(
          const SizedBox.expand(
            child: PageBackdropLayer(config: PageBackdropConfig(scene: _tile)),
          ),
          AppTheme.light(_en),
        ),
      );
      expect(tester.takeException(), isNull);
      expect(tester.renderObject(painters()), paintsExactlyCountTimes(#drawPath, 17 * 13));
    });

    testWidgets('no picture, colour only, or an uploaded picture: no scene is drawn, whatever the scene says', (
      tester,
    ) async {
      final fake = await fakePictures(tester);
      for (final config in const [
        PageBackdropConfig(style: BackdropStyle.none, scene: 'dunes'),
        PageBackdropConfig(style: BackdropStyle.none, scene: _tile, color: Color(0xFFFFF3E0)),
        PageBackdropConfig(style: BackdropStyle.image, scene: 'souq', imageUrl: 'https://cdn.test/a.jpg'),
      ]) {
        await tester.pumpWidget(
          _host(
            Directionality(
              textDirection: TextDirection.ltr,
              child: SizedBox(
                width: 400,
                height: 300,
                child: PageBackdropLayer(config: config, imageBuilder: fake),
              ),
            ),
            AppTheme.light(_en),
          ),
        );
        await tester.pump();
        expect(painters(), findsNothing, reason: '$config');
        expect(tester.takeException(), isNull, reason: '$config');
      }
    });

    testWidgets('PageBackdrop with "none" adds no layer at all, the pattern or not', (tester) async {
      await tester.pumpWidget(
        _host(
          const PageBackdrop(
            config: PageBackdropConfig(style: BackdropStyle.none, scene: _tile),
            child: Text('page'),
          ),
          AppTheme.light(_en),
        ),
      );
      expect(find.byType(PageBackdropLayer), findsNothing);
      expect(painters(), findsNothing);
      expect(find.text('page'), findsOneWidget);
    });

    testWidgets('a new scene tells the pages below to rebuild', (tester) async {
      const child = SizedBox();
      expect(
        const PageBackdropScope(
          config: PageBackdropConfig(scene: 'souq'),
          imageBuilder: defaultBackdropImage,
          child: child,
        ).updateShouldNotify(
          const PageBackdropScope(
            config: PageBackdropConfig(scene: 'dunes'),
            imageBuilder: defaultBackdropImage,
            child: child,
          ),
        ),
        isTrue,
      );
      expect(
        const PageBackdropScope(
          config: PageBackdropConfig(scene: 'souq'),
          imageBuilder: defaultBackdropImage,
          child: child,
        ).updateShouldNotify(
          const PageBackdropScope(
            config: PageBackdropConfig(scene: 'souq'),
            imageBuilder: defaultBackdropImage,
            child: child,
          ),
        ),
        isFalse,
      );
    });

    for (final key in PageBackdropConfig.scenes) {
      testWidgets('$key: right to left is not mirrored, and dark mode is the dark colour on the dark page', (
        tester,
      ) async {
        Future<List<int>> shot(Locale locale, {required bool dark}) async {
          final boundary = GlobalKey();
          await tester.pumpWidget(
            _host(
              Align(
                alignment: Alignment.topLeft,
                child: RepaintBoundary(
                  key: boundary,
                  child: SizedBox(
                    width: 520,
                    height: 400,
                    child: PageBackdrop(
                      config: PageBackdropConfig(scene: key, opacity: 0.5),
                      child: const SizedBox.expand(),
                    ),
                  ),
                ),
              ),
              AppTheme.light(locale),
              dark: AppTheme.dark(locale),
              darkMode: dark,
              locale: locale,
            ),
          );
          await tester.pumpAndSettle(); // the theme eases from light to dark: wait for the last frame
          final bytes = await tester.runAsync(() async {
            final image = await tester.renderObject<RenderRepaintBoundary>(find.byKey(boundary)).toImage();
            return image.toByteData();
          });
          return [for (var i = 0; i < bytes!.lengthInBytes; i++) bytes.getUint8(i)];
        }

        // Counts the pixels that are the page colour, the tint at half opacity over it, or something else (the soft edges).
        (int, int, int) count(List<int> bytes, Color page, Color art) {
          int channel(double v) => (v * 255).round();
          bool near(int a, int b) => (a - b).abs() <= 2;
          final mixed = Color.lerp(page, art, 0.5)!;
          var onPage = 0;
          var onArt = 0;
          var other = 0;
          for (var i = 0; i < bytes.length; i += 4) {
            if (near(bytes[i], channel(page.r)) &&
                near(bytes[i + 1], channel(page.g)) &&
                near(bytes[i + 2], channel(page.b))) {
              onPage++;
            } else if (near(bytes[i], channel(mixed.r)) &&
                near(bytes[i + 1], channel(mixed.g)) &&
                near(bytes[i + 2], channel(mixed.b))) {
              onArt++;
            } else {
              other++;
            }
          }
          return (onPage, onArt, other);
        }

        final ltr = await shot(_en, dark: false);
        final rtl = await shot(_ar, dark: false);
        expect(rtl, ltr, reason: '$key looks the same in Arabic: the artwork is not mirrored');
        final (lightPage, lightArt, lightOther) = count(ltr, AppPalette.ivory, AppPalette.navy);
        expect(lightPage, greaterThan(1000), reason: '$key: the page colour');
        expect(lightArt, greaterThan(500), reason: '$key: the tint at half opacity over it');
        expect(lightOther, lessThan(lightArt), reason: '$key: only soft edges are anything else');

        final night = await shot(_ar, dark: true);
        final (darkPage, darkArt, darkOther) = count(night, AppPalette.nightBg, AppPalette.nightNavy);
        expect(darkPage, greaterThan(1000), reason: '$key: the dark page colour');
        expect(darkArt, greaterThan(500), reason: '$key: the dark tint at half opacity over it');
        expect(darkOther, lessThan(darkArt), reason: '$key: nothing of the light colours is left in dark mode');
      });
    }
  });
}

/// A canvas that only counts what the painter asks of it.
class _CountingCanvas implements Canvas {
  int paths = 0;
  int clips = 0;
  int saves = 0;
  int restores = 0;

  @override
  void drawPath(Path path, Paint paint) => paths++;

  @override
  void clipRect(Rect rect, {ui.ClipOp clipOp = ui.ClipOp.intersect, bool doAntiAlias = true}) => clips++;

  @override
  void save() => saves++;

  @override
  void restore() => restores++;

  @override
  void translate(double dx, double dy) {}

  @override
  void scale(double sx, [double? sy]) {}

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnsupportedError('${invocation.memberName} is not expected');
}
