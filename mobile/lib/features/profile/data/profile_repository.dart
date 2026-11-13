import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class ProfileRepository {
  ProfileRepository(this._api);
  final ApiClient _api;

  // Addresses
  Future<List<Address>> addresses() async => (await _api.getPage('/me/addresses', Address.fromJson)).items;
  Future<Address> address(String id) => _api.getObject('/me/addresses/$id', Address.fromJson);
  Future<Address> createAddress(AddressInput input) async =>
      Address.fromJson(asMap(await _api.post('/me/addresses', data: input.toJson())));
  Future<Address> updateAddress(String id, AddressInput input) async =>
      Address.fromJson(asMap(await _api.patch('/me/addresses/$id', data: input.toJson())));
  Future<void> deleteAddress(String id) async => _api.delete('/me/addresses/$id');
  Future<void> setDefaultAddress(String id) async => _api.post('/me/addresses/$id/default');

  // Notification preferences
  Future<List<NotificationPreference>> notificationPreferences() =>
      _api.getList('/me/notification-preferences', NotificationPreference.fromJson);

  Future<List<NotificationPreference>> saveNotificationPreferences(List<NotificationPreference> prefs) async {
    final data = await _api.put(
      '/me/notification-preferences',
      data: {'preferences': prefs.map((p) => p.toJson()).toList()},
    );
    final list = mapList(data, NotificationPreference.fromJson);
    return list.isEmpty ? prefs : list;
  }

  // Loyalty & referral
  Future<LoyaltySummary> loyalty() => _api.getObject('/me/loyalty', LoyaltySummary.fromJson);
  Future<bool> referralEnabled() async => asMap(await _api.get('/referrals/status'))['enabled'] == true;
  Future<ReferralInfo> referral() => _api.getObject('/me/referral', ReferralInfo.fromJson);

  // Account deletion
  Future<DeletionRequest> requestDeletion({String? reason}) async => DeletionRequest.fromJson(
    asMap(await _api.post('/me/deletion-request', data: {if (reason != null && reason.isNotEmpty) 'reason': reason})),
  );
  Future<void> cancelDeletion() async => _api.delete('/me/deletion-request');
}

final profileRepositoryProvider = Provider<ProfileRepository>((ref) => ProfileRepository(ref.watch(apiClientProvider)));
