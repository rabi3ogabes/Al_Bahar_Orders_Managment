import 'dart:async';

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:albahar_customer/ops/staff/adjust_flow.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:albahar_customer/ops/staff/staff_cancellation.dart';
import 'package:albahar_customer/ops/staff/staff_clock_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_errors.dart';
import 'package:albahar_customer/ops/staff/staff_offline.dart';
import 'package:albahar_customer/ops/staff/staff_pickup.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_rights.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:albahar_customer/ops/staff/staff_waiting.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The buttons of one order in the branch queue: the next step (accept, start preparing, mark ready), assign or change
/// the driver, and bring a failed delivery back. They sit on the order card and pinned in the order window, so a step is
/// one tap either way.
///
/// One action at a time. The lists reload whether it worked or not: when a colleague already moved the order (409) the
/// screen shows the real state instead of a stale button.
class OrderActionButtons extends ConsumerStatefulWidget {
  const OrderActionButtons({
    super.key,
    required this.order,
    required this.autoDispatch,
    required this.canEdit,
    required this.canAssign,
    required this.canReturn,
    this.inPanel = false,
    this.startWithAssign = false,
  });
  final QueueOrder order;
  final bool autoDispatch;
  final bool canEdit;
  final bool canAssign;
  final bool canReturn;

  /// Drawn in a side window: no extra gap above the first button.
  final bool inPanel;

  /// Opens with the driver list (a line of the needs-attention list asked for it).
  final bool startWithAssign;

  /// Whether this person has anything to press on this order right now (an empty footer is not drawn at all).
  static bool hasActions(QueueOrder o, {required bool canEdit, required bool canAssign, required bool canReturn}) =>
      _stepFor(o, canEdit) != null || _canAssign(o, canAssign) || _canReturn(o, canReturn);

  static String? _stepFor(QueueOrder o, bool canEdit) => canEdit ? o.nextStep : null;

  static bool _canAssign(QueueOrder o, bool canAssign) =>
      canAssign && o.status == 'ready' && o.deliveryStatus != 'picked_up' && o.deliveryStatus != 'out_for_delivery';

  static bool _canReturn(QueueOrder o, bool canReturn) => canReturn && o.status == 'failed' && o.deliveryId != null;

  @override
  ConsumerState<OrderActionButtons> createState() => _OrderActionButtonsState();
}

class _OrderActionButtonsState extends ConsumerState<OrderActionButtons> {
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.startWithAssign &&
        OrderActionButtons._canAssign(widget.order, widget.canAssign) &&
        !widget.order.cancellationOpen) {
      WidgetsBinding.instance.addPostFrameCallback((_) => mounted ? unawaited(_assign()) : null);
    }
  }

  void _refreshAll() => refreshStaff(ref);

  /// Runs one step. When the server accepted it, the screen shows the new state at once ([shown]); the reads that follow confirm it.
  Future<void> _run(Future<void> Function() action, {void Function(StaffLive live)? shown}) async {
    if (_busy) return;
    setState(() => _busy = true);
    final ok = await staffGuarded(context, action);
    if (!mounted) return;
    if (ok && shown != null) shown(ref.read(staffLiveProvider.notifier));
    _refreshAll();
    setState(() => _busy = false);
  }

  Future<void> _advance(String status) => _run(
    () => ref.read(opsRepositoryProvider).advance(widget.order.fulfillmentId, status),
    shown: (live) => live.applyOwn(widget.order.fulfillmentId, status: status),
  );

  /// A failed delivery brought back to the branch: stock goes back and the order closes as returned.
  Future<void> _returnToBranch() async {
    final id = widget.order.deliveryId;
    if (id == null || _busy) return;
    final t = OpsStrings.of(context);
    final sure = await opsConfirm(context, title: t.confirmReturnQ, body: t.confirmReturnBody, verb: t.confirmReturn);
    if (!sure || !mounted) return;
    await _run(
      () => ref.read(opsRepositoryProvider).returnToBranch(id),
      shown: (live) => live.applyOwn(widget.order.fulfillmentId, status: 'returned'),
    );
  }

  Future<void> _assign() async {
    if (_busy) return;
    final driver = await showDriverPicker(context, orderNumber: widget.order.number);
    if (driver == null || !mounted) return;
    await _run(
      () => ref.read(opsRepositoryProvider).assign(widget.order.fulfillmentId, driver.id),
      shown: (live) => live.applyOwn(
        widget.order.fulfillmentId,
        deliveryStatus: 'assigned',
        driverId: driver.id,
        driverName: driver.name,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final o = widget.order;
    final step = OrderActionButtons._stepFor(o, widget.canEdit);
    final stepLabel = switch (step) {
      'accepted' => t.acceptOrder,
      'preparing' => t.startPreparing,
      'ready' => t.markReady,
      _ => null,
    };
    final hasDriver = o.driverName != null;
    final showReturn = OrderActionButtons._canReturn(o, widget.canReturn);
    final canAssign = OrderActionButtons._canAssign(o, widget.canAssign);
    // While the customer's request to cancel waits for a manager nothing may be handed to a driver: shown switched off, with the reason.
    final blockedAssign = canAssign && o.cancellationOpen;
    final showAssign = canAssign && !blockedAssign;
    if (stepLabel == null && !canAssign && !showReturn) return const SizedBox.shrink();
    final gap = widget.inPanel ? 0.0 : AppSpace.sm;
    // The dashboard chooses how a step is taken: a button, or "slide to confirm" (a step then cannot be taken by a stray tap).
    final slide = ref.watch(opsStatusStyleProvider) == OpsStatusStyle.slide;
    final offlineLabel = stepLabel ?? (canAssign ? (hasDriver ? t.changeDriver : t.assignDriver) : t.returnToBranch);
    return StaffOnlineGate(
      label: offlineLabel,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (showReturn) ...[
            Gap(gap),
            OutlinedButton.icon(
              onPressed: _busy ? null : _returnToBranch,
              icon: const Icon(Icons.assignment_return_rounded, size: 18),
              label: Text(t.returnToBranch),
            ),
          ],
          if (stepLabel != null || showAssign) ...[
            Gap(gap),
            Row(
              children: [
                if (stepLabel != null && slide)
                  Expanded(
                    child: SlideToConfirm(
                      key: ValueKey('slide-${o.fulfillmentId}-${o.status}'),
                      label: t.slideStaffStep(step!),
                      busyLabel: t.updating,
                      loading: _busy,
                      onConfirmed: () => _advance(step),
                    ),
                  )
                else if (stepLabel != null)
                  Expanded(
                    child: FilledButton(
                      onPressed: _busy ? null : () => _advance(step!),
                      child: _busy
                          ? SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.2,
                                color: Theme.of(context).colorScheme.onPrimary,
                              ),
                            )
                          : Text(stepLabel, textAlign: TextAlign.center),
                    ),
                  ),
                if (showAssign)
                  Expanded(
                    child: (hasDriver || widget.autoDispatch ? OutlinedButton.new : FilledButton.new)(
                      onPressed: _busy ? null : _assign,
                      child: Text(hasDriver ? t.changeDriver : t.assignDriver, textAlign: TextAlign.center),
                    ),
                  ),
              ],
            ),
          ],
          if (blockedAssign) ...[
            Gap(stepLabel != null ? AppSpace.xs : gap),
            BlockedAction(
              label: hasDriver ? t.changeDriver : t.assignDriver,
              icon: Icons.two_wheeler_rounded,
              reason: t.cancelHold,
            ),
          ],
        ],
      ),
    );
  }
}

/// One order in a window beside the queue: how long it has waited, the customer's request to cancel (when there is one), customer, address,
/// what is in it, payment, the driver, and the step buttons pinned at the bottom. The queue behind keeps its tab and scroll position; one Back
/// closes the window.
///
/// It follows the order from stage to stage (accepting moves it from New to Preparing, and the window moves with it);
/// when the order leaves the queue (delivered, cancelled, handed over) the window closes itself and says so. [autoAssign] / [autoReissue]
/// open the driver list / the "new pass" question at once (a line of the needs-attention list asked for it).
Future<void> showOrderPanel(
  BuildContext context, {
  required QueueOrder order,
  required StaffRights rights,
  bool autoAssign = false,
  bool autoReissue = false,
}) {
  return showSidePanel<void>(
    context: context,
    title: OpsStrings.of(context).orderTitle(order.number),
    scrollable: false,
    builder: (_) => LiveRecord<QueueOrder>(
      initial: order,
      source: staffOrderLookupProvider,
      isSame: (o) => o.fulfillmentId == order.fulfillmentId,
      goneMessage: (c) => OpsStrings.of(c).orderGone,
      builder: (_, current) =>
          _OrderWindow(order: current, rights: rights, autoAssign: autoAssign, autoReissue: autoReissue),
    ),
  );
}

class _OrderWindow extends ConsumerWidget {
  const _OrderWindow({required this.order, required this.rights, required this.autoAssign, required this.autoReissue});
  final QueueOrder order;
  final StaffRights rights;
  final bool autoAssign;
  final bool autoReissue;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final o = order;
    final Widget? footer;
    if (o.isPickup) {
      footer = PickupActionButtons.hasActions(o, rights, ref.watch(staffTickProvider))
          ? PickupActionButtons(
              key: ValueKey(o.fulfillmentId),
              order: o,
              rights: rights,
              inPanel: true,
              autoReissue: autoReissue,
            )
          : null;
    } else {
      footer =
          OrderActionButtons.hasActions(
            o,
            canEdit: rights.canEdit,
            canAssign: rights.canAssign,
            canReturn: rights.canReturn,
          )
          ? OrderActionButtons(
              key: ValueKey(o.fulfillmentId),
              order: o,
              autoDispatch: rights.autoDispatch,
              canEdit: rights.canEdit,
              canAssign: rights.canAssign,
              canReturn: rights.canReturn,
              inPanel: true,
              startWithAssign: autoAssign,
            )
          : null;
    }
    return SidePanelLayout(
      footer: footer,
      child: _OrderDetails(order: o, rights: rights),
    );
  }
}

class _OrderDetails extends ConsumerWidget {
  const _OrderDetails({required this.order, required this.rights});
  final QueueOrder order;
  final StaffRights rights;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final o = order;
    final canEdit = rights.canEdit;
    final address = t.address(o.address);
    final cod = o.paymentMethod == 'cod';
    final pickup = o.isPickup;
    final driverPhone = o.driverId == null
        ? null
        : ref.watch(staffDriversProvider).value?.where((d) => d.id == o.driverId).firstOrNull?.phone;
    final showDelivery =
        !pickup &&
        (o.driverName != null || const {'ready', 'dispatched', 'out_for_delivery', 'failed'}.contains(o.status));
    final request = o.cancellation;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (request != null && request.open) ...[
          CancellationBanner(request: request, canDecide: rights.canDecideCancellation),
          if (rights.canDecideCancellation) ...[const Gap(AppSpace.xs), CancellationDecision(request: request)],
          const Gap(AppSpace.md),
        ],
        if (o.waiting != null) ...[WaitingBanner(waiting: o.waiting!), const Gap(AppSpace.md)],
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpace.md),
          child: Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              OrderAgeChip(order: o),
              StatusChip(
                label: pickup && o.status == 'pickup_ready' ? t.stagePickupReady : t.orderStage(o.status),
                tone: switch (o.status) {
                  'failed' => StatusTone.danger,
                  'ready' || 'pickup_ready' => StatusTone.success,
                  'pending' => StatusTone.warning,
                  _ => StatusTone.info,
                },
              ),
              if (pickup) StatusChip(label: t.pickupOrderTag, tone: StatusTone.accent, icon: Icons.storefront_rounded),
              if (o.status == 'failed')
                StatusChip(
                  label: t.deliveryFailedTag,
                  tone: StatusTone.danger,
                  icon: Icons.report_gmailerrorred_rounded,
                ),
              if (cod) StatusChip(label: t.paymentCash, tone: StatusTone.warning, icon: Icons.payments_outlined),
            ],
          ),
        ),
        PanelSection(
          title: t.customer,
          children: [
            Text(o.customerName, style: context.text.titleMedium),
            if (o.customerPhone != null) ...[
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(o.customerPhone!, style: context.text.bodyMedium),
                ),
              ),
              const Gap(AppSpace.sm),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: () => dial(context, o.customerPhone),
                  icon: const Icon(Icons.call_rounded, size: 18),
                  label: Text('${t.call} ${o.customerName}', maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
              ),
            ],
            // The customer's second number, so staff can reach the customer when the main one is not answered (only while the
            // dashboard's "Alternative phone number" switch is on).
            if (o.alternatePhone != null && ref.watch(opsAlternatePhoneEnabledProvider))
              AlternatePhoneCall(key: const ValueKey('call-alternative'), phone: o.alternatePhone!),
          ],
        ),
        if (pickup)
          _PickupSection(order: o)
        else
          PanelSection(
            title: t.deliveryAddress,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.place_outlined, size: 20, color: p.muted),
                  const Gap(AppSpace.xs),
                  Expanded(child: Text(address, style: context.text.bodyLarge)),
                ],
              ),
              if (o.address.notes != null)
                Padding(
                  padding: const EdgeInsetsDirectional.only(top: AppSpace.xs, start: 28),
                  child: Text(o.address.notes!, style: context.text.bodyMedium?.copyWith(color: p.muted)),
                ),
              const Gap(AppSpace.sm),
              SizedBox(
                width: double.infinity,
                child: CopyTextButton(text: address, label: t.copyAddress),
              ),
            ],
          ),
        PanelItems(items: o.items, count: o.itemsCount),
        // Something is short on the shelf: only while the branch is accepting or preparing the order, and only for those who may edit it.
        if (canEdit && AdjustEntry.appliesTo(o)) AdjustEntry(order: o),
        PanelSection(
          title: t.orderSection,
          children: [
            PanelRow(
              label: t.payment,
              value: cod ? t.paymentCash : (o.paymentMethod == 'pay_at_pickup' ? t.paymentAtPickup : t.paymentOnline),
            ),
            PanelRow(label: t.total, value: t.money(o.total), emphasize: true),
            if (pickup && o.dueAmount > 0) PanelRow(label: t.toCollect, value: t.money(o.amountDue), emphasize: true),
          ],
        ),
        if (showDelivery)
          PanelSection(
            title: t.deliveryTitle,
            children: [
              if (o.driverName != null) ...[
                Row(
                  children: [
                    Icon(Icons.two_wheeler_rounded, size: 20, color: p.muted),
                    const Gap(AppSpace.xs),
                    Expanded(child: Text(o.driverName!, style: context.text.titleSmall)),
                    if (o.deliveryStatus != null)
                      StatusChip(label: t.statusLabel(o.deliveryStatus!), tone: toneForOpsStatus(o.deliveryStatus!)),
                  ],
                ),
                if (driverPhone != null) ...[
                  const Gap(AppSpace.sm),
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      onPressed: () => dial(context, driverPhone),
                      icon: const Icon(Icons.call_rounded, size: 18),
                      label: Text('${t.call} ${o.driverName}', maxLines: 1, overflow: TextOverflow.ellipsis),
                    ),
                  ),
                ],
              ] else
                Row(
                  children: [
                    Icon(Icons.hourglass_top_rounded, size: 20, color: p.warning),
                    const Gap(AppSpace.xs),
                    Expanded(child: Text(t.noDriverYet, style: context.text.bodyLarge)),
                  ],
                ),
            ],
          ),
      ],
    );
  }
}

/// Where a pickup order stands at the counter: the pass (valid until, or run out) and whether part of the goods was handed over already.
class _PickupSection extends ConsumerWidget {
  const _PickupSection({required this.order});
  final QueueOrder order;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final now = ref.watch(staffTickProvider);
    final pass = order.pickup;
    final ready = order.status == 'pickup_ready';
    final expired = ready && (pass?.isExpired(now) ?? false);
    final until = pass?.expiresAt;
    final partly = order.items.isNotEmpty && ready && (pass?.status == 'partially_collected');
    return PanelSection(
      title: t.modePickup,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              expired ? Icons.timer_off_outlined : Icons.storefront_rounded,
              size: 20,
              color: expired ? p.danger : p.muted,
            ),
            const Gap(AppSpace.xs),
            Expanded(
              child: Text(
                !ready
                    ? t.stageLabelPickup(order.status)
                    : expired
                    ? '${t.passExpired}. ${t.passExpiredHint}'
                    : until != null
                    ? t.passValidUntil(QatarTime.dateTime(until, t.code))
                    : t.stagePickupReady,
                style: context.text.bodyLarge?.copyWith(color: expired ? p.danger : null),
              ),
            ),
          ],
        ),
        if (partly)
          Padding(
            padding: const EdgeInsets.only(top: AppSpace.xs),
            child: Text(t.partlyHandedOver, style: context.text.bodyMedium?.copyWith(color: p.muted)),
          ),
      ],
    );
  }
}

/// "Missing an item?" under the items of an order that is being accepted or prepared. The server decides whether the parcel can still
/// be corrected (`can_adjust`): once it says no the button is gone. While that answer is on its way, or when it could not be read,
/// the button is there and a tap asks again.
class AdjustEntry extends ConsumerStatefulWidget {
  const AdjustEntry({super.key, required this.order});
  final QueueOrder order;

  /// Only these two stages of a branch part can be corrected.
  static bool appliesTo(QueueOrder o) => o.status == 'accepted' || o.status == 'preparing';

  @override
  ConsumerState<AdjustEntry> createState() => _AdjustEntryState();
}

class _AdjustEntryState extends ConsumerState<AdjustEntry> {
  bool _busy = false;

  Future<void> _open() async {
    if (_busy) return;
    final id = widget.order.fulfillmentId;
    setState(() => _busy = true);
    final provider = adjustContextProvider(id);
    // An answer that could not be read is asked for again on this tap.
    if (ref.read(provider).hasError) ref.invalidate(provider);
    AdjustContext? data;
    try {
      data = await ref.read(provider.future);
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    }
    if (!mounted) return;
    setState(() => _busy = false);
    if (data == null) return;
    if (!data.canAdjust) {
      showInfoSnack(context, OpsStrings.of(context).adjustNotAllowed);
      return;
    }
    final changed = await showAdjustFlow(context, order: widget.order, data: data);
    if (changed == true && mounted) ref.invalidate(adjustContextProvider(id));
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final known = ref.watch(adjustContextProvider(widget.order.fulfillmentId));
    if (known.value?.canAdjust == false) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpace.md),
      child: OutlinedButton.icon(
        onPressed: _busy ? null : _open,
        icon: _busy
            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
            : const Icon(Icons.remove_shopping_cart_outlined, size: 20),
        label: Text(t.missingItem, maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
    );
  }
}

/// Choose the driver for an order. A window beside the order, with the drivers who are on duty and least busy first;
/// resolves to the driver that was tapped (the opener assigns).
Future<BranchDriver?> showDriverPicker(BuildContext context, {required String orderNumber}) {
  final t = OpsStrings.of(context);
  return showSidePanel<BranchDriver>(
    context: context,
    title: t.chooseDriver,
    subtitle: t.forOrder(orderNumber),
    scrollable: false,
    builder: (_) => const _DriverPickerList(),
  );
}

class _DriverPickerList extends ConsumerWidget {
  const _DriverPickerList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final drivers = ref.watch(staffDriversProvider);
    return drivers.when(
      loading: () => const Padding(
        padding: EdgeInsets.all(AppSpace.xl),
        child: Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffDriversProvider)),
      data: (list) {
        if (list.isEmpty) {
          return Padding(
            padding: const EdgeInsets.all(AppSpace.lg),
            child: Text(t.noDrivers, textAlign: TextAlign.center),
          );
        }
        // On duty first, then the least busy, then by name.
        final sorted = [...list]
          ..sort((a, b) {
            if (a.onDuty != b.onDuty) return a.onDuty ? -1 : 1;
            final byJobs = a.openJobs.compareTo(b.openJobs);
            return byJobs != 0 ? byJobs : a.name.compareTo(b.name);
          });
        return ListView(
          shrinkWrap: true,
          children: [for (final d in sorted) DriverTile(driver: d, onTap: () => Navigator.of(context).pop(d))],
        );
      },
    );
  }
}

/// A driver row: duty colour, name, open jobs or "Off duty", and a call button named after the driver.
class DriverTile extends StatelessWidget {
  const DriverTile({super.key, required this.driver, this.onTap});
  final BranchDriver driver;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    return ListTile(
      minTileHeight: 60,
      onTap: onTap,
      leading: CircleAvatar(
        backgroundColor: (driver.onDuty ? p.success : p.muted).withValues(alpha: 0.16),
        child: Icon(Icons.two_wheeler_rounded, color: driver.onDuty ? p.success : p.muted),
      ),
      title: Text(driver.name),
      subtitle: Text(driver.onDuty ? t.openJobs(driver.openJobs) : t.offDutyTag),
      trailing: driver.phone == null
          ? null
          : IconButton(
              tooltip: '${t.call} ${driver.name}',
              icon: const Icon(Icons.call_rounded),
              onPressed: () => dial(context, driver.phone),
            ),
    );
  }
}

/// One driver in a window beside the Drivers list: duty, the delivery in hand, when the phone was last heard from, the
/// vehicle, and a big Call button pinned at the bottom. It follows the driver live and closes with a message if the
/// driver leaves the branch.
Future<void> showDriverPanel(BuildContext context, {required BranchDriver driver}) {
  return showSidePanel<void>(
    context: context,
    title: driver.name,
    scrollable: false,
    builder: (_) => LiveRecord<BranchDriver>(
      initial: driver,
      source: staffLiveDriversProvider,
      isSame: (d) => d.id == driver.id,
      goneMessage: (c) => OpsStrings.of(c).driverGone,
      builder: (ctx, current) => SidePanelLayout(
        footer: current.phone == null
            ? null
            : FilledButton.icon(
                onPressed: () => dial(ctx, current.phone),
                icon: const Icon(Icons.call_rounded, size: 20),
                label: Text('${OpsStrings.of(ctx).call} ${current.name}', maxLines: 1, overflow: TextOverflow.ellipsis),
              ),
        child: _DriverDetails(driver: current),
      ),
    ),
  );
}

class _DriverDetails extends ConsumerWidget {
  const _DriverDetails({required this.driver});
  final BranchDriver driver;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final d = driver;
    final fleet = ref.watch(staffFleetProvider);
    final live = fleet.value?.where((x) => x.id == d.id).firstOrNull;
    final now = DateTime.now();
    final age = live == null
        ? null
        : live.fix?.age(now) ?? (live.lastSeen == null ? null : now.difference(live.lastSeen!));
    final job = live?.orderNumber == null
        ? (d.onDuty ? t.driversFree : t.offDutyTag)
        : '${t.orderNo(live!.orderNumber!)} · ${t.statusLabel(live.jobStatus ?? '')}';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: AppSpace.md),
          child: Wrap(
            spacing: AppSpace.xs,
            runSpacing: AppSpace.xs,
            children: [
              StatusChip(
                label: d.onDuty ? t.onDuty : t.offDuty,
                tone: d.onDuty ? StatusTone.success : StatusTone.neutral,
              ),
              StatusChip(label: t.openJobs(d.openJobs), tone: StatusTone.info, icon: Icons.inventory_2_outlined),
            ],
          ),
        ),
        PanelSection(
          title: t.currentDelivery,
          children: [
            if (fleet.isLoading && live == null)
              const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.2))
            else
              Row(
                children: [
                  Icon(Icons.two_wheeler_rounded, size: 20, color: p.muted),
                  const Gap(AppSpace.xs),
                  Expanded(child: Text(job, style: context.text.bodyLarge)),
                ],
              ),
          ],
        ),
        if (live != null)
          PanelSection(
            title: t.lastSeen,
            children: [
              Row(
                children: [
                  Icon(Icons.location_on_outlined, size: 20, color: p.muted),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Text(
                      live.hasPosition && age != null ? t.seenAgo(age) : t.noLocationYet,
                      style: context.text.bodyLarge,
                    ),
                  ),
                ],
              ),
              if (live.vehicle != null)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpace.xs),
                  child: Text('${t.vehicle}: ${live.vehicle}', style: context.text.bodyMedium),
                ),
              if (live.battery != null)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(t.batteryLevel(live.battery!), style: context.text.bodyMedium),
                ),
            ],
          ),
      ],
    );
  }
}
