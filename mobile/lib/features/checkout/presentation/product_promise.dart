import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/checkout/presentation/promise_note.dart';
import 'package:albahar_customer/features/checkout/presentation/state/promise_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "When will I get it?" on the product page: the delivery date and the pickup time of the chosen variant and quantity (assembly adds its days),
/// from the customer's showroom. Nothing while there is no answer; never blocks buying.
class ProductPromise extends ConsumerWidget {
  const ProductPromise({
    super.key,
    required this.productId,
    required this.variantId,
    required this.quantity,
    required this.assembly,
  });

  final String productId;
  final String? variantId;
  final int quantity;
  final bool assembly;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final delivery = ref.watch(
      productPromiseProvider((
        productId: productId,
        variantId: variantId,
        quantity: quantity,
        assembly: assembly,
        type: 'delivery',
      )),
    );
    final pickup = ref.watch(
      productPromiseProvider((
        productId: productId,
        variantId: variantId,
        quantity: quantity,
        assembly: assembly,
        type: 'pickup',
      )),
    );
    final noAnswer = delivery.value == null && pickup.value == null;
    if (variantId == null || (noAnswer && !delivery.isLoading)) return const SizedBox.shrink();
    return Column(
      key: const ValueKey('product-promise'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PromiseNote(promise: delivery.value, loading: delivery.isLoading && delivery.value == null, compact: true),
        if (pickup.value != null && pickup.value!.visible) ...[
          const Gap(AppSpace.xs),
          PromiseNote(promise: pickup.value, compact: true),
        ],
        const Gap(AppSpace.md),
      ],
    );
  }
}
