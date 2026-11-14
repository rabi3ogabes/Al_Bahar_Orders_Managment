import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/foundation.dart';

/*
 * What the real-time messages say about jobs and orders, and how it is laid over what the last read of the server said.
 * Pure functions on plain values: the same rules serve the driver app and the branch staff app, and are tested without a phone.
 *
 * The rule of the overlay: a message that is at least as new as the row it concerns (seq >= the row's seq) wins; a read that is newer
 * than the message replaces it. So an update shows the instant it arrives, a late or repeated message changes nothing, and the next
 * read of the server (always made after a burst of messages, and after every reconnect) has the last word.
 */

/// The queue stage of a delivery order in the staff app, from its branch part's status (same stages as the server's queue).
String stageOfStatus(String fulfillmentStatus) => switch (fulfillmentStatus) {
  'pending' => 'new',
  'accepted' || 'preparing' => 'preparing',
  'ready' => 'ready',
  'dispatched' || 'out_for_delivery' => 'out',
  'failed' => 'failed',
  _ => 'done',
};

/// The queue stages in tab order.
const kQueueStages = ['new', 'preparing', 'ready', 'out', 'failed'];

/// Delivery statuses after which a job is history for the driver.
bool isFinishedJob(String status) => status == 'delivered' || status == 'returned' || status == 'cancelled';

// ── Driver ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/// What a message said about one of this driver's jobs.
@immutable
class JobPatch {
  const JobPatch({required this.deliveryId, required this.orderId, required this.number, required this.seq, required this.status, required this.assigned});

  /// Reads a `dispatch.updated` message from the driver's own channel. Null when it names no delivery.
  static JobPatch? fromEvent(PusherEvent e) {
    final d = e.data;
    final id = d['delivery_id']?.toString() ?? '';
    if (id.isEmpty) return null;
    return JobPatch(
      deliveryId: id,
      orderId: d['order_id']?.toString() ?? '',
      number: d['number']?.toString() ?? '',
      seq: int.tryParse('${d['seq'] ?? ''}') ?? 0,
      status: d['status']?.toString() ?? '',
      assigned: d['assigned'] != false,
    );
  }

  final String deliveryId;
  final String orderId;
  final String number;
  final int seq;
  final String status;

  /// False when the job was taken away from this driver (re-assigned): it leaves every list and its window closes.
  final bool assigned;
}

/// The row as the overlay shows it, or null when it is no longer this driver's.
DriverJob? effectiveJob(DriverJob job, Map<String, JobPatch> patches) {
  final p = patches[job.id];
  if (p == null || p.seq < job.seq) return job;
  if (!p.assigned) return null;
  return job.withState(status: p.status, seq: p.seq);
}

/// The two job lists as the screens show them: [active] (open jobs) or history (finished ones, newest first). A job that has just
/// been delivered moves from one to the other at once.
List<DriverJob> mergeJobs({
  required List<DriverJob> active,
  required List<DriverJob> history,
  required Map<String, JobPatch> patches,
  required bool wantHistory,
}) {
  if (patches.isEmpty) return wantHistory ? history : active;
  final open = <DriverJob>[];
  final finished = <DriverJob>[];
  for (final job in active) {
    final eff = effectiveJob(job, patches);
    if (eff == null) continue;
    (isFinishedJob(eff.status) ? finished : open).add(eff);
  }
  if (!wantHistory) return open;
  final known = {for (final j in finished) j.id};
  final older = <DriverJob>[];
  for (final job in history) {
    if (known.contains(job.id)) continue;
    final eff = effectiveJob(job, patches);
    if (eff != null) older.add(eff);
  }
  return [...finished, ...older];
}

// ── Branch staff ───────────────────────────────────────────────────────────────────────────────────────────────────────────

/// What a message said about one branch part of an order (a row of the staff queue).
@immutable
class PartPatch {
  const PartPatch({
    required this.fulfillmentId,
    required this.orderId,
    required this.number,
    required this.seq,
    required this.status,
    required this.deliveryStatus,
    required this.deliveryId,
    required this.driverId,
    required this.driverName,
  });

  /// Reads a `dispatch.updated` message from the branch channel. Null when it is not about a delivery order of a branch part.
  static PartPatch? fromEvent(PusherEvent e) {
    final d = e.data;
    final id = d['fulfillment_id']?.toString() ?? '';
    final type = d['fulfillment_type']?.toString();
    final status = d['fulfillment_status']?.toString() ?? (d['kind'] == 'order' && d['order_level'] != true ? d['status']?.toString() : null);
    if (id.isEmpty || status == null || status.isEmpty || (type != null && type != 'delivery')) return null;
    String? text(String key) {
      final v = d[key]?.toString();
      return v == null || v.isEmpty ? null : v;
    }

    return PartPatch(
      fulfillmentId: id,
      orderId: d['order_id']?.toString() ?? '',
      number: d['number']?.toString() ?? '',
      seq: int.tryParse('${d['seq'] ?? ''}') ?? 0,
      status: status,
      deliveryStatus: text('delivery_status'),
      deliveryId: text('delivery_id'),
      driverId: text('driver_id'),
      driverName: text('driver_name'),
    );
  }

  final String fulfillmentId;
  final String orderId;
  final String number;
  final int seq;

  /// The branch part's status (pending, accepted, preparing, ready, dispatched, out_for_delivery, failed, delivered, cancelled …).
  final String status;
  final String? deliveryStatus;
  final String? deliveryId;
  final String? driverId;
  final String? driverName;

  String get stage => stageOfStatus(status);
}

/// The row as the overlay shows it.
QueueOrder effectiveOrder(QueueOrder row, Map<String, PartPatch> patches) {
  final p = patches[row.fulfillmentId];
  if (p == null || p.seq < row.seq) return row;
  return row.withState(
    status: p.status,
    seq: p.seq,
    deliveryStatus: p.deliveryStatus,
    deliveryId: p.deliveryId,
    driverId: p.driverId,
    driverName: p.driverName,
    clearDriver: p.driverId == null,
  );
}

/// The rows of one queue [stage] after the messages are laid over what the server last said for ALL stages: a row that moved in
/// from another stage appears, one that moved out (or finished) disappears. Rows already in the stage keep the server's order;
/// those that arrived by a message follow them.
List<QueueOrder> mergeStage(String stage, Map<String, List<QueueOrder>> byStage, Map<String, PartPatch> patches) {
  if (patches.isEmpty) return byStage[stage] ?? const [];
  final here = <QueueOrder>[];
  final moved = <QueueOrder>[];
  final seen = <String>{};
  for (final entry in [for (final s in kQueueStages) MapEntry(s, byStage[s] ?? const <QueueOrder>[])]) {
    for (final row in entry.value) {
      if (!seen.add(row.fulfillmentId)) continue;
      final eff = effectiveOrder(row, patches);
      if (stageOfStatus(eff.status) != stage) continue;
      (entry.key == stage ? here : moved).add(eff);
    }
  }
  return [...here, ...moved];
}

/// How the stage counts of the overview change because of the messages: each stage and "waiting for a driver" by the number of rows
/// that moved in or out compared with what the server counted.
StaffOverview adjustOverview(StaffOverview o, Map<String, List<QueueOrder>> byStage, Map<String, PartPatch> patches) {
  if (patches.isEmpty) return o;
  final delta = {for (final s in kQueueStages) s: 0};
  var waiting = 0;
  bool isWaiting(QueueOrder r) => stageOfStatus(r.status) == 'ready' && r.driverId == null || r.deliveryStatus == 'pending';
  final seen = <String>{};
  for (final entry in byStage.entries) {
    for (final row in entry.value) {
      if (!seen.add(row.fulfillmentId)) continue;
      final eff = effectiveOrder(row, patches);
      if (identical(eff, row)) continue;
      final from = stageOfStatus(row.status);
      final to = stageOfStatus(eff.status);
      if (from != to) {
        if (delta.containsKey(from)) delta[from] = delta[from]! - 1;
        if (delta.containsKey(to)) delta[to] = delta[to]! + 1;
      }
      waiting += (isWaiting(eff) ? 1 : 0) - (isWaiting(row) ? 1 : 0);
    }
  }
  return o.shifted(
    fresh: delta['new']!,
    preparing: delta['preparing']!,
    ready: delta['ready']!,
    out: delta['out']!,
    failed: delta['failed']!,
    waitingForDriver: waiting,
  );
}
