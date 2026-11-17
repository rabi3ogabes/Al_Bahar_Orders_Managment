import 'package:flutter/material.dart';

import '../theme/design_tokens.dart';
import '../theme/tokens.dart';
import '../utils/haptics.dart';

enum AppButtonVariant { primary, accent, secondary, ghost, destructive }

/// Primary call-to-action button with loading state and haptics.
class AppButton extends StatelessWidget {
  const AppButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.variant = AppButtonVariant.primary,
    this.icon,
    this.loading = false,
    this.expand = true,
    this.haptic = true,
    this.semanticLabel,
    this.labelMaxLines = 1,
  });

  const AppButton.accent({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = true,
    this.haptic = true,
    this.semanticLabel,
  }) : variant = AppButtonVariant.accent,
       labelMaxLines = 1;

  const AppButton.secondary({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = true,
    this.haptic = false,
    this.semanticLabel,
    this.labelMaxLines = 1,
  }) : variant = AppButtonVariant.secondary;

  const AppButton.ghost({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = false,
    this.haptic = false,
    this.semanticLabel,
  }) : variant = AppButtonVariant.ghost,
       labelMaxLines = 1;

  final String label;
  final VoidCallback? onPressed;
  final AppButtonVariant variant;
  final IconData? icon;
  final bool loading;
  final bool expand;
  final bool haptic;
  final String? semanticLabel;

  /// How many lines the label may take before it is cut with an ellipsis (one, unless a long label must stay readable on a small phone).
  final int labelMaxLines;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final enabled = onPressed != null && !loading;
    void handle() {
      if (haptic) Haptics.light();
      onPressed?.call();
    }

    final fg = switch (variant) {
      AppButtonVariant.primary => context.colors.onPrimary,
      AppButtonVariant.accent => Colors.white,
      AppButtonVariant.destructive => Colors.white,
      _ => context.colors.onSurface,
    };

    // Fresh design: primary and accent buttons are pills that end with an arrow.
    final arrow =
        context.design.pillButtons && (variant == AppButtonVariant.primary || variant == AppButtonVariant.accent);
    final child = AnimatedSwitcher(
      duration: AppDurations.fast,
      child: loading
          ? SizedBox(
              key: const ValueKey('loading'),
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2.2, color: fg),
            )
          : Row(
              key: const ValueKey('label'),
              mainAxisSize: MainAxisSize.min,
              children: [
                if (icon != null) ...[Icon(icon, size: 20), const Gap(AppSpace.xs)],
                Flexible(
                  child: Text(
                    label,
                    overflow: TextOverflow.ellipsis,
                    maxLines: labelMaxLines,
                    textAlign: TextAlign.center,
                  ),
                ),
                if (arrow) ...[const Gap(AppSpace.xs), const Icon(Icons.arrow_forward_rounded, size: 20)],
              ],
            ),
    );

    final Widget button = switch (variant) {
      AppButtonVariant.primary => FilledButton(onPressed: enabled ? handle : null, child: child),
      AppButtonVariant.accent => FilledButton(
        onPressed: enabled ? handle : null,
        style: FilledButton.styleFrom(
          backgroundColor: palette.brass,
          foregroundColor: Colors.white,
          disabledBackgroundColor: palette.ink.withValues(alpha: 0.10),
          disabledForegroundColor: palette.ink.withValues(alpha: 0.40),
        ),
        child: child,
      ),
      AppButtonVariant.destructive => FilledButton(
        onPressed: enabled ? handle : null,
        style: FilledButton.styleFrom(
          backgroundColor: palette.danger,
          foregroundColor: Colors.white,
          disabledBackgroundColor: palette.ink.withValues(alpha: 0.10),
          disabledForegroundColor: palette.ink.withValues(alpha: 0.40),
        ),
        child: child,
      ),
      AppButtonVariant.secondary => OutlinedButton(onPressed: enabled ? handle : null, child: child),
      AppButtonVariant.ghost => TextButton(onPressed: enabled ? handle : null, child: child),
    };

    return Semantics(
      button: true,
      enabled: enabled,
      label: semanticLabel,
      excludeSemantics: semanticLabel != null,
      child: expand ? SizedBox(width: double.infinity, child: button) : button,
    );
  }
}

/// Circular icon button with a soft surface — used over imagery.
class RoundIconButton extends StatelessWidget {
  const RoundIconButton({
    super.key,
    required this.icon,
    required this.onPressed,
    required this.tooltip,
    this.color,
    this.background,
    this.size = 44,
  });

  final IconData icon;
  final VoidCallback? onPressed;
  final String tooltip;
  final Color? color;
  final Color? background;
  final double size;

  @override
  Widget build(BuildContext context) {
    final onTap = onPressed == null
        ? null
        : () {
            Haptics.tap();
            onPressed!();
          };
    // The disc is [size] (it may be smaller than 48), but the whole 48 x 48 box is the button: a tap on the margin round the disc counts,
    // so a thumb that lands a little off the picture still lands. (The disc's own ink answers inside it; the outer detector the rest.)
    return Tooltip(
      message: tooltip,
      child: Semantics(
        button: true,
        enabled: onPressed != null,
        label: tooltip,
        onTap: onTap,
        excludeSemantics: true,
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: onTap,
          child: SizedBox(
            width: kMinTouchTarget,
            height: kMinTouchTarget,
            child: Center(
              child: Material(
                color: background ?? context.colors.surface.withValues(alpha: 0.92),
                shape: const CircleBorder(),
                elevation: 0,
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: onTap,
                  child: SizedBox(
                    width: size,
                    height: size,
                    child: Icon(icon, size: 21, color: color ?? context.colors.onSurface),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
