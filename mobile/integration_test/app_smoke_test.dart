// On-device smoke test (Android emulator / iOS simulator / physical device).
//
//   flutter test integration_test/app_smoke_test.dart                 # fake backend
//   flutter test integration_test/app_smoke_test.dart \
//     --dart-define=LIVE_API=true --dart-define=API_BASE_URL=https://staging-api.albahar.qa/api/v1
//
// The default run uses an in-process fake API so it is deterministic in CI;
// LIVE_API=true exercises the real staging backend (read-only screens).
import 'dart:io' show Platform;

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/config/app_config.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../test/helpers/fakes.dart';

const _live = bool.fromEnvironment('LIVE_API');

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  Future<ProviderContainer> start({required String locale}) async {
    SharedPreferences.setMockInitialValues({'locale': locale, 'onboarding_done': true});
    final prefs = await SharedPreferences.getInstance();
    final config = _live ? AppConfig.fromEnvironment(flavor: Flavor.staging) : testConfig;
    final store = MemoryTokenStore();
    final adapter = FakeAdapter(
      routes({
        'GET /home': (_) => ok({
          'sections': [
            {
              'id': 'hero',
              'type': 'banners',
              'items': [
                {'id': 'b1', 'title': 'The Autumn Edit', 'image_url': null},
              ],
            },
            {
              'id': 'new',
              'type': 'new_arrivals',
              'title': 'New in',
              'items': [productCard('1'), productCard('2'), productCard('3')],
            },
          ],
        }),
        'GET /categories': (_) => ok([
          {'id': 'c1', 'name': 'Living room', 'children': <Object>[]},
        ]),
        'GET /products': (_) => page([productCard('1'), productCard('2')]),
        'GET /auctions': (_) => page([]),
      }),
    );
    final container = ProviderContainer(
      overrides: [
        appConfigProvider.overrideWithValue(config),
        sharedPreferencesProvider.overrideWithValue(prefs),
        tokenStoreProvider.overrideWithValue(store),
        pushMessagingProvider.overrideWithValue(NoopPushMessaging()),
        if (!_live)
          apiClientProvider.overrideWith(
            (ref) => ApiClient.create(
              config: config,
              tokenStore: store,
              localeCode: () => ref.read(localeCodeProvider),
              onSessionExpired: () async {},
              adapter: adapter,
            ),
          ),
      ],
      retry: (_, _) => null,
    );
    return container;
  }

  for (final locale in ['en', 'ar']) {
    testWidgets('boots, renders home and navigates the shell ($locale)', (tester) async {
      final container = await start(locale: locale);
      addTearDown(container.dispose);
      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: AlBaharApp(watchConnectivity: !Platform.isLinux),
        ),
      );
      await tester.pump(const Duration(seconds: 2));
      await tester.pump(const Duration(seconds: 1));

      expect(find.byType(NavigationBar), findsOneWidget);
      if (!_live) expect(find.text('The Autumn Edit'), findsOneWidget);

      final rtl = Directionality.of(tester.element(find.byType(NavigationBar))) == TextDirection.rtl;
      expect(rtl, locale == 'ar');

      // Shop tab → categories.
      await tester.tap(find.byIcon(Icons.grid_view_outlined));
      await tester.pump(const Duration(seconds: 1));
      // Auctions tab.
      await tester.tap(find.byIcon(Icons.gavel_outlined));
      await tester.pump(const Duration(seconds: 1));
      // Account tab (guest view).
      await tester.tap(find.byIcon(Icons.person_outline_rounded));
      await tester.pump(const Duration(seconds: 1));
      expect(find.byType(ListTile), findsWidgets);
    });
  }
}
