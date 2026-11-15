import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/material.dart';

/// The pieces of the "Missing an item?" window: a line of the parcel with its choices, a replacement to pick, a notice box.

/// How much a replacement costs next to what it replaces, in words ("the customer pays the original price", "cheaper", "same").
String substitutePriceNote(OpsStrings t, AdjustItem original, Substitute sub) {
  final a = sub.unitPrice.value;
  final b = original.unitPrice.value;
  if (a > b) return t.priceCapped(original.unitPrice.amount);
  if (a < b) return t.priceLower(sub.unitPrice.amount);
  return t.priceSame(original.unitPrice.amount);
}

/// A rounded box with one icon and a sentence: what the server will do, or what to watch out for.
class AdjustNotice extends StatelessWidget {
  const AdjustNotice({super.key, required this.icon, required this.text, this.tone = StatusTone.info});
  final IconData icon;
  final String text;
  final StatusTone tone;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final (Color fg, Color bg) = switch (tone) {
      StatusTone.warning => (p.warning, dark ? p.warning.withValues(alpha: 0.14) : AppPalette.warningTint),
      StatusTone.danger => (p.danger, dark ? p.danger.withValues(alpha: 0.14) : AppPalette.dangerTint),
      StatusTone.success => (p.success, dark ? p.success.withValues(alpha: 0.14) : AppPalette.successTint),
      _ => (p.info, dark ? p.info.withValues(alpha: 0.14) : AppPalette.infoTint),
    };
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpace.sm),
      padding: const EdgeInsets.all(AppSpace.sm),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.input)),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 20, color: fg),
          const Gap(AppSpace.xs),
          Expanded(
            child: Text(text, style: context.text.bodyMedium?.copyWith(color: context.colors.onSurface)),
          ),
        ],
      ),
    );
  }
}

/// One choice inside an opened line: a big full-width row with an icon, the words, and a tick when it is the chosen one.
class AdjustChoiceRow extends StatelessWidget {
  const AdjustChoiceRow({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.selected = false,
    this.danger = false,
  });
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool selected;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    final color = danger ? p.danger : context.colors.primary;
    return Padding(
      padding: const EdgeInsets.only(top: AppSpace.xs),
      child: Semantics(
        button: true,
        selected: selected,
        label: label,
        excludeSemantics: true,
        onTap: onTap,
        child: Material(
          color: selected ? color.withValues(alpha: 0.10) : context.colors.surface,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppRadius.input),
            side: BorderSide(color: selected ? color : p.line, width: selected ? 1.5 : 1),
          ),
          child: InkWell(
            borderRadius: BorderRadius.circular(AppRadius.input),
            onTap: onTap,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 52),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
                child: Row(
                  children: [
                    Icon(icon, color: color, size: 22),
                    const Gap(AppSpace.sm),
                    Expanded(
                      child: Text(label, style: context.text.titleSmall?.copyWith(color: danger ? p.danger : null)),
                    ),
                    if (selected) Icon(Icons.check_circle_rounded, color: color, size: 22),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A line of the parcel: photo, name, variant, quantity and what it is worth. Tapping it opens its choices (reduce, replace, remove);
/// a line that was changed carries a coloured chip and an Undo.
class AdjustItemTile extends StatelessWidget {
  const AdjustItemTile({
    super.key,
    required this.item,
    required this.change,
    required this.expanded,
    required this.onToggle,
    required this.onReduce,
    required this.onReplace,
    required this.onRemove,
    required this.onUndo,
  });

  final AdjustItem item;
  final AdjustChange? change;
  final bool expanded;
  final VoidCallback onToggle;

  /// Called with the quantity that stays (1 .. quantity - 1).
  final ValueChanged<int> onReduce;
  final VoidCallback onReplace;
  final VoidCallback onRemove;
  final VoidCallback onUndo;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final scheme = context.colors;
    final c = change;
    final gone = c is AdjustRemove || c is AdjustReplace;
    final reduce = MediaQuery.disableAnimationsOf(context);
    final (String chip, StatusTone tone) = switch (c) {
      AdjustReduce(:final newQuantity) => (t.chipReduced(item.quantity, newQuantity), StatusTone.warning),
      AdjustReplace() => (t.chipReplaced, StatusTone.info),
      AdjustRemove() => (t.chipRemoved, StatusTone.danger),
      null => ('', StatusTone.neutral),
    };
    final nameStyle = context.text.titleSmall?.copyWith(
      decoration: gone ? TextDecoration.lineThrough : null,
      color: gone ? p.muted : null,
    );
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpace.sm),
      decoration: BoxDecoration(
        color: c == null ? scheme.surface : p.tint,
        borderRadius: BorderRadius.circular(AppRadius.input),
        border: Border.all(
          color: c == null ? p.line : scheme.primary.withValues(alpha: 0.45),
          width: c == null ? 1 : 1.5,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Semantics(
            button: true,
            expanded: expanded,
            label: '${item.name}${item.variantName == null ? '' : ', ${item.variantName}'}, × ${item.quantity}',
            excludeSemantics: true,
            onTap: onToggle,
            child: InkWell(
              borderRadius: BorderRadius.circular(AppRadius.input),
              onTap: onToggle,
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: 76),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpace.sm),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      AppNetworkImage(
                        url: item.imageUrl,
                        width: 52,
                        height: 60,
                        borderRadius: BorderRadius.circular(AppRadius.sm),
                      ),
                      const Gap(AppSpace.sm),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(item.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: nameStyle),
                            if (item.variantName != null)
                              Text(item.variantName!, style: context.text.bodySmall?.copyWith(color: p.muted)),
                            if (item.mark?.kind == 'reduced' && item.mark?.originalQuantity != null)
                              Text(
                                t.alreadyReduced(item.mark!.originalQuantity!),
                                style: context.text.bodySmall?.copyWith(color: p.warning),
                              ),
                          ],
                        ),
                      ),
                      const Gap(AppSpace.xs),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          // The multiplication sign and the digits read left to right in both languages.
                          Directionality(
                            textDirection: TextDirection.ltr,
                            child: Text(
                              '× ${item.quantity}',
                              style: context.text.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                            ),
                          ),
                          Text(t.money(item.lineTotal.amount), style: context.text.bodySmall?.copyWith(color: p.muted)),
                          Icon(
                            expanded ? Icons.expand_less_rounded : Icons.expand_more_rounded,
                            size: 22,
                            color: p.muted,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          if (c != null)
            Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.sm, 0, AppSpace.xxs, AppSpace.xxs),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        StatusChip(label: chip, tone: tone),
                        if (c is AdjustReplace)
                          Padding(
                            padding: const EdgeInsets.only(top: AppSpace.xxs),
                            child: Text(
                              '${c.substitute.name} × ${c.quantity}',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: context.text.bodySmall,
                            ),
                          ),
                      ],
                    ),
                  ),
                  TextButton(
                    style: TextButton.styleFrom(minimumSize: const Size(kMinTouchTarget, kMinTouchTarget)),
                    onPressed: onUndo,
                    child: Text(t.undo),
                  ),
                ],
              ),
            ),
          AnimatedSize(
            duration: reduce ? Duration.zero : const Duration(milliseconds: 180),
            alignment: Alignment.topCenter,
            child: expanded
                ? Padding(
                    padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.sm, 0, AppSpace.sm, AppSpace.sm),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (item.quantity >= 2) ...[
                          AdjustChoiceRow(
                            icon: Icons.exposure_neg_1_rounded,
                            label: t.reduceQty,
                            selected: c is AdjustReduce,
                            onTap: c is AdjustReduce ? () {} : () => onReduce(item.quantity - 1),
                          ),
                          // Down to 1 is the only choice for a line of two: no stepper to play with.
                          if (c is AdjustReduce && item.quantity > 2)
                            Padding(
                              padding: const EdgeInsets.only(top: AppSpace.xs),
                              child: Row(
                                children: [
                                  Expanded(child: Text(t.newQuantity, style: context.text.bodyMedium)),
                                  QuantityStepper(
                                    value: c.newQuantity,
                                    min: 1,
                                    max: item.quantity - 1,
                                    onChanged: onReduce,
                                  ),
                                ],
                              ),
                            ),
                        ],
                        AdjustChoiceRow(
                          icon: Icons.swap_horiz_rounded,
                          label: t.replaceItem,
                          selected: c is AdjustReplace,
                          onTap: onReplace,
                        ),
                        AdjustChoiceRow(
                          icon: Icons.delete_outline_rounded,
                          label: t.removeItem,
                          danger: true,
                          selected: c is AdjustRemove,
                          onTap: c is AdjustRemove ? () {} : onRemove,
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

/// A product the branch has, to pick as the replacement: photo, name, variant, price and how many are there. The chosen one is
/// outlined, and asks for a quantity and says what the customer will pay.
class SubstituteTile extends StatelessWidget {
  const SubstituteTile({
    super.key,
    required this.sub,
    required this.selected,
    required this.onTap,
    this.original,
    this.quantity = 1,
    this.maxQuantity = 1,
    this.onQuantity,
  });
  final Substitute sub;
  final bool selected;
  final VoidCallback onTap;
  final AdjustItem? original;
  final int quantity;
  final int maxQuantity;
  final ValueChanged<int>? onQuantity;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final scheme = context.colors;
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpace.sm),
      decoration: BoxDecoration(
        color: selected ? p.tint : scheme.surface,
        borderRadius: BorderRadius.circular(AppRadius.input),
        border: Border.all(color: selected ? scheme.primary : p.line, width: selected ? 1.5 : 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Semantics(
            button: true,
            selected: selected,
            label:
                '${sub.name}${sub.variantName == null ? '' : ', ${sub.variantName}'}, ${t.availableCount(sub.available)}',
            excludeSemantics: true,
            onTap: onTap,
            child: InkWell(
              borderRadius: BorderRadius.circular(AppRadius.input),
              onTap: onTap,
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: 72),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpace.sm),
                  child: Row(
                    children: [
                      AppNetworkImage(
                        url: sub.imageUrl,
                        width: 48,
                        height: 56,
                        borderRadius: BorderRadius.circular(AppRadius.sm),
                      ),
                      const Gap(AppSpace.sm),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              sub.name,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: context.text.titleSmall,
                            ),
                            if (sub.variantName != null)
                              Text(sub.variantName!, style: context.text.bodySmall?.copyWith(color: p.muted)),
                            Text(
                              t.money(sub.unitPrice.amount),
                              style: context.text.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                            ),
                          ],
                        ),
                      ),
                      const Gap(AppSpace.xs),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          StatusChip(label: t.availableCount(sub.available), tone: StatusTone.success, dense: true),
                          if (selected) ...[
                            const Gap(AppSpace.xxs),
                            Icon(Icons.check_circle_rounded, color: scheme.primary, size: 22),
                          ],
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          if (selected && original != null)
            Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.sm, 0, AppSpace.sm, AppSpace.sm),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (maxQuantity > 1)
                    Row(
                      children: [
                        Expanded(child: Text(t.quantityLabel, style: context.text.bodyMedium)),
                        QuantityStepper(value: quantity, min: 1, max: maxQuantity, onChanged: onQuantity),
                      ],
                    ),
                  Padding(
                    padding: const EdgeInsets.only(top: AppSpace.xxs),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(Icons.info_outline_rounded, size: 18, color: p.info),
                        const Gap(AppSpace.xs),
                        Expanded(child: Text(substitutePriceNote(t, original!, sub), style: context.text.bodySmall)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// One line of the review: what happens to a product, in plain words.
class AdjustReviewLine extends StatelessWidget {
  const AdjustReviewLine({super.key, required this.line});
  final AdjustLine line;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final r = line.replacement;
    final (Widget status, String? detail) = switch (line.action) {
      'reduce' => (
        Directionality(
          textDirection: TextDirection.ltr,
          child: StatusChip(label: '${line.fromQuantity} → ${line.toQuantity}', tone: StatusTone.warning),
        ),
        null,
      ),
      'replace' => (
        StatusChip(label: t.chipReplaced, tone: StatusTone.info),
        r == null ? null : t.replacedBy(r.variantName == null ? r.name : '${r.name} (${r.variantName})', r.quantity),
      ),
      _ => (StatusChip(label: t.chipRemoved, tone: StatusTone.danger), null),
    };
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpace.xxs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(line.name, style: context.text.bodyLarge),
                if (line.variantName != null)
                  Text(line.variantName!, style: context.text.bodySmall?.copyWith(color: p.muted)),
                if (detail != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text(detail, style: context.text.bodyMedium?.copyWith(color: p.info)),
                  ),
              ],
            ),
          ),
          const Gap(AppSpace.xs),
          status,
        ],
      ),
    );
  }
}
