import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/deals/domain/deal_countdown.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_card_data.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart' show NumberFormat;

/// How big the countdown is drawn.
enum DealTimerSize { small, medium, large }

/// The countdown of a deal in one of its three looks (`boxes`, `digits`, `text`).
///
/// It draws the numbers of [parts] only: the caller owns the clock. The numbers always run left to right (a clock reads
/// the same in Arabic), in the digits of the app's language. The whole thing is hidden from screen readers (the deal
/// widget speaks one label that changes once a minute).
class DealTimerView extends StatelessWidget {
  const DealTimerView({
    super.key,
    required this.parts,
    required this.look,
    required this.shape,
    required this.text,
    required this.accent,
    this.urgent = false,
    this.size = DealTimerSize.medium,
    this.secondsLeft,
    this.display = false,
    this.align = AlignmentDirectional.centerStart,
  });

  final List<DealPart> parts;
  final DealTimerLook look;
  final DealShape shape;

  /// The widget's text colour (digits) and its accent (the digits of the last stretch, the box borders of the luxe look).
  final Color text;
  final Color accent;

  /// The last stretch: digits in the accent colour, and a small pulse once a second.
  final bool urgent;
  final DealTimerSize size;

  /// Seconds left: the pulse beats once each time it changes.
  final int? secondsLeft;

  /// Draw the digits in the display (serif) font: the luxe style.
  final bool display;
  final AlignmentGeometry align;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final lang = context.localeCode;
    final fmt = NumberFormat('00', lang);
    final ink = urgent ? accent : text;
    final fontSize = switch (size) {
      DealTimerSize.small => 15.0,
      DealTimerSize.medium => 22.0,
      DealTimerSize.large => 32.0,
    };
    final base = Theme.of(context).textTheme.titleLarge ?? const TextStyle();
    final digits = base.copyWith(
      color: ink,
      fontSize: fontSize,
      fontWeight: FontWeight.w700,
      height: 1.1,
      letterSpacing: 0,
      fontFamily: display && lang != 'ar' ? AppFonts.display : base.fontFamily,
      fontFeatures: const [FontFeature.tabularFigures(), FontFeature.liningFigures()],
    );
    final labelStyle = Theme.of(context).textTheme.labelSmall?.copyWith(
      color: text.withValues(alpha: 0.78),
      fontSize: size == DealTimerSize.small ? 9.5 : 11,
      letterSpacing: 0,
    );
    final boxRadius = switch (shape) {
      DealShape.rounded => 10.0,
      DealShape.sharp => 2.0,
      DealShape.pill => 999.0,
    };

    final Widget body = switch (look) {
      DealTimerLook.boxes => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < parts.length; i++) ...[
            if (i > 0) SizedBox(width: size == DealTimerSize.small ? 4 : 6),
            _Box(
              value: fmt.format(parts[i].value),
              label: dealUnitLabel(l10n, parts[i].unit),
              digits: digits,
              labelStyle: labelStyle,
              size: size,
              radius: shape == DealShape.pill ? (size == DealTimerSize.small ? 14 : 20) : boxRadius,
              fill: display ? Colors.transparent : text.withValues(alpha: 0.13),
              border: display ? accent.withValues(alpha: 0.65) : null,
            ),
          ],
        ],
      ),
      DealTimerLook.digits => Row(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.baseline,
        textBaseline: TextBaseline.alphabetic,
        children: [
          for (var i = 0; i < parts.length; i++) ...[
            if (i > 0) Text(':', style: digits.copyWith(color: ink.withValues(alpha: 0.6))),
            Text(fmt.format(parts[i].value), style: digits),
          ],
        ],
      ),
      DealTimerLook.text => Text.rich(
        TextSpan(
          children: [
            for (var i = 0; i < parts.length; i++) ...[
              if (i > 0) const TextSpan(text: ' '),
              TextSpan(text: fmt.format(parts[i].value), style: digits),
              TextSpan(
                text: dealUnitLabel(l10n, parts[i].unit, short: true),
                style: digits.copyWith(fontSize: fontSize * 0.62, fontWeight: FontWeight.w600),
              ),
            ],
          ],
        ),
      ),
    };

    return ExcludeSemantics(
      child: Align(
        alignment: align,
        // A clock reads left to right whatever the language; scaled down rather than overflowing a narrow phone.
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.center,
            child: DealPulse(active: urgent, tick: secondsLeft, child: body),
          ),
        ),
      ),
    );
  }
}

class _Box extends StatelessWidget {
  const _Box({
    required this.value,
    required this.label,
    required this.digits,
    required this.labelStyle,
    required this.size,
    required this.radius,
    required this.fill,
    this.border,
  });

  final String value;
  final String label;
  final TextStyle digits;
  final TextStyle? labelStyle;
  final DealTimerSize size;
  final double radius;
  final Color fill;
  final Color? border;

  @override
  Widget build(BuildContext context) {
    final small = size == DealTimerSize.small;
    return Container(
      constraints: BoxConstraints(minWidth: small ? 36 : (size == DealTimerSize.large ? 62 : 50)),
      padding: EdgeInsets.symmetric(horizontal: small ? 6 : 8, vertical: small ? 4 : 7),
      decoration: BoxDecoration(
        color: fill,
        borderRadius: BorderRadius.circular(radius),
        border: border == null ? null : Border.all(color: border!),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(value, style: digits),
          if (!small) Text(label, maxLines: 1, style: labelStyle),
        ],
      ),
    );
  }
}

/// A beat once each time [tick] changes while [active]: the digits of the last stretch of a deal. Still when the system
/// asks for reduced motion.
class DealPulse extends StatefulWidget {
  const DealPulse({super.key, required this.active, required this.tick, required this.child});

  final bool active;
  final Object? tick;
  final Widget child;

  @override
  State<DealPulse> createState() => _DealPulseState();
}

class _DealPulseState extends State<DealPulse> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 600),
  );
  late final Animation<double> _scale = TweenSequence<double>([
    TweenSequenceItem(tween: Tween(begin: 1.0, end: 1.07).chain(CurveTween(curve: Curves.easeOut)), weight: 35),
    TweenSequenceItem(tween: Tween(begin: 1.07, end: 1.0).chain(CurveTween(curve: Curves.easeIn)), weight: 65),
  ]).animate(_controller);

  @override
  void didUpdateWidget(covariant DealPulse oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.active && widget.tick != oldWidget.tick && !MediaQuery.disableAnimationsOf(context)) {
      _controller.forward(from: 0);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.active || MediaQuery.disableAnimationsOf(context)) return widget.child;
    return ScaleTransition(scale: _scale, child: widget.child);
  }
}
