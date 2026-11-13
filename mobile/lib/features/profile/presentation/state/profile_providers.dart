import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/profile/data/profile_repository.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class AddressesController extends AsyncNotifier<List<Address>> {
  @override
  Future<List<Address>> build() async {
    if (!ref.watch(isAuthenticatedProvider)) return const [];
    ref.watch(localeCodeProvider);
    return ref.read(profileRepositoryProvider).addresses();
  }

  ProfileRepository get _repo => ref.read(profileRepositoryProvider);

  Future<Address> save(AddressInput input, {String? id}) async {
    final saved = id == null ? await _repo.createAddress(input) : await _repo.updateAddress(id, input);
    await _reload();
    return saved;
  }

  Future<void> delete(String id) async {
    await _repo.deleteAddress(id);
    final current = state.value ?? const [];
    state = AsyncData(current.where((a) => a.id != id).toList());
  }

  Future<void> makeDefault(String id) async {
    await _repo.setDefaultAddress(id);
    await _reload();
  }

  Future<void> _reload() async {
    final list = await _repo.addresses();
    if (ref.mounted) state = AsyncData(list);
  }
}

final addressesProvider = AsyncNotifierProvider<AddressesController, List<Address>>(AddressesController.new);

final defaultAddressProvider = Provider<Address?>((ref) {
  final list = ref.watch(addressesProvider).value ?? const [];
  return list.where((a) => a.isDefault).firstOrNull ?? list.firstOrNull;
});

class NotificationPrefsController extends AsyncNotifier<List<NotificationPreference>> {
  @override
  Future<List<NotificationPreference>> build() async {
    if (!ref.watch(isAuthenticatedProvider)) return const [];
    final list = await ref.read(profileRepositoryProvider).notificationPreferences();
    await _cachePush(list);
    return list;
  }

  Future<void> toggle(NotificationPreference pref, bool enabled) async {
    final previous = state.value ?? const [];
    final next = [
      for (final p in previous)
        if (p.channel == pref.channel && p.category == pref.category) p.withEnabled(enabled) else p,
    ];
    state = AsyncData(next);
    try {
      final saved = await ref.read(profileRepositoryProvider).saveNotificationPreferences(next);
      if (ref.mounted) state = AsyncData(saved);
      await _cachePush(saved);
    } catch (_) {
      if (ref.mounted) state = AsyncData(previous);
      rethrow;
    }
  }

  /// Foreground push banners respect the push-channel preferences.
  Future<void> _cachePush(List<NotificationPreference> list) => ref.read(appPreferencesProvider).setPushCategoryPrefs({
    for (final p in list.where((p) => p.channel == 'push')) p.category: p.isEnabled,
  });
}

final notificationPrefsProvider = AsyncNotifierProvider<NotificationPrefsController, List<NotificationPreference>>(
  NotificationPrefsController.new,
);

final loyaltyProvider = FutureProvider.autoDispose<LoyaltySummary>((ref) {
  ref.watch(isAuthenticatedProvider);
  ref.watch(localeCodeProvider);
  return ref.watch(profileRepositoryProvider).loyalty();
});

/// Public flag (works before sign-in): is the referral programme on?
final referralEnabledProvider = FutureProvider.autoDispose<bool>(
  (ref) => ref.watch(profileRepositoryProvider).referralEnabled(),
);

final referralProvider = FutureProvider.autoDispose<ReferralInfo>((ref) {
  ref.watch(isAuthenticatedProvider);
  return ref.watch(profileRepositoryProvider).referral();
});
