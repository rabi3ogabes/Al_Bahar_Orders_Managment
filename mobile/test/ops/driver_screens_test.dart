import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

Future<ProviderContainer> _pump(
  WidgetTester tester,
  FakeOps repo, {
  Variant variant = const Variant('default', Size(390, 844), kEn),
  FakeLocation? location,
  FakePicker? picker,
  FakeTracker? tracker,
}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(
    opsHost(const DriverHome(), repo, variant: variant, location: location, picker: picker, tracker: tracker),
  );
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(DriverHome)));
}

void main() {
  group('driver home under every phone, language, text size and theme', () {
    for (final v in variants) {
      testWidgets('lays out without overflow: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        final repo = FakeOps()
          ..jobList = [
            FakeOps.job(id: 'a', status: 'assigned'),
            FakeOps.job(id: 'b', status: 'out_for_delivery'),
            FakeOps.job(id: 'c', status: 'failed', cod: '0'),
          ];
        await _pump(tester, repo, variant: v);
        expect(tester.takeException(), isNull);
        expect(find.text('#AB100008'), findsWidgets);
        // The address words follow the app language.
        expect(find.textContaining(v.rtl ? 'المنطقة 24' : 'Zone 24'), findsWidgets);
        expect(find.text(v.rtl ? 'قبول' : 'Accept'), findsOneWidget);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }
  });

  group('states', () {
    testWidgets('loading shows a spinner, empty explains, error offers a retry', (tester) async {
      final repo = FakeOps()..jobsGate = Completer<List<DriverJob>>();
      await _pump(tester, repo);
      expect(find.byType(CircularProgressIndicator), findsWidgets);
      repo.jobsGate!.complete(const []);
      await settle(tester);
      expect(find.text('No deliveries yet'), findsOneWidget);
      expect(find.textContaining('New deliveries appear here'), findsOneWidget);
    });

    testWidgets('offline with nothing to show: plain message and Try again reloads', (tester) async {
      final repo = FakeOps()..jobsError = apiError(ApiErrorKind.offline, 0);
      await _pump(tester, repo);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsWidgets);
      final before = repo.jobsLoads;
      repo.jobsError = null;
      await tester.tap(find.text('Try again').first);
      await settle(tester);
      expect(repo.jobsLoads, greaterThan(before));
      expect(find.text('#AB100008'), findsOneWidget);
    });

    testWidgets('a failed refresh keeps the last list and says so (the list never vanishes on a bad network)', (
      tester,
    ) async {
      final repo = FakeOps();
      final container = await _pump(tester, repo);
      expect(find.text('#AB100008'), findsOneWidget);
      repo.jobsError = apiError(ApiErrorKind.timeout, 0);
      container.invalidate(driverJobsProvider(false));
      await settle(tester);
      expect(find.text('#AB100008'), findsOneWidget);
      expect(find.text('Could not refresh. Showing the last list.'), findsOneWidget);
      repo.jobsError = null;
      await tester.tap(find.text('Try again'));
      await settle(tester);
      expect(find.text('Could not refresh. Showing the last list.'), findsNothing);
    });

    testWidgets('an empty list can still be pulled to refresh', (tester) async {
      final repo = FakeOps()..jobList = [];
      await _pump(tester, repo);
      final before = repo.jobsLoads;
      await tester.fling(find.text('No deliveries yet'), const Offset(0, 400), 1000);
      await settle(tester);
      expect(repo.jobsLoads, greaterThan(before));
    });

    testWidgets('scheduled time and the cash to collect are shown in Qatar time', (tester) async {
      await _pump(tester, FakeOps());
      expect(find.textContaining('Scheduled:'), findsOneWidget);
      expect(find.textContaining('3:00'), findsOneWidget); // 12:00 UTC = 15:00 Doha
      expect(find.textContaining('QAR 303.00'), findsOneWidget);
    });

    testWidgets('Arabic shows the amount with the Arabic currency', (tester) async {
      await _pump(tester, FakeOps(), variant: variants[1]);
      expect(find.textContaining('303.00 ر.ق'), findsOneWidget);
    });
  });

  group('duty and live location', () {
    testWidgets('on duty with location allowed starts the tracker once; going off duty stops it', (tester) async {
      final repo = FakeOps();
      final tracker = FakeTracker(repo);
      await _pump(tester, repo, tracker: tracker);
      expect(tracker.starts, 1);
      expect(tracker.isRunning, isTrue);

      await tester.tap(find.byType(Switch));
      await settle(tester);
      expect(repo.calls, contains('duty:false'));
      expect(tracker.isRunning, isFalse);
      expect(find.text('Off duty'), findsOneWidget);
    });

    testWidgets('the duty switch ignores a second tap while the first is still on its way', (tester) async {
      final repo = FakeOps()..dutyGate = Completer<void>();
      await _pump(tester, repo);
      await tester.tap(find.byType(Switch));
      await tester.pump();
      expect(find.byType(Switch), findsNothing); // a spinner replaces the switch
      expect(repo.calls.where((c) => c.startsWith('duty')), ['duty:false']);
      repo.dutyGate!.complete();
      await settle(tester);
      expect(find.byType(Switch), findsOneWidget);
    });

    testWidgets('location permission refused: a clear card with a way to the settings, no tracker', (tester) async {
      final repo = FakeOps();
      final location = FakeLocation(access: LocationAccess.deniedForever);
      final tracker = FakeTracker(repo);
      await _pump(tester, repo, location: location, tracker: tracker);
      expect(find.text('Location is off'), findsOneWidget);
      expect(tracker.starts, 0);
      await tester.tap(find.text('Open settings'));
      await tester.pump();
      expect(location.settingsOpened, 1);

      // Allowed again (back from the settings): the card goes and the tracker starts.
      location.access = LocationAccess.granted;
      final container = ProviderScope.containerOf(tester.element(find.byType(DriverHome)));
      container.invalidate(driverMeProvider);
      await settle(tester);
      // The next foreground tick re-checks the permission.
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(tester);
      expect(find.text('Location is off'), findsNothing);
      expect(tracker.starts, 1);
    });

    testWidgets('leaving the screen stops the tracker (no location stream left running)', (tester) async {
      final repo = FakeOps();
      final tracker = FakeTracker(repo);
      await _pump(tester, repo, tracker: tracker);
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      expect(tracker.isRunning, isFalse);
    });
  });

  group('job steps', () {
    testWidgets('accept, picked up and on my way call the right step', (tester) async {
      for (final (status, label, step) in [
        ('assigned', 'Accept', 'accept'),
        ('accepted', 'Picked up from branch', 'pickup'),
        ('picked_up', 'On my way', 'out-for-delivery'),
      ]) {
        final repo = FakeOps()..jobList = [FakeOps.job(status: status)];
        await _pump(tester, repo);
        await tester.tap(find.widgetWithText(FilledButton, label));
        await settle(tester);
        expect(repo.calls.where((c) => !c.startsWith('duty')), ['$step:d1']);
        await tester.pumpWidget(const SizedBox.shrink());
      }
    });

    testWidgets('a double tap sends one request', (tester) async {
      final repo = FakeOps()..stepGate = Completer<void>();
      await _pump(tester, repo);
      final accept = find.widgetWithText(FilledButton, 'Accept');
      await tester.tap(accept);
      await tester.pump();
      await tester.tap(find.byType(FilledButton).first, warnIfMissed: false);
      await tester.pump();
      expect(repo.calls, ['accept:d1']);
      repo.stepGate!.complete();
      await settle(tester);
    });

    testWidgets('409: the job was already taken - the server message shows and the list reloads', (tester) async {
      final repo = FakeOps()..stepError = conflict('This delivery is already accepted');
      await _pump(tester, repo);
      final before = repo.jobsLoads;
      await tester.tap(find.widgetWithText(FilledButton, 'Accept'));
      await settle(tester);
      expect(find.text('This delivery is already accepted'), findsOneWidget);
      expect(repo.jobsLoads, greaterThan(before));
    });

    testWidgets('403 shows the server message', (tester) async {
      final repo = FakeOps()..stepError = apiError(ApiErrorKind.forbidden, 403, message: 'Not your delivery');
      await _pump(tester, repo);
      await tester.tap(find.widgetWithText(FilledButton, 'Accept'));
      await settle(tester);
      expect(find.text('Not your delivery'), findsOneWidget);
    });

    testWidgets('a failed delivery can go out again or be handed back to the branch', (tester) async {
      final repo = FakeOps()..jobList = [FakeOps.job(status: 'failed')];
      await _pump(tester, repo);
      expect(find.text('Try delivery again'), findsOneWidget);
      expect(find.text('Return to branch'), findsOneWidget);

      await tester.tap(find.text('Return to branch'));
      await settle(tester);
      expect(find.text('Handed back at the branch?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.calls, isEmpty);

      await tester.tap(find.text('Return to branch'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm return'));
      await settle(tester);
      expect(repo.calls, ['return:d1']);

      await tester.tap(find.text('Try delivery again'));
      await settle(tester);
      expect(repo.calls.last, 'out-for-delivery:d1');
    });

    testWidgets('could not deliver: reason, then a confirmation, then one request', (tester) async {
      final repo = FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery')];
      await _pump(tester, repo);
      await tester.tap(find.byTooltip('Could not deliver'));
      await settle(tester);
      await tester.tap(find.text('Wrong address'));
      await settle(tester);
      expect(find.text('Report as not delivered?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.calls, isEmpty);

      await tester.tap(find.byTooltip('Could not deliver'));
      await settle(tester);
      await tester.tap(find.text('Wrong address'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Report'));
      await settle(tester);
      expect(repo.calls, ['fail:d1:wrong_address']);
    });
  });

  group('proof of delivery', () {
    Future<void> openSheet(WidgetTester tester, FakeOps repo, {FakePicker? picker, Variant? variant}) async {
      await _pump(tester, repo, picker: picker, variant: variant ?? const Variant('default', Size(390, 844), kEn));
      await tester.tap(
        find.widgetWithText(
          FilledButton,
          (variant ?? const Variant('d', Size(1, 1), kEn)).rtl ? 'تسجيل التسليم' : 'Mark delivered',
        ),
      );
      await settle(tester);
    }

    FakeOps outForDelivery({String cod = '303.00', bool proof = true}) =>
        FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery', cod: cod, proof: proof)];

    testWidgets('the photo is required; cash is validated; Arabic digits are understood', (tester) async {
      final repo = outForDelivery();
      final picker = FakePicker();
      await openSheet(tester, repo, picker: picker);

      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(find.text('A proof photo is required.'), findsOneWidget);
      expect(repo.delivered, isEmpty);

      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      expect(find.text('Photo added'), findsOneWidget);

      final cash = find.widgetWithText(TextField, 'Cash collected (QAR)');
      await tester.enterText(cash, '');
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(find.text('Enter the cash amount.'), findsOneWidget);

      await tester.enterText(cash, '999');
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(find.text('More than the amount due (QAR 303.00).'), findsOneWidget);

      await tester.enterText(cash, '30a3,0x0');
      expect(tester.widget<TextField>(cash).controller!.text, '303.00');

      await tester.enterText(cash, '٣٠٣٫٠٠');
      expect(tester.widget<TextField>(cash).controller!.text, '303.00');
      await tester.enterText(find.widgetWithText(TextField, 'Received by'), '  Noura Ali  ');
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(repo.delivered.single.cod, 303.0);
      expect(repo.delivered.single.recipient, 'Noura Ali');
      expect(repo.delivered.single.proof!.path, picker.next);
      expect(find.text('Photo added'), findsNothing); // the sheet closed
    });

    testWidgets('a double tap on Confirm uploads once', (tester) async {
      final repo = outForDelivery(cod: '0')..deliverGate = Completer<void>();
      await openSheet(tester, repo);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await tester.pump();
      await tester.tap(find.byType(FilledButton).last, warnIfMissed: false);
      await tester.pump();
      expect(repo.delivered, hasLength(1));
      repo.deliverGate!.complete();
      await settle(tester);
    });

    testWidgets('no cash field for a prepaid order, and a missing photo is fine when proof is not required', (
      tester,
    ) async {
      final repo = FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery', cod: '0', proof: false)];
      await openSheet(tester, repo);
      expect(find.widgetWithText(TextField, 'Cash collected (QAR)'), findsNothing);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(repo.delivered.single.proof, isNull);
      expect(repo.delivered.single.cod, isNull);
    });

    testWidgets('offline: the sheet stays open with the photo and the typed name, and works on the next try', (
      tester,
    ) async {
      final repo = outForDelivery(cod: '0')..deliverError = apiError(ApiErrorKind.offline, 0);
      await openSheet(tester, repo);
      await tester.enterText(find.widgetWithText(TextField, 'Received by'), 'Mariam');
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
      expect(find.text('Photo added'), findsOneWidget);
      expect(find.text('Mariam'), findsOneWidget);

      repo.deliverError = null;
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(repo.delivered, hasLength(2));
      expect(find.text('Photo added'), findsNothing);
    });

    testWidgets('422 from the server (amount changed) shows its message in the sheet', (tester) async {
      final repo = outForDelivery()
        ..deliverError = apiError(
          ApiErrorKind.validation,
          422,
          message: 'Collected amount must equal the amount due (QAR 303.00).',
        );
      await openSheet(tester, repo);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(find.text('Collected amount must equal the amount due (QAR 303.00).'), findsOneWidget);
    });

    testWidgets('camera refused: a message, not a crash', (tester) async {
      final repo = outForDelivery();
      final picker = FakePicker()..error = StateError('camera_access_denied');
      await openSheet(tester, repo, picker: picker);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      expect(tester.takeException(), isNull);
      expect(find.text('Could not open the camera. Check the camera permission.'), findsOneWidget);
    });

    testWidgets('closing with a photo taken asks first', (tester) async {
      final repo = outForDelivery();
      await openSheet(tester, repo);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.tap(find.byTooltip('Close'));
      await settle(tester);
      expect(find.text('Discard changes?'), findsOneWidget);
      await tester.tap(find.text('Keep editing'));
      await settle(tester);
      expect(find.text('Photo added'), findsOneWidget);
      await tester.tap(find.byTooltip('Close'));
      await settle(tester);
      await tester.tap(find.text('Discard'));
      await settle(tester);
      expect(find.text('Photo added'), findsNothing);
      expect(repo.delivered, isEmpty);
    });

    for (final v in variants) {
      testWidgets('the sheet fits with the keyboard up: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        final repo = outForDelivery();
        await openSheet(tester, repo, variant: v);
        tester.view.viewInsets = const FakeViewPadding(bottom: 260);
        addTearDown(tester.view.resetViewInsets);
        await tester.pump();
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        handle.dispose();
      });
    }
  });
}
