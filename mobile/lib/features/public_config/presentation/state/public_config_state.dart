import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/public_config/data/public_config_repository.dart';
import 'package:albahar_customer/features/public_config/domain/public_config.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The dashboard's public settings. Starts with the last good answer (instant, works offline), asks the server once in the
/// background and swaps in a newer answer when it arrives. A failure is silent: the screens simply keep what they have.
class PublicConfigController extends Notifier<PublicConfig> {
  @override
  PublicConfig build() {
    final cached = ref.read(appPreferencesProvider).publicConfig;
    unawaited(Future.microtask(refresh));
    return cached == null ? PublicConfig.empty : PublicConfig.fromJson(cached);
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

final publicConfigProvider = NotifierProvider<PublicConfigController, PublicConfig>(PublicConfigController.new);
