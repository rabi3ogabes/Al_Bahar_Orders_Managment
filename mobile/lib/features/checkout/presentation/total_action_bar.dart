import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';

/// The bottom bar of the cart and of checkout: a small label over the amount, and the one main action beside it.
///
/// On a narrow phone, or with larger text, the amount no longer leaves the button room to keep its words, so the amount takes
/// its own line above a full-width button instead of squeezing it.
class TotalActionBar extends StatelessWidget {
  const TotalActionBar({super.key, required this.label, required this.amount, required this.action});

  /// "Subtotal" / "Total".
  final String label;

  /// The amount itself (an animated switcher while totals are being recalculated).
  final Widget amount;

  /// The main button.
  final Widget action;

  /// Below this width, or above this text size, the amount sits on its own line.
  static const narrowWidth = 360.0;
  static const largeText = 1.15;

  @override
  Widget build(BuildContext context) {
    final small = context.text.bodySmall;
    return LayoutBuilder(
      builder: (context, constraints) {
        final stacked =
            constraints.maxWidth < narrowWidth || MediaQuery.textScalerOf(context).scale(16) / 16 > largeText;
        if (!stacked) {
          return Row(
            children: [
              Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: small),
                  amount,
                ],
              ),
              const Gap(AppSpace.md),
              Expanded(child: action),
            ],
          );
        }
        return Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(child: Text(label, style: small)),
                const Gap(AppSpace.sm),
                Flexible(
                  child: FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerEnd, child: amount),
                ),
              ],
            ),
            const Gap(AppSpace.xs),
            action,
          ],
        );
      },
    );
  }
}
