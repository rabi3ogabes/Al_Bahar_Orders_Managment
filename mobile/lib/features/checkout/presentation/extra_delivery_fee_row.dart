import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:flutter/material.dart';

/// "Extra delivery fee for bulky items" under a delivery line, with what it comes to and, one tap away, which products it comes from.
///
/// The delivery line above keeps the whole fee, this detail is a part of it (never added on top), so it sits indented behind a thin
/// rule and the opened list says so. With a free-delivery coupon ([waived]) the fee is struck through like the delivery line.
/// Without a list of products (an older server) it is a plain line that does not open.
class ExtraDeliveryFeeRow extends StatefulWidget {
  const ExtraDeliveryFeeRow({super.key, required this.extras, this.waived = false});

  final DeliveryExtras extras;

  /// A free-delivery coupon waives the whole delivery fee, this part included.
  final bool waived;

  @override
  State<ExtraDeliveryFeeRow> createState() => _ExtraDeliveryFeeRowState();
}

class _ExtraDeliveryFeeRowState extends State<ExtraDeliveryFeeRow> {
  bool _open = false;

  void _toggle() {
    Haptics.tap();
    setState(() => _open = !_open);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final items = widget.extras.items;
    final expandable = items.isNotEmpty;
    final waived = widget.waived;
    final fee = widget.extras.fee.formatted;
    final small = context.text.bodySmall;
    final amountStyle = small?.copyWith(
      color: waived ? palette.muted : null,
      fontWeight: FontWeight.w600,
      decoration: waived ? TextDecoration.lineThrough : null,
    );

    final header = Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpace.xxs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Expanded(
            child: Row(
              children: [
                Flexible(
                  child: Text(l10n.bulkyDeliveryFee, style: small?.copyWith(color: palette.muted)),
                ),
                if (expandable)
                  Icon(_open ? Icons.expand_less_rounded : Icons.expand_more_rounded, size: 18, color: palette.muted),
              ],
            ),
          ),
          const Gap(AppSpace.sm),
          Text(fee, style: amountStyle),
        ],
      ),
    );

    return Container(
      key: const ValueKey('extra-delivery-fee'),
      margin: const EdgeInsetsDirectional.only(start: AppSpace.xs, bottom: AppSpace.xxs),
      padding: const EdgeInsetsDirectional.only(start: AppSpace.sm),
      decoration: BoxDecoration(
        border: BorderDirectional(start: BorderSide(color: palette.line, width: 2)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Semantics(
            container: true,
            button: expandable,
            expanded: expandable ? _open : null,
            label: [l10n.bulkyDeliveryFee, fee, if (waived) l10n.free].join(', '),
            hint: expandable ? (_open ? l10n.bulkyDeliveryHide : l10n.bulkyDeliveryShow) : null,
            onTap: expandable ? _toggle : null,
            excludeSemantics: true,
            child: expandable
                ? InkWell(
                    key: const ValueKey('extra-delivery-fee-toggle'),
                    onTap: _toggle,
                    borderRadius: BorderRadius.circular(AppRadius.input),
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(minHeight: kMinTouchTarget),
                      child: Align(alignment: AlignmentDirectional.centerStart, child: header),
                    ),
                  )
                : header,
          ),
          AnimatedSize(
            duration: AppDurations.fast,
            alignment: AlignmentDirectional.topStart,
            child: expandable && _open
                ? Padding(
                    key: const ValueKey('extra-delivery-fee-details'),
                    padding: const EdgeInsets.only(bottom: AppSpace.xs),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final item in items) _ExtraLine(item: item, waived: waived),
                        const Gap(AppSpace.xxs),
                        Text(
                          l10n.bulkyDeliveryIncluded,
                          style: context.text.labelSmall?.copyWith(color: palette.muted),
                        ),
                      ],
                    ),
                  )
                : const SizedBox(width: double.infinity),
          ),
        ],
      ),
    );
  }
}

/// One product of the list: its name, how it was charged ("×3 · QAR 50.00 each" or "×1 · charged once") and the amount.
class _ExtraLine extends StatelessWidget {
  const _ExtraLine({required this.item, required this.waived});

  final DeliveryExtra item;
  final bool waived;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final how = item.perUnit
        ? (item.feeEach == null ? '×${item.quantity}' : l10n.bulkyDeliveryEach(item.quantity, item.feeEach!.formatted))
        : l10n.bulkyDeliveryOnce(item.quantity);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: context.text.bodySmall),
                Text(how, style: context.text.labelSmall?.copyWith(color: palette.muted)),
              ],
            ),
          ),
          const Gap(AppSpace.sm),
          Text(
            item.amount.formatted,
            style: context.text.bodySmall?.copyWith(
              color: waived ? palette.muted : null,
              decoration: waived ? TextDecoration.lineThrough : null,
            ),
          ),
        ],
      ),
    );
  }
}
