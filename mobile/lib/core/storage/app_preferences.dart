import 'dart:convert';

import 'package:flutter/material.dart' show ThemeMode;
import 'package:shared_preferences/shared_preferences.dart';

import '../utils/json.dart';

/// Non-sensitive, per-device preferences.
class AppPreferences {
  AppPreferences(this._prefs);

  final SharedPreferences _prefs;

  static const _kLocale = 'locale';
  static const _kOnboarding = 'onboarding_done';
  static const _kBranch = 'preferred_branch';
  static const _kRecentSearches = 'recent_searches';
  static const _kThemeMode = 'theme_mode';
  static const _kPushPrefs = 'push_category_prefs';
  static const _kInstallId = 'install_id';
  static const _kPushToken = 'registered_push_token';

  String? get localeCode => _prefs.getString(_kLocale);
  Future<void> setLocaleCode(String code) => _prefs.setString(_kLocale, code);

  bool get onboardingDone => _prefs.getBool(_kOnboarding) ?? false;
  Future<void> setOnboardingDone() => _prefs.setBool(_kOnboarding, true);

  JsonMap? get preferredBranch {
    final raw = _prefs.getString(_kBranch);
    if (raw == null) return null;
    try {
      return asMap(jsonDecode(raw));
    } catch (_) {
      return null;
    }
  }

  Future<void> setPreferredBranch(JsonMap? branch) => branch == null
      ? _prefs.remove(_kBranch)
      : _prefs.setString(_kBranch, jsonEncode(branch));

  List<String> get recentSearches => _prefs.getStringList(_kRecentSearches) ?? const [];
  Future<void> setRecentSearches(List<String> values) => _prefs.setStringList(_kRecentSearches, values);

  ThemeMode get themeMode => switch (_prefs.getString(_kThemeMode)) {
    'light' => ThemeMode.light,
    'dark' => ThemeMode.dark,
    _ => ThemeMode.system,
  };
  Future<void> setThemeMode(ThemeMode mode) => _prefs.setString(_kThemeMode, mode.name);

  /// Cached copy of server push preferences per category, used to decide
  /// whether a *foreground* push should be surfaced as a banner.
  Map<String, bool> get pushCategoryPrefs {
    final raw = _prefs.getString(_kPushPrefs);
    if (raw == null) return const {};
    try {
      return asMap(jsonDecode(raw)).map((k, v) => MapEntry(k, asBool(v, true)));
    } catch (_) {
      return const {};
    }
  }

  Future<void> setPushCategoryPrefs(Map<String, bool> prefs) =>
      _prefs.setString(_kPushPrefs, jsonEncode(prefs));

  String? get installId => _prefs.getString(_kInstallId);
  Future<void> setInstallId(String id) => _prefs.setString(_kInstallId, id);

  String? get registeredPushToken => _prefs.getString(_kPushToken);
  Future<void> setRegisteredPushToken(String? token) =>
      token == null ? _prefs.remove(_kPushToken) : _prefs.setString(_kPushToken, token);
}
