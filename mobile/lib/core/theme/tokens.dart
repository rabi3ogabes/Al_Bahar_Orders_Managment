import 'package:flutter/material.dart';

/// Brand palette — warm ivory & sand, deep charcoal-navy ink, brass accent.
abstract final class AppPalette {
  // Light
  static const ivory = Color(0xFFF8F4EE);
  static const sand = Color(0xFFEFE6D8);
  static const sandDeep = Color(0xFFE2D5C1);
  static const paper = Color(0xFFFFFDF9);
  static const ink = Color(0xFF1D2330);
  static const inkSoft = Color(0xFF3A4252);
  static const muted = Color(0xFF6B6F78);
  static const line = Color(0xFFE6DDCF);
  static const brass = Color(0xFFB08D57);
  static const brassDeep = Color(0xFF8A6A3A);
  static const brassTint = Color(0xFFF2E8D8);

  // Semantic
  static const success = Color(0xFF3F7A57);
  static const warning = Color(0xFFB4781F);
  static const danger = Color(0xFFB0453A);
  static const info = Color(0xFF3E5C80);

  // Dark
  static const nightBg = Color(0xFF121419);
  static const nightSurface = Color(0xFF1A1D24);
  static const nightSurfaceHigh = Color(0xFF232731);
  static const nightInk = Color(0xFFF1ECE3);
  static const nightMuted = Color(0xFFA7A29A);
  static const nightLine = Color(0xFF2E333D);
  static const nightBrass = Color(0xFFCDAA70);
}

/// 8-pt spacing scale.
abstract final class AppSpace {
  static const double xxs = 4;
  static const double xs = 8;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;
  static const double xxl = 48;
  static const double xxxl = 64;

  /// Default horizontal page gutter.
  static const double gutter = 20;
}

abstract final class AppRadius {
  static const double sm = 10;
  static const double input = 14;
  static const double card = 16;
  static const double lg = 20;
  static const double sheet = 28;
  static const double pill = 999;

  static const cardAll = BorderRadius.all(Radius.circular(card));
  static const lgAll = BorderRadius.all(Radius.circular(lg));
  static const inputAll = BorderRadius.all(Radius.circular(input));
}

abstract final class AppDurations {
  static const fast = Duration(milliseconds: 150);
  static const medium = Duration(milliseconds: 260);
  static const slow = Duration(milliseconds: 420);
}

/// Minimum touch target (Material / WCAG).
const double kMinTouchTarget = 48;

/// Vertical / horizontal gap widget for the spacing scale.
class Gap extends StatelessWidget {
  const Gap(this.size, {super.key});
  final double size;

  @override
  Widget build(BuildContext context) => SizedBox(width: size, height: size);
}

/// Custom tokens not covered by [ColorScheme].
@immutable
class AppColors extends ThemeExtension<AppColors> {
  const AppColors({
    required this.brass,
    required this.brassTint,
    required this.ink,
    required this.muted,
    required this.line,
    required this.surfaceMuted,
    required this.success,
    required this.warning,
    required this.danger,
    required this.info,
    required this.skeletonBase,
    required this.skeletonHighlight,
  });

  static const light = AppColors(
    brass: AppPalette.brass,
    brassTint: AppPalette.brassTint,
    ink: AppPalette.ink,
    muted: AppPalette.muted,
    line: AppPalette.line,
    surfaceMuted: AppPalette.sand,
    success: AppPalette.success,
    warning: AppPalette.warning,
    danger: AppPalette.danger,
    info: AppPalette.info,
    skeletonBase: Color(0xFFEDE5D8),
    skeletonHighlight: Color(0xFFF8F3EA),
  );

  static const dark = AppColors(
    brass: AppPalette.nightBrass,
    brassTint: Color(0xFF2D2719),
    ink: AppPalette.nightInk,
    muted: AppPalette.nightMuted,
    line: AppPalette.nightLine,
    surfaceMuted: AppPalette.nightSurfaceHigh,
    success: Color(0xFF6FB48A),
    warning: Color(0xFFE0A84F),
    danger: Color(0xFFE07A6E),
    info: Color(0xFF8AA8CF),
    skeletonBase: Color(0xFF242832),
    skeletonHighlight: Color(0xFF2F3440),
  );

  final Color brass;
  final Color brassTint;
  final Color ink;
  final Color muted;
  final Color line;
  final Color surfaceMuted;
  final Color success;
  final Color warning;
  final Color danger;
  final Color info;
  final Color skeletonBase;
  final Color skeletonHighlight;

  @override
  AppColors copyWith({
    Color? brass,
    Color? brassTint,
    Color? ink,
    Color? muted,
    Color? line,
    Color? surfaceMuted,
    Color? success,
    Color? warning,
    Color? danger,
    Color? info,
    Color? skeletonBase,
    Color? skeletonHighlight,
  }) => AppColors(
    brass: brass ?? this.brass,
    brassTint: brassTint ?? this.brassTint,
    ink: ink ?? this.ink,
    muted: muted ?? this.muted,
    line: line ?? this.line,
    surfaceMuted: surfaceMuted ?? this.surfaceMuted,
    success: success ?? this.success,
    warning: warning ?? this.warning,
    danger: danger ?? this.danger,
    info: info ?? this.info,
    skeletonBase: skeletonBase ?? this.skeletonBase,
    skeletonHighlight: skeletonHighlight ?? this.skeletonHighlight,
  );

  @override
  AppColors lerp(ThemeExtension<AppColors>? other, double t) {
    if (other is! AppColors) return this;
    return AppColors(
      brass: Color.lerp(brass, other.brass, t)!,
      brassTint: Color.lerp(brassTint, other.brassTint, t)!,
      ink: Color.lerp(ink, other.ink, t)!,
      muted: Color.lerp(muted, other.muted, t)!,
      line: Color.lerp(line, other.line, t)!,
      surfaceMuted: Color.lerp(surfaceMuted, other.surfaceMuted, t)!,
      success: Color.lerp(success, other.success, t)!,
      warning: Color.lerp(warning, other.warning, t)!,
      danger: Color.lerp(danger, other.danger, t)!,
      info: Color.lerp(info, other.info, t)!,
      skeletonBase: Color.lerp(skeletonBase, other.skeletonBase, t)!,
      skeletonHighlight: Color.lerp(skeletonHighlight, other.skeletonHighlight, t)!,
    );
  }
}

extension ThemeContextX on BuildContext {
  ThemeData get theme => Theme.of(this);
  TextTheme get text => Theme.of(this).textTheme;
  ColorScheme get colors => Theme.of(this).colorScheme;
  AppColors get palette => Theme.of(this).extension<AppColors>() ?? AppColors.light;
  bool get isRtl => Directionality.of(this) == TextDirection.rtl;
}
