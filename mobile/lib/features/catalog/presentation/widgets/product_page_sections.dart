import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/html_content.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/branches_screen.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Where the product can be bought: stock at the shopper's branch, delivery and pickup, and the extra delivery fee note.
class ProductAvailabilityCard extends ConsumerWidget {
  const ProductAvailabilityCard({super.key, required this.product, required this.variant, required this.branchName});
  final ProductDetail product;
  final ProductVariant? variant;
  final String? branchName;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final palette = context.palette;
    final reason = variant == null
        ? 'out_of_stock'
        : !variant!.availableInBranch
        ? 'not_in_branch'
        : !variant!.inStock
        ? 'out_of_stock'
        : product.availability.reason;
    final ok = reason == null;
    final name = branchName ?? l10n.selectedBranch;
    final (icon, color, text) = switch (reason) {
      null => (Icons.check_circle_outline_rounded, palette.success, l10n.availableAt(name)),
      'not_in_branch' => (Icons.do_not_disturb_on_outlined, palette.muted, l10n.notInBranch(name)),
      _ => (Icons.inventory_2_outlined, palette.warning, l10n.outOfStockAt(name)),
    };
    final delivers = product.deliveryAvailable != false;

    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, color: color),
              const Gap(AppSpace.sm),
              Expanded(child: Text(text, style: context.text.titleSmall)),
            ],
          ),
          const Gap(AppSpace.sm),
          Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              if (delivers)
                StatusChip(
                  label: l10n.deliveryAvailable,
                  icon: Icons.local_shipping_outlined,
                  tone: ok ? StatusTone.success : StatusTone.neutral,
                  dense: true,
                ),
              if (product.pickupAvailable != false)
                StatusChip(
                  label: l10n.pickupAvailable,
                  icon: Icons.storefront_outlined,
                  tone: ok ? StatusTone.success : StatusTone.neutral,
                  dense: true,
                ),
              if (product.isOversized)
                StatusChip(label: l10n.oversizedItem, icon: Icons.open_in_full_rounded, dense: true),
              if (product.requiresAssembly)
                StatusChip(label: l10n.assemblyRequired, icon: Icons.handyman_outlined, dense: true),
              if (product.assemblyServiceAvailable)
                StatusChip(
                  label: l10n.assemblyAvailable,
                  icon: Icons.build_circle_outlined,
                  tone: StatusTone.info,
                  dense: true,
                ),
            ],
          ),
          if (delivers && product.hasExtraDeliveryFee) ...[
            const Gap(AppSpace.sm),
            ExtraDeliveryNote(fee: product.extraDeliveryFee!, mode: product.extraDeliveryFeeMode),
          ],
          const Gap(AppSpace.xs),
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              TextButton.icon(
                onPressed: () => showSidePanel<void>(
                  context: context,
                  title: l10n.branchAvailabilityTitle,
                  builder: (_) => _BranchStockSheet(productId: product.id, variantId: variant?.id, host: context),
                ),
                icon: const Icon(Icons.store_mall_directory_outlined, size: 18),
                label: Text(l10n.checkOtherBranches),
              ),
              TextButton(onPressed: () => unawaited(showBranchPicker(context)), child: Text(l10n.change)),
            ],
          ),
        ],
      ),
    );
  }
}

/// A calm line under the delivery chips: this bulky product adds an extra delivery fee, per item or once.
class ExtraDeliveryNote extends StatelessWidget {
  const ExtraDeliveryNote({super.key, required this.fee, required this.mode});
  final Money fee;

  /// [extraDeliveryPerUnit] or [extraDeliveryPerLine].
  final String mode;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = mode == extraDeliveryPerLine
        ? l10n.extraDeliveryFeePerLine(fee.formatted)
        : l10n.extraDeliveryFeePerUnit(fee.formatted);
    return DecoratedBox(
      key: const ValueKey('extra-delivery-note'),
      decoration: BoxDecoration(color: context.palette.tint, borderRadius: BorderRadius.circular(AppRadius.sm)),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 1),
              child: Icon(Icons.local_shipping_outlined, size: 16, color: context.palette.brassDeep),
            ),
            const Gap(AppSpace.xs),
            Expanded(child: Text(text, style: context.text.bodySmall)),
          ],
        ),
      ),
    );
  }
}

class _BranchStockSheet extends ConsumerWidget {
  const _BranchStockSheet({required this.productId, required this.variantId, required this.host});
  final String productId;
  final String? variantId;

  /// The page behind the window: the confirmation is shown there after the window closes.
  final BuildContext host;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final async = ref.watch(productAvailabilityProvider(productId));
    final preferred = ref.watch(preferredBranchIdProvider);
    return async.when(
      loading: () => const SizedBox(height: 240, child: ListSkeleton(count: 3, leading: false)),
      error: (e, _) =>
          ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(productAvailabilityProvider(productId))),
      data: (branches) => Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final b in branches)
            Builder(
              builder: (context) {
                final stock = b.forVariant(variantId);
                final available = stock?.inStock ?? false;
                return ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(
                    b.branch.id == preferred ? Icons.radio_button_checked_rounded : Icons.storefront_outlined,
                    color: b.branch.id == preferred ? context.palette.brass : null,
                  ),
                  title: Text(b.branch.name),
                  subtitle: Text(available ? l10n.availableQty(stock!.available) : l10n.outOfStock),
                  trailing: stock?.price != null
                      ? PriceTag(price: stock!.price!, size: PriceTagSize.small, showBadge: false)
                      : null,
                  onTap: () async {
                    await ref
                        .read(preferredBranchProvider.notifier)
                        .select(BranchRef(id: b.branch.id, name: b.branch.name));
                    if (context.mounted) {
                      Navigator.of(context).pop();
                      if (host.mounted) showInfoSnack(host, l10n.branchSelectedToast(b.branch.name));
                    }
                  },
                );
              },
            ),
          const Gap(AppSpace.xs),
          Text(l10n.branchChangeNote, style: context.text.bodySmall),
        ],
      ),
    );
  }
}

/// Description, specifications, dimensions and warranty, each in a section that opens and closes.
class ProductDetailsSection extends StatelessWidget {
  const ProductDetailsSection({super.key, required this.product});
  final ProductDetail product;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    String num(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(1);
    final d = product.dimensions;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (product.descriptionHtml != null && product.descriptionHtml!.trim().isNotEmpty)
          _Expandable(title: l10n.description, initiallyExpanded: true, child: HtmlContent(product.descriptionHtml!)),
        if (product.specifications.isNotEmpty)
          _Expandable(
            title: l10n.specifications,
            child: Column(
              children: [for (final s in product.specifications) SummaryRow(label: s.label, value: s.value)],
            ),
          ),
        if (!d.isEmpty || product.weight != null)
          _Expandable(
            title: l10n.dimensions,
            child: Column(
              children: [
                if (d.length != null) SummaryRow(label: l10n.dimensionLength, value: '${num(d.length!)} ${d.unit}'),
                if (d.width != null) SummaryRow(label: l10n.dimensionWidth, value: '${num(d.width!)} ${d.unit}'),
                if (d.height != null) SummaryRow(label: l10n.dimensionHeight, value: '${num(d.height!)} ${d.unit}'),
                if (product.weight != null)
                  SummaryRow(label: l10n.weight, value: '${num(product.weight!.value)} ${product.weight!.unit}'),
              ],
            ),
          ),
        if (product.warranty != null)
          _Expandable(
            title: l10n.warranty,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.warrantyMonths(product.warranty!.durationMonths), style: context.text.titleSmall),
                if (product.warranty!.provider != null)
                  Text(l10n.warrantyBy(product.warranty!.provider!), style: context.text.bodySmall),
                if (product.warranty!.terms != null) ...[
                  const Gap(AppSpace.xs),
                  Text(product.warranty!.terms!, style: context.text.bodyMedium),
                ],
              ],
            ),
          ),
      ],
    );
  }
}

class _Expandable extends StatelessWidget {
  const _Expandable({required this.title, required this.child, this.initiallyExpanded = false});
  final String title;
  final Widget child;
  final bool initiallyExpanded;

  @override
  Widget build(BuildContext context) => Theme(
    data: context.theme.copyWith(dividerColor: Colors.transparent),
    child: Column(
      children: [
        Divider(color: context.palette.line),
        ExpansionTile(
          initiallyExpanded: initiallyExpanded,
          tilePadding: EdgeInsets.zero,
          childrenPadding: const EdgeInsets.only(bottom: AppSpace.md),
          expandedCrossAxisAlignment: CrossAxisAlignment.start,
          title: Text(title, style: context.text.titleMedium),
          children: [child],
        ),
      ],
    ),
  );
}

/// The bar at the bottom of the page: quantity and add to cart, "add N items" for a product sold in several colours, or
/// "notify me" when it cannot be bought.
class ProductBuyBar extends StatelessWidget {
  const ProductBuyBar({
    super.key,
    required this.purchasable,
    required this.quantity,
    required this.maxQuantity,
    required this.onQuantity,
    required this.onAdd,
    required this.onNotify,
    this.notifying = false,
    this.multiCount,
    this.onAddMany,
  });

  /// Colour mode: how many items are picked and the add-all action.
  final int? multiCount;
  final VoidCallback? onAddMany;

  final bool purchasable;
  final int quantity;
  final int maxQuantity;
  final ValueChanged<int> onQuantity;
  final VoidCallback? onAdd;
  final VoidCallback? onNotify;

  /// The customer already asked to be told when this is back: the button says so, and a tap cancels it.
  final bool notifying;

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
            maxWidth: 760,
            child: LayoutBuilder(
              builder: (context, constraints) {
                // On the narrowest phones the button gives up its icon, so the label keeps room next to the stepper.
                final icon = constraints.maxWidth < 300 ? null : Icons.shopping_bag_outlined;
                return multiCount != null && purchasable
                    ? AppButton(
                        label: multiCount! > 0 ? l10n.addItemsToBag(multiCount!) : l10n.addToCart,
                        icon: icon,
                        onPressed: multiCount! > 0 ? onAddMany : null,
                      )
                    : purchasable
                    ? Row(
                        children: [
                          QuantityStepper(value: quantity, max: maxQuantity, onChanged: onQuantity, compact: true),
                          const Gap(AppSpace.sm),
                          Expanded(
                            child: AppButton(label: l10n.addToCart, icon: icon, onPressed: onAdd),
                          ),
                        ],
                      )
                    : AppButton.secondary(
                        label: notifying ? l10n.alertNotifyOn : l10n.notifyBackInStock,
                        icon: constraints.maxWidth < 300
                            ? null
                            : notifying
                            ? Icons.notifications_active_rounded
                            : Icons.notifications_active_outlined,
                        onPressed: onNotify,
                      );
              },
            ),
          ),
        ),
      ),
    );
  }
}
