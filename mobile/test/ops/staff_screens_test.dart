import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';

import 'ops_test_kit.dart';

Future<ProviderContainer> _pump(
  WidgetTester tester,
  FakeOps repo, {
  Variant variant = const Variant('default', Size(390, 844), kEn),
}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: variant, role: OpsRole.staff));
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
}

/// Opens the third tab (Ready); on a phone the scrollable tab bar has to be scrolled to it first.
Future<void> _openReady(WidgetTester tester, Variant v) async {
  final tab = find.byType(Tab).at(2);
  await tester.ensureVisible(tab);
  await tester.pump();
  await tester.tap(tab);
  await settle(tester);
}

Future<void> _openTab(WidgetTester tester, int index) async {
  final tab = find.byType(Tab).at(index);
  await tester.ensureVisible(tab);
  await tester.pump();
  await tester.tap(tab);
  await settle(tester);
}

void main() {
  group('branch staff home under every phone, language, text size and theme', () {
    for (final v in variants) {
      testWidgets('lays out without overflow: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pump(tester, FakeOps(), variant: v);
        expect(tester.takeException(), isNull);
        expect(find.text('Al Markhiya Main Branch'), findsOneWidget);
        await _openReady(tester, v);
        expect(find.text('#AB100009'), findsOneWidget);
        expect(find.textContaining(v.rtl ? 'المنطقة 24' : 'Zone 24'), findsOneWidget);
        expect(find.textContaining(v.rtl ? '120.00 ر.ق' : 'QAR 120.00'), findsOneWidget);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }
  });

  group('permissions decide which buttons exist', () {
    testWidgets('view only: no step buttons, no drivers or map, no add product', (tester) async {
      final repo = FakeOps()..perms = const ['orders.view'];
      await _pump(tester, repo);
      expect(find.text('Accept order'), findsNothing);
      expect(find.text('Drivers'), findsNothing);
      expect(find.text('Live map'), findsNothing);
      expect(find.byTooltip('Add product'), findsNothing);
      await _openReady(tester, variants.first);
      expect(find.text('Assign driver'), findsNothing);
      expect(find.text('#AB100009'), findsOneWidget);
    });

    testWidgets('order manager: steps, assigning, drivers and map', (tester) async {
      final repo = FakeOps()..perms = const ['orders.view', 'orders.edit', 'deliveries.view', 'deliveries.assign'];
      await _pump(tester, repo);
      expect(find.text('Accept order'), findsOneWidget);
      expect(find.text('Drivers'), findsOneWidget);
      expect(find.text('Live map'), findsOneWidget);
      expect(find.byTooltip('Add product'), findsNothing);
      await _openReady(tester, variants.first);
      expect(find.text('Assign driver'), findsOneWidget);
    });

    test('wildcards expand like the server', () {
      expect(hasPermission(const ['*'], 'orders.edit'), isTrue);
      expect(hasPermission(const ['orders.*'], 'orders.edit'), isTrue);
      expect(hasPermission(const ['orders.view'], 'orders.edit'), isFalse);
      expect(hasPermission(const <String>[], 'orders.view'), isFalse);
    });

    testWidgets('add product shows for catalog.create', (tester) async {
      final repo = FakeOps()..perms = const ['orders.view', 'catalog.create'];
      await _pump(tester, repo);
      expect(find.byTooltip('Add product'), findsOneWidget);
    });
  });

  group('order steps', () {
    testWidgets('accept moves the order and reloads the lists', (tester) async {
      final repo = FakeOps();
      await _pump(tester, repo);
      final before = repo.queueLoads;
      await tester.tap(find.text('Accept order'));
      await settle(tester);
      expect(repo.calls, ['advance:f0:accepted']);
      expect(repo.queueLoads, greaterThan(before));
    });

    testWidgets('409: a colleague already moved it - the message shows and the card reloads', (tester) async {
      final repo = FakeOps()..advanceError = conflict('This action is not allowed while the status is "Accepted".');
      await _pump(tester, repo);
      final before = repo.queueLoads;
      await tester.tap(find.text('Accept order'));
      await settle(tester);
      expect(find.text('This action is not allowed while the status is "Accepted".'), findsOneWidget);
      expect(repo.queueLoads, greaterThan(before));
    });

    testWidgets('403 shows the server message', (tester) async {
      final repo = FakeOps()
        ..advanceError = apiError(ApiErrorKind.forbidden, 403, message: 'Not allowed at this branch');
      await _pump(tester, repo);
      await tester.tap(find.text('Accept order'));
      await settle(tester);
      expect(find.text('Not allowed at this branch'), findsOneWidget);
    });

    testWidgets('a double tap sends one request', (tester) async {
      final repo = FakeOps()..advanceGate = Completer<void>();
      await _pump(tester, repo);
      await tester.tap(find.text('Accept order'));
      await tester.pump();
      await tester.tap(find.byType(FilledButton).last, warnIfMissed: false);
      await tester.pump();
      expect(repo.calls, ['advance:f0:accepted']);
      repo.advanceGate!.complete();
      await settle(tester);
    });

    testWidgets('assigning a driver: choose from the list, one request; off-duty drivers are marked', (tester) async {
      final repo = FakeOps();
      await _pump(tester, repo);
      await _openReady(tester, variants.first);
      await tester.tap(find.text('Assign driver'));
      await settle(tester);
      expect(find.text('Choose a driver'), findsOneWidget);
      expect(find.text('Off duty'), findsOneWidget);
      await tester.tap(find.text('Omar').last);
      await settle(tester);
      expect(repo.calls, ['assign:f1:u1']);
    });

    testWidgets('any listed driver can be picked (the server decides who may take the order)', (tester) async {
      final repo = FakeOps();
      await _pump(tester, repo);
      await _openReady(tester, variants.first);
      await tester.tap(find.text('Assign driver'));
      await settle(tester);
      await tester.tap(find.text('Salem'));
      await settle(tester);
      expect(repo.calls.single, 'assign:f1:u2');
    });

    testWidgets('a failed delivery stays visible and can be brought back to the branch (with a confirmation)', (
      tester,
    ) async {
      final repo = FakeOps()..perms = const ['orders.view', 'deliveries.edit'];
      await _pump(tester, repo);
      expect(find.textContaining('Failed · 1'), findsOneWidget);
      await _openTab(tester, 4);
      expect(find.text('#AB100010'), findsOneWidget);
      expect(find.text('Delivery failed'), findsOneWidget);
      await tester.tap(find.text('Return to branch'));
      await settle(tester);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.calls, isEmpty);
      await tester.tap(find.text('Return to branch'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm return'));
      await settle(tester);
      expect(repo.calls, ['return:dl-f2']);
    });

    testWidgets('without deliveries.edit there is no way to return a delivery', (tester) async {
      final repo = FakeOps()..perms = const ['orders.view'];
      await _pump(tester, repo);
      await _openTab(tester, 4);
      expect(find.text('#AB100010'), findsOneWidget);
      expect(find.text('Return to branch'), findsNothing);
    });

    testWidgets('a ready order with a driver offers Change driver, not a second assign', (tester) async {
      final repo = FakeOps()
        ..queues = {
          'ready': [FakeOps.order(driver: 'Omar')],
        };
      await _pump(tester, repo);
      await _openReady(tester, variants.first);
      expect(find.text('Driver: Omar'), findsOneWidget);
      expect(find.text('Change driver'), findsOneWidget);
    });
  });

  group('states', () {
    testWidgets('empty stage explains and can be pulled to refresh', (tester) async {
      final repo = FakeOps()..queues = {};
      await _pump(tester, repo);
      expect(find.text('Nothing here right now'), findsOneWidget);
      final before = repo.queueLoads;
      await tester.fling(find.text('Nothing here right now'), const Offset(0, 400), 1000);
      await settle(tester);
      expect(repo.queueLoads, greaterThan(before));
    });

    testWidgets('a failed refresh keeps the orders on screen with a notice', (tester) async {
      final repo = FakeOps();
      final container = await _pump(tester, repo);
      expect(find.text('#AB100001'), findsOneWidget);
      repo.queueError = apiError(ApiErrorKind.offline, 0);
      container.invalidate(staffQueueProvider('new'));
      await settle(tester);
      expect(find.text('#AB100001'), findsOneWidget);
      expect(find.text('Could not refresh. Showing the last list.'), findsOneWidget);
    });

    testWidgets('overview failing before anything loaded shows a retry', (tester) async {
      final repo = FakeOps()..overviewError = apiError(ApiErrorKind.server, 500, message: 'x');
      await _pump(tester, repo);
      expect(find.text('Try again'), findsWidgets);
      repo.overviewError = null;
      await tester.tap(find.text('Try again').first);
      await settle(tester);
      expect(find.text('Al Markhiya Main Branch'), findsOneWidget);
    });

    testWidgets('drivers tab: list with call buttons named after the driver; empty state', (tester) async {
      final repo = FakeOps();
      await _pump(tester, repo);
      await _openTab(tester, 5);
      expect(find.byTooltip('Call Omar'), findsOneWidget);
      expect(find.byTooltip('Call Salem'), findsNothing); // no phone number on file

      final empty = FakeOps()..branchDrivers = const [];
      await tester.pumpWidget(const SizedBox.shrink());
      await _pump(tester, empty);
      await _openTab(tester, 5);
      expect(find.textContaining('No drivers in this branch'), findsOneWidget);
    });
  });

  group('live map', () {
    Future<void> pumpMap(WidgetTester tester, FakeOps repo, Stream<Map<String, dynamic>> events, {Variant? v}) async {
      final variant = v ?? const Variant('default', Size(390, 844), kEn);
      useVariant(tester, variant);
      await tester.pumpWidget(
        opsHost(
          Scaffold(body: FleetMapTab(events: events)),
          repo,
          variant: variant,
          role: OpsRole.staff,
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
    }

    FleetDriver driver(String id, String name, {double? lat, double? lng, DateTime? seen, bool onDuty = true}) =>
        FleetDriver.fromJson({
          'id': id,
          'name': name,
          'on_duty': onDuty,
          'lat': lat,
          'lng': lng,
          'last_seen_at': seen?.toIso8601String(),
          'open_jobs': 0,
        });

    for (final v in variants) {
      testWidgets('lays out: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        final repo = FakeOps()
          ..fleetList = [
            driver('u1', 'Omar', lat: 25.28, lng: 51.52, seen: DateTime.now()),
            driver('u2', 'Salem'),
            driver('u3', 'Khalid', lat: 25.3, lng: 51.5, seen: DateTime.now().subtract(const Duration(hours: 3))),
          ];
        await pumpMap(tester, repo, const Stream.empty(), v: v);
        expect(tester.takeException(), isNull);
        expect(find.text('Omar'), findsWidgets);
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }

    testWidgets('no drivers: an explanation, not an empty map', (tester) async {
      await pumpMap(tester, FakeOps(), const Stream.empty());
      expect(find.text('No drivers to show'), findsOneWidget);
    });

    testWidgets('a newer refresh beats an older live ping, so a pin is never stuck on a stale spot', (tester) async {
      final events = StreamController<Map<String, dynamic>>.broadcast();
      addTearDown(events.close);
      final repo = FakeOps()
        ..fleetList = [
          driver('u1', 'Omar', lat: 25.28, lng: 51.52, seen: DateTime.now().subtract(const Duration(hours: 1))),
        ];
      await pumpMap(tester, repo, events.stream);
      events.add({'driver_id': 'u1', 'lat': 25.4, 'lng': 51.6});
      await tester.pump();
      // The pin glides to the new spot over about a second instead of jumping.
      await tester.pump(const Duration(seconds: 2));
      LatLng pin() => tester.widget<MarkerLayer>(find.byType(MarkerLayer)).markers.single.point;
      expect(pin().latitude, 25.4);

      // The server now reports a position heard from after the live ping.
      repo.fleetList = [
        driver('u1', 'Omar', lat: 25.31, lng: 51.55, seen: DateTime.now().add(const Duration(minutes: 5))),
      ];
      final container = ProviderScope.containerOf(tester.element(find.byType(FleetMapTab)));
      container.invalidate(staffFleetProvider);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      await tester.pump(const Duration(seconds: 2));
      expect(pin().latitude, 25.31);
    });

    testWidgets('the map asks for the branch the app is showing', (tester) async {
      final repo = FakeOps()..fleetList = [driver('u1', 'Omar', lat: 25.28, lng: 51.52, seen: DateTime.now())];
      useVariant(tester, const Variant('default', Size(390, 844), kEn));
      await tester.pumpWidget(opsHost(const StaffHome(), repo, role: OpsRole.staff));
      await settle(tester);
      await _openTab(tester, 6);
      expect(repo.fleetBranch, 'b1');
    });
  });
}
