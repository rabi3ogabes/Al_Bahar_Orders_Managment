import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:albahar_customer/features/onboarding/presentation/art/art_kit.dart';
import 'package:flutter/material.dart';

/// "From our branches to your door": the Qatar peninsula in flag maroon on a cream Gulf, branch pins, a delivery route to a
/// home, and two small cards for the delivery slot and the pick-up. North is up and the shape is never mirrored, in either
/// reading direction. Places are placed by longitude and latitude, so the map is true to the real coast.
abstract final class QatarMapScene {
  static const layers = <ArtLayer>[
    ArtLayer(0.02, _sea),
    ArtLayer(0.05, _mainland),
    ArtLayer(0.09, _peninsula),
    ArtLayer(0.15, _route),
    ArtLayer(0.30, _cards),
  ];

  /// Longitude / latitude to the picture's grid: 188 units per degree of latitude, centred on 51.2 E.
  static Offset _at(double lon, double lat) => Offset(200 + (lon - 51.2) * 169.9, 38 + (26.2 - lat) * 188);

  // The southern land border with Saudi Arabia, west to east.
  static final _border = [_at(50.82, 24.72), _at(51.02, 24.47), _at(51.28, 24.58), _at(51.60, 24.62)];

  // The coast, from the south-east corner round the north tip to the south-west corner: the Khor Al Adaid inlet, Mesaieed,
  // Wakrah, Doha's bay, Lusail, Al Khor, Ras Laffan, Ruwais, Ras Rakan, Zubarah, Zekreet and Dukhan.
  static final _coast = [
    _at(51.60, 24.62),
    _at(51.61, 24.70),
    _at(51.50, 24.69),
    _at(51.46, 24.75),
    _at(51.52, 24.81),
    _at(51.60, 24.92),
    _at(51.63, 25.06),
    _at(51.61, 25.17),
    _at(51.57, 25.23),
    _at(51.60, 25.28),
    _at(51.54, 25.31),
    _at(51.52, 25.37),
    _at(51.52, 25.45),
    _at(51.48, 25.53),
    _at(51.51, 25.62),
    _at(51.52, 25.72),
    _at(51.57, 25.82),
    _at(51.62, 25.91),
    _at(51.52, 26.00),
    _at(51.38, 26.07),
    _at(51.26, 26.13),
    _at(51.21, 26.17),
    _at(51.14, 26.12),
    _at(51.03, 26.03),
    _at(50.95, 25.97),
    _at(50.87, 25.85),
    _at(50.80, 25.65),
    _at(50.77, 25.50),
    _at(50.79, 25.38),
    _at(50.83, 25.26),
    _at(50.80, 25.08),
    _at(50.82, 24.88),
    _at(50.82, 24.72),
  ];

  // The Saudi coast of the Gulf of Salwa, heading north-west from the border.
  static final _saudiCoast = [
    _at(50.82, 24.72),
    _at(50.62, 24.95),
    _at(50.42, 25.25),
    _at(50.26, 25.62),
    _at(50.22, 26.0),
  ];

  static Path _spline(List<Offset> pts, Path into) {
    for (var i = 0; i < pts.length - 1; i++) {
      final p0 = pts[math.max(i - 1, 0)];
      final p1 = pts[i];
      final p2 = pts[i + 1];
      final p3 = pts[math.min(i + 2, pts.length - 1)];
      into.cubicTo(
        p1.dx + (p2.dx - p0.dx) / 6,
        p1.dy + (p2.dy - p0.dy) / 6,
        p2.dx - (p3.dx - p1.dx) / 6,
        p2.dy - (p3.dy - p1.dy) / 6,
        p2.dx,
        p2.dy,
      );
    }
    return into;
  }

  static Path _qatar() {
    final path = Path()..moveTo(_coast.first.dx, _coast.first.dy);
    _spline(_coast, path);
    return path
      ..lineTo(_border[1].dx, _border[1].dy)
      ..lineTo(_border[2].dx, _border[2].dy)
      ..close();
  }

  static Color _land(ArtPalette p) =>
      p.dark ? const Color(0xFF3B2431) : ArtPalette.mix(ArtPalette.maroon, p.background, 0.86);

  static void _sea(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final top = p.dark ? const Color(0xFF22151D) : const Color(0xFFF9F1EA);
    final bottom = p.dark ? const Color(0xFF2B1A25) : const Color(0xFFF0E1D8);
    canvas.drawRect(f.canvas, Paint()..shader = vertical(f.top, f.bottom, [top, bottom]));

    // Dotted lines of latitude and longitude, and a few waves.
    final grid = fillPaint(ArtPalette.maroon.withValues(alpha: p.dark ? 0.35 : 0.16));
    for (var lat = 24.5; lat < 26.3; lat += 0.5) {
      final y = _at(51, lat).dy;
      for (var x = f.left; x < f.right; x += 9) {
        canvas.drawCircle(Offset(x, y), 0.9, grid);
      }
    }
    for (var lon = 50.0; lon < 52.2; lon += 0.5) {
      final x = _at(lon, 25).dx;
      for (var y = f.top; y < f.bottom; y += 9) {
        canvas.drawCircle(Offset(x, y), 0.9, grid);
      }
    }
    final wave = strokePaint(ArtPalette.maroon.withValues(alpha: p.dark ? 0.3 : 0.14), 1.2);
    final random = seeded(5);
    for (var i = 0; i < 18; i++) {
      final x = f.left + random.nextDouble() * (f.right - f.left);
      final y = 20 + random.nextDouble() * 360;
      canvas.drawPath(
        Path()
          ..moveTo(x, y)
          ..quadraticBezierTo(x + 5, y - 4, x + 10, y)
          ..quadraticBezierTo(x + 15, y + 4, x + 20, y),
        wave,
      );
    }
    canvas.restore();
  }

  /// Saudi Arabia to the south and west, the Emirates to the south-east, and Bahrain.
  static void _mainland(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final land = fillPaint(_land(p));
    final mainland = Path()
      ..moveTo(f.left, _saudiCoast.last.dy)
      ..lineTo(f.left, f.bottom)
      ..lineTo(f.right, f.bottom)
      ..lineTo(f.right, _border.last.dy + 44)
      ..quadraticBezierTo(330, _border.last.dy + 12, _border.last.dx, _border.last.dy)
      ..lineTo(_border[2].dx, _border[2].dy)
      ..lineTo(_border[1].dx, _border[1].dy)
      ..lineTo(_border[0].dx, _border[0].dy);
    _spline(_saudiCoast, mainland);
    mainland.close();
    canvas.drawPath(mainland, land);
    final shore = Path()..moveTo(_saudiCoast.first.dx, _saudiCoast.first.dy);
    canvas.drawPath(
      _spline(_saudiCoast, shore),
      strokePaint(ArtPalette.maroon.withValues(alpha: p.dark ? 0.55 : 0.35), 1.2),
    );

    // Bahrain.
    final isle = _at(50.58, 26.1);
    final bahrain = Path()..addOval(Rect.fromCenter(center: isle, width: 20, height: 40));
    canvas
      ..drawPath(bahrain, land)
      ..drawPath(bahrain, strokePaint(ArtPalette.maroon.withValues(alpha: p.dark ? 0.55 : 0.35), 1.2));

    // The land border, dashed.
    final border = Path()..moveTo(_border[0].dx, _border[0].dy);
    for (final b in _border.skip(1)) {
      border.lineTo(b.dx, b.dy);
    }
    _dash(
      canvas,
      border,
      strokePaint(ArtPalette.gold.withValues(alpha: p.dark ? 0.8 : 0.95), 1.6, cap: StrokeCap.butt),
      5,
      4,
    );
    canvas.restore();
  }

  /// The peninsula: a halo of ripples, the maroon land with a woven texture, and a thin gold shore.
  static void _peninsula(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final qatar = _qatar();
    for (final (width, alpha) in const [(30.0, 0.05), (20.0, 0.08), (11.0, 0.12)]) {
      canvas.drawPath(qatar, strokePaint(ArtPalette.maroon.withValues(alpha: p.dark ? alpha * 2.2 : alpha), width));
    }
    final bounds = qatar.getBounds();
    canvas.drawPath(
      qatar,
      Paint()
        ..shader = ui.Gradient.linear(
          bounds.topLeft,
          bounds.bottomRight,
          [const Color(0xFFA11B45), ArtPalette.maroon, const Color(0xFF6B0F2B)],
          const [0, 0.5, 1],
        ),
    );

    // Sadu-style weave: rows of small triangles, clipped to the land.
    canvas
      ..save()
      ..clipPath(qatar);
    final weave = fillPaint(Colors.white.withValues(alpha: 0.07));
    for (var row = 0; row < 40; row++) {
      final y = bounds.top + row * 9.0;
      for (var x = bounds.left - 8 + (row.isEven ? 0 : 4.5); x < bounds.right + 8; x += 9) {
        canvas.drawPath(polygon([Offset(x, y + 6), Offset(x + 4.5, y), Offset(x + 9, y + 6)]), weave);
      }
    }
    canvas.drawPath(
      qatar,
      Paint()
        ..shader = ui.Gradient.linear(bounds.topCenter, bounds.bottomCenter, [
          Colors.white.withValues(alpha: 0.12),
          Colors.black.withValues(alpha: 0.12),
        ]),
    );
    canvas.restore();
    canvas.drawPath(qatar, strokePaint(ArtPalette.gold.withValues(alpha: 0.95), 1.5));
    canvas.restore();
  }

  /// A dashed delivery route from the Doha branch to a home, with a clock for the slot, and the branch pins.
  static void _route(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final doha = _at(51.53, 25.285);
    final home = _at(51.10, 25.40);
    final route = Path()
      ..moveTo(doha.dx - 2, doha.dy - 3)
      ..cubicTo(doha.dx - 10, doha.dy - 52, home.dx + 36, home.dy - 52, home.dx + 1, home.dy - 3);
    _dash(canvas, route, strokePaint(const Color(0xFFFFF3D6), 2.6), 6.5, 5);

    // The other branches.
    for (final tip in [_at(51.52, 25.69), _at(51.60, 25.12), _at(51.54, 24.97), _at(50.80, 25.40)]) {
      _pin(canvas, tip, 6.5, ArtPalette.goldLight, ArtPalette.maroon, p);
    }
    _pin(canvas, doha, 9, ArtPalette.gold, ArtPalette.maroonDeep, p);
    _homePin(canvas, home, p);

    // The slot clock halfway along the route.
    final metric = route.computeMetrics().first;
    final mid = metric.getTangentForOffset(metric.length * 0.5)!.position;
    canvas
      ..drawCircle(mid.translate(0, 1.5), 11.5, fillPaint(Colors.black.withValues(alpha: 0.18)))
      ..drawCircle(mid, 11.5, fillPaint(Colors.white))
      ..drawCircle(mid, 11.5, strokePaint(ArtPalette.gold, 1.6));
    final hand = strokePaint(ArtPalette.maroon, 1.8);
    canvas
      ..drawLine(mid, mid.translate(0, -7), hand)
      ..drawLine(mid, mid.translate(5, 2.5), hand);
    // A thin line from the clock to the delivery-slot card.
    _dash(
      canvas,
      Path()
        ..moveTo(mid.dx + 12, mid.dy - 4)
        ..lineTo(290, 128),
      strokePaint(ArtPalette.gold.withValues(alpha: 0.9), 1.2, cap: StrokeCap.butt),
      3,
      3,
    );
    canvas.restore();
  }

  static void _pin(Canvas canvas, Offset tip, double r, Color fill, Color inner, ArtPalette p) {
    final center = tip.translate(0, -r * 2.15);
    canvas.drawOval(
      Rect.fromCenter(center: tip.translate(0, 0.5), width: r * 1.6, height: r * 0.55),
      fillPaint(Colors.black.withValues(alpha: 0.25)),
    );
    final path = Path()
      ..moveTo(tip.dx, tip.dy)
      ..lineTo(center.dx - r * 0.9, center.dy + r * 0.45)
      ..arcToPoint(Offset(center.dx + r * 0.9, center.dy + r * 0.45), radius: Radius.circular(r), largeArc: true)
      ..close();
    canvas
      ..drawPath(path, fillPaint(fill))
      ..drawPath(path, strokePaint(Colors.white.withValues(alpha: 0.9), 1.1))
      ..drawCircle(center, r * 0.42, fillPaint(inner));
  }

  static void _homePin(Canvas canvas, Offset tip, ArtPalette p) {
    const r = 12.0;
    final center = tip.translate(0, -r * 2.1);
    canvas.drawOval(
      Rect.fromCenter(center: tip.translate(0, 0.5), width: r * 1.5, height: r * 0.5),
      fillPaint(Colors.black.withValues(alpha: 0.28)),
    );
    final path = Path()
      ..moveTo(tip.dx, tip.dy)
      ..lineTo(center.dx - r * 0.9, center.dy + r * 0.45)
      ..arcToPoint(Offset(center.dx + r * 0.9, center.dy + r * 0.45), radius: const Radius.circular(r), largeArc: true)
      ..close();
    canvas
      ..drawPath(path, fillPaint(Colors.white))
      ..drawPath(path, strokePaint(ArtPalette.gold, 1.8));
    // A little house.
    final house = Path()
      ..moveTo(center.dx - 6.5, center.dy + 0.5)
      ..lineTo(center.dx, center.dy - 6)
      ..lineTo(center.dx + 6.5, center.dy + 0.5)
      ..lineTo(center.dx + 5, center.dy + 0.5)
      ..lineTo(center.dx + 5, center.dy + 6)
      ..lineTo(center.dx - 5, center.dy + 6)
      ..lineTo(center.dx - 5, center.dy + 0.5)
      ..close();
    canvas
      ..drawPath(house, fillPaint(ArtPalette.maroon))
      ..drawRect(Rect.fromLTWH(center.dx - 1.6, center.dy + 2, 3.2, 4), fillPaint(Colors.white));
  }

  /// Floating cards, like the chips of a delivery app: the slot you pick and the branch you can collect from.
  static void _cards(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    _card(canvas, const Rect.fromLTWH(286, 92, 98, 54), p, icon: _Glyph.clock);
    _card(canvas, const Rect.fromLTWH(16, 286, 98, 54), p, icon: _Glyph.bag);
    canvas.restore();
  }

  static void _card(Canvas canvas, Rect r, ArtPalette p, {required _Glyph icon}) {
    final rr = RRect.fromRectAndRadius(r, const Radius.circular(14));
    canvas
      ..drawRRect(
        rr.shift(const Offset(0, 6)),
        Paint()
          ..color = ArtPalette.maroon.withValues(alpha: p.dark ? 0.5 : 0.22)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 7),
      )
      ..drawRRect(rr, fillPaint(p.paper))
      ..drawRRect(rr, strokePaint(ArtPalette.gold.withValues(alpha: 0.7), 1));
    final c = Offset(r.left + 21, r.center.dy);
    canvas.drawCircle(c, 14, fillPaint(ArtPalette.maroon));
    final glyph = strokePaint(Colors.white, 1.9);
    switch (icon) {
      case _Glyph.clock:
        canvas
          ..drawCircle(c, 7, glyph)
          ..drawLine(c, c.translate(0, -4.2), glyph)
          ..drawLine(c, c.translate(3.4, 1.8), glyph);
      case _Glyph.bag:
        canvas
          ..drawRRect(
            RRect.fromRectAndRadius(
              Rect.fromCenter(center: c.translate(0, 2), width: 13, height: 11),
              const Radius.circular(2.4),
            ),
            fillPaint(Colors.white),
          )
          ..drawArc(Rect.fromCenter(center: c.translate(0, -3), width: 8, height: 9), math.pi, math.pi, false, glyph);
    }
    final bar = r.left + 42;
    canvas
      ..drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(bar, r.center.dy - 11, r.right - bar - 12, 7),
          const Radius.circular(3.5),
        ),
        fillPaint(p.ink.withValues(alpha: p.dark ? 0.7 : 0.78)),
      )
      ..drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(bar, r.center.dy + 1, (r.right - bar - 12) * 0.62, 6),
          const Radius.circular(3),
        ),
        fillPaint(ArtPalette.gold.withValues(alpha: 0.9)),
      )
      ..drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(bar, r.center.dy + 11, (r.right - bar - 12) * 0.4, 4),
          const Radius.circular(2),
        ),
        fillPaint(p.ink.withValues(alpha: 0.2)),
      );
  }

  /// Draws [path] as dashes.
  static void _dash(Canvas canvas, Path path, Paint paint, double dash, double gap) {
    for (final metric in path.computeMetrics()) {
      var d = 0.0;
      while (d < metric.length) {
        canvas.drawPath(metric.extractPath(d, math.min(d + dash, metric.length)), paint);
        d += dash + gap;
      }
    }
  }
}

enum _Glyph { clock, bag }
