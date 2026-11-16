import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/public_config/data/public_config_repository.dart';
import 'package:albahar_customer/features/public_config/domain/public_config.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The dashboard's public settings. Starts with the last good answer (instant, works offline), asks the server once in the
/// background, asks again whenever the app comes back to the foreground and swaps in a newer answer when it arrives. A failure
/// is silent: the screens simply keep what they have.
class PublicConfigController extends Notifier<PublicConfig> {
  @override
  PublicConfig build() {
    final cached = ref.read(appPreferencesProvider).publicConfig;
    _refreshOnResume();
    unawaited(Future.microtask(refresh));
    return cached == null ? PublicConfig.empty : PublicConfig.fromJson(cached);
  }

  /// A change made on the dashboard (the alternative-number switch, say) reaches a phone that stayed open in the pocket.
  void _refreshOnResume() {
    try {
      final observer = _ResumeObserver(() => unawaited(refresh()));
      WidgetsBinding.instance.addObserver(observer);
      ref.onDispose(() => WidgetsBinding.instance.removeObserver(observer));
    } catch (_) {
      // No widgets binding (a plain unit test): nothing to listen to.
    }
  }

  Future<void> refresh() async {
    try {
      final next = await ref.read(publicConfigRepositoryProvider).load();
      if (!ref.mounted) return;
      await ref.read(appPreferencesProvider).setPublicConfig(next.toJson());
      if (ref.mounted && next != state) state = next;
    } catch (_) {
      // Offline, slow or an older server: keep the last good value.
    }
  }
}

/// Calls [onResume] each time the app comes back to the foreground.
class _ResumeObserver with WidgetsBindingObserver {
  _ResumeObserver(this.onResume);
  final VoidCallback onResume;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) onResume();
  }
}

final publicConfigProvider = NotifierProvider<PublicConfigController, PublicConfig>(PublicConfigController.new);

/// Whether the customer app asks for and shows the alternative phone number (the dashboard's "Alternative phone number" switch).
/// On from the start and until the dashboard says otherwise; the last answer the phone saw is used before the server answers.
final alternatePhoneEnabledProvider = Provider<bool>(
  (ref) => ref.watch(publicConfigProvider.select((c) => c.alternatePhoneEnabled)),
);
