import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/wishlist/presentation/state/wishlist_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class WishlistScreen extends ConsumerWidget {
  const WishlistScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final wishlist = ref.watch(wishlistControllerProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.wishlistTitle)),
      body: wishlist.when(
        skipLoadingOnRefresh: true,
        loading: () => const ProductGridSkeleton(),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(wishlistControllerProvider)),
        data: (items) => items.isEmpty
            ? EmptyState(
                icon: Icons.favorite_border_rounded,
                title: l10n.wishlistEmpty,
                message: l10n.wishlistEmptyMessage,
                actionLabel: l10n.startShopping,
                onAction: () => context.go('/home'),
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
      ),
    );
  }
}
