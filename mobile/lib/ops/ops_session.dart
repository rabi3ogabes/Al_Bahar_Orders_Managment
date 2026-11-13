import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Which app this build is (set once at start-up by the entry point).
final opsRoleProvider = Provider<OpsRole>((ref) => throw UnimplementedError('opsRoleProvider must be overridden'));

enum OpsAuth { signedOut, signedIn }

/// How a sign-in attempt ended (anything else is thrown as an [ApiException]).
enum OpsSignIn { success, notEligible, twoFactorRequired }

class OpsSession extends Notifier<OpsAuth> {
  /// True after the server ended the session (revoked or refresh refused), so the sign-in screen can say why.
  bool expired = false;

  @override
  OpsAuth build() {
    final expiry = ref.watch(sessionExpirySignalProvider);
    void onExpired() {
      if (state == OpsAuth.signedIn) expired = true;
      state = OpsAuth.signedOut;
    }

    expiry.addListener(onExpired);
    ref.onDispose(() => expiry.removeListener(onExpired));
    return ref.watch(tokenStoreProvider).cached == null ? OpsAuth.signedOut : OpsAuth.signedIn;
  }

  /// Signs in and checks the account can use this app. A refused account or a missing two-factor code is a normal
  /// outcome; every other failure is thrown so the caller can show the localised message.
  Future<OpsSignIn> signIn(String email, String password, {String? twoFactorCode}) async {
    final repo = ref.read(opsRepositoryProvider);
    final store = ref.read(tokenStoreProvider);
    try {
      await store.write(await repo.login(email.trim(), password, twoFactorCode: twoFactorCode));
      final role = ref.read(opsRoleProvider);
      final ok = role == OpsRole.driver ? await _isDriver(repo) : await _canManageOrders(repo);
      if (!ok) {
        await _discardSession(repo, store);
        return OpsSignIn.notEligible;
      }
      expired = false;
      state = OpsAuth.signedIn;
      return OpsSignIn.success;
    } on ApiException catch (e) {
      await store.clear();
      if (e.code == 'two_factor_required') return OpsSignIn.twoFactorRequired;
      rethrow;
    }
  }

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
      if (e.statusCode == 403) return false;
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
    expired = false;
    state = OpsAuth.signedOut;
  }
}

final opsSessionProvider = NotifierProvider<OpsSession, OpsAuth>(OpsSession.new);
