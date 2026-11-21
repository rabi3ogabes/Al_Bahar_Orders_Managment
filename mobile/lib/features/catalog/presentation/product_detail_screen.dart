import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/rating.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/alerts/domain/alert_models.dart';
import 'package:albahar_customer/features/alerts/presentation/alert_actions.dart';
import 'package:albahar_customer/features/alerts/presentation/state/alerts_controller.dart';
import 'package:albahar_customer/features/alerts/presentation/stock_alert_controls.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/presentation/assembly_choice.dart';
import 'package:albahar_customer/features/cart/presentation/cart_panel.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/domain/share_text.dart';
import 'package:albahar_customer/features/catalog/presentation/share_actions.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_gallery.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_header.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_options.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_page_sections.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/review_widgets.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/upsell_widgets.dart';
import 'package:albahar_customer/features/checkout/presentation/product_promise.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_price_tag.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_product_strip.dart';
import 'package:albahar_customer/features/reviews/presentation/review_deep_link.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

/// Scrolling of the whole product page, on every platform (Android included): the page springs back at both ends, and
/// pulling down at the top stretches the photo. Android's own "clamping" feel would leave the photo with nothing to stretch.
const ScrollPhysics kProductPagePhysics = BouncingScrollPhysics(parent: AlwaysScrollableScrollPhysics());

/// A product: one page that scrolls. The photo gallery is the top of it and collapses under a pinned bar as the customer
/// scrolls (and stretches when pulled down); then the name and price, colours and sizes, stock and delivery, details,
/// reviews and related products. The buy bar stays at the bottom.
class ProductDetailScreen extends ConsumerStatefulWidget {
  const ProductDetailScreen({
    super.key,
    required this.productId,
    this.heroScope = '',
    this.preview,
    this.openReview = false,
  });
  final String productId;
  final String heroScope;

  /// Opened from the review request message (`?review=1`): the review window opens at once (see [ReviewDeepLink]).
  final bool openReview;

  /// Summary from the list for an instant, hero-animated first frame.
  final ProductSummary? preview;

  @override
  ConsumerState<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends ConsumerState<ProductDetailScreen> {
  /// The most of one product the shopper can put in the bag at once (the server shows stock up to 10).
  static const _maxQuantity = 10;

  Map<String, String>? _selection;
  int _quantity = 1;
  final Map<String, int> _colourQty = {};

  /// "Add assembly and installation" ticked for this add (only a product that offers it).
  bool _assembly = false;

  @override
  void didUpdateWidget(covariant ProductDetailScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    // The same page reused for another product starts clean (no stale colour, size or quantity).
    if (oldWidget.productId != widget.productId) {
      _selection = null;
      _quantity = 1;
      _colourQty.clear();
      _assembly = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(productDetailProvider(widget.productId));
    return async.when(
      skipLoadingOnRefresh: true,
      skipLoadingOnReload: true,
      loading: () => _ProductSkeleton(preview: widget.preview, heroScope: widget.heroScope),
      error: (e, _) => Scaffold(
        body: CustomScrollView(
          physics: kProductPagePhysics,
          slivers: [
            const ProductSliverHeader(compact: true),
            SliverFillRemaining(
              child: ErrorState(error: e, onRetry: () => ref.invalidate(productDetailProvider(widget.productId))),
            ),
          ],
        ),
      ),
      data: _buildLoaded,
    );
  }

  Widget _buildLoaded(ProductDetail product) {
    final l10n = context.l10n;
    final selection = _currentSelection(product);
    final variant = product.attributes.isEmpty ? product.defaultVariant : product.resolveVariant(selection);
    final price = variant?.price ?? product.summary.price;
    // The deal this product is in, as it reads for the chosen variant (null: none, or this variant is not in it).
    final deal = dealForVariant(product.summary.deal, product.variants, variant);
    final branch = ref.watch(preferredBranchProvider);
    final colourAttr = product.colourAttribute;
    final multi = colourAttr != null;
    final rows = multi ? colourRowsFor(product, colourAttr, selection) : const <ColourRow>[];
    final pickedCount = rows.fold<int>(0, (n, r) => n + (_colourQty[r.variant.id] ?? 0));
    final purchasable = variant != null && variant.purchasable && product.availability.purchasable;
    // The buy bar's "Notify me" button shows the subscribed state when the customer already asked for this variant.
    final notifying =
        variant != null &&
        (ref.watch(alertsControllerProvider).value ?? const <StockAlert>[]).any(
          (a) => a.variantId == variant.id && a.type == AlertType.backInStock,
        );
    final colourBuyable = product.availability.purchasable && rows.any((r) => r.variant.purchasable);
    final sku = variant?.sku ?? product.summary.sku;
    final hasRating = product.summary.rating.count > 0;
    // What a share says: the name, its option, the price as shown and the product's plain page address (the chat builds its picture card from it).
    final assemblyFee = product.summary.assemblyFee;
    final shareText = productShareText(
      name: product.name,
      variantName: variant != null && variant.name.isNotEmpty ? variant.name : null,
      intro: l10n.shareProductIntro,
      url: product.shareUrl ?? DeepLinks.productShareUrl(product.summary.slug ?? product.id),
      priceLine: price == null ? null : l10n.shareProductPrice(price.finalPrice.formatted),
    );

    // ONE clock for the whole page (the price, the deal strip and the end-of-deal refetch all read it), ticking only while a deal is on it.
    return DealClockHost(
      active: product.summary.deal != null,
      child: Scaffold(
        body: CustomScrollView(
          physics: kProductPagePhysics,
          slivers: [
            ProductSliverHeader(
              title: product.name,
              background: ProductGallery(
                images: product.imagesFor(variant),
                heroTag: product.summary.heroTag(widget.heroScope),
                bottomInset: ProductSliverHeader.sheetOverlap,
              ),
              actions: [
                Builder(
                  builder: (anchor) => ProductHeaderButton(
                    icon: Icons.ios_share_rounded,
                    tooltip: l10n.share,
                    onPressed: () => unawaited(_share(product, shareText, anchor)),
                  ),
                ),
                HeaderChrome(
                  builder: (context, disc) =>
                      WishlistHeart(product: product.summary, variantId: variant?.id, size: 40, background: disc),
                ),
              ],
            ),
            SliverToBoxAdapter(
              child: _Sheet(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Draws nothing; when the deal ends (or starts) the product is read again, so the price and the strip follow the server.
                    DealDueWatcher(
                      dueAt: product.summary.deal?.dueAt,
                      onDue: () => ref.invalidate(productDetailProvider(widget.productId)),
                    ),
                    if (product.summary.brand != null)
                      Text(
                        product.summary.brand!.name,
                        style: context.text.labelMedium?.copyWith(color: context.palette.brassDeep, letterSpacing: 0.4),
                      ),
                    const Gap(AppSpace.xxs),
                    Semantics(header: true, child: Text(product.name, style: context.text.headlineLarge)),
                    if (hasRating || sku != null) ...[
                      const Gap(AppSpace.xs),
                      Wrap(
                        crossAxisAlignment: WrapCrossAlignment.center,
                        spacing: AppSpace.sm,
                        runSpacing: AppSpace.xxs,
                        children: [
                          if (hasRating)
                            RatingStars(average: product.summary.rating.average, count: product.summary.rating.count),
                          if (sku != null) Text(l10n.skuLabel(sku), style: context.text.bodySmall),
                        ],
                      ),
                    ],
                    const Gap(AppSpace.md),
                    // Choosing another colour or size changes the price (and the sale line): it eases in, nothing jumps.
                    AnimatedSize(
                      duration: AppDurations.fast,
                      alignment: AlignmentDirectional.topStart,
                      child: SizedBox(
                        width: double.infinity,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            if (price != null)
                              DealOnBuilder(
                                deal: deal,
                                builder: (context, dealOn) => Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    if (dealOn)
                                      DealPriceTag(deal: deal!, price: price, size: PriceTagSize.large)
                                    else
                                      PriceTag(price: price, size: PriceTagSize.large),
                                    if (!dealOn && price.isOnSale && price.saleEndsAt != null)
                                      Padding(
                                        padding: const EdgeInsets.only(top: 4),
                                        child: Text(
                                          l10n.saleEnds(QatarTime.date(price.saleEndsAt, context.localeCode)),
                                          style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                                        ),
                                      ),
                                  ],
                                ),
                              )
                            else
                              Text(l10n.selectBranchToBuy, style: context.text.bodyMedium),
                          ],
                        ),
                      ),
                    ),
                    // The deal: its countdown, the stock bar and what one order and this customer can buy at the deal price.
                    if (deal != null) ...[const Gap(AppSpace.md), DealProductStrip(deal: deal)],
                    if (product.shortDescription != null) ...[
                      const Gap(AppSpace.md),
                      Text(
                        product.shortDescription!,
                        style: context.text.bodyLarge?.copyWith(color: context.palette.muted),
                      ),
                    ],
                    if (multi) ...[
                      const Gap(AppSpace.lg),
                      ColourQuantities(
                        title: colourAttr.name.isEmpty ? l10n.coloursTitle : colourAttr.name,
                        rows: rows,
                        quantities: _colourQty,
                        onChanged: (id, q) => setState(() => q <= 0 ? _colourQty.remove(id) : _colourQty[id] = q),
                      ),
                    ],
                    for (final attr in product.attributes.where((a) => a != colourAttr)) ...[
                      const Gap(AppSpace.lg),
                      ProductAttributeSelector(
                        attribute: attr,
                        selectedId: selection[attr.code],
                        isSelectable: (valueId) => product.isValueSelectable(selection, attr.code, valueId),
                        onSelected: (valueId) {
                          Haptics.tap();
                          setState(() {
                            final next = {...selection, attr.code: valueId};
                            // If the combination does not exist, keep only this choice
                            // and let the resolver pick the closest variant.
                            final resolved =
                                product.resolveVariant(next) ?? product.resolveVariant({attr.code: valueId});
                            _selection = resolved == null ? next : product.selectionFor(resolved);
                          });
                        },
                      ),
                    ],
                    const Gap(AppSpace.lg),
                    // Assembly and installation at the door (delivery orders only): ticked here, then chosen per line in the cart.
                    if (assemblyFee != null) ...[
                      AssemblyChoice(fee: assemblyFee, selected: _assembly, onChanged: (v) => setState(() => _assembly = v)),
                      const Gap(AppSpace.md),
                    ],
                    // When will I get it? The delivery date and the pickup time from the customer's showroom; never blocks buying.
                    if (purchasable || (multi && colourBuyable))
                      ProductPromise(
                        productId: product.id,
                        variantId: variant?.id,
                        quantity: multi ? (pickedCount > 0 ? pickedCount : 1) : _quantity,
                        assembly: _assembly && assemblyFee != null,
                      ),
                    ProductAvailabilityCard(product: product, variant: variant, branchName: branch?.name),
                    WhatsAppShareButton(text: shareText, subject: product.name),
                    StockAlertControls(variantId: variant?.id, canBuy: purchasable, showBell: false),
                    ReviewDeepLink(enabled: widget.openReview, productId: product.id, productName: product.name),
                    const Gap(AppSpace.lg),
                    ProductDetailsSection(product: product),
                    const Gap(AppSpace.lg),
                    ReviewsPreview(productId: product.id, productName: product.name, rating: product.summary.rating),
                  ],
                ),
              ),
            ),
            if (product.upsell.isNotEmpty)
              SliverToBoxAdapter(
                child: UpsellSection(
                  title: l10n.completeYourSet,
                  products: product.upsell,
                  scope: 'upsell-${product.id}',
                ),
              ),
            if (product.related.isNotEmpty) ...[
              SliverToBoxAdapter(child: SectionHeader(title: l10n.relatedProducts)),
              SliverToBoxAdapter(
                child: ProductCarousel(products: product.related, scope: 'related-${product.id}'),
              ),
            ],
            const SliverToBoxAdapter(child: Gap(AppSpace.xl)),
          ],
        ),
        bottomNavigationBar: ProductBuyBar(
          multiCount: multi ? pickedCount : null,
          onAddMany: multi ? () => _addColours(product, rows) : null,
          purchasable: multi ? colourBuyable : purchasable,
          quantity: _quantity,
          maxQuantity: _maxQuantity,
          onQuantity: (q) => setState(() => _quantity = q),
          onAdd: variant == null ? null : () => _addToCart(product, variant),
          notifying: notifying,
          onNotify: variant == null
              ? null
              : () => unawaited(toggleAlert(context, ref, variantId: variant.id, type: AlertType.backInStock)),
        ),
      ),
    );
  }

  /// The chosen colour / size values; the product's default variant until the shopper picks, and again if the data
  /// changed under the choice (another branch, a refresh) so that it no longer names a variant.
  Map<String, String> _currentSelection(ProductDetail product) {
    final current = _selection;
    if (current != null && (product.attributes.isEmpty || product.resolveVariant(current) != null)) return current;
    final fresh = product.defaultVariant == null ? <String, String>{} : product.selectionFor(product.defaultVariant!);
    _selection = fresh;
    return fresh;
  }

  /// [anchor] is the share button: iPads place the share sheet next to it.
  Future<void> _share(ProductDetail product, String text, BuildContext anchor) async {
    final box = anchor.findRenderObject();
    final origin = box is RenderBox && box.hasSize ? box.localToGlobal(Offset.zero) & box.size : null;
    try {
      await ref.read(shareActionProvider)(ShareParams(text: text, subject: product.name, sharePositionOrigin: origin));
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    }
  }

  /// The cart opens over this page (no navigation, the page keeps its place); the badge changes at once.
  void _addToCart(ProductDetail product, ProductVariant variant) {
    unawaited(
      addToCartAndShow(context, ref, [
        CartAddition(
          variantId: variant.id,
          product: product.summary,
          quantity: _quantity,
          unitPrice: variant.price,
          variantName: variant.name.isEmpty ? null : variant.name,
          assembly: _assembly && product.summary.assemblyFee != null,
        ),
      ]),
    );
  }

  void _addColours(ProductDetail product, List<ColourRow> rows) {
    final picked = [
      for (final r in rows)
        if ((_colourQty[r.variant.id] ?? 0) > 0) (r.variant, _colourQty[r.variant.id]!),
    ];
    if (picked.isEmpty) {
      showInfoSnack(context, context.l10n.pickAtLeastOne);
      return;
    }
    unawaited(
      addToCartAndShow(context, ref, [
        for (final (v, qty) in picked)
          CartAddition(
            variantId: v.id,
            product: product.summary,
            quantity: qty,
            unitPrice: v.price,
            variantName: v.name.isEmpty ? null : v.name,
            assembly: _assembly && product.summary.assemblyFee != null,
          ),
      ]),
    );
    setState(_colourQty.clear);
  }
}

/// The page content under the photo: the opaque page colour (so it reads on top of the page background), a comfortable
/// reading width on tablets, and the side gutter.
class _Sheet extends StatelessWidget {
  const _Sheet({required this.child});
  final Widget child;

  @override
  // A Material, not a ColoredBox: the sections' tiles (description, specifications) paint their ink on it.
  Widget build(BuildContext context) => Material(
    color: context.pageColor,
    child: SafeArea(
      top: false,
      bottom: false,
      child: ContentWidth(
        maxWidth: 760,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter),
          child: child,
        ),
      ),
    ),
  );
}

/// The loading page: the same header and sheet as the finished page, with the list photo (which flies in from the card)
/// or a skeleton, so the layout does not change when the product arrives.
class _ProductSkeleton extends StatelessWidget {
  const _ProductSkeleton({required this.preview, required this.heroScope});
  final ProductSummary? preview;
  final String heroScope;

  @override
  Widget build(BuildContext context) {
    final preview = this.preview;
    return Scaffold(
      body: CustomScrollView(
        physics: kProductPagePhysics,
        slivers: [
          ProductSliverHeader(
            background: Stack(
              fit: StackFit.expand,
              children: [
                if (preview?.image != null)
                  Hero(
                    tag: preview!.heroTag(heroScope),
                    child: AppNetworkImage(url: preview.image!.url, memCacheWidth: galleryCacheWidth(context)),
                  )
                else
                  const Skeleton(radius: 0),
                const Positioned.fill(child: IgnorePointer(child: ProductHeaderScrim())),
              ],
            ),
          ),
          SliverToBoxAdapter(
            child: _Sheet(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (preview != null) ...[
                    Text(preview.name, style: context.text.headlineLarge),
                    const Gap(AppSpace.md),
                    if (preview.price != null) PriceTag(price: preview.price!, size: PriceTagSize.large),
                  ] else ...[
                    const Skeleton(width: 220, height: 28),
                    const Gap(AppSpace.md),
                    const Skeleton(width: 120, height: 24),
                  ],
                  const Gap(AppSpace.lg),
                  const Skeleton(height: 48, radius: 14),
                  const Gap(AppSpace.md),
                  const Skeleton(height: 90, radius: 16),
                  const Gap(AppSpace.md),
                  const Skeleton(height: 120, radius: 16),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
