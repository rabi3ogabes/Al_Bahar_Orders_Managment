// The dashboard's "Alternative phone number" switch in the customer app: `GET /app-config` → `alternate_phone_enabled`
// (on by default). Off hides the field and every word about it (profile, checkout), never holds the order back and sends nothing
// about it, whatever was typed or saved before.
import 'dart:async';

import 'package:albahar_customer/core/providers.dart' show appPreferencesProvider, supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/profile/presentation/edit_profile_screen.dart';
import 'package:albahar_customer/features/public_config/domain/public_config.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/checkout_backend.dart';
import '../helpers/fakes.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

const _fieldKey = ValueKey('alternate-phone-field');
const _noneKey = ValueKey('alternate-phone-none');
const _profileKey = ValueKey('profile-alternate-phone');

Future<void> _settle(WidgetTester t, [int frames = 24]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

Widget _app(ProviderContainer container, Widget home, {Locale locale = const Locale('en')}) =>
    UncontrolledProviderScope(
      container: container,
      child: MaterialApp(
        locale: locale,
        supportedLocales: supportedLocales,
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: AppTheme.light(locale),
        home: home,
      ),
    );

void main() {
  group('PublicConfig.alternatePhoneEnabled', () {
    test('on when the server says nothing (an older server, a missing key, nothing cached)', () {
      expect(PublicConfig.empty.alternatePhoneEnabled, isTrue);
      expect(PublicConfig.fromJson(const {}).alternatePhoneEnabled, isTrue);
      expect(PublicConfig.fromJson({'alternate_phone_enabled': null}).alternatePhoneEnabled, isTrue);
      expect(PublicConfig.fromJson({'alternate_phone_enabled': <String>[]}).alternatePhoneEnabled, isTrue);
    });

    test('exactly what the dashboard sends: true is on, false is off', () {
      expect(PublicConfig.fromJson({'alternate_phone_enabled': true}).alternatePhoneEnabled, isTrue);
      expect(PublicConfig.fromJson({'alternate_phone_enabled': false}).alternatePhoneEnabled, isFalse);
      // A server that sends 0/1 or the words still means what it says.
      expect(PublicConfig.fromJson({'alternate_phone_enabled': 0}).alternatePhoneEnabled, isFalse);
      expect(PublicConfig.fromJson({'alternate_phone_enabled': 1}).alternatePhoneEnabled, isTrue);
      expect(PublicConfig.fromJson({'alternate_phone_enabled': 'false'}).alternatePhoneEnabled, isFalse);
      expect(PublicConfig.fromJson({'alternate_phone_enabled': 'true'}).alternatePhoneEnabled, isTrue);
    });

    test('is remembered with the rest of the config and takes part in equality', () {
      const off = PublicConfig(alternatePhoneEnabled: false);
      expect(PublicConfig.fromJson(off.toJson()), off);
      expect(off.toJson()['alternate_phone_enabled'], isFalse);
      expect(off == const PublicConfig(), isFalse);
      expect(off.hashCode == const PublicConfig().hashCode, isFalse);
    });

    test('a cache written before the switch existed reads as on', () {
      expect(
        PublicConfig.fromJson({
          'designed_by': {'en': 'Designed by X', 'ar': ''},
        }).alternatePhoneEnabled,
        isTrue,
      );
    });
  });

  group('alternatePhoneEnabledProvider', () {
    Future<ProviderContainer> start({Map<String, Object> prefs = const {}, FakeHandler? handler}) async {
      final h = await TestHarness.create(prefs: {'locale': 'en', ...prefs}, handler: handler);
      final sub = h.container.listen(alternatePhoneEnabledProvider, (_, _) {});
      addTearDown(sub.close);
      return h.container;
    }

    test('is on before anything is known, and stays on when the server cannot be reached', () async {
      final c = await start(handler: (_) => error(503, 'unavailable'));
      expect(c.read(alternatePhoneEnabledProvider), isTrue);
      await pumpEventQueue();
      expect(c.read(alternatePhoneEnabledProvider), isTrue);
    });

    test('the server turning it off reaches the screens, and is remembered for the next launch', () async {
      final c = await start(
        handler: routes({
          'GET /app-config': (_) => ok({'alternate_phone_enabled': false}),
        }),
      );
      expect(c.read(alternatePhoneEnabledProvider), isTrue, reason: 'on until the answer arrives');
      await pumpEventQueue();
      expect(c.read(alternatePhoneEnabledProvider), isFalse);
      expect(c.read(appPreferencesProvider).publicConfig?['alternate_phone_enabled'], isFalse);
    });

    test('the last answer is used at once on the next launch, before any network', () async {
      final answer = Completer<FakeReply>();
      final c = await start(
        prefs: {'public_config': '{"designed_by":{"en":"","ar":""},"alternate_phone_enabled":false}'},
        handler: (_) => answer.future,
      );
      expect(c.read(alternatePhoneEnabledProvider), isFalse);
      answer.complete(ok({'alternate_phone_enabled': true}));
      await pumpEventQueue();
      expect(c.read(alternatePhoneEnabledProvider), isTrue, reason: 'a newer answer replaces it');
    });

    test('offline or a bad answer keeps the cached value', () async {
      final c = await start(
        prefs: {'public_config': '{"alternate_phone_enabled":false}'},
        handler: (_) => const FakeReply(200, 'welcome to the cafe wifi'),
      );
      await pumpEventQueue();
      expect(c.read(alternatePhoneEnabledProvider), isFalse);
    });

    testWidgets('coming back to the app asks the server again, so a dashboard change reaches an open phone', (t) async {
      var enabled = true;
      var asked = 0;
      final h = await TestHarness.create(
        handler: routes({
          'GET /app-config': (_) {
            asked++;
            return ok({'alternate_phone_enabled': enabled});
          },
        }),
      );
      final sub = h.container.listen(alternatePhoneEnabledProvider, (_, _) {});
      addTearDown(sub.close);
      await _settle(t, 4);
      expect(asked, 1);
      expect(h.read(alternatePhoneEnabledProvider), isTrue);

      enabled = false;
      final binding = TestWidgetsFlutterBinding.instance;
      binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await _settle(t, 4);
      expect(asked, 1, reason: 'going to the background asks nothing');
      binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await _settle(t, 4);
      expect(asked, 2);
      expect(h.read(alternatePhoneEnabledProvider), isFalse);
    });
  });

  group('profile', () {
    /// What the server's `alternate_phone_enabled` says right now (a test flips it, then the app asks again).
    var serverEnabled = true;

    Future<TestHarness> pump(
      WidgetTester t, {
      bool? enabled,
      Locale locale = const Locale('en'),
      String? alternate = '+97455987654',
    }) async {
      serverEnabled = enabled ?? true;
      t.view.physicalSize = const Size(390, 1600);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final user = {
        'id': 'u1',
        'name': 'Noor',
        'phone': '+97455123456',
        'alternate_phone': alternate,
        'email': 'noor@example.com',
        'marketing_consent': false,
      };
      final h = await TestHarness.create(
        tokens: _signedIn,
        prefs: {
          'locale': locale.languageCode,
          'onboarding_done': true,
          if (enabled != null) 'public_config': '{"alternate_phone_enabled":$enabled}',
        },
        handler: routes({
          'GET /me': (_) => ok(user),
          'PATCH /me': (_) => ok(user),
          'GET /app-config': (_) => ok({'alternate_phone_enabled': serverEnabled}),
        }),
      );
      h.container.listen(sessionControllerProvider, (_, _) {});
      h.container.listen(publicConfigProvider, (_, _) {});
      await t.pumpWidget(_app(h.container, const Scaffold(body: SizedBox.shrink()), locale: locale));
      await _settle(t, 4);
      expect(h.read(sessionControllerProvider).user?.name, 'Noor');
      final context = t.element(find.byType(Scaffold));
      unawaited(
        Navigator.of(context)
            .push(MaterialPageRoute<bool>(builder: (_) => const Scaffold(body: EditProfileScreen(embedded: true)))),
      );
      await _settle(t, 8);
      return h;
    }

    testWidgets('on: the field is there with the saved number, and a save sends it', (t) async {
      final h = await pump(t, enabled: true);
      expect(find.byKey(_profileKey), findsOneWidget);
      expect(find.widgetWithText(TextField, '55987654'), findsOneWidget);
      await t.tap(find.text('Save'));
      await _settle(t, 8);
      final body = h.adapter.where('PATCH', '/me').single.body as Map;
      expect(body['alternate_phone'], '+97455987654');
    });

    testWidgets('no answer yet and nothing cached: still on, exactly as before the switch', (t) async {
      await pump(t);
      expect(find.byKey(_profileKey), findsOneWidget);
    });

    testWidgets('off: no field and no word about it, and a save sends nothing about it', (t) async {
      final h = await pump(t, enabled: false);
      expect(find.byKey(_profileKey), findsNothing);
      expect(find.textContaining('Alternative phone'), findsNothing);
      expect(find.textContaining('another number'), findsNothing);
      await t.enterText(find.widgetWithText(TextField, 'Noor'), 'Noor Al Thani');
      await t.pump();
      await t.tap(find.text('Save'));
      await _settle(t, 8);
      final body = h.adapter.where('PATCH', '/me').single.body as Map;
      expect(body['name'], 'Noor Al Thani');
      expect(body.containsKey('alternate_phone'), isFalse, reason: 'the saved number stays untouched on the server');
    });

    testWidgets('off in Arabic: no alternative-number field either', (t) async {
      await pump(t, enabled: false, locale: const Locale('ar'));
      expect(find.byKey(_profileKey), findsNothing);
      expect(find.textContaining('رقم هاتف بديل'), findsNothing);
    });

    testWidgets('typed while it was on, then switched off before saving: the typed number is left out of the request', (
      t,
    ) async {
      final h = await pump(t, enabled: true, alternate: null);
      await t.enterText(find.descendant(of: find.byKey(_profileKey), matching: find.byType(TextField)), '55987654');
      await t.pump();
      serverEnabled = false;
      unawaited(h.read(publicConfigProvider.notifier).refresh());
      await _settle(t, 4);
      expect(find.byKey(_profileKey), findsNothing);
      await t.tap(find.text('Save'));
      await _settle(t, 8);
      final body = h.adapter.where('PATCH', '/me').single.body as Map;
      expect(body.containsKey('alternate_phone'), isFalse);
    });
  });

  group('checkout', () {
    CheckoutBackend backend({bool? enabled, String? saved}) {
      final d = qDay(1);
      return CheckoutBackend(
        savedAlternatePhone: saved,
        deliveryPublishesSlots: false,
        delivery: {
          'br1': {
            d: [slotRow('s-16', d, '16:00', '18:00')],
          },
        },
        pickup: {
          'br1': {
            d: [slotRow('p-10', d, '10:00', '12:00')],
          },
        },
      )..alternatePhoneEnabled = enabled;
    }

    Future<TestHarness> pump(
      WidgetTester t,
      CheckoutBackend backend, {
      Locale locale = const Locale('en'),
      Map<String, Object> prefs = const {},
    }) async {
      t.view.physicalSize = const Size(900, 3200);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: backend.handler,
        prefs: {'locale': locale.languageCode, 'onboarding_done': true, ...prefs},
      );
      h.container.listen(checkoutControllerProvider, (_, _) {});
      h.container.listen(sessionControllerProvider, (_, _) {});
      await t.pumpWidget(_app(h.container, const CheckoutScreen(), locale: locale));
      await _settle(t);
      return h;
    }

    FilledButton place(WidgetTester t) => t.widget<FilledButton>(find.widgetWithText(FilledButton, 'Place order'));
    CheckoutState state(TestHarness h) => h.read(checkoutControllerProvider);

    testWidgets('on: the field and the tick are there and the order waits for an answer, as before', (t) async {
      await pump(t, backend(enabled: true), prefs: {'public_config': '{"alternate_phone_enabled":true}'});
      expect(find.byKey(_fieldKey), findsOneWidget);
      expect(find.byKey(_noneKey), findsOneWidget);
      expect(place(t).onPressed, isNull);
    });

    testWidgets('off: no field, no tick, no hint, and the order is not held back', (t) async {
      final h = await pump(t, backend(enabled: false), prefs: {'public_config': '{"alternate_phone_enabled":false}'});
      expect(find.byKey(_fieldKey), findsNothing);
      expect(find.byKey(_noneKey), findsNothing);
      expect(find.textContaining('alternative number'), findsNothing);
      expect(find.textContaining('another number'), findsNothing);
      expect(state(h).alternateEnabled, isFalse);
      expect(state(h).missingRequirement, isNull);
      expect(place(t).onPressed, isNotNull);
    });

    testWidgets('off: neither the quote nor the order carries anything about the number, though one is saved', (
      t,
    ) async {
      final h = await pump(
        t,
        backend(enabled: false, saved: '+97455987654'),
        prefs: {'public_config': '{"alternate_phone_enabled":false}'},
      );
      expect(state(h).alternate.text, '55987654', reason: 'the saved number is still known, just never used');
      final result = h.read(checkoutControllerProvider.notifier).placeOrder();
      await _settle(t);
      await result;
      final quote = h.adapter.where('POST', '/checkout/quote').last.body as Map;
      final order = h.adapter.where('POST', '/checkout/orders').single.body as Map;
      for (final body in [quote, order]) {
        expect(body.containsKey('alternate_phone'), isFalse);
        expect(body.containsKey('no_alternate_phone'), isFalse);
      }
    });

    testWidgets('off in Arabic: no alternative-number words anywhere on the page', (t) async {
      await pump(
        t,
        backend(enabled: false),
        locale: const Locale('ar'),
        prefs: {'public_config': '{"alternate_phone_enabled":false}'},
      );
      expect(find.byKey(_fieldKey), findsNothing);
      expect(find.textContaining('رقم هاتف بديل'), findsNothing);
      expect(find.textContaining('رقم آخر'), findsNothing);
    });

    testWidgets(
      'the dashboard switching it off while checkout is open removes the question and what was typed is not sent',
      (t) async {
        final b = backend(enabled: true);
        final h = await pump(t, b);
        await t.enterText(find.descendant(of: find.byKey(_fieldKey), matching: find.byType(TextField)), '55987654');
        await _settle(t, 4);
        expect(place(t).onPressed, isNotNull);

        b.alternatePhoneEnabled = false;
        unawaited(h.read(publicConfigProvider.notifier).refresh());
        await _settle(t, 6);
        expect(find.byKey(_fieldKey), findsNothing);
        expect(state(h).alternateEnabled, isFalse);

        final result = h.read(checkoutControllerProvider.notifier).placeOrder();
        await _settle(t);
        await result;
        final order = h.adapter.where('POST', '/checkout/orders').single.body as Map;
        expect(order.containsKey('alternate_phone'), isFalse);
        expect(order.containsKey('no_alternate_phone'), isFalse);
      },
    );

    testWidgets('switching it back on brings the question back, and the order waits for an answer again', (t) async {
      final b = backend(enabled: false);
      final h = await pump(t, b);
      await _settle(t, 6);
      expect(find.byKey(_fieldKey), findsNothing);
      expect(place(t).onPressed, isNotNull);

      b.alternatePhoneEnabled = true;
      unawaited(h.read(publicConfigProvider.notifier).refresh());
      await _settle(t, 6);
      expect(find.byKey(_fieldKey), findsOneWidget);
      expect(place(t).onPressed, isNull);
    });
  });
}
