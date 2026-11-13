import 'dart:async';

import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:albahar_customer/core/live_order/live_order_api.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';

/// One call to [FakePlatform.show].
class ShownCard {
  ShownCard(this.order, this.locale, this.force, this.arabicDigits);
  final LiveOrder order;
  final String locale;
  final bool force;
  final bool arabicDigits;
}

/// Records what the controller asks the native renderer to do.
class FakePlatform implements LiveOrderPlatform {
  final shown = <ShownCard>[];
  final cancelled = <String>[];
  int cancelAllCalls = 0;
  int openSettingsCalls = 0;
  int requestCalls = 0;

  LiveOrderShowResult result = LiveOrderShowResult.posted;
  LiveOrderPermission state = LiveOrderPermission.granted;

  /// What the system dialog answers when it is shown.
  LiveOrderPermission dialogAnswer = LiveOrderPermission.granted;

  ShownCard get last => shown.last;
  List<String> get phases => [for (final c in shown) c.order.phase.wire];

  @override
  Future<LiveOrderShowResult> show(
    LiveOrder order, {
    required String locale,
    bool force = false,
    bool arabicDigits = false,
  }) async {
    shown.add(ShownCard(order, locale, force, arabicDigits));
    return result;
  }

  @override
  Future<void> cancel(String orderId) async => cancelled.add(orderId);

  @override
  Future<void> cancelAll() async => cancelAllCalls++;

  @override
  Future<bool> areEnabled() async => state == LiveOrderPermission.granted;

  @override
  Future<LiveOrderPermission> permission() async => state;

  @override
  Future<LiveOrderPermission> requestPermission() async {
    requestCalls++;
    state = dialogAnswer;
    return state;
  }

  @override
  Future<bool> openSettings() async {
    openSettingsCalls++;
    return true;
  }
}

/// A server whose answers the test sets.
class FakeLiveOrderApi implements LiveOrderApi {
  /// `null` means "the server cannot say".
  List<LiveOrder>? active = const [];
  Object? activeError;
  final byId = <String, LiveOrder?>{};
  final lookupErrors = <String, Object>{};

  int activeCalls = 0;
  final lookups = <String>[];

  @override
  Future<List<LiveOrder>?> activeProgress() async {
    activeCalls++;
    final error = activeError;
    if (error != null) throw error;
    return active;
  }

  @override
  Future<LiveOrder?> orderProgress(String orderId) async {
    lookups.add(orderId);
    final error = lookupErrors[orderId];
    if (error != null) throw error;
    return byId[orderId];
  }
}

LiveOrder liveOrder(
  String id, {
  LiveOrderPhase phase = LiveOrderPhase.placed,
  int seq = 0,
  bool pickup = false,
  DateTime? updatedAt,
  String? etaLabel,
  DateTime? etaTo,
  double? sub,
}) => LiveOrder(
  orderId: id,
  number: 'N-$id',
  phase: phase,
  pickup: pickup,
  seq: seq,
  updatedAt: updatedAt,
  etaLabel: etaLabel,
  etaTo: etaTo,
  sub: sub,
);
