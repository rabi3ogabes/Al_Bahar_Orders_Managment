@Timeout(Duration(minutes: 5))
library;

// The customer's order after a failed delivery and after a delivery: the new times to choose (open, all taken, run out, chosen), a refusal in plain
// words, the proof of delivery (with and without the photo) and the assembly answer, in English and Arabic.

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/follow_up_cards.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show ar, backend, boot, en, settle, teardown;
import '../../qa/test_fonts.dart';

Map<String, dynamic> _time(String slot, String date, {bool available = true}) => {
  'slot_id': slot,
  'date': date,
  'starts_at': '16:00',
  'ends_at': '18:00',
  'available': available,
  'label': '',
};

Map<String, dynamic> _offer({
  String status = 'open',
  bool canChoose = true,
  List<Map<String, dynamic>>? options,
  Map<String, dynamic>? chosen,
}) => {
  'id': 'off1',
  'status': status,
  'source': 'system',
  'expires_at': DateTime.now().add(const Duration(hours: 40)).toUtc().toIso8601String(),
  'can_choose': canChoose,
  'options': options ?? [_time('s1', '2026-11-20'), _time('s2', '2026-11-21'), _time('s3', '2026-11-22')],
  'chosen': chosen,
};

Map<String, dynamic> _order({
  Map<String, dynamic>? offer,
  Map<String, dynamic>? proof,
  Map<String, dynamic>? assembly,
  String status = 'failed',
}) {
  final base = orderDetail('o1', status: 'out_for_delivery');
  final f = Map<String, dynamic>.from((base['fulfillments'] as List).first as Map)
    ..['status'] = status
    ..['slot_offer'] = offer
    ..['proof'] = proof
    ..['assembly'] = assembly;
  return {
    ...base,
    'can_return': false,
    'can_cancel': false,
    'fulfillments': [f],
  };
}

class _Shop {
  _Shop(this.order);
  Map<String, dynamic> order;
  final picks = <RecordedRequest>[];
  FakeReply Function(RecordedRequest)? reply;

  FakeHandler get handler => backend(
    extra: (r) {
      if (r.method == 'GET' && r.path == '/orders/o1') return ok(order);
      if (r.method == 'GET' && r.path == '/orders/o1/timeline') return ok(const []);
      if (r.method == 'POST' && r.path == '/orders/o1/slot-offers/off1/choose') {
        picks.add(r);
        return reply?.call(r) ?? ok({'status': 'chosen', 'replayed': false, 'order': order});
      }
      return null;
    },
  );
}

Future<void> _open(WidgetTester t, _Shop shop, {String locale = 'en', double scale = 1}) async {
  if (scale != 1) {
    t.platformDispatcher.textScaleFactorTestValue = scale;
    addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
  }
  final h = await boot(t, shop.handler, locale: locale, height: 1900);
  h.read(routerProvider).go('/orders/o1');
  await settle(t, 10);
}

Future<void> _tapKey(WidgetTester t, String key) async {
  final f = find.byKey(ValueKey(key));
  await t.ensureVisible(f);
  await t.pump();
  await t.tap(f);
  await settle(t, 8);
}

void main() {
  setUpAll(() async {
    await loadAppFonts();
    await initializeDateFormatting('en');
    await initializeDateFormatting('ar');
  });

  group('reading the order', () {
    test('the offer, the proof and the assembly answer are read; an older server sends none', () {
      final f = Order.fromJson(
        _order(
          offer: _offer(),
          proof: {
            'delivered_at': '2026-11-19T12:00:00+03:00',
            'received_by': 'Mariam',
            'photo': {'url': 'https://api.example/photo?signature=x', 'expires_at': '2026-11-19T12:10:00+03:00'},
          },
          assembly: {'status': 'done', 'done_at': '2026-11-19T12:30:00+03:00', 'reason_label': null},
        ),
      ).fulfillments.single;
      expect(f.slotOffer!.isOpen, isTrue);
      expect(f.slotOffer!.canChoose, isTrue);
      expect(f.slotOffer!.options.map((t) => t.key), ['s1|2026-11-20', 's2|2026-11-21', 's3|2026-11-22']);
      expect(f.proof!.receivedBy, 'Mariam');
      expect(f.proof!.photoUrl, contains('signature='));
      expect(f.assembly!.isDone, isTrue);
      final plain = Order.fromJson(orderDetail('o2')).fulfillments.single;
      expect(plain.slotOffer, isNull);
      expect(plain.proof, isNull);
      expect(plain.assembly, isNull);
    });

    test('a live status change keeps them', () {
      final o = Order.fromJson(_order(offer: _offer(), assembly: {'status': 'pending'}));
      final live = o.withLive(
        seq: 5,
        status: 'processing',
        parts: {'f1': (status: 'dispatched', statusLabel: null, deliveryStatus: 'rescheduled')},
      );
      expect(live.fulfillments.single.slotOffer, isNotNull);
      expect(live.fulfillments.single.assembly, isNotNull);
    });

    test('every refusal has words in both languages', () {
      for (final l10n in [en, ar]) {
        ApiException conflict(String code) => ApiException(kind: ApiErrorKind.conflict, code: code);
        expect(slotOfferFailureMessage(l10n, conflict('offer_expired')), l10n.slotOfferExpired);
        expect(slotOfferFailureMessage(l10n, conflict('offer_already_chosen')), l10n.slotOfferErrChosen);
        expect(slotOfferFailureMessage(l10n, conflict('offer_closed')), l10n.slotOfferErrClosed);
        expect(slotOfferFailureMessage(l10n, conflict('slot_full')), l10n.slotOfferErrTaken);
        expect(slotOfferFailureMessage(l10n, const ApiException(kind: ApiErrorKind.notFound)), l10n.slotOfferErrGone);
        expect(slotOfferFailureMessage(l10n, const ApiException(kind: ApiErrorKind.offline)), l10n.errorOffline);
      }
      expect(en.slotOfferConfirm, isNot(ar.slotOfferConfirm));
    });
  });

  group('choosing a new time', () {
    testWidgets('shows the times and keeps Confirm off until one is chosen', (t) async {
      await _open(t, _Shop(_order(offer: _offer())));
      expect(find.text(en.slotOfferTitle), findsOneWidget);
      expect(find.byKey(const ValueKey('slot-offer-time-s1|2026-11-20')), findsOneWidget);
      expect(find.byKey(const ValueKey('slot-offer-time-s3|2026-11-22')), findsOneWidget);
      expect(t.widget<AppButton>(find.byKey(const ValueKey('slot-offer-confirm'))).onPressed, isNull);
      await _tapKey(t, 'slot-offer-time-s2|2026-11-21');
      expect(t.widget<AppButton>(find.byKey(const ValueKey('slot-offer-confirm'))).onPressed, isNotNull);
      await teardown(t);
    });

    testWidgets('Confirm sends the chosen slot once, with an Idempotency-Key, and says thank you', (t) async {
      final shop = _Shop(_order(offer: _offer()));
      await _open(t, shop);
      await _tapKey(t, 'slot-offer-time-s2|2026-11-21');
      await _tapKey(t, 'slot-offer-confirm');
      expect(shop.picks, hasLength(1));
      expect(shop.picks.single.body, {'slot_id': 's2', 'date': '2026-11-21'});
      expect(shop.picks.single.header('Idempotency-Key'), isNotEmpty);
      expect(find.textContaining('Thank you'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a time somebody else took is marked and cannot be chosen', (t) async {
      final options = [_time('s1', '2026-11-20', available: false), _time('s2', '2026-11-21')];
      await _open(t, _Shop(_order(offer: _offer(options: options))));
      expect(find.text(en.slotOfferTaken), findsOneWidget);
      await _tapKey(t, 'slot-offer-time-s1|2026-11-20');
      expect(t.widget<AppButton>(find.byKey(const ValueKey('slot-offer-confirm'))).onPressed, isNull);
      await teardown(t);
    });

    testWidgets('a refusal is put in plain words and the times stay', (t) async {
      final shop = _Shop(_order(offer: _offer()))..reply = (r) => error(409, 'slot_full');
      await _open(t, shop);
      await _tapKey(t, 'slot-offer-time-s1|2026-11-20');
      await _tapKey(t, 'slot-offer-confirm');
      expect(find.text(en.slotOfferErrTaken), findsOneWidget);
      expect(find.byKey(const ValueKey('slot-offer-open')), findsOneWidget);
      await teardown(t);
    });

    testWidgets('when every time was taken, it says so calmly with no buttons', (t) async {
      await _open(
        t,
        _Shop(_order(offer: _offer(canChoose: false, options: [_time('s1', '2026-11-20', available: false)]))),
      );
      expect(find.text(en.slotOfferAllTaken), findsOneWidget);
      expect(find.byKey(const ValueKey('slot-offer-confirm')), findsNothing);
      await teardown(t);
    });

    testWidgets('an offer that ran out says we will call', (t) async {
      await _open(
        t,
        _Shop(
          _order(
            offer: _offer(status: 'expired', canChoose: false, options: const []),
          ),
        ),
      );
      expect(find.text(en.slotOfferExpired), findsOneWidget);
      expect(find.byKey(const ValueKey('slot-offer-confirm')), findsNothing);
      await teardown(t);
    });

    testWidgets('a time already chosen is shown as the delivery time', (t) async {
      await _open(
        t,
        _Shop(
          _order(
            status: 'dispatched',
            offer: _offer(status: 'chosen', canChoose: false, options: const [], chosen: _time('s2', '2026-11-21')),
          ),
        ),
      );
      expect(find.text(en.slotOfferChosen), findsOneWidget);
      expect(find.byKey(const ValueKey('slot-offer-confirm')), findsNothing);
      await teardown(t);
    });

    testWidgets('no offer, nothing shown', (t) async {
      await _open(t, _Shop(_order()));
      expect(find.text(en.slotOfferTitle), findsNothing);
      await teardown(t);
    });

    testWidgets('is in Arabic and survives large text', (t) async {
      await _open(t, _Shop(_order(offer: _offer())), locale: 'ar', scale: 1.3);
      expect(find.text(ar.slotOfferTitle), findsOneWidget);
      expect(find.text(ar.slotOfferConfirm), findsOneWidget);
      expect(t.takeException(), isNull);
      await teardown(t);
    });
  });

  group('the proof of delivery and the assembly', () {
    final proof = {
      'delivered_at': '2026-11-19T12:00:00+03:00',
      'received_by': 'Mariam',
      'photo': {'url': 'https://api.example/photo?signature=x', 'expires_at': '2026-11-19T12:10:00+03:00'},
    };

    testWidgets('says when it was delivered and who received it, with the photo', (t) async {
      await _open(t, _Shop(_order(status: 'delivered', proof: proof)));
      expect(find.byKey(const ValueKey('delivery-proof')), findsOneWidget);
      expect(find.textContaining('received by Mariam'), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-proof-photo')), findsOneWidget);
      await teardown(t);
    });

    testWidgets('without a photo (an older delivery, or the shop hides it) only the line is shown', (t) async {
      await _open(t, _Shop(_order(status: 'delivered', proof: {...proof, 'photo': null})));
      expect(find.textContaining('received by Mariam'), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-proof-photo')), findsNothing);
      await teardown(t);
    });

    testWidgets('a delivery with no name says only when', (t) async {
      await _open(t, _Shop(_order(status: 'delivered', proof: {...proof, 'received_by': null, 'photo': null})));
      expect(find.textContaining('Delivered on'), findsOneWidget);
      expect(find.textContaining('received by'), findsNothing);
      await teardown(t);
    });

    testWidgets('no proof before the delivery', (t) async {
      await _open(t, _Shop(_order(status: 'out_for_delivery')));
      expect(find.byKey(const ValueKey('delivery-proof')), findsNothing);
      await teardown(t);
    });

    testWidgets('says "Assembled on" when it was done, and that the branch will be in touch when it was not', (
      t,
    ) async {
      await _open(
        t,
        _Shop(_order(status: 'delivered', assembly: {'status': 'done', 'done_at': '2026-11-19T12:30:00+03:00'})),
      );
      expect(find.textContaining('Assembled on'), findsOneWidget);
      await teardown(t);
      await _open(
        t,
        _Shop(
          _order(status: 'delivered', assembly: {'status': 'not_done', 'reason_label': 'Parts or tools were missing'}),
        ),
      );
      expect(find.textContaining('Parts or tools were missing'), findsOneWidget);
      expect(find.textContaining('will contact you'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('an assembly nobody answered yet shows nothing', (t) async {
      await _open(t, _Shop(_order(status: 'out_for_delivery', assembly: {'status': 'pending'})));
      expect(find.byKey(const ValueKey('assembly-line')), findsNothing);
      await teardown(t);
    });

    testWidgets('is in Arabic', (t) async {
      await _open(
        t,
        _Shop(_order(status: 'delivered', proof: proof)),
        locale: 'ar',
      );
      expect(find.textContaining('استلمه Mariam'), findsOneWidget);
      expect(t.takeException(), isNull);
      await teardown(t);
    });
  });
}
