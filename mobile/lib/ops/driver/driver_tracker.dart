import 'dart:async';

import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';

/// How the phone is behaving, which sets how often it reports.
enum TrackerMode {
  /// On the road: at most one report every [DriverTracker.minGap].
  moving,

  /// Standing still for a while: GPS drift is not reported again and again, one report every
  /// [DriverTracker.idleGap] at most (the minute-long keep-alive still tells dispatch the phone is alive).
  idle,
}

/// Shares the driver's position while on duty. On Android it runs as a foreground service (a visible "sharing your
/// location" notification), so tracking continues with the screen off or the app in the background.
///
/// * Fixes with a poor accuracy (worse than [maxAccuracyM]) are dropped: a 400 m circle is not a position.
/// * Sends at most one update every [minGap] while moving and every [idleGap] once the driver has stood still for
///   [idleAfter]; the server also keeps only one breadcrumb per 3 s.
/// * While the driver stands still the phone sends nothing, so a slow timer repeats the last fix every [keepAlive] and
///   dispatch can tell "waiting at the branch" from "phone off".
/// * Heading is only sent while moving (a standing phone reports a meaningless 0), and the fix time goes along so a
///   report that sat in a queue is not mistaken for a live one.
/// * The stream settings are never changed while running: restarting an Android foreground service from the background
///   is not allowed on recent Android versions, so "slower when idle" is done on the sending side.
class DriverTracker {
  DriverTracker(
    this._repo, {
    this.minGap = const Duration(seconds: 8),
    this.idleGap = const Duration(seconds: 30),
    this.keepAlive = const Duration(seconds: 60),
    this.idleAfter = const Duration(seconds: 90),
    this.maxAccuracyM = 100,
    this.moveMetres = 25,
    Stream<Position> Function(LocationSettings settings)? positions,
    this.now = DateTime.now,
  }) : _positions = positions ?? ((s) => Geolocator.getPositionStream(locationSettings: s));

  final OpsRepository _repo;
  final Duration minGap;
  final Duration idleGap;
  final Duration keepAlive;
  final Duration idleAfter;
  final double maxAccuracyM;

  /// A move of at least this many metres from the resting point counts as driving.
  final double moveMetres;
  final Stream<Position> Function(LocationSettings settings) _positions;

  /// The clock (replaced in tests).
  final DateTime Function() now;
  StreamSubscription<Position>? _sub;
  Timer? _keepAliveTimer;
  Position? _last;
  DateTime _lastSent = DateTime.fromMillisecondsSinceEpoch(0);
  bool _sending = false;
  LatLng? _anchor;
  DateTime? _movedAt;
  TrackerMode _mode = TrackerMode.moving;
  int _dropped = 0;
  final _fixes = StreamController<GeoFix>.broadcast();
  GeoFix? _lastFix;

  bool get running => _sub != null;
  TrackerMode get mode => _mode;

  /// How many fixes were thrown away for poor accuracy (diagnostics and tests).
  int get droppedForAccuracy => _dropped;

  /// Every accepted fix, for the driver's own map. Broadcast; fixes keep coming after a [stop] and a new [start].
  Stream<GeoFix> get fixes => _fixes.stream;

  /// The newest accepted fix since the tracker was started, null before the first one.
  GeoFix? get lastFix => _lastFix;

  /// [onError] is told when the location stream breaks (permission taken away, GPS switched off). [channelName] is the
  /// name of the Android notification channel, shown in the system settings, in the app's language.
  Future<void> start({
    required String title,
    required String body,
    String? channelName,
    void Function(Object error)? onError,
  }) async {
    if (_sub != null) return;
    final LocationSettings settings = switch (defaultTargetPlatform) {
      TargetPlatform.android => AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 15,
        intervalDuration: const Duration(seconds: 10),
        foregroundNotificationConfig: ForegroundNotificationConfig(
          notificationTitle: title,
          notificationText: body,
          notificationChannelName: channelName ?? 'Live location',
          notificationIcon: const AndroidResource(name: 'ic_launcher', defType: 'mipmap'),
          enableWakeLock: true,
          setOngoing: true,
        ),
      ),
      TargetPlatform.iOS || TargetPlatform.macOS => AppleSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 15,
        activityType: ActivityType.automotiveNavigation,
        pauseLocationUpdatesAutomatically: false,
        allowBackgroundLocationUpdates: true,
        showBackgroundLocationIndicator: true,
      ),
      _ => const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 15),
    };
    _mode = TrackerMode.moving;
    _anchor = null;
    _movedAt = now();
    _sub = _positions(settings).listen(_onPosition, onError: (Object e) => onError?.call(e));
    _keepAliveTimer = Timer.periodic(keepAlive, (_) {
      final p = _last;
      final at = now();
      _evaluateIdle(at);
      // Repeat only a recent fix: after a long GPS blackout the old position must not look fresh.
      if (p != null &&
          at.difference(_lastSent) >= keepAlive &&
          at.difference(p.timestamp) < const Duration(minutes: 10)) {
        unawaited(_send(p));
      }
    });
  }

  Future<void> _onPosition(Position p) async {
    // `accuracy` is the radius of the 68 % circle in metres; a negative value means the phone does not know it.
    if (p.accuracy.isFinite && p.accuracy > maxAccuracyM) {
      _dropped++;
      return;
    }
    if (!MapGeo.isFinitePair(p.latitude, p.longitude)) return;
    _last = p;
    final at = now();
    _classify(p, at);
    final fix = _toFix(p);
    _lastFix = fix;
    if (!_fixes.isClosed) _fixes.add(fix);
    final gap = _mode == TrackerMode.idle ? idleGap : minGap;
    if (at.difference(_lastSent) < gap) return;
    await _send(p);
  }

  /// Moving or resting? A move of [moveMetres] from the resting point, or a speed of about 4 km/h, is driving; the
  /// phone counts as idle after [idleAfter] without either.
  void _classify(Position p, DateTime at) {
    final here = LatLng(p.latitude, p.longitude);
    final anchor = _anchor;
    final moved = anchor == null || MapGeo.distanceMeters(anchor, here) >= moveMetres;
    final fast = p.speed.isFinite && p.speed >= 1.1;
    if (moved || fast) {
      _anchor = here;
      _movedAt = at;
      _mode = TrackerMode.moving;
    } else {
      _evaluateIdle(at);
    }
  }

  void _evaluateIdle(DateTime at) {
    final since = _movedAt;
    if (since != null && _mode == TrackerMode.moving && at.difference(since) >= idleAfter) _mode = TrackerMode.idle;
  }

  GeoFix _toFix(Position p) => GeoFix(
    lat: p.latitude,
    lng: p.longitude,
    heading: _headingOf(p),
    speedKmh: _speedKmh(p),
    accuracyM: p.accuracy.isFinite && p.accuracy >= 0 ? p.accuracy : null,
    at: p.timestamp,
    receivedAt: now(),
  );

  static double? _speedKmh(Position p) =>
      p.speed.isFinite && p.speed >= 0 ? double.parse((p.speed * 3.6).clamp(0, 300).toStringAsFixed(1)) : null;

  /// A heading is only worth sending while the phone is really moving.
  static double? _headingOf(Position p) =>
      p.speed.isFinite && p.speed >= 1.0 && p.heading.isFinite && p.heading >= 0 && p.heading <= 360 ? p.heading : null;

  Future<void> _send(Position p) async {
    // One request at a time: on a bad connection the next fix replaces a slow one instead of queueing behind it.
    if (_sending) return;
    _sending = true;
    _lastSent = now();
    try {
      await _repo.heartbeat(
        p.latitude,
        p.longitude,
        speedKmh: _speedKmh(p),
        heading: _headingOf(p)?.round(),
        accuracy: p.accuracy.isFinite && p.accuracy >= 0 ? p.accuracy.round().clamp(0, 32767) : null,
        recordedAt: p.timestamp.toUtc(),
      );
    } catch (_) {
      // Offline or a server hiccup: the next fix (or the keep-alive) retries.
    } finally {
      _sending = false;
    }
  }

  Future<void> stop() async {
    _keepAliveTimer?.cancel();
    _keepAliveTimer = null;
    _last = null;
    _anchor = null;
    _movedAt = null;
    _lastFix = null;
    _mode = TrackerMode.moving;
    // `running` turns false at once; the stream may take a moment to confirm the cancel.
    final cancelled = _sub?.cancel();
    _sub = null;
    await cancelled;
  }

  /// Ends the tracker for good (the app is closing): stops and closes the fix stream.
  Future<void> dispose() async {
    await stop();
    await _fixes.close();
  }
}
