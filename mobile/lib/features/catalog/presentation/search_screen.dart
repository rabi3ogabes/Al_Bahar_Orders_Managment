import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/debouncer.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/product_list_screen.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Debounced Arabic/English search with recent searches.
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key, this.initialQuery});
  final String? initialQuery;

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  late final _controller = TextEditingController(text: widget.initialQuery ?? '');
  final _debouncer = Debouncer(delay: const Duration(milliseconds: 400));
  late String _term = (widget.initialQuery ?? '').trim();
  ProductQuery? _query;

  @override
  void initState() {
    super.initState();
    if (_term.length >= 2) _query = ProductQuery(q: _term);
  }

  @override
  void dispose() {
    _debouncer.dispose();
    _controller.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    _debouncer(() {
      if (!mounted) return;
      final term = value.trim();
      setState(() {
        _term = term;
        _query = term.length >= 2 ? (_query ?? const ProductQuery()).copyWith(q: term) : null;
      });
    });
  }

  void _submit(String value) {
    _debouncer.cancel();
    final term = value.trim();
    if (term.isEmpty) return;
    ref.read(recentSearchesProvider.notifier).add(term);
    setState(() {
      _term = term;
      _query = (_query ?? const ProductQuery()).copyWith(q: term);
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final recent = ref.watch(recentSearchesProvider);
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: Padding(
          padding: const EdgeInsetsDirectional.only(end: AppSpace.md),
          child: TextField(
            controller: _controller,
            autofocus: widget.initialQuery == null,
            textInputAction: TextInputAction.search,
            onChanged: _onChanged,
            onSubmitted: _submit,
            decoration: InputDecoration(
              hintText: l10n.searchHint,
              prefixIcon: const Icon(Icons.search_rounded),
              suffixIcon: _controller.text.isEmpty
                  ? null
                  : IconButton(
                      tooltip: l10n.clear,
                      icon: const Icon(Icons.close_rounded),
                      onPressed: () {
                        _controller.clear();
                        setState(() {
                          _term = '';
                          _query = null;
                        });
                      },
                    ),
              contentPadding: const EdgeInsets.symmetric(vertical: 12),
            ),
          ),
        ),
      ),
      body: _query == null
          ? ListView(
              padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
              children: [
                if (recent.isNotEmpty) ...[
                  SectionHeader(
                    title: l10n.recentSearches,
                    actionLabel: l10n.clearRecent,
                    onAction: () => ref.read(recentSearchesProvider.notifier).clear(),
                    padding: const EdgeInsetsDirectional.fromSTEB(
                      AppSpace.gutter,
                      AppSpace.sm,
                      AppSpace.xs,
                      AppSpace.xs,
                    ),
                  ),
                  for (final r in recent)
                    ListTile(
                      leading: const Icon(Icons.history_rounded),
                      title: Text(r),
                      trailing: IconButton(
                        tooltip: l10n.remove,
                        icon: const Icon(Icons.close_rounded, size: 18),
                        onPressed: () => ref.read(recentSearchesProvider.notifier).remove(r),
                      ),
                      onTap: () {
                        _controller.text = r;
                        _submit(r);
                      },
                    ),
                ] else
                  Padding(
                    padding: const EdgeInsets.only(top: AppSpace.xxl),
                    child: EmptyState(
                      icon: Icons.manage_search_rounded,
                      title: l10n.searchTitle,
                      message: l10n.searchPrompt,
                      compact: true,
                    ),
                  ),
                const Gap(AppSpace.lg),
                Center(
                  child: TextButton.icon(
                    onPressed: () => context.go('/categories'),
                    icon: const Icon(Icons.grid_view_rounded),
                    label: Text(l10n.categoriesTitle),
                  ),
                ),
              ],
            )
          : ProductResults(
              query: _query!,
              emptyTitle: l10n.searchNoResults(_term),
              onQueryChanged: (q) => setState(() => _query = q),
            ),
    );
  }
}
