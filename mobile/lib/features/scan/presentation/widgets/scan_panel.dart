import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/scan/presentation/state/scan_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What the buttons of the panel do (the screen owns navigation and windows).
class ScanPanelActions {
  const ScanPanelActions({
    required this.keepScanning,
    required this.viewCart,
    required this.enterCode,
    required this.searchByName,
    required this.chooseOption,
    required this.signIn,
    required this.retry,
    required this.undo,
  });

  final VoidCallback keepScanning;
  final VoidCallback viewCart;
  final VoidCallback enterCode;
  final VoidCallback searchByName;
  final VoidCallback chooseOption;
  final VoidCallback signIn;
  final VoidCallback retry;
  final VoidCallback undo;
}

/// The sheet that slides up from the bottom of the scanner: the result of the last scan (added / a plain-words problem), the
/// running "N items · subtotal" bar and the buttons. It never covers the scan window and scrolls if the text is very large.
class ScanPanel extends ConsumerWidget {
  const ScanPanel({super.key, required this.actions});

  final ScanPanelActions actions;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(scanControllerProvider);
    final signedIn = ref.watch(isAuthenticatedProvider);
    final cart = signedIn ? ref.watch(cartControllerProvider).value : null;
    final hasCart = cart != null && cart.itemsCount > 0;
    final reduce = MediaQuery.disableAnimationsOf(context);
    final screen = MediaQuery.sizeOf(context);
    final entry = state.entry;
    final problem = state.problem;
    final hasCard = entry != null || problem != null;

    final Widget card = entry != null
        ? ScanResultCard(key: ValueKey('entry-${entry.id}'), entry: entry, onUndo: actions.undo)
        : problem != null
        ? ScanProblemCard(key: ValueKey('problem-${problem.kind.name}-${problem.code}'), problem: problem)
        : _Hint(key: const ValueKey('hint'), looking: state.looking);

    final buttons = _buttons(context, state: state, hasCart: hasCart);
    final bigText = MediaQuery.textScalerOf(context).scale(16) > 19;

    return Material(
      color: context.colors.surface,
      elevation: 12,
      shadowColor: Colors.black,
      borderRadius: const BorderRadius.vertical(top: Radius.circular(AppRadius.sheet)),
      clipBehavior: Clip.antiAlias,
      child: SafeArea(
        top: false,
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: screen.height * 0.62),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(AppSpace.md, AppSpace.sm, AppSpace.md, AppSpace.sm),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 36,
                    height: 4,
                    margin: const EdgeInsets.only(bottom: AppSpace.sm),
                    decoration: BoxDecoration(color: context.palette.line, borderRadius: BorderRadius.circular(2)),
                  ),
                ),
                // Reduced motion: the card is simply there (no slide, no size animation).
                if (reduce)
                  card
                else
                  AnimatedSize(
                    duration: AppDurations.fast,
                    alignment: Alignment.topCenter,
                    child: AnimatedSwitcher(
                      duration: AppDurations.medium,
                      switchInCurve: Curves.easeOutCubic,
                      transitionBuilder: (child, animation) => FadeTransition(
                        opacity: animation,
                        child: SlideTransition(
                          position: Tween(begin: const Offset(0, 0.25), end: Offset.zero).animate(animation),
                          child: child,
                        ),
                      ),
                      child: card,
                    ),
                  ),
                if (hasCart) ...[const Gap(AppSpace.xs), _CartBar(cart: cart)],
                if (buttons.isNotEmpty) ...[
                  const Gap(AppSpace.sm),
                  if (bigText)
                    for (final b in buttons) ...[b, const Gap(AppSpace.xs)]
                  else
                    Row(
                      children: [
                        for (var i = 0; i < buttons.length; i++) ...[
                          if (i > 0) const Gap(AppSpace.xs),
                          Expanded(child: buttons[i]),
                        ],
                      ],
                    ),
                ],
                if (!hasCard) _ManualEntryButton(onPressed: actions.enterCode) else const Gap(AppSpace.xxs),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// One primary action first, then the quiet one. "Keep scanning" is the default after anything that has a card.
  List<Widget> _buttons(BuildContext context, {required ScanState state, required bool hasCart}) {
    final l10n = context.l10n;
    final problem = state.problem;
    Widget primary(String label, VoidCallback onTap, {IconData? icon}) =>
        AppButton(label: label, icon: icon, onPressed: onTap);
    Widget quiet(String label, VoidCallback onTap, {IconData? icon}) =>
        AppButton.secondary(label: label, icon: icon, onPressed: onTap);
    final keep = primary(l10n.scanKeepScanning, actions.keepScanning);
    final cart = quiet(l10n.viewCart, actions.viewCart, icon: Icons.shopping_bag_outlined);

    if (state.entry != null) return [keep, cart];
    if (problem == null) return hasCart ? [cart] : const [];
    return switch (problem.kind) {
      ScanProblemKind.notFound => [
        primary(l10n.scanSearchByName, actions.searchByName, icon: Icons.search_rounded),
        quiet(l10n.scanKeepScanning, actions.keepScanning),
      ],
      ScanProblemKind.offline => [
        primary(l10n.retry, actions.retry),
        quiet(l10n.scanKeepScanning, actions.keepScanning),
      ],
      ScanProblemKind.needsChoice => [
        primary(l10n.scanChoiceAction, actions.chooseOption),
        quiet(l10n.scanKeepScanning, actions.keepScanning),
      ],
      ScanProblemKind.signInRequired => [
        primary(l10n.signIn, actions.signIn),
        quiet(l10n.scanKeepScanning, actions.keepScanning),
      ],
      ScanProblemKind.outOfStock ||
      ScanProblemKind.unavailable ||
      ScanProblemKind.limitReached ||
      ScanProblemKind.failed => [keep, if (hasCart) cart],
    };
  }
}

class _Hint extends StatelessWidget {
  const _Hint({super.key, required this.looking});
  final bool looking;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Semantics(
      liveRegion: true,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (looking)
              const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
            else
              Icon(Icons.qr_code_scanner_rounded, size: 20, color: context.palette.muted),
            const Gap(AppSpace.xs),
            Flexible(
              child: Text(
                looking ? l10n.scanLooking : l10n.scanHint,
                textAlign: TextAlign.center,
                style: context.text.bodyMedium?.copyWith(color: context.palette.muted),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ManualEntryButton extends StatelessWidget {
  const _ManualEntryButton({required this.onPressed});
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Align(
    child: AppButton.ghost(label: context.l10n.scanEnterCode, icon: Icons.keyboard_alt_outlined, onPressed: onPressed),
  );
}

/// "N items · subtotal": the cart's own numbers (the subtotal is the server's, dimmed while a change is on its way).
class _CartBar extends ConsumerWidget {
  const _CartBar({required this.cart});
  final Cart cart;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final syncing = ref.watch(cartBusyItemsProvider).isNotEmpty;
    return Semantics(
      liveRegion: true,
      label: '${l10n.itemsCount(cart.itemsCount)}, ${l10n.subtotal} ${cart.subtotal.formatted}',
      child: ExcludeSemantics(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
          decoration: BoxDecoration(color: context.palette.tint, borderRadius: AppRadius.imageAll),
          child: Row(
            children: [
              Icon(Icons.shopping_bag_outlined, size: 20, color: context.colors.primary),
              const Gap(AppSpace.xs),
              Expanded(
                child: Text(
                  '${l10n.itemsCount(cart.itemsCount)} · ${l10n.subtotal}',
                  style: context.text.bodyMedium,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const Gap(AppSpace.xs),
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
                  style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The product just added: photo, name, price, − / + and Undo.
class ScanResultCard extends ConsumerStatefulWidget {
  const ScanResultCard({super.key, required this.entry, required this.onUndo});

  final ScanEntry entry;
  final VoidCallback onUndo;

  @override
  ConsumerState<ScanResultCard> createState() => _ScanResultCardState();
}

class _ScanResultCardState extends ConsumerState<ScanResultCard> {
  @override
  void initState() {
    super.initState();
    // Screen readers hear what was added, even though focus stays on the camera.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final view = View.of(context);
      final text = context.l10n.scanAddedAnnouncement(widget.entry.product.name);
      SemanticsService.sendAnnouncement(view, text, Directionality.of(context));
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final entry = widget.entry;
    final line = ref.watch(
      cartControllerProvider.select((c) => c.value?.items.where((i) => i.variantId == entry.variantId).firstOrNull),
    );
    final busyIds = ref.watch(cartBusyItemsProvider);
    final busy = line == null || busyIds.contains(line.id);
    final quantity = line?.quantity ?? entry.previousQuantity + 1;
    final max = entry.maxQuantity < quantity ? quantity : entry.maxQuantity;

    return SurfaceCard(
      padding: const EdgeInsets.all(AppSpace.sm),
      color: context.palette.brassTint,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.check_circle_rounded, color: context.palette.success),
              const Gap(AppSpace.xs),
              Expanded(child: Text(l10n.addedToCart, style: context.text.titleSmall)),
            ],
          ),
          const Gap(AppSpace.xs),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 64,
                height: 64,
                child: AppNetworkImage(
                  url: entry.product.image?.listUrl,
                  semanticLabel: entry.product.image?.alt ?? entry.product.name,
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
                      entry.product.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: context.text.titleSmall,
                    ),
                    if (entry.variantName != null)
                      Text(
                        entry.variantName!,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: context.text.bodySmall,
                      ),
                    const Gap(AppSpace.xxs),
                    if (entry.price != null) PriceTag(price: entry.price!, size: PriceTagSize.small, showBadge: false),
                  ],
                ),
              ),
            ],
          ),
          const Gap(AppSpace.xxs),
          Row(
            children: [
              Expanded(
                child: Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton.icon(
                    onPressed: busy ? null : widget.onUndo,
                    icon: const Icon(Icons.undo_rounded, size: 20),
                    label: Text(l10n.scanUndo),
                  ),
                ),
              ),
              QuantityStepper(
                value: quantity,
                max: max,
                busy: busy,
                compact: true,
                onChanged: line == null
                    ? null
                    : (q) async {
                        try {
                          await ref.read(cartControllerProvider.notifier).updateQuantity(line.id, q);
                        } on ApiException catch (e) {
                          if (context.mounted) showErrorSnack(context, e);
                        }
                      },
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// A scan that did not end in "added": what happened in plain words (the action is the panel's button).
class ScanProblemCard extends StatelessWidget {
  const ScanProblemCard({super.key, required this.problem});

  final ScanProblem problem;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final palette = context.palette;
    final name = problem.product?.name ?? '';
    final (IconData icon, Color tone, String title, String message) = switch (problem.kind) {
      ScanProblemKind.notFound => (
        Icons.search_off_rounded,
        palette.muted,
        l10n.scanNotFoundTitle,
        l10n.scanNotFoundMessage,
      ),
      ScanProblemKind.outOfStock => (
        Icons.inventory_2_outlined,
        palette.warning,
        l10n.scanOutOfStockTitle,
        l10n.scanOutOfStockMessage(name),
      ),
      ScanProblemKind.unavailable => (
        Icons.storefront_outlined,
        palette.warning,
        l10n.scanUnavailableTitle,
        l10n.scanUnavailableMessage(name),
      ),
      ScanProblemKind.limitReached => (
        Icons.shopping_bag_outlined,
        palette.warning,
        l10n.scanLimitTitle,
        l10n.scanLimitMessage(problem.max ?? 0),
      ),
      ScanProblemKind.offline => (
        Icons.wifi_off_rounded,
        palette.warning,
        l10n.scanOfflineTitle,
        l10n.scanOfflineMessage,
      ),
      ScanProblemKind.needsChoice => (
        Icons.tune_rounded,
        palette.info,
        l10n.scanChoiceTitle,
        l10n.scanChoiceMessage(name),
      ),
      ScanProblemKind.signInRequired => (
        Icons.lock_outline_rounded,
        palette.info,
        l10n.scanSignInTitle,
        l10n.scanSignInMessage(name),
      ),
      ScanProblemKind.failed => (
        Icons.error_outline_rounded,
        palette.danger,
        l10n.scanFailedTitle,
        describeError(l10n, problem.error),
      ),
    };
    return Semantics(
      liveRegion: true,
      container: true,
      label: '$title. $message',
      child: ExcludeSemantics(
        child: SurfaceCard(
          padding: const EdgeInsets.all(AppSpace.sm),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: tone.withValues(alpha: 0.12), shape: BoxShape.circle),
                child: Icon(icon, color: tone),
              ),
              const Gap(AppSpace.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: context.text.titleSmall),
                    const Gap(AppSpace.xxs),
                    Text(message, style: context.text.bodyMedium?.copyWith(color: palette.muted)),
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
