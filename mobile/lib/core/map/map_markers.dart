import 'dart:math' as math;

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';

/// What the driver is riding or driving; picks the top-down glyph on the marker.
enum VehicleKind { bike, car, van }

/// Maps the free text the server sends (`motorcycle`, `Toyota Hilux pickup` ...) to a glyph. Unknown text is a bike,
/// the most common delivery vehicle.
VehicleKind vehicleKindOf(String? vehicle) {
  final v = (vehicle ?? '').toLowerCase();
  if (RegExp(r'van|truck|pickup|lorry|hilux|شاحنة|فان|وانيت').hasMatch(v)) return VehicleKind.van;
  if (RegExp(r'car|sedan|suv|hatch|سيارة').hasMatch(v)) return VehicleKind.car;
  return VehicleKind.bike;
}

/// The colours of one driver marker.
@immutable
class DriverMarkerColors {
  const DriverMarkerColors({required this.fill, required this.glyph, required this.halo});

  /// Live: the brand colour. Stale: a quiet grey.
  factory DriverMarkerColors.of(BuildContext context, {required bool stale, bool selected = false}) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final palette = context.palette;
    final live = selected ? palette.brassDeep : scheme.primary;
    return DriverMarkerColors(
      fill: stale ? (dark ? const Color(0xFF6B6168) : const Color(0xFF8E8288)) : live,
      glyph: Colors.white,
      halo: dark ? const Color(0xFF181115) : Colors.white,
    );
  }

  final Color fill;
  final Color glyph;
  final Color halo;
}

/// Paints the round brand marker with a top-down vehicle that turns to [heading] (degrees clockwise from north), a
/// small arrow head outside the disc, and, while [pulse] runs (0..1), a soft ring that grows and fades.
class DriverMarkerPainter extends CustomPainter {
  const DriverMarkerPainter({
    required this.colors,
    required this.vehicle,
    this.heading,
    this.pulse,
    this.selected = false,
    this.showArrow = true,
  });

  final DriverMarkerColors colors;
  final VehicleKind vehicle;
  final double? heading;
  final double? pulse;
  final bool selected;
  final bool showArrow;

  static const double radius = 17;

  @override
  void paint(Canvas canvas, Size size) {
    final c = size.center(Offset.zero);
    final r = selected ? radius + 2 : radius;
    final p = pulse;
    if (p != null) {
      canvas.drawCircle(c, r + 4 + 14 * p, Paint()..color = colors.fill.withValues(alpha: 0.30 * (1 - p)));
    }
    canvas.drawCircle(
      c.translate(0, 2),
      r + 4,
      Paint()
        ..color = const Color(0x33000000)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 4),
    );
    canvas.drawCircle(c, r + 3, Paint()..color = colors.halo);
    canvas.drawCircle(c, r, Paint()..color = colors.fill);
    final angle = (heading ?? 0) * math.pi / 180;
    if (heading != null && showArrow) {
      canvas.save();
      canvas.translate(c.dx, c.dy);
      canvas.rotate(angle);
      final arrow = Path()
        ..moveTo(0, -r - 11)
        ..lineTo(-6.5, -r - 1.5)
        ..lineTo(0, -r - 4.5)
        ..lineTo(6.5, -r - 1.5)
        ..close();
      canvas.drawPath(arrow, Paint()..color = colors.halo);
      canvas.drawPath(
        arrow,
        Paint()
          ..color = colors.fill
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.2
          ..strokeJoin = StrokeJoin.round,
      );
      canvas.restore();
    }
    canvas.save();
    canvas.translate(c.dx, c.dy);
    canvas.rotate(angle);
    _glyph(canvas);
    canvas.restore();
  }

  void _glyph(Canvas canvas) {
    final fill = Paint()..color = colors.glyph;
    final cut = Paint()..color = colors.fill;
    switch (vehicle) {
      case VehicleKind.bike:
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromCenter(center: const Offset(0, 1), width: 5, height: 14),
            const Radius.circular(2.5),
          ),
          fill,
        );
        canvas.drawLine(
          const Offset(-6.5, -4),
          const Offset(6.5, -4),
          Paint()
            ..color = colors.glyph
            ..strokeWidth = 2
            ..strokeCap = StrokeCap.round,
        );
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromCenter(center: const Offset(0, -8.5), width: 3, height: 5),
            const Radius.circular(1.5),
          ),
          fill,
        );
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromCenter(center: const Offset(0, 9), width: 3.6, height: 5),
            const Radius.circular(1.5),
          ),
          fill,
        );
      case VehicleKind.car:
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromCenter(center: Offset.zero, width: 11, height: 19),
            const Radius.circular(4.5),
          ),
          fill,
        );
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromCenter(center: const Offset(0, -3.6), width: 7.4, height: 3.6),
            const Radius.circular(1.6),
          ),
          cut,
        );
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromCenter(center: const Offset(0, 6), width: 7, height: 2.6),
            const Radius.circular(1.2),
          ),
          cut,
        );
      case VehicleKind.van:
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromCenter(center: Offset.zero, width: 12, height: 21),
            const Radius.circular(3),
          ),
          fill,
        );
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            Rect.fromCenter(center: const Offset(0, -6), width: 8, height: 3.6),
            const Radius.circular(1.4),
          ),
          cut,
        );
        canvas.drawLine(
          const Offset(-5, -2.4),
          const Offset(5, -2.4),
          Paint()
            ..color = colors.fill
            ..strokeWidth = 1.2,
        );
    }
  }

  @override
  bool shouldRepaint(DriverMarkerPainter old) =>
      old.colors.fill != colors.fill ||
      old.colors.halo != colors.halo ||
      old.vehicle != vehicle ||
      old.heading != heading ||
      old.pulse != pulse ||
      old.selected != selected ||
      old.showArrow != showArrow;
}

/// A driver on the map: the vehicle disc plus an optional name pill and a "last seen" line when the position is stale.
/// The box is square-ish and the disc sits in its exact middle, so a `Marker` with the default alignment puts the
/// driver's position under the disc.
class DriverMarkerView extends StatefulWidget {
  const DriverMarkerView({
    super.key,
    required this.label,
    required this.semanticsLabel,
    this.vehicle = VehicleKind.bike,
    this.heading,
    this.mapRotation = 0,
    this.moving = false,
    this.stale = false,
    this.selected = false,
    this.lastSeen,
    this.onTap,
    this.showName = true,
  });

  static const double width = 96;
  static const double height = 96;

  final String label;
  final String semanticsLabel;
  final VehicleKind vehicle;
  final double? heading;

  /// Rotation of the map (degrees), so the arrow keeps pointing at the real compass direction.
  final double mapRotation;
  final bool moving;
  final bool stale;
  final bool selected;

  /// "Last seen 4 min ago", shown under the name while stale.
  final String? lastSeen;
  final VoidCallback? onTap;
  final bool showName;

  @override
  State<DriverMarkerView> createState() => _DriverMarkerViewState();
}

class _DriverMarkerViewState extends State<DriverMarkerView> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1600),
  );

  bool _shouldPulse(BuildContext context) => widget.moving && !widget.stale && !MediaQuery.disableAnimationsOf(context);

  void _sync() {
    if (_shouldPulse(context)) {
      if (!_pulse.isAnimating) _pulse.repeat();
    } else if (_pulse.isAnimating || _pulse.value != 0) {
      _pulse.stop();
      _pulse.value = 0;
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _sync();
  }

  @override
  void didUpdateWidget(DriverMarkerView old) {
    super.didUpdateWidget(old);
    _sync();
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final colors = DriverMarkerColors.of(context, stale: widget.stale, selected: widget.selected);
    final heading = widget.heading == null ? null : widget.heading! - widget.mapRotation;
    final pulsing = _shouldPulse(context);
    final dark = Theme.of(context).brightness == Brightness.dark;
    final text = Theme.of(context).textTheme;
    final pillBg = (dark ? const Color(0xFF221A1E) : Colors.white).withValues(alpha: 0.94);
    final pillFg = dark ? const Color(0xFFF1ECE3) : const Color(0xFF2A1A20);
    return Semantics(
      container: true,
      label: widget.semanticsLabel,
      button: widget.onTap != null,
      selected: widget.selected,
      excludeSemantics: true,
      onTap: widget.onTap,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: widget.onTap,
        child: SizedBox(
          width: DriverMarkerView.width,
          height: DriverMarkerView.height,
          child: Stack(
            alignment: Alignment.center,
            clipBehavior: Clip.none,
            children: [
              RepaintBoundary(
                child: AnimatedBuilder(
                  animation: _pulse,
                  builder: (_, _) => CustomPaint(
                    size: const Size(60, 60),
                    painter: DriverMarkerPainter(
                      colors: colors,
                      vehicle: widget.vehicle,
                      heading: heading,
                      pulse: pulsing ? _pulse.value : null,
                      selected: widget.selected,
                      showArrow: !widget.stale,
                    ),
                  ),
                ),
              ),
              if (widget.showName || widget.stale)
                Positioned(
                  top: 70,
                  left: 0,
                  right: 0,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (widget.showName && widget.label.isNotEmpty)
                        _Pill(
                          text: widget.label,
                          style: text.labelSmall?.copyWith(fontWeight: FontWeight.w600, color: pillFg, fontSize: 10.5),
                          background: pillBg,
                        ),
                      if (widget.stale && widget.lastSeen != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: _Pill(
                            text: widget.lastSeen!,
                            style: text.labelSmall?.copyWith(color: pillFg.withValues(alpha: 0.8), fontSize: 9.5),
                            background: pillBg,
                          ),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.text, required this.style, required this.background});
  final String text;
  final TextStyle? style;
  final Color background;

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(8)),
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
      // The scale is capped: a marker label that follows a 1.3x system font would swallow the map.
      child: MediaQuery.withClampedTextScaling(
        maxScaleFactor: 1.15,
        child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis, style: style),
      ),
    ),
  );
}

/// What a pin stands for.
enum PinKind { branch, destination, picked }

/// A teardrop pin whose tip is the coordinate: use it in a `Marker` with `alignment: Alignment.topCenter`.
class MapPin extends StatelessWidget {
  const MapPin({super.key, required this.kind, required this.semanticsLabel, this.selected = false, this.onTap});

  static const double width = 44;
  static const double height = 54;

  final PinKind kind;
  final String semanticsLabel;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final color = switch (kind) {
      PinKind.branch => selected ? palette.brassDeep : palette.brass,
      PinKind.destination => scheme.primary,
      PinKind.picked => palette.brass,
    };
    final icon = switch (kind) {
      PinKind.branch => Icons.storefront_rounded,
      PinKind.destination => Icons.home_rounded,
      PinKind.picked => Icons.circle,
    };
    const scale = 1.0;
    final lift = selected ? 1.12 : scale;
    return Semantics(
      container: true,
      label: semanticsLabel,
      button: onTap != null,
      selected: selected,
      excludeSemantics: true,
      onTap: onTap,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: onTap,
        child: SizedBox(
          width: width,
          height: height,
          child: Transform.scale(
            scale: lift,
            alignment: Alignment.bottomCenter,
            child: Stack(
              alignment: Alignment.topCenter,
              children: [
                CustomPaint(
                  size: const Size(width, height),
                  painter: PinPainter(color: color, rim: dark ? const Color(0xFF181115) : Colors.white),
                ),
                Positioned(
                  top: 9,
                  child: Icon(icon, size: kind == PinKind.picked ? 10 : 20, color: Colors.white),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The teardrop outline: a disc with a point at the bottom centre.
class PinPainter extends CustomPainter {
  const PinPainter({required this.color, required this.rim});
  final Color color;
  final Color rim;

  @override
  void paint(Canvas canvas, Size size) {
    final cx = size.width / 2;
    const r = 17.0;
    const cy = r + 3;
    final tip = size.height - 1;
    final path = Path()
      ..moveTo(cx, tip)
      ..cubicTo(cx - 6, tip - 9, cx - r, cy + r * 0.7, cx - r, cy)
      ..arcToPoint(Offset(cx + r, cy), radius: const Radius.circular(r))
      ..cubicTo(cx + r, cy + r * 0.7, cx + 6, tip - 9, cx, tip)
      ..close();
    canvas.drawShadow(path, Colors.black, 4, false);
    canvas.drawPath(path, Paint()..color = color);
    canvas.drawPath(
      path,
      Paint()
        ..color = rim
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(PinPainter old) => old.color != color || old.rim != rim;
}

/// The "you are here" dot: a blue disc with a white ring, like the maps people already know.
class MyLocationDot extends StatelessWidget {
  const MyLocationDot({super.key, required this.semanticsLabel, this.heading});
  static const double size = 28;
  final String semanticsLabel;
  final double? heading;

  @override
  Widget build(BuildContext context) {
    final blue = Theme.of(context).brightness == Brightness.dark ? const Color(0xFF6FA8FF) : const Color(0xFF1A73E8);
    return Semantics(
      container: true,
      label: semanticsLabel,
      excludeSemantics: true,
      child: SizedBox(
        width: size,
        height: size,
        child: CustomPaint(painter: _DotPainter(blue, heading)),
      ),
    );
  }
}

class _DotPainter extends CustomPainter {
  const _DotPainter(this.color, this.heading);
  final Color color;
  final double? heading;

  @override
  void paint(Canvas canvas, Size size) {
    final c = size.center(Offset.zero);
    if (heading != null) {
      canvas.save();
      canvas.translate(c.dx, c.dy);
      canvas.rotate(heading! * math.pi / 180);
      final cone = Path()
        ..moveTo(0, -13)
        ..lineTo(-6, -7)
        ..lineTo(6, -7)
        ..close();
      canvas.drawPath(cone, Paint()..color = color);
      canvas.restore();
    }
    canvas.drawCircle(
      c.translate(0, 1),
      10,
      Paint()
        ..color = const Color(0x40000000)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 3),
    );
    canvas.drawCircle(c, 9, Paint()..color = Colors.white);
    canvas.drawCircle(c, 6.2, Paint()..color = color);
  }

  @override
  bool shouldRepaint(_DotPainter old) => old.color != color || old.heading != heading;
}

/// A round count badge standing for several drivers that are close together.
class ClusterBadge extends StatelessWidget {
  const ClusterBadge({super.key, required this.count, required this.semanticsLabel, this.onTap});
  static const double size = 48;
  final int count;
  final String semanticsLabel;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Semantics(
      container: true,
      label: semanticsLabel,
      button: onTap != null,
      excludeSemantics: true,
      onTap: onTap,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: onTap,
        child: SizedBox(
          width: size,
          height: size,
          child: DecoratedBox(
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: scheme.primary,
              border: Border.all(color: dark ? const Color(0xFF181115) : Colors.white, width: 3),
              boxShadow: const [BoxShadow(color: Color(0x40000000), blurRadius: 8, offset: Offset(0, 2))],
            ),
            child: Center(
              child: MediaQuery.withClampedTextScaling(
                maxScaleFactor: 1.1,
                child: Text(
                  '$count',
                  style: Theme.of(context).textTheme.titleSmall
                      ?.copyWith(color: scheme.onPrimary, fontWeight: FontWeight.w700),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
