import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:albahar_customer/features/onboarding/presentation/art/art_kit.dart';
import 'package:flutter/material.dart';

/// "Live auctions and curated living": a majlis. A mihrab niche with a hanging lantern, sadu-woven rug and cushions, a
/// brass dallah on its tray, and in front an auction gavel and a bidding paddle, with a small "live" signal on the wall.
abstract final class MajlisScene {
  static const layers = <ArtLayer>[
    ArtLayer(0.02, _wall),
    ArtLayer(0.06, _niche),
    ArtLayer(0.12, _floor),
    ArtLayer(0.22, _coffee),
    ArtLayer(0.34, _auction),
  ];

  static const _floorY = 304.0;
  static const _nicheRect = Rect.fromLTWH(104, 62, 192, 242);

  static Color _wood(ArtPalette p) => ArtPalette.mix(ArtPalette.maroonDeep, p.dark ? Colors.black : p.ink, 0.3);

  static void _wall(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final top = p.dark ? const Color(0xFF2A1A22) : ArtPalette.mix(p.background, ArtPalette.maroon, 0.2);
    final bottom = p.dark ? const Color(0xFF1D1117) : ArtPalette.mix(p.background, ArtPalette.maroon, 0.09);
    canvas.drawRect(f.canvas, Paint()..shader = vertical(f.top, _floorY, [top, bottom]));

    // A quiet tile pattern: eight-point stars made of two turned squares.
    final star = strokePaint(ArtPalette.maroon.withValues(alpha: p.dark ? 0.28 : 0.16), 1, cap: StrokeCap.butt);
    for (var y = 6.0; y < _floorY; y += 34) {
      for (var x = f.left - 4 + ((y / 34).floor().isEven ? 0 : 17); x < f.right; x += 34) {
        canvas
          ..save()
          ..translate(x + 17, y + 17);
        canvas.drawRect(Rect.fromCenter(center: Offset.zero, width: 17, height: 17), star);
        canvas
          ..rotate(math.pi / 4)
          ..drawRect(Rect.fromCenter(center: Offset.zero, width: 17, height: 17), star)
          ..restore();
      }
    }

    // Skirting board.
    canvas
      ..drawRect(Rect.fromLTRB(f.left, _floorY - 7, f.right, _floorY), fillPaint(_wood(p).withValues(alpha: 0.9)))
      ..drawRect(
        Rect.fromLTRB(f.left, _floorY - 7, f.right, _floorY - 5.5),
        fillPaint(ArtPalette.gold.withValues(alpha: 0.7)),
      );
    canvas.restore();
  }

  /// The mihrab niche: a pointed arch in maroon with a lattice of gold, a lantern, and the "live" signal.
  static void _niche(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    const r = _nicheRect;
    final arch = archTop(r)
      ..lineTo(r.right, _floorY)
      ..lineTo(r.left, _floorY)
      ..close();
    // The frame: a broad border with a gold line, then the deep niche.
    canvas.drawPath(arch, strokePaint(p.dark ? ArtPalette.maroonDeep : ArtPalette.maroon, 22, cap: StrokeCap.butt));
    canvas.drawPath(
      arch,
      Paint()
        ..shader = vertical(r.top, _floorY, [
          ArtPalette.maroonDeep,
          p.dark ? const Color(0xFF2B0A16) : ArtPalette.maroon,
        ]),
    );
    canvas
      ..save()
      ..clipPath(arch);
    final lattice = strokePaint(ArtPalette.gold.withValues(alpha: p.dark ? 0.3 : 0.28), 1, cap: StrokeCap.butt);
    for (var i = -16; i < 22; i++) {
      final x = r.left + i * 17.0;
      canvas
        ..drawLine(Offset(x, r.top), Offset(x + 270, r.top + 270), lattice)
        ..drawLine(Offset(x + 270, r.top), Offset(x, r.top + 270), lattice);
    }
    // The warm light of the lantern fills the niche.
    const lamp = Offset(200, 124);
    canvas.drawCircle(
      lamp,
      150,
      Paint()
        ..shader = ui.Gradient.radial(lamp, 150, [
          ArtPalette.goldLight.withValues(alpha: p.dark ? 0.42 : 0.5),
          ArtPalette.gold.withValues(alpha: 0),
        ]),
    );
    canvas.restore();
    canvas
      ..drawPath(arch, strokePaint(ArtPalette.gold.withValues(alpha: 0.95), 2.2))
      ..drawPath(archTop(r.inflate(11)), strokePaint(ArtPalette.gold.withValues(alpha: 0.55), 1))
      ..drawPath(archTop(r.deflate(8)), strokePaint(ArtPalette.gold.withValues(alpha: 0.4), 1));

    _lantern(canvas, p);
    _liveSignal(canvas, const Offset(338, 100));
    canvas.restore();
  }

  static void _lantern(Canvas canvas, ArtPalette p) {
    canvas
      ..save()
      ..translate(200, -8);
    final frame = strokePaint(ArtPalette.maroonDeep, 1.6);
    canvas.drawLine(const Offset(0, 70), const Offset(0, 96), strokePaint(ArtPalette.gold, 1.4));
    // Cap, glass, and the pointed foot.
    canvas.drawPath(
      polygon(const [Offset(-12, 108), Offset(12, 108), Offset(7, 97), Offset(-7, 97)]),
      fillPaint(ArtPalette.goldDeep),
    );
    final glass = polygon(const [
      Offset(-12, 108),
      Offset(-17, 126),
      Offset(-12, 148),
      Offset(12, 148),
      Offset(17, 126),
      Offset(12, 108),
    ]);
    canvas
      ..drawPath(
        glass,
        Paint()
          ..shader = ui.Gradient.radial(
            const Offset(0, 127),
            24,
            [const Color(0xFFFFF4D2), ArtPalette.goldLight, ArtPalette.gold],
            const [0, 0.55, 1],
          ),
      )
      ..drawPath(glass, frame)
      ..drawLine(const Offset(-5, 108), const Offset(-6, 148), frame)
      ..drawLine(const Offset(5, 108), const Offset(6, 148), frame)
      ..drawPath(polygon(const [Offset(-9, 148), Offset(9, 148), Offset(0, 164)]), fillPaint(ArtPalette.goldDeep))
      ..drawCircle(const Offset(0, 166), 2.2, fillPaint(ArtPalette.gold));
    canvas.restore();
  }

  /// A small broadcast sign: the sign of a live sale.
  static void _liveSignal(Canvas canvas, Offset at) {
    const red = Color(0xFFE5484D);
    canvas.drawCircle(at, 11, fillPaint(red.withValues(alpha: 0.18)));
    canvas.drawCircle(at, 5, fillPaint(red));
    for (final (radius, alpha) in const [(10.0, 0.85), (16.0, 0.5)]) {
      final arc = strokePaint(red.withValues(alpha: alpha), 2);
      canvas
        ..drawArc(Rect.fromCircle(center: at, radius: radius), -0.75, 1.5, false, arc)
        ..drawArc(Rect.fromCircle(center: at, radius: radius), math.pi - 0.75, 1.5, false, arc);
    }
  }

  /// The floor: a sadu-woven rug seen in perspective and the low majlis seating along the wall.
  static void _floor(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    canvas.drawRect(
      Rect.fromLTRB(f.left, _floorY, f.right, f.bottom + 2),
      Paint()
        ..shader = vertical(_floorY, f.bottom, [
          p.dark ? const Color(0xFF2A1A21) : ArtPalette.mix(ArtPalette.goldLight, p.background, 0.55),
          p.dark ? const Color(0xFF1A0F14) : ArtPalette.mix(ArtPalette.gold, p.background, 0.55),
        ]),
    );

    // The rug.
    const topY = _floorY + 12;
    const bottomY = 440.0;
    final rug = polygon(const [Offset(74, topY), Offset(326, topY), Offset(404, bottomY), Offset(-4, bottomY)]);
    canvas
      ..drawPath(rug, Paint()..shader = vertical(topY, bottomY, [ArtPalette.maroonDeep, ArtPalette.maroon]))
      ..save()
      ..clipPath(rug);
    // Rows of woven triangles, wider as they come closer.
    const rows = [
      (topY + 8, ArtPalette.goldLight, 0.9),
      (topY + 24, ArtPalette.gold, 0.85),
      (topY + 46, ArtPalette.goldLight, 0.8),
      (topY + 72, ArtPalette.gold, 0.75),
    ];
    for (final (y, color, alpha) in rows) {
      final t = (y - topY) / (bottomY - topY);
      final size = 6 + t * 9;
      final half = 126 + t * 80;
      for (var x = 200 - half; x < 200 + half; x += size * 1.6) {
        canvas.drawPath(
          polygon([Offset(x, y + size), Offset(x + size * 0.8, y), Offset(x + size * 1.6, y + size)]),
          fillPaint(color.withValues(alpha: alpha * 0.9)),
        );
      }
      canvas.drawLine(
        Offset(200 - half, y + size + 2),
        Offset(200 + half, y + size + 2),
        strokePaint(ArtPalette.gold.withValues(alpha: 0.5), 1, cap: StrokeCap.butt),
      );
    }
    canvas.restore();
    canvas.drawPath(rug, strokePaint(ArtPalette.gold.withValues(alpha: 0.9), 1.6));

    _cushions(canvas, p, mirrored: false);
    _cushions(canvas, p, mirrored: true);
    canvas.restore();
  }

  /// Low seating against the wall at one side, with a woven band.
  static void _cushions(Canvas canvas, ArtPalette p, {required bool mirrored}) {
    canvas
      ..save()
      ..translate(mirrored ? 400 : 0, 0)
      ..scale(mirrored ? -1 : 1, 1);
    const seatTop = _floorY - 32;
    final seat = RRect.fromRectAndRadius(
      const Rect.fromLTRB(-30, seatTop, 98, _floorY + 10),
      const Radius.circular(10),
    );
    final back = RRect.fromRectAndRadius(
      const Rect.fromLTRB(-30, seatTop - 44, 78, seatTop + 8),
      const Radius.circular(12),
    );
    final body = mirrored ? ArtPalette.mix(ArtPalette.maroonDeep, Colors.black, 0.15) : ArtPalette.maroon;
    const pattern = ArtPalette.goldLight;
    canvas
      ..drawRRect(back.shift(const Offset(0, 3)), fillPaint(Colors.black.withValues(alpha: 0.18)))
      ..drawRRect(back, fillPaint(ArtPalette.mix(body, Colors.black, 0.18)))
      ..drawRRect(seat.shift(const Offset(0, 3)), fillPaint(Colors.black.withValues(alpha: 0.2)))
      ..drawRRect(seat, fillPaint(body));
    // A woven band along the seat and a diamond on the back.
    canvas
      ..save()
      ..clipRRect(seat);
    for (var x = -30.0; x < 100; x += 10) {
      canvas
        ..drawPath(
          polygon([Offset(x, seatTop + 28), Offset(x + 5, seatTop + 18), Offset(x + 10, seatTop + 28)]),
          fillPaint(pattern.withValues(alpha: 0.9)),
        )
        ..drawPath(
          polygon([Offset(x + 5, seatTop + 28), Offset(x + 10, seatTop + 38), Offset(x, seatTop + 38)]),
          fillPaint(pattern.withValues(alpha: 0.55)),
        );
    }
    canvas.restore();
    const cy = seatTop - 20;
    final diamond = Path()
      ..moveTo(24, cy - 17)
      ..lineTo(40, cy)
      ..lineTo(24, cy + 17)
      ..lineTo(8, cy)
      ..close();
    final inner = Path()
      ..moveTo(24, cy - 9)
      ..lineTo(32, cy)
      ..lineTo(24, cy + 9)
      ..lineTo(16, cy)
      ..close();
    canvas
      ..drawPath(diamond, strokePaint(pattern.withValues(alpha: 0.85), 1.6))
      ..drawPath(inner, fillPaint(pattern.withValues(alpha: 0.7)));
    canvas.restore();
  }

  /// The coffee set: a brass dallah on its tray, two finjan cups.
  static void _coffee(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    // Tray.
    final tray = Rect.fromCenter(center: const Offset(200, _floorY + 8), width: 168, height: 20);
    canvas
      ..drawOval(tray.shift(const Offset(0, 5)), fillPaint(Colors.black.withValues(alpha: 0.28)))
      ..drawOval(
        tray,
        Paint()
          ..shader = ui.Gradient.linear(
            tray.centerLeft,
            tray.centerRight,
            [ArtPalette.goldDeep, ArtPalette.goldLight, ArtPalette.gold, ArtPalette.goldDeep],
            const [0, 0.35, 0.7, 1],
          ),
      )
      ..drawOval(tray.deflate(5), strokePaint(ArtPalette.goldDeep.withValues(alpha: 0.6), 1));

    _cup(canvas, const Offset(144, _floorY + 6));
    _cup(canvas, const Offset(256, _floorY + 6));
    _dallah(canvas, const Offset(200, _floorY + 8), 1.22, p);
    canvas.restore();
  }

  static void _cup(Canvas canvas, Offset at) {
    canvas
      ..save()
      ..translate(at.dx, at.dy);
    canvas
      ..drawOval(Rect.fromCenter(center: const Offset(0, 1), width: 22, height: 6), fillPaint(ArtPalette.goldLight))
      ..drawPath(
        polygon(const [Offset(-8, -12), Offset(8, -12), Offset(5.5, 0), Offset(-5.5, 0)]),
        fillPaint(const Color(0xFFFFF7EA)),
      )
      ..drawOval(
        Rect.fromCenter(center: const Offset(0, -12), width: 16, height: 4.4),
        fillPaint(const Color(0xFF3A1D12)),
      )
      ..drawPath(
        polygon(const [Offset(-8, -12), Offset(8, -12), Offset(7.4, -9), Offset(-7.4, -9)]),
        fillPaint(ArtPalette.maroon.withValues(alpha: 0.85)),
      );
    canvas.restore();
  }

  /// A Gulf dallah: a belly, a tall neck with a domed lid, a long curved spout and a handle, in engraved brass.
  static void _dallah(Canvas canvas, Offset base, double s, ArtPalette p) {
    canvas
      ..save()
      ..translate(base.dx, base.dy)
      ..scale(s);
    final brass = Paint()
      ..shader = ui.Gradient.linear(
        const Offset(-40, 0),
        const Offset(40, 0),
        [ArtPalette.goldDeep, ArtPalette.gold, ArtPalette.goldLight, ArtPalette.gold, ArtPalette.goldDeep],
        const [0, 0.22, 0.4, 0.72, 1],
      );
    final shade = fillPaint(ArtPalette.goldDeep);
    final body = Path()
      ..moveTo(-27, 0)
      ..cubicTo(-38, -10, -40, -30, -30, -44)
      ..cubicTo(-22, -52, -14, -58, -13, -70)
      ..lineTo(-13, -78)
      ..cubicTo(-13, -82, -17, -84, -19, -90)
      ..lineTo(19, -90)
      ..cubicTo(17, -84, 13, -82, 13, -78)
      ..lineTo(13, -70)
      ..cubicTo(14, -58, 22, -52, 30, -44)
      ..cubicTo(40, -30, 38, -10, 27, 0)
      ..close();

    // Handle and spout first, so the body overlaps their roots.
    canvas
      ..drawPath(
        Path()
          ..moveTo(17, -84)
          ..cubicTo(54, -92, 60, -42, 33, -30),
        strokePaint(ArtPalette.goldDeep, 6),
      )
      ..drawPath(
        Path()
          ..moveTo(-30, -24)
          ..cubicTo(-52, -30, -60, -52, -57, -82)
          ..quadraticBezierTo(-56, -94, -68, -103),
        strokePaint(ArtPalette.gold, 8.5),
      )
      ..drawPath(
        Path()
          ..moveTo(-30, -24)
          ..cubicTo(-52, -30, -60, -52, -57, -82)
          ..quadraticBezierTo(-56, -94, -68, -103),
        strokePaint(ArtPalette.goldLight.withValues(alpha: 0.55), 2.2),
      )
      ..drawPath(polygon(const [Offset(-74, -110), Offset(-62, -102), Offset(-68, -97)]), shade)
      ..drawPath(body, brass)
      ..drawPath(body, strokePaint(ArtPalette.goldDeep, 1.1));

    // Engraved bands and a row of diamonds.
    final line = strokePaint(ArtPalette.goldDeep.withValues(alpha: 0.85), 1.2);
    for (final y in const [-18.0, -38.0]) {
      canvas.drawArc(
        Rect.fromCenter(center: Offset(0, y), width: 76 - (y.abs() - 18) * 0.5, height: 9),
        0.05,
        math.pi - 0.1,
        false,
        line,
      );
    }
    for (var x = -22.0; x <= 22; x += 11) {
      canvas.drawPath(
        polygon([Offset(x, -30), Offset(x + 4, -27), Offset(x, -24), Offset(x - 4, -27)]),
        fillPaint(ArtPalette.maroon.withValues(alpha: 0.85)),
      );
    }
    canvas.drawLine(const Offset(-13, -74), const Offset(13, -74), line);

    // Lid with its finial.
    final lid = Path()
      ..moveTo(-19, -90)
      ..quadraticBezierTo(-18, -104, 0, -105)
      ..quadraticBezierTo(18, -104, 19, -90)
      ..close();
    canvas
      ..drawPath(lid, brass)
      ..drawPath(lid, strokePaint(ArtPalette.goldDeep, 1))
      ..drawCircle(const Offset(0, -109), 4.2, fillPaint(ArtPalette.goldLight))
      ..drawCircle(const Offset(0, -109), 4.2, strokePaint(ArtPalette.goldDeep, 0.9));
    canvas.restore();
  }

  /// In front: the gavel on its block and a bidding paddle.
  static void _auction(Canvas canvas, SceneFrame f, ArtPalette p) {
    canvas.save();
    f.apply(canvas);
    final wood = _wood(p);
    final woodLight = ArtPalette.mix(wood, ArtPalette.goldLight, 0.22);

    // Paddle.
    canvas
      ..save()
      ..translate(62, 366)
      ..rotate(-0.2);
    canvas
      ..drawRRect(
        RRect.fromRectAndRadius(const Rect.fromLTWH(-3, 14, 6, 34), const Radius.circular(3)),
        fillPaint(wood),
      )
      ..drawCircle(const Offset(0, 2), 21, fillPaint(Colors.black.withValues(alpha: 0.22)))
      ..drawCircle(const Offset(0, 0), 20, fillPaint(ArtPalette.maroon))
      ..drawCircle(const Offset(0, 0), 20, strokePaint(ArtPalette.gold, 2.2))
      ..drawCircle(const Offset(0, 0), 14, strokePaint(ArtPalette.goldLight.withValues(alpha: 0.85), 1.2));
    // A star in the middle: two turned squares.
    final star = strokePaint(ArtPalette.goldLight, 1.5);
    canvas
      ..drawRect(Rect.fromCenter(center: Offset.zero, width: 11, height: 11), star)
      ..save()
      ..rotate(math.pi / 4)
      ..drawRect(Rect.fromCenter(center: Offset.zero, width: 11, height: 11), star)
      ..restore();
    canvas.restore();

    // Block and gavel.
    final block = RRect.fromRectAndRadius(const Rect.fromLTWH(268, 392, 62, 13), const Radius.circular(5));
    canvas
      ..drawRRect(block.shift(const Offset(0, 3)), fillPaint(Colors.black.withValues(alpha: 0.28)))
      ..drawRRect(block, fillPaint(wood))
      ..drawRRect(
        RRect.fromRectAndRadius(const Rect.fromLTWH(268, 392, 62, 3.5), const Radius.circular(2)),
        fillPaint(woodLight),
      )
      ..drawRect(const Rect.fromLTWH(272, 399, 54, 1.6), fillPaint(ArtPalette.gold.withValues(alpha: 0.85)));
    canvas
      ..save()
      ..translate(302, 366)
      ..rotate(0.5);
    final handle = RRect.fromRectAndRadius(const Rect.fromLTWH(-3, -2, 6, 38), const Radius.circular(3));
    final head = RRect.fromRectAndRadius(const Rect.fromLTWH(-22, -9, 44, 17), const Radius.circular(6));
    canvas
      ..drawRRect(handle, fillPaint(wood))
      ..drawRRect(head.shift(const Offset(1, 3)), fillPaint(Colors.black.withValues(alpha: 0.25)))
      ..drawRRect(head, fillPaint(wood))
      ..drawRRect(
        RRect.fromRectAndRadius(const Rect.fromLTWH(-22, -9, 44, 5), const Radius.circular(4)),
        fillPaint(woodLight),
      )
      ..drawRect(const Rect.fromLTWH(-17, -9, 4, 17), fillPaint(ArtPalette.gold))
      ..drawRect(const Rect.fromLTWH(13, -9, 4, 17), fillPaint(ArtPalette.gold));
    canvas.restore();
    canvas.restore();
  }
}
