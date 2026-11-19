import 'dart:async';

import 'package:albahar_customer/core/realtime/live_gate.dart';
import 'package:albahar_customer/core/realtime/live_host.dart';
import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/ops/driver/driver_models.dart';
import 'package:albahar_customer/ops/driver/driver_tracker.dart';
import 'package:albahar_customer/ops/ops_board.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

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

/// "My day": today's jobs, the cash collected and the cash still held. Read when the screen opens and when the driver pulls to refresh.
final driverDayProvider = FutureProvider.autoDispose<DriverDay>((ref) => ref.watch(opsRepositoryProvider).day());

/// Why a job just left the driver's list without him doing anything: its order was cancelled, or it was given to another driver.
enum JobNoticeKind { cancelled, removed }

/// One thing the driver must be told once ("This order was cancelled. No need to go."), by the delivery it is about.
@immutable
class JobNotice {
  const JobNotice({required this.deliveryId, required this.number, required this.kind});
  final String deliveryId;
  final String number;
  final JobNoticeKind kind;
}

/// What the driver's screens know about the live link and about the jobs that moved since the last read of the server.
@immutable
class DriverLiveState {
  const DriverLiveState({
    required this.link,
    this.patches = const {},
    this.notices = const [],
    this.arrived = const {},
  });
  final LiveLink link;

  /// The newest message about each job, by delivery id (laid over the last read by [mergeJobs]).
  final Map<String, JobPatch> patches;

  /// Jobs that were cancelled or taken away, waiting to be shown to the driver once.
  final List<JobNotice> notices;

  /// Jobs the driver said "I have arrived" at, by delivery id: the job card shows it before the server's list is read again.
  final Map<String, DateTime> arrived;

  DriverLiveState copyWith({
    LiveLink? link,
    Map<String, JobPatch>? patches,
    List<JobNotice>? notices,
    Map<String, DateTime>? arrived,
  }) => DriverLiveState(
    link: link ?? this.link,
    patches: patches ?? this.patches,
    notices: notices ?? this.notices,
    arrived: arrived ?? this.arrived,
  );
}

/// The driver app's ONE real-time orchestrator (alive while the home screen is). It follows `private-drivers.{id}`; applies each message
/// at once (a job moves, finishes or is taken away without waiting for a read); confirms a burst of messages with one read; reads again
/// on every reconnect, return to the foreground and return of the network; and polls every 15 s only while the socket is down.
class DriverLive extends Notifier<DriverLiveState> {
  final _gate = SeqGate();
  late final LiveSync _sync;

  /// Jobs the driver took off his own list (he handed them back): the message that echoes it is not news to him.
  final _own = <String>{};

  /// Notices already made (job and kind): a message that arrives again, or after the dialog was closed, tells nothing new.
  final _told = <String>{};

  @override
  DriverLiveState build() {
    _sync = bindLiveSync(ref, resync: _reload);
    ref.listen(driverEventsProvider, (_, next) {
      final e = next.value;
      if (e != null) onEvent(e);
    });
    final links = _sync.links.listen((l) => state = state.copyWith(link: l));
    ref.onDispose(links.cancel);
    return DriverLiveState(link: _sync.link);
  }

  /// Applies one message of the driver's channel (public for tests).
  @visibleForTesting
  void onEvent(PusherEvent e) {
    if (e.event != 'dispatch.updated') return;
    final patch = JobPatch.fromEvent(e);
    if (patch == null) return;
    // What the last read showed counts as already seen: a message older than that is a latecomer.
    final rows = _rows();
    final row = rows.where((j) => j.id == patch.deliveryId).firstOrNull;
    _gate.seen(patch.deliveryId, row?.seq);
    if (!_gate.accept(patch.deliveryId, patch.seq)) return;
    final patches = {...state.patches, patch.deliveryId: patch}
      ..removeWhere((id, p) => rows.any((j) => j.id == id && j.seq > p.seq));
    // A job that comes back to him after he handed it back is his again: its later messages are news.
    if (patch.assigned) _own.remove(patch.deliveryId);
    final notice = _noticeFor(patch, known: row != null);
    // A job that moved on from the road (failed, delivered, tried again) starts its next arrival from scratch.
    final arrived = patch.status == 'out_for_delivery' || !state.arrived.containsKey(patch.deliveryId)
        ? null
        : ({...state.arrived}..remove(patch.deliveryId));
    state = state.copyWith(
      patches: patches,
      notices: notice == null ? null : [...state.notices, notice],
      arrived: arrived,
    );
    // A job this phone has never seen (just assigned): its details come from the server, now rather than after the burst.
    if (row == null && patch.assigned) _reload(LiveResync.event);
    _sync.eventArrived();
  }

  /// A cancelled job, or one taken away from the driver, is told to him once (never for a job he handed back himself, and never twice).
  JobNotice? _noticeFor(JobPatch p, {required bool known}) {
    final cancelled = p.assigned && p.status == 'cancelled';
    if (!cancelled && p.assigned) return null;
    if (_own.contains(p.deliveryId)) return null;
    // A cancelled job this phone had already seen as finished is old news (the list was read after the cancellation).
    if (cancelled && known && _rows().any((j) => j.id == p.deliveryId && j.status == 'cancelled')) return null;
    if (!_told.add('${p.deliveryId}:${cancelled ? 'c' : 'r'}')) return null;
    return JobNotice(
      deliveryId: p.deliveryId,
      number: p.number,
      kind: cancelled ? JobNoticeKind.cancelled : JobNoticeKind.removed,
    );
  }

  /// The driver saw the notice: it is not shown again.
  void dismissNotice(String deliveryId) {
    state = state.copyWith(
      notices: [
        for (final n in state.notices)
          if (n.deliveryId != deliveryId) n,
      ],
    );
  }

  /// The driver handed this job back himself: it leaves his list at once and he is not told it "moved".
  void applyOwnRemoval(String deliveryId) {
    _own.add(deliveryId);
    final job = _rows().where((j) => j.id == deliveryId).firstOrNull;
    final seq = _gate.lastOf(deliveryId) ?? job?.seq ?? 0;
    final patch = JobPatch(
      deliveryId: deliveryId,
      orderId: job?.orderId ?? '',
      number: job?.orderNumber ?? '',
      seq: seq,
      status: 'pending',
      assigned: false,
    );
    state = state.copyWith(patches: {...state.patches, deliveryId: patch});
  }

  /// The driver said "I have arrived" and the server took it: the job card shows the next step at once.
  void markArrived(String deliveryId, [DateTime? at]) {
    state = state.copyWith(arrived: {...state.arrived, deliveryId: at ?? DateTime.now()});
  }

  /// What the driver just did, shown before the server's answer is read back (the message that echoes it then changes nothing).
  void applyOwnStep(String deliveryId, String status) {
    final job = _rows().where((j) => j.id == deliveryId).firstOrNull;
    final seq = _gate.lastOf(deliveryId) ?? job?.seq ?? 0;
    final patch = JobPatch(
      deliveryId: deliveryId,
      orderId: job?.orderId ?? '',
      number: job?.orderNumber ?? '',
      seq: seq,
      status: status,
      assigned: true,
    );
    state = state.copyWith(patches: {...state.patches, deliveryId: patch});
  }

  List<DriverJob> _rows() => [
    ...?ref.read(driverJobsProvider(false)).value,
    ...?ref.read(driverJobsProvider(true)).value,
  ];

  void _reload(LiveResync reason) {
    ref.invalidate(driverJobsProvider(false));
    ref.invalidate(driverJobsProvider(true));
    ref.invalidate(driverMeProvider);
  }
}

final driverLiveProvider = NotifierProvider.autoDispose<DriverLive, DriverLiveState>(DriverLive.new);

/// The job list as the screens show it: the last read of the server with the real-time messages laid over it, so a job moves, finishes
/// or disappears the moment the message arrives. Active jobs, or the history (a delivered job is already at its top).
final driverJobsViewProvider = Provider.autoDispose.family<AsyncValue<List<DriverJob>>, bool>((ref, history) {
  final own = ref.watch(driverJobsProvider(history));
  final patches = ref.watch(driverLiveProvider.select((s) => s.patches));
  final arrived = ref.watch(driverLiveProvider.select((s) => s.arrived));
  AsyncValue<List<DriverJob>> merged = own;
  if (patches.isNotEmpty && own.hasValue) {
    final other = ref.watch(driverJobsProvider(!history));
    final active = history ? other : own;
    final past = history ? own : other;
    merged = own.whenData(
      (_) => mergeJobs(
        active: active.value ?? const [],
        history: past.value ?? const [],
        patches: patches,
        wantHistory: history,
      ),
    );
  }
  if (arrived.isEmpty || !merged.hasValue) return merged;
  return merged.whenData(
    (rows) => [for (final j in rows) arrived.containsKey(j.id) ? j.withArrived(arrived[j.id]!) : j],
  );
});

/// The job list as a window sees it: the rows, and whether the list is up to date (so a window can tell "this job left
/// my list" from "the list is still reloading").
final driverLiveJobsProvider = Provider.autoDispose.family<LiveList<DriverJob>, bool>(
  (ref, history) => liveOf(ref.watch(driverJobsViewProvider(history))),
);

/// Builds the location tracker (replaced in tests so no GPS or network is touched).
final driverTrackerProvider = Provider<DriverTracker>((ref) {
  final tracker = DriverTracker(ref.watch(opsRepositoryProvider));
  ref.onDispose(() => unawaited(tracker.dispose()));
  return tracker;
});
