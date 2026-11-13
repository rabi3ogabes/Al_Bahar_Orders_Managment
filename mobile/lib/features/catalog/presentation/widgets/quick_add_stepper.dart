import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/require_auth.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Add-to-bag control on a product card: a round **+**, which becomes a **− 2 +** pill once the product is in the bag.
/// Products that need a choice (colour, size) open their page instead. Out-of-stock products show nothing.
class QuickAddStepper extends ConsumerStatefulWidget {
  const QuickAddStepper({super.key, required this.product});
  final ProductSummary product;

  @override
  ConsumerState<QuickAddStepper> createState() => _QuickAddStepperState();
}

class _QuickAddStepperState extends ConsumerState<QuickAddStepper> {
  bool _busy = false;
  static const _max = 99;

  CartItem? _line(Cart? cart) {
    final id = widget.product.variantId;
    if (cart == null || id == null) return null;
    for (final i in cart.items) {
      if (i.variantId == id) return i;
    }
    return null;
  }

  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await action();
      Haptics.tap();
    } on ApiException catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _add() {
    final p = widget.product;
    if (p.variantId == null || p.hasVariants) {
      openProduct(context, p);
      return;
    }
    if (!requireAuth(context, ref)) return;
    _run(() => ref.read(cartControllerProvider.notifier).addItem(p.variantId!));
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.product;
    if (!p.inStock || p.price == null) return const SizedBox.shrink();
    final l10n = context.l10n;
    final line = _line(ref.watch(cartControllerProvider).value);
    final qty = line?.quantity ?? 0;
    final limit = line?.availableQuantity == null ? _max : line!.availableQuantity!.clamp(1, _max);
    final primary = Theme.of(context).colorScheme.primary;

    Widget round(IconData icon, String label, VoidCallback? onTap) => Semantics(
      button: true,
      label: label,
      enabled: onTap != null,
      child: InkResponse(
        onTap: onTap,
        radius: 22,
        child: SizedBox(
          width: kMinTouchTarget,
          height: kMinTouchTarget,
          child: Center(
            child: _busy
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : Icon(icon, size: 20, color: Colors.white.withValues(alpha: onTap == null ? 0.45 : 1)),
          ),
        ),
      ),
    );

    return AnimatedSize(
      duration: AppDurations.medium,
      curve: Curves.easeOutCubic,
      alignment: AlignmentDirectional.centerEnd,
      child: Material(
        color: primary,
        elevation: 3,
        shadowColor: primary.withValues(alpha: 0.35),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        child: qty == 0
            ? round(Icons.add_rounded, p.hasVariants || p.variantId == null ? l10n.chooseOptions : l10n.addToCart, _add)
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  round(
                    qty == 1 ? Icons.delete_outline_rounded : Icons.remove_rounded,
                    l10n.decreaseQuantity,
                    () => _run(() => ref.read(cartControllerProvider.notifier).updateQuantity(line!.id, qty - 1)),
                  ),
                  Semantics(
                    liveRegion: true,
                    label: '$qty',
                    child: SizedBox(
                      width: 22,
                      child: Text(
                        '$qty',
                        textAlign: TextAlign.center,
                        style: context.text.labelLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w700),
                      ),
                    ),
                  ),
                  round(
                    Icons.add_rounded,
                    l10n.increaseQuantity,
                    qty >= limit
                        ? null
                        : () => _run(() => ref.read(cartControllerProvider.notifier).updateQuantity(line!.id, qty + 1)),
                  ),
                ],
              ),
      ),
    );
  }
}
