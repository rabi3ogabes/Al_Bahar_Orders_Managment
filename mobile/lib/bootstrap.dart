import 'dart:async';

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/config/app_config.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Shared start-up for every flavour entry point.
Future<void> bootstrap(Flavor? flavor) async {
  WidgetsFlutterBinding.ensureInitialized();
  final config = AppConfig.fromEnvironment(flavor: flavor);

  FlutterError.onError = (details) {
    FlutterError.presentError(details);
    // Hook a crash reporter (e.g. Crashlytics) here for staging/prod.
  };

  await Future.wait([initializeDateFormatting('ar'), initializeDateFormatting('en')]);
  final prefs = await SharedPreferences.getInstance();

  final container = ProviderContainer(
    overrides: [
      appConfigProvider.overrideWithValue(config),
      sharedPreferencesProvider.overrideWithValue(prefs),
    ],
    // Repositories surface errors to the UI; no silent automatic retries.
    retry: (retryCount, error) => null,
  );

  // Load tokens before the first frame so the session state is known
  // synchronously (no flash of the signed-out UI).
  await container.read(tokenStoreProvider).read();

  // Push is optional: without Firebase config files the app runs normally.
  unawaited(container.read(pushServiceProvider).start().catchError((Object e) {
    if (kDebugMode) debugPrint('Push start failed: $e');
  }));

  runApp(UncontrolledProviderScope(container: container, child: const AlBaharApp()));
}
