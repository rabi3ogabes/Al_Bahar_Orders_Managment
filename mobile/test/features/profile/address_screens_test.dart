// Address list and address form: the "Alternative phone number" switch changes nothing there (the second number lives on the profile and
// at checkout, never on an address), and a phone number reads left to right inside Arabic text.
import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/profile/presentation/address_form_screen.dart';
import 'package:albahar_customer/features/profile/presentation/addresses_screen.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

Future<void> _settle(WidgetTester t, [int frames = 16]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

/// Opens [home] with the dashboard's alternative-number switch [alternate] (on until the server says otherwise).
Future<TestHarness> _pump(
  WidgetTester t,
  Widget home, {
  required bool alternate,
  String locale = 'en',
  Size size = const Size(390, 1800),
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(
    tokens: _signedIn,
    prefs: {'locale': locale, 'onboarding_done': true, 'public_config': '{"alternate_phone_enabled":$alternate}'},
    handler: routes({
      'GET /app-config': (_) => ok({'alternate_phone_enabled': alternate}),
      'GET /me': (_) => ok(userJson()),
      'GET /me/addresses': (_) => page([addressJson('a1', isDefault: true), addressJson('a2')]),
      'GET /delivery-zones': (_) => ok(const []),
    }),
  );
  h.container.listen(sessionControllerProvider, (_, _) {});
  h.container.listen(publicConfigProvider, (_, _) {});
  await t.pumpWidget(
    UncontrolledProviderScope(
      container: h.container,
      child: MaterialApp(
        locale: Locale(locale),
        supportedLocales: supportedLocales,
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: AppTheme.light(Locale(locale)),
        home: home,
      ),
    ),
  );
  await _settle(t);
  return h;
}

/// Any word about a second number, in either language.
final _alternateWords = RegExp('alternat|another number|رقم هاتف بديل|رقم آخر', caseSensitive: false);

void main() {
  for (final alternate in [true, false]) {
    group('with the alternative number switch ${alternate ? 'on' : 'off'}', () {
      testWidgets('the address list has no alternative-number field or words', (t) async {
        await _pump(t, const AddressesScreen(), alternate: alternate);
        expect(find.text('Home'), findsWidgets);
        expect(find.textContaining(_alternateWords, findRichText: true), findsNothing);
        expect(find.byKey(const ValueKey('alternate-phone-field')), findsNothing);
        expect(t.takeException(), isNull);
      });

      testWidgets('the address form has no alternative-number field or words', (t) async {
        await _pump(t, const Scaffold(body: AddressFormScreen(embedded: true)), alternate: alternate);
        expect(find.byType(AddressFormScreen), findsOneWidget);
        expect(find.textContaining(_alternateWords, findRichText: true), findsNothing);
        expect(find.byKey(const ValueKey('alternate-phone-field')), findsNothing);
        expect(find.byKey(const ValueKey('profile-alternate-phone')), findsNothing);
        expect(t.takeException(), isNull);
      });
    });
  }

  testWidgets('Arabic: the phone number keeps its "+" in front of the digits, whatever the text around it', (t) async {
    await _pump(t, const AddressesScreen(), alternate: false, locale: 'ar');
    // The number is isolated left-to-right inside the right-to-left line.
    expect(find.textContaining('\u2066+97455123456\u2069', findRichText: true), findsWidgets);
    expect(t.takeException(), isNull);
  });

  testWidgets('a small phone with larger text and Arabic: the address cards fit', (t) async {
    t.platformDispatcher.textScaleFactorTestValue = 1.3;
    addTearDown(t.platformDispatcher.clearAllTestValues);
    await _pump(t, const AddressesScreen(), alternate: true, locale: 'ar', size: const Size(320, 1800));
    expect(find.byType(AddressesScreen), findsOneWidget);
    expect(t.takeException(), isNull);
  });

  testWidgets('the address form fits a small phone with larger text in Arabic', (t) async {
    t.platformDispatcher.textScaleFactorTestValue = 1.3;
    addTearDown(t.platformDispatcher.clearAllTestValues);
    await _pump(
      t,
      const Scaffold(body: AddressFormScreen(embedded: true)),
      alternate: true,
      locale: 'ar',
      size: const Size(320, 2600),
    );
    expect(find.byType(AddressFormScreen), findsOneWidget);
    expect(t.takeException(), isNull);
  });
}
