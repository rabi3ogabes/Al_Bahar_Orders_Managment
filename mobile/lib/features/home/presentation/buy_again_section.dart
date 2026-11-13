import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/cart/presentation/cart_panel.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "Buy again": the customer's own past products in a horizontal shelf, each re-added to the bag with one tap.
/// Hidden for guests and for customers with no past orders.
class BuyAgainSection extends ConsumerWidget {
  const BuyAgainSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(buyAgainProvider).value ?? const <BuyAgainItem>[];
    if (items.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title: context.l10n.buyAgainTitle),
        SizedBox(
          height: 218,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            clipBehavior: Clip.none,
            padding: const EdgeInsetsDirectional.fromSTEB(AppSpace.gutter, 4, AppSpace.gutter, 20),
            itemCount: items.length,
            separatorBuilder: (_, _) => const Gap(AppSpace.sm),
            itemBuilder: (_, i) => _BuyAgainCard(item: items[i]),
          ),
        ),
      ],
    );
  }
}

class _BuyAgainCard extends ConsumerStatefulWidget {
  const _BuyAgainCard({required this.item});
  final BuyAgainItem item;

  @override
  ConsumerState<_BuyAgainCard> createState() => _BuyAgainCardState();
}

class _BuyAgainCardState extends ConsumerState<_BuyAgainCard> {
  /// The cart opens over Home (no navigation); the badge changes at once.
  void _add() {
    final item = widget.item;
    unawaited(
      addToCartAndShow(context, ref, [
        CartAddition(
          variantId: item.variantId,
          product: item.product,
          quantity: item.lastQuantity,
          unitPrice: item.product.price,
        ),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.item.product;
    final l10n = context.l10n;
    return SizedBox(
      width: 148,
      child: SurfaceCard(
        padding: const EdgeInsets.all(8),
        onTap: () => openProduct(context, p, heroScope: 'buy-again'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              height: 84,
              width: double.infinity,
              child: AppNetworkImage(
                url: p.image?.thumbUrl ?? p.image?.url,
                borderRadius: AppRadius.imageAll,
                fit: BoxFit.contain,
              ),
            ),
            const Gap(AppSpace.xs),
            Text(p.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: context.text.titleSmall),
            const Spacer(),
            Row(
              children: [
                Expanded(
                  child: Text(
                    p.price?.finalPrice.formatted ?? '',
                    style: context.text.titleSmall?.copyWith(
                      color: context.colors.primary,
                      fontWeight: FontWeight.w700,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Semantics(
                  button: true,
                  label: '${l10n.buyAgainAdd} ${widget.item.lastQuantity} · ${p.name}',
                  child: Material(
                    color: context.colors.primary,
                    shape: const CircleBorder(),
                    child: InkWell(
                      customBorder: const CircleBorder(),
                      onTap: p.inStock ? _add : null,
                      child: SizedBox(
                        width: kMinTouchTarget,
                        height: kMinTouchTarget,
                        child: Icon(Icons.add_rounded, size: 20, color: context.colors.onPrimary),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
