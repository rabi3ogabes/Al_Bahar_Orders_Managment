import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Live suggestions under the search box: a "did you mean" fix, matching categories and brands, and the top products.
class SearchSuggestionsPanel extends ConsumerWidget {
  const SearchSuggestionsPanel({super.key, required this.term, required this.onSearch});

  /// What the customer typed (already trimmed, at least 2 characters).
  final String term;

  /// Runs the full search for a term (the typed text, a correction, or "see all").
  final ValueChanged<String> onSearch;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final async = ref.watch(searchSuggestionsProvider(term));
    // Keep the previous list on screen while the next keystroke loads, so the panel never flickers.
    final data = async.value;
    if (data == null) {
      return async.hasError
          ? ErrorState(error: async.error!, onRetry: () => ref.invalidate(searchSuggestionsProvider(term)))
          : const Center(child: CircularProgressIndicator());
    }
    return ListView(
      padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      children: [
        if (data.didYouMean != null)
          ListTile(
            leading: Icon(Icons.auto_fix_high_rounded, color: context.colors.primary),
            title: Text(l10n.didYouMean(data.didYouMean!), style: const TextStyle(fontWeight: FontWeight.w600)),
            onTap: () => onSearch(data.didYouMean!),
          ),
        if (data.categories.isNotEmpty || data.brands.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.xs, AppSpace.gutter, AppSpace.sm),
            child: Wrap(
              spacing: AppSpace.xs,
              runSpacing: AppSpace.xs,
              children: [
                for (final c in data.categories)
                  ActionChip(
                    avatar: const Icon(Icons.grid_view_rounded, size: 16),
                    label: Text(c.name),
                    onPressed: () => context.push('/categories/${c.id}'),
                  ),
                for (final b in data.brands)
                  ActionChip(
                    avatar: const Icon(Icons.sell_outlined, size: 16),
                    label: Text(b.name),
                    onPressed: () => context.push('/products?brand_id=${b.id}&title=${Uri.encodeComponent(b.name)}'),
                  ),
              ],
            ),
          ),
        for (final p in data.products)
          ListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter, vertical: 2),
            leading: SizedBox(
              width: 52,
              height: 52,
              child: AppNetworkImage(
                url: p.image?.thumbUrl ?? p.image?.url,
                borderRadius: AppRadius.imageAll,
                fit: BoxFit.contain,
              ),
            ),
            title: Text(p.name, maxLines: 2, overflow: TextOverflow.ellipsis),
            subtitle: p.price == null
                ? null
                : Text(
                    p.price!.finalPrice.formatted,
                    style: TextStyle(color: context.colors.primary, fontWeight: FontWeight.w600),
                  ),
            trailing: const Icon(Icons.north_west_rounded, size: 18),
            onTap: () => openProduct(context, p, heroScope: 'suggest'),
          ),
        if (data.products.isNotEmpty)
          ListTile(
            leading: const Icon(Icons.search_rounded),
            title: Text(l10n.searchSeeAll(term), style: const TextStyle(fontWeight: FontWeight.w600)),
            onTap: () => onSearch(term),
          ),
        if (data.isEmpty && data.didYouMean == null)
          Padding(
            padding: const EdgeInsets.only(top: AppSpace.xxl),
            child: EmptyState(
              icon: Icons.search_off_rounded,
              title: l10n.searchNoResults(term),
              message: l10n.searchPrompt,
              compact: true,
            ),
          ),
      ],
    );
  }
}
