import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:albahar_customer/features/onboarding/presentation/art/art_kit.dart';
import 'package:flutter/material.dart';

/// "Made for homes across Qatar": Doha at dusk. The West Bay towers (Burj Doha with its lattice, the hourglass Tornado
/// Tower), the Aspire Tower torch, the pigeon towers of Katara and a dhow on the Gulf, drawn as sheets that slide at
/// different speeds. Everything is on the picture's 400 x 440 grid; the water line is at y = 318.
abstract final class DohaScene {
  static const layers = <ArtLayer>[
    ArtLayer(0.02, _sky),
    ArtLayer(0.05, _farCity),
    ArtLayer(0.10, _skyline),
    ArtLayer(0.14, _sea),
    ArtLayer(0.26, _foreground),
  ];

  static const _horizon = 318.0;

  static void _sky(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final rect = Rect.fromLTRB(f.left, f.top, f.right, _horizon + 6);
    canvas.drawRect(
      rect,
      Paint()..shader = vertical(math.min(f.top, 0), _horizon, [p.skyTop, p.skyMid, p.skyGlow], const [0, 0.58, 1]),
    );

    // The setting sun by day, a thin moon and a few stars at night.
    const sun = Offset(204, 200);
    canvas.drawCircle(
      sun,
      210,
      Paint()
        ..shader = ui.Gradient.radial(sun, 210, [
          p.skyGlow.withValues(alpha: p.dark ? 0.55 : 0.75),
          p.skyGlow.withValues(alpha: 0),
        ]),
    );
    if (!p.dark) {
      canvas.drawCircle(
        sun,
        52,
        Paint()
          ..shader = ui.Gradient.radial(
            sun,
            52,
            [const Color(0xFFFFF3CF), ArtPalette.goldLight, ArtPalette.gold],
            const [0, 0.7, 1],
          ),
      );
    } else {
      final random = seeded(7);
      for (var i = 0; i < 38; i++) {
        final x = f.left + random.nextDouble() * (f.right - f.left);
        final y = math.min(f.top, 0) + random.nextDouble() * (_horizon - 70 - math.min(f.top, 0));
        final r = 0.5 + random.nextDouble() * 1.1;
        canvas.drawCircle(Offset(x, y), r, fillPaint(Colors.white.withValues(alpha: 0.35 + random.nextDouble() * 0.5)));
      }
      const moon = Offset(236, 118);
      canvas
        ..drawCircle(
          moon,
          54,
          Paint()
            ..shader = ui.Gradient.radial(moon, 54, [
              ArtPalette.goldLight.withValues(alpha: 0.28),
              ArtPalette.goldLight.withValues(alpha: 0),
            ]),
        )
        ..saveLayer(Rect.fromCircle(center: moon, radius: 30), Paint())
        ..drawCircle(moon, 17, fillPaint(const Color(0xFFFFF1CC)))
        ..drawCircle(moon.translate(8, -5), 15, Paint()..blendMode = BlendMode.clear)
        ..restore();
    }

    // A few thin streaks of cloud.
    final cloud = p.dark
        ? const Color(0xFFC46A7C).withValues(alpha: 0.22)
        : const Color(0xFFFFF1DD).withValues(alpha: 0.55);
    for (final c in const [
      Rect.fromLTWH(48, 196, 118, 5),
      Rect.fromLTWH(238, 168, 96, 4.5),
      Rect.fromLTWH(120, 226, 150, 4),
      Rect.fromLTWH(300, 214, 74, 4),
    ]) {
      canvas.drawRRect(RRect.fromRectAndRadius(c, const Radius.circular(3)), fillPaint(cloud));
    }

    // Birds going home.
    final bird = strokePaint(
      p.dark ? const Color(0xFFE9B7C0).withValues(alpha: 0.7) : ArtPalette.maroonDeep.withValues(alpha: 0.55),
      1.3,
    );
    for (final b in const [Offset(112, 138), Offset(130, 128), Offset(100, 124), Offset(142, 146)]) {
      canvas.drawPath(
        Path()
          ..moveTo(b.dx - 6, b.dy)
          ..quadraticBezierTo(b.dx - 3, b.dy - 4.5, b.dx, b.dy)
          ..quadraticBezierTo(b.dx + 3, b.dy - 4.5, b.dx + 6, b.dy),
        bird,
      );
    }
    canvas.restore();
  }

  /// The low, hazy city far behind the towers.
  static void _farCity(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final random = seeded(21);
    final body = fillPaint(p.farTone);
    var x = f.left - 4;
    while (x < f.right) {
      final w = 11 + random.nextDouble() * 15;
      final h = 18 + random.nextDouble() * 34;
      canvas.drawRect(Rect.fromLTWH(x, _horizon - h, w, h + 2), body);
      if (random.nextDouble() < 0.22) {
        canvas.drawRect(Rect.fromLTWH(x + w / 2 - 0.8, _horizon - h - 12, 1.6, 12), body);
      }
      x += w + random.nextDouble() * 4;
    }
    canvas.drawRect(
      Rect.fromLTRB(f.left, _horizon - 70, f.right, _horizon + 2),
      Paint()
        ..shader = vertical(_horizon - 70, _horizon, [
          p.skyGlow.withValues(alpha: 0),
          p.skyGlow.withValues(alpha: p.dark ? 0.55 : 0.8),
        ]),
    );
    canvas.restore();
  }

  /// West Bay and the Aspire torch.
  static void _skyline(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final soft = ArtPalette.mix(p.midTone, p.farTone, 0.45);
    final windows = p.dark ? p.lamp.withValues(alpha: 0.85) : Colors.white.withValues(alpha: 0.22);
    final lattice = strokePaint(
      p.dark ? p.lamp.withValues(alpha: 0.5) : Colors.white.withValues(alpha: 0.32),
      0.9,
      cap: StrokeCap.butt,
    );

    void lit(Rect r, int seed, double density) => _windows(canvas, r, seed, density, windows);

    // A block with a sloping roof.
    canvas.drawPath(
      polygon(const [Offset(50, _horizon), Offset(50, 204), Offset(82, 178), Offset(82, _horizon)]),
      fillPaint(soft),
    );
    lit(const Rect.fromLTRB(54, 198, 78, 312), 3, 0.32);

    // The hourglass Tornado Tower.
    final tornado = Path()
      ..moveTo(92, _horizon)
      ..quadraticBezierTo(103, 272, 104, 240)
      ..quadraticBezierTo(105, 206, 94, 174)
      ..lineTo(109, 126)
      ..lineTo(124, 174)
      ..quadraticBezierTo(113, 206, 114, 240)
      ..quadraticBezierTo(115, 272, 126, _horizon)
      ..close();
    canvas.drawPath(tornado, fillPaint(p.midTone));
    lit(const Rect.fromLTRB(99, 150, 119, 312), 5, 0.3);

    // Burj Doha: a round tower in a lattice of diamonds.
    final burj = RRect.fromLTRBAndCorners(
      134,
      104,
      174,
      _horizon,
      topLeft: const Radius.elliptical(20, 18),
      topRight: const Radius.elliptical(20, 18),
    );
    canvas
      ..drawRRect(burj, fillPaint(p.midTone))
      ..save()
      ..clipRRect(burj);
    for (var i = -12; i < 22; i++) {
      final x = 134.0 + i * 11;
      canvas
        ..drawLine(Offset(x, 104), Offset(x + 110, 104 + 214), lattice)
        ..drawLine(Offset(x + 110, 104), Offset(x, 104 + 214), lattice);
    }
    canvas.restore();

    // Palm Tower style: a slanted crown with a spire.
    canvas
      ..drawPath(
        polygon(const [Offset(236, _horizon), Offset(236, 208), Offset(268, 166), Offset(268, _horizon)]),
        fillPaint(soft),
      )
      ..drawRect(const Rect.fromLTWH(265.5, 142, 1.8, 26), fillPaint(soft));
    lit(const Rect.fromLTRB(240, 202, 264, 312), 11, 0.3);

    // A stepped crown.
    canvas.drawPath(
      polygon(const [
        Offset(276, _horizon),
        Offset(276, 186),
        Offset(281, 186),
        Offset(281, 156),
        Offset(293, 156),
        Offset(293, 186),
        Offset(298, 186),
        Offset(298, _horizon),
      ]),
      fillPaint(p.midTone),
    );
    lit(const Rect.fromLTRB(279, 164, 295, 312), 13, 0.3);

    // A pair of slim towers, one with an aerial.
    canvas
      ..drawRect(const Rect.fromLTRB(305, 202, 317, _horizon), fillPaint(soft))
      ..drawRect(const Rect.fromLTRB(320, 178, 334, _horizon), fillPaint(p.midTone))
      ..drawRect(const Rect.fromLTWH(326.2, 156, 1.6, 24), fillPaint(p.midTone));
    lit(const Rect.fromLTRB(322, 184, 332, 312), 17, 0.3);

    // The Aspire Tower: a slim shaft carrying the torch.
    canvas
      ..save()
      ..translate(350, 16);
    canvas.drawPath(
      polygon(const [Offset(-6, _horizon), Offset(-3.5, 132), Offset(3.5, 132), Offset(6, _horizon)]),
      fillPaint(p.midTone),
    );
    final torch = Path()
      ..moveTo(-3.5, 134)
      ..cubicTo(-4.5, 122, -12, 114, -14, 96)
      ..lineTo(-14, 90)
      ..quadraticBezierTo(-13, 74, 0, 62)
      ..quadraticBezierTo(13, 74, 14, 90)
      ..lineTo(14, 96)
      ..cubicTo(12, 114, 4.5, 122, 3.5, 134)
      ..close();
    canvas
      ..drawPath(torch, fillPaint(p.midTone))
      ..drawRect(const Rect.fromLTRB(-14, 93, 14, 97), fillPaint(p.lamp.withValues(alpha: p.dark ? 0.9 : 0.55)));
    canvas
      ..restore()
      ..restore();
  }

  static void _windows(Canvas canvas, Rect area, int seed, double density, Color color) {
    final random = seeded(seed);
    final paint = fillPaint(color);
    for (var y = area.top; y < area.bottom; y += 7.5) {
      for (var x = area.left; x < area.right; x += 6) {
        if (random.nextDouble() < density) canvas.drawRect(Rect.fromLTWH(x, y, 2.4, 3.2), paint);
      }
    }
  }

  /// The sea: the sky's glow on the water, the sun's path and the towers upside down in the ripples.
  static void _sea(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final rect = Rect.fromLTRB(f.left, _horizon, f.right, f.bottom + 2);
    canvas.drawRect(rect, Paint()..shader = vertical(_horizon, f.bottom, [p.seaTop, p.seaBottom]));

    // Reflections of the towers fade as they go down.
    canvas.saveLayer(rect, Paint());
    final mirror = fillPaint(p.midTone.withValues(alpha: p.dark ? 0.55 : 0.4));
    for (final r in const [
      Rect.fromLTRB(50, 0, 82, 120),
      Rect.fromLTRB(92, 0, 126, 190),
      Rect.fromLTRB(134, 0, 174, 215),
      Rect.fromLTRB(236, 0, 268, 150),
      Rect.fromLTRB(276, 0, 298, 165),
      Rect.fromLTRB(305, 0, 334, 140),
      Rect.fromLTRB(356, 0, 368, 250),
    ]) {
      canvas.drawRect(Rect.fromLTRB(r.left, _horizon, r.right, _horizon + r.bottom * 0.3), mirror);
    }
    canvas.drawRect(
      rect,
      Paint()
        ..blendMode = BlendMode.dstIn
        ..shader = vertical(_horizon, _horizon + 70, [Colors.white, Colors.white.withValues(alpha: 0)]),
    );
    canvas.restore();

    // Ripples cut through the reflections, and the glitter of the sun's path.
    final ripple = fillPaint(ArtPalette.mix(p.seaTop, p.seaBottom, 0.35).withValues(alpha: 0.85));
    final random = seeded(33);
    for (var i = 0; i < 16; i++) {
      final y = _horizon + 5 + i * 7.5;
      final w = 40 + random.nextDouble() * 110;
      final x = f.left + random.nextDouble() * (f.right - f.left);
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(x, y, w, 1.6), const Radius.circular(1)), ripple);
    }
    final glitter = p.dark ? ArtPalette.goldLight : const Color(0xFFFFEFC2);
    for (var i = 0; i < 9; i++) {
      final w = 96 - i * 9.0;
      final y = _horizon + 5 + i * 9.5;
      final shift = math.sin(i * 1.7) * 7;
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromCenter(center: Offset(204 + shift, y), width: w, height: 2.6),
          const Radius.circular(2),
        ),
        fillPaint(glitter.withValues(alpha: (p.dark ? 0.55 : 0.8) - i * 0.06)),
      );
    }
    canvas.drawRect(
      Rect.fromLTRB(f.left, _horizon - 0.5, f.right, _horizon + 1.2),
      fillPaint(p.skyGlow.withValues(alpha: 0.9)),
    );
    canvas.restore();
  }

  /// Close to the viewer: Katara's pigeon towers and palms on the left, a dhow riding on the right.
  static void _foreground(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final near = fillPaint(p.nearTone);

    // The waterfront wall with lit arches.
    canvas.drawRect(Rect.fromLTRB(f.left, 300, 132, _horizon + 2), near);
    for (var x = f.left; x < 132; x += 11) {
      canvas.drawRect(Rect.fromLTWH(x, 296, 6, 5), near);
    }
    final glow = fillPaint(p.dark ? p.lamp.withValues(alpha: 0.85) : p.lamp.withValues(alpha: 0.5));
    for (var x = 4.0; x < 126; x += 17) {
      canvas.drawPath(
        Path()
          ..moveTo(x, _horizon)
          ..lineTo(x, 314)
          ..quadraticBezierTo(x + 3, 303, x + 6, 314)
          ..lineTo(x + 6, _horizon)
          ..close(),
        glow,
      );
    }

    _pigeonTower(canvas, 52, 1, p);
    _pigeonTower(canvas, 102, 0.78, p);
    _palm(canvas, 136, 0.9, p);
    _palm(canvas, 156, 0.7, p);

    _dhow(canvas, const Offset(228, 334), 0.66, p);
    canvas.restore();
  }

  /// A tower of Katara: a round shaft, a ring of perches and a cone roof.
  static void _pigeonTower(Canvas canvas, double cx, double s, ArtPalette p) {
    canvas
      ..save()
      ..translate(cx, _horizon)
      ..scale(s);
    final near = fillPaint(p.nearTone);
    final body = Path()
      ..moveTo(-14, 2)
      ..lineTo(-12, -86)
      ..lineTo(-19, -90)
      ..lineTo(-17, -100)
      ..lineTo(-12, -102)
      ..lineTo(0, -140)
      ..lineTo(12, -102)
      ..lineTo(17, -100)
      ..lineTo(19, -90)
      ..lineTo(12, -86)
      ..lineTo(14, 2)
      ..close();
    canvas
      ..drawPath(body, near)
      ..drawRect(const Rect.fromLTWH(-0.9, -152, 1.8, 14), near);
    // Perches sticking out of the cone, and the little doorways of the pigeons.
    final stick = strokePaint(p.nearTone, 1.5);
    for (final y in const [-100.0, -112.0, -124.0]) {
      final half = 15.0 - (y.abs() - 100) * 0.55;
      canvas
        ..drawLine(Offset(-half, y), Offset(-half - 6, y - 1.5), stick)
        ..drawLine(Offset(half, y), Offset(half + 6, y - 1.5), stick);
    }
    final hole = fillPaint(p.dark ? p.lamp : p.lamp.withValues(alpha: 0.75));
    for (final y in const [-72.0, -58.0, -44.0]) {
      for (final x in const [-6.0, 0.0, 6.0]) {
        canvas.drawCircle(Offset(x, y), 1.5, hole);
      }
    }
    canvas.restore();
  }

  static void _palm(Canvas canvas, double x, double s, ArtPalette p) {
    canvas
      ..save()
      ..translate(x, _horizon)
      ..scale(s);
    final trunk = strokePaint(p.nearTone, 3.4);
    canvas.drawPath(
      Path()
        ..moveTo(0, 2)
        ..quadraticBezierTo(5, -52, -3, -104),
      trunk,
    );
    final frond = strokePaint(p.nearTone, 2.4);
    const crown = Offset(-3, -104);
    for (final tip in const [
      Offset(-36, -88),
      Offset(-28, -112),
      Offset(-10, -126),
      Offset(14, -122),
      Offset(30, -104),
      Offset(32, -84),
      Offset(8, -94),
      Offset(-16, -96),
    ]) {
      final mid = Offset((crown.dx + tip.dx) / 2, math.min(crown.dy, tip.dy) - 12);
      canvas.drawPath(
        Path()
          ..moveTo(crown.dx, crown.dy)
          ..quadraticBezierTo(mid.dx, mid.dy, tip.dx, tip.dy),
        frond,
      );
    }
    canvas.restore();
  }

  /// A dhow: high stern, long lateen sail on a slanted yard.
  static void _dhow(Canvas canvas, Offset at, double s, ArtPalette p) {
    canvas
      ..save()
      ..translate(at.dx, at.dy)
      ..scale(s);
    final sail = p.dark
        ? ArtPalette.mix(p.skyGlow, p.nearTone, 0.5)
        : ArtPalette.mix(ArtPalette.goldLight, ArtPalette.maroon, 0.12);
    final hull = Path()
      ..moveTo(-76, -18)
      ..lineTo(-62, -8)
      ..quadraticBezierTo(-10, -4, 50, -15)
      ..lineTo(58, -33)
      ..lineTo(74, -38)
      ..quadraticBezierTo(74, -4, 40, 8)
      ..quadraticBezierTo(-20, 16, -62, 4)
      ..quadraticBezierTo(-74, -2, -76, -18)
      ..close();

    // Reflection first, so the hull sits on it.
    canvas
      ..save()
      ..scale(1, -0.42)
      ..translate(0, 4);
    canvas.drawPath(hull, fillPaint(p.nearTone.withValues(alpha: 0.28)));
    canvas.restore();

    final yard = strokePaint(p.nearTone, 2.4);
    canvas
      ..drawPath(polygon(const [Offset(-60, -20), Offset(28, -124), Offset(46, -22)]), fillPaint(sail))
      ..drawLine(const Offset(-62, -20), const Offset(30, -128), yard)
      ..drawLine(const Offset(-8, -12), const Offset(-4, -98), strokePaint(p.nearTone, 2.6))
      ..drawLine(const Offset(-14, -70), const Offset(34, -70), strokePaint(p.nearTone.withValues(alpha: 0.18), 1))
      ..drawLine(const Offset(-30, -46), const Offset(40, -46), strokePaint(p.nearTone.withValues(alpha: 0.18), 1))
      ..drawLine(const Offset(30, -128), const Offset(72, -38), strokePaint(p.nearTone.withValues(alpha: 0.55), 1))
      ..drawLine(const Offset(-62, -20), const Offset(-76, -18), yard)
      ..drawPath(hull, fillPaint(p.nearTone));
    // A stripe of brass along the gunwale.
    canvas.drawPath(
      Path()
        ..moveTo(-62, -8)
        ..quadraticBezierTo(-10, -4, 50, -15),
      strokePaint(ArtPalette.gold.withValues(alpha: p.dark ? 0.7 : 0.9), 1.4),
    );
    canvas.restore();
  }
}
