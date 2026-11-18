import 'dart:async';
import 'dart:math' as math;

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/language_switch.dart';
import 'package:albahar_customer/features/onboarding/presentation/art/art_kit.dart';
import 'package:albahar_customer/features/onboarding/presentation/art/intro_art_window.dart';
import 'package:flutter/material.dart';

/// One page of the first-run intro: an illustration and what it says.
@immutable
class IntroSlide {
  const IntroSlide({required this.title, required this.body, required this.artLabel, required this.layers});
  final String title;
  final String body;
  final String artLabel;
  final List<ArtLayer> layers;
}

/// How far page [index] is from the centre of the screen, in pages (0 = in view), read live from the swipe.
double introPageDelta(PageController controller, int index) {
  if (!controller.hasClients || !controller.position.haveDimensions) return controller.initialPage - index.toDouble();
  return (controller.page ?? controller.initialPage.toDouble()) - index;
}

/// An illustrated intro page. On a phone the picture sits above the words; on a wide window (tablet, landscape, web) they
/// sit side by side. If the words ever need more room than the screen has (large text sizes), the page scrolls.
///
/// The picture and the words are placed from the top of the page, at an offset that does not depend on how long the words are
/// (it is what centring a typical title and three lines would give). So when the words change (the dashboard's text arriving
/// a moment after the page, or a language switch) only the words change: they cross-fade in place and nothing else moves.
class IntroPage extends StatelessWidget {
  const IntroPage({super.key, required this.slide, required this.index, required this.controller});

  final IntroSlide slide;
  final int index;
  final PageController controller;

  /// Height kept for the title and text under the picture on a phone.
  static const double _textReserve = 190;

  /// The height of a typical title and text block at the normal text size: what the page centres around (see above).
  static const double _typicalWords = 150;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, c) {
        final wide = c.maxWidth >= 720 && c.maxWidth >= c.maxHeight * 1.1;
        final compact = c.maxWidth < 360;

        final art = _PageMotion(
          controller: controller,
          index: index,
          child: IntroArtWindow(
            layers: slide.layers,
            semanticLabel: slide.artLabel,
            motion: ArtMotion(listenable: controller, delta: () => introPageDelta(controller, index)),
          ),
        );
        final words = _PageFade(
          controller: controller,
          index: index,
          child: LanguageTransition(
            child: _IntroWords(title: slide.title, body: slide.body, wide: wide, compact: compact),
          ),
        );
        final typicalWords = MediaQuery.textScalerOf(context).scale(_typicalWords);

        if (wide) {
          final artWidth = math.min((c.maxWidth - 96) * 0.55, 520.0);
          final height = math.min(c.maxHeight - AppSpace.md, artWidth * 1.08);
          return ContentWidth(
            maxWidth: 1080,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.xl),
              child: Row(
                children: [
                  Expanded(
                    flex: 11,
                    child: Center(
                      child: SizedBox(width: artWidth, height: height, child: art),
                    ),
                  ),
                  const Gap(AppSpace.xxl),
                  Expanded(
                    flex: 9,
                    child: Align(
                      alignment: AlignmentDirectional.topStart,
                      child: Padding(
                        padding: EdgeInsets.only(top: math.max(0, (c.maxHeight - typicalWords) / 2)),
                        child: SingleChildScrollView(child: words),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          );
        }

        final contentWidth = c.maxWidth >= 600 ? 600.0 : 480.0;
        final artWidth = math.min(c.maxWidth - 2 * AppSpace.lg, contentWidth);
        // The picture takes most of the page, but always leaves room for a title and three lines of text. Larger text needs more
        // than proportionally more room (the lines are taller and there are more of them), so the room for the words grows with the
        // square of the text scale (the gap stays) and larger text makes the picture a little smaller instead of making the page scroll.
        final scale = MediaQuery.textScalerOf(context).scale(1);
        final reserve = AppSpace.lg + (_textReserve - AppSpace.lg) * scale * scale;
        final artHeight = math
            .min(c.maxHeight * 0.62, math.min(c.maxHeight - reserve, artWidth * 1.04))
            .clamp(150.0, 1000.0);
        final top = math.max(0.0, (c.maxHeight - artHeight - AppSpace.lg - typicalWords) / 2);
        return SingleChildScrollView(
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: c.maxHeight),
            child: Align(
              alignment: Alignment.topCenter,
              child: Padding(
                padding: EdgeInsets.fromLTRB(AppSpace.lg, top, AppSpace.lg, 0),
                child: ContentWidth(
                  maxWidth: contentWidth,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      SizedBox(width: artWidth, height: artHeight, child: art),
                      const Gap(AppSpace.lg),
                      words,
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

/// The title and text of one page. When they change while the page is showing (the dashboard's words arriving after the page
/// opened) the old words fade out as the new ones fade in, in the same place. A language switch is not animated here: the page's
/// [LanguageTransition] already fades that, so the old language is gone at once. With reduced motion every change is instant.
class _IntroWords extends StatefulWidget {
  const _IntroWords({required this.title, required this.body, required this.wide, required this.compact});

  final String title;
  final String body;
  final bool wide;
  final bool compact;

  @override
  State<_IntroWords> createState() => _IntroWordsState();
}

class _IntroWordsState extends State<_IntroWords> with SingleTickerProviderStateMixin {
  late final AnimationController _fade = AnimationController(vsync: this, duration: AppDurations.slow, value: 1);
  late final Animation<double> _in = CurvedAnimation(parent: _fade, curve: Curves.easeOutCubic);
  late final Animation<double> _out = Tween<double>(begin: 1, end: 0).animate(_in);

  /// The words that are fading out, while there are any.
  ({String title, String body})? _previous;
  Locale? _locale;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _locale = Localizations.localeOf(context);
  }

  @override
  void didUpdateWidget(_IntroWords oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.title == widget.title && oldWidget.body == widget.body) return;
    final locale = Localizations.localeOf(context);
    final languageChanged = locale != _locale;
    _locale = locale;
    if (languageChanged || MediaQuery.disableAnimationsOf(context)) {
      _previous = null;
      _fade.value = 1;
      return;
    }
    _previous = (title: oldWidget.title, body: oldWidget.body);
    unawaited(
      _fade.forward(from: 0).whenComplete(() {
        if (mounted) setState(() => _previous = null);
      }),
    );
  }

  @override
  void dispose() {
    _fade.dispose();
    super.dispose();
  }

  Widget _words(BuildContext context, String title, String body) {
    final align = widget.wide ? TextAlign.start : TextAlign.center;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: widget.wide ? CrossAxisAlignment.start : CrossAxisAlignment.center,
      children: [
        Semantics(
          header: true,
          child: Text(
            title,
            textAlign: align,
            style: widget.compact ? context.text.headlineMedium : context.text.displaySmall,
          ),
        ),
        const Gap(AppSpace.sm),
        Text(
          body,
          textAlign: align,
          style: (widget.compact ? context.text.bodyMedium : context.text.bodyLarge)?.copyWith(
            color: context.palette.muted,
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final previous = _previous;
    return Stack(
      alignment: widget.wide ? AlignmentDirectional.topStart : Alignment.topCenter,
      children: [
        if (previous != null)
          ExcludeSemantics(
            child: FadeTransition(opacity: _out, child: _words(context, previous.title, previous.body)),
          ),
        FadeTransition(opacity: _in, child: _words(context, widget.title, widget.body)),
      ],
    );
  }
}

/// The words fade out as their page leaves and fade in as it arrives.
class _PageFade extends StatelessWidget {
  const _PageFade({required this.controller, required this.index, required this.child});
  final PageController controller;
  final int index;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.disableAnimationsOf(context)) return child;
    return AnimatedBuilder(
      animation: controller,
      child: child,
      builder: (context, child) {
        final away = introPageDelta(controller, index).abs();
        return Opacity(opacity: (1 - away * 1.3).clamp(0.0, 1.0), child: child);
      },
    );
  }
}

/// The picture settles in slightly as it comes to the middle.
class _PageMotion extends StatelessWidget {
  const _PageMotion({required this.controller, required this.index, required this.child});
  final PageController controller;
  final int index;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.disableAnimationsOf(context)) return child;
    return AnimatedBuilder(
      animation: controller,
      child: child,
      builder: (context, child) {
        final away = introPageDelta(controller, index).abs();
        return Transform.scale(scale: 1 - 0.06 * math.min(away, 1), child: child);
      },
    );
  }
}
