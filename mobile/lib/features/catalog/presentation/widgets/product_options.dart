import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/product_list_screen.dart' show colorFromHex;
import 'package:flutter/material.dart';

/// One row per colour (for the current choice of the other attributes). Shared by the product page and the quick view.
List<ColourRow> colourRowsFor(ProductDetail product, ProductAttribute colour, Map<String, String> selection) {
  final others = {...selection}..remove(colour.code);
  final rows = <ColourRow>[];
  for (final v in colour.values) {
    final variant = product.resolveVariant({...others, colour.code: v.id});
    if (variant != null && variant.attributeValueIds.contains(v.id)) rows.add(ColourRow(v, variant));
  }
  return rows;
}

/// One colour of a product with the variant that is bought for it.
class ColourRow {
  const ColourRow(this.value, this.variant);
  final AttributeValue value;
  final ProductVariant variant;
}

/// "Colours": every colour on its own row with a − / + stepper, so several colours can go in the bag at once.
class ColourQuantities extends StatelessWidget {
  const ColourQuantities({
    super.key,
    required this.title,
    required this.rows,
    required this.quantities,
    required this.onChanged,
  });
  final String title;
  final List<ColourRow> rows;
  final Map<String, int> quantities;
  final void Function(String variantId, int quantity) onChanged;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(header: true, child: Text(title, style: context.text.titleMedium)),
        const Gap(2),
        Text(l10n.coloursHint, style: context.text.bodySmall),
        const Gap(AppSpace.sm),
        for (final r in rows)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpace.xs),
            child: SurfaceCard(
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
              child: Row(
                children: [
                  Container(
                    width: 30,
                    height: 30,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: r.value.colorHex != null ? colorFromHex(r.value.colorHex!) : context.palette.surfaceMuted,
                      border: Border.all(color: context.palette.line, width: 1.5),
                    ),
                  ),
                  const Gap(AppSpace.sm),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(r.value.name, style: context.text.titleSmall),
                        if (r.variant.price != null)
                          Text(
                            r.variant.price!.finalPrice.formatted,
                            style: context.text.bodySmall?.copyWith(
                              color: context.colors.primary,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                      ],
                    ),
                  ),
                  if (r.variant.purchasable)
                    QuantityStepper(
                      value: quantities[r.variant.id] ?? 0,
                      min: 0,
                      max: 10,
                      compact: true,
                      onChanged: (q) {
                        Haptics.tap();
                        onChanged(r.variant.id, q);
                      },
                    )
                  else
                    StatusChip(label: l10n.colourOutOfStock, tone: StatusTone.danger),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

class ProductAttributeSelector extends StatelessWidget {
  const ProductAttributeSelector({
    super.key,
    required this.attribute,
    required this.selectedId,
    required this.isSelectable,
    required this.onSelected,
  });

  final ProductAttribute attribute;
  final String? selectedId;
  final bool Function(String valueId) isSelectable;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) {
    final selected = attribute.values.where((v) => v.id == selectedId).firstOrNull;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text.rich(
          TextSpan(
            children: [
              TextSpan(text: '${attribute.name}: ', style: context.text.titleSmall),
              TextSpan(
                text: selected?.name ?? context.l10n.selectOption(attribute.name),
                style: context.text.bodyMedium,
              ),
            ],
          ),
        ),
        const Gap(AppSpace.sm),
        Wrap(
          spacing: AppSpace.xs,
          runSpacing: AppSpace.xs,
          children: [
            for (final v in attribute.values)
              if (attribute.isColor || v.colorHex != null)
                ProductSwatch(
                  value: v,
                  selected: v.id == selectedId,
                  enabled: isSelectable(v.id),
                  onTap: () => onSelected(v.id),
                )
              else
                ChoiceChip(
                  label: Text(v.name),
                  selected: v.id == selectedId,
                  onSelected: isSelectable(v.id) ? (_) => onSelected(v.id) : null,
                ),
          ],
        ),
      ],
    );
  }
}

class ProductSwatch extends StatelessWidget {
  const ProductSwatch({
    super.key,
    required this.value,
    required this.selected,
    required this.enabled,
    required this.onTap,
  });
  final AttributeValue value;
  final bool selected;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    selected: selected,
    enabled: enabled,
    label: value.name,
    excludeSemantics: true,
    child: Tooltip(
      message: value.name,
      child: InkResponse(
        onTap: enabled ? onTap : null,
        radius: 26,
        child: AnimatedContainer(
          duration: AppDurations.fast,
          width: kMinTouchTarget,
          height: kMinTouchTarget,
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(
              color: selected ? context.colors.onSurface : context.palette.line,
              width: selected ? 2 : 1,
            ),
          ),
          child: Opacity(
            opacity: enabled ? 1 : 0.3,
            child: DecoratedBox(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: value.colorHex != null ? colorFromHex(value.colorHex!) : context.palette.surfaceMuted,
              ),
            ),
          ),
        ),
      ),
    ),
  );
}
