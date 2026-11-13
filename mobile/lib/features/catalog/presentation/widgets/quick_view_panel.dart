import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/rating.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/presentation/cart_panel.dart';
import 'package:albahar_customer/features/catalog/data/catalog_repository.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_options.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// A product in a side window: picture, price, options and the add-to-cart action, without leaving the list or the
/// search results the person is browsing. "View full details" opens the product page when they want the long version.
Future<void> showQuickView(BuildContext context, ProductSummary product) {
  final host = context;
  return showSidePanel<void>(
    context: context,
    title: context.l10n.quickView,
    scrollable: false,
    builder: (_) => QuickViewBody(product: product, host: host),
  );
}

/// Body of [showQuickView]. [host] is the page that opened it: the cart window opens on it after an add.
class QuickViewBody extends ConsumerStatefulWidget {
  const QuickViewBody({super.key, required this.product, required this.host});
  final ProductSummary product;
  final BuildContext host;

  @override
  ConsumerState<QuickViewBody> createState() => _QuickViewBodyState();
}

class _QuickViewBodyState extends ConsumerState<QuickViewBody> {
  Map<String, String>? _selection;
  int _quantity = 1;
  final Map<String, int> _colourQty = {};
  int _image = 0;

  void _openFullPage() {
    final router = GoRouter.of(context);
    final p = widget.product;
    Navigator.of(context).pop();
    unawaited(router.push('/products/${p.id}', extra: p));
  }

  /// Closes this window, then the cart window opens on the page behind it (the cart and its badge change at once).
  void _add(ProductDetail product, List<CartAddition> additions) {
    if (additions.isEmpty) return;
    if (!requireAuth(context, ref, beforeLeaving: () => Navigator.of(context).pop())) return;
    final host = widget.host;
    Navigator.of(context).pop();
    if (host.mounted) unawaited(addToCartAndShow(host, ref, additions));
  }

  Future<void> _notify(ProductVariant variant) async {
    if (!requireAuth(context, ref, beforeLeaving: () => Navigator.of(context).pop())) return;
    try {
      await ref
          .read(catalogRepositoryProvider)
          .createStockAlert(variantId: variant.id, branchId: ref.read(preferredBranchIdProvider));
      if (mounted) showInfoSnack(context, context.l10n.alertCreated);
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final preview = widget.product;
    final async = ref.watch(productDetailProvider(preview.id));
    return async.when(
      skipLoadingOnRefresh: true,
      skipLoadingOnReload: true,
      loading: () => SidePanelLayout(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AspectRatio(
              aspectRatio: 1.3,
              child: AppNetworkImage(url: preview.image?.url, borderRadius: AppRadius.imageAll, fit: BoxFit.contain),
            ),
            const Gap(AppSpace.md),
            Text(preview.name, style: context.text.headlineSmall),
            const Gap(AppSpace.xs),
            if (preview.price != null) PriceTag(price: preview.price!, size: PriceTagSize.large),
            const Gap(AppSpace.lg),
            const Skeleton(height: 48, radius: 14),
          ],
        ),
      ),
      error: (e, _) =>
          ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(productDetailProvider(preview.id))),
      data: (product) {
        _selection ??= product.defaultVariant == null
            ? <String, String>{}
            : product.selectionFor(product.defaultVariant!);
        final selection = _selection!;
        final variant = product.attributes.isEmpty ? product.defaultVariant : product.resolveVariant(selection);
        final price = variant?.price ?? product.summary.price;
        final colourAttr = product.colourAttribute;
        final multi = colourAttr != null;
        final rows = multi ? colourRowsFor(product, colourAttr, selection) : const <ColourRow>[];
        final picked = <String, int>{
          for (final r in rows)
            if ((_colourQty[r.variant.id] ?? 0) > 0) r.variant.id: _colourQty[r.variant.id]!,
        };
        final pickedCount = picked.values.fold<int>(0, (n, q) => n + q);
        final purchasable = variant != null && variant.purchasable && product.availability.purchasable;
        final images = product.imagesFor(variant);
        final current = images.isEmpty ? null : images[_image.clamp(0, images.length - 1)];
        final branch = ref.watch(preferredBranchProvider);
        final canBuy = multi ? rows.any((r) => r.variant.purchasable) : purchasable;

        List<CartAddition> additions(Map<String, int> lines) => [
          for (final e in lines.entries)
            CartAddition(
              variantId: e.key,
              product: product.summary,
              quantity: e.value,
              unitPrice: product.variants.where((v) => v.id == e.key).firstOrNull?.price,
              variantName: product.variants.where((v) => v.id == e.key).firstOrNull?.name,
            ),
        ];

        Widget footer() {
          if (!canBuy) {
            return Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                AppButton.secondary(
                  label: l10n.notifyBackInStock,
                  icon: Icons.notifications_active_outlined,
                  onPressed: variant == null ? null : () => _notify(variant),
                ),
                AppButton.ghost(label: l10n.viewFullDetails, expand: true, onPressed: _openFullPage),
              ],
            );
          }
          return Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (multi)
                AppButton(
                  label: pickedCount > 0 ? l10n.addItemsToBag(pickedCount) : l10n.addToCart,
                  icon: Icons.shopping_bag_outlined,
                  onPressed: pickedCount > 0 ? () => _add(product, additions(picked)) : null,
                )
              else
                Row(
                  children: [
                    QuantityStepper(
                      value: _quantity,
                      max: 10,
                      compact: true,
                      onChanged: (q) => setState(() => _quantity = q),
                    ),
                    const Gap(AppSpace.sm),
                    Expanded(
                      child: AppButton(
                        label: l10n.addToCart,
                        icon: Icons.shopping_bag_outlined,
                        onPressed: variant == null ? null : () => _add(product, additions({variant.id: _quantity})),
                      ),
                    ),
                  ],
                ),
              AppButton.ghost(label: l10n.viewFullDetails, expand: true, onPressed: _openFullPage),
            ],
          );
        }

        return SidePanelLayout(
          footer: footer(),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AspectRatio(
                aspectRatio: 1.3,
                child: AppNetworkImage(
                  url: current?.url,
                  semanticLabel: current?.alt ?? product.name,
                  borderRadius: AppRadius.imageAll,
                  fit: BoxFit.contain,
                ),
              ),
              if (images.length > 1) ...[
                const Gap(AppSpace.xs),
                SizedBox(
                  height: 56,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: images.length,
                    separatorBuilder: (_, _) => const Gap(AppSpace.xs),
                    itemBuilder: (context, i) => Semantics(
                      button: true,
                      selected: i == _image,
                      label: l10n.imageOf(i + 1, images.length),
                      excludeSemantics: true,
                      child: InkWell(
                        borderRadius: BorderRadius.circular(AppRadius.sm),
                        onTap: () => setState(() => _image = i),
                        child: Container(
                          width: 56,
                          height: 56,
                          padding: const EdgeInsets.all(2),
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(AppRadius.sm),
                            border: Border.all(
                              color: i == _image ? context.colors.primary : context.palette.line,
                              width: i == _image ? 2 : 1,
                            ),
                          ),
                          child: AppNetworkImage(
                            url: images[i].url,
                            borderRadius: BorderRadius.circular(AppRadius.sm),
                            fit: BoxFit.cover,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
              const Gap(AppSpace.md),
              if (product.summary.brand != null)
                Text(
                  product.summary.brand!.name,
                  style: context.text.labelMedium?.copyWith(color: context.palette.brassDeep, letterSpacing: 0.4),
                ),
              Semantics(header: true, child: Text(product.name, style: context.text.headlineSmall)),
              const Gap(AppSpace.xs),
              if (product.summary.rating.count > 0)
                RatingStars(average: product.summary.rating.average, count: product.summary.rating.count),
              const Gap(AppSpace.sm),
              if (price != null)
                PriceTag(price: price, size: PriceTagSize.large)
              else
                Text(l10n.selectBranchToBuy, style: context.text.bodyMedium),
              if (product.shortDescription != null) ...[
                const Gap(AppSpace.sm),
                Text(product.shortDescription!, style: context.text.bodyMedium?.copyWith(color: context.palette.muted)),
              ],
              if (multi) ...[
                const Gap(AppSpace.md),
                ColourQuantities(
                  title: colourAttr.name.isEmpty ? l10n.coloursTitle : colourAttr.name,
                  rows: rows,
                  quantities: _colourQty,
                  onChanged: (id, q) => setState(() => q <= 0 ? _colourQty.remove(id) : _colourQty[id] = q),
                ),
              ],
              for (final attr in product.attributes.where((a) => a != colourAttr)) ...[
                const Gap(AppSpace.md),
                ProductAttributeSelector(
                  attribute: attr,
                  selectedId: selection[attr.code],
                  isSelectable: (valueId) => product.isValueSelectable(selection, attr.code, valueId),
                  onSelected: (valueId) {
                    Haptics.tap();
                    setState(() {
                      final next = {...selection, attr.code: valueId};
                      final resolved = product.resolveVariant(next) ?? product.resolveVariant({attr.code: valueId});
                      _selection = resolved == null ? next : product.selectionFor(resolved);
                      _image = 0;
                    });
                  },
                ),
              ],
              const Gap(AppSpace.md),
              Row(
                children: [
                  Icon(
                    canBuy ? Icons.check_circle_outline_rounded : Icons.inventory_2_outlined,
                    size: 18,
                    color: canBuy ? context.palette.success : context.palette.warning,
                  ),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Text(
                      canBuy
                          ? l10n.availableAt(branch?.name ?? l10n.selectedBranch)
                          : l10n.outOfStockAt(branch?.name ?? l10n.selectedBranch),
                      style: context.text.bodySmall,
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }
}
