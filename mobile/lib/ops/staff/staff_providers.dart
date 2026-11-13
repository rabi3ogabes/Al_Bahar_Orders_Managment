import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final staffOverviewProvider = FutureProvider.autoDispose<StaffOverview>(
  (ref) => ref.watch(opsRepositoryProvider).overview(),
);

/// The order queue stages, in tab order.
const kStaffStages = ['new', 'preparing', 'ready', 'out', 'failed'];

final staffQueueProvider = FutureProvider.autoDispose.family<List<QueueOrder>, String>(
  (ref, stage) => ref.watch(opsRepositoryProvider).queue(stage),
);

/// Every order of every stage, for a window that follows one order as it moves from stage to stage (accept moves it
/// from New to Preparing). It is only settled once all the stages are up to date, so an order in the middle of moving
/// is never mistaken for one that left the queue.
final staffOrderLookupProvider = Provider.autoDispose<LiveList<QueueOrder>>((ref) {
  final parts = [for (final s in kStaffStages) ref.watch(staffQueueProvider(s))];
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
