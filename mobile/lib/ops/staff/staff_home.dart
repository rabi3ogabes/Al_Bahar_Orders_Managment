import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/debouncer.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final staffOverviewProvider = FutureProvider.autoDispose<StaffOverview>(
  (ref) => ref.watch(opsRepositoryProvider).overview(),
);

final staffQueueProvider = FutureProvider.autoDispose.family<List<QueueOrder>, String>(
  (ref, stage) => ref.watch(opsRepositoryProvider).queue(stage),
);

final staffDriversProvider = FutureProvider.autoDispose<List<BranchDriver>>(
  (ref) => ref.watch(opsRepositoryProvider).drivers(),
);

/// Real-time pings for the branch (`private-branches.{id}`): an order or delivery moved.
final staffEventsProvider = StreamProvider.autoDispose<PusherEvent>((ref) {
  final id = ref.watch(staffOverviewProvider.select((o) => o.value?.branchId));
  if (id == null || id.isEmpty) return const Stream.empty();
  return ref.watch(pusherClientProvider).channel('private-branches.$id');
});

/// `driver.moved` payloads from the branch channel, for the live map.
final staffMovesProvider = Provider.autoDispose<Stream<Map<String, dynamic>>>((ref) {
  final id = ref.watch(staffOverviewProvider.select((o) => o.value?.branchId));
  if (id == null || id.isEmpty) return const Stream.empty();
  return ref
      .watch(pusherClientProvider)
      .channel('private-branches.$id')
      .where((e) => e.event == 'driver.moved')
      .map((e) => e.data);
});

const _stages = ['new', 'preparing', 'ready', 'out', 'failed'];

/// Branch staff app: the day's delivery orders by stage, one-tap order steps, and (when the branch is on manual
/// dispatch) assigning a driver to a ready order. Several employees can use it at once; every screen reloads often.
final staffPermissionsProvider = FutureProvider.autoDispose<List<String>>(
  (ref) => ref.watch(opsRepositoryProvider).permissions(),
);

/// Whether the signed-in employee holds [key] (the API checks it again; this only hides buttons that would be refused).
bool hasPermission(List<String> perms, String key) {
  if (perms.contains('*') || perms.contains(key)) return true;
  final dot = key.indexOf('.');
  return dot > 0 && perms.contains('${key.substring(0, dot)}.*');
}

class StaffHome extends ConsumerStatefulWidget {
  const StaffHome({super.key});

  @override
  ConsumerState<StaffHome> createState() => _StaffHomeState();
}

class _StaffHomeState extends ConsumerState<StaffHome> with OpsPolling {
  // A burst of real-time pings (several orders at once) becomes one reload.
  final _reloadSoon = Debouncer(delay: const Duration(milliseconds: 800));

  @override
  void initState() {
    super.initState();
    startPolling(_reload, every: const Duration(seconds: 60));
  }

  @override
  void dispose() {
    _reloadSoon.dispose();
    super.dispose();
  }

  void _reload() {
    ref.invalidate(staffOverviewProvider);
    for (final s in _stages) {
      ref.invalidate(staffQueueProvider(s));
    }
    ref.invalidate(staffDriversProvider);
    ref.invalidate(staffFleetProvider);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final overview = ref.watch(staffOverviewProvider);
    final o = overview.value;
    final permsAsync = ref.watch(staffPermissionsProvider);
    final perms = permsAsync.value ?? const <String>[];
    final canAddProduct = hasPermission(perms, 'catalog.create');
    final canSeeDrivers = hasPermission(perms, 'deliveries.view');
    final canEdit = hasPermission(perms, 'orders.edit');
    final canAssign = hasPermission(perms, 'deliveries.assign');
    final canReturn = hasPermission(perms, 'deliveries.edit');
    ref.listen(staffEventsProvider, (_, next) {
      final e = next.value;
      if (e == null || e.event != 'dispatch.updated') return;
      _reloadSoon(_reload);
      if (e.data['kind'] == 'order' && e.data['status'] == 'pending') {
        Haptics.success();
        showInfoSnack(context, t.newOrderAlert('${e.data['number'] ?? ''}'));
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
                        for (final s in _stages)
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
      value: ref.watch(staffQueueProvider(stage)),
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

class _OrderCard extends ConsumerStatefulWidget {
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
  ConsumerState<_OrderCard> createState() => _OrderCardState();
}

class _OrderCardState extends ConsumerState<_OrderCard> {
  bool _busy = false;

  void _refreshAll() {
    ref.invalidate(staffOverviewProvider);
    for (final s in _stages) {
      ref.invalidate(staffQueueProvider(s));
    }
    ref.invalidate(staffDriversProvider);
  }

  /// One action at a time. Lists reload whether it worked or not: when a colleague already moved the order (409) the card
  /// shows the real state instead of a stale button.
  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() => _busy = true);
    await guarded(context, action);
    if (!mounted) return;
    _refreshAll();
    setState(() => _busy = false);
  }

  Future<void> _advance(String status) =>
      _run(() => ref.read(opsRepositoryProvider).advance(widget.order.fulfillmentId, status));

  /// A failed delivery brought back to the branch: stock goes back and the order closes as returned.
  Future<void> _returnToBranch() async {
    final id = widget.order.deliveryId;
    if (id == null || _busy) return;
    final t = OpsStrings.of(context);
    final sure = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t.confirmReturnQ),
        content: Text(t.confirmReturnBody),
        actions: [
          TextButton(autofocus: true, onPressed: () => Navigator.pop(c, false), child: Text(t.cancel)),
          TextButton(onPressed: () => Navigator.pop(c, true), child: Text(t.confirm)),
        ],
      ),
    );
    if (sure != true || !mounted) return;
    await _run(() => ref.read(opsRepositoryProvider).returnToBranch(id));
  }

  Future<void> _assign() async {
    if (_busy) return;
    final driver = await showModalBottomSheet<BranchDriver>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => const _DriverPicker(),
    );
    if (driver == null || !mounted) return;
    await _run(() => ref.read(opsRepositoryProvider).assign(widget.order.fulfillmentId, driver.id));
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final o = widget.order;
    final step = widget.canEdit ? o.nextStep : null;
    final stepLabel = switch (step) {
      'accepted' => t.acceptOrder,
      'preparing' => t.startPreparing,
      'ready' => t.markReady,
      _ => null,
    };
    final hasDriver = o.driverName != null;
    final showReturn = widget.canReturn && o.status == 'failed' && o.deliveryId != null;
    final showAssign =
        widget.canAssign &&
        o.status == 'ready' &&
        o.deliveryStatus != 'picked_up' &&
        o.deliveryStatus != 'out_for_delivery';
    return SurfaceCard(
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
          if (showReturn) ...[
            const Gap(AppSpace.sm),
            OutlinedButton.icon(
              onPressed: _busy ? null : _returnToBranch,
              icon: const Icon(Icons.assignment_return_rounded, size: 18),
              label: Text(t.returnToBranch),
            ),
          ],
          if (stepLabel != null || showAssign) ...[
            const Gap(AppSpace.sm),
            Row(
              children: [
                if (stepLabel != null)
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
        ],
      ),
    );
  }
}

class _DriverPicker extends ConsumerWidget {
  const _DriverPicker();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final drivers = ref.watch(staffDriversProvider);
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.7),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.all(AppSpace.md),
              child: Text(t.chooseDriver, style: Theme.of(context).textTheme.titleMedium),
            ),
            Flexible(
              child: drivers.when(
                loading: () => const Padding(padding: EdgeInsets.all(AppSpace.xl), child: CircularProgressIndicator()),
                error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffDriversProvider)),
                data: (list) => list.isEmpty
                    ? Padding(
                        padding: const EdgeInsets.all(AppSpace.lg),
                        child: Text(t.noDrivers, textAlign: TextAlign.center),
                      )
                    : ListView(
                        shrinkWrap: true,
                        children: [
                          for (final d in list) _DriverTile(driver: d, onTap: () => Navigator.pop(context, d)),
                        ],
                      ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DriverTile extends StatelessWidget {
  const _DriverTile({required this.driver, this.onTap});
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
                children: [for (final d in list) _DriverTile(driver: d)],
              ),
      ),
    );
  }
}
