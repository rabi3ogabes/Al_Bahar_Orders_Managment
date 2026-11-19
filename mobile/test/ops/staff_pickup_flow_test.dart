import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/scan/data/scan_source.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../features/scan/scan_kit.dart';
import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

const _phone = Variant('390x1400 en', Size(390, 1400), kEn);
const _phoneAr = Variant('390x1400 ar', Size(390, 1400), kAr);

Future<void> _pump(
  WidgetTester tester,
  FakeOps repo, {
  Variant variant = _phone,
  FakeScanSource? scanner,
  Stream<PusherEvent>? events,
}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(
    opsHost(
      const StaffHome(),
      repo,
      variant: variant,
      role: OpsRole.staff,
      staffEvents: events,
      overrides: [if (scanner != null) scanSourceProvider.overrideWithValue(scanner)],
    ),
  );
  await settle(tester);
}

FakeOps _branch({List<QueueOrder>? pickups, Map<String, bool>? can}) => FakeOps()
  ..overviewData = staffOverview(fresh: 1, ready: 0, pickupNew: 1, pickupPreparing: 1, pickupReady: 1, can: can)
  ..queues = {
    'new': [staffOrder(id: 'd1', number: 'AB100001', clock: clockJson('ok', 2))],
  }
  ..pickupQueue =
      pickups ??
      [
        staffOrder(
          id: 'p1',
          number: 'AB200001',
          type: 'pickup',
          status: 'pending',
          clock: clockJson('amber', 7),
          method: 'pay_at_pickup',
          due: '300.00',
        ),
        staffOrder(
          id: 'p2',
          number: 'AB200002',
          type: 'pickup',
          status: 'preparing',
          clock: clockJson('ok', 30, kind: 'preparing', amberAt: 45, redAt: 90),
        ),
        staffOrder(
          id: 'p3',
          number: 'AB200003',
          type: 'pickup',
          status: 'pickup_ready',
          clock: clockJson('ok', 100, kind: 'pickup', amberAt: 2880, redAt: 5760),
          pickup: {'status': 'ready', 'expired': false, 'token_expires_at': '2099-01-01T10:00:00+03:00'},
        ),
      ];

Future<void> _pickupMode(WidgetTester tester) async {
  await tester.tap(find.text('Pickup · 3'));
  await settle(tester);
}

Future<void> _open(WidgetTester tester, String number) async {
  await tester.tap(find.text('#$number'));
  await settle(tester);
}

Finder get _scanTop => find.byKey(const ValueKey('scan-pass'));

Future<FakeScanSource> _openScanner(WidgetTester tester, FakeOps repo) async {
  final source = FakeScanSource();
  await _pump(tester, repo, scanner: source);
  await tester.tap(_scanTop);
  await settle(tester);
  return source;
}

void main() {
  group('Delivery | Pickup inside the orders screen', () {
    testWidgets(
      'a switch with the two counts; Pickup lists the pickups in their stages, Delivery brings the deliveries back',
      (tester) async {
        await _pump(tester, _branch());
        expect(find.text('Delivery · 1'), findsOneWidget);
        expect(find.text('Pickup · 3'), findsOneWidget);
        expect(find.text('#AB100001'), findsOneWidget);
        expect(find.text('#AB200001'), findsNothing);

        await _pickupMode(tester);
        expect(find.text('#AB100001'), findsNothing);
        expect(find.text('#AB200001'), findsOneWidget);
        expect(find.text('New'), findsWidgets);
        expect(find.text('Preparing'), findsWidgets);
        expect(find.text('Ready for pickup'), findsWidgets);
        expect(find.text('Getting late · 7 min'), findsOneWidget);
        expect(find.text('Collect QAR 300.00 at the counter'), findsOneWidget);

        await tester.tap(find.text('Delivery · 1'));
        await settle(tester);
        expect(find.text('#AB100001'), findsOneWidget);
        expect(find.text('#AB200001'), findsNothing);
      },
    );

    testWidgets('no new tab: still three tabs at most, and the tab number counts the pickups too', (tester) async {
      await _pump(tester, _branch());
      expect(find.byType(Tab), findsNWidgets(3));
      // 1 delivery to do + 3 pickups
      expect(
        find.descendant(of: find.byKey(const ValueKey('staff-tab-badge-todo')), matching: find.text('4')),
        findsOneWidget,
      );
    });

    testWidgets('a person who may not see pickups has no switch and no scanner, only the deliveries', (tester) async {
      await _pump(tester, _branch(can: const {'pickup.view': false}));
      expect(find.byKey(const ValueKey('orders-mode')), findsNothing);
      expect(_scanTop, findsNothing);
      expect(find.text('#AB100001'), findsOneWidget);
      // and the tab number does not count what they cannot see
      expect(
        find.descendant(of: find.byKey(const ValueKey('staff-tab-badge-todo')), matching: find.text('1')),
        findsOneWidget,
      );
    });

    testWidgets('the pickup list says calmly when it is empty', (tester) async {
      await _pump(tester, _branch(pickups: const []));
      await tester.tap(find.text('Pickup · 3'));
      await settle(tester);
      expect(find.text('No pickup orders right now'), findsOneWidget);
    });

    testWidgets('a pickup moves step by step: accept, start preparing, then "Ready for pickup" issues the pass', (
      tester,
    ) async {
      final repo = _branch();
      await _pump(tester, repo);
      await _pickupMode(tester);
      await tester.tap(find.text('Accept order'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Ready for pickup'));
      await settle(tester);
      expect(repo.calls, ['advance:p1:accepted', 'pickup-ready:p2']);
      expect(find.text('Ready for pickup. The customer has been told.'), findsOneWidget);
    });

    testWidgets('the dashboard\'s "slide to confirm" is respected for the pickup steps too', (tester) async {
      final repo = _branch()..style = OpsStatusStyle.slide;
      await _pump(tester, repo);
      await _pickupMode(tester);
      expect(find.byType(SlideToConfirm), findsNWidgets(2), reason: 'accept and "ready for pickup"');
      expect(find.text('Slide to accept order'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Accept order'), findsNothing);
    });

    testWidgets('a step the server refuses is explained and the list is read again', (tester) async {
      final repo = _branch()
        ..advanceError = apiError(ApiErrorKind.conflict, 409, code: 'order_awaiting_payment', message: 'x');
      await _pump(tester, repo);
      await _pickupMode(tester);
      final before = repo.pickupLoads;
      await tester.tap(find.text('Accept order'));
      await settle(tester);
      expect(
        find.text('This order is not paid yet. It can be accepted when the online payment arrives.'),
        findsOneWidget,
      );
      expect(repo.pickupLoads, greaterThan(before));
    });

    testWidgets('a new pickup order alerts like a delivery one, once', (tester) async {
      final events = StreamController<PusherEvent>.broadcast();
      addTearDown(events.close);
      final repo = _branch();
      await _pump(tester, repo, events: events.stream);
      PusherEvent say(int seq) => PusherEvent(
        channel: 'private-branches.b1',
        event: 'dispatch.updated',
        data: {
          'fulfillment_id': 'p9',
          'fulfillment_type': 'pickup',
          'fulfillment_status': 'pending',
          'kind': 'order',
          'seq': seq,
          'number': 'AB200009',
          'order_id': 'o-p9',
        },
      );
      events.add(say(1));
      await settle(tester);
      expect(find.text('New pickup order #AB200009'), findsOneWidget);
      repo.pickupQueue = [
        ...repo.pickupQueue,
        staffOrder(id: 'p9', number: 'AB200009', type: 'pickup', clock: clockJson('ok', 0)),
      ];
      events.add(say(2));
      await settle(tester);
      await tester.pump(const Duration(seconds: 5));
      await tester.pump(const Duration(seconds: 1));
      expect(
        find.text('New pickup order #AB200009'),
        findsNothing,
        reason: 'the first one timed out and the repeat did not alert again',
      );
    });
  });

  group('the pass', () {
    FakeOps expired() => _branch(
      pickups: [
        staffOrder(
          id: 'p3',
          number: 'AB200003',
          type: 'pickup',
          status: 'pickup_ready',
          clock: clockJson('red', 6000, kind: 'pickup', amberAt: 2880, redAt: 5760),
          pickup: {'status': 'ready', 'expired': true, 'token_expires_at': '2020-01-01T10:00:00+03:00'},
        ),
      ],
    );

    testWidgets('an expired pass says so on the card and offers a new one first', (tester) async {
      await _pump(tester, expired());
      await _pickupMode(tester);
      expect(find.text('The pass has expired'), findsOneWidget);
      expect(find.byKey(const ValueKey('pickup-reissue')), findsOneWidget);
      expect(find.byKey(const ValueKey('pickup-scan')), findsNothing, reason: 'the card shows one thing to do');
    });

    testWidgets('"Re-issue pass" asks first, then gives the new pass and says the customer was told', (tester) async {
      final repo = expired();
      await _pump(tester, repo);
      await _pickupMode(tester);
      await tester.tap(find.byKey(const ValueKey('pickup-reissue')));
      await settle(tester);
      expect(find.text('Issue a new pass?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.calls, isEmpty);
      await tester.tap(find.byKey(const ValueKey('pickup-reissue')));
      await settle(tester);
      await tester.tap(find.text('Issue new pass'));
      await settle(tester);
      expect(repo.calls, ['pickup-reissue:p3']);
      expect(find.text('New pass issued. The customer has been told.'), findsOneWidget);
    });

    testWidgets('the window has all three: scan, a new pass, and (a manager) hand over without the pass', (
      tester,
    ) async {
      await _pump(tester, expired());
      await _pickupMode(tester);
      await _open(tester, 'AB200003');
      expect(find.byKey(const ValueKey('pickup-scan')), findsOneWidget);
      expect(find.byKey(const ValueKey('pickup-reissue')), findsNWidgets(2), reason: 'the card and the window');
      expect(find.byKey(const ValueKey('pickup-manual')), findsOneWidget);
      expect(find.textContaining('The pass has expired'), findsWidgets);
    });

    testWidgets('a person without the right never sees "hand over without the pass"', (tester) async {
      final repo = expired()
        ..overviewData = staffOverview(
          pickupReady: 1,
          can: const {'pickup.view': true, 'pickup.edit': true, 'pickup.approve': false},
        );
      await _pump(tester, repo);
      await tester.tap(find.text('Pickup · 1'));
      await settle(tester);
      await _open(tester, 'AB200003');
      expect(find.byKey(const ValueKey('pickup-manual')), findsNothing);
      expect(find.byKey(const ValueKey('pickup-scan')), findsOneWidget);
    });
  });

  group('hand over without the pass (a manager)', () {
    Future<FakeOps> openForm(WidgetTester tester, {String method = 'cod', String due = '0.00'}) async {
      final repo = _branch(
        pickups: [
          staffOrder(
            id: 'p3',
            number: 'AB200003',
            type: 'pickup',
            status: 'pickup_ready',
            method: method,
            due: due,
            pickup: {'status': 'ready', 'expired': true},
          ),
        ],
      );
      await _pump(tester, repo);
      await _pickupMode(tester);
      await _open(tester, 'AB200003');
      await tester.tap(find.byKey(const ValueKey('pickup-manual')));
      await settle(tester);
      return repo;
    }

    testWidgets('asks the last 4 digits and why; both are checked before anything is sent', (tester) async {
      final repo = await openForm(tester);
      expect(find.text('Hand over without the pass'), findsWidgets);
      expect(find.text('Order #AB200003'), findsWidgets);
      await tester.tap(find.byKey(const ValueKey('manual-confirm')));
      await settle(tester);
      expect(find.text('Type exactly 4 digits.'), findsOneWidget);
      expect(find.text('Write why the pass is not used.'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('manual-digits')), '12');
      await tester.enterText(find.byKey(const ValueKey('manual-reason')), 'Pass expired, customer is here');
      await tester.tap(find.byKey(const ValueKey('manual-confirm')));
      await settle(tester);
      expect(find.text('Type exactly 4 digits.'), findsOneWidget);
      expect(repo.manualHandovers, isEmpty);
    });

    testWidgets('sends the order, the 4 digits and the reason, then closes and says it was recorded', (tester) async {
      final repo = await openForm(tester);
      await tester.enterText(find.byKey(const ValueKey('manual-digits')), '٣٤٥٦'); // typed with Arabic digits
      await tester.enterText(find.byKey(const ValueKey('manual-reason')), '  Pass expired, customer is here  ');
      await tester.tap(find.byKey(const ValueKey('manual-confirm')));
      await settle(tester);
      expect(repo.manualHandovers.single.branchId, 'b1');
      expect(repo.manualHandovers.single.orderNumber, 'AB200003');
      expect(repo.manualHandovers.single.phoneLast4, '3456');
      expect(repo.manualHandovers.single.reason, 'Pass expired, customer is here');
      expect(repo.manualHandovers.single.collection, isNull);
      expect(find.byKey(const ValueKey('manual-confirm')), findsNothing);
      expect(find.text('Handed over. Your reason was recorded.'), findsOneWidget);
    });

    testWidgets('pay at pickup: the money is collected in the same step', (tester) async {
      final repo = await openForm(tester, method: 'pay_at_pickup', due: '120.00');
      expect(find.text('QAR 120.00'), findsWidgets);
      await tester.enterText(find.byKey(const ValueKey('manual-digits')), '3456');
      await tester.enterText(find.byKey(const ValueKey('manual-reason')), 'Lost the phone');
      await tester.tap(find.text('Card terminal'));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('manual-confirm')));
      await settle(tester);
      final c = repo.manualHandovers.single.collection!;
      expect((c.tender, c.amount), ('card_terminal', '120.00'));
    });

    testWidgets('a wrong detail is answered in the window, never saying which one, and the window stays open', (
      tester,
    ) async {
      final repo = await openForm(tester);
      repo.pickupError = apiError(
        ApiErrorKind.validation,
        422,
        code: 'pickup_details_mismatch',
        message: 'server text',
      );
      await tester.enterText(find.byKey(const ValueKey('manual-digits')), '0000');
      await tester.enterText(find.byKey(const ValueKey('manual-reason')), 'Lost the pass');
      await tester.tap(find.byKey(const ValueKey('manual-confirm')));
      await settle(tester);
      expect(
        find.text(
          'These details do not match an order waiting at this branch. Check the order number and the last 4 digits of the phone number.',
        ),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('manual-confirm')), findsOneWidget);
      repo.pickupError = const ApiException(
        kind: ApiErrorKind.rateLimited,
        statusCode: 429,
        code: 'pickup_manual_locked',
        context: {'minutes': 15},
      );
      await tester.tap(find.byKey(const ValueKey('manual-confirm')));
      await settle(tester);
      expect(find.text('Too many wrong tries for this order. Try again in 15 minutes.'), findsOneWidget);
    });
  });

  group('scanning a pass', () {
    PickupCheck check({String due = '0.00', List<PickupItem>? items}) => passOk(due: due, items: items);

    testWidgets('the scan button is in the header; the camera opens and the hint says what to do', (tester) async {
      final source = await _openScanner(tester, _branch());
      expect(find.text('Scan pickup pass'), findsOneWidget);
      expect(find.text("Point the camera at the customer's QR code."), findsOneWidget);
      expect(source.starts, 1);
      expect(find.byKey(const ValueKey('fake-camera')), findsOneWidget);
    });

    testWidgets(
      'a good pass shows what to hand over; "Hand over everything" sends every line, then the screen is ready for the next customer',
      (tester) async {
        final repo = _branch()..verifyAnswer = check();
        final source = await _openScanner(tester, repo);
        source.emit('ABPK1.token');
        await settle(tester);
        expect(repo.verified.single, (code: 'ABPK1.token', branchId: 'b1'));
        expect(find.text('The pass is valid'), findsOneWidget);
        expect(find.text('Noura'), findsOneWidget);
        expect(find.text('Oslo sofa · Grey'), findsOneWidget);
        expect(find.text('3 left'), findsOneWidget);
        expect(find.text('Hand over everything'), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('handover-confirm')));
        await settle(tester);
        expect(repo.handovers.single.reservationId, 'rv1');
        expect(repo.handovers.single.items, {'i1': 3, 'i2': 1});
        expect(repo.handovers.single.collector, 'Noura');
        expect(repo.handovers.single.collection, isNull);
        expect(find.text('Handed over. The order is complete.'), findsOneWidget);
        expect(find.text('Scan pickup pass'), findsOneWidget, reason: 'still on the scanner');
      },
    );

    testWidgets('part of the goods: fewer of a line, the button says so, and the rest stays reserved', (tester) async {
      final repo = _branch()
        ..verifyAnswer = check()
        ..handoverAnswer = 'partially_collected';
      final source = await _openScanner(tester, repo);
      source.emit('ABPK1.token');
      await settle(tester);
      // two taps on the minus of the sofa line: 3 -> 1; the lamp is not handed over now
      final minus = find.byIcon(Icons.remove_rounded);
      await tester.tap(minus.first);
      await tester.pump();
      await tester.tap(minus.first);
      await tester.pump();
      await tester.tap(minus.last);
      await settle(tester);
      expect(find.text('Hand over selected items'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('handover-confirm')));
      await settle(tester);
      expect(repo.handovers.single.items, {'i1': 1});
      expect(find.text('Part handed over. The rest stays reserved.'), findsOneWidget);
    });

    testWidgets('nothing selected: the button is off and says why', (tester) async {
      final repo = _branch()
        ..verifyAnswer = check(
          items: const [PickupItem(id: 'i2', name: 'Oslo lamp', quantity: 1, handedOver: 0)],
        );
      final source = await _openScanner(tester, repo);
      source.emit('ABPK1.token');
      await settle(tester);
      await tester.tap(find.byIcon(Icons.remove_rounded));
      await settle(tester);
      expect(find.text('Choose at least one item to hand over.'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('handover-confirm'))).onPressed, isNull);
    });

    testWidgets('a line that was partly handed over before shows what is left', (tester) async {
      final repo = _branch()
        ..verifyAnswer = check(
          items: const [PickupItem(id: 'i1', name: 'Oslo sofa', quantity: 3, handedOver: 2)],
        );
      final source = await _openScanner(tester, repo);
      source.emit('ABPK1.token');
      await settle(tester);
      expect(find.text('1 left · 2 already handed over'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('handover-confirm')));
      await settle(tester);
      expect(repo.handovers.single.items, {'i1': 1});
    });

    testWidgets(
      'pay at pickup: the amount to collect is shown, cash is the default, and the collection goes with the hand-over',
      (tester) async {
        final repo = _branch()..verifyAnswer = check(due: '300.00');
        final source = await _openScanner(tester, repo);
        source.emit('ABPK1.token');
        await settle(tester);
        expect(find.byKey(const ValueKey('handover-amount')), findsOneWidget);
        expect(find.text('QAR 300.00'), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('handover-confirm')));
        await settle(tester);
        final c = repo.handovers.single.collection!;
        expect((c.tender, c.amount, c.receiptReference), ('cash', '300.00', null));
      },
    );

    testWidgets('paid with the card terminal: the receipt reference goes along', (tester) async {
      final repo = _branch()..verifyAnswer = check(due: '300.00');
      final source = await _openScanner(tester, repo);
      source.emit('ABPK1.token');
      await settle(tester);
      await tester.tap(find.text('Card terminal'));
      await settle(tester);
      await tester.enterText(find.byType(TextField), 'RCPT-77');
      await tester.tap(find.byKey(const ValueKey('handover-confirm')));
      await settle(tester);
      final c = repo.handovers.single.collection!;
      expect((c.tender, c.amount, c.receiptReference), ('card_terminal', '300.00', 'RCPT-77'));
    });

    for (final (result, words) in const [
      ('expired', 'This pass has expired. Give the customer a new pass, or a manager can hand over without it.'),
      ('already_used', 'This pass was already used. Do not hand anything over.'),
      ('wrong_branch', 'This pass is for another branch.'),
      ('not_ready', 'This order is not ready for pickup yet.'),
      ('invalid_token', 'This code is not a valid pass. Do not hand anything over.'),
    ]) {
      testWidgets('a refused pass ($result) says why in plain words and nothing can be handed over', (tester) async {
        final repo = _branch()..verifyAnswer = PickupCheck(result: result);
        final source = await _openScanner(tester, repo);
        source.emit('ABPK1.token');
        await settle(tester);
        expect(find.text(words), findsOneWidget);
        expect(find.byKey(const ValueKey('handover-confirm')), findsNothing);
        await tester.tap(find.text('Scan another pass'));
        await settle(tester);
        expect(find.text('Scan pickup pass'), findsOneWidget);
        expect(repo.handovers, isEmpty);
      });
    }

    testWidgets('the same pass held in front of the camera is checked once; a different one right after is checked', (
      tester,
    ) async {
      final repo = _branch()..verifyAnswer = const PickupCheck(result: 'already_used');
      final source = await _openScanner(tester, repo);
      source
        ..emit('ABPK1.one')
        ..emit('ABPK1.one');
      await settle(tester);
      expect(repo.verified.length, 1);
      await tester.tap(find.text('Scan another pass'));
      await settle(tester);
      source.emit('ABPK1.one');
      await settle(tester);
      expect(repo.verified.length, 1, reason: 'still in front of the camera, just refused');
      source.emit('ABPK1.two');
      await settle(tester);
      expect(repo.verified.map((v) => v.code), ['ABPK1.one', 'ABPK1.two']);
    });

    testWidgets('the camera is not allowed: a clear message, the settings, and the code can be typed instead', (
      tester,
    ) async {
      final source = FakeScanSource(camera: ScanCameraState.permissionDenied);
      final repo = _branch()..verifyAnswer = check();
      await _pump(tester, repo, scanner: source);
      await tester.tap(_scanTop);
      await settle(tester);
      expect(find.byKey(const ValueKey('scan-camera-problem')), findsOneWidget);
      expect(
        find.text('The camera is not allowed. Allow it in the phone settings, or type the code under the QR.'),
        findsOneWidget,
      );
      await tester.tap(find.text('Open settings'));
      expect(source.settingsOpened, 1);
      await tester.tap(find.byKey(const ValueKey('scan-type-code')));
      await settle(tester);
      await tester.enterText(find.byKey(const ValueKey('scan-code-field')), 'ABPK1.typed');
      await tester.tap(find.text('Check the code'));
      await settle(tester);
      expect(repo.verified.single.code, 'ABPK1.typed');
      expect(find.text('The pass is valid'), findsOneWidget);
    });

    testWidgets('no camera at all: says so and offers to try again', (tester) async {
      final source = FakeScanSource(camera: ScanCameraState.unavailable);
      await _pump(tester, _branch(), scanner: source);
      await tester.tap(_scanTop);
      await settle(tester);
      expect(find.text('The camera cannot be opened. Type the code under the QR instead.'), findsOneWidget);
      await tester.tap(find.text('Try again'));
      expect(source.starts, 2);
    });

    testWidgets('a pass check that could not be made says why and the scanner stays ready', (tester) async {
      final repo = _branch()..verifyError = apiError(ApiErrorKind.offline, 0);
      final source = await _openScanner(tester, repo);
      source.emit('ABPK1.token');
      await settle(tester);
      expect(find.text('Scan pickup pass'), findsOneWidget);
      expect(find.byKey(const ValueKey('handover-confirm')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets(
      'the hand-over is refused while a cancellation waits (the safety net): the sentence shows and the window stays',
      (tester) async {
        final repo = _branch()
          ..verifyAnswer = check()
          ..pickupError = apiError(ApiErrorKind.conflict, 409, code: 'cancellation_pending', message: 'x');
        final source = await _openScanner(tester, repo);
        source.emit('ABPK1.token');
        await settle(tester);
        await tester.tap(find.byKey(const ValueKey('handover-confirm')));
        await settle(tester);
        expect(
          find.text('The customer asked to cancel. A manager must decide before this order leaves the branch.'),
          findsOneWidget,
        );
        expect(find.byKey(const ValueKey('handover-confirm')), findsOneWidget);
      },
    );

    testWidgets('a person who may check but not hand over is told to ask a manager, and cannot press the button', (
      tester,
    ) async {
      final repo = _branch(can: const {'pickup.view': true, 'pickup.approve': false})..verifyAnswer = check();
      final source = await _openScanner(tester, repo);
      source.emit('ABPK1.token');
      await settle(tester);
      expect(find.byKey(const ValueKey('handover-no-right')), findsOneWidget);
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('handover-confirm'))).onPressed, isNull);
    });

    testWidgets('double tap on "Hand over" hands over once', (tester) async {
      final repo = _branch()
        ..verifyAnswer = check()
        ..pickupGate = Completer<void>();
      final source = await _openScanner(tester, repo);
      source.emit('ABPK1.token');
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('handover-confirm')));
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('handover-confirm')), warnIfMissed: false);
      await tester.pump();
      repo.pickupGate!.complete();
      await settle(tester);
      expect(repo.handovers.length, 1);
    });

    testWidgets('Arabic: the scanner and the hand-over are Arabic and fit', (tester) async {
      final repo = _branch()..verifyAnswer = check(due: '300.00');
      final source = FakeScanSource();
      await _pump(tester, repo, variant: _phoneAr, scanner: source);
      await tester.tap(_scanTop);
      await settle(tester);
      expect(find.text('مسح تصريح الاستلام'), findsOneWidget);
      source.emit('ABPK1.token');
      await settle(tester);
      expect(find.text('التصريح صالح'), findsOneWidget);
      expect(find.text('حصّل المبلغ أولاً'), findsWidgets);
      expect(tester.takeException(), isNull);
    });
  });
}
