import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:flutter/material.dart';

/// The delivery line of a quote's totals.
///
/// Delivery always costs the fee of its zone, whatever the basket is worth, so for a delivery order this line is always there
/// (a fee of zero reads "Free"). The one way to waive the fee is a free-delivery coupon: then the fee is not hidden, it stays on the
/// line struck through, followed by "Free" and the code that waived it ([waived], [code]).
class DeliveryFeeRow extends StatelessWidget {
  const DeliveryFeeRow({super.key, required this.fee, this.waived = false, this.code});

  /// The full delivery fee as the server quoted it (before any coupon).
  final Money fee;

  /// A free-delivery coupon is applied: the fee is shown struck through with "Free".
  final bool waived;

  /// The coupon code that waived the fee, shown next to the label.
  final String? code;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    if (!waived) return SummaryRow(label: l10n.deliveryFee, value: fee.isZero ? l10n.free : fee.formatted);

    final palette = context.palette;
    final style = context.text.bodyMedium;
    final coupon = code;
    // A fee of zero has nothing to strike through: it simply reads "Free".
    final struck = !fee.isZero;
    return Semantics(
      container: true,
      // One sentence for a screen reader: the picture of a struck-through number followed by "Free" is easy to misread aloud.
      label: [
        l10n.deliveryFee,
        coupon != null ? l10n.deliveryFreeWithCode(coupon) : l10n.free,
        if (struck) l10n.deliveryFeeWas(fee.formatted),
      ].join('. '),
      child: ExcludeSemantics(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 5),
          // The amount side may take up to just over half of the line and wraps when it must (a small phone, Arabic, large text),
          // so the label and the code keep their room instead of being squeezed into a one-word column.
          child: LayoutBuilder(
            builder: (context, constraints) => Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Wrap(
                    spacing: AppSpace.xs,
                    runSpacing: AppSpace.xxs,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(l10n.deliveryFee, style: style?.copyWith(color: palette.muted)),
                      if (coupon != null) _CouponChip(code: coupon),
                    ],
                  ),
                ),
                const Gap(AppSpace.sm),
                ConstrainedBox(
                  constraints: BoxConstraints(maxWidth: constraints.maxWidth * 0.55),
                  child: Wrap(
                    alignment: WrapAlignment.end,
                    spacing: AppSpace.xs,
                    runSpacing: AppSpace.xxs,
                    children: [
                      if (struck)
                        Text(
                          fee.formatted,
                          style: style?.copyWith(color: palette.muted, decoration: TextDecoration.lineThrough),
                        ),
                      Text(
                        l10n.free,
                        style: style?.copyWith(color: palette.success, fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The code that waived the fee, in the quiet green the rest of the totals use for savings.
class _CouponChip extends StatelessWidget {
  const _CouponChip({required this.code});

  final String code;

  @override
  Widget build(BuildContext context) {
    final success = context.palette.success;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: AppSpace.xs, vertical: 2),
      decoration: BoxDecoration(
        color: success.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        code,
        style: context.text.labelSmall?.copyWith(color: success, fontWeight: FontWeight.w700, letterSpacing: 0.4),
      ),
    );
  }
}
