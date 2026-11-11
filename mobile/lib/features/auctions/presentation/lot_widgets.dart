import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/countdown.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

String lotStatusLabel(AppLocalizations l10n, String status) => switch (status) {
  'open' => l10n.lotStatusOpen,
  'closing' => l10n.lotStatusClosing,
  'closed' => l10n.lotStatusClosed,
  'sold' => l10n.lotStatusSold,
  'unsold' => l10n.lotStatusUnsold,
  'cancelled' => l10n.lotStatusCancelled,
  _ => l10n.lotStatusScheduled,
};

String? myStatusLabel(AppLocalizations l10n, String? myStatus) => switch (myStatus) {
  'leading' => l10n.youAreLeading,
  'outbid' => l10n.youWereOutbid,
  'won' => l10n.youWon,
  'lost' => l10n.youLost,
  _ => null,
};

class LotCard extends StatelessWidget {
  const LotCard({super.key, required this.lot, this.serverOffset = Duration.zero, this.trailing});
  final Lot lot;
  final Duration serverOffset;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final mine = myStatusLabel(l10n, lot.myStatus);
    return SurfaceCard(
      padding: const EdgeInsets.all(AppSpace.sm),
      onTap: () => context.push('/auctions/lots/${lot.id}'),
      child: Row(
        children: [
          AppNetworkImage(url: lot.imageUrl, width: 92, height: 108, borderRadius: BorderRadius.circular(12)),
          const Gap(AppSpace.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.lotNumber(lot.lotNumber),
                  style: context.text.labelSmall?.copyWith(color: context.palette.brass),
                ),
                Text(lot.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: context.text.titleSmall),
                const Gap(AppSpace.xxs),
                Text(lot.currentPrice != null ? l10n.currentBid : l10n.startingPrice, style: context.text.bodySmall),
                Text(lot.displayPrice.formatted, style: context.text.titleMedium),
                const Gap(AppSpace.xxs),
                Wrap(
                  spacing: AppSpace.xs,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    StatusChip(label: lotStatusLabel(l10n, lot.status), tone: toneForStatus(lot.status), dense: true),
                    if (mine != null) StatusChip(label: mine, tone: toneForStatus(lot.myStatus), dense: true),
                    if (lot.isOpen && lot.endsAt != null)
                      CountdownText(target: lot.endsAt!, serverOffset: serverOffset, style: context.text.labelMedium),
                  ],
                ),
              ],
            ),
          ),
          trailing ?? const Icon(Icons.chevron_right_rounded),
        ],
      ),
    );
  }
}
