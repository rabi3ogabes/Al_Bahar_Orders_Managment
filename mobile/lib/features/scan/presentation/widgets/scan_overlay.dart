import 'dart:math' as math;

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';

/// Where the scan window sits in a screen of [size]: a wide rounded window (barcodes are wide) in the upper middle, so the
/// panel that slides up from the bottom never covers it.
Rect scanWindowFor(Size size) {
  final width = math.min(size.width * 0.8, 340.0);
  final height = width * 0.62;
  final centerY = math.max(height / 2 + 120, size.height * 0.36);
  return Rect.fromCenter(center: Offset(size.width / 2, centerY), width: width, height: height);
}

/// The calm dimmed layer over the camera with a rounded clear window and four corner marks. The corners turn green for a
/// moment after a successful scan ([flash] changes with every scan); with reduced motion they simply switch colour.
class ScanOverlay extends StatelessWidget {
  const ScanOverlay({super.key, required this.flash, required this.looking});

  /// Changes with every successful scan (restarts the green flash).
  final int flash;

  /// A lookup is running: the corners are drawn a little thicker.
  final bool looking;

  static const _dim = Color(0x8C000000);

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.disableAnimationsOf(context);
    final success = context.palette.success;
    final label = context.l10n.scanWindowLabel;
    return LayoutBuilder(
      builder: (context, constraints) {
        final window = scanWindowFor(constraints.biggest);
        return Semantics(
          label: label,
          container: true,
          child: ExcludeSemantics(
            child: TweenAnimationBuilder<Color?>(
              key: ValueKey(flash),
              tween: ColorTween(begin: flash == 0 ? Colors.white : success, end: Colors.white),
              duration: reduce || flash == 0 ? Duration.zero : const Duration(milliseconds: 900),
              curve: Curves.easeInCubic,
              builder: (context, color, _) => CustomPaint(
                size: Size.infinite,
                painter: _OverlayPainter(window: window, corner: color ?? Colors.white, dim: _dim, thick: looking),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _OverlayPainter extends CustomPainter {
  _OverlayPainter({required this.window, required this.corner, required this.dim, required this.thick});

  final Rect window;
  final Color corner;
  final Color dim;
  final bool thick;

  @override
  void paint(Canvas canvas, Size size) {
    final rrect = RRect.fromRectAndRadius(window, const Radius.circular(AppRadius.lg));
    final shade = Path.combine(PathOperation.difference, Path()..addRect(Offset.zero & size), Path()..addRRect(rrect));
    canvas.drawPath(shade, Paint()..color = dim);

    // A hairline around the window, then the four corner marks on top.
    canvas.drawRRect(
      rrect,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1
        ..color = Colors.white.withValues(alpha: 0.25),
    );
    final mark = Paint()
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..strokeWidth = thick ? 6 : 4
      ..color = corner;
    const r = AppRadius.lg;
    final len = math.min(window.shortestSide * 0.22, 44.0);
    final l = window.left, t = window.top, rt = window.right, b = window.bottom;
    final path = Path()
      ..moveTo(l, t + len)
      ..lineTo(l, t + r)
      ..arcToPoint(Offset(l + r, t), radius: const Radius.circular(r))
      ..lineTo(l + len, t)
      ..moveTo(rt - len, t)
      ..lineTo(rt - r, t)
      ..arcToPoint(Offset(rt, t + r), radius: const Radius.circular(r))
      ..lineTo(rt, t + len)
      ..moveTo(rt, b - len)
      ..lineTo(rt, b - r)
      ..arcToPoint(Offset(rt - r, b), radius: const Radius.circular(r))
      ..lineTo(rt - len, b)
      ..moveTo(l + len, b)
      ..lineTo(l + r, b)
      ..arcToPoint(Offset(l, b - r), radius: const Radius.circular(r))
      ..lineTo(l, b - len);
    canvas.drawPath(path, mark);
  }

  @override
  bool shouldRepaint(_OverlayPainter old) =>
      old.window != window || old.corner != corner || old.thick != thick || old.dim != dim;
}
