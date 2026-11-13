import 'package:flutter/material.dart';

import '../l10n/l10n.dart';
import '../theme/tokens.dart';
import '../utils/haptics.dart';

/// Read-only star rating. Half stars mirror correctly in RTL.
class RatingStars extends StatelessWidget {
  const RatingStars({super.key, required this.average, this.count, this.size = 14, this.showValue = true});

  final double average;
  final int? count;
  final double size;
  final bool showValue;

  @override
  Widget build(BuildContext context) {
    final color = context.palette.brass;
    final value = average.clamp(0, 5).toDouble();
    return Semantics(
      label: context.l10n.ratingLabel(value.toStringAsFixed(1)),
      excludeSemantics: true,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 1; i <= 5; i++)
            // Icons.star_half_rounded has matchTextDirection, so it mirrors in RTL.
            Icon(
              value >= i
                  ? Icons.star_rounded
                  : value >= i - 0.5
                  ? Icons.star_half_rounded
                  : Icons.star_outline_rounded,
              size: size,
              color: color,
            ),
          if (showValue) ...[
            const Gap(AppSpace.xxs),
            Flexible(
              child: Text(
                count != null ? '${value.toStringAsFixed(1)} ($count)' : value.toStringAsFixed(1),
                style: context.text.bodySmall,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Interactive 1–5 star input with ≥48dp targets.
class RatingInput extends StatelessWidget {
  const RatingInput({super.key, required this.value, required this.onChanged, this.size = 36});

  final int value;
  final ValueChanged<int> onChanged;
  final double size;

  @override
  Widget build(BuildContext context) {
    final color = context.palette.brass;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 1; i <= 5; i++)
          Semantics(
            button: true,
            selected: value == i,
            label: context.l10n.starLabel(i),
            child: InkResponse(
              radius: 28,
              onTap: () {
                Haptics.tap();
                onChanged(i);
              },
              child: SizedBox(
                width: kMinTouchTarget,
                height: kMinTouchTarget,
                child: AnimatedScale(
                  scale: value >= i ? 1.08 : 1,
                  duration: AppDurations.fast,
                  child: Icon(value >= i ? Icons.star_rounded : Icons.star_outline_rounded, size: size, color: color),
                ),
              ),
            ),
          ),
      ],
    );
  }
}
