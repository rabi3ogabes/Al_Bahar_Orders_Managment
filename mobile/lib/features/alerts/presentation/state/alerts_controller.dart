import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/alerts/data/alerts_repository.dart';
import 'package:albahar_customer/features/alerts/domain/alert_models.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What the store offers; a failed or slow answer reads as the safe default (back-in-stock on, price alerts off).
final alertsConfigProvider = FutureProvider<AlertsConfig>((ref) async {
  try {
    return await ref.read(alertsRepositoryProvider).config();
  } catch (_) {
    return AlertsConfig.fallback;
  }
});

/// The signed-in customer's active alerts: one list for the product page, the wishlist and "My alerts", so every control shows the same state.
class AlertsController extends AsyncNotifier<List<StockAlert>> {
  @override
  Future<List<StockAlert>> build() async {
    if (!ref.watch(isAuthenticatedProvider)) return const [];
    ref.watch(localeCodeProvider);
    return ref.read(alertsRepositoryProvider).list();
  }

  StockAlert? find(String variantId, AlertType type) {
    for (final a in state.value ?? const <StockAlert>[]) {
      if (a.variantId == variantId && a.type == type) return a;
    }
    return null;
  }

  /// Asks for an alert (asking twice changes nothing: the server answers with the one it already has).
  Future<StockAlert> subscribe(String variantId, AlertType type, {String? branchId}) async {
    final created = await ref
        .read(alertsRepositoryProvider)
        .subscribe(variantId: variantId, type: type, branchId: branchId);
    if (ref.mounted) {
      final previous = state.value ?? const <StockAlert>[];
      state = AsyncData([created, ...previous.where((a) => a.id != created.id)]);
    }
    return created;
  }

  /// Optimistic: the alert leaves the list at once and comes back if the server refuses.
  Future<void> remove(StockAlert alert) async {
    final previous = state.value ?? const <StockAlert>[];
    state = AsyncData(previous.where((a) => a.id != alert.id).toList());
    try {
      await ref.read(alertsRepositoryProvider).remove(alert.id);
    } catch (_) {
      if (ref.mounted) state = AsyncData(previous);
      rethrow;
    }
  }
}

final alertsControllerProvider = AsyncNotifierProvider<AlertsController, List<StockAlert>>(AlertsController.new);

/// What a signed-out visitor asked for before the sign-in screen opened, so it is done for them as soon as they are in. An intent older than
/// [maxAge] is forgotten: signing in much later is not a decision about this product any more.
@immutable
class AlertIntent {
  AlertIntent(this.variantId, this.type, {DateTime? at}) : createdAt = at ?? DateTime.now();

  static const maxAge = Duration(minutes: 10);

  final String variantId;
  final AlertType type;
  final DateTime createdAt;

  bool isStale([DateTime? now]) => (now ?? DateTime.now()).difference(createdAt) > maxAge;
}

class PendingAlertIntent extends Notifier<AlertIntent?> {
  @override
  AlertIntent? build() => null;

  void set(AlertIntent? intent) => state = intent;
}

final pendingAlertIntentProvider = NotifierProvider<PendingAlertIntent, AlertIntent?>(PendingAlertIntent.new);
