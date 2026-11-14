import 'dart:async';

import 'package:albahar_customer/core/map/map_nav.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/ops/driver/driver_job_map.dart';
import 'package:albahar_customer/ops/driver/driver_job_panels.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/driver/driver_tracker.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

export 'package:albahar_customer/ops/driver/driver_providers.dart';

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
      channelName: t.trackingChannel,
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
  const _JobCard({super.key, required this.job, required this.readOnly, this.embedded = false});
  final DriverJob job;
  final bool readOnly;

  /// Shown inside the delivery map: the map and Navigate buttons are the screen's own, and the card is not opened in a
  /// window (the map is already the delivery's own screen).
  final bool embedded;

  @override
  ConsumerState<_JobCard> createState() => _JobCardState();
}

class _JobCardState extends ConsumerState<_JobCard> {
  void _openMap() {
    final tracker = ref.read(driverTrackerProvider);
    unawaited(
      Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => _JobMapRoute(job: widget.job, tracker: tracker),
        ),
      ),
    );
  }

  /// The whole card opens the delivery in a window beside the list; the list keeps its place and Back closes only the
  /// window. The map is a full screen of its own, so the window hands over to it.
  Future<void> _openDetails() async {
    final result = await showJobPanel(context, job: widget.job, readOnly: widget.readOnly);
    if (result == JobPanelResult.openMap && mounted) _openMap();
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final job = widget.job;
    final when = job.scheduledFor == null
        ? null
        : QatarTime.dateTime(job.scheduledFor, Localizations.localeOf(context).languageCode);
    return SurfaceCard(
      onTap: widget.embedded ? null : _openDetails,
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
              if (!widget.readOnly && !widget.embedded)
                IconButton(tooltip: t.jobMap, icon: const Icon(Icons.map_outlined), onPressed: _openMap),
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
                if (job.alternatePhone != null) ...[
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Semantics(
                      label: t.callAlternative,
                      button: true,
                      excludeSemantics: true,
                      child: OutlinedButton.icon(
                        key: const ValueKey('card-call-alternative'),
                        onPressed: () => dial(context, job.alternatePhone),
                        icon: const Icon(Icons.phone_forwarded_rounded, size: 18),
                        label: Text(t.alternativeShort, maxLines: 1, overflow: TextOverflow.ellipsis),
                      ),
                    ),
                  ),
                ],
                if (!widget.embedded) ...[
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => openMaps(
                        context,
                        lat: job.address.lat,
                        lng: job.address.lng,
                        query: job.address.line,
                        launcher: ref.read(navigationLauncherProvider),
                      ),
                      icon: const Icon(Icons.navigation_rounded, size: 18),
                      label: Text(t.navigate, maxLines: 1, overflow: TextOverflow.ellipsis),
                    ),
                  ),
                ],
              ],
            ),
            JobActionButtons(key: ValueKey('steps-${job.id}'), job: job),
          ],
        ],
      ),
    );
  }
}

/// The delivery map route: keeps the job fresh from the job list (a step taken on this screen moves the card on) and
/// closes itself when the delivery has left the active list (delivered, reassigned or cancelled).
class _JobMapRoute extends ConsumerWidget {
  const _JobMapRoute({required this.job, required this.tracker});
  final DriverJob job;
  final DriverTracker tracker;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final jobs = ref.watch(driverJobsProvider(false));
    final live = jobs.value?.where((j) => j.id == job.id).firstOrNull;
    if (jobs.hasValue && live == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (context.mounted) unawaited(Navigator.of(context).maybePop());
      });
    }
    final current = live ?? job;
    return DriverJobMapScreen(
      job: current,
      fixes: tracker.fixes,
      initialFix: tracker.lastFix,
      onNavigate: () => unawaited(
        openMaps(
          context,
          lat: current.address.lat,
          lng: current.address.lng,
          query: current.address.line,
          launcher: ref.read(navigationLauncherProvider),
        ),
      ),
      card: _JobCard(key: ValueKey('map-${current.id}'), job: current, readOnly: false, embedded: true),
    );
  }
}
