import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/models/paginated.dart';
import '../../../core/network/api_client.dart';
import '../../../core/providers.dart';
import '../domain/branch_models.dart';

class BranchRepository {
  BranchRepository(this._api);
  final ApiClient _api;

  Future<Paginated<Branch>> list({String? search, bool? pickup, bool? delivery, int page = 1}) => _api.getPage(
    '/branches',
    Branch.fromJson,
    query: {'search': search, 'pickup': pickup, 'delivery': delivery, 'page': page, 'per_page': 50},
  );

  Future<List<Branch>> nearby({required double lat, required double lng, double radiusKm = 30}) async {
    final page = await _api.getPage(
      '/branches/nearby',
      Branch.fromJson,
      query: {'lat': lat, 'lng': lng, 'radius_km': radiusKm},
    );
    return page.items;
  }

  Future<Branch> get(String id) => _api.getObject('/branches/$id', Branch.fromJson);

  Future<BranchHours> hours(String id) => _api.getObject('/branches/$id/hours', BranchHours.fromJson);

  Future<List<PickupSlot>> pickupSlots(String id, String date) =>
      _api.getList('/branches/$id/pickup-slots', PickupSlot.fromJson, query: {'date': date});

  /// `date` must be today … +29 days (Qatar), otherwise the backend answers 422;
  /// 404 when the branch does not deliver.
  Future<List<DeliverySlot>> deliverySlots(String id, String date) =>
      _api.getList('/branches/$id/delivery-slots', DeliverySlot.fromJson, query: {'date': date});

  Future<List<DeliveryZone>> deliveryZones() => _api.getList('/delivery-zones', DeliveryZone.fromJson);
}

final branchRepositoryProvider = Provider<BranchRepository>((ref) => BranchRepository(ref.watch(apiClientProvider)));
