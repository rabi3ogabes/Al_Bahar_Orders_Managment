import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_exception.dart';
import '../../../../core/providers.dart';
import '../../../../core/push/push_service.dart';
import '../../../../core/utils/json.dart';
import '../../data/auth_repository.dart';
import '../../domain/auth_models.dart';

enum SessionStatus { guest, authenticated }

@immutable
class SessionState {
  const SessionState._(this.status, this.user, {this.expired = false});

  const SessionState.guest({bool expired = false}) : this._(SessionStatus.guest, null, expired: expired);
  const SessionState.authenticated([AppUser? user]) : this._(SessionStatus.authenticated, user);

  final SessionStatus status;

  /// May be null briefly after cold start until `/me` loads.
  final AppUser? user;

  /// True when the guest state results from a revoked/expired session.
  final bool expired;

  bool get isAuthenticated => status == SessionStatus.authenticated;
  String? get userId => user?.id;

  SessionState withUser(AppUser user) => SessionState.authenticated(user);
}

/// Owns the signed-in state. Tokens live in secure storage; the user
/// profile is cached (non-sensitive) so the UI can greet offline.
class SessionController extends Notifier<SessionState> {
  static const _userCacheKey = 'cached_user';

  @override
  SessionState build() {
    final signal = ref.watch(sessionExpirySignalProvider);
    void onExpired() => _expire();
    signal.addListener(onExpired);
    ref.onDispose(() => signal.removeListener(onExpired));

    final tokens = ref.watch(tokenStoreProvider).cached;
    if (tokens == null) return const SessionState.guest();
    final cached = _readCachedUser();
    // Refresh the profile in the background.
    Future.microtask(refreshUser);
    return SessionState.authenticated(cached);
  }

  AuthRepository get _repo => ref.read(authRepositoryProvider);

  Future<void> signIn(Authenticated result) async {
    await ref.read(tokenStoreProvider).write(result.tokens);
    _cacheUser(result.user);
    state = SessionState.authenticated(result.user);
    // Keep the server's locale in sync with the language chosen in-app.
    final appLocale = ref.read(localeCodeProvider);
    if (result.user.locale != appLocale) {
      fireAndForget(updateProfile({'locale': appLocale}).then((_) {}));
    }
  }

  Future<void> refreshUser() async {
    if (!state.isAuthenticated) return;
    try {
      final user = await _repo.me();
      if (!ref.mounted || !state.isAuthenticated) return;
      _cacheUser(user);
      state = state.withUser(user);
    } on ApiException catch (e) {
      // 401 after a failed refresh is handled by the expiry signal.
      if (e.kind == ApiErrorKind.forbidden && e.code == 'account_inactive') await _expire();
    } catch (_) {}
  }

  Future<AppUser> updateProfile(JsonMap changes) async {
    final user = await _repo.updateProfile(changes);
    _cacheUser(user);
    state = state.withUser(user);
    return user;
  }

  void replaceUser(AppUser user) {
    _cacheUser(user);
    state = state.withUser(user);
  }

  Future<void> logout({bool allDevices = false}) async {
    if (!state.isAuthenticated) return;
    // Must run while the token is still valid.
    try {
      await ref.read(pushServiceProvider).unregister();
    } catch (_) {}
    try {
      await _repo.logout(allDevices: allDevices);
    } catch (_) {
      // Best effort: local sign-out always succeeds.
    }
    await _clearLocal();
    state = const SessionState.guest();
  }

  Future<void> _expire() async {
    if (!state.isAuthenticated) return;
    await _clearLocal();
    if (ref.mounted) state = const SessionState.guest(expired: true);
  }

  Future<void> _clearLocal() async {
    await ref.read(tokenStoreProvider).clear();
    await ref.read(sharedPreferencesProvider).remove(_userCacheKey);
  }

  AppUser? _readCachedUser() {
    final raw = ref.read(sharedPreferencesProvider).getString(_userCacheKey);
    if (raw == null) return null;
    try {
      return AppUser.fromJson(asMap(jsonDecode(raw)));
    } catch (_) {
      return null;
    }
  }

  void _cacheUser(AppUser user) {
    unawaited(ref.read(sharedPreferencesProvider).setString(_userCacheKey, jsonEncode(user.toJson())));
  }
}

final sessionControllerProvider = NotifierProvider<SessionController, SessionState>(SessionController.new);

/// Current user id (null for guests or until `/me` loads).
final currentUserIdProvider = Provider<String?>((ref) => ref.watch(sessionControllerProvider).user?.id);

/// User-scoped providers watch this so they reset on sign-in / sign-out.
final isAuthenticatedProvider = Provider<bool>((ref) => ref.watch(sessionControllerProvider).isAuthenticated);
