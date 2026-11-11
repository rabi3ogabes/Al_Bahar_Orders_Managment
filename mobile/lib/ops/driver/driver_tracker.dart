import 'dart:async';

import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

/// Shares the driver's position while on duty. On Android it runs as a foreground service (a visible "sharing your
/// location" notification), so tracking continues with the screen off or the app in the background.
/// Sends at most one update every [minGap]; the server also keeps only one breadcrumb per 3 s.
class DriverTracker {
  DriverTracker(this._repo, {this.minGap = const Duration(seconds: 8)});

  final OpsRepository _repo;
  final Duration minGap;
  StreamSubscription<Position>? _sub;
  DateTime _lastSent = DateTime.fromMillisecondsSinceEpoch(0);

  bool get running => _sub != null;

  Future<void> start({required String title, required String body}) async {
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
    _sub = Geolocator.getPositionStream(locationSettings: settings).listen(_onPosition, onError: (Object _) {});
  }

  Future<void> _onPosition(Position p) async {
    final now = DateTime.now();
    if (now.difference(_lastSent) < minGap) return;
    _lastSent = now;
    try {
      await _repo.heartbeat(
        p.latitude,
        p.longitude,
        speedKmh: p.speed >= 0 ? p.speed * 3.6 : null,
        heading: p.heading >= 0 ? p.heading.round() : null,
        accuracy: p.accuracy.round(),
      );
    } catch (_) {
      // Offline or a server hiccup: the next fix retries.
    }
  }

  Future<void> stop() async {
    await _sub?.cancel();
    _sub = null;
  }
}
