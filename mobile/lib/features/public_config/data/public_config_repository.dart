import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/public_config/domain/public_config.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class PublicConfigRepository {
  PublicConfigRepository(this._api, {this.timeout = const Duration(seconds: 6)});
  final ApiClient _api;

  /// How long the first screen waits for the server's answer before it carries on with what it has.
  final Duration timeout;

  /// Throws when the answer is not the dashboard's JSON object (a captive portal's page, say), so a bad answer can never replace
  /// the last good one.
  Future<PublicConfig> load() async {
    final data = await _api.get('/app-config').timeout(timeout);
    if (data is! Map) throw const FormatException('The app configuration is not an object.');
    return PublicConfig.fromJson(asMap(data));
  }
}

final publicConfigRepositoryProvider = Provider<PublicConfigRepository>(
  (ref) => PublicConfigRepository(ref.watch(apiClientProvider)),
);
