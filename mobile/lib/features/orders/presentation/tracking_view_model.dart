import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/map_format.dart';
import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/domain/tracking_state.dart';
import 'package:flutter/foundation.dart';

/// How fresh the picture on the screen is.
enum TrackingLink {
  /// Positions are arriving (socket connected or a recent answer).
  live,

  /// The socket dropped; the screen keeps asking every few seconds.
  reconnecting,

  /// The phone has no internet: the map shows the last known position.
  offline,

  /// Nothing more will arrive (delivered, closed, or no driver yet).
  idle,
}

/// Everything the bottom panel shows, already in words. Plain data, so the states can be tested without a screen.
@immutable
class TrackingPanelModel {
  const TrackingPanelModel({
    required this.stage,
    required this.step,
    required this.title,
    this.subtitle,
    this.etaText,
    this.distanceText,
    this.note,
    this.initial,
    this.canCall = false,
    this.link = TrackingLink.idle,
    this.stale = false,
  });

  final TrackingStage stage;
  final TrackingStep step;
  final String title;
  final String? subtitle;

  /// `Arriving in ~12 min`.
  final String? etaText;

  /// `2.4 km away`.
  final String? distanceText;

  /// A line about the data itself: offline, last seen.
  final String? note;

  /// First letter of the driver's name, for the avatar.
  final String? initial;
  final bool canCall;
  final TrackingLink link;
  final bool stale;
}

/// Builds the panel text for the current moment.
TrackingPanelModel buildTrackingPanel(
  AppLocalizations l10n, {
  required OrderTracking tracking,
  required GeoFix? fix,
  required DateTime now,
  required bool offline,
  required RealtimeState socket,
  required bool hasPhone,
  required String locale,
  Duration? staleAfter,
}) {
  final stage = trackingStageOf(tracking);
  final step = trackingStepOf(tracking);
  final threshold = tracking.staleAfter ?? staleAfter ?? MapGeo.staleAfter;
  final first = tracking.driverFirstName;
  final stale = fix != null && fix.isStale(now, threshold: threshold);
  // The link pill talks about the live position, so it shows only while there is one to follow.
  final following = stage == TrackingStage.onTheWay || (stage == TrackingStage.waitingPickup && fix != null);
  final link = !following
      ? TrackingLink.idle
      : offline
      ? TrackingLink.offline
      : (socket == RealtimeState.connected || (fix != null && !stale) ? TrackingLink.live : TrackingLink.reconnecting);
  final age = fix?.age(now);
  final offlineNote = offline ? l10n.trackOffline : null;
  switch (stage) {
    case TrackingStage.onTheWay:
      final minutes = tracking.eta?.minutes;
      final metres = tracking.eta?.distanceM ?? distanceToTarget(fix, tracking);
      return TrackingPanelModel(
        stage: stage,
        step: step,
        title: first == null || first.isEmpty ? l10n.trackDriverOnWay : l10n.trackDriverNamed(first),
        subtitle: tracking.vehicle,
        etaText: fix == null
            ? null
            : minutes == null
            ? null
            : (minutes <= 1 ? l10n.trackEtaSoon : l10n.trackEta(minutes)),
        distanceText: fix == null || metres == null ? null : l10n.trackAway(MapFormat.distance(l10n, metres)),
        note:
            offlineNote ??
            (fix == null ? l10n.trackLocating : (stale && age != null ? MapFormat.lastSeen(l10n, age) : null)),
        initial: _initial(first),
        canCall: hasPhone,
        link: link,
        stale: stale,
      );
    case TrackingStage.waitingPickup:
      return TrackingPanelModel(
        stage: stage,
        step: step,
        title: l10n.trackPickupTitle,
        subtitle: tracking.branchName == null || tracking.branchName!.isEmpty
            ? l10n.trackPickupBodyPlain
            : l10n.trackPickupBody(tracking.branchName!),
        note: offlineNote,
        initial: _initial(first),
        canCall: hasPhone,
        link: link,
        stale: stale,
      );
    case TrackingStage.delivered:
      final at = tracking.deliveredAt ?? fix?.at;
      return TrackingPanelModel(
        stage: stage,
        step: step,
        title: at == null ? l10n.trackDeliveredTitle : l10n.trackDeliveredAt(QatarTime.time(at, locale)),
        subtitle: l10n.trackDeliveredBody,
        note: offlineNote,
        link: TrackingLink.idle,
      );
    case TrackingStage.closed:
      return TrackingPanelModel(
        stage: stage,
        step: step,
        title: l10n.trackClosedTitle,
        subtitle: l10n.trackClosedBody,
        link: TrackingLink.idle,
      );
    case TrackingStage.notAssigned:
      return TrackingPanelModel(
        stage: stage,
        step: step,
        title: l10n.trackNotAssignedTitle,
        subtitle: l10n.trackWaiting,
        note: offlineNote,
        link: TrackingLink.idle,
      );
  }
}

String? _initial(String? name) {
  final n = (name ?? '').trim();
  return n.isEmpty ? null : String.fromCharCode(n.runes.first).toUpperCase();
}
