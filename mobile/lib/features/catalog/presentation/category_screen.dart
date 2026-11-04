import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/product_list_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Category page: banner, sub-category chips (nested navigation) and the
/// product grid filtered by this category (descendants included server-side).
class CategoryScreen extends ConsumerStatefulWidget {
  const CategoryScreen({super.key, required this.categoryId});
  final String categoryId;

  @override
  ConsumerState<CategoryScreen> createState() => _CategoryScreenState();
}

class _CategoryScreenState extends ConsumerState<CategoryScreen> {
  late ProductQuery _query = ProductQuery(categoryId: widget.categoryId);

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(categoryDetailProvider(widget.categoryId));
    final tree = ref.watch(categoriesProvider).value ?? const [];
    final fromTree = findCategory(tree, widget.categoryId);
    final category = detail.value?.category ?? fromTree;
    final children = (category?.children.isNotEmpty ?? false)
        ? category!.children
        : (fromTree?.children ?? const <CatalogCategory>[]);
    final filters = detail.value?.filters ?? const <AttributeFilter>[];

    return Scaffold(
      appBar: AppBar(
        title: Text(category?.name ?? ''),
        actions: [
          IconButton(
            tooltip: context.l10n.search,
            icon: const Icon(Icons.search_rounded),
            onPressed: () => context.push('/search'),
          ),
        ],
      ),
      body: ProductResults(
        query: _query,
        filters: filters,
        onQueryChanged: (q) => setState(() => _query = q),
        header: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (category?.bannerUrl != null)
              Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(
                  AppSpace.gutter,
                  AppSpace.xs,
                  AppSpace.gutter,
                  AppSpace.sm,
                ),
                child: AspectRatio(
                  aspectRatio: 21 / 9,
                  child: AppNetworkImage(url: category!.bannerUrl, borderRadius: AppRadius.lgAll),
                ),
              ),
            if (children.isNotEmpty)
              SizedBox(
                height: 56,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.gutter, vertical: AppSpace.xs),
                  itemCount: children.length,
                  separatorBuilder: (_, _) => const Gap(AppSpace.xs),
                  itemBuilder: (context, i) {
                    final c = children[i];
                    return ActionChip(
                      label: Text(c.name),
                      avatar: c.iconUrl != null
                          ? ClipOval(child: AppNetworkImage(url: c.iconUrl, width: 20, height: 20))
                          : null,
                      onPressed: () => context.push('/categories/${c.id}'),
                    );
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }
}
