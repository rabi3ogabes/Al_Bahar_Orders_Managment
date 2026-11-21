@Timeout(Duration(minutes: 5))
library;

// "Notify me when it is back" and "Tell me if the price drops": what the product page offers, the subscribed state, "My alerts" (list, empty, error,
// remove), a signed-out visitor who is asked to sign in with the choice kept, and Arabic at text scale 1.3.
import 'dart:async';

import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/features/alerts/domain/alert_models.dart';
import 'package:albahar_customer/features/alerts/presentation/alerts_screen.dart';
import 'package:albahar_customer/features/alerts/presentation/state/alerts_controller.dart';
import 'package:albahar_customer/features/auth/domain/auth_models.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show ar, backend, boot, en, go, settle, teardown;
import '../../qa/test_fonts.dart';

Map<String, dynamic> _alertJson(
  String id, {
  String type = 'back_in_stock',
  bool inStock = false,
  String? was,
  String? now,
  String name = 'Oslo Sofa',
}) => {
  'id': id,
  'type': type,
  'product': {'id': 'p1', 'slug': 'oslo-sofa', 'name': name, 'image_url': null, 'thumb_url': null},
  'variant': {'id': 'v-$id', 'name': 'Grey'},
  'branch_id': null,
  'reference_price': was == null ? null : money(was),
  'current_price': now == null ? null : money(now),
  'in_stock': inStock,
  'since': '2026-11-19T12:00:00+03:00',
  'expires_at': '2027-02-17T12:00:00+03:00',
};

/// The alerts of a pretend server: what is asked, what is removed, what it answers.
class FakeAlertsServer {
  FakeAlertsServer({List<Map<String, dynamic>>? alerts, this.priceDrop = true}) : alerts = [...?alerts];

  final List<Map<String, dynamic>> alerts;
  bool priceDrop;
  bool failList = false;
  final asked = <Map<String, dynamic>>[];
  final removed = <String>[];

  FutureOr<FakeReply?> handle(RecordedRequest r) {
    if (r.method == 'GET' && r.path == '/stock-alerts/config') {
      return ok({
        'back_in_stock': true,
        'price_drop': priceDrop,
        'price_drop_percent': 5,
        'price_drop_min_amount': '5.00',
      });
    }
    if (r.method == 'GET' && r.path == '/me/stock-alerts') {
      return failList ? const FakeReply(500, {'message': 'Boom', 'code': 'server_error'}) : ok(alerts);
    }
    if (r.method == 'POST' && r.path == '/me/stock-alerts') {
      final body = r.body as Map;
      asked.add({...body});
      final made = _alertJson(
        'a${asked.length}',
        type: body['type'] as String,
        inStock: body['type'] == 'price_drop',
        was: body['type'] == 'price_drop' ? '100.00' : null,
        now: body['type'] == 'price_drop' ? '100.00' : null,
      )..['variant'] = {'id': body['variant_id'], 'name': 'Oak'};
      alerts.insert(0, made);
      return ok({'subscribed': true, 'alert': made}, status: 201);
    }
    if (r.method == 'DELETE' && r.path.startsWith('/me/stock-alerts/')) {
      final id = r.path.split('/').last;
      removed.add(id);
      alerts.removeWhere((a) => a['id'] == id);
      return ok({'subscribed': false});
    }
    return null;
  }
}

Map<String, dynamic> _detail({required bool inStock}) {
  final d = productDetail('p1');
  (d['variants'] as List<dynamic>).first['in_stock'] = inStock;
  d['in_stock'] = inStock;
  d['availability'] = {'branch_id': null, 'purchasable': inStock, 'reason': inStock ? null : 'out_of_stock'};
  return d;
}

FakeHandler _shop(FakeAlertsServer alerts, {required bool inStock}) => backend(
  extra: (r) {
    if (r.method == 'GET' && r.path == '/products/p1') return ok(_detail(inStock: inStock));
    return alerts.handle(r);
  },
);

void main() {
  setUpAll(loadAppFonts);

  group('what the product page offers', () {
    const on = AlertsConfig(priceDrop: true);
    StockAlert a(String variant, AlertType type) => StockAlert(
      id: '$type$variant',
      type: type,
      productId: 'p',
      productName: 'P',
      variantId: variant,
      inStock: true,
    );

    test('the bell is for what cannot be bought and shows the subscribed state per variant', () {
      ProductAlertControls c({
        bool canBuy = false,
        AlertsConfig cfg = on,
        List<StockAlert> list = const [],
        String? v = 'v1',
      }) => productAlertControls(canBuy: canBuy, config: cfg, alerts: list, variantId: v);
      expect(c().bell, BellState.notify);
      expect(c(list: [a('v1', AlertType.backInStock)]).bell, BellState.subscribed);
      expect(
        c(list: [a('v1', AlertType.backInStock)], v: 'v2').bell,
        BellState.notify,
        reason: 'another variant is its own alert',
      );
      expect(c(canBuy: true).bell, BellState.hidden);
      expect(c(v: null).isEmpty, isTrue, reason: 'no variant chosen yet');
      expect(c(cfg: const AlertsConfig(backInStock: false)).bell, BellState.hidden);
    });

    test(
      'the price choice is for what can be bought, only while the owner has it on, but a watcher can always stop',
      () {
        ProductAlertControls c({bool canBuy = true, AlertsConfig cfg = on, List<StockAlert> list = const []}) =>
            productAlertControls(canBuy: canBuy, config: cfg, alerts: list, variantId: 'v1');
        expect(c().price, PriceAlertState.offer);
        expect(
          c(cfg: AlertsConfig.fallback).price,
          PriceAlertState.hidden,
          reason: 'price alerts are off until the owner switches them on',
        );
        expect(c(canBuy: false).price, PriceAlertState.hidden, reason: 'nobody can use a price they cannot buy at');
        final watching = [a('v1', AlertType.priceDrop)];
        expect(c(list: watching).price, PriceAlertState.subscribed);
        expect(c(list: watching, cfg: AlertsConfig.fallback).price, PriceAlertState.subscribed);
        expect(c(list: watching, canBuy: false).price, PriceAlertState.subscribed);
      },
    );

    test('an alert reads its server fields, in Arabic too, and says what it waits for', () {
      final s = StockAlert.fromJson(
        _alertJson('a1', type: 'price_drop', inStock: true, was: '200.00', now: '150.00', name: 'كنبة أوسلو'),
      );
      expect(s.type, AlertType.priceDrop);
      expect(s.productName, 'كنبة أوسلو');
      expect(s.variantName, 'Grey');
      expect(s.status, AlertStatus.watching);
      expect(s.droppedPercent, 25);
      expect(
        StockAlert.fromJson(_alertJson('a2', type: 'price_drop', inStock: true, was: '100.00', now: '120.00'))
            .droppedPercent,
        0,
        reason: 'a rise is not a drop',
      );
      expect(StockAlert.fromJson(_alertJson('a3')).droppedPercent, isNull);
      expect(StockAlert.fromJson(_alertJson('a3')).status, AlertStatus.waitingStock);
      expect(StockAlert.fromJson(_alertJson('a4', inStock: true)).status, AlertStatus.availableNow);
      expect(StockAlert.fromJson(_alertJson('a5', type: 'price_drop')).status, AlertStatus.soldOut);
      expect(AlertType.parse('nope'), isNull);
    });

    test('what a signed-out visitor asked for is forgotten after ten minutes', () {
      final now = DateTime(2026, 11, 19, 12);
      expect(AlertIntent('v1', AlertType.priceDrop, at: now).isStale(now.add(const Duration(minutes: 9))), isFalse);
      expect(AlertIntent('v1', AlertType.priceDrop, at: now).isStale(now.add(const Duration(minutes: 11))), isTrue);
    });
  });

  group('the product page', () {
    testWidgets('an out-of-stock product: Notify me asks for the alert, then shows it is on, and a tap cancels it', (
      t,
    ) async {
      final server = FakeAlertsServer();
      final h = await boot(t, _shop(server, inStock: false));
      unawaited(h.read(routerProvider).push('/products/p1'));
      await settle(t);

      expect(find.text(en.notifyBackInStock), findsOneWidget);
      await t.tap(find.text(en.notifyBackInStock));
      await settle(t);
      expect(server.asked, hasLength(1));
      expect(server.asked.single['variant_id'], 'v1');
      expect(server.asked.single['type'], 'back_in_stock');
      expect(find.text(en.alertNotifyOn), findsOneWidget, reason: 'the button now shows the subscribed state');
      expect(find.text(en.alertCreated), findsOneWidget, reason: 'and says so');

      await t.tap(find.text(en.alertNotifyOn));
      await settle(t);
      expect(server.removed, ['a1']);
      expect(find.text(en.notifyBackInStock), findsOneWidget);
      expect(find.text(en.alertRemoved), findsOneWidget);
      await teardown(t);
    });

    testWidgets(
      'an in-stock product: "Tell me if the price drops" is offered only when the owner has price alerts on',
      (t) async {
        final off = FakeAlertsServer(priceDrop: false);
        var h = await boot(t, _shop(off, inStock: true));
        unawaited(h.read(routerProvider).push('/products/p1'));
        await settle(t);
        expect(find.text(en.alertPriceToggle), findsNothing);
        await teardown(t);

        final server = FakeAlertsServer();
        h = await boot(t, _shop(server, inStock: true));
        unawaited(h.read(routerProvider).push('/products/p1'));
        await settle(t);
        await t.ensureVisible(find.text(en.alertPriceToggle));
        await t.pump(); // the page scrolls on the next frame; the tap must read the new place
        await t.tap(find.text(en.alertPriceToggle));
        await settle(t);
        expect(server.asked.single['type'], 'price_drop');
        expect(find.text(en.alertPriceOn), findsOneWidget);
        expect(find.text(en.alertPriceCreated), findsOneWidget);
        expect(find.text(en.alertPriceNeedsConsent), findsNothing, reason: 'this customer agreed to offers');
        await teardown(t);
      },
    );

    testWidgets('a signed-out visitor is taken to sign in, and the alert is made for them when they are back', (
      t,
    ) async {
      final server = FakeAlertsServer();
      final h = await boot(t, _shop(server, inStock: false), signedIn: false);
      unawaited(h.read(routerProvider).push('/products/p1'));
      await settle(t);

      await t.tap(find.text(en.notifyBackInStock));
      await settle(t);
      expect(currentAppLocation(h.read(routerProvider)), startsWith('/login'));
      expect(server.asked, isEmpty, reason: 'nothing is asked while they are signed out');
      expect(h.read(pendingAlertIntentProvider)?.variantId, 'v1');

      await h
          .read(sessionControllerProvider.notifier)
          .signIn(
            Authenticated(
              user: const AppUser(id: 'u1', name: 'Noor', phone: '+97455123456', locale: 'en'),
              tokens: signedInTokens,
            ),
          );
      leaveSignIn(rootNavigatorKey.currentContext!, '/products/p1');
      await settle(t, 12);
      expect(currentAppLocation(h.read(routerProvider)), '/products/p1');
      expect(server.asked, hasLength(1), reason: 'the choice they made was kept');
      expect(server.asked.single['variant_id'], 'v1');
      expect(h.read(pendingAlertIntentProvider), isNull);
      expect(find.text(en.alertNotifyOn), findsOneWidget);
      await teardown(t);
    });
  });

  group('My alerts', () {
    Future<void> open(WidgetTester t, dynamic h) async {
      await go(t, h, '/account');
      await t.tap(find.text(en.alertsTitle).first);
      await settle(t);
    }

    testWidgets('lists every alert with what it waits for, and removes one', (t) async {
      final server = FakeAlertsServer(
        alerts: [
          _alertJson('a1', name: 'Oslo Sofa'),
          _alertJson('a2', type: 'price_drop', inStock: true, was: '200.00', now: '150.00', name: 'Linen Cushion'),
          _alertJson('a3', inStock: true, name: 'Oak Table'),
        ],
      );
      final h = await boot(t, _shop(server, inStock: true));
      await open(t, h);

      expect(find.text('Oslo Sofa'), findsOneWidget);
      expect(find.text(en.alertWaitingStock), findsOneWidget);
      expect(find.text(en.alertAvailableNow), findsWidgets);
      expect(find.textContaining('Now QAR 150.00', findRichText: true), findsOneWidget);
      expect(find.textContaining('Was QAR 200.00', findRichText: true), findsOneWidget);
      expect(find.textContaining('25% lower', findRichText: true), findsOneWidget);
      expect(find.textContaining('Since', findRichText: true), findsWidgets);

      await t.tap(find.byTooltip(en.alertRemove).first);
      await settle(t);
      expect(server.removed, ['a1']);
      expect(find.text('Oslo Sofa'), findsNothing);
      expect(find.text('Linen Cushion'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('has an empty state with a way on, and an error state with a retry', (t) async {
      final server = FakeAlertsServer();
      final h = await boot(t, _shop(server, inStock: true));
      await open(t, h);
      expect(find.text(en.alertsEmptyTitle), findsOneWidget);
      expect(find.text(en.alertsBrowse), findsOneWidget);
      await teardown(t);

      final broken = FakeAlertsServer()..failList = true;
      final h2 = await boot(t, _shop(broken, inStock: true));
      await open(t, h2);
      expect(find.text(en.retry), findsOneWidget);
      broken
        ..failList = false
        ..alerts.add(_alertJson('a1'));
      await t.tap(find.text(en.retry));
      await settle(t);
      expect(find.text('Oslo Sofa'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a removal the server refuses puts the alert back and says why', (t) async {
      final server = FakeAlertsServer(alerts: [_alertJson('a1')]);
      final base = _shop(server, inStock: true);
      final h = await boot(
        t,
        (r) => r.method == 'DELETE' ? const FakeReply(500, {'message': 'Boom', 'code': 'server_error'}) : base(r),
      );
      await open(t, h);
      await t.tap(find.byTooltip(en.alertRemove).first);
      await settle(t);
      expect(find.text('Oslo Sofa'), findsOneWidget, reason: 'it came back');
      await teardown(t);
    });

    testWidgets('reads right to left in Arabic at text size 1.3 without a layout error', (t) async {
      final server = FakeAlertsServer(
        alerts: [
          _alertJson('a1', name: 'كنبة أوسلو ثلاثية المقاعد من الكتان الفاخر باللون الرمادي'),
          _alertJson('a2', type: 'price_drop', inStock: true, was: '1250.00', now: '999.00', name: 'سجادة جوت منسوجة'),
        ],
      );
      t.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
      final h = await boot(t, _shop(server, inStock: true), width: 360, locale: 'ar');
      await go(t, h, '/account');
      await t.tap(find.text(ar.alertsTitle).first);
      await settle(t);
      expect(find.text('سجادة جوت منسوجة'), findsOneWidget);
      expect(find.text(ar.alertWaitingStock), findsOneWidget);
      expect(find.byType(AlertCard), findsNWidgets(2));
      expect(Directionality.of(t.element(find.byType(AlertCard).first)), TextDirection.rtl);
      expect(t.takeException(), isNull);
      await teardown(t);
    });
  });
}
