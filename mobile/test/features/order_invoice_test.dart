@Timeout(Duration(minutes: 5))
library;

import 'dart:async';
import 'dart:typed_data';

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/features/orders/data/invoice_delivery.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import '../qa/customer_fixtures.dart';
import '../qa/test_fonts.dart';

AppLocalizations get en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get ar => lookupAppLocalizations(const Locale('ar'));

final _pdf = Uint8List.fromList([0x25, 0x50, 0x44, 0x46, 0x2d, 0x31, 0x2e, 0x34]); // %PDF-1.4

/// What the customer's phone was asked to do with the invoice.
class _Delivery {
  final files = <({Uint8List bytes, String name})>[];
  Completer<InvoiceDelivered>? hold;
  Object? failWith;
  InvoiceDelivered result = InvoiceDelivered.shared;

  Future<InvoiceDelivered> call(Uint8List bytes, String name) async {
    files.add((bytes: bytes, name: name));
    final pending = hold;
    if (pending != null) return pending.future;
    if (failWith != null) throw failWith!;
    return result;
  }
}

class _Shop {
  _Shop({this.status = 'processing', this.canReturn = true});
  String status;
  bool canReturn;
  FakeReply Function()? invoice;
  FakeReply Function()? returnReply;
  Future<FakeReply> Function()? invoiceLater;

  FakeHandler get handler => (r) async {
    if (r.method == 'GET' && r.path == '/orders/o1') {
      return ok({...orderDetail('o1', status: status), 'can_return': canReturn});
    }
    if (r.method == 'GET' && r.path == '/orders/o1/invoice') {
      if (invoiceLater != null) return invoiceLater!();
      return invoice?.call() ?? FakeReply(200, _pdf);
    }
    if (r.method == 'POST' && r.path == '/orders/o1/returns') return returnReply?.call() ?? ok({'id': 'r1'});
    if (r.method == 'GET' && r.path == '/orders/o1/timeline') return ok(const []);
    return customerBackend(ApiMode.data)(r);
  };
}

Future<TestHarness> _open(WidgetTester t, _Shop shop, _Delivery delivery, {String locale = 'en'}) async {
  t.view.physicalSize = const Size(390, 1400);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(
    tokens: signedInTokens,
    prefs: {'locale': locale, 'onboarding_done': true},
    handler: shop.handler,
    overrides: [invoiceDeliveryProvider.overrideWithValue(delivery.call)],
  );
  await t.pumpWidget(
    UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
  );
  await t.pump(const Duration(milliseconds: 1700));
  h.read(routerProvider).go('/orders/o1');
  await settle(t);
  return h;
}

Future<void> settle(WidgetTester t, [int frames = 8]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 150));
  }
}

Future<void> teardown(WidgetTester t) async {
  await t.pumpWidget(const SizedBox.shrink());
  await t.pump(const Duration(seconds: 10));
}

Finder invoiceButton(String label) => find.widgetWithText(AppButton, label);

Future<void> tapInvoice(WidgetTester t, [AppLocalizations? l10n]) async {
  final target = invoiceButton((l10n ?? en).downloadInvoice);
  await t.ensureVisible(target);
  await t.pump();
  await t.tap(target);
  await settle(t);
}

void main() {
  setUpAll(loadAppFonts);

  group('the invoice button', () {
    test('an order has an invoice once it is placed and paid for, and not while it waits for payment or after it was cancelled', () {
      Order order(String status) => Order.fromJson(orderDetail('o1', status: status));
      for (final s in [
        'confirmed',
        'processing',
        'partially_fulfilled',
        'ready_for_pickup',
        'out_for_delivery',
        'completed',
        'partially_cancelled',
        'cancellation_requested',
        'returned',
      ]) {
        expect(order(s).hasInvoice, isTrue, reason: s);
      }
      expect(order('pending_payment').hasInvoice, isFalse);
      expect(order('cancelled').hasInvoice, isFalse);
    });

    for (final status in ['processing', 'completed', 'out_for_delivery', 'ready_for_pickup']) {
      testWidgets('is on the order page for a $status order, named "Download invoice (PDF)"', (t) async {
        await _open(t, _Shop(status: status), _Delivery());
        expect(invoiceButton('Download invoice (PDF)'), findsOneWidget);
        await teardown(t);
      });
    }

    for (final status in ['pending_payment', 'cancelled']) {
      testWidgets('is hidden for a $status order', (t) async {
        await _open(t, _Shop(status: status), _Delivery());
        expect(invoiceButton(en.downloadInvoice), findsNothing);
        await teardown(t);
      });
    }

    testWidgets('has an Arabic label too', (t) async {
      await _open(t, _Shop(), _Delivery(), locale: 'ar');
      expect(invoiceButton(ar.downloadInvoice), findsOneWidget);
      expect(ar.downloadInvoice, contains('PDF'));
      await teardown(t);
    });
  });

  group('downloading', () {
    testWidgets('fetches the PDF once and hands it over as AlBahar-<order number>.pdf', (t) async {
      final delivery = _Delivery();
      final h = await _open(t, _Shop(), delivery);

      await tapInvoice(t);

      final request = h.adapter.where('GET', '/orders/o1/invoice').single;
      expect(request.header('Accept'), 'application/pdf');
      expect(delivery.files, hasLength(1));
      expect(delivery.files.single.name, 'AlBahar-AB26o1.pdf');
      expect(delivery.files.single.bytes, _pdf);
      expect(find.text(en.invoiceSaved), findsNothing, reason: 'a phone shows its own sheet; no extra message');
      expect(invoiceButton(en.downloadInvoice), findsOneWidget);
      await teardown(t);
    });

    testWidgets('in a browser the page confirms that the file was downloaded', (t) async {
      final delivery = _Delivery()..result = InvoiceDelivered.downloaded;
      await _open(t, _Shop(), delivery);
      await tapInvoice(t);
      expect(find.text(en.invoiceSaved), findsOneWidget);
      await teardown(t);
    });

    testWidgets('shows progress on the button, and a second tap while it works starts nothing', (t) async {
      final gate = Completer<FakeReply>();
      final shop = _Shop()..invoiceLater = () => gate.future;
      final delivery = _Delivery();
      final h = await _open(t, shop, delivery);

      final target = invoiceButton(en.downloadInvoice);
      await t.ensureVisible(target);
      await t.pump();
      await t.tap(target);
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));

      AppButton invoiceAppButton() =>
          t.widgetList<AppButton>(find.byType(AppButton)).singleWhere((b) => b.label == en.downloadInvoice);
      expect(invoiceAppButton().loading, isTrue, reason: 'the button shows its progress');
      expect(find.byType(CircularProgressIndicator), findsOneWidget);

      // Tap again (twice), as an impatient customer would.
      await t.tap(find.byType(CircularProgressIndicator), warnIfMissed: false);
      await t.tap(find.byType(CircularProgressIndicator), warnIfMissed: false);
      await t.pump(const Duration(milliseconds: 100));
      expect(h.adapter.where('GET', '/orders/o1/invoice'), hasLength(1));

      gate.complete(FakeReply(200, _pdf));
      await settle(t);

      expect(h.adapter.where('GET', '/orders/o1/invoice'), hasLength(1), reason: 'one download for all those taps');
      expect(delivery.files, hasLength(1));
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(invoiceButton(en.downloadInvoice), findsOneWidget);
      await teardown(t);
    });

    testWidgets('two taps in the same frame still download once', (t) async {
      final gate = Completer<FakeReply>();
      final shop = _Shop()..invoiceLater = () => gate.future;
      final h = await _open(t, shop, _Delivery());
      final target = invoiceButton(en.downloadInvoice);
      await t.ensureVisible(target);
      await t.pump();
      final button = t.widget<AppButton>(target);
      button.onPressed!();
      button.onPressed!();
      await t.pump(const Duration(milliseconds: 300));
      expect(h.adapter.where('GET', '/orders/o1/invoice'), hasLength(1));
      gate.complete(FakeReply(200, _pdf));
      await settle(t);
      await teardown(t);
    });
  });

  group('when it does not work', () {
    Future<void> expectMessage(WidgetTester t, _Shop shop, String message, {_Delivery? delivery}) async {
      final h = await _open(t, shop, delivery ?? _Delivery());
      await tapInvoice(t);
      expect(find.text(message), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing, reason: 'the button is usable again');
      expect(invoiceButton(en.downloadInvoice), findsOneWidget);
      expect(t.takeException(), isNull);
      expect(h.adapter.where('GET', '/orders/o1/invoice'), hasLength(1));
      await teardown(t);
    }

    testWidgets('an order that is not paid yet (409) says the invoice is ready after payment', (t) async {
      final shop = _Shop()..invoice = () => error(409, 'invoice_unavailable', message: 'Pending payment.');
      await expectMessage(t, shop, en.invoiceNotReady);
    });

    testWidgets('a server error says to try again', (t) async {
      final shop = _Shop()..invoice = () => error(500, 'server_error');
      await expectMessage(t, shop, en.invoiceFailed);
    });

    testWidgets('an order that is gone says to try again too, never a raw message', (t) async {
      final shop = _Shop()..invoice = () => error(404, 'not_found', message: 'SQLSTATE[HY000] leaked detail');
      await expectMessage(t, shop, en.invoiceFailed);
    });

    testWidgets('being offline says so', (t) async {
      final shop = _Shop();
      final h = await _open(t, shop, _Delivery());
      h.adapter.failConnection = (r) => r.path == '/orders/o1/invoice';
      await tapInvoice(t);
      expect(find.text(en.errorOffline), findsOneWidget);
      expect(invoiceButton(en.downloadInvoice), findsOneWidget);
      h.adapter.failConnection = null;
      await tapInvoice(t);
      expect(h.adapter.where('GET', '/orders/o1/invoice'), hasLength(2), reason: 'try again works');
      await teardown(t);
    });

    testWidgets('a phone that cannot share the file says to try again', (t) async {
      final delivery = _Delivery()..failWith = StateError('no share sheet');
      await expectMessage(t, _Shop(), en.invoiceFailed, delivery: delivery);
    });

    testWidgets('the messages are in Arabic for an Arabic customer', (t) async {
      final shop = _Shop()..invoice = () => error(409, 'invoice_unavailable');
      await _open(t, shop, _Delivery(), locale: 'ar');
      await tapInvoice(t, ar);
      expect(find.text(ar.invoiceNotReady), findsOneWidget);
      await teardown(t);
    });
  });

  group('the repository', () {
    test('reads the server message of a failed download, which arrives as bytes', () async {
      final h = await TestHarness.create(
        handler: (_) => error(409, 'invoice_unavailable', message: 'Invoice available after payment.'),
      );
      await expectLater(
        h.read(orderRepositoryProvider).invoice('o1'),
        throwsA(
          isA<ApiException>()
              .having((e) => e.kind, 'kind', ApiErrorKind.conflict)
              .having((e) => e.code, 'code', 'invoice_unavailable')
              .having((e) => e.message, 'message', 'Invoice available after payment.')
              .having((e) => e.requestId, 'request id', 'req-1'),
        ),
      );
    });

    test('a failed download that is not JSON is still a clean error', () async {
      final h = await TestHarness.create(
        handler: (_) => FakeReply(502, Uint8List.fromList('<html>Bad gateway</html>'.codeUnits)),
      );
      await expectLater(
        h.read(orderRepositoryProvider).invoice('o1'),
        throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.server)),
      );
    });

    test('file names are safe whatever the order number holds', () {
      expect(invoiceFileName('AB26o1'), 'AlBahar-AB26o1.pdf');
      expect(invoiceFileName('AB-2026/0001'), 'AlBahar-AB-2026-0001.pdf');
      expect(invoiceFileName(r'..\x y'), 'AlBahar-..-x-y.pdf');
    });
  });

  group('returns obey the server', () {
    testWidgets('the Return button is there only when the server says the order can be returned', (t) async {
      await _open(t, _Shop(canReturn: true), _Delivery());
      expect(find.widgetWithText(AppButton, en.requestReturn), findsOneWidget);
      await teardown(t);

      await _open(t, _Shop(canReturn: false), _Delivery());
      expect(find.widgetWithText(AppButton, en.requestReturn), findsNothing);
      expect(invoiceButton(en.downloadInvoice), findsOneWidget, reason: 'the other buttons are untouched');
      await teardown(t);
    });

    testWidgets('a refused return (403) says so, reads the order again and the button goes away', (t) async {
      final shop = _Shop(canReturn: true);
      shop.returnReply = () {
        shop.canReturn = false; // the shop turned returns off while the page was open
        return error(403, 'returns_disabled', message: 'Returns are disabled.');
      };
      final h = await _open(t, shop, _Delivery());
      final reads = h.adapter.where('GET', '/orders/o1').length;

      final button = find.widgetWithText(AppButton, en.requestReturn);
      await t.ensureVisible(button);
      await t.pump();
      await t.tap(button);
      await settle(t);
      await t.tap(find.byType(CheckboxListTile).first);
      await settle(t);
      await t.tap(find.widgetWithText(AppButton, en.requestReturn).last);
      await settle(t);

      expect(h.adapter.where('POST', '/orders/o1/returns'), hasLength(1));
      expect(find.text(en.returnsUnavailable), findsOneWidget);
      expect(find.text(en.errorForbidden), findsNothing);
      expect(h.adapter.where('GET', '/orders/o1').length, greaterThan(reads), reason: 'the order is read again');
      expect(find.widgetWithText(AppButton, en.requestReturn), findsNothing, reason: 'and the button is gone');
      expect(t.takeException(), isNull);
      await teardown(t);
    });

    testWidgets('a return that works still says it was requested', (t) async {
      final h = await _open(t, _Shop(), _Delivery());
      final button = find.widgetWithText(AppButton, en.requestReturn);
      await t.ensureVisible(button);
      await t.pump();
      await t.tap(button);
      await settle(t);
      await t.tap(find.byType(CheckboxListTile).first);
      await settle(t);
      await t.tap(find.widgetWithText(AppButton, en.requestReturn).last);
      await settle(t);
      expect(h.adapter.where('POST', '/orders/o1/returns'), hasLength(1));
      expect(find.text(en.returnRequested), findsOneWidget);
      await teardown(t);
    });
  });
}
