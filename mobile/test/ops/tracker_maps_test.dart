import 'dart:async';

import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/driver/driver_tracker.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:fake_async/fake_async.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';

import '../helpers/fakes.dart';
import 'ops_test_kit.dart';

Position _p(
  double lat,
  double lng, {
  required DateTime at,
  double accuracy = 8,
  double speed = 0,
  double heading = 0,
}) => Position(
  latitude: lat,
  longitude: lng,
  timestamp: at,
  accuracy: accuracy,
  altitude: 0,
  altitudeAccuracy: 0,
  heading: heading,
  headingAccuracy: 0,
  speed: speed,
  speedAccuracy: 0,
);

void main() {
  group('driver location tracker', () {
    var clock = DateTime.utc(2026, 11, 13, 12);
    DateTime now() => clock;

    void run(String name, void Function(FakeAsync async) body) {
      test(name, () {
        clock = DateTime.utc(2026, 11, 13, 12);
        fakeAsync(body);
      });
    }

    void tick(FakeAsync async, Duration d) {
      clock = clock.add(d);
      async.elapse(d);
    }

    // A tracker with the keep-alive pushed far away, so each test sees only what it is about.
    DriverTracker make(
      FakeOps repo,
      StreamController<Position> fixes, {
      Duration keepAlive = const Duration(hours: 5),
    }) => DriverTracker(repo, positions: (_) => fixes.stream, now: now, keepAlive: keepAlive);

    void close(FakeAsync async, DriverTracker tracker, StreamController<Position> fixes) {
      fixes.close();
      tracker.stop();
      async.flushMicrotasks();
    }

    run('a fix worse than 100 m is dropped: not sent, not shown, counted; exactly 100 m is kept', (async) {
      final repo = FakeOps();
      final fixes = StreamController<Position>();
      final tracker = make(repo, fixes)..start(title: 't', body: 'b');
      async.flushMicrotasks();
      final seen = <GeoFix>[];
      tracker.fixes.listen(seen.add);

      fixes.add(_p(25.28, 51.52, at: clock, accuracy: 250));
      fixes.add(_p(25.28, 51.52, at: clock, accuracy: 100.5));
      async.flushMicrotasks();
      expect(repo.beats, isEmpty);
      expect(seen, isEmpty);
      expect(tracker.lastFix, isNull);
      expect(tracker.droppedForAccuracy, 2);

      fixes.add(_p(25.28, 51.52, at: clock, accuracy: 100));
      async.flushMicrotasks();
      expect(repo.beats, hasLength(1));
      expect(seen, hasLength(1));
      expect(tracker.lastFix!.accuracyM, 100);
      close(async, tracker, fixes);
    });

    run('the payload carries speed in km/h, heading only while moving, rounded accuracy and the fix time', (async) {
      final repo = FakeOps();
      final fixes = StreamController<Position>();
      final tracker = make(repo, fixes)..start(title: 't', body: 'b');
      async.flushMicrotasks();

      final at = clock.subtract(const Duration(seconds: 3));
      fixes.add(_p(25.28, 51.52, at: at, accuracy: 12.4, speed: 10, heading: 87.6));
      async.flushMicrotasks();
      var b = repo.beats.single;
      expect(b.speedKmh, 36.0);
      expect(b.heading, 88);
      expect(b.accuracy, 12);
      expect(b.recordedAt, at);
      expect(b.recordedAt!.isUtc, isTrue);

      // Standing still: the phone reports heading 0; sending that would point every standing driver north.
      tick(async, const Duration(seconds: 10));
      fixes.add(_p(25.2801, 51.5201, at: clock, speed: 0.2, heading: 0));
      async.flushMicrotasks();
      b = repo.beats.last;
      expect(b.heading, isNull);
      expect(b.speedKmh, 0.7);

      // Unknown speed: nothing is invented.
      tick(async, const Duration(seconds: 10));
      fixes.add(_p(25.2802, 51.5202, at: clock, speed: -1, heading: -1));
      async.flushMicrotasks();
      b = repo.beats.last;
      expect(b.speedKmh, isNull);
      expect(b.heading, isNull);
      close(async, tracker, fixes);
    });

    run('the driver\'s own map gets every accepted fix, with heading only while moving', (async) {
      final repo = FakeOps();
      final fixes = StreamController<Position>();
      final tracker = make(repo, fixes)..start(title: 't', body: 'b');
      async.flushMicrotasks();
      final seen = <GeoFix>[];
      tracker.fixes.listen(seen.add);
      fixes.add(_p(25.28, 51.52, at: clock, speed: 12, heading: 200));
      tick(async, const Duration(seconds: 2));
      fixes.add(_p(25.2801, 51.5201, at: clock, speed: 0, heading: 0));
      async.flushMicrotasks();
      expect(seen, hasLength(2));
      expect(seen.first.heading, 200);
      expect(seen.first.speedKmh, closeTo(43.2, 1e-9));
      expect(seen.last.heading, isNull);
      expect(tracker.lastFix, seen.last);
      close(async, tracker, fixes);
    });

    run('standing still for 90 s slows the reports to one per 30 s; driving again speeds them up at once', (async) {
      final repo = FakeOps();
      final fixes = StreamController<Position>();
      final tracker = make(repo, fixes)..start(title: 't', body: 'b');
      async.flushMicrotasks();
      expect(tracker.mode, TrackerMode.moving);

      fixes.add(_p(25.2800, 51.5200, at: clock)); // first fix: the resting point
      async.flushMicrotasks();
      expect(repo.beats, hasLength(1));

      tick(async, const Duration(seconds: 20));
      fixes.add(_p(25.28002, 51.52002, at: clock)); // GPS drift of a couple of metres
      async.flushMicrotasks();
      expect(repo.beats, hasLength(2), reason: 'still in the first 90 s: normal 8 s gap');
      expect(tracker.mode, TrackerMode.moving);

      tick(async, const Duration(seconds: 80)); // 100 s since the driver last moved
      fixes.add(_p(25.28001, 51.52001, at: clock));
      async.flushMicrotasks();
      expect(tracker.mode, TrackerMode.idle);
      expect(repo.beats, hasLength(3));

      tick(async, const Duration(seconds: 12));
      fixes.add(_p(25.28003, 51.52001, at: clock));
      async.flushMicrotasks();
      expect(repo.beats, hasLength(3), reason: 'idle: drift 12 s after the last report is not sent');

      tick(async, const Duration(seconds: 20));
      fixes.add(_p(25.28001, 51.52003, at: clock));
      async.flushMicrotasks();
      expect(repo.beats, hasLength(4), reason: '32 s since the last report: sent');

      // The driver sets off (about 150 m): back to normal at once.
      tick(async, const Duration(seconds: 9));
      fixes.add(_p(25.2815, 51.5200, at: clock, speed: 6, heading: 0));
      async.flushMicrotasks();
      expect(tracker.mode, TrackerMode.moving);
      expect(repo.beats, hasLength(5));
      expect(repo.beats.last.heading, 0);
      close(async, tracker, fixes);
    });

    run('a driver who stands from the start becomes idle through the keep-alive tick, with no fix events at all', (
      async,
    ) {
      final repo = FakeOps();
      final fixes = StreamController<Position>();
      final tracker = DriverTracker(repo, positions: (_) => fixes.stream, now: now)..start(title: 't', body: 'b');
      async.flushMicrotasks();
      fixes.add(_p(25.28, 51.52, at: clock));
      async.flushMicrotasks();
      tick(async, const Duration(seconds: 61));
      expect(tracker.mode, TrackerMode.moving);
      tick(async, const Duration(seconds: 61)); // 122 s: past the 90 s of calm
      expect(tracker.mode, TrackerMode.idle);
      close(async, tracker, fixes);
    });

    run('the keep-alive repeats the last good fix, never a rejected inaccurate one', (async) {
      final repo = FakeOps();
      final fixes = StreamController<Position>();
      final tracker = make(repo, fixes, keepAlive: const Duration(seconds: 60))..start(title: 't', body: 'b');
      async.flushMicrotasks();
      fixes.add(_p(25.28, 51.52, at: clock));
      async.flushMicrotasks();
      tick(async, const Duration(seconds: 30));
      fixes.add(_p(25.99, 51.99, at: clock, accuracy: 900)); // a wild fix from a tunnel
      async.flushMicrotasks();
      tick(async, const Duration(seconds: 40));
      expect(repo.beats.last.lat, 25.28);
      expect(repo.beats.every((b) => b.lat == 25.28), isTrue);
      close(async, tracker, fixes);
    });

    run('one request at a time: a slow connection does not queue reports behind each other', (async) {
      final gate = Completer<void>();
      final slow = _SlowOps(gate);
      final fixes = StreamController<Position>();
      final tracker = DriverTracker(slow, positions: (_) => fixes.stream, now: now, keepAlive: const Duration(hours: 5))
        ..start(title: 't', body: 'b');
      async.flushMicrotasks();
      fixes.add(_p(25.28, 51.52, at: clock));
      async.flushMicrotasks();
      tick(async, const Duration(seconds: 10));
      fixes.add(_p(25.281, 51.521, at: clock));
      tick(async, const Duration(seconds: 10));
      fixes.add(_p(25.282, 51.522, at: clock));
      async.flushMicrotasks();
      expect(slow.requests, 1, reason: 'the first request is still in flight');
      gate.complete();
      async.flushMicrotasks();
      tick(async, const Duration(seconds: 10));
      fixes.add(_p(25.283, 51.523, at: clock));
      async.flushMicrotasks();
      expect(slow.requests, 2);
      close(async, tracker, fixes);
    });

    run('stop and dispose leave no timer, no subscription and no live stream behind', (async) {
      final repo = FakeOps();
      final fixes = StreamController<Position>.broadcast();
      final tracker = DriverTracker(repo, positions: (_) => fixes.stream, now: now)..start(title: 't', body: 'b');
      async.flushMicrotasks();
      expect(tracker.running, isTrue);
      expect(fixes.hasListener, isTrue);
      fixes.add(_p(25.28, 51.52, at: clock));
      async.flushMicrotasks();
      expect(tracker.lastFix, isNotNull);

      tracker.stop();
      async.flushMicrotasks();
      expect(tracker.running, isFalse);
      expect(tracker.lastFix, isNull);
      expect(fixes.hasListener, isFalse);
      expect(async.pendingTimers, isEmpty);

      // It can start again after a stop (duty switched off and on).
      tracker.start(title: 't', body: 'b');
      async.flushMicrotasks();
      expect(tracker.running, isTrue);
      tracker.dispose();
      async.flushMicrotasks();
      expect(tracker.running, isFalse);
      expect(async.pendingTimers, isEmpty);
    });

    run('starting twice does not open a second location stream', (async) {
      var opened = 0;
      final fixes = StreamController<Position>.broadcast();
      final tracker = DriverTracker(
        FakeOps(),
        positions: (_) {
          opened++;
          return fixes.stream;
        },
        now: now,
      )..start(title: 't', body: 'b');
      async.flushMicrotasks();
      tracker.start(title: 't', body: 'b');
      async.flushMicrotasks();
      expect(opened, 1);
      tracker.dispose();
      async.flushMicrotasks();
    });

    test('Android: the foreground service says what it is doing, in the app language', () {
      debugDefaultTargetPlatformOverride = TargetPlatform.android;
      addTearDown(() => debugDefaultTargetPlatformOverride = null);
      for (final code in ['en', 'ar']) {
        final t = OpsStrings(code);
        late LocationSettings settings;
        final fixes = StreamController<Position>();
        final tracker = DriverTracker(
          FakeOps(),
          positions: (s) {
            settings = s;
            return fixes.stream;
          },
        );
        tracker.start(title: t.trackingTitle, body: t.trackingBody, channelName: t.trackingChannel);
        final android = settings as AndroidSettings;
        final n = android.foregroundNotificationConfig!;
        expect(n.notificationTitle, t.trackingTitle);
        expect(n.notificationText, t.trackingBody);
        expect(n.notificationChannelName, t.trackingChannel);
        expect(n.setOngoing, isTrue);
        expect(android.distanceFilter, 15);
        expect(RegExp('[؀-ۿ]').hasMatch(n.notificationTitle), code == 'ar');
        tracker.stop();
        fixes.close();
      }
    });

    test('iOS: background updates stay on with the system indicator', () {
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      addTearDown(() => debugDefaultTargetPlatformOverride = null);
      late LocationSettings settings;
      final fixes = StreamController<Position>();
      final tracker = DriverTracker(
        FakeOps(),
        positions: (s) {
          settings = s;
          return fixes.stream;
        },
      );
      tracker.start(title: 't', body: 'b');
      final apple = settings as AppleSettings;
      expect(apple.allowBackgroundLocationUpdates, isTrue);
      expect(apple.showBackgroundLocationIndicator, isTrue);
      expect(apple.pauseLocationUpdatesAutomatically, isFalse);
      tracker.stop();
      fixes.close();
    });
  });

  group('heartbeat request', () {
    test(
      'sends the fix time; a phone clock ahead of the server (422 location_in_future) is retried without it',
      () async {
        final h = await TestHarness.create(
          handler: routes({
            'POST /admin/dispatch/driver/location': (r) => r.body is Map && (r.body as Map).containsKey('recorded_at')
                ? error(422, 'location_in_future')
                : ok({'ok': true, 'recorded': true}),
          }),
        );
        final repo = OpsRepository(h.read(apiClientProvider));
        final at = DateTime.utc(2026, 11, 13, 12, 0, 5);
        await repo.heartbeat(25.28, 51.52, speedKmh: 30, heading: 90, accuracy: 9, recordedAt: at);
        final sent = h.adapter.where('POST', '/admin/dispatch/driver/location');
        expect(sent, hasLength(2));
        expect(sent.first.body['recorded_at'], '2026-11-13T12:00:05.000Z');
        expect(sent.first.body['speed_kmh'], 30);
        expect(sent.first.body['heading'], 90);
        expect(sent.first.body['accuracy'], 9);
        expect(sent.last.body.containsKey('recorded_at'), isFalse);
        expect(sent.last.body['latitude'], 25.28);
      },
    );

    test('a fix that is too old is not resent (it would look fresh), and other errors surface', () async {
      final h = await TestHarness.create(
        handler: routes({'POST /admin/dispatch/driver/location': (_) => error(422, 'location_too_old')}),
      );
      final repo = OpsRepository(h.read(apiClientProvider));
      await expectLater(
        repo.heartbeat(25.28, 51.52, recordedAt: DateTime.utc(2026, 11, 13, 11)),
        throwsA(isA<ApiException>().having((e) => e.code, 'code', 'location_too_old')),
      );
      expect(h.adapter.where('POST', '/admin/dispatch/driver/location'), hasLength(1));
    });

    test('without a fix time the request has none, and nothing is sent twice', () async {
      final h = await TestHarness.create(
        handler: routes({
          'POST /admin/dispatch/driver/location': (_) => ok({'ok': true}),
        }),
      );
      final repo = OpsRepository(h.read(apiClientProvider));
      await repo.heartbeat(25.28, 51.52);
      final sent = h.adapter.where('POST', '/admin/dispatch/driver/location');
      expect(sent, hasLength(1));
      expect(sent.single.body.keys.toSet(), {'latitude', 'longitude'});
    });
  });
}

class _SlowOps extends FakeOps {
  _SlowOps(this.gate);
  final Completer<void> gate;
  int requests = 0;

  @override
  Future<void> heartbeat(
    double lat,
    double lng, {
    double? speedKmh,
    int? heading,
    int? accuracy,
    DateTime? recordedAt,
  }) async {
    requests++;
    await gate.future;
  }
}
