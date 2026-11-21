import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/cart/domain/guest_cart.dart';
import 'package:albahar_customer/features/cart/presentation/state/guest_cart_notice.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What changed behind the customer's cart since they last looked (a price, the stock, a product that is gone, or what the server could not take
/// when a guest's cart was folded into the account on sign-in), said once in plain words with an OK button. Nothing when nothing changed.
class GuestCartNoticeBanner extends ConsumerWidget {
  const GuestCartNoticeBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final changes = ref.watch(guestCartNoticeProvider);
    if (changes.isEmpty) return const SizedBox.shrink();
    final l10n = context.l10n;
    final palette = context.palette;
    String text(GuestCartChange c) => switch (c.kind) {
      GuestChangeKind.priceChanged => l10n.guestChangePrice(c.name, c.from?.formatted ?? '', c.to?.formatted ?? ''),
      GuestChangeKind.reduced => l10n.guestChangeReduced(c.name, c.available ?? 0),
      GuestChangeKind.unavailable => l10n.guestChangeUnavailable(c.name),
      GuestChangeKind.assemblyRemoved => l10n.guestChangeAssembly(c.name),
    };
    return Container(
      key: const ValueKey('guest-cart-notice'),
      margin: const EdgeInsets.only(bottom: AppSpace.md),
      padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.md, AppSpace.md, AppSpace.xs, AppSpace.xs),
      decoration: BoxDecoration(
        color: palette.warning.withValues(alpha: 0.1),
        borderRadius: AppRadius.cardAll,
        border: Border.all(color: palette.warning.withValues(alpha: 0.35)),
      ),
      child: Semantics(
        liveRegion: true,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.info_outline_rounded, color: palette.warning),
                const SizedBox(width: AppSpace.sm),
                Expanded(child: Text(l10n.guestChangesTitle, style: context.text.titleSmall)),
              ],
            ),
            const SizedBox(height: AppSpace.xs),
            for (final c in changes)
              Padding(
                padding: const EdgeInsets.only(bottom: 2),
                child: Text(text(c), style: context.text.bodyMedium),
              ),
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton(
                onPressed: () => ref.read(guestCartNoticeProvider.notifier).clear(),
                child: Text(l10n.ok),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
