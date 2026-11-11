import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class CartScreen extends ConsumerWidget {
  const CartScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final signedIn = ref.watch(isAuthenticatedProvider);
    if (!signedIn) {
      return Scaffold(
        appBar: AppBar(title: Text(l10n.cartTitle)),
        body: EmptyState(
          icon: Icons.shopping_bag_outlined,
          title: l10n.signInRequiredTitle,
          message: l10n.signInRequiredMessage,
          actionLabel: l10n.signIn,
          onAction: () => context.push('/login?from=%2Fcart'),
        ),
      );
    }
    final cart = ref.watch(cartControllerProvider);
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.cartTitle),
        actions: const [
          Padding(
            padding: EdgeInsetsDirectional.only(end: AppSpace.sm),
            child: Center(child: BranchPill()),
          ),
        ],
      ),
      body: cart.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const ListSkeleton(count: 3),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(cartControllerProvider)),
        data: (c) => c.isEmpty
            ? EmptyState(
                icon: Icons.shopping_bag_outlined,
                title: l10n.cartEmpty,
                message: l10n.cartEmptyMessage,
                actionLabel: l10n.startShopping,
                onAction: () => context.go('/home'),
              )
            : RefreshIndicator(
                onRefresh: () => ref.read(cartControllerProvider.notifier).revalidate(),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.xl),
                  children: [
                    ContentWidth(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          if (c.warnings.isNotEmpty || c.hasIssues) _WarningsBanner(cart: c),
                          for (final item in c.items) ...[CartLine(item: item), Divider(color: context.palette.line)],
                          const Gap(AppSpace.md),
                          _CouponField(code: c.couponCode),
                          const Gap(AppSpace.lg),
                          SummaryRow(label: l10n.itemsCount(c.itemsCount), value: ''),
                          SummaryRow(label: l10n.subtotal, value: c.subtotal.formatted, emphasize: true),
                          const Gap(AppSpace.xxs),
                          Text(l10n.totalsCalculatedAtCheckout, style: context.text.bodySmall),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
      ),
      bottomNavigationBar: cart.value == null || cart.value!.isEmpty ? null : _CheckoutBar(cart: cart.value!),
    );
  }
}

class _WarningsBanner extends StatelessWidget {
  const _WarningsBanner({required this.cart});
  final Cart cart;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final messages = [if (cart.hasIssues) context.l10n.cartHasIssues, ...cart.warnings];
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpace.md),
      padding: const EdgeInsets.all(AppSpace.md),
      decoration: BoxDecoration(
        color: palette.warning.withValues(alpha: 0.1),
        borderRadius: AppRadius.cardAll,
        border: Border.all(color: palette.warning.withValues(alpha: 0.35)),
      ),
      child: Semantics(
        liveRegion: true,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.info_outline_rounded, color: palette.warning),
            const Gap(AppSpace.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [for (final m in messages) Text(m, style: context.text.bodyMedium)],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class CartLine extends ConsumerWidget {
  const CartLine({super.key, required this.item});
  final CartItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final palette = context.palette;
    final busy = ref.watch(cartBusyItemsProvider).contains(item.id);
    final issue = switch (item.issue) {
      'unavailable_in_branch' => l10n.itemUnavailable,
      'insufficient_stock' => l10n.itemInsufficientStock(item.availableQuantity ?? 0),
      'inactive' => l10n.itemInactive,
      _ => item.available ? null : l10n.itemUnavailable,
    };
    final maxQty = item.issue == 'insufficient_stock'
        ? (item.availableQuantity ?? 99).clamp(item.quantity, 99)
        : (item.availableQuantity ?? 99).clamp(1, 99);

    Future<void> run(Future<void> Function() action) async {
      try {
        await action();
      } on ApiException catch (e) {
        if (context.mounted) showErrorSnack(context, e);
      }
    }

    return Dismissible(
      key: ValueKey(item.id),
      direction: DismissDirection.endToStart,
      background: Container(
        alignment: AlignmentDirectional.centerEnd,
        padding: const EdgeInsetsDirectional.only(end: AppSpace.lg),
        color: palette.danger.withValues(alpha: 0.12),
        child: Icon(Icons.delete_outline_rounded, color: palette.danger),
      ),
      confirmDismiss: (_) =>
          confirmAction(context, title: l10n.removeItemConfirm, confirmLabel: l10n.remove, destructive: true),
      onDismissed: (_) => run(() => ref.read(cartControllerProvider.notifier).removeItem(item.id)),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpace.md),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            GestureDetector(
              onTap: () => openProduct(context, item.product, heroScope: 'cart'),
              child: SizedBox(
                width: 88,
                height: 110,
                child: Hero(
                  tag: item.product.heroTag('cart'),
                  child: AppNetworkImage(url: item.product.image?.listUrl, borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ),
            const Gap(AppSpace.md),
            Expanded(
              child: Opacity(
                opacity: item.hasIssue && item.issue != 'insufficient_stock' ? 0.6 : 1,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.product.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: context.text.titleSmall,
                    ),
                    if (item.variantName != null) Text(item.variantName!, style: context.text.bodySmall),
                    const Gap(AppSpace.xxs),
                    if (item.unitPrice != null)
                      PriceTag(price: item.unitPrice!, size: PriceTagSize.small, showBadge: false),
                    if (item.priceChanged && item.previousPrice != null)
                      Text(
                        l10n.priceChangedFrom(item.previousPrice!.formatted),
                        style: context.text.bodySmall?.copyWith(color: palette.warning),
                      ),
                    if (issue != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(issue, style: context.text.bodySmall?.copyWith(color: palette.danger)),
                      ),
                    const Gap(AppSpace.xs),
                    // Wraps onto two lines on narrow phones / large text.
                    Wrap(
                      alignment: WrapAlignment.spaceBetween,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      spacing: AppSpace.sm,
                      runSpacing: AppSpace.xs,
                      children: [
                        QuantityStepper(
                          value: item.quantity,
                          max: maxQty,
                          busy: busy,
                          compact: true,
                          onChanged: item.hasIssue && item.issue != 'insufficient_stock'
                              ? null
                              : (q) => run(() => ref.read(cartControllerProvider.notifier).updateQuantity(item.id, q)),
                        ),
                        if (item.lineTotal != null) Text(item.lineTotal!.formatted, style: context.text.titleSmall),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            IconButton(
              tooltip: l10n.remove,
              icon: const Icon(Icons.close_rounded, size: 20),
              onPressed: busy
                  ? null
                  : () async {
                      if (await confirmAction(
                        context,
                        title: l10n.removeItemConfirm,
                        confirmLabel: l10n.remove,
                        destructive: true,
                      )) {
                        await run(() => ref.read(cartControllerProvider.notifier).removeItem(item.id));
                      }
                    },
            ),
          ],
        ),
      ),
    );
  }
}

class _CouponField extends ConsumerStatefulWidget {
  const _CouponField({required this.code});
  final String? code;

  @override
  ConsumerState<_CouponField> createState() => _CouponFieldState();
}

class _CouponFieldState extends ConsumerState<_CouponField> {
  final _controller = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _apply() async {
    if (_controller.text.trim().isEmpty) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(cartControllerProvider.notifier).applyCoupon(_controller.text);
      Haptics.success();
      _controller.clear();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.fieldError('coupon_code') ?? describeError(context.l10n, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    if (widget.code != null) {
      return SurfaceCard(
        child: Row(
          children: [
            Icon(Icons.local_offer_outlined, color: context.palette.success),
            const Gap(AppSpace.sm),
            Expanded(child: Text(l10n.couponApplied(widget.code!), style: context.text.titleSmall)),
            TextButton(
              onPressed: _busy
                  ? null
                  : () async {
                      setState(() => _busy = true);
                      try {
                        await ref.read(cartControllerProvider.notifier).removeCoupon();
                      } catch (e) {
                        if (context.mounted) showErrorSnack(context, e);
                      } finally {
                        if (mounted) setState(() => _busy = false);
                      }
                    },
              child: Text(l10n.removeCoupon),
            ),
          ],
        ),
      );
    }
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: AppTextField(
            controller: _controller,
            label: l10n.couponCode,
            prefixIcon: Icons.local_offer_outlined,
            forceLtr: true,
            textCapitalization: TextCapitalization.characters,
            errorText: _error,
            onSubmitted: (_) => _apply(),
          ),
        ),
        const Gap(AppSpace.sm),
        SizedBox(
          height: 56,
          child: AppButton.secondary(label: l10n.applyCoupon, loading: _busy, expand: false, onPressed: _apply),
        ),
      ],
    );
  }
}

class _CheckoutBar extends ConsumerStatefulWidget {
  const _CheckoutBar({required this.cart});
  final Cart cart;

  @override
  ConsumerState<_CheckoutBar> createState() => _CheckoutBarState();
}

class _CheckoutBarState extends ConsumerState<_CheckoutBar> {
  bool _busy = false;

  Future<void> _go() async {
    setState(() => _busy = true);
    try {
      // Re-validate prices & stock before checkout.
      final fresh = await ref.read(cartControllerProvider.notifier).revalidate();
      if (!mounted) return;
      if (fresh.canCheckout) {
        unawaited(context.push('/checkout'));
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
    return Container(
      decoration: BoxDecoration(
        color: context.colors.surface,
        border: Border(top: BorderSide(color: context.palette.line)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.sm),
          child: ContentWidth(
            child: Row(
              children: [
                Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(l10n.subtotal, style: context.text.bodySmall),
                    Text(widget.cart.subtotal.formatted, style: context.text.titleLarge),
                  ],
                ),
                const Gap(AppSpace.md),
                Expanded(
                  child: AppButton(
                    label: l10n.proceedToCheckout,
                    loading: _busy,
                    onPressed: widget.cart.canCheckout ? _go : null,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
