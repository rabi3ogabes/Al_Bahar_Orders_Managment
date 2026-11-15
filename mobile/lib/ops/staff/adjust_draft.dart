import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/foundation.dart';

/// What the employee has chosen so far in "Missing an item?": at most one change per line, kept in the order of the parcel's lines.
/// Nothing here talks to the server; the flow sends [changes] once the employee reviews them.
@immutable
class AdjustDraft {
  const AdjustDraft._(this._items, this._byLine);

  /// An empty draft over the parcel's lines (a line that an earlier correction already emptied cannot be changed again).
  factory AdjustDraft.of(List<AdjustItem> items) => AdjustDraft._([
    for (final i in items)
      if (i.canChange) i,
  ], const {});

  final List<AdjustItem> _items;
  final Map<String, AdjustChange> _byLine;

  /// The lines the employee can still change.
  List<AdjustItem> get items => _items;

  bool get isEmpty => _byLine.isEmpty;
  int get count => _byLine.length;

  /// The change chosen for [orderItemId], if any.
  AdjustChange? of(String orderItemId) => _byLine[orderItemId];

  /// The changes to send, in the order of the lines.
  List<AdjustChange> get changes => [
    for (final i in _items)
      if (_byLine[i.orderItemId] != null) _byLine[i.orderItemId]!,
  ];

  AdjustItem? item(String orderItemId) => _items.where((i) => i.orderItemId == orderItemId).firstOrNull;

  AdjustDraft _with(String id, AdjustChange? change) {
    final next = {..._byLine};
    if (change == null) {
      next.remove(id);
    } else {
      next[id] = change;
    }
    return AdjustDraft._(_items, next);
  }

  /// [newQuantity] of this line instead of all of it. Never more than ordered (that is no change at all), and 0 means the line goes.
  AdjustDraft reduce(AdjustItem item, int newQuantity) {
    if (newQuantity >= item.quantity) return _with(item.orderItemId, null);
    if (newQuantity <= 0) return remove(item);
    return _with(item.orderItemId, AdjustReduce(item.orderItemId, newQuantity));
  }

  AdjustDraft remove(AdjustItem item) => _with(item.orderItemId, AdjustRemove(item.orderItemId));

  /// The whole line is swapped for [quantity] of [substitute]; the quantity stays within what the branch has and what was ordered.
  AdjustDraft replace(AdjustItem item, Substitute substitute, int quantity) {
    final most = substitute.available < item.quantity ? substitute.available : item.quantity;
    final q = quantity.clamp(1, most < 1 ? 1 : most);
    return _with(item.orderItemId, AdjustReplace(item.orderItemId, substitute, q));
  }

  AdjustDraft undo(String orderItemId) => _with(orderItemId, null);

  /// Takes the replacements back (the server said one of them is gone). With [positions] only those of the request's `changes`.
  AdjustDraft withoutReplacements({Set<int>? positions}) {
    final sent = changes;
    final next = {..._byLine};
    for (var i = 0; i < sent.length; i++) {
      if (sent[i] is AdjustReplace && (positions == null || positions.contains(i))) next.remove(sent[i].orderItemId);
    }
    return AdjustDraft._(_items, next);
  }

  /// The same changes over a freshly read list of lines; a change whose line is gone or now has fewer pieces than it needs is dropped.
  AdjustDraft rebase(List<AdjustItem> fresh) {
    final next = AdjustDraft.of(fresh);
    var out = next;
    for (final c in changes) {
      final item = next.item(c.orderItemId);
      if (item == null) continue;
      out = switch (c) {
        AdjustReduce(:final newQuantity) => out.reduce(item, newQuantity),
        AdjustRemove() => out.remove(item),
        AdjustReplace(:final substitute, :final quantity) => out.replace(item, substitute, quantity),
      };
    }
    return out;
  }
}
