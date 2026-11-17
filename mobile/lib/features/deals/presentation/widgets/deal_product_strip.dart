import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_card_data.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_widget.dart';
import 'package:flutter/material.dart';

/// The deal of a product as it reads for the variant the shopper has picked, or null when that variant is not in the deal.
///
/// The product's block carries the cheapest variant's price ("from"). When the variant rows carry deal prices, a variant
/// without one gets no deal (its normal price is already lower than the deal's), and the others show their own. An older
/// server (no variant prices at all) shows the block as it is.
DealInfo? dealForVariant(DealInfo? block, List<ProductVariant> variants, ProductVariant? selected) {
  if (block == null) return null;
  final priced = variants.any((v) => v.dealPrice != null);
  if (!priced || selected == null) return block;
  final price = selected.dealPrice;
  if (price == null) return null;
  return block.forVariant(dealPrice: price, regularPrice: selected.price?.finalPrice);
}

/// The deal strip of the product page: the deal widget (name, countdown, percent, stock bar) with what one order and this
/// customer can buy underneath. A deal that has not started shows "Starts in"; a sold-out deal, or one this customer has
/// used up, says so in one calm line (the regular price applies). Draws nothing for a deal that is over.
class DealProductStrip extends StatelessWidget {
  const DealProductStrip({super.key, required this.deal});

  final DealInfo deal;

  @override
  Widget build(BuildContext context) => DealClockBoundary(child: _StripBody(deal: deal));
}

class _StripBody extends StatelessWidget {
  const _StripBody({required this.deal});

  final DealInfo deal;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final now = DealClockScope.nowOf(context);
    final phase = deal.phaseAt(now);
    switch (phase) {
      case DealPhase.ended || DealPhase.paused || DealPhase.unknown:
        return const SizedBox.shrink();
      case DealPhase.scheduled:
        return DealWidget(data: DealCardData.fromInfo(deal), showButton: false);
      case DealPhase.soldOut:
        return _Notice(title: l10n.dealSoldOutNotice, hint: l10n.dealYourLimitHint);
      case DealPhase.live:
        if (deal.soldOut) return _Notice(title: l10n.dealSoldOutNotice, hint: l10n.dealYourLimitHint);
        if (deal.limitReached) return _Notice(title: l10n.dealYourLimit, hint: l10n.dealYourLimitHint);
        final limits = [
          if (deal.limitPerOrder != null) l10n.dealLimitPerOrder(deal.limitPerOrder!),
          if (deal.customerRemaining != null && deal.limitPerCustomer != null)
            l10n.dealCustomerRemaining(deal.customerRemaining!)
          else if (deal.limitPerCustomer != null)
            l10n.dealLimitPerCustomer(deal.limitPerCustomer!),
        ];
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            DealWidget(data: DealCardData.fromInfo(deal), showButton: false),
            if (limits.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.xs),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.info_outline_rounded, size: 16, color: context.palette.muted),
                    const Gap(AppSpace.xs),
                    Expanded(
                      child: Text(
                        limits.join(' · '),
                        style: context.text.bodySmall?.copyWith(color: context.palette.muted),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        );
    }
  }
}

/// One calm line for a deal the shopper cannot use now: why, and that the regular price applies.
class _Notice extends StatelessWidget {
  const _Notice({required this.title, required this.hint});

  final String title;
  final String hint;

  @override
  Widget build(BuildContext context) => Semantics(
    container: true,
    child: Container(
      padding: const EdgeInsets.all(AppSpace.sm),
      decoration: BoxDecoration(
        color: context.palette.surfaceMuted,
        borderRadius: BorderRadius.circular(AppRadius.input),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.local_fire_department_outlined, size: 20, color: context.palette.muted),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: context.text.titleSmall),
                Text(hint, style: context.text.bodySmall?.copyWith(color: context.palette.muted)),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}
