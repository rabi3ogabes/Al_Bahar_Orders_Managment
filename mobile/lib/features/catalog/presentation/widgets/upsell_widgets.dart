import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_suggestions.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// A titled row of suggested products (cards with a quick add button). Renders nothing when there are none.
class UpsellSection extends StatelessWidget {
  const UpsellSection({
    super.key,
    required this.title,
    required this.products,
    required this.scope,
    this.flush = false,
  });

  final String title;
  final List<ProductSummary> products;
  final String scope;

  /// True inside an already padded list: the row aligns with the surrounding content instead of adding its own gutter.
  final bool flush;

  @override
  Widget build(BuildContext context) {
    if (products.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionHeader(
          title: title,
          padding: flush ? const EdgeInsetsDirectional.fromSTEB(0, AppSpace.md, 0, AppSpace.xs) : null,
        ),
        ProductCarousel(
          products: products,
          scope: scope,
          padding: flush ? const EdgeInsetsDirectional.fromSTEB(0, 4, 0, 16) : null,
        ),
      ],
    );
  }
}

/// "You may also need" strip for the bag: skeleton while loading, hidden when there is nothing to suggest.
class CartSuggestionsStrip extends ConsumerWidget {
  const CartSuggestionsStrip({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final suggestions = ref.watch(cartSuggestionsProvider);
    return suggestions.when(
      skipLoadingOnReload: true,
      skipLoadingOnRefresh: true,
      loading: () => const _StripSkeleton(),
      error: (_, _) => const SizedBox.shrink(),
      data: (items) =>
          UpsellSection(title: context.l10n.youMayAlsoNeed, products: items, scope: 'cart-suggest', flush: true),
    );
  }
}

class _StripSkeleton extends StatelessWidget {
  const _StripSkeleton();

  @override
  Widget build(BuildContext context) => Semantics(
    label: context.l10n.youMayAlsoNeed,
    child: const ExcludeSemantics(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(height: AppSpace.md),
          ProductCarouselSkeleton(flush: true),
        ],
      ),
    ),
  );
}
