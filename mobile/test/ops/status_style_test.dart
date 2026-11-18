import 'dart:async';

import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../helpers/fakes.dart';
import 'ops_test_kit.dart';

Future<SharedPreferences> _prefs([Map<String, Object> values = const {}]) async {
  SharedPreferences.setMockInitialValues(values);
  return SharedPreferences.getInstance();
}

Future<ProviderContainer> _staff(
  WidgetTester tester,
  FakeOps repo, {
  Variant variant = const Variant('default', Size(390, 844), kEn),
  SharedPreferences? prefs,
}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(
    opsHost(
      const StaffHome(),
      repo,
      variant: variant,
      role: OpsRole.staff,
      overrides: [if (prefs != null) sharedPreferencesProvider.overrideWithValue(prefs)],
    ),
  );
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
}

Future<ProviderContainer> _driver(
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

/// Slides the (only) slide on screen all the way across, in the direction of the text.
Future<void> _slide(WidgetTester tester, {bool rtl = false, Finder? within}) async {
  final thumb = within == null
      ? find.byKey(slideThumbKey)
      : find.descendant(of: within, matching: find.byKey(slideThumbKey));
  await tester.drag(thumb.first, Offset(rtl ? -260 : 260, 0));
  await settle(tester);
}

void main() {
  group('the setting', () {
    test('only the exact word "slide" asks for the slide; everything else is the buttons', () {
      expect(OpsStatusStyle.fromCode('slide'), OpsStatusStyle.slide);
      expect(OpsStatusStyle.fromCode('buttons'), OpsStatusStyle.buttons);
      for (final v in [null, '', 'SLIDE', 'swipe', 1, true, <String, Object>{}]) {
        expect(OpsStatusStyle.fromCode(v), OpsStatusStyle.buttons, reason: '$v');
      }
    });

    group('the request', () {
      Future<(TestHarness, OpsRepository)> repo(FakeHandler handler) async {
        final h = await TestHarness.create(
          handler: handler,
          tokens: const AuthTokens(accessToken: 'A', refreshToken: 'R'),
        );
        return (h, h.read(opsRepositoryProvider));
      }

      test('asks the public app-config without the staff token and reads ops.status_style', () async {
        final (h, r) = await repo(
          (_) => ok({
            'ops': {'status_style': 'slide'},
            'designed_by': {'en': 'x'},
          }),
        );
        expect(await r.statusStyle(), OpsStatusStyle.slide);
        final req = h.adapter.where('GET', '/app-config').single;
        expect(req.header('Authorization'), isNull, reason: 'a public call: no token');
      });

      test('buttons, a missing ops section and a wrong value all mean buttons', () async {
        for (final body in <Object?>[
          {
            'ops': {'status_style': 'buttons'},
          },
          {
            'designed_by': {'en': 'x'},
          },
          {'ops': <String, Object>{}},
          {'ops': 'nope'},
          {
            'ops': {'status_style': 7},
          },
        ]) {
          final (_, r) = await repo((_) => ok(body));
          expect(await r.statusStyle(), OpsStatusStyle.buttons, reason: '$body');
        }
      });

      test('an answer that is not the dashboard JSON throws, so it can never replace the last good one', () async {
        final (_, r) = await repo((_) => const FakeReply(200, 'welcome to the café wifi'));
        await expectLater(r.statusStyle(), throwsA(isA<FormatException>()));
      });

      test('a server error throws', () async {
        final (_, r) = await repo((_) => error(500, 'server_error'));
        await expectLater(r.statusStyle(), throwsA(isA<ApiException>()));
      });
    });

    group('the provider', () {
      Future<ProviderContainer> container(FakeOps repo, SharedPreferences prefs) async {
        final c = ProviderContainer(
          overrides: [
            opsRepositoryProvider.overrideWithValue(repo),
            sharedPreferencesProvider.overrideWithValue(prefs),
          ],
          retry: (_, _) => null,
        );
        addTearDown(c.dispose);
        return c;
      }

      test('starts with the buttons when the phone knows nothing, and never throws', () async {
        final repo = FakeOps()..styleError = apiError(ApiErrorKind.offline, 0);
        final c = await container(repo, await _prefs());
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.buttons);
        await Future<void>.delayed(Duration.zero);
        await Future<void>.delayed(Duration.zero);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.buttons);
        expect(repo.styleLoads, 1);
      });

      test('a phone without any storage (nothing overridden) still starts, with the buttons', () async {
        final c = ProviderContainer(
          overrides: [opsRepositoryProvider.overrideWithValue(FakeOps()..style = OpsStatusStyle.slide)],
          retry: (_, _) => null,
        );
        addTearDown(c.dispose);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.buttons);
        await Future<void>.delayed(Duration.zero);
        await Future<void>.delayed(Duration.zero);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.slide, reason: 'the server answer still counts');
      });

      test('the server answer replaces the default and is remembered for the next start', () async {
        final prefs = await _prefs();
        final repo = FakeOps()..style = OpsStatusStyle.slide;
        final c = await container(repo, prefs);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.buttons);
        await Future<void>.delayed(Duration.zero);
        await Future<void>.delayed(Duration.zero);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.slide);
        expect(prefs.getString(OpsStatusStyleController.cacheKey), 'slide');

        // The next start (a new container over the same storage) begins with it, before any answer.
        final second = await container(FakeOps()..styleError = apiError(ApiErrorKind.offline, 0), prefs);
        expect(second.read(opsStatusStyleProvider), OpsStatusStyle.slide);
      });

      test('offline: the last value the phone saw stays, in both directions', () async {
        final prefs = await _prefs({OpsStatusStyleController.cacheKey: 'slide'});
        final repo = FakeOps()..styleError = apiError(ApiErrorKind.timeout, 0);
        final c = await container(repo, prefs);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.slide);
        await Future<void>.delayed(Duration.zero);
        await Future<void>.delayed(Duration.zero);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.slide);
        expect(prefs.getString(OpsStatusStyleController.cacheKey), 'slide');
      });

      test('the dashboard switching back to buttons reaches a phone that had the slide', () async {
        final prefs = await _prefs({OpsStatusStyleController.cacheKey: 'slide'});
        final repo = FakeOps()..style = OpsStatusStyle.buttons;
        final c = await container(repo, prefs);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.slide);
        await Future<void>.delayed(Duration.zero);
        await Future<void>.delayed(Duration.zero);
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.buttons);
        expect(prefs.getString(OpsStatusStyleController.cacheKey), 'buttons');
      });

      test('refresh asks again every time it is called', () async {
        final repo = FakeOps();
        final c = await container(repo, await _prefs());
        c.read(opsStatusStyleProvider);
        await Future<void>.delayed(Duration.zero);
        repo.style = OpsStatusStyle.slide;
        await c.read(opsStatusStyleProvider.notifier).refresh();
        expect(c.read(opsStatusStyleProvider), OpsStatusStyle.slide);
        expect(repo.styleLoads, 2);
      });
    });

    group('the app asks again when it comes back to the foreground', () {
      testWidgets('a change on the dashboard reaches an open app on resume', (tester) async {
        var style = 'buttons';
        var asked = 0;
        final h = await TestHarness.create(
          tokens: const AuthTokens(accessToken: 'A', refreshToken: 'R'),
          handler: (r) {
            switch ('${r.method} ${r.path}') {
              case 'GET /app-config':
                asked++;
                return ok({
                  'ops': {'status_style': style},
                });
              case 'GET /admin/dispatch/driver':
                return ok({'id': 'u1', 'name': 'Omar', 'on_duty': false, 'open_jobs': 0, 'branches': []});
              case 'GET /admin/dispatch/driver/jobs':
                return ok([
                  {
                    'id': 'd1',
                    'status': 'assigned',
                    'order_number': 'AB100008',
                    'customer_name': 'Noura',
                    'address': {'zone_number': '24'},
                    'payment_method': 'card',
                    'branch': {'id': 'b1', 'name': 'Al Markhiya'},
                  },
                ]);
            }
            return error(404, 'not_found');
          },
          overrides: [
            opsRoleProvider.overrideWithValue(OpsRole.driver),
            driverEventsProvider.overrideWith((ref) => const Stream.empty()),
            driverTrackerProvider.overrideWithValue(FakeTracker(OpsRepository(ApiClient(Dio())))),
            locationServiceProvider.overrideWithValue(FakeLocation()),
          ],
        );
        useVariant(tester, const Variant('d', Size(390, 844), kEn));
        await tester.pumpWidget(UncontrolledProviderScope(container: h.container, child: const OpsApp()));
        await settle(tester);
        expect(asked, 1, reason: 'asked once at start-up');
        expect(find.byType(SlideToConfirm), findsNothing);
        expect(find.widgetWithText(FilledButton, 'Accept'), findsOneWidget);

        style = 'slide';
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
        await settle(tester);
        expect(asked, 2, reason: 'asked again on resume');
        expect(find.byType(SlideToConfirm), findsOneWidget);
        expect(find.widgetWithText(FilledButton, 'Accept'), findsNothing);
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump(const Duration(seconds: 5));
        expect(h.read(opsSessionProvider), OpsAuth.signedIn);
      });
    });
  });

  group('branch staff', () {
    testWidgets('default: the plain Accept button, no slide', (tester) async {
      final repo = FakeOps();
      await _staff(tester, repo);
      expect(find.widgetWithText(FilledButton, 'Accept order'), findsOneWidget);
      expect(find.byType(SlideToConfirm), findsNothing);
    });

    testWidgets('the dashboard says slide: the step is a slide, one request when it is slid', (tester) async {
      final repo = FakeOps()..style = OpsStatusStyle.slide;
      await _staff(tester, repo);
      expect(find.widgetWithText(FilledButton, 'Accept order'), findsNothing);
      expect(find.text('Slide to accept order'), findsOneWidget);
      expect(repo.calls, isEmpty, reason: 'looking at it does nothing');
      await _slide(tester);
      expect(repo.calls, ['advance:f0:accepted']);
    });

    testWidgets('a short pull does nothing; tapping the label does nothing', (tester) async {
      final repo = FakeOps()..style = OpsStatusStyle.slide;
      await _staff(tester, repo);
      await tester.drag(find.byKey(slideThumbKey), const Offset(60, 0));
      await settle(tester);
      await tester.tap(find.text('Slide to accept order'));
      await settle(tester);
      expect(repo.calls, isEmpty);
    });

    testWidgets('every staff step has its own words: accept, start preparing, mark ready', (tester) async {
      final repo = FakeOps()
        ..style = OpsStatusStyle.slide
        ..overviewData = StaffOverview.fromJson({
          'branch': {'id': 'b1', 'name': 'Main', 'dispatch_mode': 'manual'},
          'new': 1,
          'preparing': 1,
          'ready': 0,
          'out_for_delivery': 0,
          'failed': 0,
          'waiting_for_driver': 0,
          'drivers_on_duty': 0,
          'drivers_total': 0,
        })
        ..queues = {
          'new': [FakeOps.order(id: 'f0', status: 'pending')],
          'preparing': [FakeOps.order(id: 'f3', status: 'accepted', number: 'AB100003')],
        };
      await _staff(tester, repo);
      // New and preparing orders are on the same To do list, each with its own slide words.
      expect(find.text('Slide to accept order'), findsOneWidget);
      expect(find.text('Slide to start preparing'), findsOneWidget);
      await tester.drag(find.byKey(slideThumbKey).last, const Offset(260, 0));
      await settle(tester);
      expect(repo.calls.last, 'advance:f3:preparing');
    });

    testWidgets('view only: no step, so no slide either', (tester) async {
      final repo = FakeOps()
        ..style = OpsStatusStyle.slide
        ..perms = const ['orders.view'];
      await _staff(tester, repo);
      expect(find.byType(SlideToConfirm), findsNothing);
    });

    testWidgets('the server refusing the step (409) shows its message and the slide is ready again', (tester) async {
      final repo = FakeOps()
        ..style = OpsStatusStyle.slide
        ..advanceError = conflict('This action is not allowed while the status is "Accepted".');
      await _staff(tester, repo);
      await _slide(tester);
      expect(find.text('This action is not allowed while the status is "Accepted".'), findsOneWidget);
      expect(find.byType(SlideToConfirm), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      repo.advanceError = null;
      await _slide(tester);
      expect(repo.calls, ['advance:f0:accepted', 'advance:f0:accepted']);
    });

    testWidgets('a slide that is still working cannot be started again', (tester) async {
      final repo = FakeOps()
        ..style = OpsStatusStyle.slide
        ..advanceGate = Completer<void>();
      await _staff(tester, repo);
      await tester.drag(find.byKey(slideThumbKey), const Offset(260, 0));
      await tester.pump(const Duration(milliseconds: 600));
      await tester.pump(const Duration(milliseconds: 600));
      await tester.drag(find.byKey(slideThumbKey), const Offset(-120, 0), warnIfMissed: false);
      await tester.drag(find.byKey(slideThumbKey), const Offset(260, 0), warnIfMissed: false);
      await tester.pump();
      expect(repo.calls, ['advance:f0:accepted']);
      repo.advanceGate!.complete();
      await settle(tester);
    });

    testWidgets('opening the order window: the same slide is pinned at the bottom', (tester) async {
      final repo = FakeOps()..style = OpsStatusStyle.slide;
      await _staff(tester, repo);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.text('Slide to accept order'), findsNWidgets(2), reason: 'on the card behind and in the window');
      await _slide(tester, within: find.byType(BottomSheet));
      expect(repo.calls, ['advance:f0:accepted']);
    });

    testWidgets('Arabic: the words are Arabic and the thumb slides to the left', (tester) async {
      final repo = FakeOps()..style = OpsStatusStyle.slide;
      await _staff(tester, repo, variant: const Variant('ar', Size(390, 844), kAr));
      expect(find.text('اسحب لقبول الطلب'), findsOneWidget);
      await _slide(tester, rtl: true);
      expect(repo.calls, ['advance:f0:accepted']);
    });

    testWidgets('assign driver and return to branch stay as they are in slide mode', (tester) async {
      final repo = FakeOps()..style = OpsStatusStyle.slide;
      await _staff(tester, repo);
      // The ready order is on the To do list; the slide belongs to the New order only.
      await tester.scrollUntilVisible(
        find.widgetWithText(FilledButton, 'Assign driver'),
        120,
        scrollable: find.descendant(of: find.byType(CustomScrollView), matching: find.byType(Scrollable)).first,
      );
      expect(find.widgetWithText(FilledButton, 'Assign driver'), findsOneWidget);
      expect(find.byType(SlideToConfirm), findsOneWidget);
      expect(find.text('Slide to accept order'), findsOneWidget);
    });

    testWidgets('a laid-out slide never overflows: phone, large text, Arabic, dark', (tester) async {
      for (final v in variants) {
        await tester.pumpWidget(const SizedBox.shrink());
        final repo = FakeOps()..style = OpsStatusStyle.slide;
        await _staff(tester, repo, variant: v);
        expect(tester.takeException(), isNull, reason: v.name);
        expect(find.byType(SlideToConfirm), findsOneWidget, reason: v.name);
        expect(tester.getSize(find.byType(SlideToConfirm)).height, greaterThanOrEqualTo(56), reason: v.name);
      }
    });

    testWidgets('the stored value is used before the server answers (and offline)', (tester) async {
      final prefs = await _prefs({OpsStatusStyleController.cacheKey: 'slide'});
      final repo = FakeOps()..styleError = apiError(ApiErrorKind.offline, 0);
      await _staff(tester, repo, prefs: prefs);
      expect(find.byType(SlideToConfirm), findsOneWidget);
      expect(find.text('Slide to accept order'), findsOneWidget);
    });
  });

  group('driver', () {
    FakeOps jobs(String status, {String cod = '0'}) => FakeOps()
      ..style = OpsStatusStyle.slide
      ..jobList = [FakeOps.job(status: status, cod: cod)];

    testWidgets('default: buttons everywhere', (tester) async {
      await _driver(tester, FakeOps());
      expect(find.widgetWithText(FilledButton, 'Accept'), findsOneWidget);
      expect(find.byType(SlideToConfirm), findsNothing);
    });

    for (final c in const [
      ('assigned', 'Slide to accept', 'accept:d1'),
      ('accepted', 'Slide: picked up', 'pickup:d1'),
      ('picked_up', 'Slide: on my way', 'out-for-delivery:d1'),
      ('failed', 'Slide to try again', 'out-for-delivery:d1'),
      ('rescheduled', 'Slide to try again', 'out-for-delivery:d1'),
    ]) {
      testWidgets('${c.$1}: ${c.$2}', (tester) async {
        final repo = jobs(c.$1);
        await _driver(tester, repo);
        expect(find.text(c.$2), findsOneWidget);
        await _slide(tester);
        expect(repo.calls, [c.$3]);
      });
    }

    testWidgets('Arabic words and direction', (tester) async {
      final repo = jobs('assigned');
      await _driver(tester, repo, variant: const Variant('ar', Size(390, 844), kAr));
      expect(find.text('اسحب للقبول'), findsOneWidget);
      await _slide(tester, rtl: true);
      expect(repo.calls, ['accept:d1']);
    });

    testWidgets('could not deliver is still a button with a reason and a confirmation', (tester) async {
      final repo = jobs('out_for_delivery');
      await _driver(tester, repo);
      expect(find.byTooltip('Could not deliver'), findsOneWidget);
      await tester.tap(find.byTooltip('Could not deliver'));
      await settle(tester);
      await tester.tap(find.text('Wrong address'));
      await settle(tester);
      expect(find.text('Report as not delivered?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'Report'));
      await settle(tester);
      expect(repo.calls, ['fail:d1:wrong_address']);
    });

    testWidgets('return to branch keeps its confirmation in slide mode too', (tester) async {
      final repo = jobs('failed');
      await _driver(tester, repo);
      await tester.tap(find.text('Return to branch'));
      await settle(tester);
      expect(find.text('Handed back at the branch?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.calls, isEmpty);
    });

    testWidgets(
      'out for delivery: Mark delivered is a button that opens the proof window, whose Confirm is the slide',
      (tester) async {
        final repo = jobs('out_for_delivery');
        final picker = FakePicker();
        await _driver(tester, repo, picker: picker);
        expect(find.byType(SlideToConfirm), findsNothing);
        await tester.tap(find.widgetWithText(FilledButton, 'Mark delivered'));
        await settle(tester);
        expect(find.text('Proof of delivery'), findsOneWidget);
        expect(find.widgetWithText(FilledButton, 'Confirm delivery'), findsNothing);
        expect(find.text('Slide to confirm delivery'), findsOneWidget);

        // No photo: the slide springs back, says why, and nothing was sent.
        await _slide(tester);
        expect(find.text('A proof photo is required.'), findsOneWidget);
        expect(repo.delivered, isEmpty);
        expect(find.text('Slide to confirm delivery'), findsOneWidget);

        await tester.tap(find.text('Take proof photo'));
        await settle(tester);
        await _slide(tester);
        expect(repo.delivered, hasLength(1));
        expect(repo.delivered.single.proof!.path, picker.next);
        expect(find.text('Proof of delivery'), findsNothing, reason: 'the window closed');
      },
    );

    testWidgets('a failed upload keeps the photo and the slide is ready for another try', (tester) async {
      final repo = jobs('out_for_delivery')..deliverError = apiError(ApiErrorKind.offline, 0);
      await _driver(tester, repo);
      await tester.tap(find.widgetWithText(FilledButton, 'Mark delivered'));
      await settle(tester);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await _slide(tester);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
      expect(find.text('Photo added'), findsOneWidget);
      repo.deliverError = null;
      await _slide(tester);
      expect(repo.delivered, hasLength(2));
    });

    testWidgets('cash on delivery: the amount is checked before the slide goes through', (tester) async {
      final repo = jobs('out_for_delivery', cod: '303.00');
      await _driver(tester, repo);
      await tester.tap(find.widgetWithText(FilledButton, 'Mark delivered'));
      await settle(tester);
      await tester.tap(find.text('Take proof photo'));
      await settle(tester);
      await tester.enterText(find.widgetWithText(TextField, 'Cash collected (QAR)'), '999');
      await _slide(tester);
      expect(find.text('More than the amount due (QAR 303.00).'), findsOneWidget);
      expect(repo.delivered, isEmpty);
    });

    testWidgets('buttons mode keeps Confirm delivery as the button it always was', (tester) async {
      final repo = FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery')];
      await _driver(tester, repo);
      await tester.tap(find.widgetWithText(FilledButton, 'Mark delivered'));
      await settle(tester);
      expect(find.widgetWithText(FilledButton, 'Confirm delivery'), findsOneWidget);
      expect(find.byType(SlideToConfirm), findsNothing);
    });

    testWidgets('the job window pins the same slide', (tester) async {
      final repo = jobs('assigned');
      await _driver(tester, repo);
      await tester.tap(find.text('Noura'));
      await settle(tester);
      expect(find.text('Delivery #AB100008'), findsOneWidget);
      await _slide(tester, within: find.byType(BottomSheet));
      expect(repo.calls, ['accept:d1']);
    });

    testWidgets('a laid-out slide never overflows next to the could-not-deliver button', (tester) async {
      for (final v in variants) {
        await tester.pumpWidget(const SizedBox.shrink());
        final repo = jobs('out_for_delivery');
        // Out for delivery shows "Mark delivered" (a button); the step before shows the slide beside the fail button.
        repo.jobList = [FakeOps.job(status: 'picked_up')];
        await _driver(tester, repo, variant: v);
        expect(tester.takeException(), isNull, reason: v.name);
        expect(find.byType(SlideToConfirm), findsOneWidget, reason: v.name);
        expect(find.byTooltip(v.rtl ? 'تعذّر التسليم' : 'Could not deliver'), findsOneWidget, reason: v.name);
      }
    });
  });
}
