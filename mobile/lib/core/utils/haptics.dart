import 'dart:async';

import 'package:flutter/services.dart';

/// Centralised haptic vocabulary so feedback is consistent across the app.
abstract final class Haptics {
  static void tap() => unawaited(HapticFeedback.selectionClick());
  static void light() => unawaited(HapticFeedback.lightImpact());
  static void success() => unawaited(HapticFeedback.mediumImpact());
  static void heavy() => unawaited(HapticFeedback.heavyImpact());
  static void warning() => unawaited(HapticFeedback.vibrate());
}
