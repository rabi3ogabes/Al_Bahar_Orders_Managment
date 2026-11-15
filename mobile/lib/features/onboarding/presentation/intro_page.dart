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
class IntroPage extends StatelessWidget {
  const IntroPage({super.key, required this.slide, required this.index, required this.controller});

  final IntroSlide slide;
  final int index;
  final PageController controller;

  /// Height kept for the title and text under the picture on a phone.
  static const double _textReserve = 190;

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
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: wide ? CrossAxisAlignment.start : CrossAxisAlignment.center,
              children: [
                Semantics(
                  header: true,
                  child: Text(
                    slide.title,
                    textAlign: wide ? TextAlign.start : TextAlign.center,
                    style: compact ? context.text.headlineMedium : context.text.displaySmall,
                  ),
                ),
                const Gap(AppSpace.sm),
                Text(
                  slide.body,
                  textAlign: wide ? TextAlign.start : TextAlign.center,
                  style: (compact ? context.text.bodyMedium : context.text.bodyLarge)?.copyWith(
                    color: context.palette.muted,
                  ),
                ),
              ],
            ),
          ),
        );

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
                    child: Center(child: SingleChildScrollView(child: words)),
                  ),
                ],
              ),
            ),
          );
        }

        final contentWidth = c.maxWidth >= 600 ? 600.0 : 480.0;
        final artWidth = math.min(c.maxWidth - 2 * AppSpace.lg, contentWidth);
        // The picture takes most of the page, but always leaves room for a title and three lines of text.
        final artHeight = math
            .min(c.maxHeight * 0.62, math.min(c.maxHeight - _textReserve, artWidth * 1.04))
            .clamp(150.0, 1000.0);
        return SingleChildScrollView(
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: c.maxHeight),
            child: Center(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpace.lg),
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
