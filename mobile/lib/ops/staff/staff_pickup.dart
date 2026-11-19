import 'dart:async';

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:albahar_customer/ops/staff/staff_clock_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_errors.dart';
import 'package:albahar_customer/ops/staff/staff_manual_handover.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_rights.dart';
import 'package:albahar_customer/ops/staff/staff_scan.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What the buttons of one pickup order are right now: the step it can take, and what can be done at the counter once it is ready.
class PickupPlan {
  const PickupPlan({
    this.step,
    this.ready = false,
    this.scan = false,
    this.reissue = false,
    this.manual = false,
    this.blocked = false,
    this.expired = false,
  });

  /// `accepted` or `preparing` (needs orders.edit).
  final String? step;

  /// "Ready for pickup": issues the pass (needs pickup.edit).
  final bool ready;

  /// "Scan pass", "Re-issue pass" and "Hand over without the pass" (a manager) for an order that is ready for the customer.
  final bool scan;
  final bool reissue;
  final bool manual;

  /// A customer's request to cancel is waiting for a manager: the hand-over is shown switched off, with the reason.
  final bool blocked;

  /// The pass ran out: a new one is the first thing to offer.
  final bool expired;

  bool get any => step != null || ready || scan || reissue || manual;

  factory PickupPlan.of(QueueOrder o, StaffRights r, DateTime now) {
    final stepOf = switch (o.status) {
      'pending' => 'accepted',
      'accepted' => 'preparing',
      _ => null,
    };
    if (o.status == 'pickup_ready') {
      return PickupPlan(
        scan: r.canPickupView,
        reissue: r.canPickupEdit,
        manual: r.canPickupApprove,
        blocked: o.cancellationOpen,
        expired: o.pickup?.isExpired(now) ?? false,
      );
    }
    return PickupPlan(step: r.canEdit ? stepOf : null, ready: o.status == 'preparing' && r.canPickupEdit);
  }
}

/// The buttons of a pickup order, on its card and pinned in its window: accept, start preparing, make it ready (which issues the pass), and
/// once it is ready scan the customer's pass, give a new one, or (a manager) hand over without it. While the customer's request to cancel waits
/// for a manager the hand-over is switched off and says why.
///
/// One action at a time; the lists are read again whether it worked or not, so a colleague's earlier step shows instead of a stale button.
class PickupActionButtons extends ConsumerStatefulWidget {
  const PickupActionButtons({
    super.key,
    required this.order,
    required this.rights,
    this.inPanel = false,
    this.compact = false,
    this.autoReissue = false,
  });
  final QueueOrder order;
  final StaffRights rights;

  /// Drawn in a side window: no extra gap above the first button.
  final bool inPanel;

  /// The card's version: the one thing to do next (and a new pass when the pass ran out); the window has all of them.
  final bool compact;

  /// Opens with the "Issue a new pass?" question (a line of the needs-attention list asked for it).
  final bool autoReissue;

  static bool hasActions(QueueOrder o, StaffRights r, DateTime now) => PickupPlan.of(o, r, now).any;

  @override
  ConsumerState<PickupActionButtons> createState() => _PickupActionButtonsState();
}

class _PickupActionButtonsState extends ConsumerState<PickupActionButtons> {
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.autoReissue) WidgetsBinding.instance.addPostFrameCallback((_) => mounted ? unawaited(_reissue()) : null);
  }

  Future<void> _run(Future<void> Function() action, {String? done}) async {
    if (_busy) return;
    setState(() => _busy = true);
    final ok = await staffGuarded(context, action);
    if (!mounted) return;
    refreshStaff(ref);
    if (ok && done != null) showInfoSnack(context, done);
    setState(() => _busy = false);
  }

  Future<void> _advance(String status) =>
      _run(() => ref.read(opsRepositoryProvider).advance(widget.order.fulfillmentId, status));

  Future<void> _ready() => _run(
    () => ref.read(opsRepositoryProvider).pickupReady(widget.order.fulfillmentId),
    done: OpsStrings.of(context).pickupIsReady,
  );

  Future<void> _reissue() async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    final sure = await opsConfirm(context, title: t.reissueQ, body: t.reissueBody, verb: t.reissueVerb);
    if (!sure || !mounted) return;
    await _run(() => ref.read(opsRepositoryProvider).pickupReissue(widget.order.fulfillmentId), done: t.passReissued);
  }

  void _scan() => unawaited(showStaffScan(context));

  void _manual() {
    unawaited(showManualHandover(context, order: widget.order, branchId: widget.rights.branchId ?? ''));
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final now = ref.watch(staffTickProvider);
    final plan = PickupPlan.of(widget.order, widget.rights, now);
    if (!plan.any) return const SizedBox.shrink();
    final gap = widget.inPanel ? 0.0 : AppSpace.sm;
    final slide = ref.watch(opsStatusStyleProvider) == OpsStatusStyle.slide;

    Widget stepButton({
      required String label,
      required String slideLabel,
      required VoidCallback onGo,
      required String key,
    }) {
      if (slide) {
        return SlideToConfirm(
          key: ValueKey('slide-$key-${widget.order.fulfillmentId}-${widget.order.status}'),
          label: slideLabel,
          busyLabel: t.updating,
          loading: _busy,
          onConfirmed: onGo,
        );
      }
      return FilledButton(
        onPressed: _busy ? null : onGo,
        child: _busy
            ? SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2.2, color: Theme.of(context).colorScheme.onPrimary),
              )
            : Text(label, textAlign: TextAlign.center),
      );
    }

    final children = <Widget>[];
    if (plan.step != null) {
      final accept = plan.step == 'accepted';
      children.add(
        stepButton(
          key: plan.step!,
          label: accept ? t.acceptOrder : t.startPreparing,
          slideLabel: accept ? t.slideAcceptOrder : t.slideStartPreparing,
          onGo: () => unawaited(_advance(plan.step!)),
        ),
      );
    }
    if (plan.ready) {
      children.add(
        stepButton(
          key: 'pickup-ready',
          label: t.markPickupReady,
          slideLabel: t.slideMarkReady,
          onGo: () => unawaited(_ready()),
        ),
      );
    }
    if (plan.scan || plan.reissue || plan.manual) {
      if (plan.expired) {
        children.add(
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpace.xxs),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.timer_off_outlined, size: 18, color: context.palette.danger),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Text(
                    '${t.passExpired}. ${t.passExpiredHint}',
                    style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                  ),
                ),
              ],
            ),
          ),
        );
      }
      if (plan.blocked) {
        children.add(BlockedAction(label: t.scanPass, icon: Icons.qr_code_scanner_rounded, reason: t.cancelHold));
      } else {
        final reissueButton = plan.reissue
            ? (plan.expired ? FilledButton.icon : OutlinedButton.icon)(
                key: const ValueKey('pickup-reissue'),
                onPressed: _busy ? null : () => unawaited(_reissue()),
                icon: const Icon(Icons.autorenew_rounded, size: 18),
                label: Text(t.reissuePass, maxLines: 1, overflow: TextOverflow.ellipsis),
              )
            : null;
        final scanButton = plan.scan
            ? (plan.expired ? OutlinedButton.icon : FilledButton.icon)(
                key: const ValueKey('pickup-scan'),
                onPressed: _busy ? null : _scan,
                icon: const Icon(Icons.qr_code_scanner_rounded, size: 20),
                label: Text(t.scanPass, maxLines: 1, overflow: TextOverflow.ellipsis),
              )
            : null;
        if (widget.compact) {
          // On the card: the next thing to do (a new pass first when the pass ran out), the rest is in the window.
          final one = plan.expired ? (reissueButton ?? scanButton) : (scanButton ?? reissueButton);
          if (one != null) children.add(one);
        } else {
          children.addAll(
            (plan.expired ? [reissueButton, scanButton] : [scanButton, reissueButton]).whereType<Widget>(),
          );
          if (plan.manual) {
            children.add(
              OutlinedButton.icon(
                key: const ValueKey('pickup-manual'),
                onPressed: _busy ? null : _manual,
                icon: const Icon(Icons.badge_outlined, size: 18),
                label: Text(t.handOverWithoutPass, maxLines: 1, overflow: TextOverflow.ellipsis),
              ),
            );
          }
        }
      }
    }
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Gap(gap),
        for (var i = 0; i < children.length; i++) ...[if (i > 0) const Gap(AppSpace.xs), children[i]],
      ],
    );
  }
}
