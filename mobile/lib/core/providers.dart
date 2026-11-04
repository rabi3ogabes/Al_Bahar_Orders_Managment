import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'config/app_config.dart';
import 'network/api_client.dart';
import 'storage/app_preferences.dart';
import 'storage/token_storage.dart';

/// Overridden in `bootstrap()` (and in tests).
final appConfigProvider = Provider<AppConfig>((ref) => AppConfig.fromEnvironment());

final sharedPreferencesProvider = Provider<SharedPreferences>(
  (ref) => throw UnimplementedError('sharedPreferencesProvider must be overridden'),
);

final appPreferencesProvider = Provider<AppPreferences>(
  (ref) => AppPreferences(ref.watch(sharedPreferencesProvider)),
);

final tokenStoreProvider = Provider<TokenStore>((ref) => SecureTokenStore());

/// Fires whenever the refresh flow definitively fails (session revoked).
class SessionExpirySignal extends ChangeNotifier {
  void fire() => notifyListeners();
}

final sessionExpirySignalProvider = Provider<SessionExpirySignal>((ref) {
  final signal = SessionExpirySignal();
  ref.onDispose(signal.dispose);
  return signal;
});

/// Supported locales; Arabic first (primary market).
const supportedLocales = [Locale('ar'), Locale('en')];

/// Selected UI locale; `null` until the customer picks a language.
class LocaleController extends Notifier<Locale?> {
  @override
  Locale? build() {
    final code = ref.watch(appPreferencesProvider).localeCode;
    return code == null ? null : Locale(code);
  }

  Future<void> setLocale(Locale locale) async {
    state = locale;
    await ref.read(appPreferencesProvider).setLocaleCode(locale.languageCode);
  }
}

final localeControllerProvider = NotifierProvider<LocaleController, Locale?>(LocaleController.new);

/// Effective locale code used for API requests and formatting.
final localeCodeProvider = Provider<String>((ref) {
  final chosen = ref.watch(localeControllerProvider);
  if (chosen != null) return chosen.languageCode;
  final device = PlatformDispatcher.instance.locale.languageCode;
  return device == 'en' ? 'en' : 'ar';
});

class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => ref.watch(appPreferencesProvider).themeMode;

  Future<void> set(ThemeMode mode) async {
    state = mode;
    await ref.read(appPreferencesProvider).setThemeMode(mode);
  }
}

final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(ThemeModeController.new);

final apiClientProvider = Provider<ApiClient>((ref) {
  final config = ref.watch(appConfigProvider);
  final tokens = ref.watch(tokenStoreProvider);
  final expiry = ref.watch(sessionExpirySignalProvider);
  return ApiClient.create(
    config: config,
    tokenStore: tokens,
    localeCode: () => ref.read(localeCodeProvider),
    onSessionExpired: () async => expiry.fire(),
  );
});

/// Connectivity flag maintained by [ConnectivityWatcher] (see bootstrap).
class OnlineController extends Notifier<bool> {
  @override
  bool build() => true;

  void set(bool online) {
    if (state != online) state = online;
  }
}

final onlineProvider = NotifierProvider<OnlineController, bool>(OnlineController.new);

/// Small helper for fire-and-forget futures where errors are irrelevant.
void fireAndForget(Future<void> future) => unawaited(future.catchError((Object _) {}));
