import 'dart:convert';
import 'dart:ui' show Color;

import 'package:flutter/foundation.dart';

import '../router/deep_links.dart';
import '../utils/json.dart';

/// Where an order is on its way. [step] is the stage the progress bar stops at (0..3): placed, preparing, on the way
/// (ready, for a pickup order) and delivered (collected). A stopped order (cancelled, failed) keeps the stage it reached.
enum LiveOrderPhase {
  placed('placed', 0),
  preparing('preparing', 1),
  onTheWay('on_the_way', 2),
  ready('ready', 2),
  delivered('delivered', 3),
  collected('collected', 3),
  cancelled('cancelled', 0),
  failed('failed', 0);

  const LiveOrderPhase(this.wire, this.step);

  /// Value on the wire (push payload, REST, method channel).
  final String wire;
  final int step;

  bool get isStopped => this == cancelled || this == failed;
  bool get isFinal => this == delivered || this == collected || isStopped;

  static LiveOrderPhase? parse(Object? value) {
    final v = value?.toString().trim().toLowerCase();
    if (v == null || v.isEmpty) return null;
    for (final p in values) {
      if (p.wire == v) return p;
    }
    return null;
  }
}

/// The live state of one order, in the shape of the `order_progress` push data (see
/// docs/qa/LIVE_ORDER_NOTIFICATION.md). Every source (push, realtime event, REST, the order just placed) is turned into
/// one of these, so ordering, de-duplication and drawing are the same whatever told us.
@immutable
class LiveOrder {
  LiveOrder({
    required this.orderId,
    required this.number,
    required this.phase,
    this.pickup = false,
    int? step,
    this.sub,
    this.title,
    this.body,
    this.etaLabel,
    this.etaFrom,
    this.etaTo,
    this.etaAt,
    this.driverName,
    this.driverPhone,
    this.vehicle,
    this.seq = 0,
    this.updatedAt,
    this.deepLink,
    this.brandPrimary,
    this.brandAccent,
    this.headline,
    this.subtitle,
  }) : step = phase.isStopped ? (step ?? 0).clamp(0, stages - 1) : phase.step;

  /// Stages on the bar: placed, preparing, on the way / ready, delivered / collected.
  static const stages = 4;

  /// How full the segment of the current stage looks when the server sends no sub-progress: a first hint of progress.
  static const defaultSub = 0.15;
  static const defaultSubReady = 0.5;

  static const _maxText = 200;

  final String orderId;
  final String number;
  final LiveOrderPhase phase;
  final bool pickup;
  final int step;

  /// Progress inside the current stage, 0..1 (null: not sent).
  final double? sub;

  /// Text from the server, already in the customer's language.
  final String? title;
  final String? body;
  final String? etaLabel;
  final DateTime? etaFrom;
  final DateTime? etaTo;

  /// The moment the driver is expected to arrive (a point, not a window), only while on the road: the card counts down to it by itself.
  final DateTime? etaAt;
  final String? driverName;
  final String? driverPhone;
  final String? vehicle;

  /// Version of this state, growing with every change on the server; 0 when the source cannot tell.
  final int seq;
  final DateTime? updatedAt;
  final String? deepLink;
  final Color? brandPrimary;
  final Color? brandAccent;

  /// What the notification shows as its headline and status line (filled by LiveOrderTexts when the server sent none).
  final String? headline;
  final String? subtitle;

  bool get isFinal => phase.isFinal;
  bool get isStopped => phase.isStopped;

  double get effectiveSub => isFinal ? 1.0 : (sub ?? (phase == LiveOrderPhase.ready ? defaultSubReady : defaultSub));

  /// In-app location of the order (the notification opens it).
  String get location => DeepLinks.fromString(deepLink) ?? '/orders/$orderId';

  /// What makes two states of the order look the same on the screen. The version is not part of it: the server stamps
  /// every re-read with a new one, and a re-read that says the same thing must not redraw the card.
  String get signature => [
    phase.wire,
    step,
    (effectiveSub * 20).round(),
    title,
    body,
    etaLabel,
    etaFrom?.millisecondsSinceEpoch,
    etaTo?.millisecondsSinceEpoch,
    (etaAt?.millisecondsSinceEpoch ?? 0) ~/ 60000,
    driverName,
    driverPhone,
    vehicle,
  ].join('|');

  /// True when this state should replace [prev]. Versioned states (`seq`, then `updated_at`) are ordered by their
  /// version; unversioned ones never move an order backwards and never reopen a finished one. A finished order stays as
  /// it first finished (a re-read would restamp the time). Mirrors the Kotlin side.
  bool supersedes(LiveOrder? prev) {
    if (prev == null) return true;
    if (prev.isFinal && phase == prev.phase) return false;
    if (seq > 0 && prev.seq > 0) return seq > prev.seq;
    final a = updatedAt?.millisecondsSinceEpoch ?? 0;
    final b = prev.updatedAt?.millisecondsSinceEpoch ?? 0;
    if (a > 0 && b > 0 && a != b) return a > b;
    if (signature == prev.signature) return false;
    if (prev.isFinal) return false;
    if (isFinal) return true;
    return step >= prev.step;
  }

  LiveOrder copyWith({
    LiveOrderPhase? phase,
    int? step,
    double? sub,
    String? title,
    String? body,
    String? etaLabel,
    DateTime? etaFrom,
    DateTime? etaTo,
    DateTime? etaAt,
    String? driverName,
    String? driverPhone,
    String? vehicle,
    int? seq,
    DateTime? updatedAt,
    Color? brandPrimary,
    Color? brandAccent,
    String? headline,
    String? subtitle,
  }) => LiveOrder(
    orderId: orderId,
    number: number,
    pickup: pickup,
    phase: phase ?? this.phase,
    step: step ?? this.step,
    sub: sub ?? this.sub,
    title: title ?? this.title,
    body: body ?? this.body,
    etaLabel: etaLabel ?? this.etaLabel,
    etaFrom: etaFrom ?? this.etaFrom,
    etaTo: etaTo ?? this.etaTo,
    etaAt: etaAt ?? this.etaAt,
    driverName: driverName ?? this.driverName,
    driverPhone: driverPhone ?? this.driverPhone,
    vehicle: vehicle ?? this.vehicle,
    seq: seq ?? this.seq,
    updatedAt: updatedAt ?? this.updatedAt,
    deepLink: deepLink,
    brandPrimary: brandPrimary ?? this.brandPrimary,
    brandAccent: brandAccent ?? this.brandAccent,
    headline: headline ?? this.headline,
    subtitle: subtitle ?? this.subtitle,
  );

  /// Parses push data or one REST item (`kind` is optional there). Returns null without an order id; every other
  /// missing or malformed field falls back to a safe default, so a payload the server grows never breaks the app.
  static LiveOrder? tryParse(Map<String, dynamic> data) {
    final orderId = _text(data['order_id'], 80);
    if (orderId == null) return null;
    final kind = _text(data['kind'], 40);
    if (kind != null && kind != 'order_progress') return null;

    final type = (_text(data['type'], 20) ?? _text(data['fulfillment_type'], 20))?.toLowerCase();
    final pickup = type == 'pickup';
    final rawTotal = asIntOrNull(data['total']);
    final total = rawTotal != null && rawTotal >= 2 ? rawTotal : stages;
    final rawStep = data['step'] == null ? null : _normaliseStep(asInt(data['step']), total);
    final phase = LiveOrderPhase.parse(data['phase']) ?? _derivePhase(rawStep ?? 0, asBool(data['done']), pickup);
    final sub = asDoubleOrNull(data['sub']);
    final brand = _brand(data['brand']);

    return LiveOrder(
      orderId: orderId,
      number: _text(data['number'], 40) ?? orderId,
      phase: phase,
      pickup: pickup,
      step: rawStep,
      sub: sub != null && sub.isFinite ? sub.clamp(0.0, 1.0) : null,
      title: _text(data['title'], _maxText),
      body: _text(data['body'], _maxText),
      etaLabel: _text(data['eta_label'], _maxText),
      etaFrom: asDate(data['eta_from']),
      etaTo: asDate(data['eta_to']),
      etaAt: asDate(data['eta_at']),
      driverName: _text(data['driver_name'], 60),
      driverPhone: _text(data['driver_phone'], 30),
      vehicle: _text(data['vehicle'], 40),
      seq: asInt(data['seq']).clamp(0, 1 << 52),
      updatedAt: asDate(data['updated_at']),
      deepLink: _text(data['deep_link'], 300),
      brandPrimary: _hex(data['brand_primary']) ?? brand?.$1,
      brandAccent: _hex(data['brand_accent']) ?? brand?.$2,
    );
  }

  /// A state worked out from an order's status codes, for the sources that carry no progress payload (a realtime
  /// event, the orders list). Returns null for statuses that do not belong on the bar (returns, cancellation requests).
  static LiveOrder? fromStatus({
    required String orderId,
    required String number,
    required String status,
    String? fulfillmentType,
    String? deliveryStatus,
    DateTime? updatedAt,
    DateTime? etaTo,
    String? driverName,
    String? driverPhone,
    String? vehicle,
  }) {
    final pickup = fulfillmentType == 'pickup' || (fulfillmentType == null && status == 'ready_for_pickup');
    final phase = phaseForStatus(status, pickup: pickup, deliveryStatus: deliveryStatus);
    if (phase == null) return null;
    return LiveOrder(
      orderId: orderId,
      number: number,
      phase: phase,
      pickup: pickup,
      etaTo: phase == LiveOrderPhase.onTheWay ? etaTo : null,
      driverName: phase == LiveOrderPhase.onTheWay ? driverName : null,
      driverPhone: phase == LiveOrderPhase.onTheWay ? driverPhone : null,
      vehicle: phase == LiveOrderPhase.onTheWay ? vehicle : null,
      updatedAt: updatedAt,
    );
  }

  /// The ladder shared with the server's `PushNotifier::step`, plus the end states.
  static LiveOrderPhase? phaseForStatus(String status, {required bool pickup, String? deliveryStatus}) {
    switch (deliveryStatus) {
      case 'delivered':
        return LiveOrderPhase.delivered;
      case 'failed':
        return LiveOrderPhase.failed;
      case 'picked_up' || 'out_for_delivery':
        if (status != 'completed' && status != 'cancelled') return LiveOrderPhase.onTheWay;
    }
    return switch (status) {
      'pending_payment' || 'confirmed' => LiveOrderPhase.placed,
      'processing' || 'partially_fulfilled' => LiveOrderPhase.preparing,
      'out_for_delivery' => pickup ? LiveOrderPhase.ready : LiveOrderPhase.onTheWay,
      'ready_for_pickup' => pickup ? LiveOrderPhase.ready : LiveOrderPhase.preparing,
      'completed' => pickup ? LiveOrderPhase.collected : LiveOrderPhase.delivered,
      'cancelled' => LiveOrderPhase.cancelled,
      _ => null,
    };
  }

  /// The map sent to the native renderer: the push keys plus what the Dart side worked out.
  Map<String, Object?> toChannelMap({required String locale, bool force = false, bool arabicDigits = false}) => {
    'kind': 'order_progress',
    'order_id': orderId,
    'number': number,
    'type': pickup ? 'pickup' : 'delivery',
    'phase': phase.wire,
    'step': step,
    'total': stages,
    'sub': effectiveSub,
    'title': title,
    'body': body,
    'eta_label': etaLabel,
    'eta_from_ms': etaFrom?.millisecondsSinceEpoch,
    'eta_to_ms': etaTo?.millisecondsSinceEpoch,
    'eta_at_ms': etaAt?.millisecondsSinceEpoch,
    'driver_name': driverName,
    'driver_phone': driverPhone,
    'vehicle': vehicle,
    'seq': seq,
    'updated_at_ms': updatedAt?.millisecondsSinceEpoch,
    'deep_link': location,
    'brand_primary': brandPrimary?.toARGB32(),
    'headline': headline,
    'subtitle': subtitle,
    'locale': locale,
    'arabic_digits': arabicDigits,
    'force': force,
  };

  /// The data the plain progress notification (iOS, tests, a phone without the native renderer) understands.
  Map<String, dynamic> toLegacyProgress({String? stageLabel}) => {
    'kind': 'order_progress',
    'event': 'order.updated',
    'order_id': orderId,
    'number': number,
    'step': '$step',
    'total': '$stages',
    'step_label': stageLabel ?? '',
    'done': isFinal ? '1' : '0',
    'deep_link': location,
    'title': headline ?? title ?? '',
    'body': subtitle ?? body ?? '',
  };

  @override
  String toString() => 'LiveOrder($orderId ${phase.wire} step=$step seq=$seq)';

  static String? _text(Object? v, int max) {
    if (v == null || v is Map || v is List) return null;
    final s = v.toString().trim();
    if (s.isEmpty || s == 'null') return null;
    return s.length > max ? s.substring(0, max) : s;
  }

  /// A server that counts stages differently (`total` other than 4) is scaled onto the four-stage bar.
  static int _normaliseStep(int step, int total) {
    final clamped = step.clamp(0, total - 1);
    return total == stages ? clamped : (clamped * (stages - 1) / (total - 1)).round();
  }

  static LiveOrderPhase _derivePhase(int step, bool done, bool pickup) {
    if (step >= 3 || done) return pickup ? LiveOrderPhase.collected : LiveOrderPhase.delivered;
    if (step == 2) return pickup ? LiveOrderPhase.ready : LiveOrderPhase.onTheWay;
    if (step == 1) return LiveOrderPhase.preparing;
    return LiveOrderPhase.placed;
  }

  static final _hexPattern = RegExp(r'^#?([0-9a-fA-F]{6})$');

  static Color? _hex(Object? v) {
    if (v is num) return Color(0xFF000000 | (v.toInt() & 0xFFFFFF));
    final m = v is String ? _hexPattern.firstMatch(v.trim()) : null;
    return m == null ? null : Color(0xFF000000 | int.parse(m.group(1)!, radix: 16));
  }

  /// `brand` is a map `{primary, accent}` in REST and a JSON string in a push (FCM data values are strings).
  static (Color?, Color?)? _brand(Object? raw) {
    Object? value = raw;
    if (value is String && value.trim().startsWith('{')) {
      try {
        value = jsonDecode(value);
      } catch (_) {
        return null;
      }
    }
    if (value is! Map) return null;
    return (_hex(value['primary']), _hex(value['accent']));
  }
}
