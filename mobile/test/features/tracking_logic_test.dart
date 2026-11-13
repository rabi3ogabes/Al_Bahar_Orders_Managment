import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/domain/tracking_state.dart';
import 'package:albahar_customer/features/orders/presentation/tracking_view_model.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:latlong2/latlong.dart';

AppLocalizations get en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get ar => lookupAppLocalizations(const Locale('ar'));

final _t0 = DateTime.utc(2026, 11, 13, 12);

/// What `GET /orders/{id}/tracking` answers today: one `driver` object that holds the whole fix.
Map<String, dynamic> serverPayload({
  String status = 'out_for_delivery',
  String? deliveryStatus = 'out_for_delivery',
  bool live = true,
  bool withDriver = true,
  Map<String, dynamic>? eta = const {
    'minutes': 12,
    'distance_m': 2400,
    'updated_at': '2026-11-13T12:00:00Z',
    'source': 'road',
  },
  Map<String, dynamic>? driverExtra,
}) => {
  'order_id': 'o1',
  'number': 'AB100008',
  'status': status,
  'tracking': live && withDriver,
  'live': live,
  'delivery_status': deliveryStatus,
  'driver': withDriver
      ? {
          'name': 'Ahmed',
          'vehicle': 'Motorcycle',
          'status': deliveryStatus,
          'lat': 25.28,
          'lng': 51.52,
          'heading': 90,
          'speed_kmh': 32.5,
          'accuracy_m': 9,
          'at': '2026-11-13T11:59:50Z',
          'age_seconds': 10,
          'stale': false,
          ...?driverExtra,
        }
      : null,
  'destination': {'lat': 25.30, 'lng': 51.55},
  'branch': {'name': 'Al Markhiya', 'lat': 25.27, 'lng': 51.50},
  'eta': eta,
  'stale_after_seconds': 120,
};

OrderTracking parse(Map<String, dynamic> j) => OrderTracking.fromJson(j, receivedAt: _t0);

void main() {
  setUpAll(() async {
    await initializeDateFormatting('en');
    await initializeDateFormatting('ar');
  });

  group('reading the answer', () {
    test('the server answer: first name, vehicle, the fix inside `driver`, ETA, stale setting', () {
      final t = parse(serverPayload());
      expect(t.tracking, isTrue);
      expect(t.live, isTrue);
      expect(t.hasDriver, isTrue);
      expect(t.driverFirstName, 'Ahmed');
      expect(t.vehicle, 'Motorcycle');
      expect(t.position!.point, const LatLng(25.28, 51.52));
      expect(t.position!.heading, 90);
      expect(t.position!.speedKmh, 32.5);
      expect(t.position!.accuracyM, 9);
      expect(t.position!.ageSeconds, 10);
      expect(t.driver, const LatLng(25.28, 51.52));
      expect(t.destination, const LatLng(25.30, 51.55));
      expect(t.branch, const LatLng(25.27, 51.50));
      expect(t.branchName, 'Al Markhiya');
      expect(t.eta!.minutes, 12);
      expect(t.eta!.distanceM, 2400);
      expect(t.deliveryStatus, 'out_for_delivery');
      expect(t.staleAfter, const Duration(seconds: 120));
    });

    test('the nested shape from the first agreement: first_name, position{...}, speed, eta', () {
      final t = parse({
        'status': 'out_for_delivery',
        'tracking': true,
        'driver': {'first_name': 'Sara', 'vehicle': 'Car', 'phone': '+97455123456'},
        'position': {
          'lat': 25.2,
          'lng': 51.4,
          'heading': 270,
          'speed': 40,
          'accuracy': 5,
          'at': '2026-11-13T12:00:00Z',
          'age_seconds': 3,
        },
        'destination': {'lat': 25.3, 'lng': 51.5},
        'branch': {'lat': 25.1, 'lng': 51.3, 'name': 'B'},
        'eta': {'minutes': 5, 'distance_m': 1200, 'updated_at': '2026-11-13T12:00:00Z'},
      });
      expect(t.driverFirstName, 'Sara');
      expect(t.driverPhone, '+97455123456');
      expect(t.position!.speedKmh, 40);
      expect(t.position!.heading, 270);
      expect(t.eta!.minutes, 5);
    });

    test('the very first version: name, lat, lng on the driver; no eta, no position block', () {
      final t = parse({
        'status': 'out_for_delivery',
        'tracking': true,
        'driver': {
          'name': 'Ahmed Al Thani',
          'lat': 25.28,
          'lng': 51.52,
          'at': '2026-11-13T11:59:00Z',
          'status': 'out_for_delivery',
        },
        'destination': {'lat': 25.3, 'lng': 51.5},
        'branch': {'name': 'B', 'lat': null, 'lng': null},
      });
      expect(t.driverFirstName, 'Ahmed');
      expect(t.position!.point, const LatLng(25.28, 51.52));
      expect(t.eta, isNull);
      expect(t.branch, isNull);
      expect(t.deliveryStatus, 'out_for_delivery');
      expect(trackingStageOf(t), TrackingStage.onTheWay);
    });

    test('missing and broken fields never throw: empty answer, driver without coordinates, rubbish numbers', () {
      final empty = parse(const {});
      expect(empty.tracking, isFalse);
      expect(empty.hasDriver, isFalse);
      expect(empty.position, isNull);
      expect(empty.eta, isNull);
      expect(trackingStageOf(empty), TrackingStage.notAssigned);

      final noFix = parse(
        serverPayload(
          driverExtra: {'lat': null, 'lng': null, 'heading': null, 'speed_kmh': null, 'at': null, 'age_seconds': null},
        ),
      );
      expect(noFix.hasDriver, isTrue);
      expect(noFix.position, isNull);

      final junk = parse({
        'driver': {'name': 7, 'lat': 'x', 'lng': 51},
        'destination': {'lat': 999, 'lng': 51},
        'eta': {'minutes': -3, 'distance_m': 'far'},
        'stale_after_seconds': -5,
      });
      expect(junk.position, isNull);
      expect(junk.destination, isNull);
      expect(junk.eta, isNull);
      expect(junk.staleAfter, isNull);
    });
  });

  group('where the order is', () {
    test('no driver yet: not assigned; an accepted job that has not left: waiting for pickup', () {
      expect(
        trackingStageOf(
          parse(serverPayload(status: 'processing', deliveryStatus: null, live: false, withDriver: false, eta: null)),
        ),
        TrackingStage.notAssigned,
      );
      expect(
        trackingStageOf(
          parse(
            serverPayload(status: 'processing', deliveryStatus: 'accepted', live: false, withDriver: false, eta: null),
          ),
        ),
        TrackingStage.waitingPickup,
      );
      expect(
        trackingStageOf(parse(serverPayload(status: 'processing', deliveryStatus: 'accepted', live: false, eta: null))),
        TrackingStage.waitingPickup,
      );
    });

    test(
      'picked up or out for delivery: on the way; delivered or completed: delivered; cancelled and failed: closed',
      () {
        expect(trackingStageOf(parse(serverPayload(deliveryStatus: 'picked_up'))), TrackingStage.onTheWay);
        expect(trackingStageOf(parse(serverPayload())), TrackingStage.onTheWay);
        expect(
          trackingStageOf(parse(serverPayload(status: 'completed', deliveryStatus: 'delivered', live: false))),
          TrackingStage.delivered,
        );
        expect(
          trackingStageOf(
            parse(serverPayload(status: 'completed', deliveryStatus: null, live: false, withDriver: false)),
          ),
          TrackingStage.delivered,
        );
        expect(
          trackingStageOf(
            parse(serverPayload(status: 'cancelled', deliveryStatus: null, live: false, withDriver: false)),
          ),
          TrackingStage.closed,
        );
        expect(
          trackingStageOf(parse(serverPayload(status: 'out_for_delivery', deliveryStatus: 'failed', live: false))),
          TrackingStage.closed,
        );
      },
    );

    test('the four steps follow the stage', () {
      TrackingStep step(String status, String? d, {bool live = false, bool driver = false}) =>
          trackingStepOf(parse(serverPayload(status: status, deliveryStatus: d, live: live, withDriver: driver)));
      expect(step('confirmed', null), TrackingStep.confirmed);
      expect(step('pending_payment', null), TrackingStep.confirmed);
      expect(step('processing', null), TrackingStep.preparing);
      expect(step('ready_for_pickup', null), TrackingStep.preparing);
      expect(step('processing', 'accepted'), TrackingStep.preparing);
      expect(step('out_for_delivery', 'out_for_delivery', live: true, driver: true), TrackingStep.onTheWay);
      expect(step('completed', 'delivered'), TrackingStep.delivered);
    });
  });

  group('positions that arrive twice or out of order', () {
    test('the newer fix wins whichever way round they arrive', () {
      final a = GeoFix(lat: 25.2, lng: 51.5, at: _t0, receivedAt: _t0);
      final b = GeoFix(
        lat: 25.3,
        lng: 51.6,
        at: _t0.add(const Duration(seconds: 5)),
        receivedAt: _t0.add(const Duration(seconds: 1)),
      );
      expect(newerFix(a, b), b);
      expect(newerFix(b, a), b, reason: 'a push that arrives late must not move the marker back');
      expect(newerFix(null, a), a);
      expect(newerFix(a, null), a);
      expect(newerFix(null, null), isNull);
    });

    test('heading: the phone\'s own while moving, else the direction of the last real move, else the previous one', () {
      const prev = GeoFix(lat: 25.2800, lng: 51.5200);
      const moved = GeoFix(lat: 25.2810, lng: 51.5200); // ~110 m north
      expect(headingFor(const GeoFix(lat: 25.28, lng: 51.52, heading: 123, speedKmh: 30), prev), 123);
      expect(headingFor(moved, prev), closeTo(0, 0.5));
      // Standing: no jitter-driven spinning; the last known heading stays.
      expect(headingFor(const GeoFix(lat: 25.28001, lng: 51.52001, speedKmh: 0), prev, lastHeading: 77), 77);
      expect(headingFor(const GeoFix(lat: 25.28, lng: 51.52), null), isNull);
      // A reported heading of a standing phone (speed 0) is not trusted.
      expect(headingFor(const GeoFix(lat: 25.28, lng: 51.52, heading: 0, speedKmh: 0), null, lastHeading: 200), 0);
    });

    test('distance to go: to the address, or to the branch while waiting for pickup', () {
      final onWay = parse(serverPayload());
      final d = distanceToTarget(onWay.position, onWay)!;
      expect(d, closeTo(MapGeo.distanceMeters(const LatLng(25.28, 51.52), const LatLng(25.30, 51.55)), 1));
      final waiting = parse(serverPayload(status: 'processing', deliveryStatus: 'accepted', live: false, eta: null));
      final w = distanceToTarget(waiting.position, waiting)!;
      expect(w, closeTo(MapGeo.distanceMeters(const LatLng(25.28, 51.52), const LatLng(25.27, 51.50)), 1));
      expect(distanceToTarget(null, onWay), isNull);
    });
  });

  group('what the panel says', () {
    TrackingPanelModel panel(
      Map<String, dynamic> payload, {
      AppLocalizations? l10n,
      GeoFix? fix,
      Duration later = Duration.zero,
      bool offline = false,
      RealtimeState socket = RealtimeState.connected,
      bool phone = true,
    }) {
      final t = parse(payload);
      return buildTrackingPanel(
        l10n ?? en,
        tracking: t,
        fix: fix ?? t.position,
        now: _t0.add(later),
        offline: offline,
        socket: socket,
        hasPhone: phone,
        locale: 'en',
      );
    }

    test('on the way: name, vehicle, "Arriving in ~12 min", distance, live, call', () {
      final m = panel(serverPayload());
      expect(m.title, 'Ahmed is on the way');
      expect(m.subtitle, 'Motorcycle');
      expect(m.etaText, 'Arriving in ~12 min');
      expect(m.distanceText, '2.4 km away');
      expect(m.link, TrackingLink.live);
      expect(m.canCall, isTrue);
      expect(m.note, isNull);
      expect(m.initial, 'A');
      expect(m.stale, isFalse);
    });

    test('under a minute away: "Arriving any moment"; no estimate at all: no ETA line (nothing is invented)', () {
      expect(panel(serverPayload(eta: {'minutes': 0, 'distance_m': 80})).etaText, 'Arriving any moment');
      expect(panel(serverPayload(eta: {'minutes': 1, 'distance_m': 80})).etaText, 'Arriving any moment');
      final noEta = panel(serverPayload(eta: null));
      expect(noEta.etaText, isNull);
      expect(noEta.distanceText, isNotNull, reason: 'the straight-line distance is still true');
    });

    test('the distance falls back to the straight line, in metres under a kilometre', () {
      final m = panel(serverPayload(eta: null), fix: const GeoFix(lat: 25.2995, lng: 51.5495, receivedAt: null));
      expect(m.distanceText, matches(RegExp(r'^\d+ m away$')));
    });

    test('a position older than the server threshold: grey ETA, "Last seen 3 min ago", still sensible', () {
      final m = panel(serverPayload(), later: const Duration(minutes: 3));
      expect(m.stale, isTrue);
      expect(m.note, 'Last seen 3 min ago');
      expect(m.link, TrackingLink.live, reason: 'the socket is still up');
    });

    test('the socket dropped: Reconnecting while the data is fresh enough to show, Offline without internet', () {
      expect(
        panel(serverPayload(), socket: RealtimeState.reconnecting).link,
        TrackingLink.live,
        reason: 'a fresh poll still counts',
      );
      expect(
        panel(serverPayload(), socket: RealtimeState.reconnecting, later: const Duration(minutes: 3)).link,
        TrackingLink.reconnecting,
      );
      final off = panel(serverPayload(), offline: true);
      expect(off.link, TrackingLink.offline);
      expect(off.note, 'You are offline. Showing the last known position.');
    });

    test('on the way but no position yet: says it is waiting for the driver\'s location', () {
      final m = panel(serverPayload(driverExtra: {'lat': null, 'lng': null, 'at': null, 'age_seconds': null}));
      expect(m.etaText, isNull);
      expect(m.distanceText, isNull);
      expect(m.note, 'Waiting for your driver\'s location…');
    });

    test('waiting for pickup: the branch is named', () {
      final m = panel(
        serverPayload(status: 'processing', deliveryStatus: 'accepted', live: false, withDriver: false, eta: null),
      );
      expect(m.title, 'Your driver is collecting your order');
      expect(m.subtitle, 'Picking it up from Al Markhiya.');
      expect(m.etaText, isNull);
      expect(m.link, TrackingLink.idle);
    });

    test('not assigned yet', () {
      final m = panel(
        serverPayload(status: 'processing', deliveryStatus: null, live: false, withDriver: false, eta: null),
      );
      expect(m.title, 'We are getting your order ready');
      expect(m.subtitle, 'Your driver will appear here once the order is picked up');
      expect(m.canCall, isFalse, reason: 'nobody to call yet');
    });

    test('delivered: "Delivered at HH:MM" in Qatar time (UTC+3), and the map pauses', () {
      final m = panel(
        serverPayload(
          status: 'completed',
          deliveryStatus: 'delivered',
          live: false,
          eta: null,
          driverExtra: {'at': '2026-11-13T12:05:00Z'},
        ),
        later: const Duration(minutes: 30),
      );
      // (newer date data separates the minutes from "PM" with a narrow no-break space)
      expect(m.title.replaceAll(RegExp(r'\s'), ' '), 'Delivered at 3:05 PM');
      expect(m.link, TrackingLink.idle);
      expect(m.etaText, isNull);
      expect(m.stale, isFalse);
    });

    test('delivered without any time: just "Delivered"', () {
      final m = panel(
        serverPayload(status: 'completed', deliveryStatus: null, live: false, withDriver: false, eta: null),
      );
      expect(m.title, 'Delivered');
    });

    test('cancelled or failed: nothing to follow', () {
      final m = panel(
        serverPayload(status: 'cancelled', deliveryStatus: null, live: false, withDriver: false, eta: null),
      );
      expect(m.title, 'Nothing to follow');
    });

    test('Arabic: every line is Arabic, the name stays as sent', () {
      final m = panel(serverPayload(), l10n: ar);
      expect(m.title, 'Ahmed في الطريق إليك');
      expect(m.etaText, 'الوصول خلال ~12 د');
      expect(m.distanceText, 'يبعد 2.4 كم');
      final waiting = panel(
        serverPayload(status: 'processing', deliveryStatus: 'accepted', live: false, withDriver: false, eta: null),
        l10n: ar,
      );
      expect(RegExp('[؀-ۿ]').hasMatch(waiting.title), isTrue);
      expect(waiting.subtitle, 'يستلمه من Al Markhiya.');
    });
  });
}
