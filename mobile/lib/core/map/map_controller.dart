import 'dart:math' as math;

import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

/// What the controller needs from the map widget that it drives (kept as an interface so the two files do not import
/// each other).
abstract interface class AlBaharMapHost {
  EdgeInsets get fitPadding;
  void animateCamera(LatLng center, double zoom);
  void stopCameraAnimation();
}

/// Drives an [AlBaharMap]: fit the markers, fly to one, follow a moving one, reset north. Every call is safe before the
/// map has its first layout (it waits) and after it is gone (it does nothing).
class AlBaharMapController extends ChangeNotifier {
  AlBaharMapController();

  /// The underlying flutter_map controller (for rare cases such as reading the camera).
  final MapController raw = MapController();

  /// Map rotation in degrees (0 = north up); drives the compass button.
  final ValueNotifier<double> rotation = ValueNotifier<double>(0);

  AlBaharMapHost? _host;
  final List<VoidCallback> _queued = [];
  bool _ready = false;
  bool _following = false;
  bool _disposed = false;
  bool _userMoved = false;
  List<LatLng> _fitPoints = const [];
  double _fitMaxZoom = 17;

  bool get ready => _ready;
  bool get following => _following;

  /// True once the person has dragged, pinched or turned the map since the last fit; automatic re-fits then stand down.
  bool get userMoved => _userMoved;
  List<LatLng> get fitPoints => _fitPoints;
  MapCamera? get camera => _ready && !_disposed ? raw.camera : null;

  void attach(AlBaharMapHost s) => _host = s;

  void detach(AlBaharMapHost s) {
    if (identical(_host, s)) {
      _host = null;
      _ready = false;
      _queued.clear();
    }
  }

  void markReady() {
    _ready = true;
    final queued = List<VoidCallback>.of(_queued);
    _queued.clear();
    for (final fn in queued) {
      fn();
    }
  }

  void _run(VoidCallback fn) {
    if (_disposed) return;
    if (_ready) {
      fn();
    } else {
      _queued.add(fn);
    }
  }

  void userGesture() {
    if (!_userMoved) _userMoved = true;
    if (_following) {
      _following = false;
      notifyListeners();
    }
  }

  /// Frames [points] inside the visible part of the map (below the top inset, above any bottom sheet). One point centres
  /// at a street-level zoom. The points are remembered for [recenter] and for automatic re-fits.
  void fit(Iterable<LatLng> points, {double maxZoom = 17, bool animate = true}) {
    _fitPoints = [
      for (final p in points)
        if (MapGeo.isFinitePair(p.latitude, p.longitude)) p,
    ];
    _fitMaxZoom = maxZoom;
    _userMoved = false;
    _applyFit(animate: animate);
  }

  /// Re-applies the last fit only while the person has not moved the map (the screen's inset or size changed).
  void refitIfUntouched() {
    if (!_userMoved && _fitPoints.isNotEmpty) _applyFit(animate: false);
  }

  void _applyFit({required bool animate}) {
    final pts = _fitPoints;
    if (pts.isEmpty) return;
    _run(() {
      final cam = raw.camera;
      if (cam.nonRotatedSize == MapCamera.kImpossibleSize || cam.nonRotatedSize.isEmpty) return;
      final fit = CameraFit.coordinates(
        coordinates: pts,
        padding: _host?.fitPadding ?? const EdgeInsets.all(48),
        maxZoom: pts.length == 1 ? math.min(_fitMaxZoom, 16.0) : _fitMaxZoom,
        minZoom: 3,
      );
      final target = fit.fit(cam);
      _moveTo(target.center, target.zoom, animate: animate);
    });
  }

  /// Shows everything that was last fitted again.
  void recenter() {
    _following = false;
    notifyListeners();
    if (_fitPoints.isNotEmpty) {
      _userMoved = false;
      _applyFit(animate: true);
    }
  }

  /// Glides the camera to [target] (at [zoom], or the current zoom).
  void flyTo(LatLng target, {double? zoom}) {
    if (!MapGeo.isFinitePair(target.latitude, target.longitude)) return;
    _run(() => _moveTo(target, zoom ?? raw.camera.zoom, animate: true));
  }

  /// Jumps the camera to [target].
  void moveTo(LatLng target, {double? zoom}) {
    if (!MapGeo.isFinitePair(target.latitude, target.longitude)) return;
    _run(() => _moveTo(target, zoom ?? raw.camera.zoom, animate: false));
  }

  void _moveTo(LatLng center, double zoom, {required bool animate}) {
    final s = _host;
    if (animate && s != null) {
      s.animateCamera(center, zoom);
    } else {
      s?.stopCameraAnimation();
      raw.move(center, zoom);
    }
  }

  void setFollow(bool on) {
    if (_following == on) return;
    _following = on;
    notifyListeners();
  }

  void toggleFollow() => setFollow(!_following);

  /// Call when the followed marker has moved: the camera glides after it while following is on.
  void followPoint(LatLng p) {
    if (!_following) return;
    _run(() => _moveTo(p, math.max(raw.camera.zoom, 15.5), animate: true));
  }

  void resetNorth() {
    _run(() {
      raw.rotate(0);
      rotation.value = 0;
    });
  }

  void zoomBy(double delta) {
    _run(() {
      final cam = raw.camera;
      _moveTo(cam.center, (cam.zoom + delta).clamp(cam.minZoom ?? 3, cam.maxZoom ?? 19), animate: true);
    });
  }

  @override
  void dispose() {
    _disposed = true;
    _queued.clear();
    rotation.dispose();
    raw.dispose();
    super.dispose();
  }
}

