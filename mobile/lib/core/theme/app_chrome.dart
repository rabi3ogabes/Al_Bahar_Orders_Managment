import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// The ground under the whole customer app. It paints the page colour behind everything, so no corner of the window (the
/// system gesture area, the strip under a floating footer menu, the web page) can ever show the black of an empty window,
/// and it colours the Android navigation bar the same, with icons that read on it.
///
/// Put it inside `MaterialApp.builder`, where the theme is already the light or the dark one in use.
class AppChrome extends StatelessWidget {
  const AppChrome({super.key, required this.child});

  final Widget child;

  /// The Android navigation bar for a page of colour [page]: the same colour, no divider line, no grey scrim, and light or
  /// dark icons by the colour's brightness. The status bar is left to each page's app bar, as before.
  static SystemUiOverlayStyle navigationBarStyle(Color page) => SystemUiOverlayStyle(
    systemNavigationBarColor: page,
    systemNavigationBarDividerColor: Colors.transparent,
    systemNavigationBarIconBrightness: ThemeData.estimateBrightnessForColor(page) == Brightness.dark
        ? Brightness.light
        : Brightness.dark,
    systemNavigationBarContrastEnforced: false,
  );

  @override
  Widget build(BuildContext context) {
    final page = context.pageColor;
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: navigationBarStyle(page),
      child: ColoredBox(color: page, child: child),
    );
  }
}
