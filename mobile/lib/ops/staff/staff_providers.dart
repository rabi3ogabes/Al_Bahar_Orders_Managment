import 'dart:async';
import 'dart:collection';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/live_gate.dart';
import 'package:albahar_customer/core/realtime/live_host.dart';
import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/ops/ops_board.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/staff/staff_groups.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

// ── What this phone remembers: the branch a manager of several chose, and the chime ───────────────────────────────────────────

/// The branch a manager of several chose in the header (null: the server's first one). Remembered on the device; a phone without storage
/// (a test, a locked-down phone) simply forgets it when the app closes.
class StaffBranchChoice extends Notifier<String?> {
  static const storageKey = 'staff_branch_id';

  @override
  String? build() {
    try {
      final saved = ref.read(sharedPreferencesProvider).getString(storageKey);
      return saved == null || saved.isEmpty ? null : saved;
    } catch (_) {
      return null;
    }
  }

  void choose(String branchId) {
    if (branchId == state) return;
    state = branchId;
    _remember(branchId);
  }

  /// Back to the server's first branch (the saved one is not the person's any more).
  void clear() {
    if (state == null) return;
    state = null;
    _remember(null);
  }

  void _remember(String? id) {
    try {
      final prefs = ref.read(sharedPreferencesProvider);
      unawaited(id == null ? prefs.remove(storageKey) : prefs.setString(storageKey, id));
    } catch (_) {
      // Not remembering only means the next start begins with the first branch.
    }
  }
}

final staffBranchChoiceProvider = NotifierProvider<StaffBranchChoice, String?>(StaffBranchChoice.new);

/// "Sound for new orders": a short chime when a new order arrives while the app is open. Off until the person switches it on; remembered on the device.
class StaffSoundSetting extends Notifier<bool> {
  static const storageKey = 'staff_sound_new_orders';

  @override
  bool build() {
    try {
      return ref.read(sharedPreferencesProvider).getBool(storageKey) ?? false;
    } catch (_) {
      return false;
    }
  }

  void set(bool on) {
    if (on == state) return;
    state = on;
    try {
      unawaited(ref.read(sharedPreferencesProvider).setBool(storageKey, on));
    } catch (_) {
      // Not remembering only means the chime is off again at the next start.
    }
  }
}

final staffSoundProvider = NotifierProvider<StaffSoundSetting, bool>(StaffSoundSetting.new);

/// The chime itself (replaced in tests): the system's alert sound, with a firm tap so a phone on silent still shakes.
abstract class StaffChime {
  void play();
}

class SystemStaffChime implements StaffChime {
  const SystemStaffChime();

  @override
  void play() {
    unawaited(SystemSound.play(SystemSoundType.alert));
    Haptics.warning();
  }
}

final staffChimeProvider = Provider<StaffChime>((ref) => const SystemStaffChime());

// ── The clock on this phone ─────────────────────────────────────────────────────────────────────────────────────────────────

/// The time the order chips measure from. A function so tests can set it; the real one is the phone's clock.
final staffClockSourceProvider = Provider<DateTime Function()>((ref) => DateTime.now);

/// "Now" for the age chips, moved on every half minute while the staff home is open (it calls [tick]), so a card that says "Waiting 12 min" says
/// 13 a minute later without asking the server. Every few minutes it also reads the lists again (the server counts only the minutes the branch is
/// open). The timer itself belongs to the home screen, so nothing keeps running once it is gone.
class StaffTicker extends Notifier<DateTime> {
  static const every = Duration(seconds: 30);
  int _ticks = 0;

  @override
  DateTime build() => ref.read(staffClockSourceProvider)();

  void tick() {
    state = ref.read(staffClockSourceProvider)();
    _ticks++;
    // The attention list changes by itself (a part turns late), so it is read again every two minutes; the queues every five.
    if (_ticks % 4 == 0) ref.invalidate(staffAttentionProvider);
    if (_ticks % 10 == 0) {
      for (final s in [...kStaffStages, kPickupLane]) {
        ref.invalidate(staffQueueProvider(s));
      }
    }
  }
}

final staffTickProvider = NotifierProvider.autoDispose<StaffTicker, DateTime>(StaffTicker.new);

// ── Reading the branch ─────────────────────────────────────────────────────────────────────────────────────────────────────

final staffOverviewProvider = FutureProvider.autoDispose<StaffOverview>((ref) async {
  final repo = ref.watch(opsRepositoryProvider);
  final chosen = ref.watch(staffBranchChoiceProvider);
  try {
    final o = await repo.overview(branchId: chosen);
    // A saved choice the person no longer has (their access changed): start again from the server's first branch.
    if (chosen != null && o.branches.isNotEmpty && !o.branches.any((b) => b.id == chosen)) {
      scheduleMicrotask(() {
        if (ref.mounted) ref.read(staffBranchChoiceProvider.notifier).clear();
      });
    }
    return o;
  } on ApiException catch (e) {
    final stale =
        e.kind == ApiErrorKind.forbidden || e.kind == ApiErrorKind.notFound || e.kind == ApiErrorKind.validation;
    if (chosen != null && stale) {
      scheduleMicrotask(() {
        if (ref.mounted) ref.read(staffBranchChoiceProvider.notifier).clear();
      });
    }
    rethrow;
  }
});

/// The branch the screens show: the one chosen in the header, else the one the server started with (null until it is known).
final staffBranchIdProvider = Provider.autoDispose<String?>((ref) {
  final chosen = ref.watch(staffBranchChoiceProvider);
  if (chosen != null) return chosen;
  final id = ref.watch(staffOverviewProvider.select((o) => o.value?.branchId));
  return id == null || id.isEmpty ? null : id;
});

/// The order queue stages, in tab order.
const kStaffStages = ['new', 'preparing', 'ready', 'out', 'failed'];

/// The lane of the branch's pickup parts (all stages in one list: new, preparing, ready for pickup).
const kPickupLane = 'pickup';

/// How many rows one read of a queue lane asks for.
const kStaffPageSize = 50;

/// The rows of a lane as read from the server, with whether another page follows and the branch they were read for (a row read for another
/// branch is never shown). It is a plain list, so everything that reads a lane as a list keeps working.
class QueueRows extends ListBase<QueueOrder> {
  QueueRows(List<QueueOrder> rows, {this.hasMore = false, this.branchId}) : _rows = List.unmodifiable(rows);
  final List<QueueOrder> _rows;
  final bool hasMore;
  final String? branchId;

  @override
  int get length => _rows.length;

  @override
  set length(int value) => throw UnsupportedError('A queue read cannot be changed');

  @override
  QueueOrder operator [](int index) => _rows[index];

  @override
  void operator []=(int index, QueueOrder value) => throw UnsupportedError('A queue read cannot be changed');
}

/// How many pages of a lane are open ("Load more" opens the next one); a read asks for all of them again so the list never shrinks under a finger.
class StaffLaneDepth extends Notifier<int> {
  StaffLaneDepth(this.lane);
  final String lane;

  @override
  int build() => 1;

  void more() => state = state + 1;
}

final staffLaneDepthProvider = NotifierProvider.autoDispose.family<StaffLaneDepth, int, String>(StaffLaneDepth.new);

final staffQueueProvider = FutureProvider.autoDispose.family<List<QueueOrder>, String>((ref, stage) async {
  final repo = ref.watch(opsRepositoryProvider);
  final branch = ref.watch(staffBranchChoiceProvider);
  final depth = ref.watch(staffLaneDepthProvider(stage));
  final pickup = stage == kPickupLane;
  final rows = <QueueOrder>[];
  final seen = <String>{};
  var more = false;
  for (var page = 1; page <= depth; page++) {
    final read = await repo.queuePage(
      pickup ? 'all' : stage,
      branchId: branch,
      type: pickup ? 'pickup' : null,
      page: page,
      perPage: kStaffPageSize,
    );
    // A row that slid onto the next page while the list was read is shown once.
    rows.addAll(read.rows.where((r) => seen.add(r.fulfillmentId)));
    more = read.hasMore;
    if (!more) break;
  }
  return QueueRows(rows, hasMore: more, branchId: branch);
});

/// Whether more pages of [lane] exist, from the last read.
bool laneHasMore(AsyncValue<List<QueueOrder>> read) {
  final rows = read.value;
  return rows is QueueRows && rows.hasMore;
}

/// The branch this read of a lane was made for is not the one shown now (the person just switched): its rows must not be shown.
bool _foreign(List<QueueOrder> rows, String? chosen) => rows is QueueRows && rows.branchId != chosen;

/// What the staff screens know about the live link and about the orders that moved since the last read of the server.
@immutable
class StaffLiveState {
  const StaffLiveState({required this.link, this.patches = const {}, this.newOrder});
  final LiveLink link;

  /// The newest message about each branch part, by fulfillment id (laid over the last read by [mergeStage]).
  final Map<String, PartPatch> patches;

  /// The newest brand-new order this phone was told about (each order once): the home screen shows the alert when it changes.
  final NewOrderAlert? newOrder;

  StaffLiveState copyWith({LiveLink? link, Map<String, PartPatch>? patches, NewOrderAlert? newOrder}) =>
      StaffLiveState(link: link ?? this.link, patches: patches ?? this.patches, newOrder: newOrder ?? this.newOrder);
}

/// "A new order arrived": which, and what kind. [id] counts up, so two orders in a row are two alerts.
@immutable
class NewOrderAlert {
  const NewOrderAlert({required this.id, required this.number, required this.pickup});
  final int id;
  final String number;
  final bool pickup;
}

/// The branch staff app's ONE real-time orchestrator (alive while the home screen is). It follows `private-branches.{id}`; applies each
/// message at once (an order moves between stages, gets its driver, finishes; a brand-new order is read in straight away); confirms a
/// burst with one read; reads everything again on every reconnect, return to the foreground and return of the network (that fills any
/// gap); and polls every 15 s only while the socket is down.
class StaffLive extends Notifier<StaffLiveState> {
  final _gate = SeqGate();
  late final LiveSync _sync;

  /// The orders this phone already alerted about (an order alerts once, however many messages follow it).
  final _alerted = <String>{};
  int _alerts = 0;

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
    if (patch == null) {
      _onPickupMessage(e);
      return;
    }
    final rows = _rows();
    final row = rows.where((r) => r.fulfillmentId == patch.fulfillmentId).firstOrNull;
    _gate.seen(patch.fulfillmentId, row?.seq);
    if (!_gate.accept(patch.fulfillmentId, patch.seq)) return;
    state = state.copyWith(
      patches: {...state.patches, patch.fulfillmentId: patch}
        ..removeWhere((id, p) => rows.any((r) => r.fulfillmentId == id && r.seq > p.seq)),
    );
    // A brand-new order (its first message says "pending", this phone has never seen it): one alert, however many times the message repeats.
    if (patch.status == 'pending' && row == null && _isPaid(e)) {
      _alert(patch.fulfillmentId, patch.number, pickup: false);
    }
    // An order this phone has never seen (just placed): its row comes from the server now, not after the burst.
    if (row == null && patch.stage != 'done') _reload(LiveResync.event);
    _sync.eventArrived();
  }

  /// A message about a pickup part: pickups are not laid over the lists (their list is read again after the burst), but a new one alerts like a delivery.
  void _onPickupMessage(PusherEvent e) {
    final d = e.data;
    if (d['fulfillment_type']?.toString() != 'pickup') return;
    final id = d['fulfillment_id']?.toString() ?? '';
    if (id.isEmpty) return;
    // Only a list that is on screen is asked: a person who does not see pickups never causes a read of them.
    final known = ref.exists(staffQueueProvider(kPickupLane))
        ? ref.read(staffQueueProvider(kPickupLane)).value?.any((r) => r.fulfillmentId == id) ?? false
        : false;
    if (d['fulfillment_status']?.toString() == 'pending' && !known && _isPaid(e)) {
      _alert(id, d['number']?.toString() ?? '', pickup: true);
      _reload(LiveResync.event);
    }
    _sync.eventArrived();
  }

  /// An online order that is not paid yet is not the branch's work and never alerts (the server does not send it either; this is a second lock).
  bool _isPaid(PusherEvent e) => e.data['order_status']?.toString() != 'pending_payment';

  void _alert(String fulfillmentId, String number, {required bool pickup}) {
    if (!_alerted.add(fulfillmentId)) return;
    state = state.copyWith(
      newOrder: NewOrderAlert(id: ++_alerts, number: number, pickup: pickup),
    );
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
    for (final s in [...kStaffStages, kPickupLane]) {
      ref.invalidate(staffQueueProvider(s));
    }
    ref.invalidate(staffAttentionProvider);
    // Drivers change with assignments, reconnects and polls; a plain "an order moved" message does not need the driver list again.
    if (reason != LiveResync.event) ref.invalidate(staffDriversProvider);
  }
}

final staffLiveProvider = NotifierProvider.autoDispose<StaffLive, StaffLiveState>(StaffLive.new);

/// One queue stage as the screens show it: the last read of the server with the real-time messages laid over it, so an order moves
/// between stages, gets its driver or leaves the queue the moment the message arrives. A lane read for another branch is "loading".
final staffQueueViewProvider = Provider.autoDispose.family<AsyncValue<List<QueueOrder>>, String>((ref, stage) {
  final own = ref.watch(staffQueueProvider(stage));
  final chosen = ref.watch(staffBranchChoiceProvider);
  if (own.hasValue && _foreign(own.requireValue, chosen)) return const AsyncLoading();
  // Pickups are read again after each message; only deliveries are laid over (an order that moves between stages).
  if (stage == kPickupLane) return own;
  final patches = ref.watch(staffLiveProvider.select((s) => s.patches));
  if (patches.isEmpty || !own.hasValue) return own;
  final byStage = {
    for (final s in kStaffStages)
      s: s == stage ? own.requireValue : ref.watch(staffQueueProvider(s)).value ?? const <QueueOrder>[],
  };
  return own.whenData((_) => mergeStage(stage, byStage, patches));
});

/// What the lanes of a tab say beyond their rows: which have a next page, and which are loading it now.
({Set<String> more, Set<String> loadingMore}) _paging(Ref ref, Iterable<String> lanes) {
  final more = <String>{};
  final loading = <String>{};
  for (final s in lanes) {
    final read = ref.watch(staffQueueProvider(s));
    if (laneHasMore(read)) more.add(s);
    if (read.isLoading && read.hasValue) loading.add(s);
  }
  return (more: more, loadingMore: loading);
}

/// The rows of the "To do" tab (new, preparing, ready) as the screen shows them, the real-time messages already laid over each stage.
final staffTodoViewProvider = Provider.autoDispose<StageRows>((ref) {
  final paging = _paging(ref, kTodoStages);
  return combineStages(
    {for (final s in kTodoStages) s: ref.watch(staffQueueViewProvider(s))},
    more: paging.more,
    loadingMore: paging.loadingMore,
  );
});

/// The rows of the "On the way" tab (a failed delivery first, then the deliveries out).
final staffWayViewProvider = Provider.autoDispose<StageRows>((ref) {
  final paging = _paging(ref, kWayStages);
  return combineStages(
    {for (final s in kWayStages) s: ref.watch(staffQueueViewProvider(s))},
    more: paging.more,
    loadingMore: paging.loadingMore,
  );
});

/// Whether this person may see the branch's pickups (the Pickup switch, its lists and the scanner): `pickup.view` at this branch.
final staffPickupEnabledProvider = Provider.autoDispose<bool>((ref) {
  final fromBranch = ref.watch(staffOverviewProvider.select((o) => o.value?.allows('pickup.view')));
  if (fromBranch != null) return fromBranch;
  final perms = ref.watch(staffPermissionsProvider).value ?? const <String>[];
  return hasPermission(perms, 'pickup.view');
});

/// The pickup parts as the Pickup switch of the "To do" tab shows them: one list split into new, preparing and ready for pickup.
final staffPickupViewProvider = Provider.autoDispose<StageRows>((ref) {
  final read = ref.watch(staffQueueViewProvider(kPickupLane));
  final paging = _paging(ref, const [kPickupLane]);
  final rows = read.hasValue ? read.requireValue : null;
  final byStage = rows == null
      ? null
      : <String, List<QueueOrder>>{
          for (final s in kPickupStages)
            s: oldestFirst([
              for (final r in rows)
                if (pickupStageOf(r.status) == s) r,
            ]),
        };
  // The next page of the one pickup list belongs to every stage of it.
  return StageRows(
    byStage: byStage,
    error: read.hasError ? read.error : null,
    more: paging.more.isEmpty ? const {} : {...kPickupStages},
    loadingMore: paging.loadingMore.isEmpty ? const {} : {...kPickupStages},
  );
});

/// The stage the "To do" list is narrowed to, or null for all of it. It lives as long as the home screen, so a rebuild or a visit to
/// another tab never loses it.
class StaffTodoFilter extends Notifier<String?> {
  @override
  String? build() => null;

  /// A tap on a stage narrows the list to it; a second tap on the same stage shows everything again.
  void toggle(String stage) => state = state == stage ? null : stage;

  void reset() => state = null;
}

final staffTodoFilterProvider = NotifierProvider.autoDispose<StaffTodoFilter, String?>(StaffTodoFilter.new);

/// Which orders the "To do" tab lists: the deliveries or the pickups.
enum StaffOrdersMode { delivery, pickup }

class StaffOrdersModeChoice extends Notifier<StaffOrdersMode> {
  @override
  StaffOrdersMode build() => StaffOrdersMode.delivery;

  void choose(StaffOrdersMode mode) {
    if (mode == state) return;
    state = mode;
    // The stage filter of the other list means nothing here.
    ref.read(staffTodoFilterProvider.notifier).reset();
  }
}

final staffOrdersModeProvider = NotifierProvider.autoDispose<StaffOrdersModeChoice, StaffOrdersMode>(
  StaffOrdersModeChoice.new,
);

/// The two views of the Drivers tab.
enum StaffDriversView { list, map }

/// The view of the Drivers tab the employee chose last (kept while the home screen is open).
class StaffDriversViewChoice extends Notifier<StaffDriversView> {
  @override
  StaffDriversView build() => StaffDriversView.list;

  void choose(StaffDriversView view) => state = view;
}

final staffDriversViewProvider = NotifierProvider.autoDispose<StaffDriversViewChoice, StaffDriversView>(
  StaffDriversViewChoice.new,
);

/// The counts of the staff home with the same messages laid over them. An overview read for another branch is "loading".
final staffOverviewViewProvider = Provider.autoDispose<AsyncValue<StaffOverview>>((ref) {
  final own = ref.watch(staffOverviewProvider);
  final chosen = ref.watch(staffBranchChoiceProvider);
  if (own.hasValue && chosen != null && own.requireValue.branchId != chosen) return const AsyncLoading();
  final patches = ref.watch(staffLiveProvider.select((s) => s.patches));
  if (patches.isEmpty || !own.hasValue) return own;
  final byStage = {for (final s in kStaffStages) s: ref.watch(staffQueueProvider(s)).value ?? const <QueueOrder>[]};
  return own.whenData((o) => adjustOverview(o, byStage, patches));
});

/// Every order of every stage (and the pickups, when this person sees them), for a window that follows one order as it moves from stage to
/// stage (accept moves it from New to Preparing). It is only settled once all the lists are up to date, so an order in the middle of moving
/// is never mistaken for one that left the queue.
final staffOrderLookupProvider = Provider.autoDispose<LiveList<QueueOrder>>((ref) {
  final parts = [
    for (final s in kStaffStages) ref.watch(staffQueueViewProvider(s)),
    if (ref.watch(staffPickupEnabledProvider)) ref.watch(staffQueueViewProvider(kPickupLane)),
  ];
  return LiveList([
    for (final p in parts) ...?p.value,
  ], settled: parts.every((p) => p.hasValue && !p.isLoading && !p.hasError));
});

final staffDriversProvider = FutureProvider.autoDispose<List<BranchDriver>>((ref) {
  final branch = ref.watch(staffBranchIdProvider);
  return ref.watch(opsRepositoryProvider).drivers(branchId: branch);
});

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

/// Whether the employee may do [key] at the branch on screen: what the server says for that branch, else what their permission list says.
bool staffMay(String key, {required List<String> perms, StaffOverview? overview}) =>
    overview?.allows(key) ?? hasPermission(perms, key);

/// Reads everything of the branch again (the refresh button, and after every step): the counts, every list, the needs-attention list, the drivers.
void refreshStaff(WidgetRef ref, {bool drivers = true}) {
  ref.invalidate(staffOverviewProvider);
  for (final s in [...kStaffStages, kPickupLane]) {
    ref.invalidate(staffQueueProvider(s));
  }
  ref.invalidate(staffAttentionProvider);
  if (drivers) ref.invalidate(staffDriversProvider);
}

/// Whether a parcel can still be corrected ("Missing an item?"), read from the server when its window opens. The window hides the
/// entry point once the server says no.
final adjustContextProvider = FutureProvider.autoDispose.family<AdjustContext, String>(
  (ref, fulfillmentId) => ref.watch(opsRepositoryProvider).adjustContext(fulfillmentId),
);

/// The top of the needs-attention list for this person and this branch (null while it cannot be read: the strip then stays out of the way).
final staffAttentionProvider = FutureProvider.autoDispose<AttentionSummary>((ref) {
  final branch = ref.watch(staffBranchChoiceProvider);
  return ref.watch(opsRepositoryProvider).attention(branchId: branch);
});
