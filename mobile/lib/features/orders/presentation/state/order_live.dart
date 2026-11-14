import 'package:albahar_customer/core/realtime/live_gate.dart';
import 'package:albahar_customer/core/realtime/live_host.dart';
import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What an `order.updated` message said about one order: its state, and the state of each branch part and its delivery.
@immutable
class OrderPatch {
  const OrderPatch({
    required this.orderId,
    required this.seq,
    required this.status,
    this.statusLabel,
    this.paymentStatus,
    this.parts = const {},
  });

  /// Null when the message names no order.
  static OrderPatch? fromEvent(PusherEvent e) {
    final d = e.data;
    final id = d['order_id']?.toString() ?? '';
    if (e.event != 'order.updated' || id.isEmpty) return null;
    String? text(Object? v) => v == null || v.toString().isEmpty ? null : v.toString();
    final parts = <String, ({String status, String? statusLabel, String? deliveryStatus})>{};
    final list = d['fulfillments'];
    if (list is List) {
      for (final f in list.whereType<Map<dynamic, dynamic>>()) {
        final fid = text(f['id']);
        final status = text(f['status']);
        if (fid != null && status != null) {
          parts[fid] = (
            status: status,
            statusLabel: text(f['status_label']),
            deliveryStatus: text(f['delivery_status']),
          );
        }
      }
    }
    return OrderPatch(
      orderId: id,
      seq: int.tryParse('${d['seq'] ?? ''}') ?? 0,
      status: text(d['status']) ?? '',
      statusLabel: text(d['status_label']),
      paymentStatus: text(d['payment_status']),
      parts: parts,
    );
  }

  final String orderId;
  final int seq;
  final String status;
  final String? statusLabel;
  final String? paymentStatus;
  final Map<String, ({String status, String? statusLabel, String? deliveryStatus})> parts;
}

@immutable
class OrderLiveState {
  const OrderLiveState({required this.link, this.patches = const {}});
  final LiveLink link;
  final Map<String, OrderPatch> patches;
  OrderLiveState copyWith({LiveLink? link, Map<String, OrderPatch>? patches}) =>
      OrderLiveState(link: link ?? this.link, patches: patches ?? this.patches);
}

/// The customer app's ONE real-time orchestrator for orders (alive while an orders screen is open). It follows the customer's own channel
/// (`private-users.{id}`, shared with the live notification); applies each message at once to the list, the open order and the card on
/// the home screen; never lets an older message overwrite a newer one (SeqGate); confirms a burst with one read; reads again on every
/// reconnect, return to the foreground and return of the network; and polls every 15 s only while the socket is down.
class OrderLive extends Notifier<OrderLiveState> {
  final _gate = SeqGate();
  late final LiveSync _sync;

  @override
  OrderLiveState build() {
    _sync = bindLiveSync(ref, resync: _reload);
    ref.listen(userAuctionEventsProvider, (_, next) {
      final e = next.value;
      if (e != null) onEvent(e);
    });
    final links = _sync.links.listen((l) => state = state.copyWith(link: l));
    ref.onDispose(links.cancel);
    return OrderLiveState(link: _sync.link);
  }

  @visibleForTesting
  void onEvent(PusherEvent e) {
    final patch = OrderPatch.fromEvent(e);
    if (patch == null) return;
    // A message without a version or a state (an older server, a trimmed payload) still says "something changed": read again, but never paint it over the screen.
    if (patch.seq <= 0 || patch.status.isEmpty) {
      _sync.eventArrived();
      return;
    }
    if (!_gate.accept(patch.orderId, patch.seq)) return;
    state = state.copyWith(patches: {...state.patches, patch.orderId: patch});
    _sync.eventArrived();
  }

  void _reload(LiveResync reason) {
    ref.invalidate(activeOrdersProvider);
    ref.invalidate(orderDetailProvider);
    ref.invalidate(orderTimelineProvider);
    ref.invalidate(orderTrackingProvider);
    for (final f in OrderFilter.values) {
      ref.invalidate(ordersProvider(f));
    }
  }
}

final orderLiveProvider = NotifierProvider.autoDispose<OrderLive, OrderLiveState>(OrderLive.new);

/// The order as the screens show it: the last read with the newest real-time message laid over it (when it is not older than the read).
Order liveOrder(Order order, OrderPatch? p) => p == null || p.seq < order.seq
    ? order
    : order.withLive(
        seq: p.seq,
        status: p.status,
        statusLabel: p.statusLabel,
        paymentStatus: p.paymentStatus,
        parts: p.parts,
      );

OrderSummary liveSummary(OrderSummary o, OrderPatch? p) => p == null || p.seq < o.seq
    ? o
    : o.withLive(status: p.status, statusLabel: p.statusLabel, paymentStatus: p.paymentStatus, seq: p.seq);

/// A list of orders with the messages laid over it; rows that no longer belong to [filter] (an active order that completed) drop out.
List<OrderSummary> liveSummaries(List<OrderSummary> rows, Map<String, OrderPatch> patches, {Set<String>? statuses}) {
  if (patches.isEmpty) return rows;
  return [
    for (final r in rows.map((r) => liveSummary(r, patches[r.id])))
      if (statuses == null || statuses.contains(r.status)) r,
  ];
}

final orderDetailViewProvider = Provider.autoDispose.family<AsyncValue<Order>, String>((ref, id) {
  final own = ref.watch(orderDetailProvider(id));
  final patch = ref.watch(orderLiveProvider.select((s) => s.patches[id]));
  return own.whenData((o) => liveOrder(o, patch));
});

final activeOrdersViewProvider = Provider.autoDispose<AsyncValue<List<OrderSummary>>>((ref) {
  final own = ref.watch(activeOrdersProvider);
  final patches = ref.watch(orderLiveProvider.select((s) => s.patches));
  return own.whenData((rows) => liveSummaries(rows, patches, statuses: OrderFilter.active.statuses));
});
