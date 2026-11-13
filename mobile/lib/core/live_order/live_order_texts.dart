import 'dart:ui' show Locale;

import '../../l10n/generated/app_localizations.dart';
import '../utils/qatar_time.dart';
import 'live_order.dart';

/// The words of the live order notification in the customer's language: the status line, the headline ("Arrives at
/// 9:20 PM – 9:35 PM", "Delivered at 9:31 PM") and the stage names. Server text always wins; this fills what is missing.
class LiveOrderTexts {
  LiveOrderTexts(this.l10n, this.localeCode, {this.arabicDigits = false});

  factory LiveOrderTexts.forLocale(String code, {bool arabicDigits = false}) =>
      LiveOrderTexts(lookupAppLocalizations(Locale(code)), code, arabicDigits: arabicDigits);

  final AppLocalizations l10n;
  final String localeCode;

  /// Arabic-Indic digits (٩:٢٠) in Arabic times. Off by default: the rest of the app shows Western digits in Arabic.
  final bool arabicDigits;

  static const _indic = '٠١٢٣٤٥٦٧٨٩';

  /// "Your order has been placed".
  String status(LiveOrder o) => switch (o.phase) {
    LiveOrderPhase.placed => l10n.liveOrderStatusPlaced,
    LiveOrderPhase.preparing => l10n.liveOrderStatusPreparing,
    LiveOrderPhase.onTheWay => l10n.liveOrderStatusOnTheWay,
    LiveOrderPhase.ready => l10n.liveOrderStatusReady,
    LiveOrderPhase.delivered => l10n.liveOrderStatusDelivered,
    LiveOrderPhase.collected => l10n.liveOrderStatusCollected,
    LiveOrderPhase.cancelled => l10n.liveOrderStatusCancelled,
    LiveOrderPhase.failed => l10n.liveOrderStatusFailed,
  };

  /// Short name of the stage the order is at ("Preparing").
  String stage(LiveOrder o) => switch (o.phase) {
    LiveOrderPhase.placed => l10n.liveOrderStagePlaced,
    LiveOrderPhase.preparing => l10n.liveOrderStagePreparing,
    LiveOrderPhase.onTheWay => l10n.liveOrderStageOnTheWay,
    LiveOrderPhase.ready => l10n.liveOrderStageReady,
    LiveOrderPhase.delivered => l10n.liveOrderStageDelivered,
    LiveOrderPhase.collected => l10n.liveOrderStageCollected,
    LiveOrderPhase.cancelled => l10n.liveOrderHeadlineCancelled,
    LiveOrderPhase.failed => l10n.liveOrderHeadlineFailed,
  };

  /// Doha wall-clock time in the app's locale ("9:20 PM", "9:20 م"). Falls back to 24-hour digits if the date formats
  /// are not loaded yet: a notification must never fail over a time label.
  String time(DateTime t) {
    String text;
    try {
      text = QatarTime.time(t, localeCode);
    } catch (_) {
      final q = QatarTime.toQatar(t);
      text = '${q.hour.toString().padLeft(2, '0')}:${q.minute.toString().padLeft(2, '0')}';
    }
    if (!arabicDigits || localeCode != 'ar') return text;
    return text.replaceAllMapped(RegExp('[0-9]'), (m) => _indic[int.parse(m[0]!)]);
  }

  /// "Arrives at 9:20 PM – 9:35 PM" / "Arrives at 9:35 PM"; null when there is no estimate.
  String? etaWindow(LiveOrder o) {
    final from = o.etaFrom;
    final to = o.etaTo;
    if (from == null && to == null) return null;
    if (from != null && to != null && time(from) != time(to)) {
      final early = from.isBefore(to) ? from : to;
      final late = from.isBefore(to) ? to : from;
      return l10n.liveOrderArrivesBetween(time(early), time(late));
    }
    return l10n.liveOrderArrivesAt(time((to ?? from)!));
  }

  /// The bold line. Finished orders say when they finished; active ones when they arrive.
  String headline(LiveOrder o, {DateTime? now}) {
    final at = o.updatedAt ?? now ?? DateTime.now();
    return switch (o.phase) {
      LiveOrderPhase.delivered => l10n.liveOrderHeadlineDelivered(time(at)),
      LiveOrderPhase.collected => l10n.liveOrderHeadlineCollected(time(at)),
      LiveOrderPhase.cancelled => l10n.liveOrderHeadlineCancelled,
      LiveOrderPhase.failed => l10n.liveOrderHeadlineFailed,
      _ => o.etaLabel ?? etaWindow(o) ?? o.title ?? l10n.liveOrderHeadlineNumber(o.number),
    };
  }

  String subtitle(LiveOrder o) => o.body ?? status(o);

  /// [o] with its headline and status line filled in (server text kept).
  LiveOrder localise(LiveOrder o, {DateTime? now}) =>
      o.copyWith(headline: o.headline ?? headline(o, now: now), subtitle: o.subtitle ?? subtitle(o));
}
