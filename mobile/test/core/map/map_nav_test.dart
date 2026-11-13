import 'package:albahar_customer/core/map/map_nav.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/map_fakes.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('links', () {
    test('Android: Google Maps by its navigation link (web link as backup), Waze; no Apple Maps', () {
      final c = MapNavigation.choices(25.3, 51.5, platform: TargetPlatform.android);
      expect(c.map((e) => e.app), [NavApp.googleMaps, NavApp.waze]);
      expect(c[0].native.toString(), 'google.navigation:q=25.3,51.5&mode=d');
      expect(c[0].web.host, 'www.google.com');
      expect(c[0].web.queryParameters, {'api': '1', 'destination': '25.3,51.5', 'travelmode': 'driving'});
      expect(c[1].native.toString(), 'waze://?ll=25.3,51.5&navigate=yes');
      expect(c[1].web.toString(), 'https://waze.com/ul?ll=25.3%2C51.5&navigate=yes');
    });

    test('iOS: Google Maps by comgooglemaps, Apple Maps by its web link, Waze', () {
      final c = MapNavigation.choices(25.3, 51.5, platform: TargetPlatform.iOS);
      expect(c.map((e) => e.app), [NavApp.googleMaps, NavApp.appleMaps, NavApp.waze]);
      expect(c[0].native.toString(), 'comgooglemaps://?daddr=25.3,51.5&directionsmode=driving');
      expect(c[1].native, isNull);
      expect(c[1].web.host, 'maps.apple.com');
      expect(c[1].web.queryParameters['daddr'], '25.3,51.5');
    });

    test(
      'only the apps that are installed are offered; Google and Apple Maps always work through their web links',
      () async {
        final all = MapNavigation.choices(25.3, 51.5, platform: TargetPlatform.android);
        final withoutWaze = await MapNavigation.available(all, canLaunch: (u) async => u.scheme != 'waze');
        expect(withoutWaze.map((c) => c.app), [NavApp.googleMaps]);
        final both = await MapNavigation.available(all, canLaunch: (_) async => true);
        expect(both.map((c) => c.app), [NavApp.googleMaps, NavApp.waze]);
        // A phone that throws when asked (no plugin, a policy) simply has no Waze.
        final thrown = await MapNavigation.available(all, canLaunch: (_) async => throw StateError('no'));
        expect(thrown.map((c) => c.app), [NavApp.googleMaps]);
      },
    );

    test('the app used last comes first', () {
      final all = MapNavigation.choices(25.3, 51.5, platform: TargetPlatform.iOS);
      expect(MapNavigation.ordered(all, NavApp.waze).map((c) => c.app), [
        NavApp.waze,
        NavApp.googleMaps,
        NavApp.appleMaps,
      ]);
      expect(MapNavigation.ordered(all, null).map((c) => c.app), [NavApp.googleMaps, NavApp.appleMaps, NavApp.waze]);
    });

    test('the app link is tried first, then the web link; nothing opening is reported', () async {
      final waze = MapNavigation.choices(25.3, 51.5, platform: TargetPlatform.android).last;
      final tried = <String>[];
      expect(
        await MapNavigation.launch(
          waze,
          open: (u) async {
            tried.add(u.scheme);
            return u.scheme == 'https';
          },
        ),
        isTrue,
      );
      expect(tried, ['waze', 'https']);

      tried.clear();
      expect(
        await MapNavigation.launch(
          waze,
          open: (u) async {
            tried.add(u.scheme);
            return false;
          },
        ),
        isFalse,
      );
      expect(tried, ['waze', 'https']);

      expect(await MapNavigation.launch(waze, open: (_) async => throw StateError('boom')), isFalse);
    });

    test('the last choice is remembered', () async {
      expect(await MapNavigation.lastUsed(), isNull);
      await MapNavigation.remember(NavApp.waze);
      expect(await MapNavigation.lastUsed(), NavApp.waze);
    });
  });

  group('starting navigation', () {
    Future<bool?> run(WidgetTester t, FakeNavigation phone, {Future<void> Function()? whileOpen}) async {
      bool? result;
      await t.pumpWidget(
        mapHost(
          Builder(
            builder: (context) => Center(
              child: ElevatedButton(
                onPressed: () async {
                  result = await startNavigation(context, lat: 25.3, lng: 51.5, launcher: phone);
                },
                child: const Text('go'),
              ),
            ),
          ),
        ),
      );
      await t.tap(find.text('go'));
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      await whileOpen?.call();
      await t.pumpAndSettle();
      return result;
    }

    testWidgets('one app: it starts at once, with no chooser', (t) async {
      final phone = FakeNavigation();
      final r = await run(t, phone);
      expect(r, isTrue);
      expect(find.text('Navigate with'), findsNothing);
      expect(phone.opened.single.scheme, 'google.navigation');
      expect(phone.remembered, NavApp.googleMaps);
    });

    testWidgets('two apps: a chooser; the pick starts, and is remembered for next time', (t) async {
      final phone = FakeNavigation(installed: {'waze'});
      final r = await run(
        t,
        phone,
        whileOpen: () async {
          expect(find.text('Navigate with'), findsOneWidget);
          expect(find.text('Google Maps'), findsOneWidget);
          expect(find.text('Waze'), findsOneWidget);
          await t.tap(find.text('Waze'));
        },
      );
      expect(r, isTrue);
      expect(phone.opened.single.scheme, 'waze');
      expect(phone.remembered, NavApp.waze);
    });

    testWidgets('the app used last is offered first', (t) async {
      final phone = FakeNavigation(installed: {'waze'})..last = NavApp.waze;
      await run(
        t,
        phone,
        whileOpen: () async {
          final waze = t.getTopLeft(find.text('Waze')).dy;
          final google = t.getTopLeft(find.text('Google Maps')).dy;
          expect(waze, lessThan(google));
          await t.tap(find.text('Google Maps'));
        },
      );
    });

    testWidgets('closing the chooser opens nothing and is not an error', (t) async {
      final phone = FakeNavigation(installed: {'waze'});
      final r = await run(t, phone, whileOpen: () async => t.tapAt(const Offset(10, 10)));
      expect(r, isTrue);
      expect(phone.opened, isEmpty);
    });

    testWidgets('a phone that opens nothing reports failure so the screen can say so', (t) async {
      final phone = FakeNavigation(opens: false);
      final r = await run(t, phone);
      expect(r, isFalse);
      expect(phone.opened, hasLength(2), reason: 'the app link, then the web link');
      expect(phone.remembered, isNull);
    });

    testWidgets('iOS offers Apple Maps without asking the phone', (t) async {
      final phone = FakeNavigation(platformOverride: TargetPlatform.iOS);
      final r = await run(
        t,
        phone,
        whileOpen: () async {
          expect(find.text('Apple Maps'), findsOneWidget);
          await t.tap(find.text('Apple Maps'));
        },
      );
      expect(r, isTrue);
      expect(phone.opened.single.host, 'maps.apple.com');
    });
  });
}
