import 'package:flutter/material.dart';

import '../../features/catalog/domain/catalog_models.dart';
import '../l10n/l10n.dart';
import '../theme/tokens.dart';
import 'common.dart';
import 'price_tag.dart';
import 'rating.dart';

/// Editorial product card: large 4:5 image, brand eyebrow, name, price.
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
        child: InkWell(
          onTap: onTap,
          borderRadius: AppRadius.cardAll,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              AspectRatio(
                aspectRatio: 4 / 5,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    Hero(
                      tag: product.heroTag(heroScope),
                      child: AppNetworkImage(
                        url: product.image?.listUrl,
                        semanticLabel: product.image?.alt,
                        borderRadius: AppRadius.cardAll,
                      ),
                    ),
                    if (!product.inStock)
                      DecoratedBox(
                        decoration: BoxDecoration(
                          borderRadius: AppRadius.cardAll,
                          color: context.colors.surface.withValues(alpha: 0.45),
                        ),
                      ),
                    if (badge != null)
                      PositionedDirectional(
                        top: AppSpace.sm,
                        start: AppSpace.sm,
                        child: _Badge(label: badge.$1, color: badge.$2),
                      ),
                    if (topAction != null) PositionedDirectional(top: 0, end: 0, child: topAction!),
                  ],
                ),
              ),
              const Gap(AppSpace.sm),
              if (product.brand != null)
                Text(
                  product.brand!.name.toUpperCase(),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: context.text.labelSmall?.copyWith(color: palette.brass),
                ),
              const Gap(2),
              Text(
                product.name,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: context.text.bodyMedium?.copyWith(fontWeight: FontWeight.w500, height: 1.3),
              ),
              const Gap(AppSpace.xxs),
              if (product.price != null)
                PriceTag(price: product.price!, size: PriceTagSize.small, showBadge: false)
              else
                Text(l10n.productUnavailable, style: context.text.bodySmall),
              if (product.rating.count > 0) ...[
                const Gap(2),
                RatingStars(average: product.rating.average, count: product.rating.count, size: 12),
              ],
            ],
          ),
        ),
      ),
    );
  }

  (String, Color)? _primaryBadge(AppLocalizations l10n) {
    if (!product.inStock) return (l10n.outOfStock, AppPalette.inkSoft);
    final pct = product.price?.discountPercent;
    if (product.badges.contains('sale') || (product.price?.isOnSale ?? false)) {
      return (pct != null && pct > 0 ? l10n.discountOff(pct) : l10n.badgeSale, AppPalette.danger);
    }
    if (product.badges.contains('new')) return (l10n.badgeNew, AppPalette.ink);
    if (product.badges.contains('bestseller')) return (l10n.badgeBestseller, AppPalette.brassDeep);
    return null;
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.label, required this.color});
  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsetsDirectional.symmetric(horizontal: 8, vertical: 4),
    decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(6)),
    child: Text(
      label,
      style: context.text.labelSmall?.copyWith(color: Colors.white, letterSpacing: 0.4),
    ),
  );
}
