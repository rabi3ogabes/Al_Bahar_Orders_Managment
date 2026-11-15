import 'dart:async';
import 'dart:math' as math;

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:flutter/gestures.dart' show DragStartBehavior;
import 'package:flutter/material.dart';

/// The thumb of the slide, found by tests and by screen-reader focus code.
const slideThumbKey = ValueKey<String>('slide-to-confirm-thumb');

/// "Slide to confirm": a pill track with a round thumb that the person drags across. The step is taken only when the
/// thumb is pulled past [threshold] of the track (and let go), or all the way to the end; letting go earlier springs it
/// back. It replaces the plain button of a status change when the dashboard asks for it, so a step cannot be taken by a
/// stray tap in a shop or on a motorbike.
///
/// - Direction follows the text direction: in Arabic the thumb starts on the right and slides left.
/// - Only the thumb listens for the horizontal drag, so a vertical swipe on the thumb still scrolls the list or the
///   panel behind it, and a tap on the empty track only nudges the thumb (it never reaches a card behind it).
/// - [onConfirmed] fires once per slide. The thumb then waits at the end showing progress until it returns, and slides
///   back to the start when it finishes (a failed step is shown by the caller; the control is ready again).
/// - [loading] is for a caller that runs the step itself: the thumb shows progress and nothing can be started, and the
///   control resets when it turns false again. [enabled] false greys it out.
/// - Screen readers get one button named [label]: a double tap takes the step. A keyboard's Enter or Space does the same.
/// - Reduced motion: no spring, no hint animation; the thumb moves where the finger is and jumps back.
class SlideToConfirm extends StatefulWidget {
  const SlideToConfirm({
    super.key,
    required this.label,
    required this.onConfirmed,
    this.busyLabel,
    this.enabled = true,
    this.loading = false,
    this.threshold = 0.85,
    this.height = 60,
  }) : assert(threshold > 0 && threshold <= 1),
       assert(height >= 56);

  /// The step in words, shown on the track and read to a screen reader ("Slide to accept order").
  final String label;

  /// Shown on the track while the step is being taken; the plain [label] when null.
  final String? busyLabel;

  /// Called once when the slide is complete. A returned future keeps the control busy until it completes.
  final FutureOr<void> Function() onConfirmed;
  final bool enabled;
  final bool loading;

  /// How far (0..1) the thumb must be pulled for a release to confirm.
  final double threshold;

  /// The height of the track; the thumb is a little smaller than this.
  final double height;

  @override
  State<SlideToConfirm> createState() => _SlideToConfirmState();
}

enum _Phase { idle, dragging, confirming, resetting }

class _SlideToConfirmState extends State<SlideToConfirm> with TickerProviderStateMixin {
  /// How far the thumb is, 0 (start) .. 1 (end).
  late final AnimationController _slide = AnimationController(vsync: this);

  /// A few passes of the "slide this way" hint, then it rests (never an endless animation).
  late final AnimationController _hint = AnimationController(vsync: this, duration: const Duration(milliseconds: 1500));

  /// The little success pop when the slide completes.
  late final AnimationController _success = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 700),
  );

  _Phase _phase = _Phase.idle;
  bool _armed = false;
  bool _focused = false;
  bool _resetWhenCallerIsDone = false;
  bool _hintStarted = false;

  bool get _reduceMotion => MediaQuery.disableAnimationsOf(context);
  bool get _canStart => widget.enabled && !widget.loading && (_phase == _Phase.idle || _phase == _Phase.resetting);
  bool get _busy => _phase == _Phase.confirming;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!_hintStarted && widget.enabled && !widget.loading && !_reduceMotion) {
      _hintStarted = true;
      unawaited(_hint.repeat(count: 3));
    }
    if (_reduceMotion && _hint.isAnimating) _hint.stop();
  }

  @override
  void didUpdateWidget(SlideToConfirm old) {
    super.didUpdateWidget(old);
    if (_resetWhenCallerIsDone && !widget.loading) {
      _resetWhenCallerIsDone = false;
      _reset();
    }
    if (!widget.enabled && _phase == _Phase.dragging) _springBack();
  }

  @override
  void dispose() {
    _slide.dispose();
    _hint.dispose();
    _success.dispose();
    super.dispose();
  }

  Duration _d(int ms) => _reduceMotion ? Duration.zero : Duration(milliseconds: ms);

  void _onDragStart() {
    if (!_canStart) return;
    _slide.stop();
    _hint.stop();
    _armed = _slide.value >= widget.threshold;
    setState(() => _phase = _Phase.dragging);
    Haptics.tap();
  }

  void _onDragUpdate(DragUpdateDetails d, double range, bool rtl) {
    if (_phase != _Phase.dragging) return;
    final dx = rtl ? -d.delta.dx : d.delta.dx;
    _slide.value = (_slide.value + dx / range).clamp(0.0, 1.0);
    final armed = _slide.value >= widget.threshold;
    if (armed != _armed) {
      _armed = armed;
      if (armed) Haptics.tap();
    }
    // All the way to the end: no need to let go first.
    if (_slide.value >= 0.985) unawaited(_confirm());
  }

  void _onDragEnd() {
    if (_phase != _Phase.dragging) return;
    if (_slide.value >= widget.threshold) {
      unawaited(_confirm());
    } else {
      _springBack();
    }
  }

  void _springBack() {
    _phase = _Phase.resetting;
    if (_reduceMotion) {
      _slide.value = 0;
      setState(() => _phase = _Phase.idle);
      return;
    }
    setState(() {});
    unawaited(
      _slide.animateTo(0, duration: _d(260), curve: Curves.easeOutCubic).whenComplete(() {
        if (mounted && _phase == _Phase.resetting) setState(() => _phase = _Phase.idle);
      }),
    );
  }

  /// A tap on the empty track: show how it works instead of doing anything.
  void _nudge() {
    if (_phase != _Phase.idle || !widget.enabled || widget.loading || _reduceMotion) return;
    unawaited(
      _slide
          .animateTo(0.2, duration: const Duration(milliseconds: 170), curve: Curves.easeOut)
          .then(
            (_) => mounted && _phase == _Phase.idle
                ? _slide.animateTo(0, duration: const Duration(milliseconds: 240), curve: Curves.easeOutCubic)
                : null,
          ),
    );
  }

  Future<void> _confirm() async {
    if (_busy || !mounted) return;
    if (!widget.enabled || widget.loading) {
      if (_phase == _Phase.dragging) _springBack();
      return;
    }
    setState(() => _phase = _Phase.confirming);
    Haptics.success();
    _slide.stop();
    unawaited(_slide.animateTo(1, duration: _d(110), curve: Curves.easeOut));
    if (_reduceMotion) {
      _success.value = 1;
    } else {
      unawaited(_success.forward(from: 0));
    }
    try {
      await widget.onConfirmed();
    } catch (error, stack) {
      FlutterError.reportError(FlutterErrorDetails(exception: error, stack: stack, library: 'slide to confirm'));
    }
    if (!mounted) return;
    if (widget.loading) {
      // The caller is still working: come back when it says it is done.
      _resetWhenCallerIsDone = true;
    } else {
      _reset();
    }
  }

  void _reset() {
    if (!mounted) return;
    _phase = _Phase.resetting;
    _success.value = 0;
    _springBack();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = context.colors;
    final palette = context.palette;
    final rtl = context.isRtl;
    final h = widget.height;
    final thumb = h - 8;
    final active = widget.enabled;
    final accent = active ? scheme.primary : palette.muted;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final trackColor = Color.alphaBlend(accent.withValues(alpha: dark ? 0.16 : 0.09), scheme.surface);
    final canTrigger = _canStart;
    return Semantics(
      container: true,
      button: true,
      enabled: canTrigger,
      label: widget.label,
      onTap: canTrigger ? () => unawaited(_confirm()) : null,
      child: ExcludeSemantics(
        child: FocusableActionDetector(
          enabled: canTrigger,
          onShowFocusHighlight: (v) => setState(() => _focused = v),
          actions: {
            ActivateIntent: CallbackAction<ActivateIntent>(
              onInvoke: (_) {
                unawaited(_confirm());
                return null;
              },
            ),
          },
          child: LayoutBuilder(
            builder: (context, constraints) {
              final width = constraints.hasBoundedWidth ? constraints.maxWidth : 280.0;
              final range = math.max(1.0, width - h);
              return SizedBox(
                width: width,
                height: h,
                child: GestureDetector(
                  // The empty track absorbs taps (so a card behind it does not open) and shows how it works.
                  behavior: HitTestBehavior.opaque,
                  onTap: _nudge,
                  child: AnimatedBuilder(
                    animation: Listenable.merge([_slide, _hint, _success]),
                    builder: (context, _) {
                      final p = _slide.value;
                      final win = Curves.easeOut.transform(math.min(1, _success.value * 2.2));
                      final pop = 1 + 0.12 * math.sin(math.pi * math.min(1, _success.value * 2.5));
                      final labelColor = Color.lerp(accent, scheme.onPrimary, win)!;
                      final showCheck = _success.value < 0.5 && !_reduceMotion;
                      return Stack(
                        clipBehavior: Clip.none,
                        children: [
                          Positioned.fill(
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                color: trackColor,
                                borderRadius: BorderRadius.circular(h / 2),
                                border: Border.all(
                                  color: _focused ? scheme.onSurface : accent.withValues(alpha: 0.30),
                                  width: _focused ? 2 : 1.2,
                                ),
                              ),
                            ),
                          ),
                          // What the thumb has already covered.
                          PositionedDirectional(
                            start: 0,
                            top: 0,
                            bottom: 0,
                            width: p * range + h,
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                color: Color.lerp(accent.withValues(alpha: 0.20), accent, win),
                                borderRadius: BorderRadius.circular(h / 2),
                              ),
                            ),
                          ),
                          // The words, and the "slide this way" hint at the far end.
                          Positioned.fill(
                            child: Padding(
                              padding: EdgeInsetsDirectional.only(start: _busy ? 20 : h + 2, end: _busy ? h + 6 : 44),
                              child: Center(
                                child: Opacity(
                                  opacity: _busy ? 1 : (1 - p * 1.8).clamp(0.0, 1.0),
                                  child: Text(
                                    _busy ? (widget.busyLabel ?? widget.label) : widget.label,
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                    textAlign: TextAlign.center,
                                    style: context.text.labelLarge?.copyWith(
                                      color: _busy ? labelColor : (active ? accent : palette.muted),
                                      fontWeight: FontWeight.w700,
                                      fontSize: 15,
                                      height: 1.2,
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ),
                          if (!_busy && active)
                            PositionedDirectional(
                              end: 12,
                              top: 0,
                              bottom: 0,
                              child: Opacity(
                                opacity: (1 - p * 2).clamp(0.0, 1.0),
                                child: _HintChevrons(t: _hint.isAnimating ? _hint.value : null, color: accent),
                              ),
                            ),
                          PositionedDirectional(
                            start: p * range,
                            top: 0,
                            bottom: 0,
                            width: h,
                            child: GestureDetector(
                              key: slideThumbKey,
                              behavior: HitTestBehavior.opaque,
                              dragStartBehavior: DragStartBehavior.down,
                              onHorizontalDragStart: (_) => _onDragStart(),
                              onHorizontalDragUpdate: (d) => _onDragUpdate(d, range, rtl),
                              onHorizontalDragEnd: (_) => _onDragEnd(),
                              onHorizontalDragCancel: _onDragEnd,
                              child: Center(
                                child: Transform.scale(
                                  scale: pop,
                                  child: Container(
                                    width: thumb,
                                    height: thumb,
                                    decoration: BoxDecoration(
                                      shape: BoxShape.circle,
                                      color: Color.lerp(accent, scheme.onPrimary, win),
                                      boxShadow: active && !_busy
                                          ? const [
                                              BoxShadow(color: Color(0x33000000), blurRadius: 8, offset: Offset(0, 2)),
                                            ]
                                          : null,
                                    ),
                                    child: AnimatedSwitcher(
                                      duration: _d(150),
                                      child: _busy
                                          ? (showCheck
                                                ? Icon(
                                                    Icons.check_rounded,
                                                    key: const ValueKey('check'),
                                                    color: accent,
                                                    size: 28,
                                                  )
                                                : SizedBox(
                                                    key: const ValueKey('progress'),
                                                    width: 24,
                                                    height: 24,
                                                    child: Center(
                                                      child: SizedBox(
                                                        width: 22,
                                                        height: 22,
                                                        child: CircularProgressIndicator(
                                                          strokeWidth: 2.6,
                                                          color: accent,
                                                        ),
                                                      ),
                                                    ),
                                                  ))
                                          : (widget.loading
                                                ? SizedBox(
                                                    key: const ValueKey('loading'),
                                                    width: 22,
                                                    height: 22,
                                                    child: CircularProgressIndicator(
                                                      strokeWidth: 2.6,
                                                      color: scheme.onPrimary,
                                                    ),
                                                  )
                                                : Icon(
                                                    Icons.arrow_forward_rounded,
                                                    key: const ValueKey('arrow'),
                                                    color: scheme.onPrimary,
                                                    size: 28,
                                                    textDirection: rtl ? TextDirection.rtl : TextDirection.ltr,
                                                  )),
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ],
                      );
                    },
                  ),
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

/// Three small chevrons pointing the way to slide; a light passes over them while [t] runs (null = at rest).
class _HintChevrons extends StatelessWidget {
  const _HintChevrons({required this.t, required this.color});
  final double? t;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 0; i < 3; i++)
          Opacity(
            opacity: t == null
                ? 0.35 + i * 0.12
                : (0.25 + 0.75 * math.pow(math.sin(math.pi * ((t! * 1.6 - i * 0.2) % 1)), 2)).toDouble(),
            child: Align(widthFactor: 0.5, child: Icon(Icons.chevron_right_rounded, size: 22, color: color)),
          ),
      ],
    );
  }
}
