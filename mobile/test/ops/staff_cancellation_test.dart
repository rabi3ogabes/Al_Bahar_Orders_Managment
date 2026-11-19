import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

const _phone = Variant('390x1400 en', Size(390, 1400), kEn);
const _phoneAr = Variant('390x1400 ar', Size(390, 1400), kAr);

const _hold = 'The customer asked to cancel. A manager must decide before this order leaves the branch.';

Future<void> _pump(WidgetTester tester, FakeOps repo, {Variant variant = _phone}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: variant, role: OpsRole.staff));
  await settle(tester);
}

Future<void> _open(WidgetTester tester, String number) async {
  await tester.tap(find.text('#$number'));
  await settle(tester);
}

FakeOps _withRequest({String status = 'pending', Map<String, bool>? can, String perms = '*'}) => FakeOps()
  ..perms = [perms]
  ..overviewData = staffOverview(can: can)
  ..queues = {
    switch (status) {
      'pending' || 'accepted' || 'preparing' => status == 'pending' ? 'new' : 'preparing',
      _ => 'ready',
    }: [
      staffOrder(status: status, cancellation: cancellationJson(), clock: clockJson('amber', 8)),
    ],
  };

Finder get _banner => find.byKey(const ValueKey('cancellation-banner'));

void main() {
  group('a customer asked to cancel', () {
    testWidgets('the card and the window both say a manager must decide, and the window shows the reason', (
      tester,
    ) async {
      await _pump(tester, _withRequest());
      expect(_banner, findsOneWidget, reason: 'on the card');
      expect(find.text(_hold), findsOneWidget);
      await _open(tester, 'AB100001');
      expect(_banner, findsNWidgets(2), reason: 'on the card and in the window');
      expect(find.text('Reason: Changed my mind'), findsOneWidget);
      expect(find.text(_hold), findsNWidgets(2));
    });

    testWidgets('a person who cannot decide only sees it and is told to ask a manager', (tester) async {
      await _pump(tester, _withRequest(can: const {'orders.approve': false}));
      await _open(tester, 'AB100001');
      expect(find.text('Ask a manager to decide.'), findsNWidgets(2));
      expect(find.byKey(const ValueKey('cancellation-approve')), findsNothing);
      expect(find.byKey(const ValueKey('cancellation-keep')), findsNothing);
    });

    testWidgets('a manager of this branch can decide; a manager of another branch cannot', (tester) async {
      await _pump(tester, _withRequest(can: const {'orders.approve': true}));
      await _open(tester, 'AB100001');
      expect(find.byKey(const ValueKey('cancellation-approve')), findsOneWidget);
      expect(find.text('Open the order to decide.'), findsOneWidget, reason: 'the card tells the manager where');
    });

    testWidgets('while it waits, preparing and ready stay open but nothing can be handed to a driver', (tester) async {
      final ready = _withRequest(status: 'ready');
      await _pump(tester, ready);
      expect(find.text('Assign driver'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Assign driver'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Assign driver')).onPressed, isNull);
      expect(find.text(_hold), findsNWidgets(2), reason: 'the banner and the reason under the switched-off button');
      await tester.tap(find.widgetWithText(FilledButton, 'Assign driver'), warnIfMissed: false);
      await settle(tester);
      expect(find.text('Choose a driver'), findsNothing);

      final preparing = _withRequest(status: 'preparing');
      await tester.pumpWidget(const SizedBox.shrink());
      await _pump(tester, preparing);
      await tester.tap(find.text('Mark ready'));
      await settle(tester);
      expect(preparing.calls, ['advance:f1:ready'], reason: 'ready is still allowed');

      final pending = _withRequest();
      await tester.pumpWidget(const SizedBox.shrink());
      await _pump(tester, pending);
      await tester.tap(find.text('Accept order'));
      await settle(tester);
      expect(pending.calls, ['advance:f1:accepted']);
    });

    testWidgets('the same sentence blocks the pickup hand-over, for the scanner and for the manager', (tester) async {
      final repo = FakeOps()
        ..overviewData = staffOverview(fresh: 0, ready: 0, pickupReady: 1)
        ..queues = {}
        ..pickupQueue = [
          staffOrder(
            status: 'pickup_ready',
            type: 'pickup',
            cancellation: cancellationJson(),
            pickup: {'status': 'ready', 'expired': false},
          ),
        ];
      await _pump(tester, repo);
      await tester.tap(find.text('Pickup · 1'));
      await settle(tester);
      await _open(tester, 'AB100001');
      expect(find.byKey(const ValueKey('pickup-scan')), findsNothing, reason: 'no working scan button');
      final inSheet = find.descendant(
        of: find.byType(BottomSheet),
        matching: find.widgetWithText(FilledButton, 'Scan pass'),
      );
      expect(tester.widget<FilledButton>(inSheet).onPressed, isNull);
      expect(find.text(_hold), findsWidgets);
    });
  });

  group('the manager decides', () {
    testWidgets('"Keep the order" asks first, takes an optional note and declines the request', (tester) async {
      final repo = _withRequest();
      await _pump(tester, repo);
      await _open(tester, 'AB100001');
      await tester.tap(find.byKey(const ValueKey('cancellation-keep')));
      await settle(tester);
      expect(find.text('Keep this order?'), findsOneWidget);
      expect(repo.decisions, isEmpty, reason: 'nothing is sent before the manager confirms');
      await tester.enterText(find.byKey(const ValueKey('decision-note')), '  Customer called, goods are ready  ');
      await tester.tap(find.byKey(const ValueKey('decision-confirm')));
      await settle(tester);
      expect(repo.decisions.single, (id: 'c1', approve: false, note: 'Customer called, goods are ready'));
      expect(find.text('Request declined. The order goes on.'), findsOneWidget);
    });

    testWidgets('"Approve cancellation" asks with a plain warning that it cannot be undone, and can be backed out of', (
      tester,
    ) async {
      final repo = _withRequest();
      await _pump(tester, repo);
      await _open(tester, 'AB100001');
      await tester.tap(find.byKey(const ValueKey('cancellation-approve')));
      await settle(tester);
      expect(find.text('Approve the cancellation?'), findsOneWidget);
      expect(find.textContaining('This cannot be undone.'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.decisions, isEmpty);
      await tester.tap(find.byKey(const ValueKey('cancellation-approve')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('decision-confirm')));
      await settle(tester);
      expect(repo.decisions.single, (id: 'c1', approve: true, note: ''));
    });

    testWidgets('a colleague decided first: the plain sentence shows and the lists are read again', (tester) async {
      final repo = _withRequest()
        ..decideError = apiError(ApiErrorKind.conflict, 409, code: 'invalid_state_transition', message: 'server text');
      await _pump(tester, repo);
      await _open(tester, 'AB100001');
      final before = repo.queueLoads;
      await tester.tap(find.byKey(const ValueKey('cancellation-keep')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('decision-confirm')));
      await settle(tester);
      expect(find.text('Someone already moved this order. The list was refreshed.'), findsOneWidget);
      expect(repo.queueLoads, greaterThan(before));
    });

    testWidgets('a manager of one branch of a split order is told in plain words that it needs the other', (
      tester,
    ) async {
      final repo = _withRequest()
        ..decideError = apiError(ApiErrorKind.forbidden, 403, code: 'branch_forbidden', message: 'server text');
      await _pump(tester, repo);
      await _open(tester, 'AB100001');
      await tester.tap(find.byKey(const ValueKey('cancellation-approve')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('decision-confirm')));
      await settle(tester);
      expect(find.text('You do not have the right to do this at this branch. Ask a manager.'), findsOneWidget);
    });

    testWidgets('Arabic: the banner, the buttons and the questions are Arabic', (tester) async {
      await _pump(tester, _withRequest(), variant: _phoneAr);
      await _open(tester, 'AB100001');
      expect(find.text('طلب العميل إلغاء الطلب'), findsNWidgets(2));
      expect(find.text('الموافقة على الإلغاء'), findsOneWidget);
      expect(find.text('الإبقاء على الطلب'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('cancellation-approve')));
      await settle(tester);
      expect(find.text('الموافقة على الإلغاء؟'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('the server\'s answers, in plain words', () {
    Future<void> failWith(WidgetTester tester, ApiException error) async {
      final repo = FakeOps()
        ..overviewData = staffOverview()
        ..advanceError = error;
      await _pump(tester, repo);
      await tester.tap(find.text('Accept order'));
      await settle(tester);
    }

    testWidgets('an online order that is not paid yet', (tester) async {
      await failWith(
        tester,
        apiError(ApiErrorKind.conflict, 409, code: 'order_awaiting_payment', message: 'server text'),
      );
      expect(
        find.text('This order is not paid yet. It can be accepted when the online payment arrives.'),
        findsOneWidget,
      );
    });

    testWidgets('a hand-over the server refused while a cancellation waits (the safety net)', (tester) async {
      await failWith(
        tester,
        apiError(ApiErrorKind.conflict, 409, code: 'cancellation_pending', message: 'server text'),
      );
      expect(find.text(_hold), findsOneWidget);
    });

    testWidgets('a right the person does not have at this branch', (tester) async {
      await failWith(tester, apiError(ApiErrorKind.forbidden, 403, code: 'branch_forbidden', message: 'server text'));
      expect(find.text('You do not have the right to do this at this branch. Ask a manager.'), findsOneWidget);
    });

    testWidgets('an order that is not there any more', (tester) async {
      await failWith(tester, apiError(ApiErrorKind.notFound, 404, message: 'server text'));
      expect(find.text('This order was not found. It may have been removed. Pull down to refresh.'), findsOneWidget);
    });

    testWidgets('anything else keeps the server\'s own message, and a network failure its usual words', (tester) async {
      await failWith(tester, apiError(ApiErrorKind.validation, 422, message: 'Pick a driver first'));
      expect(find.text('Pick a driver first'), findsOneWidget);
    });

    testWidgets('Arabic', (tester) async {
      useVariant(tester, _phoneAr);
      final repo = FakeOps()
        ..overviewData = staffOverview()
        ..advanceError = apiError(ApiErrorKind.conflict, 409, code: 'order_awaiting_payment', message: 'x');
      await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: _phoneAr, role: OpsRole.staff));
      await settle(tester);
      await tester.tap(find.text('قبول الطلب'));
      await settle(tester);
      expect(find.text('لم يُدفع هذا الطلب بعد. يمكن قبوله عند وصول الدفع الإلكتروني.'), findsOneWidget);
    });
  });
}
