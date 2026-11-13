import 'dart:async';

import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/fleet_logic.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';

import 'ops_test_kit.dart';

/// "Now" as the screens see it (they read the real clock), so ages in the fixtures are relative to it.
DateTime _now() => DateTime.now();

Map<String, dynamic> _driver(
  String id,
  String name, {
  double? lat,
  double? lng,
  double? speed,
  int? age,
  double? heading,
  bool onDuty = true,
  String? order,
  String? status,
  LatLng? destination,
  String? vehicle,
  String? phone,
}) => {
  'id': id,
  'name': name,
  'phone': phone,
  'vehicle': vehicle,
  'on_duty': onDuty,
  'lat': lat,
  'lng': lng,
  'heading': heading,
  'speed_kmh': speed,
  'accuracy_m': lat == null ? null : 9,
  'at': age == null ? null : _now().subtract(Duration(seconds: age)).toUtc().toIso8601String(),
  'age_seconds': age,
  'last_seen_at': age == null ? null : _now().subtract(Duration(seconds: age)).toUtc().toIso8601String(),
  'open_jobs': order == null ? 0 : 1,
  'job': order == null
      ? null
      : {
          'delivery_id': 'dl-$id',
          'status': status ?? 'out_for_delivery',
          'order_number': order,
          'destination': destination == null ? null : {'lat': destination.latitude, 'lng': destination.longitude},
        },
};

FleetDriver _fd(Map<String, dynamic> j) => FleetDriver.fromJson(j);

/// Five drivers, one in each state.
List<FleetDriver> _team() => [
  _fd(
    _driver(
      'u1',
      'Omar',
      lat: 25.280,
      lng: 51.520,
      speed: 38,
      heading: 90,
      age: 4,
      order: 'AB1',
      destination: const LatLng(25.30, 51.55),
    ),
  ),
  _fd(_driver('u2', 'Salem', lat: 25.290, lng: 51.530, speed: 0, age: 10)),
  _fd(_driver('u3', 'Khalid', lat: 25.270, lng: 51.510, speed: 20, age: 600, order: 'AB2')),
  _fd(_driver('u4', 'Hamad')), // on duty, never reported a position
  _fd(_driver('u5', 'Nasser', lat: 25.285, lng: 51.525, speed: 50, age: 2, vehicle: 'van')),
];

void main() {
  group('sorting and counting', () {
    test('moving first, then waiting, then not live, then no position; names break ties', () {
      final entries = classifyFleet(_team(), const {}, _now());
      expect(entries.map((e) => e.driver.name), ['Nasser', 'Omar', 'Salem', 'Khalid', 'Hamad']);
      expect(entries.map((e) => e.activity), [
        DriverActivity.moving,
        DriverActivity.moving,
        DriverActivity.idle,
        DriverActivity.stale,
        DriverActivity.noSignal,
      ]);
    });

    test('the chips count what they filter', () {
      final entries = classifyFleet(_team(), const {}, _now());
      final c = fleetCounts(entries);
      expect(c[FleetFilter.all], 5);
      expect(c[FleetFilter.moving], 2);
      expect(c[FleetFilter.idle], 1);
      expect(c[FleetFilter.stale], 1);
      expect(entries.where((e) => e.matches(FleetFilter.moving)).map((e) => e.driver.name), ['Nasser', 'Omar']);
    });

    test('the server staleness setting decides who is "not live"', () {
      final strict = classifyFleet(_team(), const {}, _now(), staleAfter: const Duration(seconds: 5));
      expect(
        fleetCounts(strict)[FleetFilter.stale],
        2,
      ); // Salem (10 s old) and Khalid (10 min); Omar (4 s) is still live
      final lax = classifyFleet(_team(), const {}, _now(), staleAfter: const Duration(hours: 1));
      expect(fleetCounts(lax)[FleetFilter.stale], 0);
    });

    test('a live push beats an older server position and loses to a newer one', () {
      final server = [_fd(_driver('u1', 'Omar', lat: 25.28, lng: 51.52, speed: 0, age: 60))];
      final t = _now();
      final pushNew = GeoFix(lat: 25.4, lng: 51.6, speedKmh: 30, ageSeconds: 0, at: t, receivedAt: t);
      var e = classifyFleet(server, {'u1': pushNew}, t).single;
      expect(e.point, const LatLng(25.4, 51.6));
      expect(e.activity, DriverActivity.moving);

      final pushOld = GeoFix(
        lat: 25.4,
        lng: 51.6,
        ageSeconds: 300,
        at: t.subtract(const Duration(minutes: 5)),
        receivedAt: t.subtract(const Duration(minutes: 5)),
      );
      e = classifyFleet(server, {'u1': pushOld}, t).single;
      expect(e.point, const LatLng(25.28, 51.52));
    });

    test('the fleet answer: the new fields parse, an old answer without them still works', () {
      final d = _team().first;
      expect(d.vehicle, isNull);
      expect(d.fix!.heading, 90);
      expect(d.fix!.speedKmh, 38);
      expect(d.fix!.accuracyM, 9);
      expect(d.destination, const LatLng(25.30, 51.55));
      expect(d.hasPosition, isTrue);
      final old = FleetDriver.fromJson({
        'id': 'u9',
        'name': 'Old',
        'on_duty': true,
        'lat': 25.2,
        'lng': 51.5,
        'last_seen_at': '2026-11-13T10:00:00Z',
        'open_jobs': 0,
      });
      expect(old.fix, isNotNull);
      expect(old.fix!.heading, isNull);
      expect(old.fix!.at, DateTime.utc(2026, 11, 13, 10));
      expect(old.destination, isNull);
      final none = FleetDriver.fromJson({
        'id': 'u8',
        'name': 'None',
        'on_duty': true,
        'lat': null,
        'lng': null,
        'open_jobs': 0,
      });
      expect(none.fix, isNull);
      expect(none.hasPosition, isFalse);
    });
  });

  group('the fleet tab', () {
    Future<FakeOps> pump(
      WidgetTester t, {
      List<FleetDriver>? drivers,
      Stream<Map<String, dynamic>> events = const Stream.empty(),
      Variant v = const Variant('default', Size(390, 844), kEn),
      List<Override> overrides = const [],
      FakeOps? repo,
    }) async {
      final ops = repo ?? FakeOps();
      if (drivers != null) ops.fleetList = drivers;
      useVariant(t, v);
      await t.pumpWidget(
        opsHost(
          Scaffold(body: FleetMapTab(events: events)),
          ops,
          variant: v,
          role: OpsRole.staff,
          overrides: overrides,
        ),
      );
      await t.pump();
      await t.pump(const Duration(milliseconds: 150));
      return ops;
    }

    LatLng cameraCentre(WidgetTester t) => MapCamera.of(t.element(find.byType(TileLayer))).center;
    double cameraZoom(WidgetTester t) => MapCamera.of(t.element(find.byType(TileLayer))).zoom;

    /// The first view frames the drivers with a short glide: wait for it before touching the map.
    Future<void> framed(WidgetTester t) async {
      await t.pump();
      await t.pump(const Duration(seconds: 2));
    }

    /// The chips scroll sideways on a small phone: bring one into view like a person would, then tap it.
    Future<void> chip(WidgetTester t, String label) async {
      await t.ensureVisible(find.text(label));
      await t.pump();
      await t.tap(find.text(label));
      await t.pump();
      await t.pump(const Duration(seconds: 2));
    }

    List<DriverMarkerView> pins(WidgetTester t) =>
        t.widgetList<DriverMarkerView>(find.byType(DriverMarkerView)).toList();

    testWidgets(
      'lays out everywhere: both languages, two phones and a tablet, text x1.3, dark; targets are 48 dp and labelled',
      (t) async {
        for (final v in variants) {
          final handle = t.ensureSemantics();
          await pump(t, drivers: _team(), v: v);
          expect(t.takeException(), isNull, reason: v.name);
          await expectLater(t, meetsGuideline(androidTapTargetGuideline));
          await expectLater(t, meetsGuideline(labeledTapTargetGuideline));
          handle.dispose();
          await t.pumpWidget(const SizedBox.shrink());
        }
      },
    );

    testWidgets('the first view frames every driver that has a position, clear of the list sheet', (t) async {
      await pump(t, drivers: _team());
      await t.pump(const Duration(seconds: 2));
      final cam = MapCamera.of(t.element(find.byType(TileLayer)));
      final size = t.getSize(find.byType(FlutterMap));
      expect(pins(t), hasLength(4)); // Hamad has no position
      for (final d in _team().where((d) => d.hasPosition)) {
        final o = cam.getOffsetFromOrigin(LatLng(d.lat!, d.lng!));
        expect(o.dx, inInclusiveRange(0, size.width), reason: d.name);
        // The list sheet covers the lower 30 % of the tab: the pins stay above it.
        expect(o.dy, lessThanOrEqualTo(size.height * 0.72), reason: '${d.name} must not hide behind the list');
        expect(o.dy, greaterThanOrEqualTo(0), reason: d.name);
      }
    });

    testWidgets('filter chips narrow the map and the list together, with counts, and the map reframes', (t) async {
      await pump(t, drivers: _team());
      await t.pump(const Duration(seconds: 1));
      expect(find.text('All · 5'), findsOneWidget);
      expect(find.text('Moving · 2'), findsOneWidget);
      expect(find.text('Waiting · 1'), findsOneWidget);
      expect(find.text('Not live · 1'), findsOneWidget);

      await chip(t, 'Moving · 2');
      expect(pins(t).map((p) => p.label).toSet(), {'Omar', 'Nasser'});
      expect(find.widgetWithText(ListTile, 'Salem'), findsNothing);
      expect(find.widgetWithText(ListTile, 'Omar'), findsOneWidget);

      await chip(t, 'Not live · 1');
      expect(pins(t).map((p) => p.label).toSet(), {'Khalid'});
      expect(pins(t).single.stale, isTrue);
      expect(find.text('Last seen 10 min ago'), findsOneWidget);

      await chip(t, 'All · 5');
      expect(pins(t), hasLength(4));
    });

    testWidgets('a filter with nobody in it says so, and the chip count is zero', (t) async {
      await pump(t, drivers: [_fd(_driver('u1', 'Omar', lat: 25.28, lng: 51.52, speed: 0, age: 5))]);
      expect(find.text('Moving · 0'), findsOneWidget);
      await chip(t, 'Moving · 0');
      expect(find.text('No drivers match this filter.'), findsOneWidget);
      expect(pins(t), isEmpty);
    });

    testWidgets('tapping a row flies the map to that driver and highlights the row', (t) async {
      await pump(t, drivers: _team());
      await t.pump(const Duration(seconds: 1));
      await t.tap(find.widgetWithText(ListTile, 'Salem'));
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(cameraCentre(t).latitude, closeTo(25.290, 1e-3));
      expect(cameraCentre(t).longitude, closeTo(51.530, 1e-3));
      expect(cameraZoom(t), greaterThanOrEqualTo(16));
      expect(t.widget<ListTile>(find.widgetWithText(ListTile, 'Salem')).selected, isTrue);
      expect(t.widget<ListTile>(find.widgetWithText(ListTile, 'Omar')).selected, isFalse);
      expect(pins(t).where((p) => p.selected).single.label, 'Salem');
    });

    testWidgets('tapping a pin selects that driver\'s row; a driver with no position cannot be tapped', (t) async {
      await pump(t, drivers: _team());
      await framed(t);
      await t.tap(find.byWidgetPredicate((w) => w is DriverMarkerView && w.label == 'Omar'));
      await t.pump();
      await t.pump(const Duration(milliseconds: 400));
      expect(t.widget<ListTile>(find.widgetWithText(ListTile, 'Omar')).selected, isTrue);
      expect(t.widget<ListTile>(find.widgetWithText(ListTile, 'Hamad')).onTap, isNull);
      expect(find.textContaining('No location yet'), findsOneWidget);
    });

    testWidgets('the selected driver\'s delivery shows: a dashed line to the address and the address pin', (t) async {
      await pump(t, drivers: _team());
      await t.pump(const Duration(seconds: 1));
      expect(find.byType(PolylineLayer), findsNothing);
      await t.tap(find.widgetWithText(ListTile, 'Omar'));
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(find.byType(PolylineLayer), findsOneWidget);
      // The camera frames the driver and the address together, so the address pin is on screen.
      expect(find.byType(MapPin), findsOneWidget);
      // Tapping the empty map clears it.
      await t.tapAt(const Offset(30, 200));
      await t.pump(const Duration(milliseconds: 400));
      expect(find.byType(PolylineLayer), findsNothing);
    });

    testWidgets(
      'a live push moves a pin; an older push is ignored; a driver the list did not have yet appears with the next refresh',
      (t) async {
        final events = StreamController<Map<String, dynamic>>.broadcast();
        addTearDown(events.close);
        final repo = await pump(
          t,
          drivers: [_fd(_driver('u1', 'Omar', lat: 25.28, lng: 51.52, speed: 0, age: 30))],
          events: events.stream,
        );
        LatLng pin() => t.widget<MarkerLayer>(find.byType(MarkerLayer)).markers.single.point;
        final now = _now();
        events.add({
          'driver_id': 'u1',
          'lat': 25.2812,
          'lng': 51.5212,
          'speed_kmh': 30,
          'heading': 45,
          'at': now.toUtc().toIso8601String(),
          'age_seconds': 0,
        });
        await t.pump();
        await t.pump(const Duration(seconds: 2));
        expect(pin().latitude, closeTo(25.2812, 1e-6));
        expect(pins(t).single.moving, isTrue);

        events.add({
          'driver_id': 'u1',
          'lat': 25.10,
          'lng': 51.30,
          'at': now.subtract(const Duration(minutes: 3)).toUtc().toIso8601String(),
          'age_seconds': 180,
        });
        await t.pump();
        await t.pump(const Duration(seconds: 2));
        expect(pin().latitude, closeTo(25.2812, 1e-6), reason: 'a late, older push never moves the pin back');

        events.add({'driver_id': 'u2', 'lat': 25.3, 'lng': 51.5, 'speed_kmh': 5}); // not in the list yet
        await t.pump();
        expect(pins(t), hasLength(1));
        repo.fleetList = [...repo.fleetList, _fd(_driver('u2', 'Salem', lat: 25.3, lng: 51.5, speed: 5, age: 1))];
        ProviderScope.containerOf(t.element(find.byType(FleetMapTab))).invalidate(staffFleetProvider);
        await t.pump();
        await t.pump(const Duration(seconds: 2));
        expect(pins(t), hasLength(2));
      },
    );

    testWidgets('a push without any time counts as a fix taken just now', (t) async {
      final events = StreamController<Map<String, dynamic>>.broadcast();
      addTearDown(events.close);
      await pump(
        t,
        drivers: [_fd(_driver('u1', 'Omar', lat: 25.28, lng: 51.52, speed: 0, age: 400))],
        events: events.stream,
      );
      expect(pins(t).single.stale, isTrue);
      events.add({'driver_id': 'u1', 'lat': 25.2801, 'lng': 51.5201});
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(pins(t).single.stale, isFalse);
    });

    testWidgets('a quiet driver turns grey with "last seen" in the list as well', (t) async {
      await pump(t, drivers: [_fd(_driver('u1', 'Khalid', lat: 25.27, lng: 51.51, speed: 20, age: 725))]);
      expect(find.textContaining('12 min ago'), findsWidgets);
      expect(pins(t).single.stale, isTrue);
    });

    testWidgets('over 40 drivers: close ones merge into count badges; a badge zooms in', (t) async {
      final crowd = [
        for (var i = 0; i < 45; i++)
          _fd(_driver('d$i', 'D$i', lat: 25.2805 + i * 0.00001, lng: 51.5200 + i * 0.00001, speed: 0, age: 5)),
      ];
      await pump(t, drivers: crowd);
      await t.pump(const Duration(seconds: 2));
      expect(find.byType(ClusterBadge), findsWidgets);
      expect(pins(t).length, lessThan(45));
      final before = cameraZoom(t);
      await t.tap(find.byType(ClusterBadge).first);
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(cameraZoom(t), greaterThan(before));
    });

    testWidgets('no drivers: an explanation you can pull to refresh', (t) async {
      final repo = await pump(t, drivers: const []);
      expect(find.text('No drivers to show'), findsOneWidget);
      final before = repo.fleetLoads;
      await t.drag(find.byType(ListView).first, const Offset(0, 400));
      await t.pump();
      await t.pump(const Duration(seconds: 1));
      expect(repo.fleetLoads, greaterThan(before));
    });

    testWidgets('the refresh button of the driver list asks the server again', (t) async {
      final repo = await pump(t, drivers: _team());
      await t.pump(const Duration(seconds: 1));
      final before = repo.fleetLoads;
      await t.tap(find.byTooltip('Refresh'));
      await t.pump();
      await t.pump(const Duration(seconds: 1));
      expect(repo.fleetLoads, greaterThan(before));
    });

    testWidgets('a failed refresh keeps the drivers on screen under a "could not refresh" strip', (t) async {
      final repo = await pump(t, drivers: _team());
      repo.fleetError = apiError(ApiErrorKind.offline, 0);
      ProviderScope.containerOf(t.element(find.byType(FleetMapTab))).invalidate(staffFleetProvider);
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      expect(find.textContaining('Could not refresh'), findsOneWidget);
      expect(pins(t), hasLength(4));
    });

    group('connection pill', () {
      testWidgets('Live when the socket is up, Reconnecting when it dropped, Connecting at the start', (t) async {
        for (final (state, text) in [
          (RealtimeState.connected, 'Live · 5'),
          (RealtimeState.reconnecting, 'Reconnecting… · 5'),
          (RealtimeState.connecting, 'Connecting… · 5'),
        ]) {
          await pump(
            t,
            drivers: _team(),
            overrides: [staffConnectionProvider.overrideWith((ref) => Stream.value(state))],
          );
          await t.pump();
          expect(find.text(text), findsOneWidget, reason: '$state');
          await t.pumpWidget(const SizedBox.shrink());
        }
      });

      testWidgets('Offline when the phone has no internet, whatever the socket says', (t) async {
        await pump(
          t,
          drivers: _team(),
          overrides: [
            staffConnectionProvider.overrideWith((ref) => Stream.value(RealtimeState.connected)),
            onlineProvider.overrideWith(_Offline.new),
          ],
        );
        await t.pump();
        expect(find.text('Offline · 5'), findsOneWidget);
      });
    });

    testWidgets('Arabic: filters, counts and row text are in Arabic, the pin labels too', (t) async {
      final handle = t.ensureSemantics();
      await pump(t, drivers: _team(), v: const Variant('ar', Size(390, 844), kAr));
      expect(find.text('الكل · 5'), findsOneWidget);
      expect(find.text('في حركة · 2'), findsOneWidget);
      expect(find.bySemanticsLabel('Omar، في حركة'), findsOneWidget);
      handle.dispose();
    });
  });

  group('inside the staff app', () {
    testWidgets('dragging the map sideways moves the map, not the tab', (t) async {
      final repo = FakeOps()..fleetList = _team();
      const v = Variant('default', Size(390, 844), kEn);
      useVariant(t, v);
      await t.pumpWidget(opsHost(const StaffHome(), repo, role: OpsRole.staff));
      await settle(t);
      // The map is the last tab.
      await t.ensureVisible(find.text('Live map'));
      await t.pump();
      await t.tap(find.text('Live map'));
      await settle(t);
      expect(find.text('All · 5'), findsOneWidget);
      final before = MapCamera.of(t.element(find.byType(TileLayer))).center;
      await t.dragFrom(const Offset(200, 330), const Offset(-150, 0));
      await settle(t);
      expect(find.text('All · 5'), findsOneWidget, reason: 'still on the map tab');
      expect(MapCamera.of(t.element(find.byType(TileLayer))).center, isNot(before));
    });
  });
}

class _Offline extends OnlineController {
  @override
  bool build() => false;
}
