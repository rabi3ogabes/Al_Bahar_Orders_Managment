import 'package:albahar_customer/core/realtime/live_link_badge.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:albahar_customer/ops/staff/staff_panels.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

export 'package:albahar_customer/ops/staff/staff_providers.dart';

/// Branch staff app: the day's delivery orders by stage, one-tap order steps, and (when the branch is on manual
/// dispatch) assigning a driver to a ready order. Several employees can use it at once; every screen reloads often.
class StaffHome extends ConsumerStatefulWidget {
  const StaffHome({super.key});

  @override
  ConsumerState<StaffHome> createState() => _StaffHomeState();
}

class _StaffHomeState extends ConsumerState<StaffHome> {
  // The screen follows the server through ONE orchestrator (staffLiveProvider): a message moves the order at once, a burst is confirmed
  // by one read, a reconnect / return to the foreground / return of the network reads everything, and a poll runs only while the socket is down.

  /// The refresh button: read everything now.
  void _reload() {
    ref.invalidate(staffOverviewProvider);
    for (final s in kStaffStages) {
      ref.invalidate(staffQueueProvider(s));
    }
    ref.invalidate(staffDriversProvider);
    ref.invalidate(staffFleetProvider);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final overview = ref.watch(staffOverviewViewProvider);
    final link = ref.watch(staffLiveProvider.select((s) => s.link));
    final o = overview.value;
    final permsAsync = ref.watch(staffPermissionsProvider);
    final perms = permsAsync.value ?? const <String>[];
    final canAddProduct = hasPermission(perms, 'catalog.create');
    final canSeeDrivers = hasPermission(perms, 'deliveries.view');
    final canEdit = hasPermission(perms, 'orders.edit');
    final canAssign = hasPermission(perms, 'deliveries.assign');
    final canReturn = hasPermission(perms, 'deliveries.edit');
    // A brand-new order (its first message says "pending"): one alert, however many times the message repeats.
    ref.listen(staffLiveProvider.select((s) => s.patches), (prev, next) {
      for (final p in next.values) {
        if (p.status == 'pending' && prev?[p.fulfillmentId] == null) {
          Haptics.success();
          showInfoSnack(context, t.newOrderAlert(p.number));
        }
      }
    });
    final tabs = <Tab>[
      Tab(text: '${t.stageNew}${o == null ? '' : ' · ${o.fresh}'}'),
      Tab(text: '${t.stagePreparing}${o == null ? '' : ' · ${o.preparing}'}'),
      Tab(text: '${t.stageReady}${o == null ? '' : ' · ${o.ready}'}'),
      Tab(text: '${t.stageOut}${o == null ? '' : ' · ${o.out}'}'),
      Tab(text: '${t.stageFailed}${o == null ? '' : ' · ${o.failed}'}'),
      if (canSeeDrivers) Tab(text: t.drivers),
      if (canSeeDrivers) Tab(text: t.mapTab),
    ];
    return DefaultTabController(
      key: ValueKey(tabs.length),
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: Row(
            children: [
              const OpsEmblem(role: OpsRole.staff, size: 34),
              const Gap(AppSpace.xs),
              Flexible(child: Text(o?.branchName ?? t.staffApp, overflow: TextOverflow.ellipsis)),
            ],
          ),
          actions: [
            LiveLinkBadge(link: link, labels: t.liveLabels),
            const Gap(AppSpace.xxs),
            if (canAddProduct)
              IconButton(
                tooltip: t.addProduct,
                icon: const Icon(Icons.add_photo_alternate_rounded),
                onPressed: () => Navigator.of(context)
                    .push(MaterialPageRoute<bool>(fullscreenDialog: true, builder: (_) => const AddProductScreen())),
              ),
            IconButton(tooltip: t.refresh, icon: const Icon(Icons.refresh_rounded), onPressed: _reload),
            const OpsAccountMenu(),
          ],
          bottom: TabBar(isScrollable: true, tabAlignment: TabAlignment.start, tabs: tabs),
        ),
        body: overview.hasError && o == null
            ? ErrorState(error: overview.error!, onRetry: () => ref.invalidate(staffOverviewProvider))
            : Column(
                children: [
                  if (o != null) _OverviewBar(o: o),
                  Expanded(
                    child: TabBarView(
                      children: [
                        for (final s in kStaffStages)
                          _OrderList(
                            stage: s,
                            autoDispatch: o?.autoDispatch ?? false,
                            canEdit: canEdit,
                            canAssign: canAssign,
                            canReturn: canReturn,
                          ),
                        if (canSeeDrivers) const _DriverList(),
                        if (canSeeDrivers) FleetMapTab(events: ref.watch(staffMovesProvider)),
                      ],
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

class _OverviewBar extends StatelessWidget {
  const _OverviewBar({required this.o});
  final StaffOverview o;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, 0),
      child: Column(
        children: [
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                CountTile(label: t.waitingForDriver, value: o.waitingForDriver, highlight: o.waitingForDriver > 0),
                const Gap(AppSpace.xs),
                CountTile(label: t.driversOnDuty, value: o.driversOnDuty),
                const Gap(AppSpace.xs),
                CountTile(label: t.stageOut, value: o.out),
              ],
            ),
          ),
          const Gap(AppSpace.xs),
          Row(
            children: [
              Icon(
                o.autoDispatch ? Icons.auto_mode_rounded : Icons.touch_app_outlined,
                size: 16,
                color: context.palette.muted,
              ),
              const Gap(AppSpace.xxs),
              Expanded(child: Text(o.autoDispatch ? t.autoDispatch : t.manualDispatch, style: context.text.bodySmall)),
            ],
          ),
        ],
      ),
    );
  }
}

class _OrderList extends ConsumerWidget {
  const _OrderList({
    required this.stage,
    required this.autoDispatch,
    required this.canEdit,
    required this.canAssign,
    required this.canReturn,
  });
  final String stage;
  final bool autoDispatch;
  final bool canEdit;
  final bool canAssign;
  final bool canReturn;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    return OpsAsyncList<QueueOrder>(
      value: ref.watch(staffQueueViewProvider(stage)),
      onRefresh: () async {
        ref.invalidate(staffOverviewProvider);
        ref.invalidate(staffQueueProvider(stage));
        await ref.read(staffQueueProvider(stage).future);
      },
      onRetry: () => ref.invalidate(staffQueueProvider(stage)),
      emptyIcon: Icons.inbox_outlined,
      emptyTitle: t.noOrders,
      itemBuilder: (_, order) => _OrderCard(
        key: ValueKey('${order.fulfillmentId}-${order.status}-${order.driverId}'),
        order: order,
        autoDispatch: autoDispatch,
        canEdit: canEdit,
        canAssign: canAssign,
        canReturn: canReturn,
      ),
    );
  }
}

class _OrderCard extends ConsumerWidget {
  const _OrderCard({
    super.key,
    required this.order,
    required this.autoDispatch,
    required this.canEdit,
    required this.canAssign,
    required this.canReturn,
  });
  final QueueOrder order;
  final bool autoDispatch;
  final bool canEdit;
  final bool canAssign;
  final bool canReturn;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final o = order;
    final hasDriver = o.driverName != null;
    return SurfaceCard(
      // The whole card opens the order in a window beside the queue: the tab and the scroll position stay.
      onTap: () => showOrderPanel(
        context,
        order: o,
        autoDispatch: autoDispatch,
        canEdit: canEdit,
        canAssign: canAssign,
        canReturn: canReturn,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text('#${o.number}', style: context.text.titleMedium)),
              Text(
                t.money(o.total),
                style: context.text.titleSmall?.copyWith(color: context.colors.primary, fontWeight: FontWeight.w700),
              ),
            ],
          ),
          const Gap(2),
          Text('${o.customerName} · ${t.items(o.itemsCount)}', style: context.text.bodyMedium),
          Text(t.address(o.address), style: context.text.bodySmall),
          if (hasDriver)
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xs),
              child: StatusChip(
                label: t.assignedTo(o.driverName!),
                tone: StatusTone.info,
                icon: Icons.two_wheeler_rounded,
              ),
            )
          else if (o.status == 'ready')
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xs),
              child: StatusChip(label: t.waitingForDriver, tone: StatusTone.warning, icon: Icons.hourglass_top_rounded),
            ),
          if (o.status == 'failed')
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xs),
              child: StatusChip(
                label: t.deliveryFailedTag,
                tone: StatusTone.danger,
                icon: Icons.report_gmailerrorred_rounded,
              ),
            ),
          OrderActionButtons(
            key: ValueKey('steps-${o.fulfillmentId}-${o.status}-${o.driverId}'),
            order: o,
            autoDispatch: autoDispatch,
            canEdit: canEdit,
            canAssign: canAssign,
            canReturn: canReturn,
          ),
        ],
      ),
    );
  }
}

class _DriverList extends ConsumerWidget {
  const _DriverList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final drivers = ref.watch(staffDriversProvider);
    return RefreshIndicator(
      onRefresh: () => ref.refresh(staffDriversProvider.future),
      child: drivers.when(
        skipLoadingOnRefresh: true,
        skipError: true,
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffDriversProvider)),
        data: (list) => list.isEmpty
            ? ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  const Gap(AppSpace.xxl),
                  EmptyState(icon: Icons.two_wheeler_rounded, title: t.noDrivers, message: '', compact: true),
                ],
              )
            : ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
                children: [
                  for (final d in list)
                    DriverTile(
                      driver: d,
                      onTap: () => showDriverPanel(context, driver: d),
                    ),
                ],
              ),
      ),
    );
  }
}
