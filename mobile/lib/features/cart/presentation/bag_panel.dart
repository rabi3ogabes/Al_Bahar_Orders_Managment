import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/cart/presentation/cart_screen.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/upsell_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Opens the cart in a side window, so the person keeps their place on the page they were shopping on.
///
/// With [addedVariantIds] it confirms what was just added ("Added to your cart", those lines highlighted); without, it is
/// simply "Your cart". A [notice] (for example "2 items could not be added") shows above the lines. The footer pins the
/// two things people do next: go to checkout, or open the full cart tab.
Future<void> showBagPanel(BuildContext context, {Set<String> addedVariantIds = const {}, String? notice}) {
  final l10n = context.l10n;
  return showSidePanel<void>(
    context: context,
    title: addedVariantIds.isEmpty ? l10n.cartTitle : l10n.addedToCart,
    scrollable: false,
    builder: (_) => BagPanelBody(addedVariantIds: addedVariantIds, notice: notice),
  );
}

/// Body of [showBagPanel]. Public so tests (and other windows) can host it.
class BagPanelBody extends ConsumerStatefulWidget {
  const BagPanelBody({super.key, this.addedVariantIds = const {}, this.notice});
  final Set<String> addedVariantIds;
  final String? notice;

  @override
  ConsumerState<BagPanelBody> createState() => _BagPanelBodyState();
}

class _BagPanelBodyState extends ConsumerState<BagPanelBody> {
  bool _busy = false;

  /// Closes this window first, then navigates the page behind it ("Back" from there never returns to the window).
  void _leaveTo(void Function(GoRouter router) navigate) {
    final router = GoRouter.of(context);
    Navigator.of(context).pop();
    navigate(router);
  }

  Future<void> _checkout() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final fresh = await ref.read(cartControllerProvider.notifier).revalidate();
      if (!mounted) return;
      if (fresh.canCheckout) {
        _leaveTo((router) => unawaited(router.push('/checkout')));
      } else {
        Haptics.warning();
        showInfoSnack(context, context.l10n.cartHasIssues);
      }
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final cart = ref.watch(cartControllerProvider);
    return cart.when(
      skipLoadingOnRefresh: true,
      skipLoadingOnReload: true,
      loading: () => const SizedBox(height: 260, child: ListSkeleton(count: 2)),
      error: (e, _) => ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(cartControllerProvider)),
      data: (c) {
        if (c.isEmpty) {
          return EmptyState(
            icon: Icons.shopping_bag_outlined,
            title: l10n.cartEmpty,
            message: l10n.cartEmptyMessage,
            compact: true,
          );
        }
        return SidePanelLayout(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
          footer: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(l10n.itemsCount(c.itemsCount), style: context.text.bodyMedium),
                  Text(
                    '${l10n.subtotal}  ${c.subtotal.formatted}',
                    style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ],
              ),
              const Gap(AppSpace.xs),
              AppButton(
                label: l10n.proceedToCheckout,
                icon: Icons.arrow_forward_rounded,
                loading: _busy,
                onPressed: c.canCheckout ? _checkout : null,
              ),
              const Gap(AppSpace.xs),
              AppButton.secondary(
                label: l10n.viewCart,
                icon: Icons.shopping_bag_outlined,
                onPressed: () => _leaveTo((router) => router.go('/cart')),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (widget.notice != null) _Notice(message: widget.notice!),
              if (c.warnings.isNotEmpty || c.hasIssues) CartWarningsBanner(cart: c),
              for (final item in c.items)
                CartLine(
                  item: item,
                  highlight: widget.addedVariantIds.contains(item.variantId),
                  onOpenProduct: () => _leaveTo(
                    (router) => unawaited(router.push('/products/${item.product.id}?hero=cart', extra: item.product)),
                  ),
                ),
              const CartSuggestionsStrip(),
            ],
          ),
        );
      },
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: AppSpace.sm),
    padding: const EdgeInsets.all(AppSpace.sm),
    decoration: BoxDecoration(color: context.palette.brassTint, borderRadius: AppRadius.imageAll),
    child: Semantics(
      liveRegion: true,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.check_circle_outline_rounded, size: 20, color: context.palette.brassDeep),
          const Gap(AppSpace.xs),
          Expanded(child: Text(message, style: context.text.bodyMedium)),
        ],
      ),
    ),
  );
}
