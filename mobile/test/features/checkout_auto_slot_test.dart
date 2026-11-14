// Checkout: the earliest bookable delivery / pickup time is chosen for the customer and kept right.
// Real screen + real controller against a fake API whose time lists tests can change while the screen is open.
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/delivery_slot_section.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/checkout/presentation/state/slot_providers.dart';
import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/checkout_backend.dart';
import '../helpers/fakes.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

/// A phone clock tests can move.
class _Clock {
  _Clock(this.now);
  DateTime now;
  DateTime call() => now;
}

CheckoutBackend _backend({
  bool pickupTimes = true,
  bool deliveryTimes = true,
  DateTime? s16Until,
}) {
  final d = qDay(1);
  return CheckoutBackend(
    delivery: {
      'br1': deliveryTimes
          ? {
              d: [
                slotRow('s-16', d, '16:00', '18:00', available: 2, bookableUntil: s16Until?.toUtc().toIso8601String()),
                slotRow('s-18', d, '18:00', '20:00'),
                slotRow('s-20', d, '20:00', '22:00', available: 0),
              ],
            }
          : {},
      'br2': {
        d: [slotRow('b-09', d, '09:00', '11:00')],
      },
    },
    pickup: {
      'br1': pickupTimes
          ? {
              d: [slotRow('p-10', d, '10:00', '12:00'), slotRow('p-14', d, '14:00', '16:00')],
            }
          : {},
      'br2': {
        d: [slotRow('q-11', d, '11:00', '13:00')],
      },
    },
  );
}

Future<TestHarness> _pump(
  WidgetTester t,
  CheckoutBackend backend, {
  Locale locale = const Locale('en'),
  Size size = const Size(900, 3200),
  double scale = 1,
  _Clock? clock,
  bool answerPhone = true,
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(
    tokens: _signedIn,
    handler: backend.handler,
    prefs: {'locale': locale.languageCode, 'onboarding_done': true},
    overrides: [if (clock != null) slotClockProvider.overrideWithValue(clock.call)],
  );
  h.container.listen(checkoutControllerProvider, (_, _) {});
  if (answerPhone) h.read(checkoutControllerProvider.notifier).setAlternateDeclined(true);
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
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale)),
          child: child!,
        ),
        home: const CheckoutScreen(),
      ),
    ),
  );
  await _settle(t);
  return h;
}

Future<void> _settle(WidgetTester t, {int frames = 24}) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

CheckoutState _state(TestHarness h) => h.read(checkoutControllerProvider);

FilledButton _place(WidgetTester t) => t.widget<FilledButton>(find.widgetWithText(FilledButton, 'Place order'));

void main() {
  group('delivery', () {
    testWidgets('the earliest free time is chosen as soon as the times load, and the quote carries it', (t) async {
      final h = await _pump(t, _backend());
      final s = _state(h);
      expect(s.options.deliverySlotId, 's-16', reason: '16:00 is the first time with a free place');
      expect(s.options.deliveryDate, qDay(1));
      expect(s.slotAuto, isTrue);
      expect(s.slotUnavailable, isFalse);
      final quote = h.adapter.where('POST', '/checkout/quote').last.body as Map;
      expect(quote['delivery_slot_id'], 's-16');
      expect(quote['delivery_date'], qDay(1));
      expect(find.text('Earliest available'), findsOneWidget);
      expect(_place(t).onPressed, isNotNull);
      expect(find.byKey(const ValueKey('place-order-when')), findsOneWidget);
      expect(find.byKey(const ValueKey('place-order-hint')), findsNothing);
    });

    testWidgets('placing sends the chosen time', (t) async {
      final h = await _pump(t, _backend());
      final result = h.read(checkoutControllerProvider.notifier).placeOrder();
      await _settle(t);
      final placed = await result;
      expect(placed!.orderNumber, 'AB1');
      final body = h.adapter.where('POST', '/checkout/orders').single.body as Map;
      expect(body['delivery_slot_id'], 's-16');
      expect(body['delivery_date'], qDay(1));
    });

    testWidgets('choosing another time is one tap, and that time is then the customer\'s own', (t) async {
      final h = await _pump(t, _backend());
      await t.tap(find.byKey(const ValueKey('delivery-slot-s-18')));
      await _settle(t);
      expect(_state(h).options.deliverySlotId, 's-18');
      expect(_state(h).slotAuto, isFalse);
      expect(find.text('Earliest available'), findsNothing);
      expect(find.byKey(const ValueKey('delivery-slot-auto-hint')), findsNothing);
    });

    testWidgets('a time the customer chose is never replaced when an earlier one opens up', (t) async {
      final backend = _backend();
      final h = await _pump(t, backend);
      await t.tap(find.byKey(const ValueKey('delivery-slot-s-18')));
      await _settle(t);

      // A cancellation frees an earlier time; the lists are fetched again (the app coming back to the foreground).
      backend.delivery['br1']![qDay(1)]!.insert(0, slotRow('s-09', qDay(1), '09:00', '11:00'));
      h.container.invalidate(deliverySlotWindowProvider);
      await _settle(t);
      expect(_state(h).options.deliverySlotId, 's-18');
      expect(find.byKey(const ValueKey('delivery-slot-s-09')), findsOneWidget, reason: 'the new time is offered, not forced');
    });

    testWidgets('a time that fills up while the screen is open is replaced, and the screen says so', (t) async {
      final backend = _backend();
      final h = await _pump(t, backend);
      expect(_state(h).options.deliverySlotId, 's-16');

      backend.delivery['br1']![qDay(1)]![0]['available'] = 0;
      backend.delivery['br1']![qDay(1)]![0]['is_full'] = true;
      h.container.invalidate(deliverySlotWindowProvider);
      await _settle(t);

      expect(_state(h).options.deliverySlotId, 's-18');
      expect(_state(h).slotAuto, isTrue);
      expect(find.text('That time just became unavailable, so we chose the next earliest. Tap another time to change it.'), findsOneWidget);
      expect(_place(t).onPressed, isNotNull);
    });

    testWidgets('a time whose cutoff passes while the screen stays open is replaced by the next check', (t) async {
      final clock = _Clock(DateTime.now());
      final h = await _pump(t, _backend(s16Until: clock.now.add(const Duration(hours: 2))), clock: clock);
      expect(_state(h).options.deliverySlotId, 's-16');

      clock.now = clock.now.add(const Duration(hours: 3)); // past 16:00's cutoff
      await t.pump(const Duration(seconds: 31));
      await _settle(t);

      expect(_state(h).options.deliverySlotId, 's-18', reason: 'never keep a stale time');
      expect(find.text('That time just became unavailable, so we chose the next earliest. Tap another time to change it.'), findsOneWidget);
    });

    testWidgets('the server refusing the earliest time sends the screen to the next one instead of looping', (t) async {
      final backend = _backend()..refused.add('s-16');
      final h = await _pump(t, backend);
      expect(_state(h).options.deliverySlotId, 's-18');
      expect(h.adapter.where('POST', '/checkout/quote').length, lessThan(12), reason: 'no endless retry');
    });

    testWidgets('changing the address to another branch chooses that branch\'s earliest time', (t) async {
      final h = await _pump(t, _backend());
      expect(_state(h).options.deliverySlotId, 's-16');

      await h.read(checkoutControllerProvider.notifier).update((o) => o.copyWith(addressId: () => 'a2'));
      await _settle(t);

      expect(_state(h).options.deliverySlotId, 'b-09');
      expect(_state(h).options.deliveryDate, qDay(1));
      expect(_state(h).slotBranchId, 'br2');
      expect(find.byKey(const ValueKey('delivery-slot-b-09')), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-slot-s-16')), findsNothing);
    });

    testWidgets('switching to pickup and back chooses again each time', (t) async {
      final h = await _pump(t, _backend());
      final c = h.read(checkoutControllerProvider.notifier);

      await c.update((o) => o.copyWith(fulfillmentType: FulfillmentType.pickup, branchId: () => 'br1'));
      await _settle(t);
      var s = _state(h);
      expect(s.options.deliverySlotId, isNull, reason: 'a delivery time means nothing for pickup');
      expect(s.options.pickupSlotId, 'p-10');
      expect(s.options.pickupDate, qDay(1));
      expect(s.slotAuto, isTrue);
      final pickupQuote = h.adapter.where('POST', '/checkout/quote').last.body as Map;
      expect(pickupQuote['pickup_slot_id'], 'p-10');
      expect(pickupQuote.containsKey('delivery_slot_id'), isFalse);
      expect(find.text('Earliest available'), findsOneWidget);

      await c.update((o) => o.copyWith(fulfillmentType: FulfillmentType.delivery));
      await _settle(t);
      s = _state(h);
      expect(s.options.pickupSlotId, isNull);
      expect(s.options.deliverySlotId, 's-16');
    });

    testWidgets('a pickup time the customer chose is kept; a new showroom gets its own earliest time', (t) async {
      final h = await _pump(t, _backend());
      final c = h.read(checkoutControllerProvider.notifier);
      await c.update((o) => o.copyWith(fulfillmentType: FulfillmentType.pickup, branchId: () => 'br1'));
      await _settle(t);
      await t.tap(find.byKey(const ValueKey('pickup-slot-p-14')));
      await _settle(t);
      expect(_state(h).options.pickupSlotId, 'p-14');
      expect(_state(h).slotAuto, isFalse);

      await c.update((o) => o.copyWith(branchId: () => 'br2'));
      await _settle(t);
      expect(_state(h).options.pickupSlotId, 'q-11');
      expect(_state(h).slotAuto, isTrue);
    });
  });

  group('when nothing can be booked', () {
    testWidgets('delivery: a clear empty state with the next steps, and the order waits', (t) async {
      final backend = _backend(deliveryTimes: false);
      final h = await _pump(t, backend);
      expect(find.byKey(const ValueKey('delivery-slots-empty')), findsOneWidget);
      expect(find.text('No delivery times are open right now'), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-slots-retry')), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-slots-switch')), findsOneWidget, reason: 'pickup is possible');
      expect(_state(h).options.deliverySlotId, isNull);
      expect(_place(t).onPressed, isNull);
      expect(find.byKey(const ValueKey('place-order-hint')), findsOneWidget);
    });

    testWidgets('"Check again" fetches the times again and chooses the earliest as soon as one is open', (t) async {
      final backend = _backend(deliveryTimes: false);
      final h = await _pump(t, backend);
      backend.delivery['br1']![qDay(2)] = [slotRow('n-10', qDay(2), '10:00', '12:00')];
      await t.ensureVisible(find.byKey(const ValueKey('delivery-slots-retry')));
      await t.tap(find.byKey(const ValueKey('delivery-slots-retry')));
      await _settle(t);
      expect(_state(h).options.deliverySlotId, 'n-10');
      expect(_state(h).options.deliveryDate, qDay(2));
      expect(find.byKey(const ValueKey('delivery-slots-empty')), findsNothing);
      expect(_place(t).onPressed, isNotNull);
    });

    testWidgets('"Choose pickup instead" moves to pickup with its earliest time chosen', (t) async {
      final h = await _pump(t, _backend(deliveryTimes: false));
      await t.ensureVisible(find.byKey(const ValueKey('delivery-slots-switch')));
      await t.tap(find.byKey(const ValueKey('delivery-slots-switch')));
      await _settle(t);
      expect(_state(h).options.fulfillmentType, FulfillmentType.pickup);
      expect(_state(h).options.pickupSlotId, 'p-10');
    });

    testWidgets('pickup: an empty state offers delivery instead', (t) async {
      final h = await _pump(t, _backend(pickupTimes: false));
      await h.read(checkoutControllerProvider.notifier).update((o) => o.copyWith(fulfillmentType: FulfillmentType.pickup, branchId: () => 'br1'));
      await _settle(t);
      expect(find.byKey(const ValueKey('pickup-slots-empty')), findsOneWidget);
      expect(find.text('No pickup times are open right now'), findsOneWidget);
      expect(_state(h).options.pickupSlotId, isNull);
      await t.tap(find.byKey(const ValueKey('pickup-slots-switch')));
      await _settle(t);
      expect(_state(h).options.fulfillmentType, FulfillmentType.delivery);
      expect(_state(h).options.deliverySlotId, 's-16');
    });

    testWidgets('a whole window of full times is empty too', (t) async {
      final backend = _backend();
      backend.delivery['br1']![qDay(1)]!.forEach((s) {
        s['available'] = 0;
        s['is_full'] = true;
      });
      final h = await _pump(t, backend);
      expect(find.byKey(const ValueKey('delivery-slots-empty')), findsOneWidget);
      expect(_state(h).options.deliverySlotId, isNull);
    });
  });

  group('layout', () {
    for (final v in [
      (name: '390 px', size: const Size(390, 2400), scale: 1.0),
      (name: '390 px, text x1.3', size: const Size(390, 3000), scale: 1.3),
      (name: '1024 px', size: const Size(1024, 2400), scale: 1.0),
    ]) {
      for (final locale in const [Locale('en'), Locale('ar')]) {
        testWidgets('${v.name} ${locale.languageCode}: earliest time chosen, marked, no overflow', (t) async {
          final h = await _pump(t, _backend(), locale: locale, size: v.size, scale: v.scale);
          expect(_state(h).options.deliverySlotId, 's-16');
          expect(find.byKey(const ValueKey('slot-earliest-badge')), findsOneWidget);
          expect(find.text(locale.languageCode == 'ar' ? 'أقرب موعد متاح' : 'Earliest available'), findsOneWidget);
          expect(t.takeException(), isNull, reason: 'no layout overflow');
          for (final tile in t.widgetList<SlotTile>(find.byType(SlotTile))) {
            expect(t.getSize(find.byWidget(tile)).height, greaterThanOrEqualTo(48));
          }
          if (locale.languageCode == 'ar') {
            expect(Directionality.of(t.element(find.byType(SlotTile).first)), TextDirection.rtl);
          }
        });
      }
    }

    testWidgets('Arabic empty state and replaced notice read in Arabic', (t) async {
      final backend = _backend(deliveryTimes: false);
      await _pump(t, backend, locale: const Locale('ar'), size: const Size(390, 2400), scale: 1.3);
      expect(find.text('لا توجد مواعيد توصيل متاحة حالياً'), findsOneWidget);
      expect(find.text('إعادة المحاولة'), findsOneWidget);
      expect(t.takeException(), isNull);
    });
  });
}
