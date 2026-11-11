import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'brand_theme.dart';
import 'tokens.dart';

abstract final class AppFonts {
  static const arabic = 'IBMPlexSansArabic';
  static const display = 'PlayfairDisplay';
  static const body = 'Inter';
}

/// Builds light/dark themes ("Ivory Navy" soft-card design). Arabic uses IBM Plex Sans Arabic throughout;
/// Latin uses Inter for headings and body alike (heavier weights for headings, no serif).
abstract final class AppTheme {
  /// [brand] is the look chosen in the dashboard (Qatar events etc.); dark mode keeps its own palette.
  static ThemeData light(Locale locale, {BrandTheme? brand}) => _build(Brightness.light, locale, brand);
  static ThemeData dark(Locale locale) => _build(Brightness.dark, locale, null);

  static TextTheme textTheme(Locale locale, Color ink, Color muted) {
    final isArabic = locale.languageCode == 'ar';
    final display = isArabic ? AppFonts.arabic : AppFonts.body;
    final body = isArabic ? AppFonts.arabic : AppFonts.body;
    // Mixed content (Arabic product names in English UI and vice versa).
    final fallback = isArabic ? const [AppFonts.body] : const [AppFonts.arabic];
    // Arabic glyphs need a touch more line height to breathe.
    final lh = isArabic ? 1.15 : 1.0;

    TextStyle s(String family, double size, FontWeight w, double height, {double ls = 0, Color? color}) => TextStyle(
      fontFamily: family,
      fontFamilyFallback: fallback,
      fontSize: size,
      fontWeight: w,
      height: height * lh,
      letterSpacing: isArabic ? 0 : ls,
      color: color ?? ink,
    );

    return TextTheme(
      displayLarge: s(display, 40, FontWeight.w700, 1.1, ls: -0.6),
      displayMedium: s(display, 34, FontWeight.w700, 1.12, ls: -0.5),
      displaySmall: s(display, 28, FontWeight.w700, 1.15, ls: -0.3),
      headlineLarge: s(display, 28, FontWeight.w700, 1.2, ls: -0.2),
      headlineMedium: s(display, 24, FontWeight.w700, 1.22),
      headlineSmall: s(display, 21, FontWeight.w700, 1.25),
      titleLarge: s(display, 20, FontWeight.w600, 1.3),
      titleMedium: s(body, 16, FontWeight.w600, 1.35, ls: 0.1),
      titleSmall: s(body, 14, FontWeight.w600, 1.35, ls: 0.1),
      bodyLarge: s(body, 16, FontWeight.w400, 1.5),
      bodyMedium: s(body, 14, FontWeight.w400, 1.5),
      bodySmall: s(body, 12.5, FontWeight.w400, 1.45, color: muted),
      labelLarge: s(body, 15, FontWeight.w600, 1.2, ls: 0.3),
      labelMedium: s(body, 13, FontWeight.w500, 1.2, ls: 0.3),
      labelSmall: s(body, 11, FontWeight.w600, 1.2, ls: 0.8, color: muted),
    );
  }

  static Color _mix(Color a, Color b, double t) => Color.lerp(a, b, t)!;

  static ThemeData _build(Brightness brightness, Locale locale, BrandTheme? brand) {
    final isDark = brightness == Brightness.dark;
    var ext = isDark ? AppColors.dark : AppColors.light;
    var bg = isDark ? AppPalette.nightBg : AppPalette.ivory;
    var surface = isDark ? AppPalette.nightSurface : AppPalette.paper;
    var primary = AppPalette.navy;
    if (!isDark && brand != null) {
      bg = brand.background;
      surface = brand.surface;
      primary = brand.primary;
      ext = ext.copyWith(
        brass: brand.accent,
        brassTint: _mix(brand.accent, Colors.white, 0.9),
        brassDeep: brand.primary,
        primarySoft: brand.primarySoft,
        tint: _mix(brand.primary, Colors.white, 0.93),
        onPrimarySoft: _mix(brand.primarySoft, Colors.white, 0.75),
        ink: brand.ink,
      );
    }
    final surfaceHigh = isDark ? AppPalette.nightSurfaceHigh : ext.tint;
    final ink = ext.ink;
    // Brand primary: deep navy on light, a soft navy tint on dark.
    final navy = isDark ? AppPalette.nightNavy : primary;
    final onNavy = isDark ? AppPalette.nightBg : Colors.white;

    final scheme = ColorScheme(
      brightness: brightness,
      primary: navy,
      onPrimary: onNavy,
      primaryContainer: isDark ? AppPalette.nightSurfaceHigh : ext.tint,
      onPrimaryContainer: isDark ? AppPalette.nightInk : primary,
      secondary: ext.brass,
      onSecondary: isDark ? AppPalette.nightBg : ext.ink,
      secondaryContainer: ext.brassTint,
      onSecondaryContainer: isDark ? AppPalette.nightBrass : ext.brassDeep,
      tertiary: ext.info,
      onTertiary: Colors.white,
      error: ext.danger,
      onError: Colors.white,
      surface: surface,
      onSurface: ink,
      onSurfaceVariant: ext.muted,
      surfaceContainerLowest: bg,
      surfaceContainerLow: surface,
      surfaceContainer: surface,
      surfaceContainerHigh: surfaceHigh,
      surfaceContainerHighest: surfaceHigh,
      outline: ext.line,
      outlineVariant: ext.line,
      shadow: Colors.black,
      inverseSurface: isDark ? AppPalette.paper : primary,
      onInverseSurface: isDark ? AppPalette.ink : AppPalette.paper,
    );

    final text = textTheme(locale, ink, ext.muted);
    const inputRadius = AppRadius.inputAll;

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: bg,
      canvasColor: bg,
      textTheme: text,
      fontFamily: locale.languageCode == 'ar' ? AppFonts.arabic : AppFonts.body,
      extensions: [ext],
      splashFactory: InkSparkle.splashFactory,
      visualDensity: VisualDensity.standard,
      materialTapTargetSize: MaterialTapTargetSize.padded,
      appBarTheme: AppBarTheme(
        backgroundColor: bg,
        surfaceTintColor: Colors.transparent,
        foregroundColor: ink,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: true,
        titleTextStyle: text.titleLarge,
        systemOverlayStyle: isDark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
      ),
      // Soft white cards: no outline on light (a gentle shadow instead), a hairline on dark.
      cardTheme: CardThemeData(
        color: surface,
        surfaceTintColor: Colors.transparent,
        shadowColor: const Color(0x33101828),
        elevation: isDark ? 0 : 2,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadius.cardAll,
          side: isDark ? BorderSide(color: ext.line) : BorderSide.none,
        ),
      ),
      dividerTheme: DividerThemeData(color: ext.line, thickness: 1, space: 1),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(kMinTouchTarget, 54),
          padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.lg),
          shape: const RoundedRectangleBorder(borderRadius: AppRadius.inputAll),
          textStyle: text.labelLarge?.copyWith(fontSize: 16),
          backgroundColor: scheme.primary,
          foregroundColor: scheme.onPrimary,
          disabledBackgroundColor: ink.withValues(alpha: 0.10),
          disabledForegroundColor: ink.withValues(alpha: 0.40),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(kMinTouchTarget, 54),
          padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.lg),
          shape: const RoundedRectangleBorder(borderRadius: AppRadius.inputAll),
          side: BorderSide(color: navy, width: 1.5),
          foregroundColor: navy,
          textStyle: text.labelLarge?.copyWith(fontSize: 16),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: const Size(kMinTouchTarget, kMinTouchTarget),
          foregroundColor: navy,
          textStyle: text.labelLarge,
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(minimumSize: const Size(kMinTouchTarget, kMinTouchTarget)),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        contentPadding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.md, vertical: 16),
        labelStyle: text.bodyMedium?.copyWith(color: ext.muted),
        floatingLabelStyle: text.bodyMedium?.copyWith(color: navy),
        hintStyle: text.bodyMedium?.copyWith(color: ext.muted.withValues(alpha: 0.8)),
        border: OutlineInputBorder(
          borderRadius: inputRadius,
          borderSide: BorderSide(color: ext.line),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: inputRadius,
          borderSide: BorderSide(color: ext.line),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: inputRadius,
          borderSide: BorderSide(color: navy, width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: inputRadius,
          borderSide: BorderSide(color: ext.danger),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: inputRadius,
          borderSide: BorderSide(color: ext.danger, width: 1.4),
        ),
      ),
      // Filter chips: white pill with a hairline; the selected one is filled navy (white text).
      chipTheme: ChipThemeData(
        backgroundColor: surface,
        selectedColor: navy,
        side: BorderSide(color: ext.line),
        shape: const StadiumBorder(),
        labelStyle: WidgetStateTextStyle.resolveWith(
          (states) => (text.labelMedium ?? const TextStyle()).copyWith(
            color: states.contains(WidgetState.selected) ? onNavy : ink,
            fontWeight: FontWeight.w500,
            letterSpacing: 0,
          ),
        ),
        padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
        showCheckmark: false,
      ),
      // Bottom bar: white, hairline on top, no pill indicator — the active item is a filled navy icon + semibold label.
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        indicatorColor: Colors.transparent,
        height: 72,
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => text.labelSmall?.copyWith(
            letterSpacing: 0.1,
            fontSize: 11,
            fontWeight: states.contains(WidgetState.selected) ? FontWeight.w600 : FontWeight.w500,
            color: states.contains(WidgetState.selected) ? navy : ext.muted,
          ),
        ),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(color: states.contains(WidgetState.selected) ? navy : ext.muted, size: 24),
        ),
      ),
      navigationRailTheme: NavigationRailThemeData(
        backgroundColor: surface,
        indicatorColor: ext.brassTint,
        selectedIconTheme: IconThemeData(color: navy),
        unselectedIconTheme: IconThemeData(color: ext.muted),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        modalBackgroundColor: surface,
        showDragHandle: true,
        dragHandleColor: ext.line,
        shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.sheet))),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.lgAll),
        titleTextStyle: text.titleLarge,
        contentTextStyle: text.bodyMedium,
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: scheme.inverseSurface,
        contentTextStyle: text.bodyMedium?.copyWith(color: scheme.onInverseSurface),
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.inputAll),
      ),
      listTileTheme: ListTileThemeData(
        iconColor: ink,
        minTileHeight: 56,
        titleTextStyle: text.bodyLarge,
        subtitleTextStyle: text.bodySmall,
        contentPadding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.md),
      ),
      tabBarTheme: TabBarThemeData(
        labelColor: navy,
        unselectedLabelColor: ext.muted,
        labelStyle: text.titleSmall,
        unselectedLabelStyle: text.titleSmall,
        indicatorColor: navy,
        dividerColor: ext.line,
        indicatorSize: TabBarIndicatorSize.label,
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? onNavy : null),
        trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? navy : null),
      ),
      radioTheme: RadioThemeData(
        fillColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? navy : ext.muted),
      ),
      checkboxTheme: CheckboxThemeData(
        fillColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? navy : null),
        checkColor: WidgetStatePropertyAll(onNavy),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(color: navy, linearTrackColor: ext.line),
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
        },
      ),
    );
  }
}
