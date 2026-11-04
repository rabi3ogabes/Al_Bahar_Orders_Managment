import 'dart:async';

import 'package:flutter/material.dart';

import '../l10n/l10n.dart';
import '../utils/qatar_time.dart';

/// Ticking countdown to [target], corrected by [serverOffset]
/// (`server_time - device_time`) so device clock skew never matters.
class CountdownText extends StatefulWidget {
  const CountdownText({
    super.key,
    required this.target,
    this.serverOffset = Duration.zero,
    this.style,
    this.builder,
    this.onElapsed,
  });

  final DateTime target;
  final Duration serverOffset;
  final TextStyle? style;

  /// Optional custom rendering from the remaining duration.
  final Widget Function(BuildContext context, Duration remaining, String formatted)? builder;
  final VoidCallback? onElapsed;

  @override
  State<CountdownText> createState() => _CountdownTextState();
}

class _CountdownTextState extends State<CountdownText> {
  Timer? _timer;
  bool _elapsedFired = false;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) => _tick());
  }

  @override
  void didUpdateWidget(covariant CountdownText oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.target != widget.target) _elapsedFired = false;
  }

  void _tick() {
    if (!mounted) return;
    final remaining = _remaining;
    if (remaining <= Duration.zero && !_elapsedFired) {
      _elapsedFired = true;
      widget.onElapsed?.call();
    }
    setState(() {});
  }

  Duration get _remaining => widget.target.difference(DateTime.now().add(widget.serverOffset));

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    var remaining = _remaining;
    if (remaining.isNegative) remaining = Duration.zero;
    final formatted = formatCountdown(remaining, daysLabel: context.l10n.countdownDays);
    if (widget.builder != null) return widget.builder!(context, remaining, formatted);
    return Text(
      formatted,
      style: (widget.style ?? Theme.of(context).textTheme.titleMedium)?.copyWith(
        fontFeatures: const [FontFeature.tabularFigures()],
      ),
      textDirection: TextDirection.ltr,
    );
  }
}
