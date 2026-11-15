import 'dart:async';

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../helpers/fakes.dart';

/// A messaging SDK that is on and allowed, with one token.
class _Sdk extends NoopPushMessaging {
  @override
  Future<bool> initialize() async => true;

  @override
  Future<bool> requestPermission({bool provisional = false}) async => true;

  @override
  Future<PushPermission> permissionStatus() async => PushPermission.granted;

  @override
  Future<String?> getToken() async => 'tok-1';
}

/// The app widget, signed in, with a server that counts unread notifications. What the push service tells the app (a message arrived,
/// one was opened, the language changed, the person signed out) must reach the server and the screens.
void main() {
  late int unread;
  late TestHarness h;

  Future<void> boot(WidgetTester t) async {
    // The version number the device reports; without it the test waits for a real platform answer that never comes.
    PackageInfo.setMockInitialValues(
      appName: 'Al Bahar',
      packageName: 'qa.albahar.customer',
      version: '1.0.0',
      buildNumber: '1',
      buildSignature: '',
    );
    unread = 3;
    h = await TestHarness.create(
      tokens: AuthTokens.fromJson(tokens('A', 'R')),
      prefs: {'locale': 'ar', 'onboarding_done': true},
      // The real service (the app's own wiring is what is under test) on a messaging SDK that is on and allowed.
      overrides: [
        pushServiceProvider.overrideWith(
          (ref) => PushService(
            messaging: _Sdk(),
            local: ref.watch(localNotifierProvider),
            api: ref.watch(apiClientProvider),
            prefs: ref.watch(appPreferencesProvider),
            enabled: true,
            askPermissionOnRegister: false,
            localeCode: () => ref.read(localeCodeProvider),
            platform: () => 'android',
          ),
        ),
      ],
      handler: routes({
        'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+97455123456', 'locale': 'ar'}),
        'GET /me/notifications': (_) => page([], meta: {'unread_count': unread}),
        'POST /me/notifications/n1/read': (_) {
          unread--;
          return ok({'read': true});
        },
        'POST /me/devices': (_) => ok({'registered': true}),
        'DELETE /me/devices': (_) => ok({'unregistered': true}),
        'POST /auth/logout': (_) => ok({'logged_out': true}),
      }),
    );
    await t.pumpWidget(
      UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
    );
    await t.pump(const Duration(milliseconds: 1600));
    await t.pump(const Duration(milliseconds: 500));
    await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
  }

  Future<void> settle(WidgetTester t) async {
    await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
    await t.pump(const Duration(milliseconds: 100));
  }

  Future<void> teardown(WidgetTester t) async {
    await t.pumpWidget(const SizedBox.shrink());
    await t.pump(const Duration(seconds: 10));
  }

  testWidgets('the phone is registered at launch, in the language of the app', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await boot(t);

    final post = h.adapter.where('POST', '/me/devices').single;
    expect(post.body, containsPair('locale', 'ar'));
    expect(post.body, containsPair('push_token', 'tok-1'));
    await teardown(t);
  });

  testWidgets('a message that arrives while the app is open brings the unread badge up to date', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await boot(t);
    expect(h.read(unreadCountProvider), 3);

    unread = 4; // the server put the message in the inbox
    h
        .read(pushServiceProvider)
        .debugHandleForeground(const PushMessage(data: {'event': 'payment.failed', 'log_id': 'l1'}, title: 'Payment'));
    await settle(t);

    expect(h.read(unreadCountProvider), 4);
    await teardown(t);
  });

  testWidgets('opening a notification marks it read and the badge follows once the server has the change', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await boot(t);
    expect(h.read(unreadCountProvider), 3);

    h.read(pushServiceProvider).debugHandleTap({
      'event': 'order.confirmed',
      'deep_link': 'albahar://orders/o1',
      'notification_id': 'n1',
    });
    await settle(t);

    expect(h.adapter.where('POST', '/me/notifications/n1/read'), hasLength(1));
    expect(h.read(unreadCountProvider), 2);
    await teardown(t);
  });

  testWidgets('switching the language tells the server which language this phone speaks', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await boot(t);
    expect(h.adapter.where('POST', '/me/devices'), hasLength(1));

    await h.read(localeControllerProvider.notifier).setLocale(const Locale('en'));
    await settle(t);

    final posts = h.adapter.where('POST', '/me/devices');
    expect(posts, hasLength(2));
    expect(posts.last.body, containsPair('locale', 'en'));
    expect(posts.last.body, containsPair('push_token', 'tok-1'));
    await teardown(t);
  });

  testWidgets('signing out removes the phone from the account and forgets its banner preferences', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await boot(t);
    final prefs = h.read(appPreferencesProvider);
    await prefs.setPushCategoryPrefs({'marketing': false});
    expect(prefs.registeredPushToken, 'tok-1');

    unawaited(h.read(sessionControllerProvider.notifier).logout());
    await settle(t);

    expect(h.adapter.where('DELETE', '/me/devices').single.body, {'push_token': 'tok-1'});
    expect(prefs.registeredPushToken, isNull);
    expect(prefs.pushCategoryPrefs, isEmpty);
    await teardown(t);
  });

  testWidgets('a session that ended behind the person’s back also cleans the phone', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await boot(t);
    final prefs = h.read(appPreferencesProvider);
    await prefs.setPushCategoryPrefs({'marketing': false});

    h.read(sessionExpirySignalProvider).fire();
    await settle(t);

    expect(h.read(isAuthenticatedProvider), isFalse);
    expect(prefs.registeredPushToken, isNull);
    expect(prefs.pushCategoryPrefs, isEmpty);
    expect(
      h.adapter.where('DELETE', '/me/devices'),
      isEmpty,
      reason: 'the server cannot be asked without a valid session',
    );
    await teardown(t);
  });
}
