import 'dart:math' as math;

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/onboarding/presentation/art/art_kit.dart';
import 'package:flutter/material.dart';

/// What drives the sideways movement of the sheets while the pages are swiped: [listenable] fires on every scroll tick and
/// [delta] is how far this page is from the centre of the screen, in pages (0 = in view, +1 = one page towards the start).
@immutable
class ArtMotion {
  const ArtMotion({required this.listenable, required this.delta});
  final Listenable listenable;
  final double Function() delta;
}

/// An arched window onto one illustration: sheets of artwork that slide against each other while swiping, a gold inner frame
/// and the nine-point edge of the Qatar flag along the bottom. Each sheet is its own repaint boundary, so a swipe only moves
/// finished pictures and never repaints them.
class IntroArtWindow extends StatelessWidget {
  const IntroArtWindow({super.key, required this.layers, required this.semanticLabel, this.motion});

  final List<ArtLayer> layers;
  final String semanticLabel;
  final ArtMotion? motion;

  @override
  Widget build(BuildContext context) {
    final palette = ArtPalette.of(context);
    final rtl = context.isRtl;
    final moving = motion != null && !MediaQuery.disableAnimationsOf(context);

    return Semantics(
      image: true,
      label: semanticLabel,
      child: ExcludeSemantics(
        child: LayoutBuilder(
          builder: (context, constraints) {
            final window = constraints.biggest;
            return RepaintBoundary(
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  Positioned.fill(
                    child: RepaintBoundary(child: CustomPaint(painter: _ShadowPainter(palette))),
                  ),
                  Positioned.fill(
                    child: ClipPath(
                      clipper: const ArchClipper(),
                      child: Stack(
                        clipBehavior: Clip.none,
                        children: [
                          for (final layer in layers) _sheet(layer, window, palette, moving ? motion : null, rtl),
                          Positioned.fill(
                            child: RepaintBoundary(
                              child: CustomPaint(
                                painter: _FlagEdgePainter(palette),
                                foregroundPainter: _FramePainter(palette),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _sheet(ArtLayer layer, Size window, ArtPalette palette, ArtMotion? motion, bool rtl) {
    final pad = motion == null ? 0.0 : layer.parallax.abs() * window.width;
    final width = window.width + 2 * pad;
    Widget sheet = RepaintBoundary(
      child: CustomPaint(size: Size(width, window.height), painter: _SheetPainter(layer, palette, window, pad)),
    );
    if (motion != null && layer.parallax != 0) {
      final side = rtl ? -1.0 : 1.0;
      sheet = AnimatedBuilder(
        animation: motion.listenable,
        child: sheet,
        builder: (context, child) =>
            Transform.translate(offset: Offset(motion.delta() * layer.parallax * window.width * side, 0), child: child),
      );
    }
    return Positioned(left: -pad, top: 0, width: width, height: window.height, child: sheet);
  }
}

class _SheetPainter extends CustomPainter {
  const _SheetPainter(this.layer, this.palette, this.window, this.pad);

  final ArtLayer layer;
  final ArtPalette palette;
  final Size window;
  final double pad;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.clipRect(Offset.zero & size);
    final frame = SceneFrame(window: window, pad: pad, layerWidth: size.width);
    layer.paint(canvas, frame, palette);
    canvas.restore();
  }

  @override
  bool shouldRepaint(_SheetPainter old) =>
      old.layer.paint != layer.paint || old.palette != palette || old.window != window || old.pad != pad;
}

/// A soft maroon glow under the window, so it rests on the page instead of being pasted on it.
class _ShadowPainter extends CustomPainter {
  const _ShadowPainter(this.palette);
  final ArtPalette palette;

  @override
  void paint(Canvas canvas, Size size) {
    final path = archPath(Offset.zero & size).shift(const Offset(0, 10));
    canvas.drawPath(
      path,
      Paint()
        ..color = ArtPalette.maroon.withValues(alpha: palette.dark ? 0.5 : 0.22)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 14),
    );
  }

  @override
  bool shouldRepaint(_ShadowPainter old) => old.palette != palette;
}

class _FlagEdgePainter extends CustomPainter {
  const _FlagEdgePainter(this.palette);
  final ArtPalette palette;

  @override
  void paint(Canvas canvas, Size size) => paintFlagEdge(canvas, size, palette);

  @override
  bool shouldRepaint(_FlagEdgePainter old) => old.palette != palette;
}

/// The thin gold line that follows the arch, a little inside the edge, like the border of a miniature painting.
class _FramePainter extends CustomPainter {
  const _FramePainter(this.palette);
  final ArtPalette palette;

  @override
  void paint(Canvas canvas, Size size) {
    final bottom = size.height - math.min(22.0, size.height * 0.075) - 6;
    void line(double inset, double alpha, double width) {
      final r = Rect.fromLTRB(inset, inset, size.width - inset, size.height);
      final path = archTop(r);
      path
        ..moveTo(r.left, bottom)
        ..lineTo(r.left, r.top + math.min(r.height * 0.5, r.width * 0.66))
        ..moveTo(r.right, bottom)
        ..lineTo(r.right, r.top + math.min(r.height * 0.5, r.width * 0.66));
      canvas.drawPath(path, strokePaint(ArtPalette.gold.withValues(alpha: alpha), width));
    }

    line(8, 0.9, 1.3);
    line(12.5, 0.4, 0.8);
  }

  @override
  bool shouldRepaint(_FramePainter old) => old.palette != palette;
}
