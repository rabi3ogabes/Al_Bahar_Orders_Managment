import 'package:flutter/material.dart';

/// WCAG contrast helpers, so a colour picked in the dashboard can never produce unreadable text.
abstract final class Contrast {
  /// WCAG 2.x contrast ratio between two opaque colours (1 to 21).
  static double ratio(Color a, Color b) {
    final la = a.computeLuminance();
    final lb = b.computeLuminance();
    final hi = la > lb ? la : lb;
    final lo = la > lb ? lb : la;
    return (hi + 0.05) / (lo + 0.05);
  }

  /// White or near-black text, whichever reads better on [background] (AA 4.5:1 whenever one of them can).
  static Color onColor(Color background, {Color light = Colors.white, Color dark = const Color(0xFF14090D)}) =>
      ratio(background, light) >= ratio(background, dark) ? light : dark;

  /// Text colour that reads on both ends of a gradient.
  static Color onGradient(Color a, Color b, {Color light = Colors.white, Color dark = const Color(0xFF14090D)}) {
    double worst(Color fg) => ratio(a, fg) < ratio(b, fg) ? ratio(a, fg) : ratio(b, fg);
    return worst(light) >= worst(dark) ? light : dark;
  }

  /// Moves [fg] towards white (on a dark [bg]) or black (on a light [bg]) until it reaches [min] contrast.
  static Color ensure(Color fg, Color bg, {double min = 4.5}) {
    if (ratio(fg, bg) >= min) return fg;
    final towards = bg.computeLuminance() < 0.5 ? Colors.white : Colors.black;
    for (var t = 0.1; t <= 1.0; t += 0.1) {
      final c = Color.lerp(fg, towards, t)!;
      if (ratio(c, bg) >= min) return c;
    }
    return towards;
  }
}
