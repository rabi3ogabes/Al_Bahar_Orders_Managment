import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/presentation/cart_screen.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/foundation.dart' show ValueListenable;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// One thing to put in the cart: a variant, how many, and what the cart window needs to draw it before the server answers.
@immutable
class CartAddition {
  const CartAddition({
    required this.variantId,
    required this.product,
    this.quantity = 1,
    this.unitPrice,
    this.variantName,
  });
  final String variantId;
  final ProductSummary product;
  final int quantity;
  final EffectivePrice? unitPrice;
  final String? variantName;
}

/// Add to cart, the same way everywhere (product page, cards, quick view, buy again, search, suggestions):
///
/// 1. a guest is sent to sign in first;
/// 2. the cart and its badge change **at once**, and the cart window opens over the current screen — no navigation;
/// 3. if the server refuses, the cart goes back, the window closes and the person is told why.
///
/// Resolves when the server has answered. [beforeLeaving] runs before a guest is sent to sign in (a window closes itself).
Future<void> addToCartAndShow(
  BuildContext context,
  WidgetRef ref,
  List<CartAddition> additions, {
  VoidCallback? beforeLeaving,
}) async {
  if (additions.isEmpty || !requireAuth(context, ref, beforeLeaving: beforeLeaving)) return;
  final controller = ref.read(cartControllerProvider.notifier);
  final failed = ValueNotifier<bool>(false);
  try {
    // Every add starts now (each one updates the cart before its first await), so the window opens with all of them.
    final running = <Future<Cart>>[
      for (final a in additions)
        controller.addItem(
          a.variantId,
          quantity: a.quantity,
          product: a.product,
          unitPrice: a.unitPrice,
          variantName: a.variantName,
        ),
    ];
    unawaited(showCartPanel(context, addedVariantIds: {for (final a in additions) a.variantId}, closeSignal: failed));
    await Future.wait(running);
    Haptics.success();
  } on ApiException catch (e) {
    // The window closes (it only showed what never happened) and the error is told on the page behind it.
    failed.value = true;
    if (context.mounted) showErrorSnack(context, e);
  }
}

/// The cart in a side window over the current screen: what was just added (photo, name, price, quantity − / +), the cart
/// subtotal and exactly two actions — **Keep shopping** (close, the page behind stays exactly as it was) and **Checkout**.
///
/// It never closes by itself. Scrim, swipe-down (phone), Esc and Back close it. Without [addedVariantIds] it lists the
/// whole cart ("Your cart"); a [notice] shows above the lines. [closeSignal] closes it when it turns true.
Future<void> showCartPanel(
  BuildContext context, {
  Set<String> addedVariantIds = const {},
  String? notice,
  ValueListenable<bool>? closeSignal,
}) {
  final l10n = context.l10n;
  return showSidePanel<void>(
    context: context,
    title: l10n.cartTitle,
    scrollable: false,
    builder: (_) => CartPanelBody(addedVariantIds: addedVariantIds, notice: notice, closeSignal: closeSignal),
  );
}

/// Body of [showCartPanel]. Public so tests (and other windows) can host it.
class CartPanelBody extends ConsumerStatefulWidget {
  const CartPanelBody({super.key, this.addedVariantIds = const {}, this.notice, this.closeSignal});
  final Set<String> addedVariantIds;
  final String? notice;
  final ValueListenable<bool>? closeSignal;

  @override
  ConsumerState<CartPanelBody> createState() => _CartPanelBodyState();
}

class _CartPanelBodyState extends ConsumerState<CartPanelBody> {
  bool _checkingOut = false;

  @override
  void initState() {
    super.initState();
    widget.closeSignal?.addListener(_onCloseSignal);
    // The server can refuse before this window has been built: then there is nothing to wait for.
    if (widget.closeSignal?.value ?? false) WidgetsBinding.instance.addPostFrameCallback((_) => _onCloseSignal());
  }

  @override
  void dispose() {
    widget.closeSignal?.removeListener(_onCloseSignal);
    super.dispose();
  }

  void _onCloseSignal() {
    if (widget.closeSignal?.value ?? false) {
      if (mounted && (ModalRoute.of(context)?.isCurrent ?? false)) Navigator.of(context).pop();
    }
  }

  /// The same guards as the Checkout button of the cart tab: prices and stock are re-checked first.
  Future<void> _checkout() async {
    if (_checkingOut) return;
    setState(() => _checkingOut = true);
    try {
      final fresh = await ref.read(cartControllerProvider.notifier).revalidate();
      if (!mounted) return;
      if (fresh.canCheckout) {
        final router = GoRouter.of(context);
        Navigator.of(context).pop();
        unawaited(router.push('/checkout'));
      } else {
        Haptics.warning();
        showInfoSnack(context, context.l10n.cartHasIssues);
      }
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _checkingOut = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final async = ref.watch(cartControllerProvider);
    final busy = ref.watch(cartBusyItemsProvider);
    return async.when(
      skipLoadingOnRefresh: true,
      skipLoadingOnReload: true,
      loading: () => const SizedBox(height: 260, child: ListSkeleton(count: 2)),
      error: (e, _) => ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(cartControllerProvider)),
      data: (cart) {
        if (cart.isEmpty) {
          return EmptyState(
            icon: Icons.shopping_bag_outlined,
            title: l10n.cartEmpty,
            message: l10n.cartEmptyMessage,
            compact: true,
          );
        }
        final added = widget.addedVariantIds;
        final lines = added.isEmpty ? cart.items : cart.items.where((i) => added.contains(i.variantId)).toList();
        final syncing = busy.isNotEmpty;
        return SidePanelLayout(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
          footer: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _SubtotalRow(cart: cart, syncing: syncing),
              const Gap(AppSpace.sm),
              AppButton(
                label: l10n.checkoutTitle,
                icon: Icons.arrow_forward_rounded,
                loading: _checkingOut || busy.any((id) => id.startsWith('pending-')),
                onPressed: cart.canCheckout ? _checkout : null,
              ),
              const Gap(AppSpace.xs),
              AppButton.secondary(label: l10n.keepShopping, onPressed: () => Navigator.of(context).pop()),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (added.isNotEmpty) const _AddedConfirmation(),
              if (widget.notice != null) _Notice(message: widget.notice!),
              if (cart.warnings.isNotEmpty || cart.hasIssues) CartWarningsBanner(cart: cart),
              for (final item in lines) _CartPanelLine(item: item, highlight: added.isNotEmpty),
            ],
          ),
        );
      },
    );
  }
}

/// "Added to your cart" with a tick; announced to screen readers when the window opens.
class _AddedConfirmation extends StatelessWidget {
  const _AddedConfirmation();

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: AppSpace.sm),
    child: Semantics(
      liveRegion: true,
      child: Row(
        children: [
          Icon(Icons.check_circle_rounded, color: context.palette.success),
          const Gap(AppSpace.xs),
          Expanded(child: Text(context.l10n.addedToCart, style: context.text.titleMedium)),
        ],
      ),
    ),
  );
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

/// Items and subtotal. The subtotal is the server's; while a change is on its way it is dimmed with a small spinner
/// instead of being guessed (the app never does money arithmetic), and it is announced when it changes.
class _SubtotalRow extends StatelessWidget {
  const _SubtotalRow({required this.cart, required this.syncing});
  final Cart cart;
  final bool syncing;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Semantics(
      liveRegion: true,
      label: '${l10n.subtotal} ${cart.subtotal.formatted}, ${l10n.itemsCount(cart.itemsCount)}',
      child: ExcludeSemantics(
        child: Row(
          children: [
            Expanded(
              child: Text(
                '${l10n.subtotal} · ${l10n.itemsCount(cart.itemsCount)}',
                style: context.text.bodyMedium,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            const Gap(AppSpace.sm),
            if (syncing)
              const Padding(
                padding: EdgeInsetsDirectional.only(end: AppSpace.xs),
                child: SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)),
              ),
            AnimatedOpacity(
              opacity: syncing ? 0.5 : 1,
              duration: AppDurations.fast,
              child: Text(
                cart.subtotal.formatted,
                style: context.text.titleLarge?.copyWith(fontWeight: FontWeight.w700),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A cart line in the window: photo, name, price and the quantity − / + (− stops at 1; removing a line is done in the cart).
class _CartPanelLine extends ConsumerWidget {
  const _CartPanelLine({required this.item, required this.highlight});
  final CartItem item;
  final bool highlight;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final busy = ref.watch(cartBusyItemsProvider).contains(item.id);
    final maxQty = (item.availableQuantity ?? 99).clamp(item.quantity, 99);
    final unit = item.unitPrice;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpace.xs),
      child: Semantics(
        container: true,
        label: '${item.product.name}, ${item.quantity}',
        child: SurfaceCard(
          padding: const EdgeInsets.all(10),
          color: highlight ? context.palette.brassTint : null,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 72,
                height: 72,
                child: AppNetworkImage(
                  url: item.product.image?.listUrl,
                  semanticLabel: item.product.image?.alt ?? item.product.name,
                  borderRadius: AppRadius.imageAll,
                  fit: BoxFit.contain,
                ),
              ),
              const Gap(AppSpace.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.product.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: context.text.titleSmall,
                    ),
                    if (item.variantName != null)
                      Text(
                        item.variantName!,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: context.text.bodySmall,
                      ),
                    const Gap(AppSpace.xxs),
                    if (unit != null) PriceTag(price: unit, size: PriceTagSize.small, showBadge: false),
                    const Gap(AppSpace.xxs),
                    QuantityStepper(
                      value: item.quantity,
                      max: maxQty,
                      busy: busy,
                      compact: true,
                      onChanged: (q) async {
                        try {
                          await ref.read(cartControllerProvider.notifier).updateQuantity(item.id, q);
                        } on ApiException catch (e) {
                          if (context.mounted) showErrorSnack(context, e);
                        }
                      },
                    ),
                    if (item.issue == 'insufficient_stock')
                      Text(
                        l10n.itemInsufficientStock(item.availableQuantity ?? 0),
                        style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
