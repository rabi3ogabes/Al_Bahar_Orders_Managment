import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
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

const _stages = ['new', 'preparing', 'ready', 'out'];

/// Branch staff app: the day's delivery orders by stage, one-tap order steps, and (when the branch is on manual
/// dispatch) assigning a driver to a ready order. Several employees can use it at once; every screen reloads often.
final _staffPermissionsProvider = FutureProvider.autoDispose<List<String>>(
  (ref) => ref.watch(opsRepositoryProvider).permissions(),
);

class StaffHome extends ConsumerStatefulWidget {
  const StaffHome({super.key});

  @override
  ConsumerState<StaffHome> createState() => _StaffHomeState();
}

class _StaffHomeState extends ConsumerState<StaffHome> with OpsPolling {
  @override
  void initState() {
    super.initState();
    startPolling(_reload, every: const Duration(seconds: 60));
  }

  void _reload() {
    ref.invalidate(staffOverviewProvider);
    for (final s in _stages) {
      ref.invalidate(staffQueueProvider(s));
    }
    ref.invalidate(staffDriversProvider);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final overview = ref.watch(staffOverviewProvider);
    final o = overview.value;
    final perms = ref.watch(_staffPermissionsProvider).value ?? const <String>[];
    final canAddProduct = perms.contains('*') || perms.contains('catalog.create');
    ref.listen(staffEventsProvider, (_, next) {
      final e = next.value;
      if (e == null || e.event != 'dispatch.updated') return;
      _reload();
      if (e.data['kind'] == 'order' && e.data['status'] == 'pending') {
        Haptics.success();
        showInfoSnack(context, t.newOrderAlert('${e.data['number'] ?? ''}'));
      }
    });
    return DefaultTabController(
      length: 5,
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
            IconButton(
              tooltip: t.signOut,
              icon: const Icon(Icons.logout_rounded),
              onPressed: () => ref.read(opsSessionProvider.notifier).signOut(),
            ),
          ],
          bottom: TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [
              Tab(text: '${t.stageNew}${o == null ? '' : ' · ${o.fresh}'}'),
              Tab(text: '${t.stagePreparing}${o == null ? '' : ' · ${o.preparing}'}'),
              Tab(text: '${t.stageReady}${o == null ? '' : ' · ${o.ready}'}'),
              Tab(text: '${t.stageOut}${o == null ? '' : ' · ${o.out}'}'),
              Tab(text: t.drivers),
            ],
          ),
        ),
        body: overview.hasError && o == null
            ? ErrorState(error: overview.error!, onRetry: () => ref.invalidate(staffOverviewProvider))
            : Column(
                children: [
                  if (o != null) _OverviewBar(o: o),
                  Expanded(
                    child: TabBarView(
                      children: [
                        for (final s in _stages) _OrderList(stage: s, autoDispatch: o?.autoDispatch ?? false),
                        const _DriverList(),
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
          Row(
            children: [
              CountTile(label: t.waitingForDriver, value: o.waitingForDriver, highlight: o.waitingForDriver > 0),
              const Gap(AppSpace.xs),
              CountTile(label: t.driversOnDuty, value: o.driversOnDuty),
              const Gap(AppSpace.xs),
              CountTile(label: t.stageOut, value: o.out),
            ],
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
  const _OrderList({required this.stage, required this.autoDispatch});
  final String stage;
  final bool autoDispatch;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final rows = ref.watch(staffQueueProvider(stage));
    return RefreshIndicator(
      onRefresh: () async {
        ref.invalidate(staffOverviewProvider);
        ref.invalidate(staffQueueProvider(stage));
        await ref.read(staffQueueProvider(stage).future);
      },
      child: rows.when(
        skipLoadingOnRefresh: true,
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffQueueProvider(stage))),
        data: (list) => list.isEmpty
            ? ListView(
                children: [
                  const Gap(AppSpace.xxl),
                  EmptyState(icon: Icons.inbox_outlined, title: t.noOrders, message: '', compact: true),
                ],
              )
            : ListView.separated(
                padding: const EdgeInsets.all(AppSpace.gutter),
                itemCount: list.length,
                separatorBuilder: (_, _) => const Gap(AppSpace.sm),
                itemBuilder: (_, i) => _OrderCard(order: list[i], autoDispatch: autoDispatch),
              ),
      ),
    );
  }
}

class _OrderCard extends ConsumerStatefulWidget {
  const _OrderCard({required this.order, required this.autoDispatch});
  final QueueOrder order;
  final bool autoDispatch;

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
  }

  Future<void> _advance(String status) async {
    setState(() => _busy = true);
    final ok = await guarded(
      context,
      () => ref.read(opsRepositoryProvider).advance(widget.order.fulfillmentId, status),
    );
    if (ok) _refreshAll();
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _assign() async {
    final driver = await showModalBottomSheet<BranchDriver>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (_) => const _DriverPicker(),
    );
    if (driver == null || !mounted) return;
    setState(() => _busy = true);
    final ok = await guarded(
      context,
      () => ref.read(opsRepositoryProvider).assign(widget.order.fulfillmentId, driver.id),
    );
    if (ok) {
      _refreshAll();
      ref.invalidate(staffDriversProvider);
    }
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final o = widget.order;
    final step = o.nextStep;
    final stepLabel = switch (step) {
      'accepted' => t.acceptOrder,
      'preparing' => t.startPreparing,
      'ready' => t.markReady,
      _ => null,
    };
    final hasDriver = o.driverName != null;
    final showAssign = o.status == 'ready' && o.deliveryStatus != 'picked_up' && o.deliveryStatus != 'out_for_delivery';
    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text('#${o.number}', style: context.text.titleMedium)),
              Text(
                'QAR ${o.total}',
                style: context.text.titleSmall?.copyWith(color: context.colors.primary, fontWeight: FontWeight.w700),
              ),
            ],
          ),
          const Gap(2),
          Text('${o.customerName} · ${t.items(o.itemsCount)}', style: context.text.bodyMedium),
          Text(o.address.line, style: context.text.bodySmall),
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
          if (stepLabel != null || showAssign) ...[
            const Gap(AppSpace.sm),
            Row(
              children: [
                if (stepLabel != null)
                  Expanded(
                    child: FilledButton(
                      onPressed: _busy ? null : () => _advance(step!),
                      child: _busy
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white),
                            )
                          : Text(stepLabel),
                    ),
                  ),
                if (showAssign)
                  Expanded(
                    child: (hasDriver || widget.autoDispatch ? OutlinedButton.new : FilledButton.new)(
                      onPressed: _busy ? null : _assign,
                      child: Text(hasDriver ? t.changeDriver : t.assignDriver),
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
    return ListTile(
      onTap: onTap,
      leading: CircleAvatar(
        backgroundColor: driver.onDuty ? AppPalette.successTint : AppPalette.sand,
        child: Icon(Icons.two_wheeler_rounded, color: driver.onDuty ? AppPalette.success : AppPalette.muted),
      ),
      title: Text(driver.name),
      subtitle: Text(driver.onDuty ? t.openJobs(driver.openJobs) : t.offDutyTag),
      trailing: driver.phone == null
          ? null
          : IconButton(icon: const Icon(Icons.call_rounded), onPressed: () => dial(driver.phone)),
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
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffDriversProvider)),
        data: (list) => list.isEmpty
            ? ListView(
                children: [
                  const Gap(AppSpace.xxl),
                  EmptyState(icon: Icons.two_wheeler_rounded, title: t.noDrivers, message: '', compact: true),
                ],
              )
            : ListView(
                padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
                children: [for (final d in list) _DriverTile(driver: d)],
              ),
      ),
    );
  }
}
