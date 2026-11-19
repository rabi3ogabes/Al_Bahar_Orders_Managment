import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/driver/driver_models.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

/// The driver's day: arriving, handing a job back, "could not deliver", going off duty, a cancelled order, no signal, and "My day".

/// The test repository with the driver's newer calls (the plain [FakeOps] does not know them).
class DriverFakeOps extends FakeOps {
  final arrivals = <({String id, double? lat, double? lng})>[];
  Object? arrivedError;
  Completer<void>? arrivedGate;
  final declines = <({String id, String reason, String? note})>[];
  Object? declineError;
  Object? dutyError;
  DriverDay dayData = const DriverDay();
  Object? dayError;
  int dayLoads = 0;

  @override
  Future<DateTime?> arrived(String id, {double? lat, double? lng, double? accuracy}) async {
    arrivals.add((id: id, lat: lat, lng: lng));
    calls.add('arrived:$id');
    await arrivedGate?.future;
    if (arrivedError != null) throw arrivedError!;
    return DateTime.utc(2026, 11, 14, 12, 30);
  }

  @override
  Future<void> decline(String id, String reason, {String? note}) async {
    declines.add((id: id, reason: reason, note: note));
    if (declineError != null) throw declineError!;
  }

  @override
  Future<DriverDay> day() async {
    dayLoads++;
    if (dayError != null) throw dayError!;
    return dayData;
  }

  @override
  Future<bool> setDuty(bool onDuty, {double? lat, double? lng}) async {
    if (dutyError != null) throw dutyError!;
    return super.setDuty(onDuty, lat: lat, lng: lng);
  }
}

Future<ProviderContainer> _pump(
  WidgetTester tester,
  FakeOps repo, {
  Variant variant = const Variant('default', Size(390, 844), kEn),
  FakePicker? picker,
}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const DriverHome(), repo, variant: variant, picker: picker));
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(DriverHome)));
}

DriverJob _onTheRoad({bool arrived = false, String cod = '303.00'}) =>
    FakeOps.job(status: 'out_for_delivery', cod: cod, extra: arrived ? {'arrived_at': '2026-11-14T12:30:00Z'} : null);

PusherEvent _event(Map<String, dynamic> data) =>
    PusherEvent(channel: 'private-drivers.u1', event: 'dispatch.updated', data: data);

void main() {
  group('I have arrived', () {
    testWidgets('on the road the one big action is "I have arrived"; the delivery form comes after it', (tester) async {
      final repo = DriverFakeOps()..jobList = [_onTheRoad()];
      await _pump(tester, repo);
      expect(find.widgetWithText(FilledButton, 'I have arrived'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Mark delivered'), findsNothing);

      await tester.tap(find.widgetWithText(FilledButton, 'I have arrived'));
      await settle(tester);

      expect(repo.arrivals.single.id, 'd1');
      expect(repo.arrivals.single.lat, 25.28); // where he stands goes along when the phone knows it
      // The card moved on at once, before the list was read again.
      expect(find.widgetWithText(FilledButton, 'Mark delivered'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'I have arrived'), findsNothing);
      expect(find.text('The customer knows you are here.'), findsOneWidget);
    });

    testWidgets('a double tap says it once', (tester) async {
      final repo = DriverFakeOps()
        ..jobList = [_onTheRoad()]
        ..arrivedGate = Completer<void>();
      await _pump(tester, repo);
      await tester.tap(find.widgetWithText(FilledButton, 'I have arrived'));
      await tester.pump();
      await tester.tap(find.byType(FilledButton).first, warnIfMissed: false);
      await tester.pump();
      expect(repo.arrivals, hasLength(1));
      repo.arrivedGate!.complete();
      await settle(tester);
    });

    testWidgets(
      'no signal at the door: a plain sentence, the button stays, and "Mark delivered" is still one tap away',
      (tester) async {
        final repo = DriverFakeOps()
          ..jobList = [_onTheRoad()]
          ..arrivedError = apiError(ApiErrorKind.offline, 0);
        await _pump(tester, repo);
        await tester.tap(find.widgetWithText(FilledButton, 'I have arrived'));
        await settle(tester);
        expect(find.text('No connection. Check your signal and try again.'), findsOneWidget);
        expect(find.widgetWithText(FilledButton, 'I have arrived'), findsOneWidget);
        // Never a dead end: the driver can still record the delivery.
        expect(find.byKey(const ValueKey('skip-arrival')), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('skip-arrival')));
        await settle(tester);
        expect(find.text('Proof of delivery'), findsOneWidget);
      },
    );

    testWidgets('the dashboard asks for the slide: arriving is slid, never a stray tap', (tester) async {
      final repo = DriverFakeOps()
        ..style = OpsStatusStyle.slide
        ..jobList = [_onTheRoad()];
      await _pump(tester, repo);
      await tester.pump(const Duration(milliseconds: 200));
      expect(find.text('Slide: I have arrived'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'I have arrived'), findsNothing);
      await tester.drag(find.byKey(slideThumbKey).first, const Offset(300, 0));
      await settle(tester);
      expect(repo.arrivals, hasLength(1));
    });

    testWidgets('a job that already arrived goes straight to the delivery form', (tester) async {
      final repo = DriverFakeOps()..jobList = [_onTheRoad(arrived: true)];
      await _pump(tester, repo);
      expect(find.widgetWithText(FilledButton, 'Mark delivered'), findsOneWidget);
      expect(find.byKey(const ValueKey('skip-arrival')), findsNothing);
    });
  });

  group('handing a job back', () {
    testWidgets('only a job that was given and not accepted offers it', (tester) async {
      final repo = DriverFakeOps()
        ..jobList = [FakeOps.job(id: 'a', status: 'assigned'), FakeOps.job(id: 'b', status: 'accepted')];
      await _pump(tester, repo);
      expect(find.byKey(const ValueKey('decline-job')), findsOneWidget);
    });

    testWidgets('a reason, one confirm: the job leaves the list at once and the branch is told', (tester) async {
      final repo = DriverFakeOps();
      await _pump(tester, repo);
      await tester.tap(find.byKey(const ValueKey('decline-job')));
      await settle(tester);
      expect(find.text("Can't take this delivery?"), findsOneWidget);

      // Nothing happens before he chose why.
      await tester.tap(find.widgetWithText(FilledButton, 'Hand it back'));
      await settle(tester);
      expect(find.text('Choose a reason first.'), findsOneWidget);
      expect(repo.declines, isEmpty);

      await tester.tap(find.byKey(const ValueKey('decline-too_far')));
      await settle(tester);
      await tester.enterText(find.byType(TextField), 'I am in Al Khor');
      await tester.tap(find.widgetWithText(FilledButton, 'Hand it back'));
      await settle(tester);

      expect(repo.declines.single, (id: 'd1', reason: 'too_far', note: 'I am in Al Khor'));
      expect(find.text('#AB100008'), findsNothing);
      expect(find.text('Handed back. The branch will give it to another driver.'), findsOneWidget);
    });

    testWidgets('the message that echoes it is not news to him: no dialog about a job he handed back himself', (
      tester,
    ) async {
      final repo = DriverFakeOps();
      final container = await _pump(tester, repo);
      await tester.tap(find.byKey(const ValueKey('decline-job')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('decline-vehicle')));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Hand it back'));
      await settle(tester);

      container
          .read(driverLiveProvider.notifier)
          .onEvent(
            _event({
              'delivery_id': 'd1',
              'order_id': 'o1',
              'number': 'AB100008',
              'seq': 9,
              'status': 'unassigned',
              'assigned': false,
            }),
          );
      await settle(tester);

      expect(find.byKey(const ValueKey('job-notice')), findsNothing);
    });

    testWidgets('a refused hand-back says why in plain words and keeps the sheet', (tester) async {
      final repo = DriverFakeOps()..declineError = conflict();
      await _pump(tester, repo);
      await tester.tap(find.byKey(const ValueKey('decline-job')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('decline-other')));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Hand it back'));
      await settle(tester);
      expect(find.text('This step is not possible now. The list was updated.'), findsOneWidget);
      expect(find.text("Can't take this delivery?"), findsOneWidget);
    });
  });

  group('could not deliver', () {
    Future<void> open(WidgetTester tester, DriverFakeOps repo, {FakePicker? picker}) async {
      await _pump(tester, repo, picker: picker);
      await tester.tap(find.byTooltip('Could not deliver'));
      await settle(tester);
    }

    testWidgets('has a tile for every reason, damaged included', (tester) async {
      await open(tester, DriverFakeOps()..jobList = [_onTheRoad(arrived: true)]);
      for (final r in [
        'customer_absent',
        'customer_unreachable',
        'wrong_address',
        'refused',
        'access_denied',
        'damaged',
        'other',
      ]) {
        expect(find.byKey(ValueKey('fail-$r')), findsOneWidget, reason: r);
      }
      expect(find.text('Damaged'), findsOneWidget);
    });

    testWidgets('"Other" and "Damaged" need a note; the others do not', (tester) async {
      final repo = DriverFakeOps()..jobList = [_onTheRoad(arrived: true)];
      await open(tester, repo);
      await tester.tap(find.byKey(const ValueKey('fail-damaged')));
      await settle(tester);
      expect(find.text('Tell the branch what happened'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'Report: could not deliver'));
      await settle(tester);
      expect(find.text('Please write a short note so the branch knows what happened.'), findsOneWidget);
      expect(repo.failures, isEmpty);

      await tester.enterText(find.byType(TextField), 'The box was crushed');
      await tester.tap(find.widgetWithText(FilledButton, 'Report: could not deliver'));
      await settle(tester);
      expect(repo.failures.single.reason, 'damaged');
      expect(repo.failures.single.notes, 'The box was crushed');
    });

    testWidgets('a photo of the door goes with the report, and an optional note stays optional', (tester) async {
      final repo = DriverFakeOps()..jobList = [_onTheRoad(arrived: true)];
      final picker = FakePicker();
      await open(tester, repo, picker: picker);
      await tester.tap(find.byKey(const ValueKey('fail-customer_absent')));
      await settle(tester);
      expect(find.text('Note (optional)'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('fail-photo')));
      await settle(tester);
      expect(find.text('Photo added (tap to take another)'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'Report: could not deliver'));
      await settle(tester);
      expect(repo.failures.single.photo!.path, picker.next);
      expect(repo.failures.single.notes, '');
    });

    testWidgets('a double tap reports once', (tester) async {
      final repo = DriverFakeOps()
        ..jobList = [_onTheRoad(arrived: true)]
        ..failGate = Completer<void>();
      await open(tester, repo);
      await tester.tap(find.byKey(const ValueKey('fail-refused')));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Report: could not deliver'));
      await tester.pump();
      await tester.tap(find.byType(FilledButton).last, warnIfMissed: false);
      await tester.pump();
      expect(repo.failures, hasLength(1));
      repo.failGate!.complete();
      await settle(tester);
    });

    testWidgets('no signal: the sheet keeps the reason, the note and the photo, and the next try is the same report', (
      tester,
    ) async {
      final repo = DriverFakeOps()
        ..jobList = [_onTheRoad(arrived: true)]
        ..failError = apiError(ApiErrorKind.timeout, 0);
      await open(tester, repo);
      await tester.tap(find.byKey(const ValueKey('fail-other')));
      await settle(tester);
      await tester.enterText(find.byType(TextField), 'The lift is out of order');
      await tester.tap(find.byKey(const ValueKey('fail-photo')));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Report: could not deliver'));
      await settle(tester);
      expect(find.text('No connection. Check your signal and try again.'), findsOneWidget);
      expect(find.text('The lift is out of order'), findsOneWidget);
      expect(find.text('Photo added (tap to take another)'), findsOneWidget);

      repo.failError = null;
      await tester.tap(find.widgetWithText(FilledButton, 'Report: could not deliver'));
      await settle(tester);
      expect(repo.failures, hasLength(2));
      expect(repo.failures[0].key, repo.failures[1].key); // one report, however often it is sent
      expect(repo.failures[1].notes, 'The lift is out of order');
    });

    testWidgets('closing with something typed asks first', (tester) async {
      final repo = DriverFakeOps()..jobList = [_onTheRoad(arrived: true)];
      await open(tester, repo);
      await tester.tap(find.byKey(const ValueKey('fail-refused')));
      await settle(tester);
      await tester.tap(find.byTooltip('Close'));
      await settle(tester);
      expect(find.text('Discard changes?'), findsOneWidget);
    });

    testWidgets('the slide confirms it when the dashboard asks for the slide', (tester) async {
      final repo = DriverFakeOps()
        ..style = OpsStatusStyle.slide
        ..jobList = [_onTheRoad(arrived: true)];
      await open(tester, repo);
      await tester.tap(find.byKey(const ValueKey('fail-refused')));
      await settle(tester);
      expect(find.text('Slide to report'), findsOneWidget);
      await tester.drag(find.byKey(slideThumbKey).last, const Offset(300, 0));
      await settle(tester);
      expect(repo.failures, hasLength(1));
    });

    for (final v in variants) {
      testWidgets('lays out with the keyboard up: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        final repo = DriverFakeOps()..jobList = [_onTheRoad(arrived: true)];
        useVariant(tester, v);
        await tester.pumpWidget(opsHost(const DriverHome(), repo, variant: v));
        await settle(tester);
        // A small phone with large text: the list scrolls to the button.
        await tester.ensureVisible(find.byTooltip(v.rtl ? 'تعذّر التسليم' : 'Could not deliver'));
        await tester.pump();
        await tester.tap(find.byTooltip(v.rtl ? 'تعذّر التسليم' : 'Could not deliver'));
        await settle(tester);
        await tester.tap(find.byKey(const ValueKey('fail-other')));
        await settle(tester);
        tester.view.viewInsets = const FakeViewPadding(bottom: 260);
        addTearDown(tester.view.resetViewInsets);
        await tester.pump();
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        handle.dispose();
      });
    }
  });

  group('going off duty with jobs in hand', () {
    ApiException busy(int n) =>
        apiError(ApiErrorKind.conflict, 409, code: 'jobs_in_progress', message: 'x').copy(context: {'count': n});

    testWidgets('is refused in plain words, the switch stays on, and nothing is lost', (tester) async {
      final repo = DriverFakeOps()..dutyError = busy(2);
      await _pump(tester, repo);
      await tester.tap(find.byType(Switch));
      await settle(tester);
      expect(find.text('You still have 2 jobs. Finish them or ask the branch to hand them back.'), findsOneWidget);
      await tester.tap(find.text('OK'));
      await settle(tester);
      expect(tester.widget<Switch>(find.byType(Switch)).value, isTrue);
    });

    testWidgets('speaks Arabic with the Arabic forms of "two"', (tester) async {
      final repo = DriverFakeOps()..dutyError = busy(2);
      await _pump(tester, repo, variant: variants[1]);
      await tester.tap(find.byType(Switch));
      await settle(tester);
      expect(find.text('ما زال عندك طلبان. أنهِهما أو اطلب من الفرع استرجاعهما.'), findsOneWidget);
    });

    testWidgets('one job is "1 job", not "1 jobs"', (tester) async {
      final repo = DriverFakeOps()..dutyError = busy(1);
      await _pump(tester, repo);
      await tester.tap(find.byType(Switch));
      await settle(tester);
      expect(find.text('You still have 1 job. Finish it or ask the branch to hand it back.'), findsOneWidget);
    });
  });

  group('a cancelled or taken-away job', () {
    testWidgets('leaves the list at once and one calm dialog says so', (tester) async {
      final repo = DriverFakeOps();
      final container = await _pump(tester, repo);
      expect(find.text('#AB100008'), findsOneWidget);

      container
          .read(driverLiveProvider.notifier)
          .onEvent(
            _event({
              'delivery_id': 'd1',
              'order_id': 'o1',
              'number': 'AB100008',
              'seq': 5,
              'status': 'cancelled',
              'assigned': true,
            }),
          );
      await settle(tester);

      expect(find.byKey(const ValueKey('job-notice')), findsOneWidget);
      expect(find.text('Order #AB100008 cancelled'), findsOneWidget);
      expect(find.text('This order was cancelled. No need to go.'), findsOneWidget);
      await tester.tap(find.text('OK'));
      await settle(tester);
      expect(find.byKey(const ValueKey('job-notice')), findsNothing);
      expect(find.text('Accept'), findsNothing);
      // The same message again is not told twice.
      container
          .read(driverLiveProvider.notifier)
          .onEvent(
            _event({
              'delivery_id': 'd1',
              'order_id': 'o1',
              'number': 'AB100008',
              'seq': 5,
              'status': 'cancelled',
              'assigned': true,
            }),
          );
      await settle(tester);
      expect(find.byKey(const ValueKey('job-notice')), findsNothing);
    });

    testWidgets('a job given to another driver is told too, in its own words', (tester) async {
      final repo = DriverFakeOps();
      final container = await _pump(tester, repo);
      container
          .read(driverLiveProvider.notifier)
          .onEvent(
            _event({
              'delivery_id': 'd1',
              'order_id': 'o1',
              'number': 'AB100008',
              'seq': 5,
              'status': 'unassigned',
              'assigned': false,
            }),
          );
      await settle(tester);
      expect(find.text('This order was given to another driver. It is no longer yours.'), findsOneWidget);
    });

    testWidgets('the delivery window of that job closes and says nothing else: the dialog is the only message', (
      tester,
    ) async {
      final repo = DriverFakeOps();
      final container = await _pump(tester, repo);
      await tester.tap(find.text('#AB100008').first);
      await settle(tester);
      expect(find.byType(SidePanelLayout), findsOneWidget);

      container
          .read(driverLiveProvider.notifier)
          .onEvent(
            _event({
              'delivery_id': 'd1',
              'order_id': 'o1',
              'number': 'AB100008',
              'seq': 5,
              'status': 'cancelled',
              'assigned': true,
            }),
          );
      await settle(tester);
      await settle(tester);

      expect(find.byType(SidePanelLayout), findsNothing);
      expect(find.text('This order was cancelled. No need to go.'), findsOneWidget);
      expect(find.text('This delivery is no longer in your list.'), findsNothing);
    });

    testWidgets('in Arabic', (tester) async {
      final repo = DriverFakeOps();
      final container = await _pump(tester, repo, variant: variants[1]);
      container
          .read(driverLiveProvider.notifier)
          .onEvent(
            _event({
              'delivery_id': 'd1',
              'order_id': 'o1',
              'number': 'AB100008',
              'seq': 5,
              'status': 'cancelled',
              'assigned': true,
            }),
          );
      await settle(tester);
      expect(find.text('تم إلغاء هذا الطلب. لا داعي للذهاب.'), findsOneWidget);
    });
  });

  group('signing out', () {
    Future<void> askToSignOut(WidgetTester tester, DriverFakeOps repo) async {
      await _pump(tester, repo);
      await tester.tap(find.byTooltip('Menu'));
      await settle(tester);
      await tester.tap(find.text('Sign out'));
      await settle(tester);
    }

    testWidgets('with jobs in hand he is told they stay his', (tester) async {
      await askToSignOut(
        tester,
        DriverFakeOps()..jobList = [FakeOps.job(status: 'accepted'), FakeOps.job(id: 'd2', status: 'out_for_delivery')],
      );
      expect(
        find.text('You still have 2 jobs. They stay yours: finish them, or ask the branch to hand them back.'),
        findsOneWidget,
      );
    });

    testWidgets('a job he has not accepted yet does not count: it goes back to the branch when he goes off duty', (
      tester,
    ) async {
      await askToSignOut(tester, DriverFakeOps()..jobList = [FakeOps.job(status: 'assigned')]);
      expect(find.text('You will stop receiving deliveries and stop sharing your location.'), findsOneWidget);
    });

    testWidgets('with nothing in hand the old words stay', (tester) async {
      await askToSignOut(tester, DriverFakeOps()..jobList = []);
      expect(find.text('You will stop receiving deliveries and stop sharing your location.'), findsOneWidget);
    });
  });

  group('no signal', () {
    testWidgets('a banner says so with a Try again that reads the lists once more', (tester) async {
      final repo = DriverFakeOps();
      final container = await _pump(tester, repo);
      expect(find.byKey(const ValueKey('offline-banner')), findsNothing);

      container.read(onlineProvider.notifier).set(false);
      await settle(tester);
      expect(find.text('No connection. Check your signal.'), findsOneWidget);
      final before = repo.jobsLoads;
      await tester.tap(find.byKey(const ValueKey('offline-retry')));
      await settle(tester);
      expect(repo.jobsLoads, greaterThan(before));
      expect(find.byKey(const ValueKey('offline-banner')), findsNothing); // it worked: the signal is back
    });

    testWidgets('stays while the read still fails', (tester) async {
      final repo = DriverFakeOps();
      final container = await _pump(tester, repo);
      container.read(onlineProvider.notifier).set(false);
      await settle(tester);
      repo.jobsError = apiError(ApiErrorKind.offline, 0);
      await tester.tap(find.byKey(const ValueKey('offline-retry')));
      await settle(tester);
      expect(find.byKey(const ValueKey('offline-banner')), findsOneWidget);
    });
  });

  group('My day', () {
    DriverDay day({CashState state = CashState.ok, double held = 450}) => DriverDay.fromJson({
      'date': '2026-11-14',
      'on_duty': true,
      'on_duty_since': '2026-11-14T04:30:00Z',
      'jobs': {'done': 7, 'failed': 1, 'declined': 2, 'active': 3},
      'cash': {
        'currency': 'QAR',
        'collected_today': '1200.00',
        'held': held.toStringAsFixed(2),
        'held_count': 3,
        'oldest_at': '2026-11-13T08:00:00Z',
        'state': state.name,
      },
      'list': [
        {
          'id': 'x1',
          'order_number': 'AB100001',
          'status': 'delivered',
          'customer_name': 'Hamad',
          'address': {'zone_number': '24'},
          'cod_amount': '50.00',
          'at': '2026-11-14T08:00:00Z',
        },
        {
          'id': 'x2',
          'order_number': 'AB100002',
          'status': 'declined',
          'customer_name': 'Salem',
          'address': {'area': 'Al Wakra'},
          'cod_amount': '0.00',
          'at': '2026-11-14T07:00:00Z',
        },
      ],
    });

    Future<void> openDay(WidgetTester tester, DriverFakeOps repo, {Variant? variant}) async {
      await _pump(tester, repo, variant: variant ?? const Variant('default', Size(390, 844), kEn));
      await tester.tap(find.byKey(const ValueKey('open-my-day')));
      await settle(tester);
    }

    testWidgets('friendly numbers, the cash with him, and the day\'s jobs', (tester) async {
      await openDay(tester, DriverFakeOps()..dayData = day());
      expect(find.text('My day'), findsWidgets);
      expect(find.descendant(of: find.byKey(const ValueKey('stat-done')), matching: find.text('7')), findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('stat-failed')), matching: find.text('1')), findsOneWidget);
      expect(
        find.descendant(of: find.byKey(const ValueKey('stat-declined')), matching: find.text('2')),
        findsOneWidget,
      );
      expect(find.descendant(of: find.byKey(const ValueKey('stat-active')), matching: find.text('3')), findsOneWidget);
      expect(find.text('Cash with you: QAR 450.00'), findsOneWidget);
      expect(find.text('Hand it in at the branch.'), findsOneWidget);
      expect(find.text('Collected today: QAR 1200.00'), findsOneWidget);
      expect(find.textContaining('On duty since'), findsOneWidget);
      expect(find.text('#AB100001'), findsOneWidget);
      expect(find.text('Handed back'), findsWidgets); // the hand-back tile and the job's chip
      expect(find.byKey(const ValueKey('cash-amber')), findsNothing);
    });

    testWidgets('cash held a while shows an amber hint, cash held too long a red one, each with its own words', (
      tester,
    ) async {
      await openDay(tester, DriverFakeOps()..dayData = day(state: CashState.amber));
      expect(find.byKey(const ValueKey('cash-amber')), findsOneWidget);
      expect(find.text('It has been with you a while. Please hand it in soon.'), findsOneWidget);
      await tester.pageBack();
      await settle(tester);

      final red = DriverFakeOps()..dayData = day(state: CashState.red);
      await tester.pumpWidget(opsHost(const DriverHome(), red));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('open-my-day')));
      await settle(tester);
      expect(find.byKey(const ValueKey('cash-red')), findsOneWidget);
      expect(find.text('It is late. Please hand it in at the branch now.'), findsOneWidget);
    });

    testWidgets('no cash held: a calm "everything is handed in"', (tester) async {
      await openDay(tester, DriverFakeOps()..dayData = day(held: 0));
      expect(find.text('You hold no cash. Everything is handed in.'), findsOneWidget);
      expect(find.byKey(const ValueKey('cash-amber')), findsNothing);
    });

    testWidgets('an empty day is a calm page, not a blank one', (tester) async {
      await openDay(tester, DriverFakeOps());
      expect(find.text('Nothing yet today'), findsOneWidget);
      expect(find.text('Your deliveries for today will show up here.'), findsOneWidget);
    });

    testWidgets('no signal: a retry, never a blank screen', (tester) async {
      final repo = DriverFakeOps()..dayError = apiError(ApiErrorKind.offline, 0);
      await openDay(tester, repo);
      expect(find.text('Try again'), findsWidgets);
      repo.dayError = null;
      repo.dayData = day();
      await tester.tap(find.text('Try again').last);
      await settle(tester);
      expect(find.text('Cash with you: QAR 450.00'), findsOneWidget);
    });

    testWidgets('Arabic', (tester) async {
      await openDay(tester, DriverFakeOps()..dayData = day(), variant: variants[1]);
      expect(find.text('يومي'), findsWidgets);
      expect(find.text('النقد الذي معك: 450.00 ر.ق'), findsOneWidget);
      expect(find.text('أُرجعت'), findsWidgets);
    });

    for (final v in variants) {
      testWidgets('lays out without overflow: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await openDay(tester, DriverFakeOps()..dayData = day(state: CashState.red), variant: v);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }
  });
}

extension on ApiException {
  /// The same error with another context (the test helper `apiError` has no context).
  ApiException copy({required Map<String, dynamic> context}) =>
      ApiException(kind: kind, statusCode: statusCode, code: code, message: message, errors: errors, context: context);
}
