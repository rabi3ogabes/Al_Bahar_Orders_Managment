import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/fresh.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/wishlist/presentation/state/wishlist_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Opens the saved products in a side window, so the person can look, add one to the cart and stay on their page.
Future<void> showWishlistPanel(BuildContext context) => showSidePanel<void>(
  context: context,
  title: context.l10n.wishlistTitle,
  scrollable: false,
  builder: (_) => const WishlistScreen(embedded: true),
);

class WishlistScreen extends ConsumerWidget {
  const WishlistScreen({super.key, this.embedded = false});

  /// True inside a side window ([showWishlistPanel]): no app bar or header.
  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final wishlist = ref.watch(wishlistControllerProvider);
    final body = wishlist.when(
      skipLoadingOnRefresh: true,
      loading: () => const ProductGridSkeleton(),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(wishlistControllerProvider)),
      data: (items) => items.isEmpty
          ? EmptyState(
              icon: Icons.favorite_border_rounded,
              title: l10n.wishlistEmpty,
              message: l10n.wishlistEmptyMessage,
              actionLabel: l10n.startShopping,
              onAction: () {
                final router = GoRouter.of(context);
                if (embedded) Navigator.of(context).pop();
                router.go('/home');
              },
            )
          : RefreshIndicator(
              onRefresh: () => ref.refresh(wishlistControllerProvider.future),
              child: LayoutBuilder(
                builder: (context, c) => GridView.builder(
                  padding: const EdgeInsets.all(AppSpace.gutter),
                  gridDelegate: productGridDelegate(c.maxWidth, textScale: MediaQuery.textScalerOf(context).scale(1)),
                  itemCount: items.length,
                  itemBuilder: (_, i) => ProductTile(product: items[i].product, heroScope: 'wishlist'),
                ),
              ),
            ),
    );
    if (embedded) return body;
    return FreshScaffold(
      appBar: AppBar(title: Text(l10n.wishlistTitle)),
      header: FreshHeader(title: l10n.wishlistTitle, tagline: l10n.freshTaglineWishlist),
      body: body,
    );
  }
}
