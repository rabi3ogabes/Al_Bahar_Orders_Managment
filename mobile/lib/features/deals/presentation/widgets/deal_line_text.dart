import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/domain/deal_lines.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_price_tag.dart';
import 'package:flutter/material.dart';

/// "Deal price on 2 of 3 · limit 2 per order": what a cart or quote line says about its deal. Null when there is nothing to
/// say (no unit at the deal price and no reason given).
///
/// * All units at the deal price: "Deal price on all 3".
/// * Some: "Deal price on 2 of 3", then why fewer ("limit 2 per order", "your account limit is reached", "deal sold out",
///   "deal ended").
/// * None: only the reason ("You reached your limit for this deal", "deal sold out", "deal ended").
String? dealLineText(AppLocalizations l10n, DealLineUnits units) {
  final note = switch (units.note) {
    DealNote.limitPerOrder => l10n.dealNoteLimitOrder(units.unitsAtDealPrice),
    DealNote.limitPerCustomer => l10n.dealNoteLimitCustomer,
    DealNote.soldOut => l10n.dealNoteSoldOut,
    DealNote.ended => l10n.dealNoteEnded,
    null => null,
  };
  if (units.unitsAtDealPrice <= 0) {
    return switch (units.note) {
      DealNote.limitPerCustomer => l10n.dealYourLimit,
      _ => note,
    };
  }
  final head = units.unitsAtRegularPrice <= 0
      ? l10n.dealPriceOnAll(units.total)
      : l10n.dealPriceOn(units.unitsAtDealPrice, units.total);
  return [head, ?note].join(' · ');
}

/// The deal of a line, in one calm row under the line: the flame, the deal's name and the wording above.
class DealLineNote extends StatelessWidget {
  const DealLineNote({super.key, required this.name, this.units, this.color});

  final String name;

  /// How many units get the deal price; without it the row names the deal only.
  final DealLineUnits? units;

  /// The deal's accent colour; the app's own accent when the server sent none.
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final text = units == null ? null : dealLineText(context.l10n, units!);
    final tone = color ?? context.palette.brassDeep;
    final full = [if (name.isNotEmpty) name, ?text].join(' · ');
    if (full.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 1),
            child: Icon(Icons.local_fire_department_rounded, size: 14, color: tone),
          ),
          const Gap(4),
          Expanded(
            child: Text(
              full,
              style: context.text.bodySmall?.copyWith(color: tone, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }
}

/// The deal of a cart line: its name and how many units get the deal price ("Deal price on 2 of 3 · limit 2 per order").
/// Draws nothing when the line is in no deal, or the deal is not on sale now.
class DealCartNote extends StatelessWidget {
  const DealCartNote({super.key, required this.deal, this.units});

  final DealInfo? deal;
  final DealLineUnits? units;

  @override
  Widget build(BuildContext context) {
    final d = deal;
    if (d == null) return const SizedBox.shrink();
    final now = DealClockScope.peek(context)?.now();
    final phase = now == null ? d.phase : d.phaseAt(now);
    if (phase != DealPhase.live && phase != DealPhase.soldOut) return const SizedBox.shrink();
    return DealLineNote(name: d.name, units: units, color: dealAccentOn(context, d, context.colors.surface));
  }
}

/// What an order line says about the deal it was sold under: the deal's name, how many units got the deal price and what each saved.
String dealOrderLineText(AppLocalizations l10n, OrderLineDeal deal, int quantity) {
  final saved = deal.unitSavings;
  final total = quantity < deal.units ? deal.units : quantity;
  return [
    if (deal.name.isNotEmpty) deal.name,
    total == deal.units ? l10n.dealPriceOnAll(total) : l10n.dealPriceOn(deal.units, total),
    if (saved != null && !saved.isZero) l10n.dealSavedEach(saved.formatted),
  ].join(' · ');
}

/// The calm banner of a deal that changed while the customer was paying: the total on screen is the new one, and nothing is
/// placed until they confirm again.
class DealChangedBanner extends StatelessWidget {
  const DealChangedBanner({super.key, this.previousTotal, this.onDismiss});

  final Money? previousTotal;
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final tone = context.palette.warning;
    return Semantics(
      liveRegion: true,
      container: true,
      child: Container(
        padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.sm, AppSpace.xs, AppSpace.xxs, AppSpace.xs),
        decoration: BoxDecoration(
          color: tone.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(AppRadius.input),
          border: Border.all(color: tone.withValues(alpha: 0.4)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Icon(Icons.local_fire_department_rounded, size: 18, color: tone),
            ),
            const Gap(AppSpace.xs),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(l10n.dealChangedBanner, style: context.text.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
                    if (previousTotal != null)
                      Text(
                        l10n.previousTotal(previousTotal!.formatted),
                        style: context.text.bodySmall?.copyWith(decoration: TextDecoration.lineThrough),
                      ),
                  ],
                ),
              ),
            ),
            if (onDismiss != null)
              IconButton(tooltip: l10n.close, icon: const Icon(Icons.close_rounded, size: 18), onPressed: onDismiss),
          ],
        ),
      ),
    );
  }
}
