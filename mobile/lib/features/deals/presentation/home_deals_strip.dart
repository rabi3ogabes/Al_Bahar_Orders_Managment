import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/domain/deal_models.dart';
import 'package:albahar_customer/features/deals/presentation/deals_screen.dart';
import 'package:albahar_customer/features/deals/presentation/state/deals_providers.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_card_data.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_widget.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// The deals the admin put on the home page: a "Deals" header and one small deal widget each, with its countdown. Draws
/// nothing while there is nothing to show (loading, offline, an older server, no deal on sale), so Home never jumps.
class HomeDealsStrip extends ConsumerWidget {
  const HomeDealsStrip({super.key, this.maxDeals = 4});

  final int maxDeals;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(homeDealsProvider).value;
    final deals = [
      for (final d in feed?.live ?? const <Deal>[])
        if (d.phase == DealPhase.live) d,
    ].take(maxDeals).toList();
    if (deals.isEmpty) return const SizedBox.shrink();
    final due = [for (final d in deals) ?d.dueAt]..sort();
    final l10n = context.l10n;
    return DealClockHost(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          DealDueWatcher(dueAt: due.isEmpty ? null : due.first, onDue: () => ref.invalidate(homeDealsProvider)),
          SectionHeader(title: l10n.dealsTitle, onAction: () => context.push('/deals')),
          if (deals.length == 1)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter),
              child: _StripDeal(deal: deals.first),
            )
          else
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter),
              child: IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (var i = 0; i < deals.length; i++) ...[
                      if (i > 0) const Gap(AppSpace.md),
                      SizedBox(width: 300, child: _StripDeal(deal: deals[i])),
                    ],
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _StripDeal extends StatelessWidget {
  const _StripDeal({required this.deal});

  final Deal deal;

  @override
  Widget build(BuildContext context) =>
      DealWidget(data: DealCardData.fromDeal(deal), compact: true, onTap: () => openDeal(context, deal.id));
}
