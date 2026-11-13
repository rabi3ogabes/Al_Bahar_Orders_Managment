import 'dart:async';

import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

/// Shares the driver's position while on duty. On Android it runs as a foreground service (a visible "sharing your
/// location" notification), so tracking continues with the screen off or the app in the background.
/// Sends at most one update every [minGap]; the server also keeps only one breadcrumb per 3 s. While the driver
/// stands still the phone sends nothing, so a slow timer repeats the last fix every [keepAlive] and dispatch can tell
/// "waiting at the branch" from "phone off".
class DriverTracker {
  DriverTracker(
    this._repo, {
    this.minGap = const Duration(seconds: 8),
    this.keepAlive = const Duration(seconds: 60),
    Stream<Position> Function(LocationSettings settings)? positions,
    DateTime Function() now = DateTime.now,
  }) : _now = now,
       _positions = positions ?? ((s) => Geolocator.getPositionStream(locationSettings: s));

  final OpsRepository _repo;
  final Duration minGap;
  final Duration keepAlive;
  final Stream<Position> Function(LocationSettings settings) _positions;
  final DateTime Function() _now;
  StreamSubscription<Position>? _sub;
  Timer? _keepAliveTimer;
  Position? _last;
  DateTime _lastSent = DateTime.fromMillisecondsSinceEpoch(0);

  bool get running => _sub != null;

  /// [onError] is told when the location stream breaks (permission taken away, GPS switched off).
  Future<void> start({required String title, required String body, void Function(Object error)? onError}) async {
    if (_sub != null) return;
    final LocationSettings settings = switch (defaultTargetPlatform) {
      TargetPlatform.android => AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 15,
        intervalDuration: const Duration(seconds: 10),
        foregroundNotificationConfig: ForegroundNotificationConfig(
          notificationTitle: title,
          notificationText: body,
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
    _sub = _positions(settings).listen(_onPosition, onError: (Object e) => onError?.call(e));
    _keepAliveTimer = Timer.periodic(keepAlive, (_) {
      final p = _last;
      final now = _now();
      // Repeat only a recent fix: after a long GPS blackout the old position must not look fresh.
      if (p != null && now.difference(_lastSent) >= keepAlive && now.difference(p.timestamp) < const Duration(minutes: 10)) {
        unawaited(_send(p));
      }
    });
  }

  Future<void> _onPosition(Position p) async {
    _last = p;
    if (_now().difference(_lastSent) < minGap) return;
    await _send(p);
  }

  Future<void> _send(Position p) async {
    _lastSent = _now();
    try {
      await _repo.heartbeat(
        p.latitude,
        p.longitude,
        speedKmh: p.speed >= 0 ? p.speed * 3.6 : null,
        heading: p.heading >= 0 ? p.heading.round() : null,
        accuracy: p.accuracy.round(),
      );
    } catch (_) {
      // Offline or a server hiccup: the next fix (or the keep-alive) retries.
    }
  }

  Future<void> stop() async {
    _keepAliveTimer?.cancel();
    _keepAliveTimer = null;
    _last = null;
    await _sub?.cancel();
    _sub = null;
  }
}
