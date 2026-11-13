import 'dart:math' as math;

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/contrast.dart';
import 'package:albahar_customer/core/theme/design_tokens.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:flutter/material.dart';

/// Cream-to-pale-primary gradient with a few quiet leaf and blob shapes in the corners. Drawn with one [CustomPaint]
/// (no image assets) behind a [RepaintBoundary], so it is painted once and costs nothing while scrolling.
class FreshBackground extends StatelessWidget {
  const FreshBackground({super.key});

  @override
  Widget build(BuildContext context) {
    final scheme = context.colors;
    final dark = context.theme.brightness == Brightness.dark;
    return ExcludeSemantics(
      child: RepaintBoundary(
        child: CustomPaint(
          painter: FreshBackdropPainter(
            base: scheme.surfaceContainerLowest,
            tint: scheme.primary,
            dark: dark,
            rtl: context.isRtl,
          ),
          child: const SizedBox.expand(),
        ),
      ),
    );
  }
}

class FreshBackdropPainter extends CustomPainter {
  const FreshBackdropPainter({required this.base, required this.tint, required this.dark, required this.rtl});

  final Color base;
  final Color tint;
  final bool dark;
  final bool rtl;

  @override
  void paint(Canvas canvas, Size size) {
    if (size.isEmpty) return;
    final wash = dark ? 0.10 : 0.12;
    final rect = Offset.zero & size;
    canvas.drawRect(
      rect,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color.lerp(base, tint, wash)!, base, Color.lerp(base, tint, wash * 0.5)!],
          stops: const [0, 0.55, 1],
        ).createShader(rect),
    );
    final shape = Paint()..color = tint.withValues(alpha: dark ? 0.07 : 0.07);
    final soft = Paint()..color = tint.withValues(alpha: dark ? 0.05 : 0.05);
    // "End" corner is the right in LTR and the left in RTL.
    final endX = rtl ? 0.0 : size.width;
    final dir = rtl ? -1.0 : 1.0;
    canvas.drawCircle(Offset(endX, 0), size.width * 0.34, soft);
    _leaf(canvas, Offset(endX - dir * 6, 36), 118, rtl ? math.pi * 0.78 : math.pi * 0.22, shape);
    _leaf(canvas, Offset(endX - dir * 46, 8), 84, rtl ? math.pi * 0.62 : math.pi * 0.38, shape);
    final startX = rtl ? size.width : 0.0;
    canvas.drawCircle(Offset(startX, size.height), size.width * 0.3, soft);
    _leaf(canvas, Offset(startX + dir * 10, size.height - 24), 104, rtl ? -math.pi * 0.78 : -math.pi * 0.22, shape);
    _leaf(canvas, Offset(startX + dir * 58, size.height - 6), 76, rtl ? -math.pi * 0.6 : -math.pi * 0.4, shape);
  }

  void _leaf(Canvas canvas, Offset at, double length, double angle, Paint paint) {
    canvas.save();
    canvas.translate(at.dx, at.dy);
    canvas.rotate(angle);
    final path = Path()
      ..quadraticBezierTo(length * 0.3, -length * 0.4, length, 0)
      ..quadraticBezierTo(length * 0.3, length * 0.4, 0, 0);
    canvas.drawPath(path, paint);
    canvas.restore();
  }

  @override
  bool shouldRepaint(FreshBackdropPainter old) =>
      old.base != base || old.tint != tint || old.dark != dark || old.rtl != rtl;
}

/// A [Scaffold] that follows the chosen design: with `background: gradient` it paints [FreshBackground] behind the page,
/// and with `header: brand` it swaps the app bar for [header] (when the screen provides one).
/// Everything else is the normal [Scaffold], so a screen only changes `Scaffold(` to `FreshScaffold(`.
class FreshScaffold extends StatelessWidget {
  const FreshScaffold({
    super.key,
    this.appBar,
    this.header,
    this.body,
    this.bottomNavigationBar,
    this.floatingActionButton,
    this.floatingActionButtonLocation,
    this.resizeToAvoidBottomInset,
    this.backgroundColor,
  });

  /// The classic app bar.
  final PreferredSizeWidget? appBar;

  /// The brand header used instead of [appBar] when the design asks for it.
  final Widget? header;
  final Widget? body;
  final Widget? bottomNavigationBar;
  final Widget? floatingActionButton;
  final FloatingActionButtonLocation? floatingActionButtonLocation;
  final bool? resizeToAvoidBottomInset;
  final Color? backgroundColor;

  @override
  Widget build(BuildContext context) {
    final design = context.design;
    final useHeader = design.brandHeader && header != null;
    final gradient = design.gradientBackground;
    final content = body ?? const SizedBox.shrink();
    final scaffold = Scaffold(
      backgroundColor: gradient ? Colors.transparent : backgroundColor,
      appBar: useHeader ? null : appBar,
      body: useHeader
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                header!,
                Expanded(child: content),
              ],
            )
          : content,
      bottomNavigationBar: bottomNavigationBar,
      floatingActionButton: floatingActionButton,
      floatingActionButtonLocation: floatingActionButtonLocation,
      resizeToAvoidBottomInset: resizeToAvoidBottomInset,
    );
    if (!gradient) return scaffold;
    return Stack(
      fit: StackFit.expand,
      children: [
        const Positioned.fill(child: FreshBackground()),
        scaffold,
      ],
    );
  }
}

/// The brand header: the Al Bahar mark top-left, a bold title with a one-line muted tagline below, and optional round
/// icon buttons on the other side. Shows a back button by itself when the screen was pushed.
class FreshHeader extends StatelessWidget {
  const FreshHeader({super.key, required this.title, this.tagline, this.trailing = const [], this.showLogo = true});

  final String title;
  final String? tagline;
  final List<Widget> trailing;
  final bool showLogo;

  @override
  Widget build(BuildContext context) {
    final design = context.design;
    final arabic = context.localeCode == 'ar';
    final canPop = Navigator.maybeOf(context)?.canPop() ?? false;
    final titleStyle = context.text.headlineLarge?.copyWith(
      fontFamily: arabic ? AppFonts.arabic : AppFonts.display,
      fontWeight: FontWeight.w700,
      height: arabic ? 1.3 : 1.15,
    );
    return SafeArea(
      bottom: false,
      child: Padding(
        padding: EdgeInsetsDirectional.fromSTEB(design.gutter, AppSpace.sm, design.gutter, AppSpace.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                if (canPop) ...[
                  RoundIconButton(
                    icon: Icons.arrow_back_rounded,
                    tooltip: MaterialLocalizations.of(context).backButtonTooltip,
                    onPressed: () => Navigator.maybeOf(context)?.maybePop(),
                  ),
                  const Gap(AppSpace.xs),
                ],
                if (showLogo) const FreshLogo(),
                const Spacer(),
                for (final w in trailing) w,
              ],
            ),
            const Gap(AppSpace.sm),
            Semantics(
              header: true,
              child: Text(title, maxLines: 2, overflow: TextOverflow.ellipsis, style: titleStyle),
            ),
            if (tagline != null && tagline!.isNotEmpty) ...[
              const Gap(2),
              Text(
                tagline!,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: context.text.bodyMedium?.copyWith(color: context.palette.muted),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// The brand mark with the short name, as used in the fresh header.
class FreshLogo extends StatelessWidget {
  const FreshLogo({super.key});

  @override
  Widget build(BuildContext context) => Semantics(
    label: context.l10n.appName,
    excludeSemantics: true,
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: 30,
          height: 30,
          child: CustomPaint(
            painter: LogoMarkPainter(
              disc: context.colors.primary,
              sun: context.palette.brass,
              waveColor: context.palette.onPrimarySoft,
              waveColor2: context.colors.surfaceContainerLowest,
            ),
          ),
        ),
        const Gap(AppSpace.xs),
        Text(
          context.l10n.appName,
          style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w700, color: context.colors.primary),
        ),
      ],
    ),
  );
}

/// A round header action (bell, filter...) with a soft shadow, 48dp touch target.
class FreshHeaderAction extends StatelessWidget {
  const FreshHeaderAction({super.key, required this.icon, required this.tooltip, required this.onPressed, this.badge});

  final Widget icon;
  final String tooltip;
  final VoidCallback onPressed;
  final int? badge;

  @override
  Widget build(BuildContext context) {
    final count = badge ?? 0;
    return Padding(
      padding: const EdgeInsetsDirectional.only(start: AppSpace.xs),
      child: DecoratedBox(
        decoration: BoxDecoration(color: context.colors.surface, shape: BoxShape.circle, boxShadow: kSoftShadow),
        child: IconButton(
          tooltip: tooltip,
          onPressed: onPressed,
          icon: Badge.count(
            count: count,
            isLabelVisible: count > 0,
            backgroundColor: context.palette.brass,
            textColor: context.colors.onSecondary,
            child: icon,
          ),
        ),
      ),
    );
  }
}

/// Circular tinted badge with an icon: the leading mark of list rows and cards in the fresh design.
class IconBubble extends StatelessWidget {
  const IconBubble({super.key, required this.icon, this.size = 44, this.filled = false});

  final IconData icon;
  final double size;

  /// Solid primary circle with a white icon instead of the soft tint.
  final bool filled;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(color: filled ? context.colors.primary : context.palette.tint, shape: BoxShape.circle),
    child: Icon(icon, size: size * 0.5, color: filled ? context.colors.onPrimary : context.colors.primary),
  );
}

/// List row: icon bubble, title, muted subtitle and a chevron, on a soft card.
class FreshListRow extends StatelessWidget {
  const FreshListRow({super.key, required this.icon, required this.title, this.subtitle, this.onTap, this.trailing});

  final IconData icon;
  final String title;
  final String? subtitle;
  final VoidCallback? onTap;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => SurfaceCard(
    onTap: onTap,
    padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.md, vertical: AppSpace.sm),
    child: ConstrainedBox(
      constraints: const BoxConstraints(minHeight: kMinTouchTarget),
      child: Row(
        children: [
          IconBubble(icon: icon),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: context.text.titleSmall),
                if (subtitle != null)
                  Text(subtitle!, maxLines: 1, overflow: TextOverflow.ellipsis, style: context.text.bodySmall),
              ],
            ),
          ),
          trailing ?? Icon(Icons.chevron_right_rounded, color: context.palette.muted),
        ],
      ),
    ),
  );
}

/// Gradient strip with a headline and a pill button: the fresh promo block at the bottom of a list.
class PromoStrip extends StatelessWidget {
  const PromoStrip({super.key, required this.title, this.subtitle, required this.actionLabel, this.onTap});

  final String title;
  final String? subtitle;
  final String actionLabel;

  /// Without a link there is nothing to open, so the button is left out.
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final primary = context.colors.primary;
    final from = Color.lerp(primary, Colors.black, 0.28)!;
    final to = primary;
    final fg = Contrast.onGradient(from, to);
    final onFg = Contrast.onColor(fg);
    return Semantics(
      container: true,
      child: Container(
        padding: const EdgeInsetsDirectional.all(AppSpace.md),
        decoration: BoxDecoration(
          borderRadius: context.design.cardBorderRadius,
          gradient: LinearGradient(
            begin: AlignmentDirectional.topStart,
            end: AlignmentDirectional.bottomEnd,
            colors: [from, to],
          ),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: context.text.titleMedium?.copyWith(color: fg, fontWeight: FontWeight.w700),
                  ),
                  if (subtitle != null)
                    Text(subtitle!, style: context.text.bodySmall?.copyWith(color: fg.withValues(alpha: 0.9))),
                ],
              ),
            ),
            if (onTap != null) ...[
              const Gap(AppSpace.sm),
              Material(
                color: fg,
                shape: const StadiumBorder(),
                child: InkWell(
                  customBorder: const StadiumBorder(),
                  onTap: onTap,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(minHeight: kMinTouchTarget),
                    child: Padding(
                      padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.md),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            actionLabel,
                            style: context.text.labelLarge?.copyWith(color: onFg, fontWeight: FontWeight.w700),
                          ),
                          const Gap(4),
                          Icon(Icons.arrow_forward_rounded, size: 18, color: onFg),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
