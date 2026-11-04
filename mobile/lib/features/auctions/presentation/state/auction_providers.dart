import 'dart:async';

import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class AuctionListController extends PagedNotifier<AuctionSummary> {
  AuctionListController(this.status);
  final String status;

  @override
  void watchDependencies() {
    ref.watch(localeCodeProvider);
    ref.watch(isAuthenticatedProvider);
  }

  @override
  Future<Paginated<AuctionSummary>> fetchPage(int page) => ref.read(auctionRepositoryProvider).list(status, page: page);
}

final auctionListProvider = AsyncNotifierProvider.autoDispose
    .family<AuctionListController, PagedState<AuctionSummary>, String>(AuctionListController.new);

final auctionDetailProvider = FutureProvider.autoDispose.family<Auction, String>((ref, id) {
  ref.watch(localeCodeProvider);
  ref.watch(isAuthenticatedProvider);
  return ref.watch(auctionRepositoryProvider).auction(id);
});

/// Private per-user auction events (`private-users.{id}`: outbid / won).
final userAuctionEventsProvider = StreamProvider.autoDispose<PusherEvent>((ref) {
  final userId = ref.watch(currentUserIdProvider);
  if (userId == null) return const Stream.empty();
  return ref.watch(pusherClientProvider).channel('private-users.$userId');
});

@immutable
class LotViewState {
  const LotViewState({
    required this.lot,
    required this.bids,
    required this.serverOffset,
    this.realtime = RealtimeState.idle,
    this.lastEvent,
    this.lastEventAt,
  });

  final Lot lot;
  final List<BidEntry> bids;

  /// `server_time - device_time`; countdowns use `now + serverOffset`.
  final Duration serverOffset;
  final RealtimeState realtime;

  /// Last applied event type (UI shows "time extended" etc.).
  final String? lastEvent;
  final DateTime? lastEventAt;

  DateTime get serverNow => DateTime.now().add(serverOffset);

  Duration? get remaining => lot.endsAt?.difference(serverNow);

  LotViewState copyWith({
    Lot? lot,
    List<BidEntry>? bids,
    Duration? serverOffset,
    RealtimeState? realtime,
    String? lastEvent,
    DateTime? lastEventAt,
  }) => LotViewState(
    lot: lot ?? this.lot,
    bids: bids ?? this.bids,
    serverOffset: serverOffset ?? this.serverOffset,
    realtime: realtime ?? this.realtime,
    lastEvent: lastEvent ?? this.lastEvent,
    lastEventAt: lastEventAt ?? this.lastEventAt,
  );
}

Duration offsetFromServerTime(DateTime? serverTime, {DateTime? receivedAt}) =>
    serverTime == null ? Duration.zero : serverTime.difference(receivedAt ?? DateTime.now());

/// Authoritative lot state with real-time updates.
///
/// * REST is the source of truth; sockets only accelerate updates.
/// * Events carry a monotonically increasing `sequence`; duplicates are
///   dropped and any gap triggers `GET /events?after_sequence=` replay.
/// * On (re)connect the lot is re-fetched and missed events replayed.
class LotController extends AsyncNotifier<LotViewState> {
  LotController(this.lotId);
  final String lotId;

  StreamSubscription<PusherEvent>? _eventsSub;
  StreamSubscription<RealtimeState>? _stateSub;
  bool _resyncing = false;
  Timer? _myStatusRefresh;
  final Map<String, String> _bidKeys = {};

  AuctionRepository get _repo => ref.read(auctionRepositoryProvider);

  @override
  Future<LotViewState> build() async {
    ref.watch(isAuthenticatedProvider);
    ref.watch(localeCodeProvider);
    final client = ref.watch(pusherClientProvider);

    ref.onDispose(() {
      unawaited(_eventsSub?.cancel());
      unawaited(_stateSub?.cancel());
      _myStatusRefresh?.cancel();
    });

    final initial = await _load();

    _eventsSub = client.channel('auction-lot.$lotId').listen(_onEvent);
    var wasConnected = client.state == RealtimeState.connected;
    _stateSub = client.states.listen((s) {
      final current = state.value;
      if (current != null) state = AsyncData(current.copyWith(realtime: s));
      if (s == RealtimeState.connected) {
        if (wasConnected || current != null) unawaited(resync());
        wasConnected = true;
      }
    });

    // Private outbid / won notifications for this lot → re-fetch my_status.
    ref.listen(userAuctionEventsProvider, (_, next) {
      final e = next.value;
      if (e == null) return;
      final id = e.data['lot_id']?.toString() ?? (e.data['lot'] is Map ? (e.data['lot'] as Map)['id']?.toString() : null);
      if (id == lotId) unawaited(refreshLot());
    });

    return initial.copyWith(realtime: client.state);
  }

  Future<LotViewState> _load() async {
    final results = await Future.wait([_repo.lot(lotId), _repo.bids(lotId).catchError((Object _) => <BidEntry>[])]);
    final lot = results[0] as Lot;
    return LotViewState(
      lot: lot,
      bids: results[1] as List<BidEntry>,
      serverOffset: offsetFromServerTime(lot.serverTime),
    );
  }

  void _onEvent(PusherEvent e) {
    final event = LotEvent.fromJson(e.data, type: e.event);
    handleEvent(event);
  }

  /// Applies one event respecting sequence ordering (public for tests).
  @visibleForTesting
  void handleEvent(LotEvent event) {
    final current = state.value;
    if (current == null) return;
    final last = current.lot.lastSequence;
    if (event.sequence <= last) return; // duplicate / already applied
    if (event.sequence > last + 1) {
      unawaited(replayMissed());
      return;
    }
    _apply(current, event);
  }

  void _apply(LotViewState current, LotEvent event) {
    var lot = event.lot != null ? current.lot.mergePublic(event.lot!) : current.lot;
    lot = lot.copyWith(lastSequence: event.sequence);
    var bids = current.bids;
    final bid = event.bid;
    if (bid != null && !bids.any((b) => b.bidNumber == bid.bidNumber && b.bidNumber != 0)) {
      bids = [bid, ...bids];
    }
    final offset = event.lot?.serverTime != null ? offsetFromServerTime(event.lot!.serverTime) : current.serverOffset;
    state = AsyncData(
      current.copyWith(lot: lot, bids: bids, serverOffset: offset, lastEvent: event.type, lastEventAt: DateTime.now()),
    );
    // Someone else bid while we were leading: confirm our status via REST.
    if (bid != null && !bid.isMine && current.lot.myStatus == 'leading') _scheduleMyStatusRefresh();
    if (event.type == 'lot.status' && lot.isFinished) _scheduleMyStatusRefresh();
  }

  void _scheduleMyStatusRefresh() {
    _myStatusRefresh?.cancel();
    _myStatusRefresh = Timer(const Duration(milliseconds: 400), () => unawaited(refreshLot()));
  }

  /// `GET /events?after_sequence=` and apply in order; full resync on failure.
  Future<void> replayMissed() async {
    final current = state.value;
    if (current == null || _resyncing) return;
    _resyncing = true;
    try {
      final events = await _repo.events(lotId, afterSequence: current.lot.lastSequence);
      for (final e in events) {
        final s = state.value!;
        if (e.sequence == s.lot.lastSequence + 1) {
          _apply(s, e);
        } else if (e.sequence > s.lot.lastSequence + 1) {
          _resyncing = false;
          await resync();
          return;
        }
      }
    } catch (_) {
      _resyncing = false;
      await resync();
      return;
    }
    _resyncing = false;
  }

  /// Full authoritative reload followed by event replay.
  Future<void> resync() async {
    if (_resyncing) return;
    _resyncing = true;
    try {
      final fresh = await _load();
      if (!ref.mounted) return;
      final realtime = state.value?.realtime ?? RealtimeState.idle;
      state = AsyncData(fresh.copyWith(realtime: realtime));
      final events = await _repo.events(lotId, afterSequence: fresh.lot.lastSequence);
      for (final e in events) {
        final s = state.value!;
        if (e.sequence == s.lot.lastSequence + 1) _apply(s, e);
      }
    } catch (_) {
      // Keep the last known state; the UI shows the connection status.
    } finally {
      _resyncing = false;
    }
  }

  Future<void> refreshLot() async {
    try {
      final lot = await _repo.lot(lotId);
      final current = state.value;
      if (!ref.mounted || current == null) return;
      // Never go backwards: keep the newer public state if events raced ahead.
      final merged = lot.lastSequence >= current.lot.lastSequence ? lot : current.lot.copyWith(myStatus: lot.myStatus, isWatching: lot.isWatching);
      state = AsyncData(current.copyWith(lot: merged, serverOffset: offsetFromServerTime(lot.serverTime)));
    } catch (_) {}
  }

  /// Places a bid. The same amount retried after a network failure reuses
  /// its Idempotency-Key, so a bid can never be placed twice.
  Future<void> placeBid(String amount) async {
    final key = _bidKeys.putIfAbsent(amount, Idempotency.newKey);
    try {
      final result = await _repo.placeBid(lotId, amount, idempotencyKey: key);
      _bidKeys.remove(amount);
      final current = state.value;
      if (!ref.mounted || current == null) return;
      final lot = result.lot.lastSequence >= current.lot.lastSequence ? result.lot : current.lot;
      var bids = current.bids;
      final bid = result.bid;
      if (bid != null && !bids.any((b) => b.bidNumber == bid.bidNumber && b.bidNumber != 0)) bids = [bid, ...bids];
      state = AsyncData(current.copyWith(lot: lot, bids: bids, serverOffset: offsetFromServerTime(result.lot.serverTime)));
    } on ApiException catch (e) {
      // Definitive rejections free the key; network errors keep it for retry.
      if (!e.isNetwork) _bidKeys.remove(amount);
      if (e.kind == ApiErrorKind.conflict || e.kind == ApiErrorKind.validation) unawaited(refreshLot());
      rethrow;
    }
  }

  Future<void> setAutoBid(String maxAmount) async {
    await _repo.setAutoBid(lotId, maxAmount);
    await refreshLot();
  }

  Future<void> cancelAutoBid() async {
    await _repo.cancelAutoBid(lotId);
    await refreshLot();
  }

  Future<bool> toggleWatch() async {
    final current = state.value;
    if (current == null) return false;
    final watching = current.lot.isWatching;
    state = AsyncData(current.copyWith(lot: current.lot.copyWith(isWatching: !watching)));
    try {
      if (watching) {
        await _repo.unwatch(lotId);
      } else {
        await _repo.watch(lotId);
      }
      return !watching;
    } catch (_) {
      final s = state.value;
      if (ref.mounted && s != null) state = AsyncData(s.copyWith(lot: s.lot.copyWith(isWatching: watching)));
      rethrow;
    }
  }
}

final lotControllerProvider = AsyncNotifierProvider.autoDispose.family<LotController, LotViewState, String>(
  LotController.new,
);

class MyBidsController extends PagedNotifier<MyBid> {
  @override
  void watchDependencies() => ref.watch(isAuthenticatedProvider);
  @override
  Future<Paginated<MyBid>> fetchPage(int page) => ref.read(auctionRepositoryProvider).myBids(page: page);
}

class WatchlistController extends PagedNotifier<Lot> {
  @override
  void watchDependencies() => ref.watch(isAuthenticatedProvider);
  @override
  Future<Paginated<Lot>> fetchPage(int page) => ref.read(auctionRepositoryProvider).watchlist(page: page);
}

class WinsController extends PagedNotifier<AuctionWin> {
  @override
  void watchDependencies() => ref.watch(isAuthenticatedProvider);
  @override
  Future<Paginated<AuctionWin>> fetchPage(int page) => ref.read(auctionRepositoryProvider).wins(page: page);
}

final myBidsProvider = AsyncNotifierProvider.autoDispose<MyBidsController, PagedState<MyBid>>(MyBidsController.new);
final myWatchlistProvider = AsyncNotifierProvider.autoDispose<WatchlistController, PagedState<Lot>>(WatchlistController.new);
final myWinsProvider = AsyncNotifierProvider.autoDispose<WinsController, PagedState<AuctionWin>>(WinsController.new);
