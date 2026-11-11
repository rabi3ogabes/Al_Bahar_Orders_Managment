// Delivery time slots: repository parsing, quote models, checkout controller
// and the checkout UI. JSON mirrors the backend contract
// (`GET /branches/{id}/delivery-slots`, `POST /checkout/quote`).
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/features/branches/data/branch_repository.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/delivery_slot_section.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import '../helpers/fakes.dart';

String _day(int offset) => QatarTime.isoDate(QatarTime.today().add(Duration(days: offset)));

/// `GET /branches/{id}/delivery-slots` row.
Map<String, dynamic> _slot(String id, String date, String from, String to, {int available = 6, int capacity = 8}) => {
  'id': id,
  'date': date,
  'starts_at': from,
  'ends_at': to,
  'capacity': capacity,
  'available': available,
  'is_full': available == 0,
};

/// Slots the fake backend offers: only tomorrow has any.
List<Map<String, dynamic>> _slotsFor(String date) => date == _day(1)
    ? [
        _slot('s-16', date, '16:00', '18:00', available: 2),
        _slot('s-18', date, '18:00', '20:00'),
        _slot('s-20', date, '20:00', '22:00', available: 0),
      ]
    : const [];

Map<String, dynamic> _quote({
  required String hash,
  bool split = false,
  bool slotRequired = true,
  Map<String, dynamic>? slot,
  String? error,
}) {
  Map<String, dynamic> fulfillment(String branch) => {
    'branch': {'id': branch, 'name': branch == 'br1' ? 'West Bay' : 'Lusail'},
    'type': 'delivery',
    'items': [],
    'delivery_fee': money('25.00'),
    'slot': split ? null : slot,
    'slot_required': !split && slotRequired,
  };
  return {
    'quote_hash': hash,
    'fulfillments': split ? [fulfillment('br1'), fulfillment('br2')] : [fulfillment('br1')],
    'is_split': split,
    'lines': [],
    'totals': {'subtotal': money('100.00'), 'delivery_fee': money('25.00'), 'grand_total': money('125.00')},
    'payment_methods': [
      {'code': 'sadad', 'available': true},
      {'code': 'cod', 'available': true},
    ],
    'loyalty': {'balance': 0, 'max_redeemable_points': 0, 'points_to_earn': 0},
    'warnings': split ? [] : [],
    'errors': [
      if (error != null) {'code': error, 'message': error},
    ],
    'can_place_order': error == null,
    'expires_at': '2026-11-05T12:00:00+03:00',
  };
}

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

/// A backend that requires a slot for single-branch delivery and knows which
/// slots exist. `bad` is rejected as `slot_unavailable`.
FakeHandler _backend({
  bool split = false,
  bool slotRequired = true,
  Set<String> bad = const {},
  List<FakeReply Function(RecordedRequest)> placeReplies = const [],
}) {
  var place = 0;
  return routes({
    'GET /me': (_) => ok({'id': 'u1', 'name': 'Noor', 'phone': '+97455123456'}),
    'GET /cart': (_) => ok({
      'id': 'c1',
      'branch_id': 'br1',
      'fulfillment_type': 'delivery',
      'address_id': 'a1',
      'items': [],
      'items_count': 0,
      'subtotal': money('0.00'),
    }),
    'GET /me/addresses': (_) => page([
      {'id': 'a1', 'recipient_name': 'Noor', 'recipient_phone': '+97455123456', 'is_default': true},
      {'id': 'a2', 'recipient_name': 'Noor', 'recipient_phone': '+97455123456'},
    ]),
    'GET /branches/br1/delivery-slots': (r) {
      final from = r.query['from'] as String?;
      if (from == null) return ok(_slotsFor('${r.query['date']}'));
      final to = r.query['to'] as String;
      final onlyAvailable = r.query['only_available'] != null;
      return ok(
        [
          for (var i = 0; i < 30; i++)
            if (_day(i).compareTo(from) >= 0 && _day(i).compareTo(to) <= 0 && _slotsFor(_day(i)).isNotEmpty)
              {
                'date': _day(i),
                'available': _slotsFor(_day(i)).fold<int>(0, (a, s) => a + (s['available'] as int)),
                'slots': _slotsFor(_day(i)),
              },
        ].where((d) => !onlyAvailable || (d['available'] as int) > 0).toList(),
      );
    },
    'GET /branches/br2/delivery-slots': (_) => error(404, 'not_found'),
    'POST /checkout/quote': (r) {
      final id = r.body['delivery_slot_id'] as String?;
      final date = r.body['delivery_date'] as String?;
      if (split) return ok(_quote(hash: 'split', split: true));
      if (!slotRequired) return ok(_quote(hash: 'free', slotRequired: false));
      if (id == null) return ok(_quote(hash: 'none', error: 'slot_required'));
      if (bad.contains(id)) return ok(_quote(hash: 'bad', error: 'slot_unavailable'));
      return ok(_quote(hash: 'q-$id', slot: {'id': id, 'date': date, 'starts_at': '16:00', 'ends_at': '18:00'}));
    },
    'POST /checkout/orders': (r) => placeReplies.isNotEmpty
        ? placeReplies[place++ % placeReplies.length](r)
        : ok({
            'order': {'id': 'o1', 'number': 'AB1'},
            'payment': {'payment_id': 'p1', 'method': 'sadad', 'status': 'pending', 'action': 'none'},
          }, status: 201),
  });
}

Future<(TestHarness, CheckoutController)> _controller(FakeHandler handler) async {
  final h = await TestHarness.create(tokens: _signedIn, handler: handler);
  h.container.listen(checkoutControllerProvider, (_, _) {});
  await h.container.read(cartControllerProvider.future);
  return (h, h.read(checkoutControllerProvider.notifier));
}

ApiClient _client(FakeAdapter adapter) =>
    ApiClient(Dio(BaseOptions(baseUrl: 'https://api.test/api/v1'))..httpClientAdapter = adapter);

void main() {
  group('Repository', () {
    test('deliverySlots parses the contract rows and sends ?date=', () async {
      final adapter = FakeAdapter(
        (_) => const FakeReply(200, {
          'data': [
            {
              'id': '9d5c1f3e-0001',
              'date': '2026-11-08',
              'starts_at': '16:00',
              'ends_at': '18:00',
              'capacity': 8,
              'available': 2,
              'is_full': false,
            },
            {
              'id': '9d5c1f3e-0002',
              'date': '2026-11-08',
              'starts_at': '18:00',
              'ends_at': '20:00',
              'capacity': 8,
              'available': 0,
              'is_full': true,
            },
          ],
        }),
      );
      final slots = await BranchRepository(_client(adapter)).deliverySlots('br1', '2026-11-08');
      expect(adapter.requests.single.path, '/branches/br1/delivery-slots');
      expect(adapter.requests.single.query['date'], '2026-11-08');
      expect(slots, hasLength(2));
      expect(slots.first.id, '9d5c1f3e-0001');
      expect(slots.first.startsAt, '16:00');
      expect(slots.first.endsAt, '18:00');
      expect(slots.first.capacity, 8);
      expect(slots.first.available, 2);
      expect(slots.first.selectable, isTrue);
      expect(slots.last.isFull, isTrue);
      expect(slots.last.selectable, isFalse);
    });

    test('a span of dates is one request and parses days with their slots', () async {
      final adapter = FakeAdapter(
        (_) => ok([
          {
            'date': '2026-11-08',
            'available': 8,
            'slots': [
              _slot('a', '2026-11-08', '16:00', '18:00', available: 2),
              _slot('b', '2026-11-08', '18:00', '20:00'),
            ],
          },
          {
            'date': '2026-11-09',
            'available': 0,
            'slots': [_slot('c', '2026-11-09', '16:00', '18:00', available: 0)],
          },
        ]),
      );
      final days = await BranchRepository(_client(adapter))
          .deliverySlotDays('br1', from: '2026-11-08', to: '2026-11-14', onlyAvailable: true);
      final req = adapter.requests.single;
      expect(req.path, '/branches/br1/delivery-slots');
      expect(req.query['from'], '2026-11-08');
      expect(req.query['to'], '2026-11-14');
      expect('${req.query['only_available']}', '1');
      expect(req.query.containsKey('date'), isFalse);
      expect(days.map((d) => d.date), ['2026-11-08', '2026-11-09']);
      expect(days.first.available, 8);
      expect(days.first.slots.map((s) => s.startsAt), ['16:00', '18:00']);
      expect(days.first.hasSelectable, isTrue);
      expect(days.last.hasSelectable, isFalse);
    });

    test('404 (branch does not deliver) and 422 (bad date) surface as ApiException', () async {
      final adapter = FakeAdapter(
        (r) => r.query['date'] == '2030-01-01'
            ? error(
                422,
                'validation_failed',
                errors: {
                  'date': ['out of range'],
                },
              )
            : error(404, 'not_found'),
      );
      final repo = BranchRepository(_client(adapter));
      await expectLater(
        repo.deliverySlots('br9', '2026-11-08'),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 404)),
      );
      await expectLater(
        repo.deliverySlots('br1', '2030-01-01'),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 422)),
      );
    });
  });

  group('Quote models', () {
    test('parses slot_required and slot with times', () {
      final q = Quote.fromJson(
        _quote(hash: 'h', slot: {'id': 's1', 'date': '2026-11-08', 'starts_at': '16:00', 'ends_at': '18:00'}),
      );
      final f = q.fulfillments.single;
      expect(f.branch.id, 'br1');
      expect(f.slotRequired, isTrue);
      expect(f.slot!.id, 's1');
      expect(f.slot!.date, '2026-11-08');
      expect(f.slot!.startsAt, '16:00');
      expect(f.slot!.endsAt, '18:00');
      expect(q.slotRequired, isTrue);
      expect(q.deliverySlot!.endsAt, '18:00');
    });

    test('slot null / missing flag, errors and split quotes', () {
      final none = Quote.fromJson(_quote(hash: 'h', error: 'slot_required'));
      expect(none.fulfillments.single.slot, isNull);
      expect(none.canPlaceOrder, isFalse);
      expect(none.issue('slot_required'), isNotNull);

      final legacy = Quote.fromJson({
        'quote_hash': 'h',
        'fulfillments': [
          {
            'branch': {'id': 'br1', 'name': 'x'},
            'type': 'delivery',
          },
        ],
      });
      expect(legacy.slotRequired, isFalse, reason: 'old backends omit slot_required');

      final split = Quote.fromJson(_quote(hash: 'h', split: true));
      expect(split.isSplit, isTrue);
      expect(split.slotRequired, isFalse);
      expect(split.singleFulfillment, isNull);
    });

    test('slot id and date are sent together, only for delivery', () {
      const both = CheckoutOptions(addressId: 'a1', deliverySlotId: 's1', deliveryDate: '2026-11-08');
      expect(both.toJson()['delivery_slot_id'], 's1');
      expect(both.toJson()['delivery_date'], '2026-11-08');

      const idOnly = CheckoutOptions(addressId: 'a1', deliverySlotId: 's1');
      expect(idOnly.toJson().containsKey('delivery_slot_id'), isFalse);
      expect(idOnly.toJson().containsKey('delivery_date'), isFalse);

      const pickup = CheckoutOptions(
        fulfillmentType: FulfillmentType.pickup,
        branchId: 'br1',
        deliverySlotId: 's1',
        deliveryDate: '2026-11-08',
      );
      expect(pickup.toJson().containsKey('delivery_slot_id'), isFalse);

      final cleared = both.copyWith(deliverySlotId: () => null, deliveryDate: () => null);
      expect(cleared.deliverySlotId, isNull);
      expect(cleared.toJson().containsKey('delivery_date'), isFalse);
    });
  });

  group('Checkout controller', () {
    test('the date strip needs one request for the whole week, not one per day', () async {
      final (h, _) = await _controller(_backend());
      final dates = await h.read(deliveryDatesProvider('br1').future);
      expect(dates, [_day(1)]); // only tomorrow has slots in the fake backend
      final calls = h.adapter.where('GET', '/branches/br1/delivery-slots');
      expect(calls, hasLength(1));
      expect(calls.single.query['from'], _day(0));
      expect(calls.single.query['to'], _day(6));
      expect(calls.single.query.containsKey('date'), isFalse);
    });

    test('a required slot blocks placing until the customer picks one (no auto-select)', () async {
      final (h, c) = await _controller(_backend());
      await c.update((o) => o.copyWith(addressId: () => 'a1'));

      var s = h.read(checkoutControllerProvider);
      expect(s.quote!.slotRequired, isTrue);
      expect(s.options.deliverySlotId, isNull, reason: 'never auto-selected');
      expect(s.slotMissing, isTrue);
      expect(s.missingRequirement, 'slot');
      expect(s.canPlace, isFalse);

      final slots = await h.read(deliverySlotsProvider((branchId: 'br1', date: _day(1))).future);
      await c.selectDeliverySlot(slots.first, branchId: 'br1', date: _day(1));

      s = h.read(checkoutControllerProvider);
      expect(s.options.deliverySlotId, 's-16');
      final body = h.adapter.where('POST', '/checkout/quote').last.body as Map;
      expect(body['delivery_slot_id'], 's-16');
      expect(body['delivery_date'], _day(1));
      expect(s.quote!.deliverySlot!.startsAt, '16:00');
      expect(s.slotMissing, isFalse);
      expect(s.canPlace, isTrue);

      final placed = await c.placeOrder();
      expect(placed!.orderNumber, 'AB1');
      final order = h.adapter.where('POST', '/checkout/orders').single.body as Map;
      expect(order['delivery_slot_id'], 's-16');
      expect(order['delivery_date'], _day(1));
    });

    test('slot_unavailable clears the selection, flags it and refetches the slots', () async {
      final (h, c) = await _controller(_backend(bad: {'s-18'}));
      await c.update((o) => o.copyWith(addressId: () => 'a1'));
      final key = (branchId: 'br1', date: _day(1));
      h.container.listen(deliverySlotsProvider(key), (_, _) {});
      final slots = await h.read(deliverySlotsProvider(key).future);
      final fetchesBefore = h.adapter.where('GET', '/branches/br1/delivery-slots').length;

      await c.selectDeliverySlot(slots[1], branchId: 'br1', date: _day(1));
      await Future<void>.delayed(Duration.zero);
      await h.read(deliverySlotsProvider(key).future);

      final s = h.read(checkoutControllerProvider);
      expect(s.options.deliverySlotId, isNull);
      expect(s.options.deliveryDate, isNull);
      expect(s.slotUnavailable, isTrue);
      expect(s.slotMissing, isTrue);
      expect(s.canPlace, isFalse);
      expect(
        h.adapter.where('GET', '/branches/br1/delivery-slots').length,
        greaterThan(fetchesBefore),
        reason: 'slots are re-fetched',
      );

      // Choosing a different slot recovers.
      await c.selectDeliverySlot(slots.first, branchId: 'br1', date: _day(1));
      final after = h.read(checkoutControllerProvider);
      expect(after.slotUnavailable, isFalse);
      expect(after.canPlace, isTrue);
    });

    test('place-order slot_unavailable clears the slot and re-quotes', () async {
      final (h, c) = await _controller(
        _backend(placeReplies: [(_) => error(409, 'slot_unavailable', message: 'taken')]),
      );
      await c.update((o) => o.copyWith(addressId: () => 'a1'));
      final slots = await h.read(deliverySlotsProvider((branchId: 'br1', date: _day(1))).future);
      await c.selectDeliverySlot(slots.first, branchId: 'br1', date: _day(1));

      expect(await c.placeOrder(), isNull);
      final s = h.read(checkoutControllerProvider);
      expect(s.options.deliverySlotId, isNull);
      expect(s.slotUnavailable, isTrue);
      expect(s.placeError, isNull, reason: 'shown inline, not as a snackbar');
      expect(s.quote!.issue('slot_required'), isNotNull);
    });

    test('place-order 409 quote_changed still works with a slot', () async {
      final (h, c) = await _controller(
        _backend(
          placeReplies: [
            (_) => error(
              409,
              'quote_changed',
              context: {
                'quote': _quote(
                  hash: 'new',
                  slot: {'id': 's-16', 'date': _day(1), 'starts_at': '16:00', 'ends_at': '18:00'},
                ),
              },
            ),
          ],
        ),
      );
      await c.update((o) => o.copyWith(addressId: () => 'a1'));
      final slots = await h.read(deliverySlotsProvider((branchId: 'br1', date: _day(1))).future);
      await c.selectDeliverySlot(slots.first, branchId: 'br1', date: _day(1));

      expect(await c.placeOrder(), isNull);
      final s = h.read(checkoutControllerProvider);
      expect(s.quoteChanged, isTrue);
      expect(s.quote!.quoteHash, 'new');
      expect(s.options.deliverySlotId, 's-16', reason: 'the slot is kept');
    });

    test('changing the address or fulfilment type clears the slot and re-quotes', () async {
      final (h, c) = await _controller(_backend());
      await c.update((o) => o.copyWith(addressId: () => 'a1'));
      final slots = await h.read(deliverySlotsProvider((branchId: 'br1', date: _day(1))).future);
      await c.selectDeliverySlot(slots.first, branchId: 'br1', date: _day(1));
      expect(h.read(checkoutControllerProvider).options.deliverySlotId, 's-16');
      final quotes = h.adapter.where('POST', '/checkout/quote').length;

      await c.update((o) => o.copyWith(addressId: () => 'a2'));
      var s = h.read(checkoutControllerProvider);
      expect(s.options.deliverySlotId, isNull);
      expect(s.options.deliveryDate, isNull);
      expect(h.adapter.where('POST', '/checkout/quote').length, quotes + 1);
      expect((h.adapter.where('POST', '/checkout/quote').last.body as Map).containsKey('delivery_slot_id'), isFalse);
      expect(s.canPlace, isFalse);

      await c.selectDeliverySlot(slots.first, branchId: 'br1', date: _day(1));
      await c.update((o) => o.copyWith(fulfillmentType: FulfillmentType.pickup, branchId: () => 'br1'));
      s = h.read(checkoutControllerProvider);
      expect(s.options.deliverySlotId, isNull);
    });

    test('a slot is dropped when the quote turns out to be a split order', () async {
      final (h, c) = await _controller(_backend(split: true));
      await c.update((o) => o.copyWith(addressId: () => 'a1'));
      expect(h.read(checkoutControllerProvider).slotMissing, isFalse);
      await c.selectDeliverySlot(
        const DeliverySlot(
          id: 's-16',
          date: '2026-11-06',
          startsAt: '16:00',
          endsAt: '18:00',
          available: 2,
          isFull: false,
        ),
        branchId: 'br1',
        date: '2026-11-06',
      );
      final s = h.read(checkoutControllerProvider);
      expect(s.options.deliverySlotId, isNull);
      expect(s.canPlace, isTrue, reason: 'split orders never need a slot');
    });
  });

  group('Checkout screen', () {
    Future<TestHarness> pump(WidgetTester t, FakeHandler handler, {Locale locale = const Locale('en')}) async {
      t.view.physicalSize = const Size(900, 3200);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(
        tokens: _signedIn,
        handler: handler,
        prefs: {'locale': locale.languageCode, 'onboarding_done': true},
      );
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: wrapApp(const CheckoutScreen(), locale: locale),
        ),
      );
      for (var i = 0; i < 20; i++) {
        await t.pump(const Duration(milliseconds: 50));
      }
      return h;
    }

    Finder placeButton() => find.widgetWithText(FilledButton, 'Place order');

    testWidgets('shows the picker when required; place is disabled until a slot is tapped', (t) async {
      final h = await pump(t, _backend());
      expect(find.text('Delivery time'), findsOneWidget);
      expect(find.byKey(ValueKey('delivery-date-${_day(1)}')), findsOneWidget);
      expect(
        find.byKey(ValueKey('delivery-date-${_day(2)}')),
        findsNothing,
        reason: 'days without free slots are hidden',
      );
      expect(find.byKey(const ValueKey('delivery-more-dates')), findsOneWidget);
      expect(find.text(_r('16:00', '18:00')), findsOneWidget);
      expect(find.text('2 left'), findsOneWidget);
      expect(find.text('Full'), findsOneWidget);
      expect(find.text('Choose a delivery time to place your order'), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-slot-message')), findsOneWidget, reason: 'slot_required inline');
      expect(tester(t, placeButton()).onPressed, isNull);
      expect(h.read(checkoutControllerProvider).options.deliverySlotId, isNull);

      // A full slot cannot be chosen.
      await t.tap(find.byKey(const ValueKey('delivery-slot-s-20')), warnIfMissed: false);
      await t.pump(const Duration(milliseconds: 200));
      expect(h.read(checkoutControllerProvider).options.deliverySlotId, isNull);

      await t.tap(find.byKey(const ValueKey('delivery-slot-s-16')));
      for (var i = 0; i < 10; i++) {
        await t.pump(const Duration(milliseconds: 50));
      }
      expect(h.read(checkoutControllerProvider).options.deliverySlotId, 's-16');
      expect(find.byKey(const ValueKey('delivery-slot-summary')), findsOneWidget);
      expect(find.textContaining('Delivery: '), findsOneWidget);
      expect(find.textContaining(_r('16:00', '18:00')), findsWidgets);
      expect(tester(t, placeButton()).onPressed, isNotNull);
      expect(find.text('Choose a delivery time to place your order'), findsNothing);
    });

    testWidgets('slot_unavailable is shown inline and the selection is cleared', (t) async {
      final h = await pump(t, _backend(bad: {'s-18'}));
      await t.tap(find.byKey(const ValueKey('delivery-slot-s-18')));
      for (var i = 0; i < 20; i++) {
        await t.pump(const Duration(milliseconds: 50));
      }
      expect(h.read(checkoutControllerProvider).options.deliverySlotId, isNull);
      expect(find.text('That delivery time is no longer available. Please choose another.'), findsOneWidget);
      expect(tester(t, placeButton()).onPressed, isNull);
    });

    testWidgets('no picker for split orders', (t) async {
      await pump(t, _backend(split: true));
      expect(find.text('Delivery time'), findsNothing);
      expect(find.byType(DeliverySlotSection), findsNothing);
      expect(find.byType(SlotTile), findsNothing);
      expect(tester(t, placeButton()).onPressed, isNotNull);
    });

    testWidgets('no picker when the branch does not publish slots', (t) async {
      await pump(t, _backend(slotRequired: false));
      expect(find.byType(DeliverySlotSection), findsNothing);
      expect(tester(t, placeButton()).onPressed, isNotNull);
    });

    testWidgets('Arabic checkout renders RTL with the localised picker', (t) async {
      await pump(t, _backend(), locale: const Locale('ar'));
      expect(find.text('وقت التوصيل'), findsOneWidget);
      expect(find.byType(SlotTile), findsNWidgets(3));
      expect(find.text('مكتمل'), findsOneWidget);
      expect(Directionality.of(t.element(find.byType(SlotTile).first)), TextDirection.rtl);
    });
  });

  group('Order detail window', () {
    test('slot day is formatted from the calendar date, not shifted by the device zone', () async {
      await initializeDateFormatting('en');
      await initializeDateFormatting('ar');
      expect(QatarTime.slotDay('2026-11-08', 'en'), 'Sun 8 Nov');
      expect(QatarTime.slotDay('2026-11-08', 'ar'), matches(RegExp('[8٨]')));
      expect(QatarTime.slotDay('2026-11-08', 'ar'), matches(RegExp(r'[؀-ۿ]')));
      expect(QatarTime.slotDay(null, 'en'), '');
    });

    test('order fulfilment parses the held slot', () {
      final f = Fulfillment.fromJson({
        'id': 'f1',
        'number': 'AB1-1',
        'branch': {'id': 'br1', 'name': 'West Bay'},
        'type': 'delivery',
        'status': 'confirmed',
        'slot': {'date': '2026-11-08', 'starts_at': '16:00', 'ends_at': '18:00'},
      });
      expect(f.slot!['starts_at'], '16:00');
      expect(f.slot!['date'], '2026-11-08');
    });
  });

  group('SlotTile', () {
    const open = DeliverySlot(
      id: 's1',
      date: '2026-11-08',
      startsAt: '16:00',
      endsAt: '18:00',
      available: 2,
      isFull: false,
    );
    const plenty = DeliverySlot(
      id: 's2',
      date: '2026-11-08',
      startsAt: '18:00',
      endsAt: '20:00',
      available: 7,
      isFull: false,
    );
    const full = DeliverySlot(
      id: 's3',
      date: '2026-11-08',
      startsAt: '20:00',
      endsAt: '22:00',
      available: 0,
      isFull: true,
    );

    Widget tiles(void Function(String) onTap) => Column(
      children: [
        SlotTile(slot: open, selected: false, onTap: () => onTap('open')),
        SlotTile(slot: plenty, selected: true, onTap: () => onTap('plenty')),
        SlotTile(slot: full, selected: false, onTap: () => onTap('full')),
      ],
    );

    testWidgets('English: times, remaining count, Full label, disabled when full', (t) async {
      expect(_r('16:00', '18:00'), allOf(contains('4:00'), contains('PM'), contains(' – '), contains('6:00')));
      final taps = <String>[];
      await t.pumpWidget(wrapApp(tiles(taps.add)));
      expect(find.text(_r('16:00', '18:00')), findsOneWidget);
      expect(find.text('2 left'), findsOneWidget);
      expect(find.text(_r('18:00', '20:00')), findsOneWidget);
      expect(find.textContaining('left'), findsOneWidget, reason: 'no count when plenty remain');
      expect(find.text('Full'), findsOneWidget);
      for (final tile in t.widgetList<SlotTile>(find.byType(SlotTile))) {
        expect(t.getSize(find.byWidget(tile)).height, greaterThanOrEqualTo(48));
      }
      expect(Directionality.of(t.element(find.byType(SlotTile).first)), TextDirection.ltr);

      await t.tap(find.text(_r('16:00', '18:00')));
      await t.tap(find.text(_r('20:00', '22:00')), warnIfMissed: false);
      expect(taps, ['open'], reason: 'the full tile ignores taps');
    });

    testWidgets('Arabic: localised times and labels in an RTL layout', (t) async {
      final taps = <String>[];
      await t.pumpWidget(wrapApp(tiles(taps.add), locale: const Locale('ar')));
      expect(Directionality.of(t.element(find.byType(SlotTile).first)), TextDirection.rtl);
      final arabic = RegExp(r'[؀-ۿ]');
      final texts = t.widgetList<Text>(find.descendant(of: find.byType(SlotTile), matching: find.byType(Text)));
      final all = texts.map((e) => e.data ?? '').toList();
      expect(all.where(arabic.hasMatch), isNotEmpty, reason: 'Arabic meridiem / labels, not "PM"');
      expect(all.any((s) => s.contains('PM') || s.contains('AM')), isFalse);
      expect(find.text('مكتمل'), findsOneWidget);
      expect(find.text('متبقٍّ 2'), findsOneWidget);
      final firstRange = all.first;
      expect(firstRange, contains('–'));
      // Start time is laid out on the right in RTL: the range text itself is mirrored by the bidi algorithm.
      expect(QatarTime.slotRange('16:00', '18:00', 'ar'), firstRange);

      await t.tap(find.byType(SlotTile).first);
      expect(taps, ['open']);
    });
  });
}

/// English slot range exactly as rendered (intl puts a narrow no-break space before PM).
String _r(String from, String to) => QatarTime.slotRange(from, to, 'en');

FilledButton tester(WidgetTester t, Finder f) => t.widget<FilledButton>(f);
