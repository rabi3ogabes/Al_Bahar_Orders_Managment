import 'dart:math' as math;

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';

/// How far the pinned header is collapsed: 0 = fully open (or stretched), 1 = shrunk to the bar. Read inside the header
/// (its buttons, title and sheet edge), which is why they can fade with the scroll without a scroll controller.
double headerCollapse(BuildContext context) {
  final settings = context.dependOnInheritedWidgetOfExactType<FlexibleSpaceBarSettings>();
  if (settings == null) return 1;
  final delta = settings.maxExtent - settings.minExtent;
  if (delta <= 0) return 1;
  return ((settings.maxExtent - settings.currentExtent) / delta).clamp(0.0, 1.0);
}

/// The photo fades out during the last [kToolbarHeight] of the collapse, so the bar is the plain page colour by the end.
double _fadeStart(double extentRange) => extentRange <= 0 ? 1 : math.max(0, 1 - kToolbarHeight / extentRange);

/// Builds a round button over the header: [disc] is its background, a soft disc over the photo that melts away as the
/// header collapses into the plain bar (the icon then sits on the page colour).
class HeaderChrome extends StatelessWidget {
  const HeaderChrome({super.key, required this.builder});

  final Widget Function(BuildContext context, Color disc) builder;

  @override
  Widget build(BuildContext context) {
    final t = Curves.easeIn.transform(headerCollapse(context));
    return builder(context, context.colors.surface.withValues(alpha: 0.92 * (1 - t)));
  }
}

/// A round, readable icon button of the header (back, share…).
class ProductHeaderButton extends StatelessWidget {
  const ProductHeaderButton({super.key, required this.icon, required this.tooltip, required this.onPressed});

  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => HeaderChrome(
    builder: (context, disc) =>
        RoundIconButton(icon: icon, size: 40, tooltip: tooltip, background: disc, onPressed: onPressed),
  );
}

/// The pinned, stretchy app bar of the product page: [background] (the photo gallery, or a skeleton) fills the top of the
/// page and collapses under a bar that stays on screen, with a round back button and [actions] over the picture.
///
/// Scrolling up shrinks the picture (it moves a little slower than the page, a parallax) while the product name fades in on
/// the bar; pulling down at the top stretches the picture. The page sheet's rounded edge sits on the picture and melts away
/// as it collapses. It is the same bar for the loading skeleton, the page and (as [compact], without a picture) the error.
class ProductSliverHeader extends StatelessWidget {
  const ProductSliverHeader({super.key, this.title, this.background, this.actions = const [], this.compact = false});

  /// The product name, shown on the bar once the picture has collapsed.
  final String? title;

  /// What fills the expanded header, behind the buttons. Its bottom [sheetOverlap] is covered by the page sheet.
  final Widget? background;
  final List<Widget> actions;

  /// Only the bar, without a picture (the error page).
  final bool compact;

  /// Height of the rounded page edge that overlaps the bottom of the picture.
  static const double sheetOverlap = AppRadius.sheet;

  /// About 0.9 of the screen width (a square-ish picture), between 280 and 440; never more than 60% of a landscape phone.
  static double expandedHeightFor(BuildContext context) {
    final size = MediaQuery.sizeOf(context);
    final byWidth = (size.width * 0.9).clamp(280.0, 440.0);
    return math.min(byWidth, math.max(220.0, size.height * 0.6));
  }

  /// Closes the page; goes home when there is no page behind (the product was opened from a link).
  static void goBack(BuildContext context) {
    final navigator = Navigator.of(context);
    if (navigator.canPop()) {
      navigator.pop();
    } else {
      GoRouter.maybeOf(context)?.go('/home');
    }
  }

  @override
  Widget build(BuildContext context) {
    return SliverAppBar(
      pinned: true,
      stretch: true,
      expandedHeight: compact ? null : expandedHeightFor(context),
      automaticallyImplyLeading: false,
      leadingWidth: kMinTouchTarget + AppSpace.xs,
      leading: Padding(
        padding: const EdgeInsetsDirectional.only(start: AppSpace.xs),
        child: ProductHeaderButton(
          icon: Icons.arrow_back_rounded,
          tooltip: MaterialLocalizations.of(context).backButtonTooltip,
          onPressed: () => goBack(context),
        ),
      ),
      actions: [...actions, const Gap(AppSpace.xs)],
      backgroundColor: context.pageColor,
      surfaceTintColor: Colors.transparent,
      foregroundColor: context.colors.onSurface,
      elevation: 0,
      scrolledUnderElevation: 0,
      excludeHeaderSemantics: true,
      flexibleSpace: compact ? null : _HeaderSpace(title: title, background: background),
    );
  }
}

class _HeaderSpace extends StatelessWidget {
  const _HeaderSpace({required this.title, required this.background});
  final String? title;
  final Widget? background;

  @override
  Widget build(BuildContext context) {
    return _StatusBarStyle(
      child: Stack(
        fit: StackFit.expand,
        children: [
          FlexibleSpaceBar(
            collapseMode: CollapseMode.parallax,
            stretchModes: const [StretchMode.zoomBackground],
            centerTitle: false,
            expandedTitleScale: 1,
            // Clear of the back button and of the two round buttons at the other end.
            titlePadding: const EdgeInsetsDirectional.only(start: 64, end: 112, bottom: 14),
            title: title == null ? null : _BarTitle(title!),
            background: background,
          ),
          const _SheetEdge(),
          const _BarHairline(),
        ],
      ),
    );
  }
}

/// The status bar icons: white over the photo, the theme's own (dark on light) once the bar is the page colour.
class _StatusBarStyle extends StatelessWidget {
  const _StatusBarStyle({required this.child});
  final Widget child;

  static const _overPhoto = SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.light,
    statusBarBrightness: Brightness.dark,
  );

  @override
  Widget build(BuildContext context) {
    final settings = context.dependOnInheritedWidgetOfExactType<FlexibleSpaceBarSettings>();
    final range = settings == null ? 0.0 : settings.maxExtent - settings.minExtent;
    final fadeStart = _fadeStart(range);
    // Flip half way through the fade, when the photo is no longer what is under the icons.
    final overPhoto = headerCollapse(context) < fadeStart + (1 - fadeStart) / 2;
    final theme = Theme.of(context).appBarTheme.systemOverlayStyle;
    return AnnotatedRegion<SystemUiOverlayStyle>(
      key: const ValueKey('product-status-style'),
      value: overPhoto ? _overPhoto : (theme ?? SystemUiOverlayStyle.dark),
      child: child,
    );
  }
}

/// The product name on the bar: invisible while the picture is open, fading in as the bar takes over.
class _BarTitle extends StatelessWidget {
  const _BarTitle(this.name);
  final String name;

  @override
  Widget build(BuildContext context) {
    final settings = context.dependOnInheritedWidgetOfExactType<FlexibleSpaceBarSettings>();
    final range = settings == null ? 0.0 : settings.maxExtent - settings.minExtent;
    final start = _fadeStart(range);
    final opacity = Interval(math.max(0, start - 0.05), 1).transform(headerCollapse(context));
    return IgnorePointer(
      child: ExcludeSemantics(
        excluding: opacity == 0,
        child: MediaQuery.withClampedTextScaling(
          maxScaleFactor: 1.3,
          child: Opacity(
            key: const ValueKey('product-bar-title'),
            opacity: opacity,
            child: Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w700, color: context.colors.onSurface),
            ),
          ),
        ),
      ),
    );
  }
}

/// The rounded top edge of the page sheet, drawn over the bottom of the picture: the page looks like a sheet laid over the
/// photo. It fades out while the header collapses (a bar needs no rounded edge).
class _SheetEdge extends StatelessWidget {
  const _SheetEdge();

  @override
  Widget build(BuildContext context) {
    final opacity = 1 - const Interval(0.55, 0.9).transform(headerCollapse(context));
    return Positioned(
      left: 0,
      right: 0,
      bottom: 0,
      height: ProductSliverHeader.sheetOverlap,
      child: IgnorePointer(
        child: Opacity(
          opacity: opacity,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: context.pageColor,
              borderRadius: const BorderRadius.vertical(top: Radius.circular(AppRadius.sheet)),
            ),
          ),
        ),
      ),
    );
  }
}

/// A hairline under the bar once it is collapsed, so content passing under it has a clear edge.
class _BarHairline extends StatelessWidget {
  const _BarHairline();

  @override
  Widget build(BuildContext context) {
    final opacity = const Interval(0.9, 1).transform(headerCollapse(context));
    return Positioned(
      left: 0,
      right: 0,
      bottom: 0,
      height: 1,
      child: IgnorePointer(
        child: Opacity(
          opacity: opacity,
          child: ColoredBox(color: context.palette.line),
        ),
      ),
    );
  }
}
