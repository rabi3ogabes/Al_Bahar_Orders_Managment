import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Which app this build is (set once at start-up by the entry point).
final opsRoleProvider = Provider<OpsRole>((ref) => throw UnimplementedError('opsRoleProvider must be overridden'));

enum OpsAuth { signedOut, signedIn }

class OpsSession extends Notifier<OpsAuth> {
  /// Message to show on the sign-in screen after a refused or failed attempt.
  String? lastError;

  @override
  OpsAuth build() {
    final expiry = ref.watch(sessionExpirySignalProvider);
    void onExpired() {
      state = OpsAuth.signedOut;
    }

    expiry.addListener(onExpired);
    ref.onDispose(() => expiry.removeListener(onExpired));
    return ref.watch(tokenStoreProvider).cached == null ? OpsAuth.signedOut : OpsAuth.signedIn;
  }

  /// Signs in and checks the account can use this app. Returns an error message, or null on success.
  Future<String?> signIn(String email, String password, {required String notEligible}) async {
    final repo = ref.read(opsRepositoryProvider);
    final store = ref.read(tokenStoreProvider);
    try {
      await store.write(await repo.login(email.trim(), password));
      final role = ref.read(opsRoleProvider);
      final ok = role == OpsRole.driver ? await _isDriver(repo) : await _canManageOrders(repo);
      if (!ok) {
        await store.clear();
        return notEligible;
      }
      lastError = null;
      state = OpsAuth.signedIn;
      return null;
    } on ApiException catch (e) {
      await store.clear();
      return e.message ?? e.code ?? 'Error';
    }
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

  Future<void> signOut() async {
    await ref.read(tokenStoreProvider).clear();
    state = OpsAuth.signedOut;
  }
}

final opsSessionProvider = NotifierProvider<OpsSession, OpsAuth>(OpsSession.new);
