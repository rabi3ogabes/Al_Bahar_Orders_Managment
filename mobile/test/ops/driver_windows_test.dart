import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/driver/driver_job_map.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/map_fakes.dart';
import 'ops_test_kit.dart';

const _phone = Variant('390 en', Size(390, 844), kEn);
const _phoneAr = Variant('390 ar', Size(390, 844), kAr);
const _wide = Variant('1024 en', Size(1024, 768), kEn);
const _wideAr = Variant('1024 ar', Size(1024, 768), kAr);

Future<ProviderContainer> _pump(WidgetTester tester, FakeOps repo, Variant variant, {FakePicker? picker}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const DriverHome(), repo, variant: variant, picker: picker));
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(DriverHome)));
}

/// The job card is one big tap target: tapping its number opens the delivery window.
Future<void> _openDetails(WidgetTester tester) async {
  await tester.tap(find.text('#AB100008').first);
  await settle(tester);
}

/// A system Back (Android button or gesture): closes the top route only.
Future<void> _back(WidgetTester tester) async {
  await tester.binding.handlePopRoute();
  await settle(tester);
}

Finder get _sheet => find.byType(BottomSheet);
Finder get _panelBody => find.byType(SidePanelLayout);

/// The delivery window lists the details of one job; "Delivery #AB100008" is its title.
Finder get _title => find.text('Delivery #AB100008');

Future<void> _reload(WidgetTester tester, ProviderContainer container) async {
  container
    ..invalidate(driverJobsProvider(false))
    ..invalidate(driverJobsProvider(true));
  await settle(tester);
  // A window that closes itself does so after the frame that noticed, so give it that frame and its exit.
  await settle(tester);
}

void main() {
  group('the delivery opens in a window instead of a page', () {
    testWidgets('phone: a tall bottom sheet with the details; the list stays behind it', (tester) async {
      await _pump(tester, FakeOps(), _phone);
      expect(_title, findsNothing);
      await _openDetails(tester);
      expect(_sheet, findsOneWidget);
      expect(_title, findsOneWidget);
      // Customer, address, payment and the cash to collect.
      expect(find.text('Customer'), findsOneWidget);
      expect(find.text('Delivery address'), findsOneWidget);
      expect(find.text('Noura'), findsWidgets);
      expect(find.text('+97455123456'), findsOneWidget);
      expect(find.text('Zone 24 · Street 817 · Bldg 12'), findsWidgets);
      expect(find.text('QAR 303.00'), findsWidgets);
      expect(find.text('Collect this amount in cash at the door.'), findsOneWidget);
      expect(find.text('Pick up from Al Markhiya'), findsOneWidget);
      // The page behind is still there.
      expect(find.byType(DriverHome), findsOneWidget);
    });

    testWidgets('wide screen: a panel from the end edge (right in English), not a sheet', (tester) async {
      await _pump(tester, FakeOps(), _wide);
      await _openDetails(tester);
      expect(_sheet, findsNothing);
      expect(_title, findsOneWidget);
      final rect = tester.getRect(_panelBody);
      expect(rect.right, 1024);
      expect(rect.width, lessThanOrEqualTo(440));
    });

    testWidgets('Arabic: the panel comes from the LEFT edge', (tester) async {
      await _pump(tester, FakeOps(), _wideAr);
      await tester.tap(find.text('#AB100008').first);
      await settle(tester);
      expect(_sheet, findsNothing);
      expect(find.text('توصيل #AB100008'), findsOneWidget);
      final rect = tester.getRect(_panelBody);
      expect(rect.left, 0);
      expect(rect.width, lessThanOrEqualTo(440));
    });

    testWidgets('Arabic phone: sheet with the Arabic words', (tester) async {
      await _pump(tester, FakeOps(), _phoneAr);
      await tester.tap(find.text('#AB100008').first);
      await settle(tester);
      expect(_sheet, findsOneWidget);
      expect(find.text('العميل'), findsOneWidget);
      expect(find.text('عنوان التوصيل'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Back closes only the window; the list stays and nothing is signed out', (tester) async {
      await _pump(tester, FakeOps(), _phone);
      await _openDetails(tester);
      await _back(tester);
      expect(_title, findsNothing);
      expect(_sheet, findsNothing);
      expect(find.byType(DriverHome), findsOneWidget);
      expect(find.text('#AB100008'), findsOneWidget);
    });

    testWidgets('the close button, a tap on the scrim and Esc close it too', (tester) async {
      await _pump(tester, FakeOps(), _wide);
      await _openDetails(tester);
      await tester.tap(find.byTooltip('Close'));
      await settle(tester);
      expect(_title, findsNothing);

      await _openDetails(tester);
      await tester.tapAt(const Offset(40, 300));
      await settle(tester);
      expect(_title, findsNothing);

      await _openDetails(tester);
      await tester.sendKeyEvent(LogicalKeyboardKey.escape);
      await settle(tester);
      expect(_title, findsNothing);
    });

    testWidgets('the step button is pinned at the bottom and does the step; the window stays open', (tester) async {
      final repo = FakeOps();
      await _pump(tester, repo, _phone);
      await _openDetails(tester);
      final accept = find.descendant(of: _sheet, matching: find.widgetWithText(FilledButton, 'Accept'));
      expect(accept, findsOneWidget);
      // Pinned: it sits at the bottom edge of the sheet, under the details.
      expect(tester.getBottomLeft(accept).dy, greaterThan(844 - 120));
      await tester.tap(accept);
      await settle(tester);
      expect(repo.calls.where((c) => !c.startsWith('duty')), ['accept:d1']);
      expect(_title, findsOneWidget);
    });

    testWidgets('the window follows the job: a real-time reload moves it to the next step', (tester) async {
      final repo = FakeOps();
      final container = await _pump(tester, repo, _phone);
      await _openDetails(tester);
      expect(find.descendant(of: _sheet, matching: find.text('Accept')), findsOneWidget);
      repo.jobList = [FakeOps.job(status: 'accepted')];
      await _reload(tester, container);
      expect(_title, findsOneWidget);
      expect(find.descendant(of: _sheet, matching: find.text('Accept')), findsNothing);
      expect(find.descendant(of: _sheet, matching: find.text('Picked up from branch')), findsOneWidget);
    });

    testWidgets('a refused step shows its message inside the window and keeps it open', (tester) async {
      final repo = FakeOps()..stepError = conflict('This delivery is already accepted');
      await _pump(tester, repo, _phone);
      await _openDetails(tester);
      await tester.tap(find.descendant(of: _sheet, matching: find.widgetWithText(FilledButton, 'Accept')));
      await settle(tester);
      expect(find.text('This delivery is already accepted'), findsOneWidget);
      expect(_title, findsOneWidget);
    });

    testWidgets('a failed refresh does not close the window (a list still loading is not a job that left)', (
      tester,
    ) async {
      final repo = FakeOps();
      final container = await _pump(tester, repo, _phone);
      await _openDetails(tester);
      repo.jobsError = apiError(ApiErrorKind.offline, 0);
      await _reload(tester, container);
      expect(_title, findsOneWidget);
      repo.jobsError = null;
      repo.jobsGate = Completer<List<DriverJob>>();
      container.invalidate(driverJobsProvider(false));
      await tester.pump();
      expect(_title, findsOneWidget); // still loading: stays
      repo.jobsGate!.complete([FakeOps.job()]);
      await settle(tester);
      expect(_title, findsOneWidget);
    });

    testWidgets('when the job leaves the list the window closes itself and says why', (tester) async {
      final repo = FakeOps();
      final container = await _pump(tester, repo, _phone);
      await _openDetails(tester);
      repo.jobList = [];
      await _reload(tester, container);
      expect(_title, findsNothing);
      expect(_sheet, findsNothing);
      expect(find.text('This delivery is no longer in your list.'), findsOneWidget);
      expect(find.text('No deliveries yet'), findsOneWidget);
    });

    testWidgets('Call, Navigate and Copy address live in the window; copying says "Copied" on the button', (
      tester,
    ) async {
      final copied = <String>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
        if (call.method == 'Clipboard.setData') copied.add((call.arguments as Map)['text'] as String);
        return null;
      });
      addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));
      final phone = FakeNavigation();
      useVariant(tester, _phone);
      await tester.pumpWidget(opsHost(const DriverHome(), FakeOps(), navigation: phone));
      await settle(tester);
      await _openDetails(tester);
      expect(find.text('Call Noura'), findsOneWidget);
      await tester.tap(find.descendant(of: _sheet, matching: find.widgetWithText(OutlinedButton, 'Navigate')));
      await settle(tester);
      expect(phone.opened.single.toString(), 'google.navigation:q=25.3,51.5&mode=d');

      await tester.tap(find.text('Copy address'));
      await tester.pump();
      expect(copied, ['Zone 24 · Street 817 · Bldg 12']);
      expect(find.text('Copied'), findsOneWidget);
      await tester.pump(const Duration(seconds: 3));
      expect(find.text('Copied'), findsNothing);
      expect(find.text('Copy address'), findsOneWidget);
    });

    testWidgets('a failed navigation tells so inside the window (not behind it)', (tester) async {
      final phone = FakeNavigation()..opens = false;
      useVariant(tester, _phone);
      await tester.pumpWidget(opsHost(const DriverHome(), FakeOps(), navigation: phone));
      await settle(tester);
      await _openDetails(tester);
      await tester.tap(find.descendant(of: _sheet, matching: find.widgetWithText(OutlinedButton, 'Navigate')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.text('Could not open this on your phone.'), findsOneWidget);
      expect(_title, findsOneWidget);
    });

    testWidgets('"Open delivery map" hands over to the map screen; Back from the map returns to the list', (
      tester,
    ) async {
      await _pump(tester, FakeOps(), _phone);
      await _openDetails(tester);
      await tester.tap(find.text('Open delivery map'));
      await tester.pumpAndSettle();
      expect(find.byType(DriverJobMapScreen), findsOneWidget);
      expect(_title, findsNothing);
      // The map is a full screen with its own way back and its action pinned at the bottom.
      expect(find.byType(BackButton), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Navigate'), findsOneWidget);
      await tester.tap(find.byType(BackButton));
      await tester.pumpAndSettle();
      expect(find.byType(DriverJobMapScreen), findsNothing);
      expect(find.byType(DriverHome), findsOneWidget);
    });

    testWidgets('a job in the history opens read-only: no step buttons, no call', (tester) async {
      final repo = FakeOps()..jobList = [];
      useVariant(tester, _phone);
      await tester.pumpWidget(
        opsHost(
          const DriverHome(),
          repo,
          overrides: [
            driverJobsProvider(true).overrideWith((ref) async => [FakeOps.job(status: 'delivered')]),
          ],
        ),
      );
      await settle(tester);
      await tester.tap(find.text('History'));
      await settle(tester);
      await _openDetails(tester);
      expect(_title, findsOneWidget);
      expect(find.text('Delivered'), findsWidgets); // the status
      expect(find.descendant(of: _sheet, matching: find.byType(FilledButton)), findsNothing);
      expect(find.text('Call Noura'), findsNothing);
      expect(find.text('Navigate'), findsNothing);
    });

    testWidgets('prepaid and cash-free jobs say there is nothing to collect', (tester) async {
      final repo = FakeOps()..jobList = [FakeOps.job(cod: '0')];
      await _pump(tester, repo, _phone);
      await _openDetails(tester);
      expect(find.text('Cash on delivery'), findsNothing);
      expect(find.text('Nothing to collect at the door.'), findsOneWidget);
    });

    testWidgets('the card in the delivery map is not a window opener (the map is the delivery screen)', (tester) async {
      await _pump(tester, FakeOps(), _phone);
      await tester.tap(find.byTooltip('Map'));
      await tester.pumpAndSettle();
      expect(find.byType(DriverJobMapScreen), findsOneWidget);
      await tester.tap(find.text('#AB100008').first, warnIfMissed: false);
      await settle(tester);
      expect(_sheet, findsNothing);
    });
  });

  group('proof of delivery and the reason for a failed delivery stack above the delivery window', () {
    Future<FakeOps> openProof(WidgetTester tester, Variant v, {FakeOps? repo, FakePicker? picker}) async {
      final ops = repo ?? (FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery')]);
      await _pump(tester, ops, v, picker: picker);
      await _openDetails(tester);
      await tester.tap(find.descendant(of: find.byType(SidePanelLayout), matching: find.text('Mark delivered')));
      await settle(tester);
      return ops;
    }

    testWidgets('phone: Mark delivered opens the proof sheet above the delivery; Back closes only the proof', (
      tester,
    ) async {
      await openProof(tester, _phone);
      expect(find.text('Proof of delivery'), findsOneWidget);
      expect(find.text('#AB100008 · Noura'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Confirm delivery'), findsOneWidget);
      await _back(tester);
      expect(find.text('Proof of delivery'), findsNothing);
      expect(_title, findsOneWidget); // the delivery window is still there
      await _back(tester);
      expect(_title, findsNothing);
      expect(find.byType(DriverHome), findsOneWidget);
    });

    testWidgets('wide: the proof is an end panel; its pinned button is at the bottom', (tester) async {
      await openProof(tester, _wide);
      expect(_sheet, findsNothing);
      final button = find.widgetWithText(FilledButton, 'Confirm delivery');
      expect(tester.getBottomLeft(button).dy, greaterThan(768 - 100));
      expect(tester.getRect(find.byType(SidePanelLayout).last).right, 1024);
    });

    testWidgets('Arabic wide: the proof panel is on the left', (tester) async {
      await _pump(tester, FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery')], _wideAr);
      await tester.tap(find.text('#AB100008').first);
      await settle(tester);
      await tester.tap(find.descendant(of: find.byType(SidePanelLayout), matching: find.text('تسجيل التسليم')));
      await settle(tester);
      final rect = tester.getRect(find.byType(SidePanelLayout).last);
      expect(rect.left, 0);
      expect(find.text('إثبات التسليم'), findsOneWidget);
    });

    testWidgets('the proof records the delivery, both windows close and the page says "Delivery recorded."', (
      tester,
    ) async {
      final picker = FakePicker();
      final repo = await openProof(tester, _phone, picker: picker);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.enterText(find.widgetWithText(TextField, 'Received by'), 'Noura');
      // The server moves the job to the history once the delivery is recorded.
      repo.jobList = [];
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      await settle(tester);
      expect(repo.delivered.single.recipient, 'Noura');
      expect(repo.delivered.single.cod, 303.0);
      expect(find.text('Proof of delivery'), findsNothing);
      expect(_title, findsNothing);
      expect(find.text('Delivery recorded.'), findsOneWidget);
    });

    testWidgets('with a photo taken a tap outside or a swipe never closes the proof; Back asks "Discard changes?"', (
      tester,
    ) async {
      await openProof(tester, _wide);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.tapAt(const Offset(40, 300));
      await settle(tester);
      expect(find.text('Proof of delivery'), findsOneWidget);
      await _back(tester);
      expect(find.text('Discard changes?'), findsOneWidget);
      // Keep editing is the focused choice, and the window is still there with its photo.
      await tester.tap(find.text('Keep editing'));
      await settle(tester);
      expect(find.text('Photo added'), findsOneWidget);
      await _back(tester);
      await tester.tap(find.text('Discard'));
      await settle(tester);
      expect(find.text('Proof of delivery'), findsNothing);
      expect(_title, findsOneWidget); // only the proof was discarded
    });

    testWidgets('the discard question cannot be answered by tapping outside it', (tester) async {
      await openProof(tester, _phone);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.tap(find.byTooltip('Close').last);
      await settle(tester);
      expect(find.text('Discard changes?'), findsOneWidget);
      await tester.tapAt(const Offset(5, 5));
      await settle(tester);
      // The dialog closed (tap outside = keep editing) and nothing was thrown away.
      expect(find.text('Photo added'), findsOneWidget);
      expect(find.text('Proof of delivery'), findsOneWidget);
    });

    testWidgets('an untouched proof closes with Back at once, without asking', (tester) async {
      await openProof(tester, _phone);
      await _back(tester);
      expect(find.text('Discard changes?'), findsNothing);
      expect(find.text('Proof of delivery'), findsNothing);
    });

    testWidgets('offline: the proof stays open with its photo, and the pinned button works on the next try', (
      tester,
    ) async {
      final repo = FakeOps()
        ..jobList = [FakeOps.job(status: 'out_for_delivery', cod: '0')]
        ..deliverError = apiError(ApiErrorKind.offline, 0);
      await openProof(tester, _phone, repo: repo);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
      expect(find.text('Photo added'), findsOneWidget);
      repo.deliverError = null;
      repo.jobList = [];
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm delivery'));
      await settle(tester);
      expect(repo.delivered, hasLength(2));
      expect(find.text('Proof of delivery'), findsNothing);
    });

    testWidgets('could not deliver: reason window, then a centred question with Cancel focused, then one request', (
      tester,
    ) async {
      final repo = FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery')];
      await _pump(tester, repo, _phone);
      await _openDetails(tester);
      await tester.tap(find.descendant(of: find.byType(SidePanelLayout), matching: find.text('Could not deliver')));
      await settle(tester);
      expect(find.text('Choose the reason'), findsOneWidget);
      await tester.tap(find.text('Wrong address'));
      await settle(tester);
      expect(find.text('Report as not delivered?'), findsOneWidget);
      // Cancel has the focus: Enter would not report by accident.
      final cancel = tester.widget<TextButton>(find.widgetWithText(TextButton, 'Cancel'));
      expect(cancel.autofocus, isTrue);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.calls, isEmpty);
      expect(_title, findsOneWidget);

      await tester.tap(find.descendant(of: find.byType(SidePanelLayout), matching: find.text('Could not deliver')));
      await settle(tester);
      await tester.tap(find.text('Wrong address'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Report'));
      await settle(tester);
      expect(repo.calls, ['fail:d1:wrong_address']);
    });

    testWidgets('Back in the reason window closes only the reason window', (tester) async {
      final repo = FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery')];
      await _pump(tester, repo, _wide);
      await _openDetails(tester);
      await tester.tap(find.descendant(of: find.byType(SidePanelLayout), matching: find.text('Could not deliver')));
      await settle(tester);
      expect(find.text('Choose the reason'), findsOneWidget);
      await _back(tester);
      expect(find.text('Choose the reason'), findsNothing);
      expect(_title, findsOneWidget);
    });

    testWidgets('a failed delivery offers retry and return in the window; return asks first', (tester) async {
      final repo = FakeOps()..jobList = [FakeOps.job(status: 'failed')];
      await _pump(tester, repo, _phone);
      await _openDetails(tester);
      final inSheet = find.descendant(of: find.byType(SidePanelLayout), matching: find.text('Return to branch'));
      expect(
        find.descendant(of: find.byType(SidePanelLayout), matching: find.text('Try delivery again')),
        findsOneWidget,
      );
      await tester.tap(inSheet);
      await settle(tester);
      expect(find.text('Handed back at the branch?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm return'));
      await settle(tester);
      expect(repo.calls, ['return:d1']);
    });
  });

  group('layout of the delivery window', () {
    for (final v in variants) {
      testWidgets('fits without overflow, 48 dp targets and labels: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(tester, FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery')], v);
        await _openDetails(tester);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }

    testWidgets('the proof fits with the keyboard up on a small phone and the pinned button stays visible', (
      tester,
    ) async {
      final repo = FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery')];
      await _pump(tester, repo, variants.first);
      await _openDetails(tester);
      await tester.tap(find.descendant(of: find.byType(SidePanelLayout), matching: find.text('Mark delivered')));
      await settle(tester);
      await tester.tap(find.widgetWithText(TextField, 'Received by'));
      tester.view.viewInsets = const FakeViewPadding(bottom: 260);
      addTearDown(tester.view.resetViewInsets);
      await settle(tester);
      expect(tester.takeException(), isNull);
      final button = find.widgetWithText(FilledButton, 'Confirm delivery');
      expect(tester.getBottomLeft(button).dy, lessThanOrEqualTo(640 - 260));
    });
  });
}
