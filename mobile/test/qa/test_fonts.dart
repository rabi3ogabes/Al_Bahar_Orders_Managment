import 'dart:io';

import 'package:flutter/services.dart';

bool _loaded = false;

/// Registers the app's real fonts so widget tests measure text like a device.
/// Without this the test engine draws every glyph as a full-width square, which
/// makes text look ~2x wider and hides or invents overflow problems.
Future<void> loadAppFonts() async {
  if (_loaded) return;
  _loaded = true;
  const families = {
    'Inter': ['Inter-Regular', 'Inter-Medium', 'Inter-SemiBold', 'Inter-Bold'],
    'IBMPlexSansArabic': [
      'IBMPlexSansArabic-Regular',
      'IBMPlexSansArabic-Medium',
      'IBMPlexSansArabic-SemiBold',
      'IBMPlexSansArabic-Bold',
    ],
    'PlayfairDisplay': ['PlayfairDisplay-Medium', 'PlayfairDisplay-SemiBold', 'PlayfairDisplay-Bold'],
  };
  for (final entry in families.entries) {
    final loader = FontLoader(entry.key);
    for (final file in entry.value) {
      final bytes = File('assets/fonts/$file.ttf').readAsBytesSync();
      loader.addFont(Future.value(ByteData.sublistView(bytes)));
    }
    await loader.load();
  }
}
