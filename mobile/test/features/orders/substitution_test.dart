@Timeout(Duration(minutes: 5))
library;

// Substitution consent in the customer app: "We do not have X. Replace it with Y?" with Accept or "No, remove it and refund me", the time left,
// what happens if nobody answers, how a question ended, a refusal in plain words, Arabic and large text.

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/substitution_cards.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show ar, backend, boot, en, settle, teardown;
import '../../qa/test_fonts.dart';

Map<String, dynamic> _line(String name, int qty, String total, {String? variant}) => {
  'name': name,
  'variant_name': variant,
  'quantity': qty,
  'image_url': null,
  'total': money(total),
};

Map<String, dynamic> _offer({
  String id = 'sq1',
  String status = 'waiting',
  String diff = '-800.00',
  String fallback = 'remove',
  int minutes = 22,
  bool canAnswer = true,
}) => {
  'id': id,
  'status': status,
  'original': _line('Oslo Sofa', 2, '2000.00', variant: 'Grey'),
  'replacement': _line('Bergen Sofa', 2, '1200.00'),
  'price_difference': money(diff),
  'fallback': fallback,
  'asked_at': DateTime.now().subtract(const Duration(minutes: 8)).toUtc().toIso8601String(),
  'expires_at': DateTime.now().add(Duration(minutes: minutes)).toUtc().toIso8601String(),
  'answered_at': status == 'waiting' ? null : DateTime.now().toUtc().toIso8601String(),
  'can_answer': canAnswer,
};

Map<String, dynamic> _order(List<Map<String, dynamic>> offers) => {
  ...orderDetail('o1', status: 'processing'),
  'can_return': false,
  'can_cancel': false,
  'substitution_requests': offers,
  'waiting_for_customer': offers.any((o) => o['status'] == 'waiting'),
};

class _Shop {
  _Shop(this.order);
  Map<String, dynamic> order;
  final answers = <Object?>[];
  FakeReply Function(RecordedRequest)? reply;

  FakeHandler get handler => backend(
    extra: (r) {
      if (r.method == 'GET' && r.path == '/orders/o1') return ok(order);
      if (r.method == 'GET' && r.path == '/orders/o1/timeline') return ok(const []);
      if (r.method == 'POST' && r.path == '/orders/o1/substitutions/sq1') {
        answers.add((r.body as Map)['answer']);
        return reply?.call(r) ?? ok({'status': 'accepted', 'replayed': false, 'order': order});
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

  group('reading the questions', () {
    test('the open ones are read, soonest first; the price is never above what was ordered', () {
      final o = Order.fromJson(
        _order([_offer(id: 'a', minutes: 40), _offer(id: 'b', minutes: 10), _offer(id: 'c', status: 'accepted')]),
      );
      expect(o.substitutions.map((s) => s.id), ['a', 'b', 'c']);
      expect(waitingOffers(o.substitutions).map((s) => s.id), ['b', 'a']);
      final s = o.substitutions.first;
      expect(s.isWaiting, isTrue);
      expect(s.isCheaper, isTrue);
      expect(s.original!.name, 'Oslo Sofa');
      expect(s.original!.variantName, 'Grey');
      expect(s.original!.quantity, 2);
      expect(s.replacement!.total!.formatted, 'QAR 1200.00');
      expect(SubstitutionOffer.fromJson(_offer(diff: '0.00')).isCheaper, isFalse);
      expect(Order.fromJson(orderDetail('o2')).substitutions, isEmpty, reason: 'an older server sends none');
    });

    test('the time left counts up to the deadline and never goes below zero', () {
      final now = DateTime(2026, 11, 19, 12);
      SubstitutionOffer at(DateTime d) => SubstitutionOffer(id: 'x', status: 'waiting', expiresAt: d);
      expect(at(now.add(const Duration(minutes: 29, seconds: 1))).minutesLeft(now), 30);
      expect(at(now.add(const Duration(minutes: 1))).minutesLeft(now), 1);
      expect(at(now).minutesLeft(now), 0);
      expect(at(now.subtract(const Duration(hours: 1))).minutesLeft(now), 0);
      expect(const SubstitutionOffer(id: 'x', status: 'waiting').minutesLeft(now), 0);
    });

    test('every way a question can end and every refusal has words in both languages', () {
      for (final l10n in [en, ar]) {
        for (final s in ['accepted', 'declined', 'expired_removed', 'expired_kept', 'cancelled']) {
          expect(substitutionOutcome(l10n, s), isNotEmpty, reason: s);
        }
        expect(substitutionOutcome(l10n, 'waiting'), isNull);
        expect(substitutionOutcome(l10n, 'something_new'), isNull);
      }
      ApiException conflict(String code) => ApiException(kind: ApiErrorKind.conflict, code: code);
      expect(substitutionFailureMessage(en, conflict('substitution_already_answered')), en.substErrAnswered);
      expect(substitutionFailureMessage(en, conflict('substitution_expired')), en.substErrExpired);
      expect(substitutionFailureMessage(en, conflict('substitution_closed')), en.substErrClosed);
      expect(substitutionFailureMessage(ar, const ApiException(kind: ApiErrorKind.notFound)), ar.substErrGone);
      expect(substitutionFailureMessage(en, const ApiException(kind: ApiErrorKind.offline)), en.errorOffline);
    });
  });

  group('the question on the order', () {
    testWidgets('names both items, says the price, the time left and what happens if nobody answers', (t) async {
      await _open(t, _Shop(_order([_offer()])));
      expect(find.byKey(const ValueKey('substitution-sq1')), findsOneWidget);
      expect(find.text(en.substAsk('Oslo Sofa', 'Bergen Sofa')), findsOneWidget);
      expect(find.text(en.substPriceLine(en.substPriceLess('QAR 800.00'))), findsOneWidget);
      expect(find.textContaining('min left'), findsOneWidget);
      expect(find.text(en.substFallbackRemove), findsOneWidget);
      expect(find.text('Grey'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('the same price and a "keep" fallback say so', (t) async {
      await _open(t, _Shop(_order([_offer(diff: '0.00', fallback: 'keep')])));
      expect(find.text(en.substPriceLine(en.substPriceSame)), findsOneWidget);
      expect(find.text(en.substFallbackKeep), findsOneWidget);
      await teardown(t);
    });

    testWidgets('Accept sends the answer once and says thank you; the order is read again', (t) async {
      final shop = _Shop(_order([_offer()]));
      await _open(t, shop);
      await _tapKey(t, 'substitution-accept');
      expect(shop.answers, ['accept']);
      expect(find.text(en.substAcceptedToast), findsOneWidget);
      await teardown(t);
    });

    testWidgets('"No, remove it and refund me" sends decline', (t) async {
      final order = _order([_offer()]);
      final shop = _Shop(order)..reply = (r) => ok({'status': 'declined', 'replayed': false, 'order': order});
      await _open(t, shop);
      await _tapKey(t, 'substitution-decline');
      expect(shop.answers, ['decline']);
      expect(find.text(en.substDeclinedToast), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a refusal from the server is put in plain words, and the buttons are back', (t) async {
      final shop = _Shop(_order([_offer()]))..reply = (r) => error(409, 'substitution_expired');
      await _open(t, shop);
      await _tapKey(t, 'substitution-accept');
      expect(find.text(en.substErrExpired), findsOneWidget);
      expect(t.widget<AppButton>(find.byKey(const ValueKey('substitution-accept'))).onPressed, isNotNull);
      await teardown(t);
    });

    testWidgets('a question that cannot be answered any more has its buttons switched off', (t) async {
      await _open(t, _Shop(_order([_offer(canAnswer: false, minutes: -1)])));
      expect(t.widget<AppButton>(find.byKey(const ValueKey('substitution-accept'))).onPressed, isNull);
      expect(t.widget<AppButton>(find.byKey(const ValueKey('substitution-decline'))).onPressed, isNull);
      expect(find.byKey(const ValueKey('substitution-clock')), findsOneWidget);
      expect(find.text(en.substTimeUp), findsOneWidget);
      await teardown(t);
    });

    testWidgets('an answered or settled question is a calm line, with no buttons', (t) async {
      await _open(t, _Shop(_order([_offer(status: 'accepted'), _offer(id: 'sq2', status: 'expired_removed')])));
      expect(find.byKey(const ValueKey('substitution-accept')), findsNothing);
      expect(find.byKey(const ValueKey('substitution-history')), findsOneWidget);
      expect(find.textContaining(en.substOutcomeAccepted), findsOneWidget);
      expect(find.textContaining(en.substOutcomeExpiredRemoved), findsOneWidget);
      await teardown(t);
    });

    testWidgets('no question, nothing shown', (t) async {
      await _open(t, _Shop(_order(const [])));
      expect(find.byKey(const ValueKey('substitution-history')), findsNothing);
      expect(find.text(en.substTitle), findsNothing);
      await teardown(t);
    });

    testWidgets('is in Arabic and survives large text', (t) async {
      await _open(t, _Shop(_order([_offer()])), locale: 'ar', scale: 1.3);
      expect(find.text(ar.substAsk('Oslo Sofa', 'Bergen Sofa')), findsOneWidget);
      expect(find.text(ar.substDecline), findsOneWidget);
      expect(find.text(ar.substFallbackRemove), findsOneWidget);
      expect(t.takeException(), isNull);
      await teardown(t);
    });
  });
}
