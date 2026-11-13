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
  static const _kBranchAuto = 'preferred_branch_auto';
  static const _kRecentSearches = 'recent_searches';
  static const _kThemeMode = 'theme_mode';
  static const _kPushPrefs = 'push_category_prefs';
  static const _kInstallId = 'install_id';
  static const _kPushToken = 'registered_push_token';
  static const _kLiveOrders = 'live_order_active';
  static const _kLivePermissionPrompt = 'live_order_permission_prompt';

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

  Future<void> setPreferredBranch(JsonMap? branch) =>
      branch == null ? _prefs.remove(_kBranch) : _prefs.setString(_kBranch, jsonEncode(branch));

  /// True when the app picked the branch itself (nearest / available); false when the customer chose it.
  bool get preferredBranchIsAuto => _prefs.getBool(_kBranchAuto) ?? false;
  Future<void> setPreferredBranchIsAuto(bool value) => _prefs.setBool(_kBranchAuto, value);

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

  Future<void> setPushCategoryPrefs(Map<String, bool> prefs) => _prefs.setString(_kPushPrefs, jsonEncode(prefs));

  String? get installId => _prefs.getString(_kInstallId);
  Future<void> setInstallId(String id) => _prefs.setString(_kInstallId, id);

  String? get registeredPushToken => _prefs.getString(_kPushToken);
  Future<void> setRegisteredPushToken(String? token) =>
      token == null ? _prefs.remove(_kPushToken) : _prefs.setString(_kPushToken, token);

  /// Orders with a live notification right now (`{orderId: seq}`), so a restart picks them up again.
  Map<String, int> get liveOrders {
    final raw = _prefs.getString(_kLiveOrders);
    if (raw == null) return const {};
    try {
      return asMap(jsonDecode(raw)).map((k, v) => MapEntry(k, asInt(v)));
    } catch (_) {
      return const {};
    }
  }

  Future<void> setLiveOrders(Map<String, int> orders) =>
      orders.isEmpty ? _prefs.remove(_kLiveOrders) : _prefs.setString(_kLiveOrders, jsonEncode(orders));

  /// When the notification permission sheet was last shown and how often (`{at: epoch ms, count: n}`).
  ({DateTime? at, int count}) get livePermissionPrompt {
    final raw = _prefs.getString(_kLivePermissionPrompt);
    if (raw == null) return (at: null, count: 0);
    try {
      final m = asMap(jsonDecode(raw));
      final ms = asIntOrNull(m['at']);
      return (at: ms == null ? null : DateTime.fromMillisecondsSinceEpoch(ms), count: asInt(m['count']));
    } catch (_) {
      return (at: null, count: 0);
    }
  }

  Future<void> setLivePermissionPrompt(DateTime at, int count) =>
      _prefs.setString(_kLivePermissionPrompt, jsonEncode({'at': at.millisecondsSinceEpoch, 'count': count}));
}
