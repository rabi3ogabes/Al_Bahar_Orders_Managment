import 'dart:async';

import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/driver/driver_job_map.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';

import '../helpers/map_fakes.dart';
import 'ops_test_kit.dart';

DriverJob _job({double? lat = 25.30, double? lng = 51.55, String status = 'out_for_delivery'}) => DriverJob.fromJson({
  'id': 'd1',
  'status': status,
  'order_number': 'AB100008',
  'customer_name': 'Noura',
  'customer_phone': '+97455123456',
  'address': {'zone_number': '24', 'street_number': '817', 'building_number': '12', 'latitude': lat, 'longitude': lng},
  'payment_method': 'card',
  'cod_amount': '0',
  'requires_proof': true,
  'branch': {'id': 'b1', 'name': 'Al Markhiya'},
});

GeoFix _fix(double lat, double lng, {double? speed, double? heading, double? accuracy = 12, int age = 0}) => GeoFix(
  lat: lat,
  lng: lng,
  speedKmh: speed,
  heading: heading,
  accuracyM: accuracy,
  ageSeconds: age,
  receivedAt: DateTime.now(),
);

void main() {
  group('the delivery map screen', () {
    Future<StreamController<GeoFix>> pump(
      WidgetTester t, {
      DriverJob? job,
      GeoFix? initial,
      Variant v = const Variant('default', Size(390, 844), kEn),
      VoidCallback? onNavigate,
    }) async {
      final fixes = StreamController<GeoFix>.broadcast();
      addTearDown(fixes.close);
      useVariant(t, v);
      await t.pumpWidget(
        opsHost(
          DriverJobMapScreen(
            job: job ?? _job(),
            fixes: fixes.stream,
            initialFix: initial,
            onNavigate: onNavigate ?? () {},
            card: const Card(child: ListTile(title: Text('JOB CARD'))),
          ),
          FakeOps(),
          variant: v,
        ),
      );
      await t.pump();
      await t.pump(const Duration(milliseconds: 200));
      return fixes;
    }

    testWidgets('with a position: the way to the address, distance, a rough arrival time and the job card', (t) async {
      await pump(t, initial: _fix(25.28, 51.52, speed: 0));
      expect(find.text('JOB CARD'), findsOneWidget);
      // About 3 km straight: at the 30 km/h default that is 6 minutes, and the text says it is an estimate.
      expect(find.textContaining('About '), findsOneWidget);
      expect(find.textContaining('to go'), findsOneWidget);
      expect(find.text('Estimated from the straight-line distance.'), findsOneWidget);
      expect(find.byType(MapPin), findsOneWidget);
      expect(find.byType(PolylineLayer), findsOneWidget);
      expect(find.byType(DriverMarkerView), findsOneWidget);
    });

    testWidgets('the arrival time uses the real speed once the phone is driving, and counts down as it closes in', (
      t,
    ) async {
      final fixes = await pump(t, initial: _fix(25.28, 51.52, speed: 60));
      String eta() => (t.widget<Text>(find.textContaining('About ')).data!);
      final far = int.parse(RegExp(r'\d+').firstMatch(eta())!.group(0)!);
      fixes.add(_fix(25.295, 51.545, speed: 60, age: 0));
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      final near = int.parse(RegExp(r'\d+').firstMatch(eta())!.group(0)!);
      expect(near, lessThan(far));
    });

    testWidgets('no position yet: it says it is looking, and the Navigate button is already there', (t) async {
      var navigated = 0;
      await pump(t, onNavigate: () => navigated++);
      expect(find.text('Finding your position…'), findsOneWidget);
      expect(find.byType(DriverMarkerView), findsNothing);
      expect(find.text('Navigate'), findsOneWidget);
      await t.tap(find.text('Navigate'));
      expect(navigated, 1);
    });

    testWidgets('a delivery without a map pin: it says so; the map and the Navigate button still work', (t) async {
      await pump(t, job: _job(lat: null, lng: null), initial: _fix(25.28, 51.52));
      expect(find.text('This delivery has no map pin. Use the address text to find it.'), findsOneWidget);
      expect(find.byType(MapPin), findsNothing);
      expect(find.byType(DriverMarkerView), findsOneWidget);
      expect(find.text('Navigate'), findsOneWidget);
    });

    testWidgets('a bad pin (outside the globe) is treated as no pin', (t) async {
      await pump(t, job: _job(lat: 400, lng: 51.55), initial: _fix(25.28, 51.52));
      expect(find.byType(MapPin), findsNothing);
      expect(t.takeException(), isNull);
    });

    testWidgets('new positions move the marker and lengthen the trail behind it', (t) async {
      final fixes = await pump(t, initial: _fix(25.280, 51.520, speed: 30, heading: 45));
      expect(find.byType(PolylineLayer), findsOneWidget); // only the dashed way to go
      for (var i = 1; i <= 3; i++) {
        fixes.add(_fix(25.280 + i * 0.002, 51.520 + i * 0.002, speed: 30, heading: 45));
        await t.pump();
        await t.pump(const Duration(seconds: 2));
      }
      expect(find.byType(PolylineLayer), findsNWidgets(2)); // the trail and the way to go
      final pin = t.widget<MarkerLayer>(find.byType(MarkerLayer).last).markers.single.point;
      expect(pin.latitude, closeTo(25.286, 1e-6));
    });

    testWidgets('a late, older position never pulls the marker back', (t) async {
      final now = DateTime.now();
      final fixes = await pump(
        t,
        initial: GeoFix(lat: 25.285, lng: 51.525, at: now, receivedAt: now),
      );
      fixes.add(
        GeoFix(
          lat: 25.200,
          lng: 51.400,
          at: now.subtract(const Duration(seconds: 30)),
          receivedAt: now.add(const Duration(seconds: 1)),
        ),
      );
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(t.widget<MarkerLayer>(find.byType(MarkerLayer).last).markers.single.point, const LatLng(25.285, 51.525));
    });

    testWidgets('leaving the screen cancels the position subscription', (t) async {
      final fixes = await pump(t, initial: _fix(25.28, 51.52));
      expect(fixes.hasListener, isTrue);
      await t.pumpWidget(const SizedBox.shrink());
      expect(fixes.hasListener, isFalse);
    });

    for (final v in variants) {
      testWidgets('lays out: ${v.name}; targets are 48 dp and labelled', (t) async {
        final handle = t.ensureSemantics();
        await pump(t, initial: _fix(25.28, 51.52, speed: 20, heading: 10), v: v);
        expect(t.takeException(), isNull);
        await expectLater(t, meetsGuideline(androidTapTargetGuideline));
        await expectLater(t, meetsGuideline(labeledTapTargetGuideline));
        expect(
          t.getSize(find.widgetWithText(FilledButton, v.locale == kAr ? 'الاتجاه' : 'Navigate')).height,
          greaterThanOrEqualTo(56),
        );
        handle.dispose();
      });
    }
  });

  group('from the driver app', () {
    Future<FakeOps> openHome(
      WidgetTester t, {
      FakeOps? repo,
      FakeLocation? location,
      FakeTracker? tracker,
      FakeNavigation? navigation,
    }) async {
      final ops = repo ?? FakeOps();
      useVariant(t, const Variant('default', Size(390, 844), kEn));
      await t.pumpWidget(
        opsHost(const DriverHome(), ops, location: location, tracker: tracker, navigation: navigation),
      );
      await settle(t);
      return ops;
    }

    testWidgets('a job card has a map button; it opens the delivery map with the card and a big Navigate', (t) async {
      final repo = await openHome(t);
      expect(find.byTooltip('Map'), findsOneWidget);
      await t.tap(find.byTooltip('Map'));
      await t.pumpAndSettle();
      expect(find.byType(DriverJobMapScreen), findsOneWidget);
      expect(find.text('Delivery map · #AB100008'), findsOneWidget);
      // Inside the map the card does not repeat Navigate and Map (the screen has its own big button).
      expect(find.widgetWithText(OutlinedButton, 'Navigate'), findsNothing);
      expect(find.widgetWithText(FilledButton, 'Navigate'), findsOneWidget);
      expect(find.byTooltip('Map'), findsNothing);
      expect(find.text('Accept'), findsOneWidget);
      // A job step taken from the map is the same step as on the list.
      await t.tap(find.text('Accept'));
      await t.pumpAndSettle();
      expect(repo.calls, contains('accept:d1'));
    });

    testWidgets('the map shows where the phone is, from the tracker', (t) async {
      final tracker = FakeTracker(FakeOps());
      await openHome(t, tracker: tracker);
      await t.tap(find.byTooltip('Map'));
      await t.pumpAndSettle();
      expect(find.text('Finding your position…'), findsOneWidget);
      expect(find.byType(DriverMarkerView), findsNothing);
    });

    testWidgets('when the delivery leaves the active list (delivered, reassigned) the map closes by itself', (t) async {
      final repo = await openHome(t);
      await t.tap(find.byTooltip('Map'));
      await t.pumpAndSettle();
      expect(find.byType(DriverJobMapScreen), findsOneWidget);
      repo.jobList = [];
      final container = ProviderScope.containerOf(t.element(find.byType(DriverJobMapScreen)));
      container.invalidate(driverJobsProvider(false));
      await t.pumpAndSettle();
      expect(find.byType(DriverJobMapScreen), findsNothing);
      expect(find.text('No deliveries yet'), findsOneWidget);
    });

    testWidgets('Navigate hands the trip to the navigation app; with none that opens it says so', (t) async {
      final phone = FakeNavigation();
      await openHome(t, navigation: phone);
      await t.tap(find.byTooltip('Map'));
      await t.pumpAndSettle();
      await t.tap(find.widgetWithText(FilledButton, 'Navigate'));
      await t.pumpAndSettle();
      expect(phone.opened.single.toString(), 'google.navigation:q=25.3,51.5&mode=d');
      expect(find.text('Could not open this on your phone.'), findsNothing);

      phone.opens = false;
      await t.tap(find.widgetWithText(FilledButton, 'Navigate'));
      await t.pump();
      await t.pump(const Duration(milliseconds: 500));
      expect(find.text('Could not open this on your phone.'), findsOneWidget);
    });

    testWidgets('a job without a pin still navigates by address text (a search), never a broken link', (t) async {
      final repo = FakeOps()
        ..jobList = [
          DriverJob.fromJson({
            'id': 'd2',
            'status': 'accepted',
            'order_number': 'AB100009',
            'customer_name': 'Hamad',
            'address': {'zone_number': '24', 'street_number': '9'},
            'payment_method': 'card',
            'cod_amount': '0',
            'branch': {'id': 'b1', 'name': 'Al Markhiya'},
          }),
        ];
      final phone = FakeNavigation();
      await openHome(t, repo: repo, navigation: phone);
      await t.tap(find.widgetWithText(OutlinedButton, 'Navigate'));
      await t.pumpAndSettle();
      expect(phone.opened.single.host, 'www.google.com');
      expect(phone.opened.single.path, '/maps/search/');
      expect(phone.opened.single.queryParameters['query'], contains('Zone 24'));
    });
  });
}
