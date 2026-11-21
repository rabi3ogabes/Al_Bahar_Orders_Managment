@Timeout(Duration(minutes: 5))
library;

// What the customer sees on an order after delivery: where a return request stands (and no button that would ask again), a return form that
// offers only what can still go back, a review that asks which item when there are several, and the driver's call button only while the
// driver has the order.

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/return_errors.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/return_status_card.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show ar, backend, boot, en, settle, teardown;
import '../../qa/test_fonts.dart';

Map<String, dynamic> _line(
  String id,
  String productId,
  String name, {
  int quantity = 2,
  int? returnable,
  String fulfillment = 'f1',
  Map<String, dynamic>? adjustment,
}) => {
  'id': id,
  'product_id': productId,
  'variant_id': 'v-$id',
  'sku': 'SKU-$id',
  'name': name,
  'variant_name': null,
  'image_url': null,
  'quantity': quantity,
  'unit_price': money('100.00'),
  'line_total': money('${quantity * 100}.00'),
  'fulfillment_id': fulfillment,
  'returnable_quantity': ?returnable,
  'adjustment': ?adjustment,
};

Map<String, dynamic> _fulfillment(String id, String status, {Map<String, dynamic>? delivery}) => {
  'id': id,
  'number': 'AB26o1-$id',
  'branch': {'id': 'br1', 'name': 'West Bay', 'phone': '+97444000001'},
  'type': 'delivery',
  'status': status,
  'status_label': status,
  'delivery': ?delivery,
};

Map<String, dynamic> _order({
  List<Map<String, dynamic>>? items,
  List<Map<String, dynamic>>? fulfillments,
  bool canReturn = true,
  bool canReview = true,
  Map<String, dynamic>? returnRequest,
  String status = 'completed',
}) => {
  ...orderDetail('o1', status: status),
  'items': items ?? [_line('i1', 'p1', 'Oslo Sofa', returnable: 2)],
  'fulfillments': fulfillments ?? [_fulfillment('f1', 'delivered')],
  'can_return': canReturn,
  'can_review': canReview,
  'can_cancel': false,
  'return_request': ?returnRequest,
};

Map<String, dynamic> _request(String status, {String? note, String? decided = '2026-11-04T09:00:00+03:00'}) => {
  'id': 'rr1',
  'number': 'RT26001',
  'status': status,
  'status_label': status,
  'requested_at': '2026-11-03T10:00:00+03:00',
  'decided_at': status == 'requested' ? null : decided,
  'decision_note': note,
};

/// A claim is sent as multipart: the lines, the kind, the note and the photos come back as one map for the assertions.
Map<String, dynamic> _readClaim(FormData form) {
  final f = {for (final e in form.fields) e.key: e.value};
  final items = <Map<String, Object>>[];
  for (var i = 0; f.containsKey('items[$i][order_item_id]'); i++) {
    items.add({'order_item_id': f['items[$i][order_item_id]']!, 'quantity': int.parse(f['items[$i][quantity]']!)});
  }
  return {
    'items': items,
    'reason_code': f['reason_code'],
    if (f.containsKey('reason')) 'reason': f['reason'],
    'photos': [for (final e in form.files) e.value.filename],
  };
}

class _Shop {
  _Shop(this.order);
  Map<String, dynamic> order;
  final returns = <Map<String, dynamic>>[];
  FakeReply Function()? returnReply;

  FakeHandler get handler => backend(
    extra: (r) {
      if (r.method == 'GET' && r.path == '/orders/o1') return ok(order);
      if (r.method == 'GET' && r.path == '/orders/o1/timeline') return ok(const []);
      if (r.method == 'POST' && r.path == '/orders/o1/returns') {
        returns.add(_readClaim(r.body as FormData));
        return returnReply?.call() ?? ok({'id': 'rr1', 'number': 'RT26001', 'status': 'requested'}, status: 201);
      }
      return null;
    },
  );
}

Future<TestHarness> _open(WidgetTester t, _Shop shop, {String locale = 'en', bool tall = true}) async {
  final h = await boot(t, shop.handler, locale: locale, height: tall ? 1700 : 844);
  h.read(routerProvider).go('/orders/o1');
  await settle(t, 10);
  return h;
}

Finder _button(String label) => find.widgetWithText(AppButton, label);

Future<void> _tapButton(WidgetTester t, String label) async {
  final target = _button(label);
  await t.ensureVisible(target);
  await t.pump();
  await t.tap(target);
  await settle(t);
}

void main() {
  setUpAll(() async {
    await loadAppFonts();
    await initializeDateFormatting('en');
    await initializeDateFormatting('ar');
  });

  group('reading the order from the server', () {
    test('the return request, the returnable quantity and the driver\'s number are read', () {
      final o = Order.fromJson({
        ..._order(
          returnRequest: _request('rejected', note: '  Sorry, a used sofa cannot be taken back.  '),
          items: [_line('i1', 'p1', 'Oslo Sofa', returnable: 1)],
          fulfillments: [
            _fulfillment(
              'f1',
              'dispatched',
              delivery: {'status': 'out_for_delivery', 'driver_name': 'Omar', 'driver_phone': '+97455123499'},
            ),
          ],
        ),
      });
      expect(o.returnRequest!.number, 'RT26001');
      expect(o.returnRequest!.status, 'rejected');
      expect(o.returnRequest!.isRejected, isTrue);
      expect(o.returnRequest!.isOpen, isFalse);
      expect(o.returnRequest!.decisionNote, 'Sorry, a used sofa cannot be taken back.');
      expect(o.returnRequest!.requestedAt, isNotNull);
      expect(o.items.single.returnableQuantity, 1);
      expect(o.fulfillments.single.delivery!.driverPhone, '+97455123499');
      expect(Order.fromJson(_order()).returnRequest, isNull);
      expect(
        Order.fromJson(_order(items: [_line('i1', 'p1', 'Sofa')])).items.single.returnableNow,
        2,
        reason: 'an older server: the whole line',
      );
    });

    test('a live update that ends the delivery takes the driver\'s number away at once', () {
      const live = DeliveryInfo(status: 'picked_up', driverName: 'Omar', driverPhone: '+97455123499');
      expect(live.withStatus('out_for_delivery').driverPhone, '+97455123499');
      for (final done in ['delivered', 'failed', 'cancelled', 'returned', 'rescheduled', 'pending']) {
        expect(live.withStatus(done).driverPhone, isNull, reason: done);
        expect(live.withStatus(done).driverName, 'Omar');
      }
    });

    test('the products that can be reviewed: delivered or collected, not taken out, one per product', () {
      final o = Order.fromJson(
        _order(
          items: [
            _line('i1', 'p1', 'Oslo Sofa'),
            _line('i2', 'p1', 'Oslo Sofa (Grey)'),
            _line('i3', 'p2', 'Lamp'),
            _line('i4', 'p3', 'Rug', fulfillment: 'f2'),
            _line('i5', 'p4', 'Removed vase', adjustment: {'kind': 'removed', 'original_quantity': 1}),
          ],
          fulfillments: [_fulfillment('f1', 'delivered'), _fulfillment('f2', 'preparing')],
        ),
      );
      expect(o.reviewableItems.map((i) => i.productId), ['p1', 'p2']);
    });
  });

  group('the return status', () {
    test('every state has a sentence in English and Arabic', () {
      for (final status in ['requested', 'approved', 'rejected', 'received', 'refunded', 'closed', 'something_new']) {
        final r = ReturnRequestInfo.maybe(_request(status))!;
        for (final (l10n, locale) in [(en, 'en'), (ar, 'ar')]) {
          final text = ReturnStatusCard.sentence(l10n, r, locale);
          expect(text.length, greaterThan(10), reason: status);
          expect(text, isNot(contains('{')), reason: status);
          expect(text, isNot(contains('_')), reason: 'no raw word: $status');
        }
      }
      final asked = ReturnStatusCard.sentence(en, ReturnRequestInfo.maybe(_request('requested'))!, 'en');
      expect(asked, contains('Nov 3, 2026'));
    });

    testWidgets('a waiting request is a card with its status, and the Return button is gone', (t) async {
      await _open(t, _Shop(Model.order(returnRequest: _request('requested'), canReturn: false)));
      expect(find.byKey(const ValueKey('return-status-card')), findsOneWidget);
      expect(find.text(en.returnStatusTitle('RT26001')), findsOneWidget);
      expect(find.textContaining('received on'), findsOneWidget);
      expect(_button(en.requestReturn), findsNothing);
      await teardown(t);
    });

    testWidgets(
      'a refused request shows the team\'s note, and the button is back while the server allows another request',
      (t) async {
        await _open(
          t,
          _Shop(
            Model.order(
              returnRequest: _request('rejected', note: 'A used sofa cannot be taken back.'),
              canReturn: true,
            ),
          ),
        );
        expect(find.textContaining('could not accept'), findsOneWidget);
        expect(find.textContaining('A used sofa cannot be taken back.'), findsOneWidget);
        expect(find.text(en.returnStatusRejectedHelp), findsOneWidget);
        expect(_button(en.requestReturn), findsOneWidget);
        await teardown(t);
      },
    );

    testWidgets('approved, received and refunded each say where it stands', (t) async {
      for (final (status, words) in [
        ('approved', 'was approved'),
        ('received', 'received your returned'),
        ('refunded', 'refund has been raised'),
      ]) {
        await _open(t, _Shop(Model.order(returnRequest: _request(status), canReturn: false)));
        expect(find.textContaining(words), findsOneWidget, reason: status);
        expect(_button(en.requestReturn), findsNothing, reason: status);
        await teardown(t);
      }
    });

    testWidgets('is in Arabic for an Arabic customer', (t) async {
      await _open(
        t,
        _Shop(Model.order(returnRequest: _request('rejected', note: 'نعتذر'), canReturn: false)),
        locale: 'ar',
      );
      expect(find.text(ar.returnStatusTitle('RT26001')), findsOneWidget);
      expect(find.textContaining('لم نتمكن من قبول'), findsOneWidget);
      expect(find.textContaining('نعتذر'), findsWidgets);
      await teardown(t);
    });

    testWidgets('no request, no card', (t) async {
      await _open(t, _Shop(Model.order()));
      expect(find.byKey(const ValueKey('return-status-card')), findsNothing);
      expect(_button(en.requestReturn), findsOneWidget);
      await teardown(t);
    });
  });

  group('the return form', () {
    final twoLines = Model.order(
      items: [
        _line('i1', 'p1', 'Oslo Sofa', quantity: 3, returnable: 2),
        _line('i2', 'p2', 'Floor lamp', quantity: 1, returnable: 0),
        _line('i3', 'p3', 'Rug', quantity: 1, returnable: 1),
      ],
    );

    testWidgets('lists only what can still go back, with a stepper that stops at that number', (t) async {
      await _open(t, _Shop(twoLines));
      await _tapButton(t, en.requestReturn);
      expect(find.byKey(const ValueKey('return-line-i1')), findsOneWidget);
      expect(find.byKey(const ValueKey('return-line-i3')), findsOneWidget);
      expect(find.byKey(const ValueKey('return-line-i2')), findsNothing, reason: 'nothing of the lamp can be returned');
      expect(find.text(en.returnUpTo(2)), findsOneWidget);
      expect(find.text(en.returnUpTo(1)), findsOneWidget);
      expect(
        t.widget<AppButton>(find.byKey(const ValueKey('claim-submit'))).onPressed,
        isNull,
        reason: 'nothing chosen yet',
      );

      final sofa = find.descendant(
        of: find.byKey(const ValueKey('return-line-i1')),
        matching: find.byType(QuantityStepper),
      );
      final plus = find.descendant(of: sofa, matching: find.byIcon(Icons.add_rounded));
      for (var i = 0; i < 5; i++) {
        if (plus.evaluate().isNotEmpty) await t.tap(plus.first, warnIfMissed: false);
        await t.pump();
      }
      expect(t.widget<QuantityStepper>(sofa).value, 2, reason: 'never more than can be returned');
      await teardown(t);
    });

    testWidgets('sends the chosen quantities, the reason and the note', (t) async {
      final shop = _Shop(twoLines);
      await _open(t, shop);
      await _tapButton(t, en.requestReturn);
      await t.tap(find.byKey(const ValueKey('claim-type-quality')));
      await t.pump();
      final sofa = find.descendant(
        of: find.byKey(const ValueKey('return-line-i1')),
        matching: find.byType(QuantityStepper),
      );
      await t.tap(find.descendant(of: sofa, matching: find.byIcon(Icons.add_rounded)).first);
      await t.pump();
      await t.enterText(find.byType(TextField).last, '  The leg is cracked  ');
      await t.pump();
      await t.tap(_button(en.requestReturn).last);
      await settle(t, 10);

      expect(shop.returns, hasLength(1));
      expect(shop.returns.single['items'], [
        {'order_item_id': 'i1', 'quantity': 1},
      ]);
      expect(shop.returns.single['reason_code'], 'quality');
      expect(shop.returns.single['photos'], isEmpty, reason: 'a quality problem may be sent without a photo');
      expect(shop.returns.single['reason'], 'The leg is cracked');
      expect(find.text(en.returnRequested), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a refusal from the server is explained, not shown as a bare "can\'t be returned"', (t) async {
      final shop = _Shop(twoLines)
        ..returnReply = () => error(422, 'http_error', message: 'These items can\'t be returned.');
      await _open(t, shop);
      await _tapButton(t, en.requestReturn);
      await t.tap(find.byKey(const ValueKey('claim-type-changed_mind')));
      await t.pump();
      final sofa = find.descendant(
        of: find.byKey(const ValueKey('return-line-i1')),
        matching: find.byType(QuantityStepper),
      );
      await t.tap(find.descendant(of: sofa, matching: find.byIcon(Icons.add_rounded)).first);
      await t.pump();
      await t.tap(_button(en.requestReturn).last);
      await settle(t, 10);
      expect(find.text(en.returnErrNotAllowed), findsOneWidget);
      expect(find.text('These items can\'t be returned.'), findsNothing);
      await teardown(t);
    });

    test('every failure is put in plain words', () {
      ApiException failure(ApiErrorKind k, {Map<String, List<String>> errors = const {}, String? message}) =>
          ApiException(kind: k, errors: errors, message: message);
      expect(returnFailureMessage(en, failure(ApiErrorKind.forbidden)), en.returnsUnavailable);
      expect(returnFailureMessage(en, failure(ApiErrorKind.validation)), en.returnErrNotAllowed);
      expect(
        returnFailureMessage(
          en,
          failure(
            ApiErrorKind.validation,
            errors: {
              'items.0.quantity': ['x'],
            },
          ),
        ),
        en.returnErrItems,
      );
      expect(
        returnFailureMessage(
          en,
          failure(
            ApiErrorKind.validation,
            errors: {
              'items': ['x'],
            },
          ),
        ),
        en.returnErrItems,
      );
      expect(
        returnFailureMessage(
          en,
          failure(
            ApiErrorKind.validation,
            errors: {
              'reason_code': ['x'],
            },
          ),
        ),
        en.returnErrReason,
      );
      expect(returnFailureMessage(en, failure(ApiErrorKind.offline)), en.errorOffline);
      expect(returnFailureMessage(en, failure(ApiErrorKind.server)), en.errorServer);
      expect(returnFailureMessage(ar, failure(ApiErrorKind.validation)), ar.returnErrNotAllowed);
      expect(returnFailureMessage(en, StateError('x')), en.errorGeneric);
    });
  });

  group('writing a review of a many-item order', () {
    final many = Model.order(
      items: [
        _line('i1', 'p1', 'Oslo Sofa'),
        _line('i2', 'p2', 'Floor lamp'),
        _line('i3', 'p3', 'Rug', fulfillment: 'f2'),
      ],
      fulfillments: [_fulfillment('f1', 'delivered'), _fulfillment('f2', 'preparing')],
      canReturn: false,
    );

    testWidgets('asks which item, lists only the delivered ones, and opens the review of the chosen one', (t) async {
      await _open(t, _Shop(many));
      await _tapButton(t, en.writeReview);
      expect(find.text(en.reviewWhichTitle), findsOneWidget);
      expect(find.byKey(const ValueKey('review-item-i1')), findsOneWidget);
      expect(find.byKey(const ValueKey('review-item-i2')), findsOneWidget);
      expect(find.byKey(const ValueKey('review-item-i3')), findsNothing, reason: 'the rug has not arrived');

      await t.tap(find.byKey(const ValueKey('review-item-i2')));
      await settle(t, 8);
      expect(find.text(en.writeReviewTitle), findsOneWidget, reason: 'the review window opened');
      expect(find.textContaining('Floor lamp'), findsWidgets, reason: 'about the item they chose, not the first one');
      await teardown(t);
    });

    testWidgets('with a single reviewable product the review opens at once', (t) async {
      await _open(t, _Shop(Model.order(canReturn: false)));
      await _tapButton(t, en.writeReview);
      expect(find.text(en.reviewWhichTitle), findsNothing);
      expect(find.text(en.writeReviewTitle), findsOneWidget);
      await teardown(t);
    });

    testWidgets('no button when nothing in the order has arrived', (t) async {
      await _open(
        t,
        _Shop(Model.order(fulfillments: [_fulfillment('f1', 'preparing')], canReview: true, canReturn: false)),
      );
      expect(_button(en.writeReview), findsNothing);
      await teardown(t);
    });
  });

  group('calling the driver', () {
    Map<String, dynamic> onTheRoad({String? phone, String? name = 'Omar'}) => Model.order(
      fulfillments: [
        _fulfillment(
          'f1',
          'out_for_delivery',
          delivery: {
            'status': 'out_for_delivery',
            'status_label': 'On the way',
            'driver_name': name,
            'driver_phone': phone,
          },
        ),
      ],
      status: 'out_for_delivery',
      canReturn: false,
      canReview: false,
    );

    testWidgets('the call button is there while the server sends the number', (t) async {
      await _open(t, _Shop(onTheRoad(phone: '+97455123499')));
      expect(find.byKey(const ValueKey('call-driver')), findsOneWidget);
      await teardown(t);
    });

    testWidgets('no number, no button', (t) async {
      await _open(t, _Shop(onTheRoad()));
      expect(find.byKey(const ValueKey('call-driver')), findsNothing);
      expect(find.text('Omar'), findsOneWidget, reason: 'the driver\'s name is still shown');
      await teardown(t);
    });

    testWidgets('a number with no name still gets its button', (t) async {
      await _open(t, _Shop(onTheRoad(phone: '+97455123499', name: null)));
      expect(find.byKey(const ValueKey('call-driver')), findsOneWidget);
      expect(find.text(en.callDriver), findsOneWidget);
      await teardown(t);
    });
  });
}

/// The order JSON builder under a short name.
abstract final class Model {
  static Map<String, dynamic> order({
    List<Map<String, dynamic>>? items,
    List<Map<String, dynamic>>? fulfillments,
    bool canReturn = true,
    bool canReview = true,
    Map<String, dynamic>? returnRequest,
    String status = 'completed',
  }) => _order(
    items: items,
    fulfillments: fulfillments,
    canReturn: canReturn,
    canReview: canReview,
    returnRequest: returnRequest,
    status: status,
  );
}
