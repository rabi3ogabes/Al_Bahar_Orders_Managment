// Why a payment method is missing, said kindly with the numbers, and the way forward when nothing can be paid with.
import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/domain/no_payment.dart';
import 'package:albahar_customer/features/checkout/domain/payment_reasons.dart';
import 'package:albahar_customer/features/checkout/presentation/checkout_screen.dart';
import 'package:albahar_customer/features/checkout/presentation/no_payment_panel.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_reason_text.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:share_plus/share_plus.dart';

import '../../helpers/checkout_backend.dart';
import '../../helpers/fakes.dart';

AppLocalizations get _en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get _ar => lookupAppLocalizations(const Locale('ar'));

Money _qar(num v) => Money(amount: v.toStringAsFixed(2), currency: 'QAR', formatted: 'QAR ${_group(v)}');
String _group(num v) {
  final fixed = v.toStringAsFixed(2).split('.');
  final whole = fixed[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  return '$whole.${fixed[1]}';
}

PaymentOption _option(String code, {bool available = false, String? reason, Money? min, Money? max}) =>
    PaymentOption(code: code, available: available, reason: reason, limitMin: min, limitMax: max);

Branch _branch(String id, String name, {String? phone, bool pickup = true, bool payAtPickup = true}) => Branch(
  id: id,
  name: name,
  phone: phone,
  pickupEnabled: pickup,
  payAtPickupEnabled: payAtPickup,
  deliveryEnabled: true,
);

void main() {
  group('the reasons the server sends', () {
    test('cash on delivery over its limit names the limit and the basket', () {
      final r = paymentReasonOf(
        _option('cod', reason: 'outside_cod_limits', max: _qar(5000)),
        total: _qar(8200),
      );
      expect(r.kind, PaymentReasonKind.codMax);
      expect(
        paymentReasonText(_en, r),
        'Cash on delivery is available up to QAR 5,000.00. Your basket is QAR 8,200.00.',
      );
      final arabic = paymentReasonText(_ar, r);
      expect(arabic, contains('5,000.00'));
      expect(arabic, contains('8,200.00'));
      expect(arabic, isNot(contains('{')));
    });

    test('cash on delivery under its lowest amount says where it starts', () {
      final r = paymentReasonOf(
        _option('cod', reason: 'outside_cod_limits', min: _qar(300)),
        total: _qar(265),
      );
      expect(r.kind, PaymentReasonKind.codMin);
      expect(paymentReasonText(_en, r), 'Cash on delivery is available from QAR 300.00. Your basket is QAR 265.00.');
    });

    test('with both limits the one that was crossed is named', () {
      final o = _option('cod', reason: 'outside_cod_limits', min: _qar(300), max: _qar(5000));
      expect(paymentReasonOf(o, total: _qar(8200)).kind, PaymentReasonKind.codMax);
      expect(paymentReasonOf(o, total: _qar(100)).kind, PaymentReasonKind.codMin);
    });

    test('without numbers (an older server) or without a total the sentence is still whole', () {
      for (final reason in ['outside_cod_limits', 'exceeds_cod_limit']) {
        final r = paymentReasonOf(_option('cod', reason: reason), total: _qar(8200));
        expect(r.kind, PaymentReasonKind.codOutside, reason: reason);
        expect(paymentReasonText(_en, r), 'Cash on delivery is not available for an order of this size.');
      }
      expect(
        paymentReasonOf(_option('cod', reason: 'outside_cod_limits', max: _qar(5000))).kind,
        PaymentReasonKind.codOutside,
      );
    });

    test('every reason has a sentence in English and Arabic, and a new one never shows its code', () {
      const reasons = {
        'temporarily_unavailable': PaymentReasonKind.onlineDown,
        'delivery_only': PaymentReasonKind.deliveryOnly,
        'pickup_only': PaymentReasonKind.pickupOnly,
        'not_offered_in_area': PaymentReasonKind.notOfferedInArea,
        'not_offered_at_branch': PaymentReasonKind.notOfferedAtBranch,
        'brand_new_reason': PaymentReasonKind.unavailable,
        '': PaymentReasonKind.unavailable,
      };
      for (final e in reasons.entries) {
        final r = paymentReasonOf(_option('cod', reason: e.key), total: _qar(100));
        expect(r.kind, e.value, reason: e.key);
        for (final l10n in [_en, _ar]) {
          final text = paymentReasonText(l10n, r);
          expect(text.length, greaterThan(10), reason: e.key);
          expect(text, isNot(contains('_')), reason: 'no raw code: ${e.key}');
          expect(text, isNot(contains('{')));
        }
      }
      expect(
        paymentReasonOf(const PaymentOption(code: 'cod', available: false), total: _qar(1)).kind,
        PaymentReasonKind.unavailable,
      );
    });

    test('the quote reads the limits and knows when nothing can be paid with', () {
      final q = Quote.fromJson({
        'quote_hash': 'h',
        'payment_methods': [
          {'code': 'sadad', 'available': false, 'reason': 'temporarily_unavailable'},
          {
            'code': 'cod',
            'available': false,
            'reason': 'outside_cod_limits',
            'limits': {
              'min': null,
              'max': {'amount': '5000.00', 'currency': 'QAR', 'formatted': 'QAR 5,000.00'},
            },
          },
          {'code': 'pay_at_pickup', 'available': false, 'reason': 'pickup_only'},
        ],
      });
      expect(q.option(PaymentMethod.cod)!.limitMax!.formatted, 'QAR 5,000.00');
      expect(q.option(PaymentMethod.cod)!.limitMin, isNull);
      expect(q.nothingToPayWith, isTrue);
      expect(
        Quote.fromJson({'quote_hash': 'h', 'payment_methods': []}).nothingToPayWith,
        isFalse,
        reason: 'an empty list is not "all off"',
      );
      expect(
        Quote.fromJson({
          'quote_hash': 'h',
          'payment_methods': [
            {'code': 'sadad', 'available': true},
            {'code': 'cod', 'available': false},
          ],
        }).nothingToPayWith,
        isFalse,
      );
    });
  });

  group('the way forward', () {
    final west = _branch('br1', 'West Bay', phone: '+97444000001');
    final lusail = _branch('br2', 'Lusail', phone: '+97444000002');
    final noPay = _branch('br3', 'Wakra', phone: '+97444000003', payAtPickup: false);
    final noPickup = _branch('br4', 'Online', pickup: false);

    test('a showroom to collect from and pay at: one that offers both', () {
      expect(collectAndPayBranch([noPay, noPickup, west], type: FulfillmentType.delivery)!.id, 'br1');
      expect(collectAndPayBranch([noPay, noPickup], type: FulfillmentType.delivery), isNull);
      expect(
        collectAndPayBranch([west, lusail], type: FulfillmentType.delivery, preferredBranchId: 'br2')!.id,
        'br2',
        reason: 'the preferred one first',
      );
    });

    test('for a pickup order the showroom that failed is left out', () {
      expect(collectAndPayBranch([west, lusail], type: FulfillmentType.pickup, currentBranchId: 'br1')!.id, 'br2');
      expect(collectAndPayBranch([west], type: FulfillmentType.pickup, currentBranchId: 'br1'), isNull);
    });

    test('the number to call is one the API sent: the order\'s showroom first, never made up', () {
      expect(contactPhone([west, lusail], orderBranchId: 'br2'), '+97444000002');
      expect(contactPhone([west, lusail], pickupBranchId: 'br1', orderBranchId: 'unknown'), '+97444000001');
      expect(contactPhone([west, lusail], preferredBranchId: 'br2'), '+97444000002');
      expect(contactPhone([noPickup, west]), '+97444000001', reason: 'any showroom that has a number');
      expect(contactPhone([noPickup, _branch('x', 'X', phone: '  ')]), isNull);
      expect(contactPhone(const []), isNull);
    });

    test('the message to the family lists each piece with its page and ends with the total', () {
      final text = basketShareText(
        const [
          ShareLine(
            name: 'Oslo Sofa',
            quantity: 2,
            variantName: 'Grey',
            url: 'https://albahar.qa/app/products/oslo-sofa',
          ),
          ShareLine(name: 'Lamp', quantity: 1),
        ],
        intro: 'Please help.',
        total: 'Total: QAR 8,200.00',
      );
      expect(
        text,
        'Please help.\n\n• Oslo Sofa (Grey) × 2\n  https://albahar.qa/app/products/oslo-sofa\n• Lamp × 1\n\nTotal: QAR 8,200.00',
      );
      final long = basketShareText(
        [for (var i = 1; i <= 18; i++) ShareLine(name: 'Piece $i', quantity: 1)],
        intro: 'Hi',
        total: 'T',
      );
      expect(long, contains('Piece 15'));
      expect(long, isNot(contains('Piece 16')));
      expect(long, contains('… +3'));
    });
  });

  group('the checkout with nothing to pay with', () {
    final tokensIn = AuthTokens.fromJson(tokens('A', 'R'));

    Future<({TestHarness h, List<ShareParams> shared})> open(
      WidgetTester t, {
      Map<String, dynamic> Function(Map<String, dynamic> quote, bool pickup)? quote,
      List<Map<String, dynamic>>? branches,
      String locale = 'en',
    }) async {
      t.view.physicalSize = const Size(390, 3600);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final d = qDay(1);
      final backend = CheckoutBackend(
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
      )..alternatePhoneEnabled = false;
      final shared = <ShareParams>[];
      final h = await TestHarness.create(
        tokens: tokensIn,
        prefs: {'locale': locale, 'onboarding_done': true},
        handler: (r) {
          if (r.method == 'POST' && r.path == '/checkout/quote') {
            final body = backend.quoteFor(r);
            final pickup = body['fulfillment_type'] == 'pickup';
            body['lines'] = [
              {
                'variant_id': 'v1',
                'name': 'Oslo Sofa',
                'variant_name': 'Grey',
                'quantity': 2,
                'line_total': {'amount': '8200.00', 'currency': 'QAR', 'formatted': 'QAR 8,200.00'},
              },
            ];
            body['totals'] = {
              'subtotal': {'amount': '8200.00', 'currency': 'QAR', 'formatted': 'QAR 8,200.00'},
              'delivery_fee': {'amount': '0.00', 'currency': 'QAR', 'formatted': 'QAR 0.00'},
              'grand_total': {'amount': '8200.00', 'currency': 'QAR', 'formatted': 'QAR 8,200.00'},
            };
            return ok((quote ?? _allOff)(body, pickup));
          }
          if (r.method == 'GET' && r.path == '/branches') {
            return page(
              branches ??
                  [
                    {
                      'id': 'br1',
                      'name': 'West Bay',
                      'phone': '+97444000001',
                      'pickup_enabled': true,
                      'delivery_enabled': true,
                      'pay_at_pickup_enabled': true,
                    },
                    {
                      'id': 'br2',
                      'name': 'Lusail',
                      'phone': '+97444000002',
                      'pickup_enabled': true,
                      'delivery_enabled': true,
                      'pay_at_pickup_enabled': true,
                    },
                  ],
            );
          }
          if (r.method == 'GET' && r.path == '/cart') {
            return ok({
              'id': 'c1',
              'branch_id': 'br1',
              'items_count': 2,
              'subtotal': {'amount': '8200.00', 'currency': 'QAR', 'formatted': 'QAR 8,200.00'},
              'items': [
                {
                  'id': 'ci1',
                  'variant_id': 'v1',
                  'quantity': 2,
                  'product': {'id': 'p1', 'slug': 'oslo-sofa', 'name': 'Oslo Sofa'},
                },
              ],
            });
          }
          return backend.handler(r);
        },
        overrides: [shareActionProvider.overrideWithValue((params) async => shared.add(params))],
      );
      h.container.listen(checkoutControllerProvider, (_, _) {});
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
            home: const Scaffold(body: Text('home')),
          ),
        ),
      );
      unawaited(
        Navigator.of(t.element(find.text('home')))
            .push(MaterialPageRoute<void>(builder: (_) => const CheckoutScreen())),
      );
      for (var i = 0; i < 30; i++) {
        await t.pump(const Duration(milliseconds: 50));
      }
      return (h: h, shared: shared);
    }

    testWidgets('says why each way to pay is missing, with the numbers, and offers the next best steps', (t) async {
      final env = await open(t);
      expect(
        find.text('Cash on delivery is available up to QAR 5,000.00. Your basket is QAR 8,200.00.'),
        findsOneWidget,
      );
      expect(find.text(_en.paymentReasonOnlineDown), findsOneWidget);
      expect(find.text(_en.paymentReasonPickupOnly), findsOneWidget);
      expect(find.byType(NoPaymentPanel), findsOneWidget);
      expect(find.text(_en.payNoneTitle), findsOneWidget);
      expect(find.text(_en.payNoneCollect('West Bay')), findsOneWidget);
      expect(find.text(_en.payNoneCall), findsOneWidget);
      expect(find.text(_en.payNoneShare), findsOneWidget);
      expect(find.text(_en.payNoneHint), findsOneWidget, reason: 'the bar says why the order cannot be placed yet');
      expect(find.textContaining('exceeds_cod'), findsNothing);
      expect(find.textContaining('outside_cod'), findsNothing);
      expect(env.h.read(checkoutControllerProvider).canPlace, isFalse);
      expect(t.takeException(), isNull);
    });

    testWidgets('Collect and pay moves the order to pickup at that showroom, where pay at pickup is open', (t) async {
      final env = await open(t, quote: (q, pickup) => pickup ? _pickupOk(q) : _allOff(q, pickup));
      await t.ensureVisible(find.text(_en.payNoneCollect('West Bay')));
      await t.tap(find.text(_en.payNoneCollect('West Bay')));
      for (var i = 0; i < 30; i++) {
        await t.pump(const Duration(milliseconds: 50));
      }
      final state = env.h.read(checkoutControllerProvider);
      expect(state.options.fulfillmentType, FulfillmentType.pickup);
      expect(state.options.branchId, 'br1');
      expect(find.byType(NoPaymentPanel), findsNothing, reason: 'a way to pay is open now');
      expect(state.options.paymentMethod, PaymentMethod.payAtPickup, reason: 'it is chosen for the customer');
      expect(t.takeException(), isNull);
    });

    testWidgets('Share this basket hands the family the pieces, their pages and the total', (t) async {
      final env = await open(t);
      await t.ensureVisible(find.text(_en.payNoneShare));
      await t.tap(find.text(_en.payNoneShare));
      await t.pump();
      expect(env.shared, hasLength(1));
      final text = env.shared.single.text!;
      expect(text, contains(_en.payNoneShareIntro));
      expect(text, contains('• Oslo Sofa (Grey) × 2'));
      expect(text, contains('/products/oslo-sofa'));
      expect(text, contains('Total: QAR 8,200.00'));
    });

    testWidgets(
      'with no showroom that takes pay-at-pickup and none with a number, only what exists is offered (never a made-up number)',
      (t) async {
        await open(
          t,
          branches: [
            {
              'id': 'br1',
              'name': 'West Bay',
              'pickup_enabled': true,
              'delivery_enabled': true,
              'pay_at_pickup_enabled': false,
            },
          ],
        );
        expect(find.byType(NoPaymentPanel), findsOneWidget);
        expect(find.textContaining('Collect from'), findsNothing);
        expect(find.text(_en.payNoneCall), findsNothing);
        expect(find.text(_en.payNoneShare), findsOneWidget, reason: 'sharing needs no showroom');
      },
    );

    testWidgets('is shown in Arabic with the numbers and no raw codes, on a small phone without overflow', (t) async {
      t.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(t.platformDispatcher.clearAllTestValues);
      await open(t, locale: 'ar');
      expect(find.text(_ar.payNoneTitle), findsOneWidget);
      expect(find.textContaining('5,000.00'), findsWidgets);
      expect(find.text(_ar.payNoneCall), findsOneWidget);
      expect(find.textContaining('outside_cod'), findsNothing);
      expect(t.takeException(), isNull);
    });
  });
}

Map<String, dynamic> _allOff(Map<String, dynamic> quote, bool pickup) => {
  ...quote,
  'payment_methods': [
    {'code': 'sadad', 'available': false, 'reason': 'temporarily_unavailable'},
    {
      'code': 'cod',
      'available': false,
      'reason': pickup ? 'delivery_only' : 'outside_cod_limits',
      if (!pickup)
        'limits': {
          'min': null,
          'max': {'amount': '5000.00', 'currency': 'QAR', 'formatted': 'QAR 5,000.00'},
        },
    },
    {'code': 'pay_at_pickup', 'available': false, 'reason': pickup ? 'not_offered_at_branch' : 'pickup_only'},
  ],
};

Map<String, dynamic> _pickupOk(Map<String, dynamic> quote) => {
  ...quote,
  'payment_methods': [
    {'code': 'sadad', 'available': false, 'reason': 'temporarily_unavailable'},
    {'code': 'cod', 'available': false, 'reason': 'delivery_only'},
    {'code': 'pay_at_pickup', 'available': true},
  ],
};
