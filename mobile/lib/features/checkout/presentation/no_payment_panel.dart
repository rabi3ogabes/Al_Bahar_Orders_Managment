import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// Shown when the quote leaves the customer no way to pay: never a blank list. It offers what can be done next (collect at a showroom
/// and pay there, call the showroom, share the basket with someone in the family); each one only when there is something real behind it.
class NoPaymentPanel extends StatelessWidget {
  const NoPaymentPanel({super.key, this.collectFrom, this.onCollect, this.phone, this.onShare});

  /// The showroom where the order can be collected and paid for, and what choosing it does.
  final String? collectFrom;
  final VoidCallback? onCollect;

  /// The showroom's number, as the API sent it.
  final String? phone;
  final VoidCallback? onShare;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    return Container(
      key: const ValueKey('no-payment-panel'),
      margin: const EdgeInsets.only(top: AppSpace.xs),
      padding: const EdgeInsets.all(AppSpace.md),
      decoration: BoxDecoration(color: p.brassTint, borderRadius: AppRadius.cardAll),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Semantics(liveRegion: true, header: true, child: Text(l10n.payNoneTitle, style: context.text.titleMedium)),
          const Gap(AppSpace.xxs),
          Text(l10n.payNoneText, style: context.text.bodyMedium),
          const Gap(AppSpace.md),
          if (collectFrom != null && onCollect != null) ...[
            AppButton(
              variant: AppButtonVariant.accent,
              icon: Icons.storefront_outlined,
              label: l10n.payNoneCollect(collectFrom!),
              labelMaxLines: 2,
              onPressed: onCollect,
            ),
            const Gap(AppSpace.xs),
          ],
          if (phone != null) ...[
            AppButton.secondary(
              icon: Icons.call_outlined,
              label: l10n.payNoneCall,
              onPressed: () => launchUrl(Uri(scheme: 'tel', path: phone)),
            ),
            const Gap(AppSpace.xs),
          ],
          if (onShare != null)
            AppButton.secondary(icon: Icons.ios_share_rounded, label: l10n.payNoneShare, onPressed: onShare),
          const Gap(AppSpace.sm),
          Text(l10n.payNoneSaved, style: context.text.bodySmall?.copyWith(color: p.muted)),
        ],
      ),
    );
  }
}
