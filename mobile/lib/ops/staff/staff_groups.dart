import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/*
 * How the staff home groups the branch queue into its two order tabs. Pure functions on plain values, tested without a phone.
 *
 *  - "To do":      new, preparing, ready (the work the branch still has to do), grouped by stage, one vertical list.
 *  - "On the way": a failed delivery first (it needs a person), then the deliveries out with a driver.
 *
 * The server keeps one queue per stage (each capped at 100 rows by the API, oldest first). A tab reads its stages through the
 * real-time overlay (staffQueueViewProvider), where every order sits in exactly ONE stage, so putting the stages side by side can
 * never lose or repeat an order, and an order that moves (a message, or the employee's own step) simply changes group.
 */

/// The stages of the "To do" tab, in the order the work flows.
const kTodoStages = ['new', 'preparing', 'ready'];

/// The stages of the "On the way" tab: a failed delivery first, so it can never be missed.
const kWayStages = ['failed', 'out'];

/// The stages of the Pickup list of the "To do" tab: new, being prepared, ready for the customer to collect.
const kPickupStages = ['new', 'preparing', 'ready'];

/// The stage of a pickup part by its status (a part that was collected or cancelled is in none).
String pickupStageOf(String status) => switch (status) {
  'pending' => 'new',
  'accepted' || 'preparing' => 'preparing',
  'pickup_ready' || 'ready' => 'ready',
  _ => 'done',
};

/// The rows of one tab, stage by stage, once every stage of the tab has been read.
@immutable
class StageRows {
  const StageRows({this.byStage, this.error, this.more = const {}, this.loadingMore = const {}});

  /// Null until every stage of the tab has an answer (the first read, or a first read that failed).
  final Map<String, List<QueueOrder>>? byStage;

  /// A stage that could not be read. With [byStage] set the rows are the last good ones ([stale]); without, nothing can be shown.
  final Object? error;

  /// The stages that have a next page ("Load more"), and those whose next page is being read now.
  final Set<String> more;
  final Set<String> loadingMore;

  bool get ready => byStage != null;
  bool get stale => ready && error != null;
  bool get failed => !ready && error != null;
  bool get loading => !ready && error == null;

  List<QueueOrder> of(String stage) => byStage?[stage] ?? const [];
  int count(String stage) => of(stage).length;
  int total(List<String> stages) => stages.fold(0, (n, s) => n + count(s));
}

/// One header and the orders under it.
@immutable
class StageGroup {
  const StageGroup(this.stage, this.orders);
  final String stage;
  final List<QueueOrder> orders;
}

/// The groups to draw: the [stages] in order, a stage with no order left out, and, when [only] is set, just that stage.
List<StageGroup> groupsOf(StageRows rows, List<String> stages, {String? only}) => [
  for (final s in stages)
    if ((only == null || only == s) && rows.of(s).isNotEmpty) StageGroup(s, rows.of(s)),
];

/// The answers of a tab's stage queues as one value. The tab shows rows once EVERY stage has an answer (a half-filled list would
/// pass for "nothing waiting"); a stage that fails while an older answer exists keeps the old rows and says so ([StageRows.stale]).
StageRows combineStages(
  Map<String, AsyncValue<List<QueueOrder>>> parts, {
  Set<String> more = const {},
  Set<String> loadingMore = const {},
}) {
  final rows = <String, List<QueueOrder>>{};
  Object? error;
  for (final e in parts.entries) {
    final v = e.value;
    final value = v.value;
    if (v.hasValue && value != null) rows[e.key] = oldestFirst(value);
    if (v.hasError) error ??= v.error;
  }
  return StageRows(
    byStage: rows.length == parts.length ? rows : null,
    error: error,
    more: more,
    loadingMore: loadingMore,
  );
}

/// The longest wait first (a row that arrived by a message sits among the others by its clock, not at the end). Rows with equal waits keep their order.
List<QueueOrder> oldestFirst(List<QueueOrder> rows) {
  final indexed = [for (var i = 0; i < rows.length; i++) (i: i, row: rows[i])];
  indexed.sort((a, b) {
    final byAge = (b.row.clock?.ageMinutes ?? 0).compareTo(a.row.clock?.ageMinutes ?? 0);
    if (byAge != 0) return byAge;
    final x = a.row.placedAt;
    final y = b.row.placedAt;
    if (x != null && y != null) {
      final byPlaced = x.compareTo(y);
      if (byPlaced != 0) return byPlaced;
    }
    return a.i.compareTo(b.i);
  });
  return [for (final e in indexed) e.row];
}
