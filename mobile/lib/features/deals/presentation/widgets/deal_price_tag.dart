import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/theme/contrast.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:flutter/material.dart';

/// The deal's own colour for a price or a small badge: its accent, kept readable on [on] (the card or page it sits on).
Color dealAccentOn(BuildContext context, DealInfo deal, Color on) {
  final accent = deal.design.colors?.accent ?? context.palette.danger;
  return Contrast.ensure(accent, on, min: 4.5);
}

/// Whether [deal] shows its price now: it is on sale, has units left and a price to show, and this customer still has
/// units at that price. Reads the screen's server-corrected clock when there is one (without rebuilding the caller every
/// second); with none it trusts the phase the server reported.
bool dealShowsPrice(BuildContext context, DealInfo? deal) {
  if (deal == null) return false;
  return deal.showsDealPriceAt(DealClockScope.peek(context)?.now());
}

/// A product's price while a deal is on: the deal price, the regular price struck through and the "-30%" pill.
/// Never computes a price: it shows what the server sent.
class DealPriceTag extends StatelessWidget {
  const DealPriceTag({
    super.key,
    required this.deal,
    this.price,
    this.size = PriceTagSize.medium,
    this.showBadge = true,
  });

  final DealInfo deal;

  /// The product's normal price, for the struck-through line when the deal block carries no regular price.
  final EffectivePrice? price;
  final PriceTagSize size;
  final bool showBadge;

  @override
  Widget build(BuildContext context) {
    final text = context.text;
    final dealPrice = deal.dealPrice;
    if (dealPrice == null) return const SizedBox.shrink();
    final regular = deal.regularPrice ?? price?.regular;
    final accent = dealAccentOn(context, deal, context.colors.surface);
    final main = switch (size) {
      PriceTagSize.small => text.titleSmall,
      PriceTagSize.medium => text.titleMedium,
      PriceTagSize.large => text.headlineSmall?.copyWith(fontWeight: FontWeight.w600),
    };
    final struck = regular != null && regular.amount != dealPrice.amount;
    final percent = deal.percent;
    return Semantics(
      label: struck ? '${dealPrice.formatted}, ${regular.formatted}' : dealPrice.formatted,
      excludeSemantics: true,
      child: Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: AppSpace.xs,
        runSpacing: 2,
        children: [
          Text(
            dealPrice.formatted,
            style: main?.copyWith(color: accent, fontWeight: FontWeight.w800),
          ),
          if (struck)
            Text(
              regular.formatted,
              style: (size == PriceTagSize.large ? text.bodyMedium : text.bodySmall)?.copyWith(
                decoration: TextDecoration.lineThrough,
                color: context.palette.muted,
              ),
            ),
          if (showBadge && percent != null)
            Container(
              padding: const EdgeInsetsDirectional.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(color: accent.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(6)),
              child: Text(
                context.l10n.discountOff(percent),
                textDirection: TextDirection.ltr,
                style: text.labelSmall?.copyWith(color: accent, letterSpacing: 0, fontWeight: FontWeight.w800),
              ),
            ),
        ],
      ),
    );
  }
}
