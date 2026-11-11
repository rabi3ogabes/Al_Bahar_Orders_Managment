import 'package:flutter/material.dart';

/// Brand palette — "Qatar Light": bright screens, white soft cards, Qatar maroon (Pantone 1955 C, #8A1538) primary.
/// (Names such as `ink`/`brass` are kept for the whole code base; their values follow the soft-card design.)
abstract final class AppPalette {
  // Light
  static const ivory = Color(0xFFFCF9FA); // screen background
  static const sand = Color(0xFFF4ECEF); // segmented tracks, neutral wells
  static const sandDeep = Color(0xFFEADDE2);
  static const paper = Color(0xFFFFFFFF); // card surface
  static const canvas = Color(0xFFF6EEF1);
  static const ink = Color(0xFF2A1A20); // text
  static const inkSoft = Color(0xFF4F3B43);
  static const navy = Color(0xFF8A1538); // primary (buttons, active tab, prices)
  static const navySoft = Color(0xFFA3294E);
  static const tint = Color(0xFFFBEDF1); // icon wells, selected tiles, info strips
  static const muted = Color(0xFF736369);
  static const line = Color(0xFFF0E4E8);
  static const brass = Color(0xFFB3254F); // rose accent (fills, white text)
  static const brassDeep = Color(0xFF8A1538); // maroon for text on light surfaces (AA)
  static const brassTint = Color(0xFFFBEDF1);
  static const goldSoft = Color(0xFFF6D3DC); // soft rose on maroon

  // Semantic
  static const success = Color(0xFF1F7A4D);
  static const successTint = Color(0xFFDDF3E6);
  static const warning = Color(0xFF9A5F0F);
  static const warningTint = Color(0xFFFCE9CF);
  static const danger = Color(0xFFB42323);
  static const dangerTint = Color(0xFFFDE2E2);
  static const info = Color(0xFF1F5FAD);
  static const infoTint = Color(0xFFDCEBFB);

  // Dark
  static const nightBg = Color(0xFF181115);
  static const nightSurface = Color(0xFF221A1E);
  static const nightSurfaceHigh = Color(0xFF2E2328);
  static const nightInk = Color(0xFFF1ECE3);
  static const nightMuted = Color(0xFFA6B0BE);
  static const nightLine = Color(0xFF3A2E34);
  static const nightBrass = Color(0xFFE58AA5);
  static const nightNavy = Color(0xFFE8A3B8);
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
  static const double image = 14;
  static const double input = 16;
  static const double card = 20;
  static const double lg = 24;
  static const double sheet = 28;
  static const double pill = 999;

  static const cardAll = BorderRadius.all(Radius.circular(card));
  static const lgAll = BorderRadius.all(Radius.circular(lg));
  static const inputAll = BorderRadius.all(Radius.circular(input));
  static const imageAll = BorderRadius.all(Radius.circular(image));
}

/// Soft card shadow from the design ("0 8px 24px rgba(16,24,40,.06)").
const List<BoxShadow> kSoftShadow = [BoxShadow(color: Color(0x0F101828), blurRadius: 24, offset: Offset(0, 8))];

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
    skeletonBase: Color(0xFFEDE7DD),
    skeletonHighlight: Color(0xFFF7F3EC),
  );

  static const dark = AppColors(
    brass: AppPalette.nightBrass,
    brassTint: Color(0xFF2B2418),
    ink: AppPalette.nightInk,
    muted: AppPalette.nightMuted,
    line: AppPalette.nightLine,
    surfaceMuted: AppPalette.nightSurfaceHigh,
    success: Color(0xFF6FB48A),
    warning: Color(0xFFE0A84F),
    danger: Color(0xFFE88A80),
    info: Color(0xFF8AB0E0),
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
