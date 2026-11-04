import 'package:flutter/foundation.dart';

/// API `platform` value: `android` | `ios` | `web`.
String platformCode() {
  if (kIsWeb) return 'web';
  return switch (defaultTargetPlatform) {
    TargetPlatform.iOS || TargetPlatform.macOS => 'ios',
    _ => 'android',
  };
}

/// Human-readable device label for the sessions list.
String deviceName() {
  if (kIsWeb) return 'Web browser';
  return switch (defaultTargetPlatform) {
    TargetPlatform.iOS => 'iPhone / iPad',
    TargetPlatform.android => 'Android device',
    _ => 'Device',
  };
}
