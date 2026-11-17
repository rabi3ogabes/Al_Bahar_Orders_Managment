// The payment status page: what stays on screen when a payment is tried again, and what the back button does while one is checked.
import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_status_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

final _signedIn = AuthTokens.fromJson(tokens('A', 'R'));

AppLocalizations get _en => lookupAppLocalizations(const Locale('en'));

Map<String, dynamic> _payment(String id, String status, {String? reason}) => {
  'id': id,
  'status': status,
  'method': 'sadad',
  'amount': money('125.00'),
  'order_id': 'o1',
  'failure_reason': ?reason,
};

Future<void> _settle(WidgetTester t, [int frames = 12]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

/// The status page opened over a home page, so back has somewhere to go.
Future<TestHarness> _open(WidgetTester t, FakeHandler handler) async {
  t.view.physicalSize = const Size(390, 844);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(tokens: _signedIn, handler: handler);
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
  unawaited(
    Navigator.of(home).push(
      MaterialPageRoute<void>(
        builder: (_) => const PaymentStatusScreen(paymentId: 'p1', orderId: 'o1', orderNumber: 'AB1'),
      ),
    ),
  );
  await _settle(t);
  return h;
}

void main() {
  testWidgets('trying a failed payment again starts from "waiting", the old "failed" does not stay on screen', (
    t,
  ) async {
    var polls2 = 0;
    final h = await _open(
      t,
      routes({
        'GET /payments/p1': (_) => ok(_payment('p1', 'failed', reason: 'Card declined')),
        'POST /orders/o1/payments': (_) =>
            ok({'payment_id': 'p2', 'method': 'sadad', 'status': 'pending', 'action': 'none'}),
        'GET /payments/p2': (_) => ok(_payment('p2', ++polls2 == 1 ? 'pending' : 'paid')),
      }),
    );
    expect(find.text(_en.paymentFailed), findsOneWidget);
    expect(find.text('Card declined'), findsOneWidget);
    expect(find.text(_en.retryPayment), findsOneWidget);

    await t.tap(find.text(_en.retryPayment));
    await _settle(t, 6);
    expect(h.adapter.where('POST', '/orders/o1/payments'), hasLength(1));
    expect(
      find.text(_en.paymentFailed),
      findsNothing,
      reason: 'the new payment is being checked, the old answer is gone',
    );
    expect(find.text('Card declined'), findsNothing);
    expect(find.text(_en.retryPayment), findsNothing);
    expect(find.text(_en.paymentPending), findsOneWidget);

    // The next check (3 s later) finds it paid.
    await t.pump(const Duration(seconds: 4));
    await _settle(t, 4);
    expect(find.text(_en.paymentPaid), findsOneWidget);
    expect(find.text(_en.paymentPending), findsNothing);
  });

  testWidgets('back while the payment is being checked is held and says why; once it is settled back works', (t) async {
    var polls = 0;
    await _open(t, routes({'GET /payments/p1': (_) => ok(_payment('p1', ++polls == 1 ? 'pending' : 'paid'))}));
    expect(find.text(_en.paymentPending), findsOneWidget);
    final context = t.element(find.byType(PaymentStatusScreen));

    // Android back while the payment is checked.
    expect(await Navigator.of(context).maybePop(), isTrue);
    await _settle(t, 4);
    expect(find.byType(PaymentStatusScreen), findsOneWidget);
    expect(find.text(_en.paymentWaitBack), findsOneWidget);

    // Paid: the page is free to leave.
    await t.pump(const Duration(seconds: 4));
    await _settle(t, 4);
    expect(find.text(_en.paymentPaid), findsOneWidget);
    await Navigator.of(context).maybePop();
    await _settle(t, 20);
    expect(find.byType(PaymentStatusScreen), findsNothing);
  });
}
