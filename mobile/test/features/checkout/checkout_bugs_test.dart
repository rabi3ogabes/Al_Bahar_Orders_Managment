// Checkout bug sweep: what the customer sees when something goes wrong or changes under them (a refresh that fails, a second tap,
// the back button while the order is being sent, a refused order, the dashboard switching a question off).
import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/checkout_backend.dart';
import '../../helpers/fakes.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

AppLocalizations get _en => lookupAppLocalizations(const Locale('en'));

const _noAlternate = {'public_config': '{"alternate_phone_enabled":false}'};

CheckoutBackend _backend({bool? alternate = false}) {
  final d = qDay(1);
  return CheckoutBackend(
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
  )..alternatePhoneEnabled = alternate;
}

Future<void> _settle(WidgetTester t, [int frames = 24]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

/// The checkout opened over a home page (so it has a back button), on a phone-sized screen.
Future<TestHarness> _open(
  WidgetTester t,
  FakeHandler handler, {
  Map<String, Object> prefs = _noAlternate,
  Size size = const Size(390, 3000),
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(
    tokens: _signedIn,
    handler: handler,
    prefs: {'locale': 'en', 'onboarding_done': true, ...prefs},
  );
  h.container.listen(checkoutControllerProvider, (_, _) {});
  h.container.listen(sessionControllerProvider, (_, _) {});
  h.container.listen(publicConfigProvider, (_, _) {});
  await t.pumpWidget(
    UncontrolledProviderScope(
      container: h.container,
      child: MaterialApp(
        locale: const Locale('en'),
        supportedLocales: supportedLocales,
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: AppTheme.light(const Locale('en')),
        home: const Scaffold(body: Text('home')),
      ),
    ),
  );
  final home = t.element(find.text('home'));
  unawaited(Navigator.of(home).push(MaterialPageRoute<void>(builder: (_) => const CheckoutScreen())));
  await _settle(t);
  return h;
}

CheckoutState _state(TestHarness h) => h.read(checkoutControllerProvider);

/// The place-order button, whatever it shows (a spinner replaces its label while the order is on its way): the last filled button.
Finder get _placeButton => find.byType(FilledButton).last;

void main() {
  group('a refresh of the totals that fails', () {
    testWidgets('the old totals stay on screen but never place an order: the page says so and one tap asks again', (
      t,
    ) async {
      final backend = _backend();
      var failQuote = false;
      final h = await _open(t, (r) {
        if (failQuote && r.method == 'POST' && r.path == '/checkout/quote') {
          return error(422, 'address_unserviceable', message: 'We do not deliver to this address yet.');
        }
        return backend.handler(r);
      });
      expect(_state(h).canPlace, isTrue);
      expect(find.byKey(const ValueKey('quote-retry')), findsNothing);

      failQuote = true;
      unawaited(h.read(checkoutControllerProvider.notifier).update((o) => o.copyWith(addressId: () => 'a2')));
      await _settle(t);
      expect(_state(h).quote, isNotNull, reason: 'the last good totals are still there');
      expect(_state(h).quoteError, isNotNull);
      expect(_state(h).canPlace, isFalse, reason: 'those totals belong to the old address');
      expect(find.text('We do not deliver to this address yet.'), findsOneWidget);
      expect(find.text(_en.quoteRefreshFailed), findsOneWidget);
      expect(find.byKey(const ValueKey('quote-retry')), findsOneWidget);
      await t.ensureVisible(_placeButton);
      expect(t.widget<FilledButton>(_placeButton).onPressed, isNull);

      failQuote = false;
      await t.ensureVisible(find.byKey(const ValueKey('quote-retry')));
      await t.tap(find.byKey(const ValueKey('quote-retry')));
      await _settle(t);
      expect(_state(h).quoteError, isNull);
      expect(_state(h).canPlace, isTrue);
      expect(find.byKey(const ValueKey('quote-retry')), findsNothing);
      expect(find.text(_en.quoteRefreshFailed), findsNothing);
      expect(t.takeException(), isNull);
    });

    testWidgets('on a 320 px phone with larger text the message and the retry button fit', (t) async {
      t.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(t.platformDispatcher.clearAllTestValues);
      final backend = _backend();
      var failQuote = false;
      final h = await _open(t, (r) {
        if (failQuote && r.method == 'POST' && r.path == '/checkout/quote') {
          return error(422, 'address_unserviceable', message: 'We do not deliver to this address yet.');
        }
        return backend.handler(r);
      }, size: const Size(320, 3600));
      failQuote = true;
      unawaited(h.read(checkoutControllerProvider.notifier).update((o) => o.copyWith(addressId: () => 'a2')));
      await _settle(t);
      expect(find.byKey(const ValueKey('quote-retry')), findsOneWidget);
      expect(t.takeException(), isNull);
    });
  });

  group('placing the order', () {
    testWidgets('a second tap while the order is on its way sends nothing more, and back waits with a plain message', (
      t,
    ) async {
      final backend = _backend();
      final order = Completer<FakeReply>();
      final h = await _open(t, (r) {
        if (r.method == 'POST' && r.path == '/checkout/orders') return order.future;
        return backend.handler(r);
      });
      await t.ensureVisible(find.widgetWithText(FilledButton, _en.placeOrder));
      await t.tap(find.widgetWithText(FilledButton, _en.placeOrder));
      await _settle(t, 4);
      expect(_state(h).placing, isTrue);
      expect(h.adapter.where('POST', '/checkout/orders'), hasLength(1));

      // A second tap does nothing: the button is held, and so is the controller.
      expect(t.widget<FilledButton>(_placeButton).onPressed, isNull);
      expect(await h.read(checkoutControllerProvider.notifier).placeOrder(), isNull);
      await _settle(t, 4);
      expect(h.adapter.where('POST', '/checkout/orders'), hasLength(1), reason: 'never two orders from two taps');

      // Back is held: leaving now would place the order with nobody to open the payment page.
      await t.pageBack();
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      expect(find.byType(CheckoutScreen), findsOneWidget);
      expect(find.text(_en.placingOrderWait), findsOneWidget);

      // The page ignores taps meanwhile: the fulfilment cannot be switched under the order being sent.
      await t.tap(find.text(_en.pickup), warnIfMissed: false);
      await t.pump();
      expect(_state(h).options.fulfillmentType, FulfillmentType.delivery);

      // The server answers with a failure: the page is free again and back works.
      order.complete(error(500, 'server_error', message: 'Something went wrong.'));
      await _settle(t);
      expect(_state(h).placing, isFalse);
      await t.pageBack();
      await _settle(t, 20);
      expect(find.byType(CheckoutScreen), findsNothing);
    });

    testWidgets(
      'an order the server refuses (stock changed) asks for the totals again, so the page says what is true now',
      (t) async {
        final backend = _backend();
        final h = await _open(t, (r) {
          if (r.method == 'POST' && r.path == '/checkout/orders') {
            return error(422, 'insufficient_stock', message: 'Only 1 left of Marble table.');
          }
          return backend.handler(r);
        });
        final before = h.adapter.where('POST', '/checkout/quote').length;
        final result = h.read(checkoutControllerProvider.notifier).placeOrder();
        await _settle(t);
        expect(await result, isNull);
        expect(_state(h).placeError, isNotNull);
        expect(
          h.adapter.where('POST', '/checkout/quote').length,
          greaterThan(before),
          reason: 'the quote was refreshed',
        );
        expect(_state(h).quoting, isFalse);
      },
    );

    testWidgets(
      'a network failure while placing keeps the totals and lets the same order be sent again with the same key',
      (t) async {
        final backend = _backend();
        var offline = true;
        final h = await _open(t, (r) {
          if (r.method == 'POST' && r.path == '/checkout/orders' && offline) return error(503, 'unavailable');
          return backend.handler(r);
        });
        final quotes = h.adapter.where('POST', '/checkout/quote').length;
        var result = h.read(checkoutControllerProvider.notifier).placeOrder();
        await _settle(t);
        expect(await result, isNull);
        expect(_state(h).placeError, isNotNull);
        expect(
          h.adapter.where('POST', '/checkout/quote').length,
          quotes,
          reason: 'a server error is not a reason to re-quote',
        );
        offline = false;
        result = h.read(checkoutControllerProvider.notifier).placeOrder();
        await _settle(t);
        expect(await result, isNotNull);
        final keys = h.adapter.where('POST', '/checkout/orders').map((r) => r.header('idempotency-key')).toSet();
        expect(keys, hasLength(1), reason: 'the same confirmation reuses its key: the server never makes two orders');
      },
    );
  });

  group('the dashboard switches the alternative number off while checkout is open', () {
    testWidgets('an answer about the number that held the order back is asked for again, so the button is free', (
      t,
    ) async {
      final backend = _backend(alternate: true);
      // While the question is on, the server insists on an answer it has not got; with it off the quote has nothing to say.
      final h = await _open(t, (r) {
        if (r.method == 'POST' && r.path == '/checkout/quote' && backend.alternatePhoneEnabled == true) {
          final quote = backend.quoteFor(r);
          return ok({
            ...quote,
            'can_place_order': false,
            'errors': [
              {'code': 'alternate_phone_required', 'message': 'Add an alternative number.'},
            ],
          });
        }
        return backend.handler(r);
      }, prefs: const {'public_config': '{"alternate_phone_enabled":true}'});
      expect(find.text('Add an alternative number.'), findsOneWidget);
      expect(_state(h).canPlace, isFalse);
      final quotes = h.adapter.where('POST', '/checkout/quote').length;

      backend.alternatePhoneEnabled = false;
      unawaited(h.read(publicConfigProvider.notifier).refresh());
      await _settle(t);
      expect(_state(h).alternateEnabled, isFalse);
      expect(h.adapter.where('POST', '/checkout/quote').length, greaterThan(quotes), reason: 'the quote was refreshed');
      expect(find.text('Add an alternative number.'), findsNothing);
      expect(_state(h).canPlace, isTrue);
    });
  });
}
