import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class HomeRepository {
  HomeRepository(this._api);
  final ApiClient _api;

  Future<HomeFeed> load({String? branchId}) async =>
      HomeFeed.fromJson(asMap(await _api.get('/home', query: {'branch_id': branchId})));
}

final homeRepositoryProvider = Provider<HomeRepository>((ref) => HomeRepository(ref.watch(apiClientProvider)));

final homeFeedProvider = FutureProvider<HomeFeed>((ref) {
  ref.watch(localeCodeProvider);
  final branchId = ref.watch(preferredBranchIdProvider);
  return ref.watch(homeRepositoryProvider).load(branchId: branchId);
});
