import 'dart:async';

import 'package:albahar_customer/core/realtime/live_gate.dart';
import 'package:albahar_customer/core/realtime/live_host.dart';
import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
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

/// What the driver's screens know about the live link and about the jobs that moved since the last read of the server.
@immutable
class DriverLiveState {
  const DriverLiveState({required this.link, this.patches = const {}});
  final LiveLink link;

  /// The newest message about each job, by delivery id (laid over the last read by [mergeJobs]).
  final Map<String, JobPatch> patches;

  DriverLiveState copyWith({LiveLink? link, Map<String, JobPatch>? patches}) =>
      DriverLiveState(link: link ?? this.link, patches: patches ?? this.patches);
}

/// The driver app's ONE real-time orchestrator (alive while the home screen is). It follows `private-drivers.{id}`; applies each message
/// at once (a job moves, finishes or is taken away without waiting for a read); confirms a burst of messages with one read; reads again
/// on every reconnect, return to the foreground and return of the network; and polls every 15 s only while the socket is down.
class DriverLive extends Notifier<DriverLiveState> {
  final _gate = SeqGate();
  late final LiveSync _sync;

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
    state = state.copyWith(patches: patches);
    // A job this phone has never seen (just assigned): its details come from the server, now rather than after the burst.
    if (row == null && patch.assigned) _reload(LiveResync.event);
    _sync.eventArrived();
  }

  /// What the driver just did, shown before the server's answer is read back (the message that echoes it then changes nothing).
  void applyOwnStep(String deliveryId, String status) {
    final job = _rows().where((j) => j.id == deliveryId).firstOrNull;
    final seq = _gate.lastOf(deliveryId) ?? job?.seq ?? 0;
    final patch = JobPatch(deliveryId: deliveryId, orderId: job?.orderId ?? '', number: job?.orderNumber ?? '', seq: seq, status: status, assigned: true);
    state = state.copyWith(patches: {...state.patches, deliveryId: patch});
  }

  List<DriverJob> _rows() => [...?ref.read(driverJobsProvider(false)).value, ...?ref.read(driverJobsProvider(true)).value];

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
  if (patches.isEmpty) return own;
  final other = ref.watch(driverJobsProvider(!history));
  final active = history ? other : own;
  final past = history ? own : other;
  if (!own.hasValue) return own;
  return own.whenData(
    (_) => mergeJobs(active: active.value ?? const [], history: past.value ?? const [], patches: patches, wantHistory: history),
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
