import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/design_tokens.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/fresh.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Top-level category tree with editorial tiles.
class CategoriesScreen extends ConsumerWidget {
  const CategoriesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final categories = ref.watch(categoriesProvider);
    return FreshScaffold(
      header: FreshHeader(
        title: l10n.categoriesTitle,
        tagline: l10n.freshTaglineCategories,
        trailing: [
          FreshHeaderAction(
            icon: const Icon(Icons.search_rounded),
            tooltip: l10n.search,
            onPressed: () => context.push('/search'),
          ),
        ],
      ),
      appBar: AppBar(
        title: Text(l10n.categoriesTitle),
        actions: [
          IconButton(
            tooltip: l10n.search,
            icon: const Icon(Icons.search_rounded),
            onPressed: () => context.push('/search'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(categoriesProvider.future),
        child: categories.when(
          skipLoadingOnRefresh: true,
          loading: () => const _CategoryGridSkeleton(),
          error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(categoriesProvider)),
          data: (list) => list.isEmpty
              ? EmptyState(icon: Icons.category_outlined, title: l10n.noProducts)
              : LayoutBuilder(
                  builder: (context, c) {
                    final cols = c.maxWidth >= 900 ? 4 : (c.maxWidth >= 600 ? 3 : 2);
                    return GridView.builder(
                      padding: const EdgeInsets.all(AppSpace.gutter),
                      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: cols,
                        mainAxisSpacing: AppSpace.md,
                        crossAxisSpacing: AppSpace.md,
                        childAspectRatio: 0.82,
                      ),
                      itemCount: list.length,
                      itemBuilder: (_, i) => CategoryTile(category: list[i]),
                    );
                  },
                ),
        ),
      ),
    );
  }
}

class CategoryTile extends StatelessWidget {
  const CategoryTile({super.key, required this.category});
  final CatalogCategory category;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    label: category.name,
    excludeSemantics: true,
    child: InkWell(
      borderRadius: context.design.cardBorderRadius,
      onTap: () => context.push('/categories/${category.id}'),
      child: ClipRRect(
        borderRadius: context.design.cardBorderRadius,
        child: Stack(
          fit: StackFit.expand,
          children: [
            AppNetworkImage(url: category.imageUrl ?? category.bannerUrl),
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [Colors.transparent, Colors.black.withValues(alpha: 0.6)],
                  stops: const [0.45, 1],
                ),
              ),
            ),
            PositionedDirectional(
              start: AppSpace.md,
              end: AppSpace.md,
              bottom: AppSpace.md,
              child: Text(
                category.name,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: context.text.titleLarge?.copyWith(color: Colors.white),
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _CategoryGridSkeleton extends StatelessWidget {
  const _CategoryGridSkeleton();

  @override
  Widget build(BuildContext context) => GridView.builder(
    physics: const NeverScrollableScrollPhysics(),
    padding: const EdgeInsets.all(AppSpace.gutter),
    gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
      crossAxisCount: 2,
      mainAxisSpacing: AppSpace.md,
      crossAxisSpacing: AppSpace.md,
      childAspectRatio: 0.82,
    ),
    itemCount: 6,
    itemBuilder: (_, _) => const Skeleton(radius: AppRadius.card),
  );
}
