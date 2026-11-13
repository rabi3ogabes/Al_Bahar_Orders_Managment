import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/ops/driver/driver_tracker.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_input.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
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

/// Builds the location tracker (replaced in tests so no GPS or network is touched).
final driverTrackerProvider = Provider<DriverTracker>((ref) => DriverTracker(ref.watch(opsRepositoryProvider)));

/// Driver app home: duty switch, my deliveries and history. Jobs arrive here on their own (auto dispatch) or when a
/// branch employee assigns them.
class DriverHome extends ConsumerStatefulWidget {
  const DriverHome({super.key});

  @override
  ConsumerState<DriverHome> createState() => _DriverHomeState();
}

class _DriverHomeState extends ConsumerState<DriverHome> with OpsPolling {
  late final DriverTracker _tracker;
  LocationAccess? _locationIssue;
  bool _dutyBusy = false;

  @override
  void initState() {
    super.initState();
    // Read once here: `ref` cannot be used while the widget is being disposed.
    _tracker = ref.read(driverTrackerProvider);
    startPolling(every: const Duration(seconds: 60), () {
      ref.invalidate(driverJobsProvider(false));
      ref.invalidate(driverMeProvider);
      // Coming back from the phone settings: pick up a permission or GPS change right away.
      unawaited(_syncTracking(_onDuty));
    });
  }

  @override
  void dispose() {
    unawaited(_tracker.stop());
    super.dispose();
  }

  bool get _onDuty => ref.read(driverMeProvider).value?.onDuty ?? false;

  /// Live tracking runs exactly while the driver is on duty and location is allowed.
  Future<void> _syncTracking(bool onDuty) async {
    if (!onDuty) {
      await _tracker.stop();
      if (mounted && _locationIssue != null) setState(() => _locationIssue = null);
      return;
    }
    final access = await ref.read(locationServiceProvider).ensurePermission();
    if (!mounted) return;
    if (access != LocationAccess.granted) {
      await _tracker.stop();
      if (mounted) setState(() => _locationIssue = access);
      return;
    }
    if (_locationIssue != null) setState(() => _locationIssue = null);
    if (_tracker.running) return;
    final t = OpsStrings.of(context);
    await _tracker.start(
      title: t.trackingTitle,
      body: t.trackingBody,
      onError: (_) {
        // The location stream broke (permission taken away or GPS off): say so instead of silently going dark.
        unawaited(_tracker.stop());
        if (mounted) setState(() => _locationIssue = LocationAccess.denied);
      },
    );
  }

  Future<void> _toggleDuty(bool on) async {
    if (_dutyBusy) return;
    setState(() => _dutyBusy = true);
    final location = ref.read(locationServiceProvider);
    ({double lat, double lng})? pos;
    if (on && await location.ensurePermission() == LocationAccess.granted) {
      // Never keep the switch waiting for a slow GPS fix: the tracker reports the position a moment later.
      pos = await location.currentPosition().timeout(const Duration(seconds: 4), onTimeout: () => null);
    }
    if (!mounted) return;
    final ok = await guarded(context, () => ref.read(opsRepositoryProvider).setDuty(on, lat: pos?.lat, lng: pos?.lng));
    if (!mounted) return;
    if (ok) {
      ref
        ..invalidate(driverMeProvider)
        ..invalidate(driverJobsProvider(false));
      // The duty answer is in: start or stop the tracker now rather than waiting for the reload.
      await _syncTracking(on);
    }
    if (mounted) setState(() => _dutyBusy = false);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final me = ref.watch(driverMeProvider);
    ref.listen(driverMeProvider.select((m) => m.value?.onDuty), (_, on) {
      if (on != null) unawaited(_syncTracking(on));
    });
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
              Flexible(child: Text(t.driverApp, overflow: TextOverflow.ellipsis)),
            ],
          ),
          actions: const [OpsAccountMenu()],
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
              child: _DutyCard(me: me.value, busy: _dutyBusy, onToggle: _toggleDuty),
            ),
            if (_locationIssue != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.xs, AppSpace.gutter, 0),
                child: _LocationIssueCard(
                  onOpenSettings: () => unawaited(ref.read(locationServiceProvider).openSettings()),
                ),
              ),
            const Expanded(child: TabBarView(children: [_JobList(history: false), _JobList(history: true)])),
          ],
        ),
      ),
    );
  }
}

class _LocationIssueCard extends StatelessWidget {
  const _LocationIssueCard({required this.onOpenSettings});
  final VoidCallback onOpenSettings;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final scheme = Theme.of(context).colorScheme;
    return SurfaceCard(
      color: scheme.errorContainer,
      child: Row(
        children: [
          Icon(Icons.location_off_rounded, color: scheme.onErrorContainer),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(t.locationOffTitle, style: context.text.titleSmall?.copyWith(color: scheme.onErrorContainer)),
                Text(t.locationOffBody, style: context.text.bodySmall?.copyWith(color: scheme.onErrorContainer)),
              ],
            ),
          ),
          TextButton(onPressed: onOpenSettings, child: Text(t.openSettings)),
        ],
      ),
    );
  }
}

class _DutyCard extends StatelessWidget {
  const _DutyCard({required this.me, required this.busy, required this.onToggle});
  final DriverMe? me;
  final bool busy;
  final ValueChanged<bool> onToggle;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final p = context.palette;
    final on = me?.onDuty ?? false;
    return SurfaceCard(
      color: on ? p.tint : null,
      child: Row(
        children: [
          Container(
            width: 12,
            height: 12,
            decoration: BoxDecoration(shape: BoxShape.circle, color: on ? p.success : p.muted),
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
          if (busy)
            const SizedBox(width: 48, height: 48, child: Center(child: CircularProgressIndicator(strokeWidth: 2.4)))
          else
            Semantics(
              label: on ? t.onDuty : t.offDuty,
              child: Switch(value: on, onChanged: me == null ? null : onToggle),
            ),
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
    return OpsAsyncList<DriverJob>(
      value: ref.watch(driverJobsProvider(history)),
      onRefresh: () => ref.refresh(driverJobsProvider(history).future),
      onRetry: () => ref.invalidate(driverJobsProvider(history)),
      emptyIcon: Icons.two_wheeler_rounded,
      emptyTitle: t.noJobs,
      emptyMessage: history ? '' : t.noJobsHint,
      itemBuilder: (_, job) => _JobCard(key: ValueKey('${history ? 'h' : 'a'}-${job.id}'), job: job, readOnly: history),
    );
  }
}

class _JobCard extends ConsumerStatefulWidget {
  const _JobCard({super.key, required this.job, required this.readOnly});
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

  /// One action at a time. The list is reloaded whether it worked or not: a refused step (the job was reassigned or
  /// already moved) leaves the card showing the truth instead of a stale button.
  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() => _busy = true);
    await guarded(context, action);
    if (!mounted) return;
    _refresh();
    setState(() => _busy = false);
  }

  Future<void> _step(String action) async {
    if (_busy) return;
    final location = ref.read(locationServiceProvider);
    await _run(() async {
      final pos = action == 'out-for-delivery'
          ? await location.currentPosition().timeout(const Duration(seconds: 4), onTimeout: () => null)
          : null;
      await ref.read(opsRepositoryProvider).jobStep(widget.job.id, action, lat: pos?.lat, lng: pos?.lng);
    });
  }

  Future<void> _deliver() async {
    final done = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      isDismissible: false,
      enableDrag: false,
      builder: (_) => _DeliverSheet(job: widget.job),
    );
    if (done == true && mounted) _refresh();
  }

  Future<void> _fail() async {
    final t = OpsStrings.of(context);
    final reason = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      isScrollControlled: true,
      builder: (ctx) => SingleChildScrollView(
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
              ListTile(minTileHeight: 52, title: Text(t.reasonLabel(r)), onTap: () => Navigator.pop(ctx, r)),
          ],
        ),
      ),
    );
    if (reason == null || !mounted) return;
    final sure = await _confirm(t.confirmFailQ, t.confirmFailBody(t.reasonLabel(reason)), t.confirm);
    if (!sure || !mounted) return;
    await _run(() => ref.read(opsRepositoryProvider).fail(widget.job.id, reason));
  }

  Future<void> _returnToBranch() async {
    final t = OpsStrings.of(context);
    if (!await _confirm(t.confirmReturnQ, t.confirmReturnBody, t.confirm) || !mounted) return;
    await _run(() => ref.read(opsRepositoryProvider).returnToBranch(widget.job.id));
  }

  Future<bool> _confirm(String title, String body, String yes) async {
    final t = OpsStrings.of(context);
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(autofocus: true, onPressed: () => Navigator.pop(c, false), child: Text(t.cancel)),
          TextButton(onPressed: () => Navigator.pop(c, true), child: Text(yes)),
        ],
      ),
    );
    return ok ?? false;
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final job = widget.job;
    final next = widget.readOnly ? null : job.nextAction;
    final retry = job.status == 'failed' || job.status == 'rescheduled';
    final label = switch (next) {
      'accept' => t.accept,
      'pickup' => t.pickedUp,
      'out-for-delivery' => retry ? t.retryDelivery : t.onTheWay,
      'deliver' => t.delivered,
      _ => null,
    };
    final when = job.scheduledFor == null
        ? null
        : QatarTime.dateTime(job.scheduledFor, Localizations.localeOf(context).languageCode);
    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text('#${job.orderNumber}', style: context.text.titleMedium)),
              const Gap(AppSpace.xs),
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
              Expanded(child: Text(t.address(job.address), style: context.text.bodyMedium)),
            ],
          ),
          if (job.address.notes != null)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(job.address.notes!, style: context.text.bodySmall),
            ),
          if (when != null && !widget.readOnly)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Row(
                children: [
                  Icon(Icons.schedule_rounded, size: 16, color: context.palette.muted),
                  const Gap(AppSpace.xxs),
                  Expanded(child: Text(t.scheduledFor(when), style: context.text.bodySmall)),
                ],
              ),
            ),
          if (job.isCod)
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xs),
              child: StatusChip(
                label: '${t.cod}: ${t.money(job.codAmount.toStringAsFixed(2))}',
                tone: StatusTone.warning,
                icon: Icons.payments_outlined,
              ),
            ),
          if (!widget.readOnly) ...[
            const Gap(AppSpace.sm),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: job.customerPhone == null ? null : () => dial(context, job.customerPhone),
                    icon: const Icon(Icons.call_rounded, size: 18),
                    label: Text(t.call, maxLines: 1, overflow: TextOverflow.ellipsis),
                  ),
                ),
                const Gap(AppSpace.xs),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () =>
                        openMaps(context, lat: job.address.lat, lng: job.address.lng, query: job.address.line),
                    icon: const Icon(Icons.navigation_rounded, size: 18),
                    label: Text(t.navigate, maxLines: 1, overflow: TextOverflow.ellipsis),
                  ),
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
                          ? SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.2,
                                color: Theme.of(context).colorScheme.onPrimary,
                              ),
                            )
                          : Text(label, textAlign: TextAlign.center),
                    ),
                  ),
                  if (job.canFail) ...[
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
            if (job.canReturn) ...[
              const Gap(AppSpace.xs),
              OutlinedButton.icon(
                onPressed: _busy ? null : _returnToBranch,
                icon: const Icon(Icons.assignment_return_rounded, size: 18),
                label: Text(t.returnToBranch),
              ),
            ],
          ],
        ],
      ),
    );
  }
}

/// Proof of delivery: who received it, the cash (cash-on-delivery) and a photo. The upload happens inside the sheet, so a
/// network failure keeps the photo and the typed text for another try instead of losing them.
class _DeliverSheet extends ConsumerStatefulWidget {
  const _DeliverSheet({required this.job});
  final DriverJob job;

  @override
  ConsumerState<_DeliverSheet> createState() => _DeliverSheetState();
}

class _DeliverSheetState extends ConsumerState<_DeliverSheet> {
  final _recipient = TextEditingController();
  late final _cash = TextEditingController(text: widget.job.codAmount.toStringAsFixed(2));
  File? _photo;
  String? _error;
  bool _busy = false;

  bool get _dirty => _photo != null || _recipient.text.trim().isNotEmpty;

  @override
  void dispose() {
    _recipient.dispose();
    _cash.dispose();
    super.dispose();
  }

  Future<void> _shoot() async {
    final t = OpsStrings.of(context);
    try {
      final shot = await ref.read(opsImagePickerProvider).pickImage(source: ImageSource.camera, maxWidth: 1600, imageQuality: 80);
      if (shot != null && mounted) {
        setState(() {
          _photo = File(shot.path);
          _error = null;
        });
      }
    } catch (_) {
      // Camera permission refused or no camera: say what to do instead of failing silently.
      if (mounted) setState(() => _error = t.photoFailed);
    }
  }

  Future<void> _submit() async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    final job = widget.job;
    if (job.requiresProof && _photo == null) {
      setState(() => _error = t.photoRequired);
      return;
    }
    double? cod;
    if (job.isCod) {
      final text = _cash.text.trim();
      cod = parseAmount(text);
      if (text.isEmpty) {
        setState(() => _error = t.cashRequired);
        return;
      }
      if (cod == null) {
        setState(() => _error = t.cashInvalid);
        return;
      }
      if (cod > job.codAmount + 0.004) {
        setState(() => _error = t.cashTooMuch(job.codAmount.toStringAsFixed(2)));
        return;
      }
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(opsRepositoryProvider)
          .deliver(job.id, recipient: _recipient.text.trim(), proof: _photo, codAmount: cod);
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = describeError(context.l10n, e);
        });
      }
    }
  }

  Future<void> _close() async {
    if (_busy) return;
    final t = OpsStrings.of(context);
    if (_dirty) {
      final leave = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          title: Text(t.leaveQ),
          content: Text(t.leaveBody),
          actions: [
            TextButton(autofocus: true, onPressed: () => Navigator.pop(c, false), child: Text(t.stay)),
            TextButton(onPressed: () => Navigator.pop(c, true), child: Text(t.leave)),
          ],
        ),
      );
      if (leave != true || !mounted) return;
    }
    Navigator.pop(context, false);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) unawaited(_close());
      },
      child: Padding(
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
              Row(
                children: [
                  Expanded(child: Text(t.delivered, style: Theme.of(context).textTheme.titleLarge)),
                  IconButton(tooltip: t.close, icon: const Icon(Icons.close_rounded), onPressed: _busy ? null : _close),
                ],
              ),
              const Gap(AppSpace.xs),
              TextField(
                controller: _recipient,
                enabled: !_busy,
                maxLength: 80,
                textCapitalization: TextCapitalization.words,
                textInputAction: widget.job.isCod ? TextInputAction.next : TextInputAction.done,
                autofillHints: const [AutofillHints.name],
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(labelText: t.receivedBy),
              ),
              if (widget.job.isCod) ...[
                const Gap(AppSpace.sm),
                TextField(
                  controller: _cash,
                  enabled: !_busy,
                  maxLength: 12,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: const [AmountInputFormatter()],
                  textInputAction: TextInputAction.done,
                  decoration: InputDecoration(labelText: '${t.cashCollected} (${t.currency})'),
                ),
              ],
              const Gap(AppSpace.sm),
              OutlinedButton.icon(
                onPressed: _busy ? null : _shoot,
                icon: Icon(_photo == null ? Icons.photo_camera_outlined : Icons.check_circle_rounded),
                label: Text(_photo == null ? t.takePhoto : t.photoTaken),
              ),
              if (_photo != null)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpace.xs),
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(AppRadius.input),
                      child: Image.file(
                        _photo!,
                        width: 96,
                        height: 96,
                        fit: BoxFit.cover,
                        cacheWidth: 288,
                        errorBuilder: (_, _, _) => SizedBox(
                          width: 96,
                          height: 96,
                          child: Center(child: Text(t.photoBroken, textAlign: TextAlign.center)),
                        ),
                      ),
                    ),
                  ),
                ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Semantics(
                    liveRegion: true,
                    child: Text(_error!, style: TextStyle(color: context.palette.danger)),
                  ),
                ),
              const Gap(AppSpace.md),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.4,
                          color: Theme.of(context).colorScheme.onPrimary,
                        ),
                      )
                    : Text(t.confirm),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
