import 'dart:convert';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The last good answer of the lists the two staff apps show (the driver's jobs, the branch's overview and order lanes), kept on the phone so that a
/// phone or a branch tablet that lost its signal still shows what it had, also after the app was closed, with "Last updated 14:05". Only what the
/// screens show is kept, it is cleared when the person signs out, and a list that is read again replaces it. Nothing is ever sent from it.
class OpsReadCache {
  OpsReadCache(this._prefs, {this.onStale, DateTime Function()? now}) : _now = now ?? DateTime.now;

  final SharedPreferences _prefs;
  final DateTime Function() _now;

  /// Told which list is shown from the phone and since when (null: the list is live again).
  final void Function(String key, DateTime? since)? onStale;

  static const _prefix = 'ops.cache.';

  /// Reads a list from the server and keeps the answer ([keep] chooses what of it is worth keeping). When the server cannot be reached (no signal, a
  /// timeout, the server's own fault), the kept answer is shown instead and [onStale] says from when; with nothing kept the error is thrown as before.
  /// Any other answer (a refusal, a signed-out session) is never covered up.
  Future<T> read<T>(
    String key,
    Future<Object?> Function() fetch,
    T Function(Object? raw) parse, {
    Object? Function(Object? raw)? keep,
  }) async {
    final Object? raw;
    try {
      raw = await fetch();
    } on ApiException catch (e) {
      if (!e.isNetwork && e.kind != ApiErrorKind.server) rethrow;
      final saved = _saved(key);
      if (saved == null) rethrow;
      onStale?.call(key, saved.at);
      return parse(saved.data);
    }
    final value = parse(raw);
    try {
      await _prefs.setString(
        _prefix + key,
        jsonEncode({'at': _now().toUtc().toIso8601String(), 'data': keep == null ? raw : keep(raw)}),
      );
    } catch (_) {
      // A full or broken storage never stops the live list.
    }
    onStale?.call(key, null);
    return value;
  }

  /// When [key] was last read from the server, or null when nothing is kept.
  DateTime? savedAt(String key) => _saved(key)?.at;

  ({DateTime at, Object? data})? _saved(String key) {
    try {
      final text = _prefs.getString(_prefix + key);
      if (text == null) return null;
      final m = asMap(jsonDecode(text));
      final at = asDate(m['at']);
      return at == null ? null : (at: at.toLocal(), data: m['data']);
    } catch (_) {
      return null;
    }
  }

  /// Forgets every kept list (the person signed out, or the session ended).
  Future<void> clear() async {
    for (final k in _prefs.getKeys().where((k) => k.startsWith(_prefix)).toList()) {
      await _prefs.remove(k);
    }
  }
}

/// Which lists are shown from the phone right now, and since when (by the cache key). Empty while everything is live.
class OpsStale extends Notifier<Map<String, DateTime>> {
  @override
  Map<String, DateTime> build() => const {};

  void mark(String key, DateTime? since) {
    if (since == null) {
      if (!state.containsKey(key)) return;
      state = {...state}..remove(key);
    } else if (state[key] != since) {
      state = {...state, key: since};
    }
  }

  void clear() => state = const {};
}

final opsStaleProvider = NotifierProvider<OpsStale, Map<String, DateTime>>(OpsStale.new);

/// The oldest "last updated" among the lists whose key starts with [prefix] (null while all of them are live).
DateTime? staleSince(Map<String, DateTime> stale, String prefix) {
  DateTime? oldest;
  stale.forEach((key, at) {
    if (key.startsWith(prefix) && (oldest == null || at.isBefore(oldest!))) oldest = at;
  });
  return oldest;
}

/// The cache of this phone, or null where the phone storage is not available (then the lists behave as before: live or an error).
final opsReadCacheProvider = Provider<OpsReadCache?>((ref) {
  final SharedPreferences prefs;
  try {
    prefs = ref.watch(sharedPreferencesProvider);
  } catch (_) {
    return null;
  }
  return OpsReadCache(prefs, onStale: (key, since) => ref.read(opsStaleProvider.notifier).mark(key, since));
});
