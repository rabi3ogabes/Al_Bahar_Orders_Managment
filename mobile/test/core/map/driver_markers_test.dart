import 'package:albahar_customer/core/map/al_bahar_map.dart';
import 'package:albahar_customer/core/map/driver_markers_layer.dart';
import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';

import '../../helpers/map_fakes.dart';

final _t0 = DateTime.utc(2026, 11, 13, 12);

GeoFix _fix(double lat, double lng, {double? heading, double? speed, int? age, DateTime? at, DateTime? received}) =>
    GeoFix(lat: lat, lng: lng, heading: heading, speedKmh: speed, ageSeconds: age, at: at, receivedAt: received ?? _t0);

/// A map with the driver markers; `drivers` can be changed between pumps.
class _Rig extends StatefulWidget {
  const _Rig({super.key, required this.drivers, this.now, this.onTap, this.onCluster, this.zoom = 14});
  final ValueNotifier<List<DriverMarkerData>> drivers;
  final DateTime Function()? now;
  final void Function(String id)? onTap;
  final void Function(LatLng center, List<String> ids)? onCluster;
  final double zoom;

  @override
  State<_Rig> createState() => _RigState();
}

class _RigState extends State<_Rig> {
  @override
  Widget build(BuildContext context) => ValueListenableBuilder<List<DriverMarkerData>>(
    valueListenable: widget.drivers,
    builder: (context, list, _) => AlBaharMap(
      controls: MapControlsConfig.none,
      showAttribution: false,
      initialCenter: const LatLng(25.28, 51.52),
      initialZoom: widget.zoom,
      children: [
        DriverMarkersLayer(
          drivers: list,
          now: widget.now ?? () => _t0,
          onTap: widget.onTap,
          onCluster: widget.onCluster,
        ),
      ],
    ),
  );
}

List<Marker> _markers(WidgetTester t) => t.widget<MarkerLayer>(find.byType(MarkerLayer)).markers;

LatLng _only(WidgetTester t) => _markers(t).single.point;

DriverMarkerView _view(WidgetTester t) => t.widget<DriverMarkerView>(find.byType(DriverMarkerView));

void _phone(WidgetTester t) {
  t.view.physicalSize = const Size(400, 700);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
}

void main() {
  group('gliding', () {
    testWidgets('a new position glides in about a second: start, between, end; never a jump', (t) async {
      _phone(t);
      final list = ValueNotifier([DriverMarkerData(id: 'd1', fix: _fix(25.280, 51.520, age: 0), name: 'Omar')]);
      await t.pumpWidget(mapHost(_Rig(drivers: list)));
      await t.pump();
      expect(_only(t), const LatLng(25.280, 51.520));

      list.value = [
        DriverMarkerData(
          id: 'd1',
          fix: _fix(25.290, 51.540, age: 0, received: _t0.add(const Duration(seconds: 3))),
          name: 'Omar',
        ),
      ];
      await t.pump();
      expect(_only(t).latitude, closeTo(25.280, 1e-6), reason: 'the first frame still shows the old spot');
      await t.pump(const Duration(milliseconds: 500));
      final mid = _only(t);
      expect(mid.latitude, inExclusiveRange(25.280, 25.290));
      expect(mid.longitude, inExclusiveRange(51.520, 51.540));
      await t.pump(const Duration(milliseconds: 700));
      expect(_only(t), const LatLng(25.290, 51.540));
    });

    testWidgets('the arrow turns the short way: 350 to 10 passes through north, not south', (t) async {
      _phone(t);
      final list = ValueNotifier([
        DriverMarkerData(id: 'd1', fix: _fix(25.28, 51.52, heading: 350, speed: 30, age: 0), name: 'Omar'),
      ]);
      await t.pumpWidget(mapHost(_Rig(drivers: list)));
      await t.pump();
      expect(_view(t).heading, closeTo(350, 1e-6));
      list.value = [
        DriverMarkerData(
          id: 'd1',
          fix: _fix(25.281, 51.521, heading: 10, speed: 30, age: 0, received: _t0.add(const Duration(seconds: 3))),
          name: 'Omar',
        ),
      ];
      await t.pump();
      await t.pump(const Duration(milliseconds: 500));
      final h = _view(t).heading!;
      expect(h > 340 || h < 20, isTrue, reason: 'mid-turn heading $h must be near north, never near south');
      await t.pump(const Duration(seconds: 1));
      expect(_view(t).heading, closeTo(10, 1e-6));
    });

    testWidgets('a report older than the one shown is ignored (out of order)', (t) async {
      _phone(t);
      final list = ValueNotifier([
        DriverMarkerData(
          id: 'd1',
          fix: _fix(25.290, 51.540, at: _t0.add(const Duration(seconds: 10)), age: 0),
          name: 'Omar',
        ),
      ]);
      await t.pumpWidget(mapHost(_Rig(drivers: list)));
      await t.pump();
      list.value = [
        DriverMarkerData(
          id: 'd1',
          fix: _fix(25.270, 51.500, at: _t0, age: 0, received: _t0.add(const Duration(seconds: 5))),
          name: 'Omar',
        ),
      ];
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(_only(t), const LatLng(25.290, 51.540));
    });

    testWidgets('a new target mid-glide continues from where the marker is: no jump back', (t) async {
      _phone(t);
      final list = ValueNotifier([DriverMarkerData(id: 'd1', fix: _fix(25.280, 51.520, age: 0), name: 'Omar')]);
      await t.pumpWidget(mapHost(_Rig(drivers: list)));
      await t.pump();
      list.value = [
        DriverMarkerData(
          id: 'd1',
          fix: _fix(25.300, 51.560, age: 0, received: _t0.add(const Duration(seconds: 3))),
          name: 'Omar',
        ),
      ];
      await t.pump();
      await t.pump(const Duration(milliseconds: 600));
      final here = _only(t);
      list.value = [
        DriverMarkerData(
          id: 'd1',
          fix: _fix(25.320, 51.500, age: 0, received: _t0.add(const Duration(seconds: 6))),
          name: 'Omar',
        ),
      ];
      await t.pump();
      final after = _only(t);
      expect(after.latitude, closeTo(here.latitude, 1e-6));
      expect(after.longitude, closeTo(here.longitude, 1e-6));
      await t.pump(const Duration(seconds: 2));
      expect(_only(t), const LatLng(25.320, 51.500));
    });

    testWidgets('reduced motion: the marker moves at once', (t) async {
      _phone(t);
      final list = ValueNotifier([DriverMarkerData(id: 'd1', fix: _fix(25.280, 51.520, age: 0), name: 'Omar')]);
      await t.pumpWidget(mapHost(_Rig(drivers: list), reduceMotion: true));
      await t.pump();
      list.value = [
        DriverMarkerData(
          id: 'd1',
          fix: _fix(25.290, 51.540, age: 0, received: _t0.add(const Duration(seconds: 3))),
          name: 'Omar',
        ),
      ];
      await t.pump();
      expect(_only(t), const LatLng(25.290, 51.540));
    });

    testWidgets('drivers come and go without leftovers', (t) async {
      _phone(t);
      final list = ValueNotifier([
        DriverMarkerData(id: 'a', fix: _fix(25.280, 51.520, age: 0), name: 'A'),
        DriverMarkerData(id: 'b', fix: _fix(25.282, 51.522, age: 0), name: 'B'),
      ]);
      await t.pumpWidget(mapHost(_Rig(drivers: list)));
      await t.pump();
      expect(_markers(t), hasLength(2));
      list.value = [list.value.first];
      await t.pump();
      expect(_markers(t), hasLength(1));
      list.value = [];
      await t.pump();
      expect(_markers(t), isEmpty);
    });
  });

  group('how a marker looks and what it says', () {
    testWidgets('live and standing: not stale, no "last seen"; the label is the status', (t) async {
      _phone(t);
      final handle = t.ensureSemantics();
      final list = ValueNotifier([DriverMarkerData(id: 'd1', fix: _fix(25.28, 51.52, speed: 0, age: 5), name: 'Omar')]);
      await t.pumpWidget(mapHost(_Rig(drivers: list), overrides: mapTestOverrides()));
      await t.pump();
      expect(_view(t).stale, isFalse);
      expect(_view(t).moving, isFalse);
      expect(_view(t).lastSeen, isNull);
      expect(find.bySemanticsLabel('Omar, Waiting'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('moving: the ring pulses; with reduced motion or a stale position it does not', (t) async {
      _phone(t);
      DriverMarkerPainter painter() =>
          t
                  .widget<CustomPaint>(
                    find.descendant(of: find.byType(DriverMarkerView), matching: find.byType(CustomPaint)),
                  )
                  .painter!
              as DriverMarkerPainter;
      final list = ValueNotifier([
        DriverMarkerData(id: 'd1', fix: _fix(25.28, 51.52, speed: 40, heading: 90, age: 2), name: 'Omar'),
      ]);
      await t.pumpWidget(mapHost(_Rig(drivers: list)));
      await t.pump();
      await t.pump(const Duration(milliseconds: 400));
      expect(_view(t).moving, isTrue);
      expect(painter().pulse, isNotNull);

      await t.pumpWidget(mapHost(_Rig(drivers: list), reduceMotion: true));
      await t.pump();
      expect(painter().pulse, isNull);

      list.value = [
        DriverMarkerData(id: 'd1', fix: _fix(25.28, 51.52, speed: 40, heading: 90, age: 300), name: 'Omar'),
      ];
      await t.pumpWidget(mapHost(_Rig(drivers: list)));
      await t.pump();
      expect(painter().pulse, isNull);
    });

    testWidgets('older than 90 seconds: grey, with "Last seen 3 min ago", also in the screen-reader label', (t) async {
      _phone(t);
      final handle = t.ensureSemantics();
      final list = ValueNotifier([
        DriverMarkerData(id: 'd1', fix: _fix(25.28, 51.52, speed: 40, age: 200), name: 'Omar'),
      ]);
      await t.pumpWidget(mapHost(_Rig(drivers: list, now: () => _t0)));
      await t.pump();
      expect(_view(t).stale, isTrue);
      expect(_view(t).moving, isFalse, reason: 'a stale marker never claims to be moving');
      expect(find.text('Last seen 3 min ago'), findsOneWidget);
      expect(find.bySemanticsLabel('Omar, Last seen 3 min ago'), findsOneWidget);
      final colors = DriverMarkerColors.of(t.element(find.byType(DriverMarkerView)), stale: true);
      final live = DriverMarkerColors.of(t.element(find.byType(DriverMarkerView)), stale: false);
      expect(colors.fill, isNot(live.fill));
      handle.dispose();
    });

    testWidgets('a marker ages on its own: live now, grey once the clock passes the threshold, with no new data', (
      t,
    ) async {
      _phone(t);
      var now = _t0;
      final list = ValueNotifier([DriverMarkerData(id: 'd1', fix: _fix(25.28, 51.52, age: 0), name: 'Omar')]);
      await t.pumpWidget(mapHost(_Rig(drivers: list, now: () => now)));
      await t.pump();
      expect(_view(t).stale, isFalse);
      now = _t0.add(const Duration(seconds: 100));
      await t.pump(const Duration(seconds: 11));
      expect(_view(t).stale, isTrue);
      expect(find.textContaining('Last seen'), findsOneWidget);
    });

    testWidgets('the server staleness setting moves the threshold', (t) async {
      _phone(t);
      await t.pumpWidget(
        mapHost(
          AlBaharMap(
            controls: MapControlsConfig.none,
            showAttribution: false,
            children: [
              DriverMarkersLayer(
                drivers: [DriverMarkerData(id: 'd1', fix: _fix(25.28, 51.52, age: 100), name: 'Omar')],
                now: () => _t0,
                staleAfter: const Duration(seconds: 180),
              ),
            ],
          ),
        ),
      );
      await t.pump();
      expect(_view(t).stale, isFalse);
    });

    testWidgets('the selected driver is drawn last (on top); a tap reports its id', (t) async {
      _phone(t);
      String? tapped;
      final list = ValueNotifier([
        DriverMarkerData(id: 'sel', fix: _fix(25.280, 51.520, age: 0), name: 'Selected', selected: true),
        DriverMarkerData(id: 'b', fix: _fix(25.2801, 51.5201, age: 0), name: 'B'),
      ]);
      await t.pumpWidget(mapHost(_Rig(drivers: list, onTap: (id) => tapped = id)));
      await t.pump();
      final layer = _markers(t);
      expect((layer.last.child as DriverMarkerView).selected, isTrue);
      await t.tap(find.byType(DriverMarkerView).last, warnIfMissed: false);
      expect(tapped, 'sel');
    });

    testWidgets('lays out in Arabic, dark, text x1.3 without overflow', (t) async {
      _phone(t);
      final list = ValueNotifier([
        DriverMarkerData(
          id: 'd1',
          fix: _fix(25.28, 51.52, speed: 30, heading: 45, age: 400),
          name: 'عمر بن عبد العزيز الكبير جداً',
          vehicle: 'van',
        ),
        DriverMarkerData(
          id: 'd2',
          fix: _fix(25.2802, 51.5203, speed: 30, heading: 270, age: 1),
          name: 'Omar',
          vehicle: 'Toyota Camry sedan',
        ),
      ]);
      await t.pumpWidget(
        mapHost(
          _Rig(drivers: list),
          locale: const Locale('ar'),
          mode: ThemeMode.dark,
          textScale: 1.3,
        ),
      );
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      expect(t.takeException(), isNull);
      expect(find.byType(DriverMarkerView), findsNWidgets(2));
    });
  });

  group('many drivers', () {
    List<DriverMarkerData> crowd(int n) => [
      for (var i = 0; i < n; i++)
        DriverMarkerData(id: 'd$i', fix: _fix(25.2805 + i * 0.00001, 51.5200 + i * 0.00001, age: 0), name: 'D$i'),
    ];

    testWidgets('40 drivers stay separate; 45 close together merge into a count badge that opens on tap', (t) async {
      _phone(t);
      await t.pumpWidget(mapHost(_Rig(drivers: ValueNotifier(crowd(40)), zoom: 12)));
      await t.pump();
      expect(find.byType(ClusterBadge), findsNothing);
      expect(find.byType(DriverMarkerView), findsNWidgets(40));

      LatLng? opened;
      List<String>? ids;
      await t.pumpWidget(
        mapHost(
          _Rig(
            key: UniqueKey(),
            drivers: ValueNotifier(crowd(45)),
            zoom: 12,
            onCluster: (c, i) {
              opened = c;
              ids = i;
            },
          ),
        ),
      );
      await t.pump();
      expect(find.byType(ClusterBadge), findsOneWidget);
      expect(find.text('45'), findsOneWidget);
      expect(find.byType(DriverMarkerView), findsNothing);
      await t.tap(find.byType(ClusterBadge));
      expect(ids, hasLength(45));
      expect(opened, isNotNull);
    });

    testWidgets('the selected driver stays a single marker even inside a crowd', (t) async {
      _phone(t);
      final data = crowd(50);
      data[7] = DriverMarkerData(id: data[7].id, fix: data[7].fix, name: data[7].name, selected: true);
      await t.pumpWidget(mapHost(_Rig(drivers: ValueNotifier(data), zoom: 12)));
      await t.pump();
      expect(find.byType(DriverMarkerView), findsOneWidget);
      expect(find.text('49'), findsOneWidget);
    });
  });

  group('vehicle glyph', () {
    test('words from the server pick the glyph; unknown is a bike', () {
      expect(vehicleKindOf('motorcycle'), VehicleKind.bike);
      expect(vehicleKindOf('Scooter'), VehicleKind.bike);
      expect(vehicleKindOf(null), VehicleKind.bike);
      expect(vehicleKindOf(''), VehicleKind.bike);
      expect(vehicleKindOf('Toyota Camry sedan'), VehicleKind.car);
      expect(vehicleKindOf('Nissan Patrol SUV'), VehicleKind.car);
      expect(vehicleKindOf('Hilux pickup'), VehicleKind.van);
      expect(vehicleKindOf('delivery van'), VehicleKind.van);
      expect(vehicleKindOf('شاحنة'), VehicleKind.van);
    });

    test('the painter repaints only when something visible changed', () {
      const colors = DriverMarkerColors(fill: Colors.red, glyph: Colors.white, halo: Colors.white);
      const a = DriverMarkerPainter(colors: colors, vehicle: VehicleKind.bike, heading: 10);
      expect(
        a.shouldRepaint(const DriverMarkerPainter(colors: colors, vehicle: VehicleKind.bike, heading: 10)),
        isFalse,
      );
      expect(
        a.shouldRepaint(const DriverMarkerPainter(colors: colors, vehicle: VehicleKind.bike, heading: 11)),
        isTrue,
      );
      expect(a.shouldRepaint(const DriverMarkerPainter(colors: colors, vehicle: VehicleKind.car, heading: 10)), isTrue);
      expect(
        a.shouldRepaint(const DriverMarkerPainter(colors: colors, vehicle: VehicleKind.bike, heading: 10, pulse: 0.3)),
        isTrue,
      );
    });

    testWidgets('all three glyphs and a pin paint without errors', (t) async {
      _phone(t);
      await t.pumpWidget(
        mapHost(
          const Column(
            children: [
              DriverMarkerView(label: 'a', semanticsLabel: 'a', vehicle: VehicleKind.bike, heading: 0),
              DriverMarkerView(label: 'b', semanticsLabel: 'b', vehicle: VehicleKind.car, heading: 120),
              DriverMarkerView(
                label: 'c',
                semanticsLabel: 'c',
                vehicle: VehicleKind.van,
                heading: 250,
                stale: true,
                lastSeen: 'Last seen 5 min ago',
              ),
              MapPin(kind: PinKind.branch, semanticsLabel: 'Branch'),
              MapPin(kind: PinKind.destination, semanticsLabel: 'Home', selected: true),
              MapPin(kind: PinKind.picked, semanticsLabel: 'Pin'),
              MyLocationDot(semanticsLabel: 'Me', heading: 30),
            ],
          ),
        ),
      );
      await t.pump(const Duration(milliseconds: 300));
      expect(t.takeException(), isNull);
    });
  });
}
