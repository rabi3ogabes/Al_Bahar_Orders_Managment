import 'package:albahar_customer/core/realtime/live_gate.dart';
import 'package:albahar_customer/core/realtime/live_host.dart';
import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/ops/ops_board.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final staffOverviewProvider = FutureProvider.autoDispose<StaffOverview>(
  (ref) => ref.watch(opsRepositoryProvider).overview(),
);

/// The order queue stages, in tab order.
const kStaffStages = ['new', 'preparing', 'ready', 'out', 'failed'];

final staffQueueProvider = FutureProvider.autoDispose.family<List<QueueOrder>, String>(
  (ref, stage) => ref.watch(opsRepositoryProvider).queue(stage),
);

/// What the staff screens know about the live link and about the orders that moved since the last read of the server.
@immutable
class StaffLiveState {
  const StaffLiveState({required this.link, this.patches = const {}});
  final LiveLink link;

  /// The newest message about each branch part, by fulfillment id (laid over the last read by [mergeStage]).
  final Map<String, PartPatch> patches;

  StaffLiveState copyWith({LiveLink? link, Map<String, PartPatch>? patches}) =>
      StaffLiveState(link: link ?? this.link, patches: patches ?? this.patches);
}

/// The branch staff app's ONE real-time orchestrator (alive while the home screen is). It follows `private-branches.{id}`; applies each
/// message at once (an order moves between stages, gets its driver, finishes; a brand-new order is read in straight away); confirms a
/// burst with one read; reads everything again on every reconnect, return to the foreground and return of the network (that fills any
/// gap); and polls every 15 s only while the socket is down.
class StaffLive extends Notifier<StaffLiveState> {
  final _gate = SeqGate();
  late final LiveSync _sync;

  @override
  StaffLiveState build() {
    _sync = bindLiveSync(ref, resync: _reload);
    ref.listen(staffEventsProvider, (_, next) {
      final e = next.value;
      if (e != null) onEvent(e);
    });
    final links = _sync.links.listen((l) => state = state.copyWith(link: l));
    ref.onDispose(links.cancel);
    return StaffLiveState(link: _sync.link);
  }

  /// Applies one message of the branch channel (public for tests). Driver positions on the same channel are not order messages.
  @visibleForTesting
  void onEvent(PusherEvent e) {
    if (e.event != 'dispatch.updated') return;
    final patch = PartPatch.fromEvent(e);
    if (patch == null) return;
    final rows = _rows();
    final row = rows.where((r) => r.fulfillmentId == patch.fulfillmentId).firstOrNull;
    _gate.seen(patch.fulfillmentId, row?.seq);
    if (!_gate.accept(patch.fulfillmentId, patch.seq)) return;
    state = state.copyWith(
      patches: {...state.patches, patch.fulfillmentId: patch}..removeWhere((id, p) => rows.any((r) => r.fulfillmentId == id && r.seq > p.seq)),
    );
    // An order this phone has never seen (just placed): its row comes from the server now, not after the burst.
    if (row == null && patch.stage != 'done') _reload(LiveResync.event);
    _sync.eventArrived();
  }

  /// What the employee just did, shown before the server's answer is read back (the message that echoes it then changes nothing).
  void applyOwn(String fulfillmentId, {String? status, String? deliveryStatus, String? driverId, String? driverName}) {
    final row = _rows().where((r) => r.fulfillmentId == fulfillmentId).firstOrNull;
    if (row == null) return;
    final patch = PartPatch(
      fulfillmentId: fulfillmentId,
      orderId: row.orderId,
      number: row.number,
      seq: _gate.lastOf(fulfillmentId) ?? row.seq,
      status: status ?? row.status,
      deliveryStatus: deliveryStatus ?? row.deliveryStatus,
      deliveryId: row.deliveryId,
      driverId: driverId ?? row.driverId,
      driverName: driverName ?? row.driverName,
    );
    state = state.copyWith(patches: {...state.patches, fulfillmentId: patch});
  }

  List<QueueOrder> _rows() => [for (final s in kStaffStages) ...?ref.read(staffQueueProvider(s)).value];

  void _reload(LiveResync reason) {
    ref.invalidate(staffOverviewProvider);
    for (final s in kStaffStages) {
      ref.invalidate(staffQueueProvider(s));
    }
    // Drivers change with assignments, reconnects and polls; a plain "an order moved" message does not need the driver list again.
    if (reason != LiveResync.event) ref.invalidate(staffDriversProvider);
  }
}

final staffLiveProvider = NotifierProvider.autoDispose<StaffLive, StaffLiveState>(StaffLive.new);

/// One queue stage as the screens show it: the last read of the server with the real-time messages laid over it, so an order moves
/// between stages, gets its driver or leaves the queue the moment the message arrives.
final staffQueueViewProvider = Provider.autoDispose.family<AsyncValue<List<QueueOrder>>, String>((ref, stage) {
  final own = ref.watch(staffQueueProvider(stage));
  final patches = ref.watch(staffLiveProvider.select((s) => s.patches));
  if (patches.isEmpty || !own.hasValue) return own;
  final byStage = {for (final s in kStaffStages) s: s == stage ? own.value! : ref.watch(staffQueueProvider(s)).value ?? const <QueueOrder>[]};
  return own.whenData((_) => mergeStage(stage, byStage, patches));
});

/// The counts of the staff home with the same messages laid over them.
final staffOverviewViewProvider = Provider.autoDispose<AsyncValue<StaffOverview>>((ref) {
  final own = ref.watch(staffOverviewProvider);
  final patches = ref.watch(staffLiveProvider.select((s) => s.patches));
  if (patches.isEmpty || !own.hasValue) return own;
  final byStage = {for (final s in kStaffStages) s: ref.watch(staffQueueProvider(s)).value ?? const <QueueOrder>[]};
  return own.whenData((o) => adjustOverview(o, byStage, patches));
});

/// Every order of every stage, for a window that follows one order as it moves from stage to stage (accept moves it
/// from New to Preparing). It is only settled once all the stages are up to date, so an order in the middle of moving
/// is never mistaken for one that left the queue.
final staffOrderLookupProvider = Provider.autoDispose<LiveList<QueueOrder>>((ref) {
  final parts = [for (final s in kStaffStages) ref.watch(staffQueueViewProvider(s))];
  return LiveList([
    for (final p in parts) ...?p.value,
  ], settled: parts.every((p) => p.hasValue && !p.isLoading && !p.hasError));
});

final staffDriversProvider = FutureProvider.autoDispose<List<BranchDriver>>(
  (ref) => ref.watch(opsRepositoryProvider).drivers(),
);

/// The branch drivers as a window sees them (see [staffOrderLookupProvider]).
final staffLiveDriversProvider = Provider.autoDispose<LiveList<BranchDriver>>(
  (ref) => liveOf(ref.watch(staffDriversProvider)),
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

final staffPermissionsProvider = FutureProvider.autoDispose<List<String>>(
  (ref) => ref.watch(opsRepositoryProvider).permissions(),
);

/// Whether the signed-in employee holds [key] (the API checks it again; this only hides buttons that would be refused).
bool hasPermission(List<String> perms, String key) {
  if (perms.contains('*') || perms.contains(key)) return true;
  final dot = key.indexOf('.');
  return dot > 0 && perms.contains('${key.substring(0, dot)}.*');
}
