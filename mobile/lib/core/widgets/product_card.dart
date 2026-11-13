import 'package:flutter/material.dart';

import '../../features/catalog/domain/catalog_models.dart';
import '../l10n/l10n.dart';
import '../theme/design_tokens.dart';
import '../theme/tokens.dart';
import 'common.dart';
import 'price_tag.dart';
import 'rating.dart';

/// Soft-card product tile: white 20-radius card, square image tile, pill badge, bold name, navy price.
/// Presentation only — navigation and wishlist wiring are injected.
class ProductCardView extends StatelessWidget {
  const ProductCardView({
    super.key,
    required this.product,
    this.onTap,
    this.topAction,
    this.bottomAction,
    this.heroScope = '',
    this.width,
  });

  final ProductSummary product;
  final VoidCallback? onTap;

  /// Usually a wishlist heart; placed at the top-end corner.
  final Widget? topAction;

  /// Quick add to bag (a + that becomes − 1 +), placed at the bottom-end corner of the picture.
  final Widget? bottomAction;
  final String heroScope;
  final double? width;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final badge = _primaryBadge(context, l10n, context.colors.primary);

    return Semantics(
      button: true,
      label: [
        product.name,
        if (product.price != null) product.price!.finalPrice.formatted,
        if (!product.inStock) l10n.outOfStock,
      ].join(', '),
      child: SizedBox(
        width: width,
        child: DecoratedBox(
          decoration: context.cardDecoration(),
          child: Material(
            type: MaterialType.transparency,
            child: InkWell(
              onTap: onTap,
              borderRadius: context.design.cardBorderRadius,
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final image = _image(context, badge);
                    final details = <Widget>[
                      const Gap(AppSpace.sm),
                      Text(
                        product.name,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: context.text.titleSmall?.copyWith(height: 1.3),
                      ),
                      if (product.brand != null) ...[
                        const Gap(2),
                        Text(
                          product.brand!.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: context.text.bodySmall,
                        ),
                      ],
                      const Gap(AppSpace.xs),
                      if (product.price != null)
                        PriceTag(price: product.price!, size: PriceTagSize.medium, showBadge: false)
                      else
                        Text(l10n.productUnavailable, style: context.text.bodySmall),
                      if (product.rating.count > 0) ...[
                        const Gap(2),
                        RatingStars(average: product.rating.average, count: product.rating.count, size: 12),
                      ],
                    ];
                    // In grids/carousels the height is bounded: let the image flex so long names or
                    // large text never overflow. Otherwise keep a square tile.
                    if (constraints.hasBoundedHeight) {
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Flexible(
                            child: ConstrainedBox(
                              constraints: BoxConstraints(maxHeight: constraints.maxWidth),
                              child: SizedBox(width: double.infinity, child: image),
                            ),
                          ),
                          ...details,
                        ],
                      );
                    }
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        AspectRatio(aspectRatio: 1, child: image),
                        ...details,
                      ],
                    );
                  },
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _image(BuildContext context, ({String label, Color bg, Color fg})? badge) => Stack(
    fit: StackFit.expand,
    children: [
      Hero(
        tag: product.heroTag(heroScope),
        child: AppNetworkImage(
          url: product.image?.listUrl,
          semanticLabel: product.image?.alt,
          borderRadius: context.design.controlBorderRadius,
          fit: BoxFit.contain,
        ),
      ),
      if (!product.inStock)
        DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: context.design.controlBorderRadius,
            color: context.colors.surface.withValues(alpha: 0.55),
          ),
        ),
      if (badge != null)
        PositionedDirectional(
          top: AppSpace.xs,
          start: AppSpace.xs,
          child: _Badge(label: badge.label, background: badge.bg, foreground: badge.fg),
        ),
      if (topAction != null) PositionedDirectional(top: 0, end: 0, child: topAction!),
      if (bottomAction != null) PositionedDirectional(bottom: 2, end: 2, child: bottomAction!),
    ],
  );

  ({String label, Color bg, Color fg})? _primaryBadge(BuildContext context, AppLocalizations l10n, Color primary) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final p = context.palette;
    // Pastel pill in light mode, translucent tint on the night surface.
    ({Color bg, Color fg}) tone(Color light, Color lightTint, Color base) =>
        dark ? (bg: base.withValues(alpha: 0.22), fg: base) : (bg: lightTint, fg: light);
    if (!product.inStock) {
      return (
        label: l10n.outOfStock,
        bg: dark ? p.surfaceMuted : AppPalette.sand,
        fg: dark ? p.muted : AppPalette.inkSoft,
      );
    }
    final pct = product.price?.discountPercent;
    if (product.badges.contains('sale') || (product.price?.isOnSale ?? false)) {
      final t = tone(AppPalette.danger, AppPalette.dangerTint, p.danger);
      return (label: pct != null && pct > 0 ? l10n.discountOff(pct) : l10n.badgeSale, bg: t.bg, fg: t.fg);
    }
    if (product.badges.contains('new')) return (label: l10n.badgeNew, bg: primary, fg: context.colors.onPrimary);
    if (product.badges.contains('bestseller')) {
      final t = tone(AppPalette.success, AppPalette.successTint, p.success);
      return (label: l10n.badgeBestseller, bg: t.bg, fg: t.fg);
    }
    return null;
  }
}

/// Status pill (soft tinted background, darker text of the same hue).
class _Badge extends StatelessWidget {
  const _Badge({required this.label, required this.background, required this.foreground});
  final String label;
  final Color background;
  final Color foreground;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsetsDirectional.symmetric(horizontal: 9, vertical: 3),
    decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(AppRadius.pill)),
    child: Text(
      label,
      textDirection: label.startsWith('-') ? TextDirection.ltr : null,
      style: context.text.labelSmall?.copyWith(color: foreground, letterSpacing: 0.1, fontWeight: FontWeight.w600),
    ),
  );
}
