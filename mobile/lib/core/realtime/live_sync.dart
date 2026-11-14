import 'dart:async';

import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:flutter/foundation.dart';

/// What the person sees of the connection: green "Live", amber "Reconnecting", grey "Offline".
enum LiveLink { live, reconnecting, offline }

/// Why a screen is asked to read again from the server.
enum LiveResync {
  /// A message arrived (bursts are folded into one read).
  event,

  /// The socket came back: whatever was missed while it was down is read again.
  reconnect,

  /// The app came back to the foreground.
  resume,

  /// The phone's network came back.
  network,

  /// The socket is down: a short poll keeps the screen current until it returns.
  poll,
}

/// The ONE rule set every live screen of the three apps follows:
///  - while the socket is connected, nothing is polled: messages arrive, the screen applies them at once, and a burst of messages is
///    confirmed by ONE read ([debounce] after the last one);
///  - on every reconnect, every return to the foreground and every return of the network, the screen reads again (a socket that slept
///    is checked first, so a dead connection is replaced at once instead of after a minute);
///  - while the socket is NOT connected (and the app is in the foreground) the screen reads every [pollEvery] (15 s), and stops the
///    moment the socket is back.
/// It is plain Dart on timers: no widgets, so the same behaviour is tested without a phone.
class LiveSync {
  LiveSync({
    required this.readState,
    required this.states,
    required this.resync,
    this.probeSocket,
    this.reconnectSocket,
    this.pollEvery = const Duration(seconds: 15),
    this.debounce = const Duration(milliseconds: 600),
  });

  final RealtimeState Function() readState;
  final Stream<RealtimeState> states;

  /// Reads again from the server (the screen decides how much: everything, or just the lists that matter).
  final void Function(LiveResync reason) resync;
  final VoidCallback? probeSocket;
  final VoidCallback? reconnectSocket;
  final Duration pollEvery;
  final Duration debounce;

  final _links = StreamController<LiveLink>.broadcast();
  StreamSubscription<RealtimeState>? _sub;
  Timer? _debounce;
  Timer? _poll;
  RealtimeState _socket = RealtimeState.idle;
  bool _online = true;
  bool _foreground = true;
  bool _disposed = false;
  LiveLink _link = LiveLink.reconnecting;

  LiveLink get link => _link;
  Stream<LiveLink> get links => _links.stream;

  /// Starts following the socket. Does not read: the screen has just loaded its data.
  void start() {
    _socket = readState();
    _sub = states.listen(_onSocket);
    _update();
  }

  /// A message arrived: confirm with one read once the burst is over.
  void eventArrived() {
    if (_disposed) return;
    _debounce?.cancel();
    _debounce = Timer(debounce, () => _fire(LiveResync.event));
  }

  /// The app is in front again (or the screen was shown again): check the socket and read.
  void resumed() {
    if (_disposed) return;
    _foreground = true;
    probeSocket?.call();
    _fire(LiveResync.resume);
    _update();
  }

  /// The app went to the background: no polling while nobody looks.
  void paused() {
    _foreground = false;
    _update();
  }

  /// The phone's network went away or came back.
  void network({required bool online}) {
    if (_disposed || _online == online) return;
    _online = online;
    if (online) {
      reconnectSocket?.call();
      _fire(LiveResync.network);
    }
    _update();
  }

  void _onSocket(RealtimeState next) {
    final was = _socket;
    _socket = next;
    if (next == RealtimeState.connected && was != RealtimeState.connected) {
      _debounce?.cancel();
      _fire(LiveResync.reconnect);
    }
    _update();
  }

  void _update() {
    if (_disposed) return;
    final next = !_online
        ? LiveLink.offline
        : switch (_socket) {
            RealtimeState.connected => LiveLink.live,
            RealtimeState.disconnected => LiveLink.offline,
            _ => LiveLink.reconnecting,
          };
    if (next != _link) {
      _link = next;
      if (!_links.isClosed) _links.add(next);
    }
    final shouldPoll = _link != LiveLink.live && _foreground;
    if (shouldPoll && _poll == null) {
      _poll = Timer.periodic(pollEvery, (_) {
        if (_online || _link != LiveLink.offline) _fire(LiveResync.poll);
      });
    } else if (!shouldPoll) {
      _poll?.cancel();
      _poll = null;
    }
  }

  void _fire(LiveResync reason) {
    if (!_disposed) resync(reason);
  }

  @visibleForTesting
  bool get polling => _poll != null;

  void dispose() {
    _disposed = true;
    _debounce?.cancel();
    _poll?.cancel();
    _poll = null;
    unawaited(_sub?.cancel());
    unawaited(_links.close());
  }
}
