import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/html_content.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/rating.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/data/catalog_repository.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/product_list_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/review_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';

class ProductDetailScreen extends ConsumerStatefulWidget {
  const ProductDetailScreen({super.key, required this.productId, this.heroScope = '', this.preview});
  final String productId;
  final String heroScope;

  /// Summary from the list for an instant, hero-animated first frame.
  final ProductSummary? preview;

  @override
  ConsumerState<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends ConsumerState<ProductDetailScreen> {
  Map<String, String>? _selection;
  int _quantity = 1;
  final Map<String, int> _colourQty = {};
  bool _adding = false;

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(productDetailProvider(widget.productId));
    return async.when(
      skipLoadingOnRefresh: true,
      skipLoadingOnReload: true,
      loading: () => _LoadingView(preview: widget.preview, heroScope: widget.heroScope),
      error: (e, _) => Scaffold(
        appBar: AppBar(),
        body: ErrorState(error: e, onRetry: () => ref.invalidate(productDetailProvider(widget.productId))),
      ),
      data: _buildLoaded,
    );
  }

  Widget _buildLoaded(ProductDetail product) {
    final l10n = context.l10n;
    _selection ??= product.defaultVariant == null ? <String, String>{} : product.selectionFor(product.defaultVariant!);
    final variant = product.attributes.isEmpty ? product.defaultVariant : product.resolveVariant(_selection!);
    final price = variant?.price ?? product.summary.price;
    final branch = ref.watch(preferredBranchProvider);
    final colourAttr = product.colourAttribute;
    final multi = colourAttr != null;
    final rows = multi ? _colourRows(product, colourAttr) : const <_ColourRow>[];
    final pickedCount = rows.fold<int>(0, (n, r) => n + (_colourQty[r.variant.id] ?? 0));
    final purchasable = variant != null && variant.purchasable && product.availability.purchasable;

    return Scaffold(
      body: CustomScrollView(
        slivers: [
          SliverAppBar(
            pinned: true,
            stretch: true,
            expandedHeight: MediaQuery.sizeOf(context).width.clamp(0, 640) * 1.05,
            actions: [
              RoundIconButton(
                icon: Icons.ios_share_rounded,
                size: 40,
                tooltip: l10n.share,
                onPressed: () => _share(product),
              ),
              WishlistHeart(product: product.summary, variantId: variant?.id, size: 40),
              const Gap(AppSpace.xs),
            ],
            flexibleSpace: FlexibleSpaceBar(
              stretchModes: const [StretchMode.zoomBackground],
              background: _Gallery(
                images: product.imagesFor(variant),
                heroTag: product.summary.heroTag(widget.heroScope),
              ),
            ),
          ),
          SliverToBoxAdapter(
            child: Transform.translate(
              offset: const Offset(0, -24),
              child: Material(
                color: Theme.of(context).scaffoldBackgroundColor,
                shape: const RoundedRectangleBorder(
                  borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.sheet)),
                ),
                clipBehavior: Clip.antiAlias,
                child: ContentWidth(
                  maxWidth: 760,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.lg, AppSpace.gutter, 0),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (product.summary.brand != null)
                          Text(
                            product.summary.brand!.name,
                            style: context.text.labelMedium?.copyWith(
                              color: context.palette.brassDeep,
                              letterSpacing: 0.4,
                            ),
                          ),
                        const Gap(AppSpace.xxs),
                        Semantics(header: true, child: Text(product.name, style: context.text.headlineLarge)),
                        const Gap(AppSpace.xs),
                        Row(
                          children: [
                            if (product.summary.rating.count > 0) ...[
                              RatingStars(average: product.summary.rating.average, count: product.summary.rating.count),
                              const Gap(AppSpace.sm),
                            ],
                            if ((variant?.sku ?? product.summary.sku) != null)
                              Flexible(
                                child: Text(
                                  l10n.skuLabel(variant?.sku ?? product.summary.sku!),
                                  style: context.text.bodySmall,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                          ],
                        ),
                        const Gap(AppSpace.md),
                        if (price != null) ...[
                          PriceTag(price: price, size: PriceTagSize.large),
                          if (price.isOnSale && price.saleEndsAt != null)
                            Padding(
                              padding: const EdgeInsets.only(top: 4),
                              child: Text(
                                l10n.saleEnds(QatarTime.date(price.saleEndsAt, context.localeCode)),
                                style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                              ),
                            ),
                        ] else
                          Text(l10n.selectBranchToBuy, style: context.text.bodyMedium),
                        if (product.shortDescription != null) ...[
                          const Gap(AppSpace.md),
                          Text(
                            product.shortDescription!,
                            style: context.text.bodyLarge?.copyWith(color: context.palette.muted),
                          ),
                        ],
                        if (multi) ...[
                          const Gap(AppSpace.lg),
                          _ColourQuantities(
                            title: colourAttr.name.isEmpty ? l10n.coloursTitle : colourAttr.name,
                            rows: rows,
                            quantities: _colourQty,
                            onChanged: (id, q) => setState(() => q <= 0 ? _colourQty.remove(id) : _colourQty[id] = q),
                          ),
                        ],
                        for (final attr in product.attributes.where((a) => a != colourAttr)) ...[
                          const Gap(AppSpace.lg),
                          _AttributeSelector(
                            attribute: attr,
                            selectedId: _selection![attr.code],
                            isSelectable: (valueId) => product.isValueSelectable(_selection!, attr.code, valueId),
                            onSelected: (valueId) {
                              Haptics.tap();
                              setState(() {
                                final next = {..._selection!, attr.code: valueId};
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
                        _AvailabilityCard(product: product, variant: variant, branchName: branch?.name),
                        const Gap(AppSpace.lg),
                        _DetailsSection(product: product),
                        const Gap(AppSpace.lg),
                        ReviewsPreview(
                          productId: product.id,
                          productName: product.name,
                          rating: product.summary.rating,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
          if (product.related.isNotEmpty) ...[
            SliverToBoxAdapter(child: SectionHeader(title: l10n.relatedProducts)),
            SliverToBoxAdapter(
              child: ProductCarousel(products: product.related, scope: 'related-${product.id}'),
            ),
          ],
          const SliverToBoxAdapter(child: Gap(120)),
        ],
      ),
      bottomNavigationBar: _BuyBar(
        multiCount: multi ? pickedCount : null,
        onAddMany: multi ? () => _addColours(rows) : null,
        purchasable: multi ? rows.any((r) => r.variant.purchasable) : purchasable,
        price: price?.finalPrice,
        quantity: _quantity,
        maxQuantity: 10,
        adding: _adding,
        onQuantity: (q) => setState(() => _quantity = q),
        onAdd: variant == null ? null : () => _addToCart(variant),
        onNotify: variant == null ? null : () => _notify(variant),
      ),
    );
  }

  Future<void> _share(ProductDetail product) async {
    final url = product.shareUrl ?? DeepLinks.productShareUrl(product.summary.slug ?? product.id);
    await SharePlus.instance.share(ShareParams(text: '${product.name}\n$url', subject: product.name));
  }

  Future<void> _addToCart(ProductVariant variant) async {
    if (!requireAuth(context, ref)) return;
    final l10n = context.l10n;
    setState(() => _adding = true);
    try {
      await ref.read(cartControllerProvider.notifier).addItem(variant.id, quantity: _quantity);
      Haptics.success();
      if (!mounted) return;
      showInfoSnack(
        context,
        l10n.addedToCart,
        action: SnackBarAction(label: l10n.viewCart, onPressed: () => context.go('/cart')),
      );
    } on ApiException catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _adding = false);
    }
  }

  /// One row per colour (for the current choice of the other attributes).
  List<_ColourRow> _colourRows(ProductDetail product, ProductAttribute colour) {
    final others = {..._selection!}..remove(colour.code);
    final rows = <_ColourRow>[];
    for (final v in colour.values) {
      final variant = product.resolveVariant({...others, colour.code: v.id});
      if (variant != null && variant.attributeValueIds.contains(v.id)) rows.add(_ColourRow(v, variant));
    }
    return rows;
  }

  Future<void> _addColours(List<_ColourRow> rows) async {
    if (!requireAuth(context, ref)) return;
    final l10n = context.l10n;
    final picked = [
      for (final r in rows)
        if ((_colourQty[r.variant.id] ?? 0) > 0) (r.variant.id, _colourQty[r.variant.id]!),
    ];
    if (picked.isEmpty) {
      showInfoSnack(context, l10n.pickAtLeastOne);
      return;
    }
    setState(() => _adding = true);
    try {
      for (final (id, qty) in picked) {
        await ref.read(cartControllerProvider.notifier).addItem(id, quantity: qty);
      }
      Haptics.success();
      if (!mounted) return;
      setState(_colourQty.clear);
      showInfoSnack(
        context,
        l10n.addedToCart,
        action: SnackBarAction(label: l10n.viewCart, onPressed: () => context.go('/cart')),
      );
    } on ApiException catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _adding = false);
    }
  }

  Future<void> _notify(ProductVariant variant) async {
    if (!requireAuth(context, ref)) return;
    try {
      await ref
          .read(catalogRepositoryProvider)
          .createStockAlert(variantId: variant.id, branchId: ref.read(preferredBranchIdProvider));
      if (mounted) showInfoSnack(context, context.l10n.alertCreated);
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    }
  }
}

class _LoadingView extends StatelessWidget {
  const _LoadingView({required this.preview, required this.heroScope});
  final ProductSummary? preview;
  final String heroScope;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width.clamp(0, 640).toDouble();
    return Scaffold(
      appBar: AppBar(backgroundColor: Colors.transparent),
      extendBodyBehindAppBar: true,
      body: ListView(
        padding: EdgeInsets.zero,
        physics: const NeverScrollableScrollPhysics(),
        children: [
          SizedBox(
            height: width * 1.05 + MediaQuery.paddingOf(context).top,
            child: preview?.image != null
                ? Hero(
                    tag: preview!.heroTag(heroScope),
                    child: AppNetworkImage(url: preview!.image!.url),
                  )
                : const Skeleton(radius: 0),
          ),
          Padding(
            padding: const EdgeInsets.all(AppSpace.gutter),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (preview != null) ...[
                  Text(preview!.name, style: context.text.headlineLarge),
                  const Gap(AppSpace.sm),
                  if (preview!.price != null) PriceTag(price: preview!.price!, size: PriceTagSize.large),
                ] else ...[
                  const Skeleton(width: 220, height: 26),
                  const Gap(AppSpace.sm),
                  const Skeleton(width: 120, height: 22),
                ],
                const Gap(AppSpace.lg),
                const Skeleton(height: 48, radius: 14),
                const Gap(AppSpace.md),
                const Skeleton(height: 90, radius: 16),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Gallery extends StatefulWidget {
  const _Gallery({required this.images, required this.heroTag});
  final List<ImageRef> images;
  final String heroTag;

  @override
  State<_Gallery> createState() => _GalleryState();
}

class _GalleryState extends State<_Gallery> {
  int _index = 0;
  final _page = PageController();

  @override
  void didUpdateWidget(covariant _Gallery oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Variant change → jump back to its first image.
    if (oldWidget.images.firstOrNull?.url != widget.images.firstOrNull?.url && _page.hasClients) {
      _page.jumpToPage(0);
      _index = 0;
    }
  }

  @override
  void dispose() {
    _page.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final images = widget.images;
    if (images.isEmpty) return const AppNetworkImage(url: null);
    return Stack(
      fit: StackFit.expand,
      children: [
        PageView.builder(
          controller: _page,
          itemCount: images.length,
          onPageChanged: (i) => setState(() => _index = i),
          itemBuilder: (context, i) {
            final image = AppNetworkImage(
              url: images[i].url,
              semanticLabel: images[i].alt ?? context.l10n.imageOf(i + 1, images.length),
            );
            return GestureDetector(
              onTap: () => Navigator.of(context).push(
                PageRouteBuilder<void>(
                  opaque: false,
                  barrierColor: Colors.black,
                  pageBuilder: (_, _, _) => _ZoomViewer(images: images, initial: i, heroTag: widget.heroTag),
                  transitionsBuilder: (_, a, _, child) => FadeTransition(opacity: a, child: child),
                ),
              ),
              child: i == 0 ? Hero(tag: widget.heroTag, child: image) : image,
            );
          },
        ),
        if (images.length > 1)
          PositionedDirectional(
            bottom: AppSpace.md,
            start: 0,
            end: 0,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                for (var i = 0; i < images.length; i++)
                  AnimatedContainer(
                    duration: AppDurations.medium,
                    margin: const EdgeInsets.symmetric(horizontal: 3),
                    width: i == _index ? 18 : 6,
                    height: 6,
                    decoration: BoxDecoration(
                      color: i == _index ? Colors.white : Colors.white.withValues(alpha: 0.55),
                      borderRadius: BorderRadius.circular(3),
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

/// Full-screen pinch-to-zoom gallery.
class _ZoomViewer extends StatefulWidget {
  const _ZoomViewer({required this.images, required this.initial, required this.heroTag});
  final List<ImageRef> images;
  final int initial;
  final String heroTag;

  @override
  State<_ZoomViewer> createState() => _ZoomViewerState();
}

class _ZoomViewerState extends State<_ZoomViewer> {
  late final _page = PageController(initialPage: widget.initial);
  late int _index = widget.initial;

  @override
  void dispose() {
    _page.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text('${_index + 1} / ${widget.images.length}', style: const TextStyle(color: Colors.white)),
      ),
      body: PageView.builder(
        controller: _page,
        itemCount: widget.images.length,
        onPageChanged: (i) => setState(() => _index = i),
        itemBuilder: (context, i) => InteractiveViewer(
          minScale: 1,
          maxScale: 5,
          child: Center(
            child: AppNetworkImage(
              url: widget.images[i].url,
              fit: BoxFit.contain,
              semanticLabel: widget.images[i].alt ?? context.l10n.imageOf(i + 1, widget.images.length),
            ),
          ),
        ),
      ),
    );
  }
}

class _ColourRow {
  const _ColourRow(this.value, this.variant);
  final AttributeValue value;
  final ProductVariant variant;
}

/// "Colours": every colour on its own row with a − / + stepper, so several colours can go in the bag at once.
class _ColourQuantities extends StatelessWidget {
  const _ColourQuantities({required this.title, required this.rows, required this.quantities, required this.onChanged});
  final String title;
  final List<_ColourRow> rows;
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

class _AttributeSelector extends StatelessWidget {
  const _AttributeSelector({
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
                _Swatch(
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

class _Swatch extends StatelessWidget {
  const _Swatch({required this.value, required this.selected, required this.enabled, required this.onTap});
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

class _AvailabilityCard extends ConsumerWidget {
  const _AvailabilityCard({required this.product, required this.variant, required this.branchName});
  final ProductDetail product;
  final ProductVariant? variant;
  final String? branchName;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final palette = context.palette;
    final reason = variant == null
        ? 'out_of_stock'
        : !variant!.availableInBranch
        ? 'not_in_branch'
        : !variant!.inStock
        ? 'out_of_stock'
        : product.availability.reason;
    final ok = reason == null;
    final name = branchName ?? l10n.selectedBranch;
    final (icon, color, text) = switch (reason) {
      null => (Icons.check_circle_outline_rounded, palette.success, l10n.availableAt(name)),
      'not_in_branch' => (Icons.do_not_disturb_on_outlined, palette.muted, l10n.notInBranch(name)),
      _ => (Icons.inventory_2_outlined, palette.warning, l10n.outOfStockAt(name)),
    };

    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, color: color),
              const Gap(AppSpace.sm),
              Expanded(child: Text(text, style: context.text.titleSmall)),
            ],
          ),
          const Gap(AppSpace.sm),
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              if (product.deliveryAvailable != false)
                StatusChip(
                  label: l10n.deliveryAvailable,
                  icon: Icons.local_shipping_outlined,
                  tone: ok ? StatusTone.success : StatusTone.neutral,
                  dense: true,
                ),
              if (product.pickupAvailable != false)
                StatusChip(
                  label: l10n.pickupAvailable,
                  icon: Icons.storefront_outlined,
                  tone: ok ? StatusTone.success : StatusTone.neutral,
                  dense: true,
                ),
              if (product.isOversized)
                StatusChip(label: l10n.oversizedItem, icon: Icons.open_in_full_rounded, dense: true),
              if (product.requiresAssembly)
                StatusChip(label: l10n.assemblyRequired, icon: Icons.handyman_outlined, dense: true),
              if (product.assemblyServiceAvailable)
                StatusChip(
                  label: l10n.assemblyAvailable,
                  icon: Icons.build_circle_outlined,
                  tone: StatusTone.info,
                  dense: true,
                ),
            ],
          ),
          const Gap(AppSpace.xs),
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              TextButton.icon(
                onPressed: () => showAppBottomSheet<void>(
                  context: context,
                  title: l10n.branchAvailabilityTitle,
                  builder: (_) => _BranchStockSheet(productId: product.id, variantId: variant?.id),
                ),
                icon: const Icon(Icons.store_mall_directory_outlined, size: 18),
                label: Text(l10n.checkOtherBranches),
              ),
              TextButton(onPressed: () => context.push('/branches?pick=1'), child: Text(l10n.change)),
            ],
          ),
        ],
      ),
    );
  }
}

class _BranchStockSheet extends ConsumerWidget {
  const _BranchStockSheet({required this.productId, required this.variantId});
  final String productId;
  final String? variantId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final async = ref.watch(productAvailabilityProvider(productId));
    final preferred = ref.watch(preferredBranchIdProvider);
    return async.when(
      loading: () => const SizedBox(height: 240, child: ListSkeleton(count: 3, leading: false)),
      error: (e, _) =>
          ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(productAvailabilityProvider(productId))),
      data: (branches) => Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final b in branches)
            Builder(
              builder: (context) {
                final stock = b.forVariant(variantId);
                final available = stock?.inStock ?? false;
                return ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(
                    b.branch.id == preferred ? Icons.radio_button_checked_rounded : Icons.storefront_outlined,
                    color: b.branch.id == preferred ? context.palette.brass : null,
                  ),
                  title: Text(b.branch.name),
                  subtitle: Text(available ? l10n.availableQty(stock!.available) : l10n.outOfStock),
                  trailing: stock?.price != null
                      ? PriceTag(price: stock!.price!, size: PriceTagSize.small, showBadge: false)
                      : null,
                  onTap: () async {
                    await ref
                        .read(preferredBranchProvider.notifier)
                        .select(BranchRef(id: b.branch.id, name: b.branch.name));
                    if (context.mounted) {
                      Navigator.of(context).pop();
                      showInfoSnack(context, l10n.branchSelectedToast(b.branch.name));
                    }
                  },
                );
              },
            ),
          const Gap(AppSpace.xs),
          Text(l10n.branchChangeNote, style: context.text.bodySmall),
        ],
      ),
    );
  }
}

class _DetailsSection extends StatelessWidget {
  const _DetailsSection({required this.product});
  final ProductDetail product;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    String num(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(1);
    final d = product.dimensions;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (product.descriptionHtml != null && product.descriptionHtml!.trim().isNotEmpty)
          _Expandable(title: l10n.description, initiallyExpanded: true, child: HtmlContent(product.descriptionHtml!)),
        if (product.specifications.isNotEmpty)
          _Expandable(
            title: l10n.specifications,
            child: Column(
              children: [for (final s in product.specifications) SummaryRow(label: s.label, value: s.value)],
            ),
          ),
        if (!d.isEmpty || product.weight != null)
          _Expandable(
            title: l10n.dimensions,
            child: Column(
              children: [
                if (d.length != null) SummaryRow(label: l10n.dimensionLength, value: '${num(d.length!)} ${d.unit}'),
                if (d.width != null) SummaryRow(label: l10n.dimensionWidth, value: '${num(d.width!)} ${d.unit}'),
                if (d.height != null) SummaryRow(label: l10n.dimensionHeight, value: '${num(d.height!)} ${d.unit}'),
                if (product.weight != null)
                  SummaryRow(label: l10n.weight, value: '${num(product.weight!.value)} ${product.weight!.unit}'),
              ],
            ),
          ),
        if (product.warranty != null)
          _Expandable(
            title: l10n.warranty,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.warrantyMonths(product.warranty!.durationMonths), style: context.text.titleSmall),
                if (product.warranty!.provider != null)
                  Text(l10n.warrantyBy(product.warranty!.provider!), style: context.text.bodySmall),
                if (product.warranty!.terms != null) ...[
                  const Gap(AppSpace.xs),
                  Text(product.warranty!.terms!, style: context.text.bodyMedium),
                ],
              ],
            ),
          ),
      ],
    );
  }
}

class _Expandable extends StatelessWidget {
  const _Expandable({required this.title, required this.child, this.initiallyExpanded = false});
  final String title;
  final Widget child;
  final bool initiallyExpanded;

  @override
  Widget build(BuildContext context) => Theme(
    data: context.theme.copyWith(dividerColor: Colors.transparent),
    child: Column(
      children: [
        Divider(color: context.palette.line),
        ExpansionTile(
          initiallyExpanded: initiallyExpanded,
          tilePadding: EdgeInsets.zero,
          childrenPadding: const EdgeInsets.only(bottom: AppSpace.md),
          expandedCrossAxisAlignment: CrossAxisAlignment.start,
          title: Text(title, style: context.text.titleMedium),
          children: [child],
        ),
      ],
    ),
  );
}

class _BuyBar extends StatelessWidget {
  const _BuyBar({
    required this.purchasable,
    required this.price,
    required this.quantity,
    required this.maxQuantity,
    required this.adding,
    required this.onQuantity,
    required this.onAdd,
    required this.onNotify,
    this.multiCount,
    this.onAddMany,
  });

  /// Colour mode: how many items are picked, their total and the add-all action.
  final int? multiCount;
  final VoidCallback? onAddMany;

  final bool purchasable;
  final Money? price;
  final int quantity;
  final int maxQuantity;
  final bool adding;
  final ValueChanged<int> onQuantity;
  final VoidCallback? onAdd;
  final VoidCallback? onNotify;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Container(
      decoration: BoxDecoration(
        color: context.colors.surface,
        border: Border(top: BorderSide(color: context.palette.line)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.sm),
          child: ContentWidth(
            maxWidth: 760,
            child: multiCount != null && purchasable
                ? AppButton(
                    label: multiCount! > 0 ? l10n.addItemsToBag(multiCount!) : l10n.addToCart,
                    icon: Icons.shopping_bag_outlined,
                    loading: adding,
                    onPressed: multiCount! > 0 ? onAddMany : null,
                  )
                : purchasable
                ? Row(
                    children: [
                      QuantityStepper(value: quantity, max: maxQuantity, onChanged: onQuantity, compact: true),
                      const Gap(AppSpace.sm),
                      Expanded(
                        child: AppButton(
                          label: l10n.addToCart,
                          icon: Icons.shopping_bag_outlined,
                          loading: adding,
                          onPressed: onAdd,
                        ),
                      ),
                    ],
                  )
                : AppButton.secondary(
                    label: l10n.notifyBackInStock,
                    icon: Icons.notifications_active_outlined,
                    onPressed: onNotify,
                  ),
          ),
        ),
      ),
    );
  }
}
