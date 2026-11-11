import 'package:albahar_customer/core/config/app_config.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Shared start-up of the driver and branch staff apps (no push, no storefront features).
Future<void> runOps(OpsRole role) async {
  WidgetsFlutterBinding.ensureInitialized();
  await Future.wait([initializeDateFormatting('ar'), initializeDateFormatting('en')]);
  final prefs = await SharedPreferences.getInstance();
  final container = ProviderContainer(
    overrides: [
      appConfigProvider.overrideWithValue(AppConfig.fromEnvironment()),
      sharedPreferencesProvider.overrideWithValue(prefs),
      opsRoleProvider.overrideWithValue(role),
    ],
    retry: (retryCount, error) => null,
  );
  await container.read(tokenStoreProvider).read();
  runApp(UncontrolledProviderScope(container: container, child: const OpsApp()));
}
