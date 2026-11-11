import 'package:flutter/material.dart';

import '../../features/catalog/domain/catalog_models.dart';
import '../l10n/l10n.dart';
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
    this.heroScope = '',
    this.width,
  });

  final ProductSummary product;
  final VoidCallback? onTap;

  /// Usually a wishlist heart; placed at the top-end corner.
  final Widget? topAction;
  final String heroScope;
  final double? width;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final badge = _primaryBadge(l10n);

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
          decoration: BoxDecoration(
            color: context.colors.surface,
            borderRadius: AppRadius.cardAll,
            boxShadow: isDark ? null : kSoftShadow,
            border: isDark ? Border.all(color: palette.line) : null,
          ),
          child: Material(
            type: MaterialType.transparency,
            child: InkWell(
              onTap: onTap,
              borderRadius: AppRadius.cardAll,
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
          borderRadius: AppRadius.imageAll,
          fit: BoxFit.contain,
        ),
      ),
      if (!product.inStock)
        DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: AppRadius.imageAll,
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
    ],
  );

  ({String label, Color bg, Color fg})? _primaryBadge(AppLocalizations l10n) {
    if (!product.inStock) return (label: l10n.outOfStock, bg: AppPalette.sand, fg: AppPalette.inkSoft);
    final pct = product.price?.discountPercent;
    if (product.badges.contains('sale') || (product.price?.isOnSale ?? false)) {
      return (
        label: pct != null && pct > 0 ? l10n.discountOff(pct) : l10n.badgeSale,
        bg: AppPalette.dangerTint,
        fg: AppPalette.danger,
      );
    }
    if (product.badges.contains('new')) return (label: l10n.badgeNew, bg: AppPalette.navy, fg: Colors.white);
    if (product.badges.contains('bestseller')) {
      return (label: l10n.badgeBestseller, bg: AppPalette.successTint, fg: AppPalette.success);
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
