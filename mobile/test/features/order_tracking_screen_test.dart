import 'dart:async';

import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/orders/presentation/order_tracking_screen.dart';
import 'package:albahar_customer/features/orders/presentation/tracking_panel.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:latlong2/latlong.dart';

import '../helpers/fakes.dart';
import '../helpers/map_fakes.dart';
import '../ops/ops_test_kit.dart' show Variant, kAr, kEn, variants;
import 'tracking_logic_test.dart' show serverPayload;

/// A socket that only does what the tests tell it to.
class FakePusher extends PusherClient {
  FakePusher() : super(uri: Uri.parse('wss://ws.test/app/key'));

  final channels = <String, StreamController<PusherEvent>>{};
  final _states = StreamController<RealtimeState>.broadcast();
  RealtimeState current = RealtimeState.connected;

  @override
  RealtimeState get state => current;

  @override
  Stream<RealtimeState> get states => _states.stream;

  @override
  Stream<PusherEvent> channel(String channelName) =>
      (channels[channelName] ??= StreamController<PusherEvent>.broadcast()).stream;

  bool listening(String channelName) => channels[channelName]?.hasListener ?? false;

  void push(String channelName, Map<String, dynamic> data, {String event = 'driver.moved'}) =>
      channels[channelName]!.add(PusherEvent(channel: channelName, event: event, data: data));

  void changeState(RealtimeState s) {
    current = s;
    _states.add(s);
  }

  @override
  Future<void> dispose() async {}
}

Map<String, dynamic> _orderJson({String? phone = '+97455123456'}) => {
  'id': 'o1',
  'number': 'AB100008',
  'status': 'out_for_delivery',
  'items': <Object>[],
  'fulfillments': [
    {
      'id': 'f1',
      'number': 'F1',
      'type': 'delivery',
      'status': 'out_for_delivery',
      'branch': {'id': 'b1', 'name': 'Al Markhiya'},
      'delivery': {'status': 'out_for_delivery', 'driver_name': 'Ahmed Al Thani', 'driver_phone': phone},
    },
  ],
};

void main() {
  setUpAll(() async {
    await initializeDateFormatting('en');
    await initializeDateFormatting('ar');
  });

  Future<({TestHarness h, FakePusher pusher, FakeNavigation phone})> open(
    WidgetTester t, {
    Map<String, dynamic>? payload,
    FakeReply? tracking,
    String? driverPhone = '+97455123456',
    Variant v = const Variant('default', Size(390, 844), kEn),
    bool online = true,
    FakeNavigation? phone,
    FakePusher? pusher,
  }) async {
    final socket = pusher ?? FakePusher();
    final nav = phone ?? FakeNavigation();
    final h = await TestHarness.create(
      handler: routes({
        'GET /orders/o1/tracking': (_) => tracking ?? ok(payload ?? serverPayload()),
        'GET /orders/o1': (_) => ok(_orderJson(phone: driverPhone)),
      }),
      overrides: [
        pusherClientProvider.overrideWithValue(socket),
        ...mapTestOverrides(navigation: nav),
        if (!online) onlineProvider.overrideWith(_Offline.new),
      ],
    );
    t.view.physicalSize = v.size;
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await t.pumpWidget(
      UncontrolledProviderScope(
        container: h.container,
        child: MaterialApp(
          locale: v.locale,
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          theme: AppTheme.light(v.locale),
          darkTheme: AppTheme.dark(v.locale),
          themeMode: v.dark ? ThemeMode.dark : ThemeMode.light,
          builder: (context, c) => MediaQuery(
            data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(v.scale)),
            child: c!,
          ),
          home: const OrderTrackingScreen(orderId: 'o1'),
        ),
      ),
    );
    await t.pump();
    await t.pump(const Duration(milliseconds: 200));
    // The first view frames the points with a short glide.
    await t.pump();
    await t.pump(const Duration(seconds: 2));
    return (h: h, pusher: socket, phone: nav);
  }

  Finder driverMarker() => find.byType(DriverMarkerView);
  LatLng driverPoint(WidgetTester t) => t
      .widgetList<MarkerLayer>(find.byType(MarkerLayer))
      .expand((l) => l.markers)
      .firstWhere((m) => m.child is DriverMarkerView)
      .point;

  group('on the way', () {
    testWidgets('the driver, the address and the branch are on the map; the panel says who, when and how far', (
      t,
    ) async {
      await open(t);
      expect(driverMarker(), findsOneWidget);
      // The camera frames the driver and the address (the branch is behind them), so the address pin is on screen.
      expect(find.byType(MapPin), findsOneWidget);
      expect(find.text('Ahmed is on the way'), findsOneWidget);
      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('Arriving in ~12 min'), findsOneWidget);
      expect(find.text('2.4 km away'), findsOneWidget);
      expect(find.text('Live'), findsOneWidget);
      expect(find.byTooltip('Call driver'), findsOneWidget);
      expect(find.byType(PolylineLayer), findsOneWidget); // the dashed way to go
    });

    testWidgets('the panel never covers the markers: they all sit above it', (t) async {
      await open(t);
      final panelTop = t.getRect(find.byType(TrackingPanel)).top;
      expect(t.getCenter(driverMarker()).dy, lessThan(panelTop - 20));
      for (final pin in t.widgetList(find.byType(MapPin))) {
        expect(t.getRect(find.byWidget(pin)).bottom, lessThanOrEqualTo(panelTop));
      }
    });

    testWidgets('a live push moves the marker; an older push is ignored; the order status can be opened', (t) async {
      final env = await open(t);
      final now = DateTime.now().toUtc();
      env.pusher.push('private-orders.o1', {
        'order_id': 'o1',
        'name': 'Ahmed',
        'lat': 25.2820,
        'lng': 51.5240,
        'heading': 45,
        'speed_kmh': 40,
        'accuracy_m': 6,
        'at': now.toIso8601String(),
        'age_seconds': 0,
        'delivery_status': 'out_for_delivery',
      });
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(driverPoint(t).latitude, closeTo(25.2820, 1e-6));

      env.pusher.push('private-orders.o1', {
        'lat': 25.2000,
        'lng': 51.4000,
        'at': now.subtract(const Duration(minutes: 2)).toIso8601String(),
        'age_seconds': 120,
      });
      await t.pump();
      await t.pump(const Duration(seconds: 2));
      expect(driverPoint(t).latitude, closeTo(25.2820, 1e-6), reason: 'a late push must not move the marker back');
    });

    testWidgets(
      'the camera keeps the driver and the address in view while the driver moves, until the person moves the map',
      (t) async {
        final env = await open(t);
        MapCamera cam() => MapCamera.of(t.element(find.byType(TileLayer)));
        final before = cam().zoom;
        final now = DateTime.now().toUtc();
        env.pusher.push('private-orders.o1', {
          'lat': 25.2950,
          'lng': 51.5450,
          'at': now.toIso8601String(),
          'age_seconds': 0,
          'speed_kmh': 40,
          'heading': 45,
        });
        await t.pump();
        await t.pump(const Duration(seconds: 2));
        expect(cam().zoom, greaterThan(before), reason: 'closer to the address: zoom in');
        final size = t.getSize(find.byType(FlutterMap));
        final o = cam().getOffsetFromOrigin(const LatLng(25.2950, 51.5450));
        expect(o.dx, inInclusiveRange(0, size.width));

        await t.dragFrom(const Offset(200, 250), const Offset(80, 40));
        await t.pump(const Duration(milliseconds: 400));
        final held = cam().center;
        env.pusher.push('private-orders.o1', {
          'lat': 25.2980,
          'lng': 51.5480,
          'at': now.add(const Duration(seconds: 5)).toIso8601String(),
          'age_seconds': 0,
        });
        await t.pump();
        await t.pump(const Duration(seconds: 2));
        expect(cam().center, held, reason: 'the person moved the map: it stays where they put it');
      },
    );

    testWidgets('it asks again every 12 seconds, and stops while the app is in the background', (t) async {
      final env = await open(t);
      int calls() => env.h.adapter.where('GET', '/orders/o1/tracking').length;
      final first = calls();
      await t.pump(const Duration(seconds: 13));
      expect(calls(), first + 1);

      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await t.pump();
      final paused = calls();
      expect(env.pusher.listening('private-orders.o1'), isFalse, reason: 'the channel is left while nobody is looking');
      await t.pump(const Duration(seconds: 40));
      expect(calls(), paused);

      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pump();
      await t.pump(const Duration(milliseconds: 200));
      expect(calls(), paused + 1, reason: 'back in front: one refresh at once');
      expect(env.pusher.listening('private-orders.o1'), isTrue);
    });

    testWidgets('after the socket reconnects, what was missed is fetched at once', (t) async {
      final env = await open(t);
      int calls() => env.h.adapter.where('GET', '/orders/o1/tracking').length;
      env.pusher.changeState(RealtimeState.reconnecting);
      await t.pump();
      final before = calls();
      env.pusher.changeState(RealtimeState.connected);
      await t.pump();
      await t.pump(const Duration(milliseconds: 200));
      expect(calls(), before + 1);
    });

    testWidgets('a quiet driver: grey ETA and "Last seen" instead of a confident time', (t) async {
      await open(t, payload: serverPayload(driverExtra: {'age_seconds': 400}));
      expect(find.text('Last seen 6 min ago'), findsNWidgets(2)); // under the marker and in the panel
      expect(t.widget<DriverMarkerView>(driverMarker()).stale, isTrue);
    });

    testWidgets('the call button dials the number from the order; with no phone app it hands over the number', (
      t,
    ) async {
      final copied = <String>[];
      t.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
        if (call.method == 'Clipboard.setData') copied.add((call.arguments as Map)['text'] as String);
        return null;
      });
      addTearDown(() => t.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));
      final phone = FakeNavigation();
      await open(t, phone: phone);
      await t.tap(find.byTooltip('Call driver'));
      await t.pump();
      expect(phone.opened.single.toString(), 'tel:+97455123456');

      phone.opens = false;
      await t.tap(find.byTooltip('Call driver'));
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      expect(copied, ['+97455123456']);
      expect(find.text('Phone number copied'), findsOneWidget);
    });

    testWidgets('no phone number known: no call button, everything else is there', (t) async {
      await open(t, driverPhone: null);
      expect(find.byTooltip('Call driver'), findsNothing);
      expect(find.text('Ahmed is on the way'), findsOneWidget);
    });

    testWidgets('the order steps open under the panel, with the current one marked', (t) async {
      await open(t);
      expect(find.text('Order confirmed'), findsNothing);
      await t.tap(find.text('Order status'));
      await t.pump();
      await t.pump(const Duration(milliseconds: 400));
      for (final s in ['Order confirmed', 'Being prepared', 'On the way', 'Delivered']) {
        expect(find.text(s), findsOneWidget, reason: s);
      }
      expect(find.bySemanticsLabel('On the way, current step'), findsOneWidget);
      expect(find.bySemanticsLabel('Order confirmed, done'), findsOneWidget);
      expect(find.bySemanticsLabel('Delivered, not yet'), findsOneWidget);
      // The map above is still fully usable: the markers did not end up behind the taller panel.
      final panelTop = t.getRect(find.byType(TrackingPanel)).top;
      await t.pump(const Duration(seconds: 1));
      expect(t.getCenter(driverMarker()).dy, lessThan(panelTop));
    });
  });

  group('the other states', () {
    testWidgets('not assigned yet: the address and branch on the map, a calm message, no driver, no live pill', (
      t,
    ) async {
      await open(
        t,
        payload: serverPayload(status: 'processing', deliveryStatus: null, live: false, withDriver: false, eta: null),
      );
      expect(driverMarker(), findsNothing);
      expect(find.text('We are getting your order ready'), findsOneWidget);
      expect(find.text('Live'), findsNothing);
      expect(find.byType(MapPin), findsNWidgets(2));
      expect(find.byType(PolylineLayer), findsNothing);
    });

    testWidgets('waiting for pickup: names the branch', (t) async {
      await open(
        t,
        payload: serverPayload(
          status: 'processing',
          deliveryStatus: 'accepted',
          live: false,
          withDriver: false,
          eta: null,
        ),
      );
      expect(find.text('Your driver is collecting your order'), findsOneWidget);
      expect(find.text('Picking it up from Al Markhiya.'), findsOneWidget);
    });

    testWidgets('delivered: the map stands still, says when, and stops asking and listening', (t) async {
      final env = await open(
        t,
        payload: serverPayload(
          status: 'completed',
          deliveryStatus: 'delivered',
          live: false,
          eta: null,
          driverExtra: {'at': '2026-11-13T12:05:00Z', 'age_seconds': 900},
        ),
      );
      expect(find.textContaining('Delivered at'), findsOneWidget);
      expect(find.text('Live'), findsNothing);
      expect(driverMarker(), findsNothing);
      expect(find.byType(MapPin), findsNWidgets(2));
      int calls() => env.h.adapter.where('GET', '/orders/o1/tracking').length;
      final n = calls();
      await t.pump(const Duration(seconds: 60));
      expect(calls(), n, reason: 'nothing more will change');
      expect(env.pusher.listening('private-orders.o1'), isFalse);
    });

    testWidgets('cancelled: says there is nothing to follow', (t) async {
      await open(
        t,
        payload: serverPayload(status: 'cancelled', deliveryStatus: null, live: false, withDriver: false, eta: null),
      );
      expect(find.text('Nothing to follow'), findsOneWidget);
    });

    testWidgets('no coordinates at all: a clear message instead of an empty map, and the steps are still there', (
      t,
    ) async {
      await open(
        t,
        payload: {
          ...serverPayload(status: 'processing', deliveryStatus: null, live: false, withDriver: false, eta: null),
          'destination': null,
          'branch': null,
        },
      );
      expect(find.byType(FlutterMap), findsNothing);
      expect(find.text('Live tracking is unavailable'), findsOneWidget);
      expect(find.text('We are getting your order ready'), findsOneWidget);
    });

    testWidgets('the server failing: a retry that works', (t) async {
      var fail = true;
      final h = await TestHarness.create(
        handler: routes({
          'GET /orders/o1/tracking': (_) => fail ? error(500, 'server_error') : ok(serverPayload()),
          'GET /orders/o1': (_) => ok(_orderJson()),
        }),
        overrides: [pusherClientProvider.overrideWithValue(FakePusher()), ...mapTestOverrides()],
      );
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: MaterialApp(
            localizationsDelegates: const [
              AppLocalizations.delegate,
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
            ],
            supportedLocales: const [Locale('en')],
            theme: AppTheme.light(const Locale('en')),
            home: const OrderTrackingScreen(orderId: 'o1'),
          ),
        ),
      );
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      expect(find.text('Try again'), findsOneWidget);
      fail = false;
      await t.tap(find.text('Try again'));
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      expect(find.text('Ahmed is on the way'), findsOneWidget);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 30));
    });

    testWidgets('a failed refresh keeps the last picture on screen', (t) async {
      final env = await open(t);
      env.h.adapter.handler = routes({
        'GET /orders/o1/tracking': (_) => error(503, 'server_error'),
        'GET /orders/o1': (_) => ok(_orderJson()),
      });
      await t.pump(const Duration(seconds: 13));
      await t.pump(const Duration(milliseconds: 300));
      expect(find.text('Ahmed is on the way'), findsOneWidget);
      expect(driverMarker(), findsOneWidget);
    });

    testWidgets('offline: the last position stays, with a note and an Offline pill', (t) async {
      await open(t, online: false);
      expect(find.text('You are offline. Showing the last known position.'), findsOneWidget);
      expect(find.text('Offline'), findsOneWidget);
      expect(find.text('Live'), findsNothing);
      expect(driverMarker(), findsOneWidget);
    });
  });

  group('looks', () {
    for (final v in variants) {
      testWidgets('lays out: ${v.name}; targets are 48 dp and labelled', (t) async {
        final handle = t.ensureSemantics();
        await open(t, v: v);
        expect(t.takeException(), isNull);
        await expectLater(t, meetsGuideline(androidTapTargetGuideline));
        await expectLater(t, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }

    testWidgets('Arabic: the panel and the pills speak Arabic', (t) async {
      await open(t, v: const Variant('ar', Size(390, 844), kAr));
      expect(find.text('Ahmed في الطريق إليك'), findsOneWidget);
      expect(find.text('مباشر'), findsOneWidget);
      expect(find.text('الوصول خلال ~12 د'), findsOneWidget);
    });

    testWidgets('leaving the screen leaves the channel and stops the timers', (t) async {
      final env = await open(t);
      expect(env.pusher.listening('private-orders.o1'), isTrue);
      await t.pumpWidget(const SizedBox.shrink());
      expect(env.pusher.listening('private-orders.o1'), isFalse);
      await t.pump(const Duration(seconds: 30));
      expect(t.takeException(), isNull);
    });
  });
}

class _Offline extends OnlineController {
  @override
  bool build() => false;
}
