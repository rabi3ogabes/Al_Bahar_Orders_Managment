import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/alerts/domain/alert_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class AlertsRepository {
  AlertsRepository(this._api);
  final ApiClient _api;

  /// `GET /stock-alerts/config`: public.
  Future<AlertsConfig> config() => _api.getObject('/stock-alerts/config', AlertsConfig.fromJson);

  /// `GET /me/stock-alerts`: the customer's active alerts, newest first.
  Future<List<StockAlert>> list() => _api.getList('/me/stock-alerts', StockAlert.fromJson);

  /// `POST /me/stock-alerts`: 201 when new, 200 when the customer already had it. Returns the alert.
  Future<StockAlert> subscribe({required String variantId, required AlertType type, String? branchId}) async {
    final data = asMap(
      await _api.post('/me/stock-alerts', data: {'variant_id': variantId, 'type': type.wire, 'branch_id': ?branchId}),
    );
    return StockAlert.fromJson(asMap(data['alert']));
  }

  /// `DELETE /me/stock-alerts/{id}`: cancelling what is already gone is fine.
  Future<void> remove(String id) async => _api.delete('/me/stock-alerts/$id');
}

final alertsRepositoryProvider = Provider<AlertsRepository>((ref) => AlertsRepository(ref.watch(apiClientProvider)));
