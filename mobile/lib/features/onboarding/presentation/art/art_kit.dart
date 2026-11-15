import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';

/// The colours the intro artwork is painted with. Qatar maroon and a warm sunset gold belong to the artwork itself; every
/// neutral (sky, paper, ink) comes from the live theme so the pictures sit in light, dark and a dashboard re-skin alike.
@immutable
class ArtPalette {
  const ArtPalette({required this.dark, required this.background, required this.surface, required this.ink});

  factory ArtPalette.of(BuildContext context) => ArtPalette(
    dark: context.theme.brightness == Brightness.dark,
    background: context.theme.scaffoldBackgroundColor,
    surface: context.colors.surface,
    ink: context.palette.ink,
  );

  /// Qatar maroon (Pantone 1955 C), the colour of the flag.
  static const maroon = Color(0xFF8A1538);
  static const maroonDeep = Color(0xFF4E0D22);
  static const gold = Color(0xFFE2B15C);
  static const goldLight = Color(0xFFF7DDA2);
  static const goldDeep = Color(0xFF9A6522);

  final bool dark;
  final Color background;
  final Color surface;
  final Color ink;

  /// Blend of two colours.
  static Color mix(Color a, Color b, double t) => Color.lerp(a, b, t)!;

  /// The page colour the pictures are matted on: cream by day, a deep wine by night.
  Color get paper => dark ? const Color(0xFF2A1A21) : surface;

  /// Dusk sky: dusty rose overhead, apricot, then the glow at the horizon (an ember glow at night).
  Color get skyTop => dark ? const Color(0xFF1C1020) : mix(maroon, background, 0.58);
  Color get skyMid => dark ? const Color(0xFF45172F) : const Color(0xFFE8AFA6);
  Color get skyGlow => dark ? const Color(0xFFA94A3C) : const Color(0xFFF8D9A0);

  /// Layers of the city, from the haze at the back to the darkest silhouette in front.
  Color get farTone => dark ? const Color(0xFF3A1A2D) : mix(const Color(0xFFD99A9E), maroon, 0.34);
  Color get midTone => dark ? const Color(0xFF2B1322) : mix(maroon, background, 0.16);
  Color get nearTone => dark ? const Color(0xFF150910) : mix(maroonDeep, ink, 0.35);

  /// Water, from the horizon down.
  Color get seaTop => dark ? const Color(0xFF5B2336) : const Color(0xFFF1BFA0);
  Color get seaBottom => dark ? const Color(0xFF1A0C16) : mix(maroon, background, 0.42);

  /// Window light.
  Color get lamp => dark ? goldLight : mix(goldLight, background, 0.35);

  @override
  bool operator ==(Object other) =>
      other is ArtPalette &&
      other.dark == dark &&
      other.background == background &&
      other.surface == surface &&
      other.ink == ink;

  @override
  int get hashCode => Object.hash(dark, background, surface, ink);
}

/// Maps the picture's own 400 x 440 drawing grid onto whatever box the page offers, sitting on the bottom edge and centred.
/// A wider box shows more of the sky and the sea at the sides; a narrower one shows more sky above. [left], [right] and [top]
/// are the ends of the whole layer canvas on that grid, so a background can run to the edge of the canvas.
class SceneFrame {
  SceneFrame({required this.window, required this.pad, required this.layerWidth})
    : scale = math.min(window.width / gridWidth, window.height / gridHeight),
      ox = pad + (window.width - gridWidth * math.min(window.width / gridWidth, window.height / gridHeight)) / 2,
      oy = window.height - gridHeight * math.min(window.width / gridWidth, window.height / gridHeight);

  static const gridWidth = 400.0;
  static const gridHeight = 440.0;

  /// The visible box and the extra width drawn on each side of it, for layers that move.
  final Size window;
  final double pad;
  final double layerWidth;
  final double scale;
  final double ox;
  final double oy;

  double get left => -ox / scale;
  double get right => (layerWidth - ox) / scale;
  double get top => -oy / scale;
  double get bottom => gridHeight;

  /// Rect of the whole layer canvas on the drawing grid.
  Rect get canvas => Rect.fromLTRB(left, top, right, bottom);

  void apply(ui.Canvas c) => c
    ..translate(ox, oy)
    ..scale(scale);
}

typedef ArtPaint = void Function(Canvas canvas, SceneFrame frame, ArtPalette palette);

/// One sheet of a scene. [parallax] is how much of the page's own sideways movement it cancels while swiping (0 moves with
/// the page, larger values lag behind it, so the sheets slide against each other and give the picture depth).
@immutable
class ArtLayer {
  const ArtLayer(this.parallax, this.paint);
  final double parallax;
  final ArtPaint paint;
}

/// The upper half of a pointed arch (the shape of a mihrab and of the windows of old Doha houses): from the left spring point
/// over the apex to the right spring point.
Path archTop(Rect r) {
  final spring = math.min(r.height * 0.5, r.width * 0.66);
  return Path()
    ..moveTo(r.left, r.top + spring)
    ..cubicTo(r.left, r.top + spring * 0.40, r.left + r.width * 0.20, r.top + spring * 0.04, r.center.dx, r.top)
    ..cubicTo(r.right - r.width * 0.20, r.top + spring * 0.04, r.right, r.top + spring * 0.40, r.right, r.top + spring);
}

/// The whole window: the arch on top, straight sides, rounded corners at the bottom.
Path archPath(Rect r, {double bottomRadius = AppRadius.lg}) => archTop(r)
  ..lineTo(r.right, r.bottom - bottomRadius)
  ..arcToPoint(Offset(r.right - bottomRadius, r.bottom), radius: Radius.circular(bottomRadius))
  ..lineTo(r.left + bottomRadius, r.bottom)
  ..arcToPoint(Offset(r.left, r.bottom - bottomRadius), radius: Radius.circular(bottomRadius))
  ..close();

class ArchClipper extends CustomClipper<Path> {
  const ArchClipper();

  @override
  Path getClip(Size size) => archPath(Offset.zero & size);

  @override
  bool shouldReclip(covariant CustomClipper<Path> oldClipper) => false;
}

/// The nine-pointed serrated edge of the Qatar flag: a maroon band whose upper edge has nine cream points reaching into it.
void paintFlagEdge(Canvas canvas, Size size, ArtPalette p) {
  const teeth = 9;
  final band = math.min(22.0, size.height * 0.075);
  final top = size.height - band;
  final toothWidth = size.width / teeth;
  final toothHeight = math.min(band * 0.86, toothWidth * 0.62);
  canvas.drawRect(
    Rect.fromLTWH(0, top, size.width, toothHeight),
    Paint()..color = p.dark ? const Color(0xFFEFE3D6) : p.surface,
  );
  final path = Path()..moveTo(0, top);
  for (var i = 0; i < teeth; i++) {
    path
      ..lineTo(i * toothWidth, top)
      ..lineTo(i * toothWidth + toothWidth / 2, top + toothHeight)
      ..lineTo((i + 1) * toothWidth, top);
  }
  path
    ..lineTo(size.width, size.height)
    ..lineTo(0, size.height)
    ..close();
  canvas.drawPath(path, Paint()..color = ArtPalette.maroon);
}

/// A small, fixed sequence of "random" numbers so every paint of a picture is identical.
math.Random seeded(int seed) => math.Random(seed);

Paint fillPaint(Color c) => Paint()..color = c;

Paint strokePaint(Color c, double width, {StrokeCap cap = StrokeCap.round}) => Paint()
  ..color = c
  ..style = PaintingStyle.stroke
  ..strokeWidth = width
  ..strokeCap = cap
  ..strokeJoin = StrokeJoin.round;

Path polygon(List<Offset> points) {
  final path = Path()..moveTo(points.first.dx, points.first.dy);
  for (final p in points.skip(1)) {
    path.lineTo(p.dx, p.dy);
  }
  return path..close();
}

/// A vertical gradient between [y0] and [y1] on the drawing grid.
ui.Shader vertical(double y0, double y1, List<Color> colors, [List<double>? stops]) =>
    ui.Gradient.linear(Offset(0, y0), Offset(0, y1), colors, stops);
