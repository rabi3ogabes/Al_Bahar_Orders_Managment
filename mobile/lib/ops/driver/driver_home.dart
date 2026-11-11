import 'dart:io';

import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

final driverMeProvider = FutureProvider.autoDispose<DriverMe>((ref) => ref.watch(opsRepositoryProvider).driver());

/// Real-time pings for this driver (`private-drivers.{id}`): a job was assigned or changed.
final driverEventsProvider = StreamProvider.autoDispose<PusherEvent>((ref) {
  final id = ref.watch(driverMeProvider.select((m) => m.value?.id));
  if (id == null || id.isEmpty) return const Stream.empty();
  return ref.watch(pusherClientProvider).channel('private-drivers.$id');
});

final driverJobsProvider = FutureProvider.autoDispose.family<List<DriverJob>, bool>(
  (ref, history) => ref.watch(opsRepositoryProvider).jobs(history: history),
);

/// Driver app home: duty switch, my deliveries and history. Jobs arrive here on their own (auto dispatch) or when a
/// branch employee assigns them.
class DriverHome extends ConsumerStatefulWidget {
  const DriverHome({super.key});

  @override
  ConsumerState<DriverHome> createState() => _DriverHomeState();
}

class _DriverHomeState extends ConsumerState<DriverHome> with OpsPolling {
  int _beat = 0;

  @override
  void initState() {
    super.initState();
    startPolling(every: const Duration(seconds: 60), () {
      ref.invalidate(driverJobsProvider(false));
      ref.invalidate(driverMeProvider);
      // Every third tick (about a minute) tell the server where this driver is while on duty.
      if (++_beat % 3 == 0) _sendPosition();
    });
  }

  Future<void> _sendPosition() async {
    if (ref.read(driverMeProvider).value?.onDuty != true) return;
    final pos = await ref.read(locationServiceProvider).currentPosition();
    if (pos == null) return;
    try {
      await ref.read(opsRepositoryProvider).heartbeat(pos.lat, pos.lng);
    } catch (_) {}
  }

  Future<void> _toggleDuty(bool on) async {
    final location = ref.read(locationServiceProvider);
    ({double lat, double lng})? pos;
    if (on && await location.ensurePermission() == LocationAccess.granted) pos = await location.currentPosition();
    if (!mounted) return;
    final ok = await guarded(context, () => ref.read(opsRepositoryProvider).setDuty(on, lat: pos?.lat, lng: pos?.lng));
    if (ok) {
      ref
        ..invalidate(driverMeProvider)
        ..invalidate(driverJobsProvider(false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final me = ref.watch(driverMeProvider);
    ref.listen(driverEventsProvider, (_, next) {
      final e = next.value;
      if (e == null || e.event != 'dispatch.updated') return;
      ref
        ..invalidate(driverJobsProvider(false))
        ..invalidate(driverMeProvider);
      if (e.data['status'] == 'assigned') {
        Haptics.success();
        showInfoSnack(context, t.newJobAlert('${e.data['number'] ?? ''}'));
      }
    });
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Row(
            children: [
              const OpsEmblem(role: OpsRole.driver, size: 34),
              const Gap(AppSpace.xs),
              Text(t.driverApp),
            ],
          ),
          actions: [
            IconButton(
              tooltip: t.signOut,
              icon: const Icon(Icons.logout_rounded),
              onPressed: () => ref.read(opsSessionProvider.notifier).signOut(),
            ),
          ],
          bottom: TabBar(
            tabs: [
              Tab(text: t.myJobs),
              Tab(text: t.history),
            ],
          ),
        ),
        body: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, 0),
              child: _DutyCard(me: me.value, onToggle: _toggleDuty),
            ),
            const Expanded(child: TabBarView(children: [_JobList(history: false), _JobList(history: true)])),
          ],
        ),
      ),
    );
  }
}

class _DutyCard extends StatelessWidget {
  const _DutyCard({required this.me, required this.onToggle});
  final DriverMe? me;
  final ValueChanged<bool> onToggle;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final on = me?.onDuty ?? false;
    return SurfaceCard(
      color: on ? AppPalette.tint : null,
      child: Row(
        children: [
          Container(
            width: 12,
            height: 12,
            decoration: BoxDecoration(shape: BoxShape.circle, color: on ? AppPalette.success : AppPalette.muted),
          ),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(on ? t.onDuty : t.offDuty, style: context.text.titleMedium),
                Text(me == null ? '' : (on ? t.dutyOnHint : t.dutyOffHint), style: context.text.bodySmall),
                if (me != null && me!.branches.isNotEmpty)
                  Text(me!.branches.join(' · '), style: context.text.bodySmall),
              ],
            ),
          ),
          Switch(value: on, onChanged: me == null ? null : onToggle),
        ],
      ),
    );
  }
}

class _JobList extends ConsumerWidget {
  const _JobList({required this.history});
  final bool history;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final jobs = ref.watch(driverJobsProvider(history));
    return RefreshIndicator(
      onRefresh: () => ref.refresh(driverJobsProvider(history).future),
      child: jobs.when(
        skipLoadingOnRefresh: true,
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(driverJobsProvider(history))),
        data: (list) => list.isEmpty
            ? ListView(
                children: [
                  const Gap(AppSpace.xxl),
                  EmptyState(
                    icon: Icons.two_wheeler_rounded,
                    title: t.noJobs,
                    message: history ? '' : t.noJobsHint,
                    compact: true,
                  ),
                ],
              )
            : ListView.separated(
                padding: const EdgeInsets.all(AppSpace.gutter),
                itemCount: list.length,
                separatorBuilder: (_, _) => const Gap(AppSpace.sm),
                itemBuilder: (_, i) => _JobCard(job: list[i], readOnly: history),
              ),
      ),
    );
  }
}

class _JobCard extends ConsumerStatefulWidget {
  const _JobCard({required this.job, required this.readOnly});
  final DriverJob job;
  final bool readOnly;

  @override
  ConsumerState<_JobCard> createState() => _JobCardState();
}

class _JobCardState extends ConsumerState<_JobCard> {
  bool _busy = false;

  void _refresh() {
    ref
      ..invalidate(driverJobsProvider(false))
      ..invalidate(driverJobsProvider(true))
      ..invalidate(driverMeProvider);
  }

  Future<void> _step(String action) async {
    setState(() => _busy = true);
    final pos = action == 'out-for-delivery' ? await ref.read(locationServiceProvider).currentPosition() : null;
    if (!mounted) return;
    final ok = await guarded(
      context,
      () => ref.read(opsRepositoryProvider).jobStep(widget.job.id, action, lat: pos?.lat, lng: pos?.lng),
    );
    if (ok) _refresh();
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _deliver() async {
    final result = await showModalBottomSheet<_DeliverResult>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _DeliverSheet(job: widget.job),
    );
    if (result == null || !mounted) return;
    setState(() => _busy = true);
    final ok = await guarded(
      context,
      () => ref
          .read(opsRepositoryProvider)
          .deliver(widget.job.id, recipient: result.recipient, proof: result.photo, codAmount: result.cod),
    );
    if (ok) _refresh();
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _fail() async {
    final t = OpsStrings.of(context);
    final reason = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.all(AppSpace.md),
              child: Text(t.reason, style: Theme.of(ctx).textTheme.titleMedium),
            ),
            for (final r in const [
              'customer_unreachable',
              'customer_absent',
              'wrong_address',
              'refused',
              'access_denied',
              'other',
            ])
              ListTile(title: Text(t.reasonLabel(r)), onTap: () => Navigator.pop(ctx, r)),
          ],
        ),
      ),
    );
    if (reason == null || !mounted) return;
    setState(() => _busy = true);
    final ok = await guarded(context, () => ref.read(opsRepositoryProvider).fail(widget.job.id, reason));
    if (ok) _refresh();
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final job = widget.job;
    final next = widget.readOnly ? null : job.nextAction;
    final label = switch (next) {
      'accept' => t.accept,
      'pickup' => t.pickedUp,
      'out-for-delivery' => t.onTheWay,
      'deliver' => t.delivered,
      _ => null,
    };
    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text('#${job.orderNumber}', style: context.text.titleMedium)),
              StatusChip(label: t.statusLabel(job.status), tone: toneForOpsStatus(job.status)),
            ],
          ),
          const Gap(AppSpace.xs),
          Text(job.customerName, style: context.text.titleSmall),
          const Gap(2),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.place_outlined, size: 18, color: context.palette.muted),
              const Gap(AppSpace.xxs),
              Expanded(child: Text(job.address.line, style: context.text.bodyMedium)),
            ],
          ),
          if (job.address.notes != null)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(job.address.notes!, style: context.text.bodySmall),
            ),
          if (job.isCod)
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xs),
              child: StatusChip(
                label: '${t.cod}: QAR ${job.codAmount.toStringAsFixed(2)}',
                tone: StatusTone.warning,
                icon: Icons.payments_outlined,
              ),
            ),
          if (!widget.readOnly) ...[
            const Gap(AppSpace.sm),
            Row(
              children: [
                OutlinedButton.icon(
                  onPressed: job.customerPhone == null ? null : () => dial(job.customerPhone),
                  icon: const Icon(Icons.call_rounded, size: 18),
                  label: Text(t.call),
                ),
                const Gap(AppSpace.xs),
                OutlinedButton.icon(
                  onPressed: () => openMaps(lat: job.address.lat, lng: job.address.lng, query: job.address.line),
                  icon: const Icon(Icons.navigation_rounded, size: 18),
                  label: Text(t.navigate),
                ),
              ],
            ),
            if (label != null) ...[
              const Gap(AppSpace.sm),
              Row(
                children: [
                  Expanded(
                    child: FilledButton(
                      onPressed: _busy ? null : () => next == 'deliver' ? _deliver() : _step(next!),
                      child: _busy
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white),
                            )
                          : Text(label),
                    ),
                  ),
                  if (job.status == 'out_for_delivery' || job.status == 'picked_up') ...[
                    const Gap(AppSpace.xs),
                    IconButton.outlined(
                      tooltip: t.failed,
                      onPressed: _busy ? null : _fail,
                      icon: const Icon(Icons.report_gmailerrorred_rounded),
                    ),
                  ],
                ],
              ),
            ],
          ],
        ],
      ),
    );
  }
}

class _DeliverResult {
  const _DeliverResult({this.recipient, this.photo, this.cod});
  final String? recipient;
  final File? photo;
  final double? cod;
}

class _DeliverSheet extends StatefulWidget {
  const _DeliverSheet({required this.job});
  final DriverJob job;

  @override
  State<_DeliverSheet> createState() => _DeliverSheetState();
}

class _DeliverSheetState extends State<_DeliverSheet> {
  final _recipient = TextEditingController();
  late final _cash = TextEditingController(text: widget.job.codAmount.toStringAsFixed(2));
  File? _photo;
  String? _error;

  @override
  void dispose() {
    _recipient.dispose();
    _cash.dispose();
    super.dispose();
  }

  Future<void> _shoot() async {
    final shot = await ImagePicker().pickImage(source: ImageSource.camera, maxWidth: 1600, imageQuality: 80);
    if (shot != null) setState(() => _photo = File(shot.path));
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(
        AppSpace.gutter,
        0,
        AppSpace.gutter,
        MediaQuery.viewInsetsOf(context).bottom + AppSpace.md,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(t.delivered, style: Theme.of(context).textTheme.titleLarge),
            const Gap(AppSpace.sm),
            TextField(
              controller: _recipient,
              decoration: InputDecoration(labelText: t.receivedBy),
            ),
            if (widget.job.isCod) ...[
              const Gap(AppSpace.sm),
              TextField(
                controller: _cash,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: '${t.cashCollected} (QAR)'),
              ),
            ],
            const Gap(AppSpace.sm),
            OutlinedButton.icon(
              onPressed: _shoot,
              icon: Icon(_photo == null ? Icons.photo_camera_outlined : Icons.check_circle_rounded),
              label: Text(_photo == null ? t.takePhoto : t.photoTaken),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(_error!, style: TextStyle(color: context.palette.danger)),
              ),
            const Gap(AppSpace.md),
            FilledButton(
              onPressed: () {
                if (widget.job.requiresProof && _photo == null) {
                  setState(() => _error = t.photoRequired);
                  return;
                }
                Navigator.pop(
                  context,
                  _DeliverResult(
                    recipient: _recipient.text.trim(),
                    photo: _photo,
                    cod: widget.job.isCod ? double.tryParse(_cash.text.replaceAll(',', '.')) : null,
                  ),
                );
              },
              child: Text(t.confirm),
            ),
          ],
        ),
      ),
    );
  }
}
