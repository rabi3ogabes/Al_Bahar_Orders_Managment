import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../l10n/l10n.dart';
import '../theme/tokens.dart';
import '../utils/haptics.dart';
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
    return Padding(
      padding: padding ?? const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, AppSpace.lg, AppSpace.xs, AppSpace.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(header: true, child: Text(title, style: context.text.headlineSmall)),
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
                  // Directional chevron flips automatically in RTL.
                  const Icon(Icons.chevron_right_rounded, size: 18),
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
    return Container(
      padding: EdgeInsetsDirectional.symmetric(horizontal: dense ? 8 : 10, vertical: dense ? 3 : 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[Icon(icon, size: dense ? 12 : 14, color: color), const Gap(4)],
          Flexible(
            child: Text(
              label,
              overflow: TextOverflow.ellipsis,
              style: (dense ? context.text.labelSmall : context.text.labelMedium)?.copyWith(
                color: color,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.2,
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
  'ready_for_pickup' || 'pickup_ready' || 'out_for_delivery' || 'dispatched' || 'open' || 'live' => StatusTone.accent,
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
          SizedBox(
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
          ),
          btn(Icons.add_rounded, l10n.increaseQuantity, canInc, value + 1),
        ],
      ),
    );
  }
}

/// Consistent modal bottom sheet.
Future<T?> showAppBottomSheet<T>({
  required BuildContext context,
  required WidgetBuilder builder,
  String? title,
  bool scrollable = true,
}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    constraints: const BoxConstraints(maxWidth: 640),
    builder: (sheetContext) {
      final content = Padding(
        padding: EdgeInsetsDirectional.only(
          start: AppSpace.gutter,
          end: AppSpace.gutter,
          bottom: MediaQuery.viewInsetsOf(sheetContext).bottom + AppSpace.lg,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (title != null) ...[
              Semantics(header: true, child: Text(title, style: sheetContext.text.headlineSmall)),
              const Gap(AppSpace.md),
            ],
            Flexible(child: builder(sheetContext)),
          ],
        ),
      );
      return scrollable ? SingleChildScrollView(child: content) : content;
    },
  );
}

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
        TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(l10n.cancel)),
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

/// Wordmark used on splash / auth screens.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 1, this.color});
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final ink = color ?? context.colors.onSurface;
    final isAr = context.localeCode == 'ar';
    return Semantics(
      label: context.l10n.appFullName,
      excludeSemantics: true,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 56 * size,
            height: 56 * size,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              border: Border.all(color: context.palette.brass, width: 1.4),
            ),
            alignment: Alignment.center,
            child: Text(
              isAr ? 'ب' : 'B',
              style: context.text.headlineMedium?.copyWith(
                color: context.palette.brass,
                fontSize: 26 * size,
                height: 1,
              ),
            ),
          ),
          SizedBox(height: 14 * size),
          Text(
            isAr ? 'البحر' : 'AL BAHAR',
            style: context.text.displaySmall?.copyWith(
              color: ink,
              fontSize: 30 * size,
              letterSpacing: isAr ? 0 : 6 * size,
            ),
          ),
          SizedBox(height: 4 * size),
          Text(
            isAr ? 'للمنزل والمعيشة' : 'HOME & LIVING',
            style: context.text.labelSmall?.copyWith(
              color: context.palette.brass,
              letterSpacing: isAr ? 0 : 4 * size,
              fontSize: 11 * size,
            ),
          ),
        ],
      ),
    );
  }
}
