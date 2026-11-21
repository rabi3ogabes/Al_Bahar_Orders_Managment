import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/features/checkout/domain/delivery_promise.dart';
import 'package:flutter/material.dart';

/// The delivery or pickup date in one calm block: "Delivery today before 10 pm" with its small print ("if you order by 3 pm"), one row per branch
/// when the order splits, and a hint that the exact date depends on the address. It never alarms: a date that cannot be promised yet is
/// "We will confirm your delivery date" and buying stays open. Nothing at all while the dashboard switch is off or before there is an answer
/// (a skeleton while one is on its way). The sentence is the server's, in the customer's language.
class PromiseNote extends StatelessWidget {
  const PromiseNote({super.key, required this.promise, this.loading = false, this.compact = false});

  final BasketPromise? promise;
  final bool loading;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final p = promise;
    if (p == null || !p.visible) {
      return loading ? Skeleton(height: compact ? 44 : 56, radius: AppRadius.card) : const SizedBox.shrink();
    }
    final l10n = context.l10n;
    final palette = context.palette;
    final soft = !p.scheduled;
    final icon = soft
        ? Icons.event_available_outlined
        : (p.fulfillmentType == 'pickup' ? Icons.storefront_outlined : Icons.local_shipping_outlined);
    return Container(
      key: const ValueKey('promise-note'),
      padding: EdgeInsets.symmetric(horizontal: AppSpace.md, vertical: compact ? AppSpace.sm : AppSpace.md),
      decoration: BoxDecoration(
        color: soft ? palette.surfaceMuted : palette.brassTint,
        borderRadius: AppRadius.cardAll,
      ),
      child: Semantics(
        container: true,
        liveRegion: true,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Icon(icon, size: compact ? 20 : 22, color: palette.brassDeep),
            ),
            const Gap(AppSpace.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(p.message!, style: context.text.titleSmall),
                  if (p.detail != null && p.detail!.isNotEmpty)
                    Text(p.detail!, style: context.text.bodySmall?.copyWith(color: palette.muted)),
                  if (p.showsParcels) ...[
                    const Gap(AppSpace.xs),
                    for (final parcel in p.parcels)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: Text(
                                parcel.branchName,
                                style: context.text.bodySmall?.copyWith(color: palette.muted),
                              ),
                            ),
                            const Gap(AppSpace.sm),
                            Flexible(
                              child: Text(
                                parcel.scheduled
                                    ? parcel.message
                                    : (parcel.type == 'pickup'
                                          ? l10n.promiseConfirmPickup
                                          : l10n.promiseConfirmDelivery),
                                textAlign: TextAlign.end,
                                style: context.text.bodySmall?.copyWith(
                                  fontWeight: parcel.scheduled ? FontWeight.w600 : null,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                  ],
                  if (p.needsAddress) ...[
                    const Gap(AppSpace.xxs),
                    Text(l10n.promiseAddressHint, style: context.text.bodySmall?.copyWith(color: palette.muted)),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
