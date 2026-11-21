import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/features/alerts/domain/alert_models.dart';
import 'package:albahar_customer/features/alerts/presentation/alert_actions.dart';
import 'package:albahar_customer/features/alerts/presentation/state/alerts_controller.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The alert controls of a product page: the bell for a product that cannot be bought ("Notify me when available", then the subscribed state, tap to
/// cancel) and, while it can be bought and the owner has price alerts on, "Tell me if the price drops". Signed out: the sign-in screen opens and the
/// choice is made for the visitor as soon as they are back (the intent is kept). Rules: [productAlertControls].
class StockAlertControls extends ConsumerStatefulWidget {
  const StockAlertControls({super.key, required this.variantId, required this.canBuy, this.showBell = true});

  final String? variantId;
  final bool canBuy;

  /// False where the page already has its own "Notify me" button (the buy bar of the product page): only the price choice is drawn.
  final bool showBell;

  @override
  ConsumerState<StockAlertControls> createState() => _StockAlertControlsState();
}

class _StockAlertControlsState extends ConsumerState<StockAlertControls> {
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    // A visitor who signed in from a quick view and then opened this product: the choice they made there is done now.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted && ref.read(isAuthenticatedProvider)) {
        unawaited(resumeAlertIntent(context, ref, variantId: widget.variantId));
      }
    });
  }

  Future<void> _press(AlertType type) async {
    final variantId = widget.variantId;
    if (_busy || variantId == null) return;
    setState(() => _busy = true);
    try {
      await toggleAlert(context, ref, variantId: variantId, type: type);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen<bool>(isAuthenticatedProvider, (previous, signedIn) {
      if (signedIn && previous != true) unawaited(resumeAlertIntent(context, ref, variantId: widget.variantId));
    });
    final config = ref.watch(alertsConfigProvider).value ?? AlertsConfig.fallback;
    final alerts = ref.watch(alertsControllerProvider).value ?? const <StockAlert>[];
    final controls = productAlertControls(
      canBuy: widget.canBuy,
      config: config,
      alerts: alerts,
      variantId: widget.variantId,
    );
    final bell = widget.showBell ? controls.bell : BellState.hidden;
    if (bell == BellState.hidden && controls.price == PriceAlertState.hidden) return const SizedBox.shrink();
    final l10n = context.l10n;
    final user = ref.watch(sessionControllerProvider).user;
    return Padding(
      padding: const EdgeInsets.only(top: AppSpace.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (bell != BellState.hidden)
            AlertToggleTile(
              icon: bell == BellState.subscribed
                  ? Icons.notifications_active_rounded
                  : Icons.notifications_none_rounded,
              label: bell == BellState.subscribed ? l10n.alertNotifyOn : l10n.notifyBackInStock,
              on: bell == BellState.subscribed,
              busy: _busy,
              onPressed: () => unawaited(_press(AlertType.backInStock)),
            ),
          if (bell != BellState.hidden && controls.price != PriceAlertState.hidden) const Gap(AppSpace.xs),
          if (controls.price != PriceAlertState.hidden) ...[
            AlertToggleTile(
              icon: Icons.trending_down_rounded,
              label: controls.price == PriceAlertState.subscribed ? l10n.alertPriceOn : l10n.alertPriceToggle,
              on: controls.price == PriceAlertState.subscribed,
              busy: _busy,
              onPressed: () => unawaited(_press(AlertType.priceDrop)),
            ),
            // Price alerts arrive as offers and news: a customer who has not agreed to those is told what to switch on, not left waiting.
            if (controls.price == PriceAlertState.subscribed && user != null && !user.marketingConsent)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.xs),
                child: Text(
                  l10n.alertPriceNeedsConsent,
                  style: context.text.bodySmall?.copyWith(color: context.palette.muted),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

/// One full-width, 48 dp choice: an outlined row that fills with the brand tint when it is on.
class AlertToggleTile extends StatelessWidget {
  const AlertToggleTile({
    super.key,
    required this.icon,
    required this.label,
    required this.on,
    required this.onPressed,
    this.busy = false,
  });

  final IconData icon;
  final String label;
  final bool on;
  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    return Semantics(
      button: true,
      toggled: on,
      label: label,
      excludeSemantics: true,
      child: Material(
        color: on ? palette.brassTint : Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadius.cardAll,
          side: BorderSide(color: on ? palette.brassDeep : palette.line),
        ),
        child: InkWell(
          borderRadius: AppRadius.cardAll,
          onTap: busy ? null : onPressed,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: kMinTouchTarget),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.md, vertical: AppSpace.sm),
              child: Row(
                children: [
                  Icon(icon, size: 20, color: palette.brassDeep),
                  const Gap(AppSpace.sm),
                  Expanded(
                    child: Text(label, style: context.text.labelLarge?.copyWith(color: palette.brassDeep)),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
