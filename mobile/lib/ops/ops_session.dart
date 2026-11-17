import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/ops/ops_account.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Which app this build is (set once at start-up by the entry point).
final opsRoleProvider = Provider<OpsRole>((ref) => throw UnimplementedError('opsRoleProvider must be overridden'));

/// Where the person stands: signed out, signed in but still holding the temporary password an administrator set (the server refuses
/// every other call until they choose their own), or signed in and free to work.
enum OpsAuth { signedOut, passwordChange, signedIn }

/// How a sign-in attempt ended (anything else is thrown as an [ApiException]).
enum OpsSignIn { success, notEligible, twoFactorRequired, passwordChangeRequired }

class OpsSession extends Notifier<OpsAuth> {
  /// True after the server ended the session (revoked or refresh refused), so the sign-in screen can say why.
  bool expired = false;

  /// Set when the account turned out not to be for this app only after the person chose their own password (the session is gone),
  /// so the sign-in screen can say so in plain words. Cleared by the next sign-in.
  OpsSignIn? refusal;

  /// The phone remembers that the person still has to choose a password, so the next launch opens that screen at once.
  static const _mustChangeKey = 'ops.must_change_password';

  @override
  OpsAuth build() {
    final expiry = ref.watch(sessionExpirySignalProvider);
    void onExpired() {
      if (state != OpsAuth.signedOut) expired = true;
      unawaited(_remember(false));
      state = OpsAuth.signedOut;
    }

    expiry.addListener(onExpired);
    ref.onDispose(() => expiry.removeListener(onExpired));

    // Any staff call may be told "choose your own password first" (a reset while the app was open, a cold start): one place listens.
    final dio = ref.read(apiClientProvider).dio;
    final guard = PasswordChangeInterceptor(_onPasswordChangeRequired);
    dio.interceptors.add(guard);
    ref.onDispose(() => dio.interceptors.remove(guard));

    if (ref.watch(tokenStoreProvider).cached == null) return OpsAuth.signedOut;
    if (_prefs?.getBool(_mustChangeKey) != true) return OpsAuth.signedIn;
    // The flag is only a memory: the person may have chosen their password on the dashboard since, so ask the server (once).
    scheduleMicrotask(() => unawaited(recheck().catchError((Object _) {})));
    return OpsAuth.passwordChange;
  }

  SharedPreferences? get _prefs {
    try {
      return ref.read(sharedPreferencesProvider);
    } catch (_) {
      return null;
    }
  }

  Future<void> _remember(bool mustChange) async {
    try {
      final prefs = _prefs;
      if (prefs == null) return;
      if (mustChange) {
        await prefs.setBool(_mustChangeKey, true);
      } else {
        await prefs.remove(_mustChangeKey);
      }
    } catch (_) {}
  }

  void _onPasswordChangeRequired() {
    // While a sign-in is in progress it handles the answer itself; a signed-out phone has nothing to protect.
    if (state != OpsAuth.signedIn) return;
    unawaited(_remember(true));
    state = OpsAuth.passwordChange;
  }

  /// Signs in (with an e-mail or a mobile number) and checks the account can use this app. A refused account, a missing two-factor
  /// code or a temporary password are normal outcomes; every other failure is thrown so the caller can show the localised message.
  ///
  /// An account whose password an administrator set goes straight to [OpsAuth.passwordChange]: the server refuses everything else
  /// until the person has chosen their own, so whether the account suits this app is checked after that (see [changePassword]).
  Future<OpsSignIn> signIn(String email, String password, {String? twoFactorCode}) async {
    final repo = ref.read(opsRepositoryProvider);
    final store = ref.read(tokenStoreProvider);
    refusal = null;
    try {
      final login = await repo.login(normalizeLoginName(email), password, twoFactorCode: twoFactorCode);
      await store.write(login.tokens);
      if (login.mustChangePassword) return await _askForOwnPassword();
      if (!await _isEligible(repo)) {
        await _discardSession(repo, store);
        return OpsSignIn.notEligible;
      }
      await _remember(false);
      expired = false;
      state = OpsAuth.signedIn;
      return OpsSignIn.success;
    } on ApiException catch (e) {
      // The flag was set between the sign-in and the first look-up: same as being told at the sign-in.
      if (e.code == kPasswordChangeRequired && store.cached != null) return _askForOwnPassword();
      await store.clear();
      if (e.code == 'two_factor_required') return OpsSignIn.twoFactorRequired;
      rethrow;
    }
  }

  Future<OpsSignIn> _askForOwnPassword() async {
    expired = false;
    await _remember(true);
    state = OpsAuth.passwordChange;
    return OpsSignIn.passwordChangeRequired;
  }

  /// Sends the person's own password. The server keeps this device signed in (and signs the others out), so the same tokens go on
  /// working; on success the app opens like after a normal sign-in. A refusal is thrown as an [ApiException] (422 with the field
  /// messages in `errors`) for the screen to show next to the field.
  Future<void> changePassword({required String current, required String password, required String confirmation}) async {
    final repo = ref.read(opsRepositoryProvider);
    await repo.changePassword(current: current, password: password, confirmation: confirmation);
    await _remember(false);
    await _enterAfterPasswordStep(repo);
  }

  /// Asks the server whether the person still has to choose a password (they may have done it on the dashboard since the phone last
  /// asked) and opens the app if not. Does nothing while the screen is not showing.
  Future<void> recheck() async {
    if (state != OpsAuth.passwordChange) return;
    final repo = ref.read(opsRepositoryProvider);
    final account = await repo.account();
    if (!ref.mounted || state != OpsAuth.passwordChange || account.mustChangePassword) return;
    await _remember(false);
    await _enterAfterPasswordStep(repo);
  }

  /// The password is settled: the account is checked like at a normal sign-in. A look-up that fails now (no network) must not undo
  /// what was just done, so the app opens and its own screens show the problem.
  Future<void> _enterAfterPasswordStep(OpsRepository repo) async {
    final store = ref.read(tokenStoreProvider);
    var eligible = true;
    try {
      eligible = await _isEligible(repo);
    } catch (_) {}
    if (!ref.mounted) return;
    expired = false;
    if (eligible) {
      state = OpsAuth.signedIn;
      return;
    }
    await _discardSession(repo, store);
    refusal = OpsSignIn.notEligible;
    state = OpsAuth.signedOut;
  }

  Future<bool> _isEligible(OpsRepository repo) =>
      ref.read(opsRoleProvider) == OpsRole.driver ? _isDriver(repo) : _canManageOrders(repo);

  /// A wrong-app account must not keep a live token on the server.
  Future<void> _discardSession(OpsRepository repo, TokenStore store) async {
    try {
      await repo.logout();
    } catch (_) {}
    await store.clear();
  }

  Future<bool> _isDriver(OpsRepository repo) async {
    try {
      await repo.driver();
      return true;
    } on ApiException catch (e) {
      // "Choose your own password first" is also a 403, but says nothing about the account being a driver.
      if (e.statusCode == 403 && e.code != kPasswordChangeRequired) return false;
      rethrow;
    }
  }

  Future<bool> _canManageOrders(OpsRepository repo) async {
    final p = await repo.permissions();
    return p.contains('*') || p.contains('orders.view');
  }

  /// Ends the session on the server (best effort), forgets the push token and clears the local tokens.
  Future<void> signOut() async {
    try {
      await ref.read(pushServiceProvider).unregister();
    } catch (_) {}
    try {
      await ref.read(opsRepositoryProvider).logout();
    } catch (_) {}
    await ref.read(tokenStoreProvider).clear();
    await _remember(false);
    expired = false;
    refusal = null;
    state = OpsAuth.signedOut;
  }
}

final opsSessionProvider = NotifierProvider<OpsSession, OpsAuth>(OpsSession.new);
