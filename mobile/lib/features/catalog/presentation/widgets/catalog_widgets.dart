import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/product_card.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/quick_add_stepper.dart';
import 'package:albahar_customer/features/wishlist/presentation/state/wishlist_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Opens a product, passing the summary for an instant hero preview.
void openProduct(BuildContext context, ProductSummary p, {String heroScope = ''}) {
  unawaited(context.push('/products/${p.id}?hero=${Uri.encodeComponent(heroScope)}', extra: p));
}

/// Heart toggle wired to the wishlist (prompts sign-in for guests).
class WishlistHeart extends ConsumerWidget {
  const WishlistHeart({super.key, required this.product, this.variantId, this.onImage = true, this.size});
  final ProductSummary product;
  final String? variantId;
  final bool onImage;
  final double? size;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final saved = ref.watch(wishlistIdsProvider).contains(product.id);
    final l10n = context.l10n;
    return RoundIconButton(
      icon: saved ? Icons.favorite_rounded : Icons.favorite_border_rounded,
      color: saved ? context.palette.danger : null,
      background: onImage ? null : Colors.transparent,
      size: size ?? (onImage ? 36 : 44),
      tooltip: saved ? l10n.removeFromWishlist : l10n.addToWishlist,
      onPressed: () async {
        if (!requireAuth(context, ref)) return;
        try {
          final nowSaved = await ref.read(wishlistControllerProvider.notifier).toggle(product, variantId: variantId);
          if (nowSaved) Haptics.success();
          if (context.mounted) showInfoSnack(context, nowSaved ? l10n.addedToWishlist : l10n.removedFromWishlist);
        } catch (e) {
          if (context.mounted) showErrorSnack(context, e);
        }
      },
    );
  }
}

/// Product card wired to navigation and wishlist.
class ProductTile extends StatelessWidget {
  const ProductTile({super.key, required this.product, this.heroScope = '', this.width});
  final ProductSummary product;
  final String heroScope;
  final double? width;

  @override
  Widget build(BuildContext context) => ProductCardView(
    product: product,
    heroScope: heroScope,
    width: width,
    onTap: () => openProduct(context, product, heroScope: heroScope),
    topAction: WishlistHeart(product: product),
    bottomAction: QuickAddStepper(product: product),
  );
}

/// Horizontal carousel of product cards.
class ProductCarousel extends StatelessWidget {
  const ProductCarousel({super.key, required this.products, required this.scope, this.padding});
  final List<ProductSummary> products;
  final String scope;

  /// Edge padding of the row (defaults to the page gutter).
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final cardWidth = width >= 900 ? 220.0 : (width >= 600 ? 200.0 : 164.0);
    final textScale = MediaQuery.textScalerOf(context).scale(1).clamp(1.0, 1.6);
    // Square image tile + details, plus room above/below so the soft card shadow is not clipped.
    return SizedBox(
      height: cardWidth + 120 * textScale + 28,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        clipBehavior: Clip.none,
        padding: padding ?? const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, 4, AppSpace.gutter, 24),
        itemCount: products.length,
        separatorBuilder: (_, _) => const Gap(AppSpace.md),
        itemBuilder: (_, i) => ProductTile(product: products[i], heroScope: scope, width: cardWidth),
      ),
    );
  }
}

/// Loading row for carousels.
class ProductCarouselSkeleton extends StatelessWidget {
  const ProductCarouselSkeleton({super.key, this.flush = false});

  /// No page gutter, for rows inside an already padded list.
  final bool flush;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 164 + 120 + 28,
    child: ListView.separated(
      scrollDirection: Axis.horizontal,
      physics: const NeverScrollableScrollPhysics(),
      padding: flush
          ? const EdgeInsetsDirectional.fromSTEB(0, 4, 0, 16)
          : const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, 4, AppSpace.gutter, 24),
      itemCount: 4,
      separatorBuilder: (_, _) => const Gap(AppSpace.md),
      itemBuilder: (_, _) => const SizedBox(width: 164, child: ProductCardSkeleton()),
    ),
  );
}

/// Pill showing the preferred branch; opens branch selection.
class BranchPill extends ConsumerWidget {
  const BranchPill({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // One branch only: no dropdown, nothing to choose.
    if (!ref.watch(multipleBranchesProvider)) return const SizedBox.shrink();
    final branch = ref.watch(preferredBranchProvider);
    final l10n = context.l10n;
    return Semantics(
      button: true,
      label: branch == null ? l10n.chooseBranch : l10n.shoppingAt(branch.name),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        onTap: () => context.push('/branches?pick=1'),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: kMinTouchTarget),
          child: Container(
            padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
            decoration: BoxDecoration(
              color: context.palette.brassTint,
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.storefront_outlined, size: 16, color: context.palette.brass),
                const Gap(6),
                Flexible(
                  child: Text(
                    branch?.name ?? l10n.chooseBranch,
                    overflow: TextOverflow.ellipsis,
                    style: context.text.labelMedium,
                  ),
                ),
                const Icon(Icons.expand_more_rounded, size: 18),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
