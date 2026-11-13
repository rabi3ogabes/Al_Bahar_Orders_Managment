import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show ProviderListenable;

/// The rows of a live list and whether that list is up to date right now.
///
/// A row that is missing from a list that is still reloading (or whose last reload failed) is NOT gone: the list may
/// simply not have caught up. Only a [settled] list can say a row has left it.
class LiveList<T> {
  const LiveList(this.items, {required this.settled});
  final List<T> items;
  final bool settled;
}

/// One async list as a [LiveList]: settled once it holds data and is neither reloading nor failing.
LiveList<T> liveOf<T>(AsyncValue<List<T>> value) =>
    LiveList(value.value ?? const [], settled: value.hasValue && !value.isLoading && !value.hasError);

/// Keeps the record shown in a window in step with its live list (polling and real-time pings reload the list):
/// the window always shows the newest version of the record. When the record has left the list (delivered,
/// reassigned, cancelled, handed over) the window closes itself and says why, instead of showing a stale card whose
/// buttons the server would refuse.
///
/// Only the window's own route is closed (it is removed even when another window is open above it), and the message
/// appears on the page underneath once the window is gone.
class LiveRecord<T> extends ConsumerStatefulWidget {
  const LiveRecord({
    super.key,
    required this.initial,
    required this.source,
    required this.isSame,
    required this.builder,
    this.goneMessage,
    this.closeWhenGone = true,
  });

  /// The record as it was when the window opened; shown until the list says otherwise.
  final T initial;
  final ProviderListenable<LiveList<T>> source;
  final bool Function(T item) isSame;
  final Widget Function(BuildContext context, T item) builder;

  /// What to tell the person when the record leaves the list (read at that moment); null closes without a message.
  final String Function(BuildContext context)? goneMessage;

  /// Only one widget of a window (its body) closes it; the others (its pinned footer) just follow the record.
  final bool closeWhenGone;

  @override
  ConsumerState<LiveRecord<T>> createState() => _LiveRecordState<T>();
}

class _LiveRecordState<T> extends ConsumerState<LiveRecord<T>> {
  late T _item = widget.initial;
  bool _closing = false;

  @override
  Widget build(BuildContext context) {
    final live = ref.watch(widget.source);
    final matches = live.items.where(widget.isSame);
    if (matches.isNotEmpty) {
      _item = matches.first;
    } else if (live.settled && widget.closeWhenGone && !_closing) {
      _closing = true;
      WidgetsBinding.instance.addPostFrameCallback((_) => _close());
    }
    return widget.builder(context, _item);
  }

  void _close() {
    if (!mounted) return;
    final route = ModalRoute.of(context);
    final navigator = Navigator.of(context);
    final messenger = ScaffoldMessenger.maybeOf(context);
    final message = widget.goneMessage?.call(context);
    if (route != null && route.isActive) {
      if (route.isCurrent) {
        navigator.pop();
      } else {
        navigator.removeRoute(route);
      }
    }
    if (message != null && messenger != null) {
      messenger
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(message)));
    }
  }
}
