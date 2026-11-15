import 'dart:async';

import 'package:albahar_customer/core/live_order/live_activity_platform.dart';
import 'package:albahar_customer/core/live_order/live_activity_registrar.dart';
import 'package:albahar_customer/core/live_order/live_order.dart';

/// One call to [FakeActivity.show].
class ShownActivity {
  ShownActivity(this.order, this.locale, this.stageLabel);
  final LiveOrder order;
  final String locale;
  final String? stageLabel;
}

/// Records what the controller asks the iPhone to do, and lets a test push tokens in the way Apple would.
class FakeActivity implements LiveActivityPlatform {
  final shown = <ShownActivity>[];
  final ended = <String>[];
  int endAllCalls = 0;

  LiveActivityResult result = LiveActivityResult.started;
  Object? showError;
  Object? endAllError;
  var pending = <LiveActivityTokenEvent>[];
  var listeners = 0;
  final _controller = StreamController<LiveActivityTokenEvent>.broadcast(sync: true);

  void emit(LiveActivityTokenEvent e) => _controller.add(e);

  @override
  Future<bool> isAvailable() async => result != LiveActivityResult.unavailable;

  @override
  Future<LiveActivityResult> show(LiveOrder order, {required String locale, String? stageLabel}) async {
    shown.add(ShownActivity(order, locale, stageLabel));
    final error = showError;
    if (error != null) throw error;
    return result;
  }

  @override
  Future<void> end(String orderId) async => ended.add(orderId);

  @override
  Future<void> endAll() async {
    endAllCalls++;
    final error = endAllError;
    if (error != null) throw error;
  }

  @override
  Stream<LiveActivityTokenEvent> get tokens =>
      _controller.stream.map((e) => e).asBroadcastStream(onListen: (_) => listeners++, onCancel: (sub) => listeners--);

  @override
  Future<List<LiveActivityTokenEvent>> pendingTokens() async => List.of(pending);
}

/// A server that records what is reported, and fails when the test says so.
class FakeActivityApi implements LiveActivityApi {
  final activities = <Map<String, Object?>>[];
  final starts = <Map<String, Object?>>[];
  final unregistered = <String>[];
  Object? error;
  Object? unregisterError;

  void _check() {
    final e = error;
    if (e != null) throw e;
  }

  @override
  Future<void> registerActivity({
    required String orderId,
    required String token,
    required String environment,
    required String deviceId,
    String? locale,
    String? appVersion,
  }) async {
    _check();
    activities.add({
      'order_id': orderId,
      'token': token,
      'environment': environment,
      'device_id': deviceId,
      'locale': locale,
      'app_version': appVersion,
    });
  }

  @override
  Future<void> registerStart({
    required String token,
    required String environment,
    required String deviceId,
    String? locale,
    String? appVersion,
  }) async {
    _check();
    starts.add({
      'token': token,
      'environment': environment,
      'device_id': deviceId,
      'locale': locale,
      'app_version': appVersion,
    });
  }

  @override
  Future<void> unregisterDevice(String deviceId) async {
    final e = unregisterError;
    if (e != null) throw e;
    unregistered.add(deviceId);
  }
}

LiveActivityTokenEvent activityToken(String orderId, String token, {String environment = 'production'}) =>
    LiveActivityTokenEvent(kind: 'activity', orderId: orderId, token: token, environment: environment);

LiveActivityTokenEvent startToken(String token, {String environment = 'production'}) =>
    LiveActivityTokenEvent(kind: 'start', token: token, environment: environment);
