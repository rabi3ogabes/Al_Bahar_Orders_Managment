// Checkout: the second phone number for the driver (delivery only) and the "allow split shipments" switch the server decides on.
import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/checkout/domain/alternate_phone.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/checkout_backend.dart';
import '../helpers/fakes.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

CheckoutBackend _backend({String? saved, bool split = false}) {
  final d = qDay(1);
  return CheckoutBackend(
    savedAlternatePhone: saved,
    splitChoice: split,
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
  );
}

Future<void> _settle(WidgetTester t, [int frames = 24]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

Future<TestHarness> _pump(WidgetTester t, CheckoutBackend backend, {Locale locale = const Locale('en'), Size size = const Size(900, 3200)}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(
    tokens: _signedIn,
    handler: backend.handler,
    prefs: {'locale': locale.languageCode, 'onboarding_done': true},
  );
  h.container.listen(checkoutControllerProvider, (_, _) {});
  h.container.listen(sessionControllerProvider, (_, _) {});
  await t.pumpWidget(
    UncontrolledProviderScope(
      container: h.container,
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
        home: const CheckoutScreen(),
      ),
    ),
  );
  await _settle(t);
  return h;
}

FilledButton _place(WidgetTester t) => t.widget<FilledButton>(find.widgetWithText(FilledButton, 'Place order'));
CheckoutState _state(TestHarness h) => h.read(checkoutControllerProvider);

void main() {
  group('AlternatePhoneInput', () {
    test('a number, the tick, nothing yet, a bad number, the main number', () {
      const main = '+97455123456';
      expect(const AlternatePhoneInput(text: '55987654').status(mainPhone: main), AlternatePhoneStatus.valid);
      expect(const AlternatePhoneInput(text: '٥٥٩٨٧٦٥٤').e164, '+97455987654');
      expect(const AlternatePhoneInput(declined: true).status(mainPhone: main), AlternatePhoneStatus.declined);
      expect(const AlternatePhoneInput().status(mainPhone: main), AlternatePhoneStatus.missing);
      expect(const AlternatePhoneInput(text: '1234').status(mainPhone: main), AlternatePhoneStatus.invalid);
      expect(const AlternatePhoneInput(text: '4412 3456').status(mainPhone: main), AlternatePhoneStatus.invalid);
      expect(const AlternatePhoneInput(text: '5512 3456').status(mainPhone: main), AlternatePhoneStatus.sameAsMain);
      expect(const AlternatePhoneInput(text: '+974 55123456').status(mainPhone: main), AlternatePhoneStatus.sameAsMain);
    });

    test('request fields: both keys once answered, nothing before; typing and ticking exclude each other', () {
      expect(const AlternatePhoneInput(text: '55987654').requestFields(), {'alternate_phone': '+97455987654', 'no_alternate_phone': false});
      expect(const AlternatePhoneInput(declined: true).requestFields(), {'alternate_phone': null, 'no_alternate_phone': true});
      expect(const AlternatePhoneInput().requestFields(), isEmpty);
      expect(const AlternatePhoneInput(text: '12').requestFields(), isEmpty);
      expect(const AlternatePhoneInput(text: '5').decline(true), const AlternatePhoneInput(declined: true));
      expect(const AlternatePhoneInput(declined: true).typed('5'), const AlternatePhoneInput(text: '5'));
      expect(AlternatePhoneInput.fromSaved('+97455987654').text, '55987654');
      expect(AlternatePhoneInput.fromSaved(null).text, '');
    });
  });

  group('checkout screen', () {
    testWidgets('no saved number: the order waits, and says why, until a number is typed or "none" is ticked', (t) async {
      final h = await _pump(t, _backend());
      expect(find.byKey(const ValueKey('alternate-phone-field')), findsOneWidget);
      expect(_place(t).onPressed, isNull);
      expect(find.text('Add an alternative number, or tick “I don’t have another number”'), findsOneWidget);
      expect(find.text('Enter a valid Qatar mobile number: 8 digits starting with 3, 5, 6 or 7.'), findsNothing, reason: 'no surprise error on an untouched field');

      await t.tap(find.byKey(const ValueKey('alternate-phone-none')));
      await _settle(t);
      expect(_state(h).alternate.declined, isTrue);
      expect(_place(t).onPressed, isNotNull);

      // Typing unticks it; a half-typed number is not an error yet, but it keeps the order waiting.
      await t.enterText(find.descendant(of: find.byKey(const ValueKey('alternate-phone-field')), matching: find.byType(TextField)), '5598');
      await _settle(t);
      expect(_state(h).alternate.declined, isFalse);
      expect(_place(t).onPressed, isNull);
      expect(find.text('Enter a valid Qatar mobile number: 8 digits starting with 3, 5, 6 or 7.'), findsOneWidget, reason: 'shown in the bar below');

      await t.enterText(find.descendant(of: find.byKey(const ValueKey('alternate-phone-field')), matching: find.byType(TextField)), '٥٥٩٨٧٦٥٤');
      await _settle(t);
      expect(_place(t).onPressed, isNotNull);

      final result = h.read(checkoutControllerProvider.notifier).placeOrder();
      await _settle(t);
      await result;
      final body = h.adapter.where('POST', '/checkout/orders').single.body as Map;
      expect(body['alternate_phone'], '+97455987654');
      expect(body['no_alternate_phone'], false);
    });

    testWidgets('the main number is refused in plain words', (t) async {
      final h = await _pump(t, _backend());
      await t.enterText(find.descendant(of: find.byKey(const ValueKey('alternate-phone-field')), matching: find.byType(TextField)), '5512 3456');
      await _settle(t);
      expect(_place(t).onPressed, isNull);
      expect(find.text('This is your main number. Enter a different one.'), findsWidgets);
      expect(_state(h).alternate.status(mainPhone: _state(h).mainPhone), AlternatePhoneStatus.sameAsMain);
    });

    testWidgets('a saved number is already in the field and the order can go ahead', (t) async {
      final h = await _pump(t, _backend(saved: '+97455987654'));
      expect(_state(h).alternate.text, '55987654');
      expect(find.widgetWithText(TextField, '55987654'), findsOneWidget);
      expect(_place(t).onPressed, isNotNull);
      final result = h.read(checkoutControllerProvider.notifier).placeOrder();
      await _settle(t);
      await result;
      expect((h.adapter.where('POST', '/checkout/orders').single.body as Map)['alternate_phone'], '+97455987654');
    });

    testWidgets('pickup never asks for it, and does not send it', (t) async {
      final h = await _pump(t, _backend());
      unawaited(h.read(checkoutControllerProvider.notifier).update((o) => o.copyWith(fulfillmentType: FulfillmentType.pickup, branchId: () => 'br1')));
      await _settle(t);
      expect(find.byKey(const ValueKey('alternate-phone-field')), findsNothing);
      expect(_place(t).onPressed, isNotNull);
      final quote = h.adapter.where('POST', '/checkout/quote').last.body as Map;
      expect(quote.containsKey('alternate_phone'), isFalse);
    });

    testWidgets('Arabic, 390 px, text x1.3 renders the field and the tick without overflow', (t) async {
      t.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(t.platformDispatcher.clearAllTestValues);
      await _pump(t, _backend(), locale: const Locale('ar'), size: const Size(390, 2400));
      expect(find.text('رقم هاتف بديل'), findsWidgets);
      expect(find.text('ليس لدي رقم آخر'), findsOneWidget);
      expect(t.takeException(), isNull);
      expect(t.getSize(find.byKey(const ValueKey('alternate-phone-none'))).height, greaterThanOrEqualTo(40));
    });

    testWidgets('the split switch is shown only when the server says there is a choice', (t) async {
      await _pump(t, _backend(split: false));
      expect(find.byKey(const ValueKey('allow-split-switch')), findsNothing);
    });

    testWidgets('the split switch appears when the server offers the choice, and changing it re-quotes', (t) async {
      final h = await _pump(t, _backend(split: true));
      await t.ensureVisible(find.byKey(const ValueKey('allow-split-switch')));
      expect(find.byKey(const ValueKey('allow-split-switch')), findsOneWidget);
      await t.tap(find.byKey(const ValueKey('allow-split-switch')));
      await _settle(t);
      expect(_state(h).options.allowSplit, isFalse);
      expect((h.adapter.where('POST', '/checkout/quote').last.body as Map)['allow_split'], false);
    });
  });
}
