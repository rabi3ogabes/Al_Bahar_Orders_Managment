import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'tokens.dart';

abstract final class AppFonts {
  static const arabic = 'IBMPlexSansArabic';
  static const display = 'PlayfairDisplay';
  static const body = 'Inter';
}

/// Builds light/dark themes. Arabic uses IBM Plex Sans Arabic throughout;
/// Latin pairs Playfair Display (display/headlines) with Inter (UI/body).
abstract final class AppTheme {
  static ThemeData light(Locale locale) => _build(Brightness.light, locale);
  static ThemeData dark(Locale locale) => _build(Brightness.dark, locale);

  static TextTheme textTheme(Locale locale, Color ink, Color muted) {
    final isArabic = locale.languageCode == 'ar';
    final display = isArabic ? AppFonts.arabic : AppFonts.display;
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
      displayLarge: s(display, 44, FontWeight.w600, 1.1, ls: -0.5),
      displayMedium: s(display, 36, FontWeight.w600, 1.12, ls: -0.4),
      displaySmall: s(display, 30, FontWeight.w600, 1.15, ls: -0.2),
      headlineLarge: s(display, 28, FontWeight.w600, 1.2),
      headlineMedium: s(display, 24, FontWeight.w600, 1.22),
      headlineSmall: s(display, 21, FontWeight.w600, 1.25),
      titleLarge: s(display, 19, FontWeight.w600, 1.3),
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

  static ThemeData _build(Brightness brightness, Locale locale) {
    final isDark = brightness == Brightness.dark;
    final ext = isDark ? AppColors.dark : AppColors.light;
    final bg = isDark ? AppPalette.nightBg : AppPalette.ivory;
    final surface = isDark ? AppPalette.nightSurface : AppPalette.paper;
    final surfaceHigh = isDark ? AppPalette.nightSurfaceHigh : AppPalette.sand;
    final ink = ext.ink;

    final scheme = ColorScheme(
      brightness: brightness,
      primary: isDark ? AppPalette.nightInk : AppPalette.ink,
      onPrimary: isDark ? AppPalette.nightBg : AppPalette.paper,
      primaryContainer: isDark ? AppPalette.nightSurfaceHigh : AppPalette.sand,
      onPrimaryContainer: ink,
      secondary: ext.brass,
      onSecondary: isDark ? AppPalette.nightBg : Colors.white,
      secondaryContainer: ext.brassTint,
      onSecondaryContainer: isDark ? AppPalette.nightBrass : AppPalette.brassDeep,
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
      inverseSurface: isDark ? AppPalette.paper : AppPalette.ink,
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
        scrolledUnderElevation: 0.5,
        centerTitle: true,
        titleTextStyle: text.titleLarge,
        systemOverlayStyle: isDark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
      ),
      cardTheme: CardThemeData(
        color: surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadius.cardAll,
          side: BorderSide(color: ext.line.withValues(alpha: 0.7)),
        ),
      ),
      dividerTheme: DividerThemeData(color: ext.line, thickness: 1, space: 1),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(kMinTouchTarget, 52),
          padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.lg),
          shape: const RoundedRectangleBorder(borderRadius: AppRadius.inputAll),
          textStyle: text.labelLarge,
          backgroundColor: scheme.primary,
          foregroundColor: scheme.onPrimary,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(kMinTouchTarget, 52),
          padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.lg),
          shape: const RoundedRectangleBorder(borderRadius: AppRadius.inputAll),
          side: BorderSide(color: ink.withValues(alpha: 0.25)),
          foregroundColor: ink,
          textStyle: text.labelLarge,
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: const Size(kMinTouchTarget, kMinTouchTarget),
          foregroundColor: isDark ? AppPalette.nightBrass : AppPalette.brassDeep,
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
        floatingLabelStyle: text.bodyMedium?.copyWith(color: ext.brass),
        hintStyle: text.bodyMedium?.copyWith(color: ext.muted.withValues(alpha: 0.8)),
        border: OutlineInputBorder(borderRadius: inputRadius, borderSide: BorderSide(color: ext.line)),
        enabledBorder: OutlineInputBorder(borderRadius: inputRadius, borderSide: BorderSide(color: ext.line)),
        focusedBorder: OutlineInputBorder(borderRadius: inputRadius, borderSide: BorderSide(color: ext.brass, width: 1.4)),
        errorBorder: OutlineInputBorder(borderRadius: inputRadius, borderSide: BorderSide(color: ext.danger)),
        focusedErrorBorder: OutlineInputBorder(borderRadius: inputRadius, borderSide: BorderSide(color: ext.danger, width: 1.4)),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: surface,
        selectedColor: ext.brassTint,
        side: BorderSide(color: ext.line),
        shape: const StadiumBorder(),
        labelStyle: text.labelMedium,
        padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
        showCheckmark: false,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        indicatorColor: ext.brassTint,
        height: 68,
        elevation: 0,
        labelTextStyle: WidgetStatePropertyAll(text.labelSmall?.copyWith(letterSpacing: 0.2, color: ink)),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(color: states.contains(WidgetState.selected) ? ink : ext.muted, size: 24),
        ),
      ),
      navigationRailTheme: NavigationRailThemeData(
        backgroundColor: surface,
        indicatorColor: ext.brassTint,
        selectedIconTheme: IconThemeData(color: ink),
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
        labelColor: ink,
        unselectedLabelColor: ext.muted,
        labelStyle: text.titleSmall,
        unselectedLabelStyle: text.titleSmall,
        indicatorColor: ext.brass,
        dividerColor: ext.line,
        indicatorSize: TabBarIndicatorSize.label,
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? Colors.white : null),
        trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? ext.brass : null),
      ),
      radioTheme: RadioThemeData(fillColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? ext.brass : ext.muted)),
      checkboxTheme: CheckboxThemeData(
        fillColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? ext.brass : null),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(5)),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(color: ext.brass, linearTrackColor: ext.line),
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
        },
      ),
    );
  }
}
