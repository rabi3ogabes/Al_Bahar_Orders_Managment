import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Home widget: the order that is on its way, updating live. Real-time pings (`order.updated`) refresh it at once; a
/// 30 s refresh is the safety net when the connection drops. Hidden when there is no order in progress.
class ActiveOrderCard extends ConsumerStatefulWidget {
  const ActiveOrderCard({super.key});

  @override
  ConsumerState<ActiveOrderCard> createState() => _ActiveOrderCardState();
}

class _ActiveOrderCardState extends ConsumerState<ActiveOrderCard> {
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _poll = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) ref.invalidate(activeOrdersProvider);
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Instant refresh when the server says an order (or its delivery) moved.
    ref.listen(userAuctionEventsProvider, (_, next) {
      final e = next.value;
      if (e != null && e.event == 'order.updated') {
        ref
          ..invalidate(activeOrdersProvider)
          ..invalidate(orderDetailProvider(e.data['order_id']?.toString() ?? ''));
      }
    });
    final orders = ref.watch(activeOrdersProvider).value ?? const <OrderSummary>[];
    if (orders.isEmpty) return const SizedBox.shrink();
    final first = orders.first;
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.xs, AppSpace.gutter, AppSpace.sm),
      child: AnimatedSwitcher(
        duration: AppDurations.medium,
        child: _Card(key: ValueKey('${first.id}-${first.status}'), order: first, more: orders.length - 1),
      ),
    );
  }
}

/// 0..3 position on the Placed → Preparing → On the way / Ready → Done track.
int orderStep(OrderSummary o) => switch (o.status) {
  'pending_payment' || 'confirmed' => 0,
  'processing' || 'partially_fulfilled' => 1,
  'out_for_delivery' || 'ready_for_pickup' => 2,
  _ => 3,
};

class _Card extends StatelessWidget {
  const _Card({super.key, required this.order, required this.more});
  final OrderSummary order;
  final int more;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final primary = context.colors.primary;
    final pickup = order.fulfillmentType == 'pickup';
    final step = orderStep(order);
    final labels = [
      l10n.orderStepPlaced,
      l10n.orderStepPreparing,
      pickup ? l10n.orderStepReady : l10n.orderStepOnTheWay,
      l10n.orderStepDone,
    ];
    final headline = switch (order.status) {
      'pending_payment' => l10n.orderLivePendingPayment,
      'confirmed' => l10n.orderLiveConfirmed,
      'processing' || 'partially_fulfilled' => l10n.orderLivePreparing,
      'out_for_delivery' => l10n.orderLiveOnTheWay,
      'ready_for_pickup' => l10n.orderLiveReady,
      _ => order.statusLabel ?? order.status,
    };
    return Semantics(
      button: true,
      label: '${l10n.orderNumberLabel(order.number)}: $headline',
      child: SurfaceCard(
        padding: const EdgeInsets.all(AppSpace.md),
        onTap: () => context.push('/orders/${order.id}'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                _LiveDot(color: primary),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Text(
                    l10n.orderNumberLabel(order.number),
                    style: context.text.labelLarge?.copyWith(color: context.palette.muted),
                  ),
                ),
                if (more > 0)
                  Text(l10n.orderMoreCount(more), style: context.text.labelMedium?.copyWith(color: primary)),
                Icon(Icons.chevron_right_rounded, color: context.palette.muted),
              ],
            ),
            const Gap(AppSpace.xs),
            Text(headline, style: context.text.titleLarge),
            const Gap(AppSpace.md),
            Row(
              children: [
                for (var i = 0; i < 4; i++) ...[
                  _StepDot(index: i, current: step, color: primary),
                  if (i < 3)
                    Expanded(
                      child: AnimatedContainer(
                        duration: AppDurations.medium,
                        height: 3,
                        margin: const EdgeInsets.symmetric(horizontal: 4),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(2),
                          color: i < step ? primary : context.palette.line,
                        ),
                      ),
                    ),
                ],
              ],
            ),
            const Gap(AppSpace.xs),
            Row(
              children: [
                for (var i = 0; i < 4; i++)
                  Expanded(
                    child: Text(
                      labels[i],
                      textAlign: i == 0 ? TextAlign.start : (i == 3 ? TextAlign.end : TextAlign.center),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: context.text.labelSmall?.copyWith(
                        color: i <= step ? primary : context.palette.muted,
                        fontWeight: i == step ? FontWeight.w700 : FontWeight.w500,
                      ),
                    ),
                  ),
              ],
            ),
            if (order.status == 'out_for_delivery') ...[
              const Gap(AppSpace.sm),
              SizedBox(
                width: double.infinity,
                height: kMinTouchTarget,
                child: FilledButton.icon(
                  onPressed: () => context.push('/orders/${order.id}/track'),
                  icon: const Icon(Icons.location_on_rounded),
                  label: Text(l10n.trackOrder),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _StepDot extends StatelessWidget {
  const _StepDot({required this.index, required this.current, required this.color});
  final int index;
  final int current;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final done = index < current;
    final active = index == current;
    return AnimatedContainer(
      duration: AppDurations.medium,
      width: active ? 22 : 18,
      height: active ? 22 : 18,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: done || active ? color : Colors.transparent,
        border: done || active ? null : Border.all(color: context.palette.line, width: 2),
        boxShadow: active ? [BoxShadow(color: color.withValues(alpha: 0.2), spreadRadius: 4)] : null,
      ),
      child: done ? const Icon(Icons.check_rounded, size: 12, color: Colors.white) : null,
    );
  }
}

/// Soft pulsing dot: "this is live". Stays still when the system asks for reduced motion.
class _LiveDot extends StatefulWidget {
  const _LiveDot({required this.color});
  final Color color;

  @override
  State<_LiveDot> createState() => _LiveDotState();
}

class _LiveDotState extends State<_LiveDot> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context)) {
      _c.stop();
    } else if (!_c.isAnimating) {
      unawaited(_c.repeat(reverse: true));
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: _c,
    builder: (_, _) => Container(
      width: 10,
      height: 10,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: widget.color,
        boxShadow: [
          BoxShadow(
            color: widget.color.withValues(alpha: 0.35 * (1 - _c.value)),
            spreadRadius: 6 * _c.value,
          ),
        ],
      ),
    ),
  );
}
