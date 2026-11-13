import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/branch_widgets.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/profile/presentation/map_pin_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';

import '../helpers/map_fakes.dart';
import '../ops/ops_test_kit.dart' show FakeLocation, Variant, kAr, kEn, variants;

void main() {
  Future<Future<LatLng?> Function()> open(
    WidgetTester t, {
    LatLng? initial,
    FakeLocation? location,
    Variant v = const Variant('d', Size(390, 844), kEn),
  }) async {
    LatLng? result;
    t.view.physicalSize = v.size;
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await t.pumpWidget(
      mapHost(
        Builder(
          builder: (context) => Center(
            child: ElevatedButton(
              onPressed: () async =>
                  result = await Navigator.of(context)
                      .push<LatLng>(MaterialPageRoute(builder: (_) => MapPinPicker(initial: initial))),
              child: const Text('open'),
            ),
          ),
        ),
        locale: v.locale,
        textScale: v.scale,
        mode: v.dark ? ThemeMode.dark : ThemeMode.light,
        overrides: [...mapTestOverrides(), locationServiceProvider.overrideWithValue(location ?? FakeLocation())],
      ),
    );
    return () async => result;
  }

  Future<void> show(WidgetTester t) async {
    await t.tap(find.text('open'));
    await t.pumpAndSettle();
  }

  MapCamera camera(WidgetTester t) => MapCamera.of(t.element(find.byType(TileLayer)));

  testWidgets('a spot in Qatar can be confirmed and returns the middle of the map', (t) async {
    final result = await open(t, initial: const LatLng(25.3, 51.5));
    await show(t);
    expect(find.text('Confirm location'), findsOneWidget);
    expect(find.text('This spot is outside Qatar. Move the map to your address.'), findsNothing);
    await t.tap(find.text('Confirm location'));
    await t.pumpAndSettle();
    final r = (await result())!;
    expect(r.latitude, closeTo(25.3, 1e-6));
    expect(r.longitude, closeTo(51.5, 1e-6));
  });

  testWidgets('a spot abroad cannot be confirmed and the screen says why; moving back into Qatar allows it', (t) async {
    final result = await open(t, initial: const LatLng(25.2048, 55.2708)); // Dubai
    await show(t);
    expect(find.text('This spot is outside Qatar. Move the map to your address.'), findsOneWidget);
    expect(t.widget<FilledButton>(find.widgetWithText(FilledButton, 'Confirm location')).onPressed, isNull);
    await t.tap(find.text('Confirm location'), warnIfMissed: false);
    await t.pumpAndSettle();
    expect(await result(), isNull);
    expect(find.text('Confirm location'), findsOneWidget, reason: 'still on the picker');
  });

  testWidgets('the pin stays in the middle: its tip is the centre of the map, and it lifts while the map is dragged', (
    t,
  ) async {
    await open(t, initial: const LatLng(25.3, 51.5));
    await show(t);
    final map = t.getRect(find.byType(FlutterMap));
    final pin = t.getRect(find.byType(MapPin));
    expect(pin.bottomCenter.dx, closeTo(map.center.dx, 1));
    expect(pin.bottomCenter.dy, closeTo(map.center.dy, 1));
    final gesture = await t.startGesture(map.center + const Offset(0, 80));
    await gesture.moveBy(const Offset(0, -30));
    await t.pump(const Duration(milliseconds: 50));
    await gesture.moveBy(const Offset(0, -30));
    await t.pump(const Duration(milliseconds: 150));
    expect(t.getRect(find.byType(MapPin)).bottom, lessThan(pin.bottom - 5), reason: 'lifted while dragging');
    await gesture.up();
    await t.pump(const Duration(seconds: 1));
    expect(t.getRect(find.byType(MapPin)).bottom, closeTo(pin.bottom, 1));
    expect(camera(t).center.latitude, isNot(closeTo(25.3, 1e-6)));
  });

  group('my location', () {
    testWidgets('allowed: the map glides to the phone', (t) async {
      await open(t, initial: const LatLng(25.2, 51.4));
      await show(t);
      await t.tap(find.byTooltip('My location'));
      await t.pump();
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(camera(t).center.latitude, closeTo(25.28, 1e-3));
      expect(camera(t).zoom, 17);
      expect(findsNothing); // semantics are off in this test; the dot itself is drawn
      expect(find.byType(MyLocationDot), findsOneWidget);
    });

    testWidgets('refused: a plain message, no crash', (t) async {
      await open(
        t,
        initial: const LatLng(25.2, 51.4),
        location: FakeLocation(access: LocationAccess.denied),
      );
      await show(t);
      await t.tap(find.byTooltip('My location'));
      await t.pumpAndSettle();
      expect(
        find.text('Allow location access to find showrooms near you, or choose one from the list.'),
        findsOneWidget,
      );
      expect(find.byType(MyLocationDot), findsNothing);
    });

    testWidgets('blocked for good: the message offers the phone settings, and the button opens them', (t) async {
      final location = FakeLocation(access: LocationAccess.deniedForever);
      await open(t, initial: const LatLng(25.2, 51.4), location: location);
      await show(t);
      await t.tap(find.byTooltip('My location'));
      await t.pumpAndSettle();
      expect(find.text('Location access is turned off for this app. Open settings to allow it.'), findsOneWidget);
      await t.tap(find.text('Open settings'));
      await t.pump();
      expect(location.settingsOpened, 1);
    });

    testWidgets('GPS switched off: says so, with the settings button', (t) async {
      await open(
        t,
        initial: const LatLng(25.2, 51.4),
        location: FakeLocation(access: LocationAccess.serviceDisabled),
      );
      await show(t);
      await t.tap(find.byTooltip('My location'));
      await t.pumpAndSettle();
      expect(find.text('Turn on location (GPS) on your phone to use it here.'), findsOneWidget);
      expect(find.text('Open settings'), findsOneWidget);
    });
  });

  testWidgets('lays out in both languages, text x1.3, dark, small phone', (t) async {
    for (final v in [variants[1], variants[3], variants[0]]) {
      final handle = t.ensureSemantics();
      await open(t, initial: v.locale == kAr ? const LatLng(25.2, 55.0) : const LatLng(25.3, 51.5), v: v);
      await show(t);
      expect(t.takeException(), isNull, reason: v.name);
      await expectLater(t, meetsGuideline(androidTapTargetGuideline));
      await expectLater(t, meetsGuideline(labeledTapTargetGuideline));
      handle.dispose();
      await t.pumpWidget(const SizedBox.shrink());
    }
  });

  testWidgets('the saved-pin preview is a small map with the pin and cannot be dragged', (t) async {
    t.view.physicalSize = const Size(390, 844);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await t.pumpWidget(
      mapHost(
        const SizedBox(
          height: 140,
          child: PinPreview(pin: LatLng(25.3, 51.5), label: 'Location pinned'),
        ),
      ),
    );
    await t.pump(const Duration(milliseconds: 200));
    expect(find.byType(MapPin), findsOneWidget);
    final before = camera(t).center;
    await t.drag(find.byType(FlutterMap), const Offset(60, 20));
    await t.pump(const Duration(milliseconds: 300));
    expect(camera(t).center, before);
    expect(find.byIcon(Icons.center_focus_strong_rounded), findsNothing, reason: 'no buttons on a preview');
  });

  group('branch map', () {
    List<Branch> branches() => const [
      Branch(id: 'a', name: 'West Bay', latitude: 25.32, longitude: 51.52),
      Branch(id: 'b', name: 'Al Wakra', latitude: 25.17, longitude: 51.60),
      Branch(id: 'c', name: 'No pin yet'),
    ];

    testWidgets(
      'every branch with a location is framed (not just the first), the others are skipped, and a tap selects',
      (t) async {
        Branch? picked;
        t.view.physicalSize = const Size(390, 844);
        t.view.devicePixelRatio = 1;
        addTearDown(t.view.reset);
        await t.pumpWidget(mapHost(BranchMap(branches: branches(), onSelect: (b) => picked = b, selectedId: 'a')));
        await t.pump(const Duration(milliseconds: 300));
        expect(find.byType(MapPin), findsNWidgets(2));
        final size = t.getSize(find.byType(FlutterMap));
        for (final p in const [LatLng(25.32, 51.52), LatLng(25.17, 51.60)]) {
          final o = camera(t).getOffsetFromOrigin(p);
          expect(o.dx, inInclusiveRange(0, size.width));
          expect(o.dy, inInclusiveRange(0, size.height));
        }
        await t.tap(find.byType(MapPin).last);
        expect(picked?.id, 'b');
      },
    );

    testWidgets('choosing another branch elsewhere glides the map to it', (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      Widget host(String id) => mapHost(BranchMap(branches: branches(), onSelect: (_) {}, selectedId: id));
      await t.pumpWidget(host('a'));
      await t.pump(const Duration(milliseconds: 300));
      await t.pumpWidget(host('b'));
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(camera(t).center.latitude, closeTo(25.17, 1e-3));
    });

    testWidgets('a preview map (detail screen) has no buttons and ignores taps on pins', (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      Branch? picked;
      await t.pumpWidget(
        mapHost(
          SizedBox(
            height: 200,
            child: BranchMap(
              branches: [branches().first],
              onSelect: (b) => picked = b,
              selectedId: 'a',
              interactive: false,
            ),
          ),
        ),
      );
      await t.pump(const Duration(milliseconds: 300));
      expect(find.byIcon(Icons.center_focus_strong_rounded), findsNothing);
      await t.tap(find.byType(MapPin), warnIfMissed: false);
      await t.pump(const Duration(seconds: 1));
      expect(picked, isNull);
    });
  });
}
