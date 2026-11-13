import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Generic listing (collections, brands, "see all"). Filters & sort apply
/// on top of [initialQuery].
class ProductListScreen extends StatefulWidget {
  const ProductListScreen({super.key, required this.initialQuery, this.title});
  final ProductQuery initialQuery;
  final String? title;

  @override
  State<ProductListScreen> createState() => _ProductListScreenState();
}

class _ProductListScreenState extends State<ProductListScreen> {
  late ProductQuery _query = widget.initialQuery;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.title ?? context.l10n.productsTitle), actions: const [_SearchAction()]),
      body: ProductResults(query: _query, onQueryChanged: (q) => setState(() => _query = q)),
    );
  }
}

class _SearchAction extends StatelessWidget {
  const _SearchAction();
  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: context.l10n.search,
    icon: const Icon(Icons.search_rounded),
    onPressed: () => unawaited(context.push('/search')),
  );
}

/// Toolbar (filters/sort) + infinite grid. Reused by category & search.
class ProductResults extends ConsumerWidget {
  const ProductResults({
    super.key,
    required this.query,
    required this.onQueryChanged,
    this.filters = const [],
    this.header,
    this.emptyTitle,
  });

  final ProductQuery query;
  final ValueChanged<ProductQuery> onQueryChanged;
  final List<AttributeFilter> filters;
  final Widget? header;
  final String? emptyTitle;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final provider = productListProvider(query);
    final list = ref.watch(provider);
    final total = list.value?.total;

    return RefreshIndicator(
      onRefresh: () => ref.read(provider.notifier).refresh(),
      child: NotificationListener<ScrollNotification>(
        onNotification: (n) => handleLoadMoreScroll(n, () => ref.read(provider.notifier).loadMore()),
        child: CustomScrollView(
          slivers: [
            if (header != null) SliverToBoxAdapter(child: header),
            SliverToBoxAdapter(
              child: _Toolbar(query: query, total: total, filters: filters, onChanged: onQueryChanged),
            ),
            ...list.when(
              skipLoadingOnRefresh: true,
              loading: () => [const SliverToBoxAdapter(child: ProductGridSkeleton(shrinkWrap: true))],
              error: (e, _) => [
                SliverFillRemaining(
                  hasScrollBody: false,
                  child: ErrorState(error: e, onRetry: () => ref.invalidate(provider)),
                ),
              ],
              data: (state) {
                if (state.items.isEmpty) {
                  return [
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: EmptyState(
                        icon: Icons.chair_outlined,
                        title: emptyTitle ?? l10n.noProducts,
                        message: l10n.noProductsMessage,
                        actionLabel: query.activeFilterCount > 0 ? l10n.clearAll : null,
                        onAction: query.activeFilterCount > 0 ? () => onQueryChanged(query.clearFilters()) : null,
                      ),
                    ),
                  ];
                }
                return [
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.xs, AppSpace.gutter, 0),
                    sliver: SliverLayoutBuilder(
                      builder: (context, c) => SliverGrid.builder(
                        gridDelegate: productGridDelegate(
                          c.crossAxisExtent,
                          horizontalPadding: 0,
                          textScale: MediaQuery.textScalerOf(context).scale(1),
                        ),
                        itemCount: state.items.length,
                        itemBuilder: (_, i) => ProductTile(product: state.items[i], heroScope: 'list'),
                      ),
                    ),
                  ),
                  SliverToBoxAdapter(
                    child: LoadMoreFooter(state: state, onLoadMore: () => ref.read(provider.notifier).loadMore()),
                  ),
                ];
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _Toolbar extends StatelessWidget {
  const _Toolbar({required this.query, required this.total, required this.filters, required this.onChanged});
  final ProductQuery query;
  final int? total;
  final List<AttributeFilter> filters;
  final ValueChanged<ProductQuery> onChanged;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final count = query.activeFilterCount;
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, AppSpace.xs, AppSpace.sm, AppSpace.xs),
      child: Row(
        children: [
          Expanded(
            child: total == null
                ? const SizedBox.shrink()
                : Text(l10n.resultsCount(total!), style: context.text.bodySmall),
          ),
          Flexible(
            child: TextButton.icon(
              onPressed: () async {
                final next = await showAppBottomSheet<ProductQuery>(
                  context: context,
                  title: l10n.filters,
                  builder: (_) => FilterSheet(query: query, filters: filters),
                );
                if (next != null) onChanged(next);
              },
              icon: Badge(
                isLabelVisible: count > 0,
                label: Text('$count'),
                backgroundColor: context.palette.brass,
                child: const Icon(Icons.tune_rounded, size: 20),
              ),
              label: Text(l10n.filters, maxLines: 1, overflow: TextOverflow.ellipsis),
            ),
          ),
          PopupMenuButton<ProductSort>(
            tooltip: l10n.sortBy,
            initialValue: query.sort,
            onSelected: (s) {
              Haptics.tap();
              onChanged(query.copyWith(sort: s));
            },
            itemBuilder: (_) => [
              for (final s in ProductSort.values)
                CheckedPopupMenuItem(value: s, checked: s == query.sort, child: Text(sortLabel(l10n, s))),
            ],
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.xs, vertical: AppSpace.sm),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.swap_vert_rounded, size: 20),
                  const Gap(4),
                  Flexible(
                    child: Text(
                      l10n.sortBy,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: context.text.labelLarge,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

String sortLabel(AppLocalizations l10n, ProductSort s) => switch (s) {
  ProductSort.relevance => l10n.sortRelevance,
  ProductSort.priceAsc => l10n.sortPriceLowHigh,
  ProductSort.priceDesc => l10n.sortPriceHighLow,
  ProductSort.newest => l10n.sortNewest,
  ProductSort.rating => l10n.sortRating,
  ProductSort.popularity => l10n.sortPopularity,
};

/// Filters bottom sheet: price range, stock / sale, brands, attributes.
class FilterSheet extends ConsumerStatefulWidget {
  const FilterSheet({super.key, required this.query, required this.filters});
  final ProductQuery query;
  final List<AttributeFilter> filters;

  @override
  ConsumerState<FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends ConsumerState<FilterSheet> {
  late final _min = TextEditingController(text: widget.query.priceMin ?? '');
  late final _max = TextEditingController(text: widget.query.priceMax ?? '');
  late bool _inStock = widget.query.inStock;
  late bool _onSale = widget.query.onSale;
  late Set<String> _brands = {...widget.query.brandIds};
  late Set<String> _values = {...widget.query.attributeValueIds};

  @override
  void dispose() {
    _min.dispose();
    _max.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final brands = ref.watch(brandsProvider).value ?? const <Brand>[];
    final digits = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))];

    Widget heading(String t) => Padding(
      padding: const EdgeInsets.only(top: AppSpace.lg, bottom: AppSpace.xs),
      child: Text(t, style: context.text.titleMedium),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        heading(l10n.priceRange),
        Row(
          children: [
            Expanded(
              child: AppTextField(
                controller: _min,
                label: l10n.minPrice,
                keyboardType: TextInputType.number,
                inputFormatters: digits,
                forceLtr: true,
              ),
            ),
            const Gap(AppSpace.sm),
            Expanded(
              child: AppTextField(
                controller: _max,
                label: l10n.maxPrice,
                keyboardType: TextInputType.number,
                inputFormatters: digits,
                forceLtr: true,
              ),
            ),
          ],
        ),
        const Gap(AppSpace.sm),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _inStock,
          onChanged: (v) => setState(() => _inStock = v),
          title: Text(l10n.inStockOnly),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _onSale,
          onChanged: (v) => setState(() => _onSale = v),
          title: Text(l10n.onSaleOnly),
        ),
        for (final f in widget.filters) ...[
          heading(f.attribute.name),
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              for (final v in f.values)
                FilterChip(
                  selected: _values.contains(v.id),
                  onSelected: (s) => setState(() => _values = s ? {..._values, v.id} : ({..._values}..remove(v.id))),
                  avatar: v.colorHex != null ? CircleAvatar(backgroundColor: _hex(v.colorHex!), radius: 8) : null,
                  label: Text(v.name),
                ),
            ],
          ),
        ],
        if (brands.isNotEmpty) ...[
          heading(l10n.brandFilter),
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              for (final b in brands)
                FilterChip(
                  selected: _brands.contains(b.id),
                  onSelected: (s) => setState(() => _brands = s ? {..._brands, b.id} : ({..._brands}..remove(b.id))),
                  label: Text(b.name),
                ),
            ],
          ),
        ],
        const Gap(AppSpace.xl),
        Row(
          children: [
            Expanded(
              child: AppButton.secondary(
                label: l10n.clearAll,
                onPressed: () => Navigator.of(context).pop(widget.query.clearFilters()),
              ),
            ),
            const Gap(AppSpace.sm),
            Expanded(
              child: AppButton(
                label: l10n.showResults,
                onPressed: () {
                  String? clean(String s) => s.trim().isEmpty ? null : s.trim();
                  Navigator.of(context).pop(
                    widget.query.copyWith(
                      priceMin: () => clean(_min.text),
                      priceMax: () => clean(_max.text),
                      inStock: _inStock,
                      onSale: _onSale,
                      brandIds: _brands,
                      attributeValueIds: _values,
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      ],
    );
  }
}

Color _hex(String hex) {
  final h = hex.replaceFirst('#', '');
  final v = int.tryParse(h.length == 6 ? 'FF$h' : h, radix: 16) ?? 0xFFCCCCCC;
  return Color(v);
}

Color colorFromHex(String hex) => _hex(hex);
