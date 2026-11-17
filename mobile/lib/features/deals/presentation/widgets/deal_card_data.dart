import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/contrast.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/deals/domain/deal_countdown.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/domain/deal_models.dart';
import 'package:flutter/material.dart';

/// What [DealWidget] draws, whether it comes from a deal of the Deals list or from the `deal` block of a product.
@immutable
class DealCardData {
  const DealCardData({
    required this.name,
    required this.phase,
    this.tagline,
    this.startsAt,
    this.endsAt,
    this.percent,
    this.remaining,
    this.remainingPercent,
    this.design = DealDesign.fallback,
  });

  factory DealCardData.fromDeal(Deal deal) => DealCardData(
    name: deal.name,
    tagline: deal.tagline,
    phase: deal.phase,
    startsAt: deal.startsAt,
    endsAt: deal.endsAt,
    percent: deal.percent,
    design: deal.design,
  );

  factory DealCardData.fromInfo(DealInfo info) => DealCardData(
    name: info.name,
    phase: info.phase,
    startsAt: info.startsAt,
    endsAt: info.endsAt,
    percent: info.percent,
    remaining: info.remaining,
    remainingPercent: info.remainingPercent,
    design: info.design,
  );

  final String name;
  final String? tagline;
  final DealPhase phase;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final int? percent;

  /// Units left and the share of the quantity that is left (null: unlimited, no stock line).
  final int? remaining;
  final int? remainingPercent;
  final DealDesign design;

  DealPhase phaseAt(DateTime serverNow) => dealPhaseAt(phase, startsAt, endsAt, serverNow);
}

/// The icon an admin picked for a deal.
IconData dealIconData(DealIconKey key) => switch (key) {
  DealIconKey.flame => Icons.local_fire_department_rounded,
  DealIconKey.bolt => Icons.bolt_rounded,
  DealIconKey.timer => Icons.timer_rounded,
  DealIconKey.tag => Icons.sell_rounded,
  DealIconKey.gift => Icons.card_giftcard_rounded,
  DealIconKey.star => Icons.star_rounded,
  DealIconKey.crown => Icons.workspace_premium_rounded,
};

/// The three colours of a widget whose server design carried none (an old server, a broken payload): the app's own brand.
/// Never used when the server sent colours.
abstract final class DealPalette {
  static DealColors fromTheme(BuildContext context) {
    final scheme = context.colors;
    final bg = scheme.primary;
    return DealColors(
      background: bg,
      text: scheme.onPrimary,
      accent: Contrast.ensure(context.palette.onPrimarySoft, bg, min: 3),
    );
  }
}

/// A countdown for a screen reader: whole days, hours and minutes (never seconds), so the label changes once a minute.
String dealSpokenTime(AppLocalizations l10n, int secondsLeft) {
  final s = dealSpokenParts(secondsLeft);
  final parts = [
    if (s.days > 0) l10n.dealSpokenDays(s.days),
    if (s.hours > 0) l10n.dealSpokenHours(s.hours),
    if (s.minutes > 0) l10n.dealSpokenMinutes(s.minutes),
  ];
  if (parts.isEmpty) return l10n.dealSpokenLessThanMinute;
  return parts.join(l10n.dealSpokenSeparator);
}

/// The word under (or after) one unit of the countdown: `Days`, or `d` in the short form.
String dealUnitLabel(AppLocalizations l10n, DealUnit unit, {bool short = false}) => switch (unit) {
  DealUnit.days => short ? l10n.dealShortDays : l10n.dealUnitDays,
  DealUnit.hours => short ? l10n.dealShortHours : l10n.dealUnitHours,
  DealUnit.minutes => short ? l10n.dealShortMinutes : l10n.dealUnitMinutes,
  DealUnit.seconds => short ? l10n.dealShortSeconds : l10n.dealUnitSeconds,
};
