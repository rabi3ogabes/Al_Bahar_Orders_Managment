import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// How the staff and driver apps ask for a status change right now: plain buttons or "slide to confirm". The dashboard
/// decides (`GET /app-config` → `ops.status_style`). The same answer also carries the "Alternative phone number" switch, which is
/// handed to [opsAlternatePhoneEnabledProvider], so one request per start and per return to the app serves both.
///
/// It starts with the last answer the phone saw (so the first screen is right at once, also offline), asks the server in the
/// background and swaps in a newer answer; the apps ask again whenever they come back to the foreground. A failure is silent:
/// the screens keep what they have, and a phone that never heard from the server uses the plain buttons. It never blocks a
/// screen and never shows an error.
class OpsStatusStyleController extends Notifier<OpsStatusStyle> {
  /// Where the last answer is kept on the phone.
  static const cacheKey = 'ops_status_style';

  @override
  OpsStatusStyle build() {
    unawaited(Future.microtask(refresh));
    return _cached();
  }

  OpsStatusStyle _cached() {
    try {
      return OpsStatusStyle.fromCode(ref.read(sharedPreferencesProvider).getString(cacheKey));
    } catch (_) {
      // No storage (a test, a locked-down phone): plain buttons.
      return OpsStatusStyle.buttons;
    }
  }

  /// Reads the setting from the server again.
  Future<void> refresh() async {
    try {
      final config = await ref.read(opsRepositoryProvider).appConfig();
      if (!ref.mounted) return;
      final next = config.statusStyle;
      if (next != state) state = next;
      unawaited(_remember(next));
      unawaited(ref.read(opsAlternatePhoneEnabledProvider.notifier).set(config.alternatePhoneEnabled));
    } catch (_) {
      // Offline, slow, or a server that does not know the setting yet: keep what we have.
    }
  }

  Future<void> _remember(OpsStatusStyle style) async {
    try {
      await ref.read(sharedPreferencesProvider).setString(cacheKey, style.name);
    } catch (_) {
      // Not being able to remember it only means the next start begins with the buttons.
    }
  }
}

final opsStatusStyleProvider = NotifierProvider<OpsStatusStyleController, OpsStatusStyle>(OpsStatusStyleController.new);

/// Whether the staff and driver apps show the customer's alternative phone number and its call button (the dashboard's
/// "Alternative phone number" switch, `GET /app-config` → `alternate_phone_enabled`). On from the start; afterwards the last answer
/// the phone saw, which [OpsStatusStyleController.refresh] updates (it never blocks a screen and never shows an error).
class OpsAlternatePhoneEnabledController extends Notifier<bool> {
  /// Where the last answer is kept on the phone.
  static const cacheKey = 'ops_alternate_phone_enabled';

  @override
  bool build() {
    try {
      return ref.read(sharedPreferencesProvider).getBool(cacheKey) ?? true;
    } catch (_) {
      // No storage (a test, a locked-down phone): shown, as before the switch existed.
      return true;
    }
  }

  Future<void> set(bool enabled) async {
    if (enabled != state) state = enabled;
    try {
      await ref.read(sharedPreferencesProvider).setBool(cacheKey, enabled);
    } catch (_) {
      // Not being able to remember it only means the next start begins with the number shown.
    }
  }
}

final opsAlternatePhoneEnabledProvider = NotifierProvider<OpsAlternatePhoneEnabledController, bool>(
  OpsAlternatePhoneEnabledController.new,
);
