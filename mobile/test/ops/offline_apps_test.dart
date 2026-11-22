import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/driver/driver_outbox.dart';
import 'package:albahar_customer/ops/driver/driver_outbox_provider.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_read_cache.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

/// What the driver and the branch staff see with no signal (docs/guides/OFFLINE_DRIVER_AND_STAFF_APPS.md): the driver's steps wait in a visible
/// outbox and the job moves at once; the branch tablet stays readable with its actions switched off.
Future<ProviderContainer> _driver(
  WidgetTester tester,
  FakeOps repo, {
  Variant variant = const Variant('390x844 en', Size(390, 844), kEn),
}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(
    opsHost(
      const DriverHome(),
      repo,
      variant: variant,
      overrides: [tokenStoreProvider.overrideWithValue(MemoryTokenStore())],
    ),
  );
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(DriverHome)));
}

DriverJob _job(String status, {String? branchPhone}) => FakeOps.job(
  status: status,
  cod: '0',
  extra: {
    'branch': {'id': 'b1', 'name': 'Al Markhiya', 'phone': ?branchPhone},
  },
);

ApiException get _offline => apiError(ApiErrorKind.offline, 0);

void main() {
  group('driver: a step pressed without signal', () {
    testWidgets(
      'moves the card at once, says "Waiting to send", and goes later in the order pressed with the same key',
      (tester) async {
        final repo = FakeOps()
          ..jobList = [_job('accepted')]
          ..stepError = _offline;
        await _driver(tester, repo);

        await tester.tap(find.widgetWithText(FilledButton, 'Picked up from branch'));
        await settle(tester);
        expect(
          find.text('No signal. Saved on the phone: it is sent by itself when the signal is back.'),
          findsOneWidget,
        );
        expect(find.text('Waiting to send'), findsWidgets);
        expect(find.text('1 update waiting to send'), findsOneWidget);
        // The card is already in the next step: "On my way" can be pressed, and waits behind the first.
        await tester.tap(find.widgetWithText(FilledButton, 'On my way'));
        await settle(tester);
        expect(find.text('2 updates waiting to send'), findsOneWidget);
        expect(repo.calls, ['pickup:d1']); // the second never went before the first

        await tester.tap(find.byKey(const ValueKey('outbox-open')));
        await settle(tester);
        expect(find.text('Updates waiting to send'), findsOneWidget);
        expect(find.text('#AB100008 · Picked up from branch'), findsOneWidget);
        expect(find.text('#AB100008 · On the way'), findsOneWidget);
        expect(
          find.textContaining('Pressed at '),
          findsAtLeastNWidgets(2),
        ); // in the list (and on the job card behind it)
        expect(find.text('Waits for the update above.'), findsOneWidget);

        repo.stepError = null;
        final firstKey = repo.stepKeys['pickup:d1'];
        await tester.tap(find.byKey(const ValueKey('outbox-send-now')));
        await settle(tester);
        expect(repo.calls, ['pickup:d1', 'pickup:d1', 'out-for-delivery:d1']);
        expect(repo.stepKeys['pickup:d1'], firstKey); // the retry is the same update, never a second one
        expect(repo.actedAts['pickup:d1'], isNotNull); // with the time the driver pressed
        expect(find.text('Everything has been sent.'), findsOneWidget);
      },
    );

    testWidgets('with signal a step goes straight away, as before: no outbox, no banner', (tester) async {
      final repo = FakeOps()..jobList = [_job('accepted')];
      final container = await _driver(tester, repo);

      await tester.tap(find.widgetWithText(FilledButton, 'Picked up from branch'));
      await settle(tester);

      expect(repo.calls, ['pickup:d1']);
      expect(container.read(driverOutboxProvider), isEmpty);
      expect(find.byKey(const ValueKey('outbox-banner')), findsNothing);
    });

    testWidgets('a refusal is said on the job and in the list, with Discard, Try again and Call the branch', (
      tester,
    ) async {
      final repo = FakeOps()
        ..jobList = [_job('accepted', branchPhone: '+97444001122')]
        ..stepError = _offline;
      final container = await _driver(tester, repo);
      await tester.tap(find.widgetWithText(FilledButton, 'Picked up from branch'));
      await settle(tester);

      repo.stepError = apiError(ApiErrorKind.conflict, 409, code: 'delivery_cancelled', message: 'Cancelled');
      await container.read(driverOutboxProvider.notifier).flush(force: true);
      await settle(tester);
      expect(find.text('1 update was not accepted'), findsOneWidget);
      expect(find.text('Not sent'), findsWidgets); // on the job card
      expect(find.text('This order was cancelled. No need to go.'), findsWidgets);

      await tester.tap(find.byKey(const ValueKey('outbox-open')));
      await settle(tester);
      final key = container.read(driverOutboxProvider).single.key;
      expect(find.text('Discard it, try again, or call the branch.'), findsOneWidget);
      expect(find.byKey(ValueKey('outbox-call-$key')), findsOneWidget);
      expect(find.byKey(ValueKey('outbox-retry-$key')), findsOneWidget);

      await tester.tap(find.byKey(ValueKey('outbox-discard-$key')));
      await settle(tester);
      expect(find.text('Discard this update?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'Discard'));
      await settle(tester);
      expect(container.read(driverOutboxProvider), isEmpty);
      expect(find.text('Everything has been sent.'), findsOneWidget);
    });

    testWidgets('the banner sits at the top and never covers the slide of a job', (tester) async {
      final repo = FakeOps()
        ..style = OpsStatusStyle.slide
        ..jobList = [
          FakeOps.job(id: 'd1', status: 'accepted', cod: '0'),
          FakeOps.job(id: 'd2', status: 'assigned', cod: '0'),
        ]
        ..stepError = _offline;
      final container = await _driver(tester, repo);
      await container
          .read(driverOutboxProvider.notifier)
          .press(repo.jobList.first, 'pickup', key: 'k-000000000000000001');
      await settle(tester);

      final banner = tester.getRect(find.byKey(const ValueKey('outbox-banner')));
      for (final slide in tester.widgetList(find.byType(SlideToConfirm))) {
        final rect = tester.getRect(find.byWidget(slide));
        expect(banner.overlaps(rect), isFalse);
      }
    });

    testWidgets('Arabic: the banner and the list read right to left in Arabic', (tester) async {
      final repo = FakeOps()
        ..jobList = [_job('accepted')]
        ..stepError = _offline;
      await _driver(tester, repo, variant: const Variant('390x844 ar', Size(390, 844), kAr));
      await tester.tap(find.widgetWithText(FilledButton, 'تم الاستلام من الفرع'));
      await settle(tester);
      expect(find.text('تحديث واحد بانتظار الإرسال'), findsOneWidget);
      expect(find.text('بانتظار الإرسال'), findsWidgets);
      await tester.tap(find.byKey(const ValueKey('outbox-open')));
      await settle(tester);
      expect(find.text('تحديثات بانتظار الإرسال'), findsOneWidget);
      expect(Directionality.of(tester.element(find.byKey(const ValueKey('outbox-send-now')))), TextDirection.rtl);
    });

    testWidgets('lists kept on the phone say "Last updated" with their time', (tester) async {
      final container = await _driver(tester, FakeOps());
      expect(find.byKey(const ValueKey('driver-last-updated')), findsNothing);

      container.read(opsStaleProvider.notifier).mark('driver.jobs.active', DateTime.utc(2026, 11, 19, 11, 5));
      await settle(tester);
      expect(
        find.textContaining('Last updated 2:05'),
        findsOneWidget,
      ); // 2:05 PM in Qatar (the time format puts a narrow space before PM)

      container.read(opsStaleProvider.notifier).mark('driver.jobs.active', null);
      await settle(tester);
      expect(find.byKey(const ValueKey('driver-last-updated')), findsNothing);
    });
  });

  group('driver: signing out with updates waiting', () {
    Future<ProviderContainer> withWaiting(WidgetTester tester, FakeOps repo) async {
      final container = await _driver(tester, repo);
      await container
          .read(driverOutboxProvider.notifier)
          .press(repo.jobList.first, 'pickup', key: 'k-000000000000000002');
      await settle(tester);
      await tester.tap(find.byTooltip('Menu'));
      await settle(tester);
      await tester.tap(find.text('Sign out'));
      await settle(tester);
      return container;
    }

    testWidgets('asks first; "Send first" that cannot send keeps the driver signed in and says so', (tester) async {
      final repo = FakeOps()
        ..jobList = [_job('accepted')]
        ..stepError = _offline;
      final container = await withWaiting(tester, repo);
      expect(find.text('1 update has not been sent. Send it first?'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('signout-send-first')));
      await settle(tester);

      expect(find.text('Some updates could not be sent yet. Try again when the signal is better.'), findsOneWidget);
      expect(container.read(driverOutboxProvider), hasLength(1));
      expect(find.byType(DriverHome), findsOneWidget);
    });

    testWidgets('"Send first" that sends goes on to the usual question', (tester) async {
      final repo = FakeOps()
        ..jobList = [_job('accepted')]
        ..stepError = _offline;
      final container = await withWaiting(tester, repo);
      repo.stepError = null;

      await tester.tap(find.byKey(const ValueKey('signout-send-first')));
      await settle(tester);

      expect(container.read(driverOutboxProvider), isEmpty);
      expect(find.text('Sign out and go off duty?'), findsOneWidget);
    });

    testWidgets('"Sign out anyway" drops the updates (they belong to this session) and signs out', (tester) async {
      final repo = FakeOps()
        ..jobList = [_job('accepted')]
        ..stepError = _offline;
      final container = await withWaiting(tester, repo);

      await tester.tap(find.byKey(const ValueKey('signout-anyway')));
      await settle(tester);

      expect(container.read(driverOutboxProvider), isEmpty);
      expect(repo.calls, contains('duty:false'));
    });

    testWidgets('Cancel changes nothing', (tester) async {
      final repo = FakeOps()
        ..jobList = [_job('accepted')]
        ..stepError = _offline;
      final container = await withWaiting(tester, repo);

      await tester.tap(find.text('Cancel'));
      await settle(tester);

      expect(container.read(driverOutboxProvider).single.state, OutboxState.waiting);
    });
  });

  group('branch staff: no connection', () {
    FakeOps branch() => FakeOps()
      ..overviewData = staffOverview(fresh: 1)
      ..queues = {
        'new': [FakeOps.order(id: 'f0', status: 'pending', number: 'AB100001')],
      };

    Future<ProviderContainer> staff(WidgetTester tester, FakeOps repo, {Locale locale = kEn}) async {
      final v = Variant('390x1400', const Size(390, 1400), locale);
      useVariant(tester, v);
      await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: v, role: OpsRole.staff));
      await settle(tester);
      return ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
    }

    testWidgets('the list stays readable with its time, and the actions say "Needs a connection" until it is back', (
      tester,
    ) async {
      final repo = branch();
      final container = await staff(tester, repo);
      expect(find.widgetWithText(FilledButton, 'Accept order'), findsWidgets);

      container.read(onlineProvider.notifier).set(false);
      container.read(opsStaleProvider.notifier).mark('staff.queue..new..1.50', DateTime.utc(2026, 11, 19, 11, 5));
      await settle(tester);

      expect(find.textContaining('You are offline. Showing the list from 2:05'), findsOneWidget);
      expect(find.text('#AB100001'), findsWidgets);
      expect(find.text('Needs a connection. Try again when the Wi-Fi is back.'), findsWidgets);
      final blocked = tester.widget<FilledButton>(
        find.descendant(of: find.byKey(const ValueKey('needs-connection')).first, matching: find.byType(FilledButton)),
      );
      expect(blocked.onPressed, isNull);

      container.read(onlineProvider.notifier).set(true);
      container.read(opsStaleProvider.notifier).clear();
      await settle(tester);
      expect(find.text('Needs a connection. Try again when the Wi-Fi is back.'), findsNothing);
      await tester.tap(find.widgetWithText(FilledButton, 'Accept order').first);
      await settle(tester);
      expect(repo.calls, contains('advance:f0:accepted'));
    });

    testWidgets('Arabic: the offline line and the switched-off action are in Arabic', (tester) async {
      final container = await staff(tester, branch(), locale: kAr);
      container.read(onlineProvider.notifier).set(false);
      await settle(tester);
      expect(find.text('أنت غير متصل. تُعرض آخر قائمة.'), findsOneWidget);
      expect(find.text('يحتاج إلى اتصال. حاول مرة أخرى عند عودة الشبكة.'), findsWidgets);
    });

    testWidgets('the server cannot be reached while the Wi-Fi is up: the kept list says so with its time', (
      tester,
    ) async {
      final container = await staff(tester, branch());
      container.read(opsStaleProvider.notifier).mark('staff.overview.', DateTime.utc(2026, 11, 19, 11, 5));
      await settle(tester);
      expect(find.textContaining('Could not refresh. Showing the list from 2:05'), findsOneWidget);
    });
  });
}
