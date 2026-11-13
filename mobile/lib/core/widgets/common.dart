import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../l10n/l10n.dart';
import '../theme/app_theme.dart';
import '../theme/design_tokens.dart';
import '../theme/tokens.dart';
import '../utils/haptics.dart';
import 'side_panel.dart';
import 'skeleton.dart';

/// Cached network image with skeleton placeholder and graceful fallback.
class AppNetworkImage extends StatelessWidget {
  const AppNetworkImage({
    super.key,
    required this.url,
    this.fit = BoxFit.cover,
    this.semanticLabel,
    this.borderRadius,
    this.width,
    this.height,
    this.memCacheWidth,
  });

  final String? url;
  final BoxFit fit;
  final String? semanticLabel;
  final BorderRadius? borderRadius;
  final double? width;
  final double? height;
  final int? memCacheWidth;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    // On-brand tile while there is no photo (never an empty grey box): warm gradient + a quiet line icon.
    Widget fallback() => Container(
      width: width,
      height: height,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [palette.brassTint, palette.surfaceMuted],
        ),
      ),
      child: Icon(Icons.inventory_2_outlined, color: context.colors.primary.withValues(alpha: 0.55), size: 30),
    );

    Widget image;
    if (url == null || url!.isEmpty) {
      image = fallback();
    } else {
      final dpr = MediaQuery.devicePixelRatioOf(context);
      image = CachedNetworkImage(
        imageUrl: url!,
        fit: fit,
        width: width,
        height: height,
        memCacheWidth: memCacheWidth ?? (width != null ? (width! * dpr).round() : null),
        fadeInDuration: AppDurations.medium,
        placeholder: (_, _) => const Skeleton(radius: 0),
        errorWidget: (_, _, _) => fallback(),
      );
    }
    if (semanticLabel != null) {
      image = Semantics(image: true, label: semanticLabel, child: image);
    } else {
      image = ExcludeSemantics(child: image);
    }
    if (borderRadius != null) image = ClipRRect(borderRadius: borderRadius!, child: image);
    return image;
  }
}

/// Section title row with optional "See all" action.
class SectionHeader extends StatelessWidget {
  const SectionHeader({super.key, required this.title, this.subtitle, this.actionLabel, this.onAction, this.padding});

  final String title;
  final String? subtitle;
  final String? actionLabel;
  final VoidCallback? onAction;
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    final design = context.design;
    final arabic = context.localeCode == 'ar';
    final titleStyle = design.isFresh
        ? context.text.headlineSmall?.copyWith(
            fontFamily: arabic ? AppFonts.arabic : AppFonts.display,
            fontWeight: FontWeight.w700,
          )
        : context.text.headlineSmall;
    return Padding(
      padding:
          padding ??
          EdgeInsetsDirectional.fromSTEB(design.gutter, AppSpace.lg * design.spacing, AppSpace.xs, AppSpace.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(header: true, child: Text(title, style: titleStyle)),
                if (subtitle != null) ...[const Gap(2), Text(subtitle!, style: context.text.bodySmall)],
              ],
            ),
          ),
          if (onAction != null)
            TextButton(
              onPressed: onAction,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(actionLabel ?? context.l10n.seeAll),
                  const Gap(2),
                  // Directional icons flip automatically in RTL.
                  Icon(design.isFresh ? Icons.arrow_forward_rounded : Icons.chevron_right_rounded, size: 18),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Tone for [StatusChip].
enum StatusTone { neutral, info, success, warning, danger, accent }

class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.label, this.tone = StatusTone.neutral, this.icon, this.dense = false});

  final String label;
  final StatusTone tone;
  final IconData? icon;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    final color = switch (tone) {
      StatusTone.neutral => p.muted,
      StatusTone.info => p.info,
      StatusTone.success => p.success,
      StatusTone.warning => p.warning,
      StatusTone.danger => p.danger,
      StatusTone.accent => p.brass,
    };
    // Soft pastel pill with darker text of the same hue (light); a translucent tint on dark.
    final dark = Theme.of(context).brightness == Brightness.dark;
    final (Color bg, Color fg) = dark
        ? (color.withValues(alpha: 0.16), color)
        : switch (tone) {
            StatusTone.neutral => (const Color(0xFFECEEF1), const Color(0xFF4B5563)),
            StatusTone.info => (AppPalette.infoTint, AppPalette.info),
            StatusTone.success => (AppPalette.successTint, AppPalette.success),
            StatusTone.warning => (AppPalette.warningTint, AppPalette.warning),
            StatusTone.danger => (AppPalette.dangerTint, AppPalette.danger),
            StatusTone.accent => (p.brassTint, p.brassDeep),
          };
    return Container(
      padding: EdgeInsetsDirectional.symmetric(horizontal: dense ? 8 : 10, vertical: dense ? 3 : 5),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.pill)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[Icon(icon, size: dense ? 12 : 14, color: fg), const Gap(4)],
          Flexible(
            child: Text(
              label,
              overflow: TextOverflow.ellipsis,
              style: (dense ? context.text.labelSmall : context.text.labelMedium)?.copyWith(
                color: fg,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.1,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Maps API order / fulfilment / payment status codes to tones.
StatusTone toneForStatus(String? status) => switch (status) {
  'completed' ||
  'delivered' ||
  'picked_up' ||
  'paid' ||
  'won' ||
  'leading' ||
  'sold' ||
  'resolved' => StatusTone.success,
  'out_for_delivery' || 'dispatched' => StatusTone.info,
  'ready_for_pickup' || 'pickup_ready' || 'open' || 'live' => StatusTone.accent,
  'pending_payment' ||
  'cancellation_requested' ||
  'pending' ||
  'processing' ||
  'outbid' ||
  'closing' ||
  'awaiting_customer' ||
  'pending_customer' => StatusTone.warning,
  'cancelled' ||
  'failed' ||
  'expired' ||
  'returned' ||
  'lost' ||
  'unsold' ||
  'partially_cancelled' => StatusTone.danger,
  'confirmed' ||
  'accepted' ||
  'preparing' ||
  'ready' ||
  'partially_fulfilled' ||
  'upcoming' ||
  'scheduled' => StatusTone.info,
  _ => StatusTone.neutral,
};

/// Minus / value / plus stepper with ≥48dp targets.
class QuantityStepper extends StatelessWidget {
  const QuantityStepper({
    super.key,
    required this.value,
    required this.onChanged,
    this.min = 1,
    this.max = 99,
    this.busy = false,
    this.compact = false,
  });

  final int value;
  final ValueChanged<int>? onChanged;
  final int min;
  final int max;
  final bool busy;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final canDec = onChanged != null && !busy && value > min;
    final canInc = onChanged != null && !busy && value < max;
    Widget btn(IconData icon, String label, bool enabled, int next) => Semantics(
      button: true,
      enabled: enabled,
      label: label,
      child: InkResponse(
        radius: 24,
        onTap: enabled
            ? () {
                Haptics.tap();
                onChanged!(next);
              }
            : null,
        child: SizedBox(
          width: kMinTouchTarget,
          height: kMinTouchTarget,
          child: Icon(icon, size: 18, color: enabled ? context.colors.onSurface : palette.muted.withValues(alpha: 0.4)),
        ),
      ),
    );

    final fresh = context.design.isFresh;
    // Fresh: two round buttons (a soft minus, a solid plus) around the number.
    Widget round(IconData icon, String label, bool enabled, int next, {required bool solid}) => Semantics(
      button: true,
      enabled: enabled,
      label: label,
      excludeSemantics: true,
      child: InkResponse(
        radius: 26,
        onTap: enabled
            ? () {
                Haptics.tap();
                onChanged!(next);
              }
            : null,
        child: SizedBox(
          width: kMinTouchTarget,
          height: kMinTouchTarget,
          child: Center(
            child: Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: !enabled ? palette.line : (solid ? context.colors.primary : palette.tint),
              ),
              child: Icon(
                icon,
                size: 18,
                color: !enabled
                    ? palette.muted.withValues(alpha: 0.5)
                    : (solid ? context.colors.onPrimary : context.colors.primary),
              ),
            ),
          ),
        ),
      ),
    );
    final number = SizedBox(
      width: compact ? 28 : 36,
      child: Center(
        heightFactor: 1,
        child: busy
            ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
            : AnimatedSwitcher(
                duration: AppDurations.fast,
                transitionBuilder: (c, a) => ScaleTransition(scale: a, child: c),
                child: Text(
                  '$value',
                  key: ValueKey(value),
                  style: context.text.titleSmall,
                  semanticsLabel: '${l10n.quantity} $value',
                ),
              ),
      ),
    );
    if (fresh) {
      return Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          round(Icons.remove_rounded, l10n.decreaseQuantity, canDec, value - 1, solid: false),
          number,
          round(Icons.add_rounded, l10n.increaseQuantity, canInc, value + 1, solid: true),
        ],
      );
    }

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: palette.line),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        color: context.colors.surface,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          btn(Icons.remove_rounded, l10n.decreaseQuantity, canDec, value - 1),
          number,
          btn(Icons.add_rounded, l10n.increaseQuantity, canInc, value + 1),
        ],
      ),
    );
  }
}

/// The older name of [showSidePanel], kept so existing call sites open the same window (a sheet on phones, an end-side
/// panel on wide screens). New code calls [showSidePanel] directly.
Future<T?> showAppBottomSheet<T>({
  required BuildContext context,
  required WidgetBuilder builder,
  String? title,
  bool scrollable = true,
  bool isForm = false,
}) => showSidePanel<T>(
  context: context,
  title: title ?? '',
  builder: builder,
  scrollable: scrollable,
  isForm: isForm,
);

/// Confirmation dialog for destructive or important actions.
Future<bool> confirmAction(
  BuildContext context, {
  required String title,
  String? message,
  String? confirmLabel,
  bool destructive = false,
}) async {
  final l10n = context.l10n;
  final result = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(title),
      content: message == null ? null : Text(message),
      actions: [
        TextButton(autofocus: true, onPressed: () => Navigator.of(ctx).pop(false), child: Text(l10n.cancel)),
        FilledButton(
          style: destructive ? FilledButton.styleFrom(backgroundColor: ctx.palette.danger) : null,
          onPressed: () {
            if (destructive) Haptics.heavy();
            Navigator.of(ctx).pop(true);
          },
          child: Text(confirmLabel ?? l10n.confirm),
        ),
      ],
    ),
  );
  return result ?? false;
}

/// Constrains content width on tablets / web for comfortable reading.
class ContentWidth extends StatelessWidget {
  const ContentWidth({super.key, required this.child, this.maxWidth = 720});
  final Widget child;
  final double maxWidth;

  @override
  Widget build(BuildContext context) => Align(
    alignment: AlignmentDirectional.topCenter,
    // Size to the child vertically (safe inside bottom bars / sheets).
    heightFactor: 1,
    child: ConstrainedBox(
      constraints: BoxConstraints(maxWidth: maxWidth),
      child: child,
    ),
  );
}

/// Titled surface card used for grouped content.
class SurfaceCard extends StatelessWidget {
  const SurfaceCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(AppSpace.md),
    this.onTap,
    this.color,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) => Card(
    color: color,
    clipBehavior: Clip.antiAlias,
    child: InkWell(
      onTap: onTap,
      child: Padding(padding: padding, child: child),
    ),
  );
}

/// Key/value row used in summaries (left label, right value).
class SummaryRow extends StatelessWidget {
  const SummaryRow({super.key, required this.label, required this.value, this.emphasize = false, this.valueColor});

  final String label;
  final String value;
  final bool emphasize;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    final style = emphasize ? context.text.titleMedium : context.text.bodyMedium;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(label, style: style?.copyWith(color: emphasize ? null : context.palette.muted)),
          ),
          const Gap(AppSpace.sm),
          Text(
            value,
            style: style?.copyWith(color: valueColor, fontWeight: emphasize ? FontWeight.w700 : FontWeight.w500),
          ),
        ],
      ),
    );
  }
}

/// Wave mark: navy disc, gold sun and two waves. Same geometry as the web logo (64-unit box).
class LogoMarkPainter extends CustomPainter {
  const LogoMarkPainter({
    this.disc = AppPalette.navy,
    this.sun = AppPalette.brass,
    this.waveColor = AppPalette.goldSoft,
    this.waveColor2 = AppPalette.ivory,
  });

  final Color disc;
  final Color sun;
  final Color waveColor;
  final Color waveColor2;

  @override
  void paint(Canvas canvas, Size size) {
    final k = size.width / 64;
    canvas.drawCircle(Offset(32 * k, 32 * k), 32 * k, Paint()..color = disc);
    canvas.drawCircle(Offset(32 * k, 22 * k), 7 * k, Paint()..color = sun);
    Path wave(double y) {
      final path = Path()..moveTo(10 * k, y * k);
      for (var i = 0; i < 4; i++) {
        final dir = i.isEven ? -1.0 : 1.0;
        path.relativeCubicTo(3.4 * k, 5 * dir * k, 7.3 * k, 5 * dir * k, 10.75 * k, 0);
      }
      return path;
    }

    Paint stroke(Color c) => Paint()
      ..color = c
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3 * k
      ..strokeCap = StrokeCap.round;
    canvas.drawPath(wave(37), stroke(waveColor));
    canvas.drawPath(wave(47), stroke(waveColor2.withValues(alpha: 0.9)));
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// Bilingual wordmark used on splash / auth screens: mark, Arabic name, Latin name.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 1, this.color});
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final ink = color ?? context.colors.onSurface;
    return Semantics(
      label: context.l10n.appFullName,
      excludeSemantics: true,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            width: 68 * size,
            height: 68 * size,
            child: CustomPaint(
              painter: LogoMarkPainter(
                disc: context.colors.primary,
                sun: context.palette.brass,
                waveColor: context.palette.onPrimarySoft,
                waveColor2: context.colors.surfaceContainerLowest,
              ),
            ),
          ),
          SizedBox(height: 14 * size),
          Text(
            'البحار',
            textDirection: TextDirection.rtl,
            style: context.text.displaySmall?.copyWith(color: ink, fontSize: 38 * size, height: 1.1, letterSpacing: 0),
          ),
          SizedBox(height: 2 * size),
          Text(
            'AL BAHAR',
            style: context.text.labelMedium?.copyWith(
              color: context.palette.brassDeep,
              letterSpacing: 5 * size,
              fontSize: 12 * size,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

/// Three-step progress header (Bag → Delivery → Pay). [current] is 0-based; earlier steps show a tick.
class CheckoutSteps extends StatelessWidget {
  const CheckoutSteps({super.key, required this.current, required this.labels});
  final int current;
  final List<String> labels;

  @override
  Widget build(BuildContext context) {
    final navy = context.colors.primary;
    Widget dot(int i) {
      final done = i < current;
      final active = i == current;
      final size = active ? 28.0 : 24.0;
      return Container(
        width: size,
        height: size,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: done || active ? navy : Colors.transparent,
          border: done || active ? null : Border.all(color: context.palette.muted.withValues(alpha: 0.5), width: 1.5),
          boxShadow: active ? [BoxShadow(color: navy.withValues(alpha: 0.16), spreadRadius: 4)] : null,
        ),
        child: done
            ? Icon(Icons.check_rounded, size: 14, color: context.colors.onPrimary)
            : Text(
                '${i + 1}',
                style: context.text.labelSmall?.copyWith(
                  color: active ? context.colors.onPrimary : context.palette.muted,
                  letterSpacing: 0,
                ),
              ),
      );
    }

    // Large text: only the current step keeps its label, so the row never overflows.
    final compact = MediaQuery.textScalerOf(context).scale(10) > 11;
    return Semantics(
      label: labels[current],
      child: Row(
        children: [
          for (var i = 0; i < labels.length; i++) ...[
            dot(i),
            if (!compact || i == current) ...[
              const Gap(6),
              Flexible(
                child: Text(
                  labels[i],
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: context.text.labelMedium?.copyWith(
                    color: i <= current ? navy : context.palette.muted,
                    fontWeight: i == current ? FontWeight.w600 : FontWeight.w500,
                    letterSpacing: 0,
                  ),
                ),
              ),
            ],
            if (i < labels.length - 1)
              Expanded(
                child: Container(
                  height: 2,
                  margin: const EdgeInsets.symmetric(horizontal: 8),
                  color: i < current ? navy : context.palette.line,
                ),
              ),
          ],
        ],
      ),
    );
  }
}
