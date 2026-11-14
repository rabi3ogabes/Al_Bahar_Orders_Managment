import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

enum RealtimeState { idle, connecting, connected, reconnecting, disconnected }

@immutable
class PusherEvent {
  const PusherEvent({required this.channel, required this.event, required this.data});
  final String channel;
  final String event;
  final Map<String, dynamic> data;
}

/// Authorises a private channel: returns the `auth` string
/// (`"<key>:<signature>"`) from the backend's broadcasting auth endpoint.
typedef ChannelAuthorizer = Future<String?> Function(String socketId, String channelName);

typedef SocketConnector = WebSocketChannel Function(Uri uri);

/// Minimal Pusher protocol v7 client (compatible with Laravel Reverb).
///
/// * Lazy: connects on first channel listener, disconnects when idle.
/// * Ref-counted channel subscriptions exposed as broadcast streams.
/// * Automatic reconnect with exponential backoff + jitter; channels are
///   re-subscribed and [states] emits `connected` again so consumers can
///   resync from REST (the server state is authoritative).
/// * Answers `pusher:ping` and sends its own pings on inactivity.
class PusherClient {
  PusherClient({
    Uri? uri,
    this.uriResolver,
    SocketConnector? connector,
    this.authorizer,
    this.maxBackoff = const Duration(seconds: 30),
    this.idleDisconnectAfter = const Duration(seconds: 20),
    this.probeTimeout = const Duration(seconds: 4),
  }) : assert(uri != null || uriResolver != null, 'give the socket address or a way to find it'),
       uri = uri ?? Uri(),
       _connector = connector ?? WebSocketChannel.connect;

  /// The socket address when it is fixed (tests, dev). The apps pass [uriResolver] instead: it asks the server where the socket is
  /// (GET /realtime-config) every time a connection is opened, so a build never carries a socket address that can go stale.
  final Uri uri;
  final Future<Uri> Function()? uriResolver;

  /// How long a connection that has just come back from the background gets to answer a ping before it is declared dead.
  final Duration probeTimeout;
  final ChannelAuthorizer? authorizer;
  final Duration maxBackoff;
  final Duration idleDisconnectAfter;
  final SocketConnector _connector;

  WebSocketChannel? _socket;
  StreamSubscription<dynamic>? _socketSub;
  String? _socketId;
  Duration _activityTimeout = const Duration(seconds: 120);
  Timer? _activityTimer;
  Timer? _pongTimer;
  Timer? _reconnectTimer;
  Timer? _idleTimer;
  Timer? _probeTimer;
  int _attempt = 0;
  int _connectGeneration = 0;
  bool _opening = false;
  bool _closedByUser = false;
  final Map<String, Timer> _authRetry = {};
  final Map<String, int> _authAttempts = {};
  final Set<String> _confirmed = {};

  final _stateController = StreamController<RealtimeState>.broadcast();
  RealtimeState _state = RealtimeState.idle;
  final Map<String, StreamController<PusherEvent>> _channels = {};
  final Set<String> _subscribed = {};

  RealtimeState get state => _state;
  Stream<RealtimeState> get states => _stateController.stream;
  String? get socketId => _socketId;

  /// Broadcast stream of events for [channelName]. Subscribes on first
  /// listen and unsubscribes when the last listener cancels.
  Stream<PusherEvent> channel(String channelName) {
    final existing = _channels[channelName];
    if (existing != null) return existing.stream;
    late final StreamController<PusherEvent> controller;
    controller = StreamController<PusherEvent>.broadcast(
      onListen: () {
        _idleTimer?.cancel();
        _ensureConnected();
        if (_state == RealtimeState.connected) unawaited(_subscribe(channelName));
      },
      onCancel: () {
        _channels.remove(channelName);
        _confirmed.remove(channelName);
        _authRetry.remove(channelName)?.cancel();
        _authAttempts.remove(channelName);
        if (_subscribed.remove(channelName)) {
          _send({
            'event': 'pusher:unsubscribe',
            'data': {'channel': channelName},
          });
        }
        unawaited(controller.close());
        if (_channels.isEmpty) {
          _idleTimer?.cancel();
          _idleTimer = Timer(idleDisconnectAfter, () => unawaited(disconnect()));
        }
      },
    );
    _channels[channelName] = controller;
    return controller.stream;
  }

  void _setState(RealtimeState s) {
    if (_state == s) return;
    _state = s;
    if (!_stateController.isClosed) _stateController.add(s);
  }

  void _ensureConnected() {
    if (_socket != null || _opening || _reconnectTimer?.isActive == true) return;
    _closedByUser = false;
    _connect();
  }

  void _connect() {
    _setState(_attempt == 0 ? RealtimeState.connecting : RealtimeState.reconnecting);
    final generation = ++_connectGeneration;
    _opening = true;
    unawaited(_open(generation));
  }

  Future<void> _open(int generation) async {
    Uri target;
    try {
      target = uriResolver != null ? await uriResolver!() : uri;
    } catch (_) {
      if (generation == _connectGeneration) {
        _opening = false;
        _onClosed();
      }
      return;
    }
    // A newer attempt (or a disconnect) has taken over while the address was being looked up.
    if (generation != _connectGeneration || _closedByUser) {
      if (generation == _connectGeneration) _opening = false;
      return;
    }
    _opening = false;
    try {
      final socket = _connector(target);
      _socket = socket;
      _socketSub = socket.stream.listen(_onMessage, onError: (_) => _onClosed(), onDone: _onClosed, cancelOnError: true);
    } catch (_) {
      _onClosed();
    }
  }

  void _onMessage(dynamic raw) {
    _resetActivityTimer();
    Map<String, dynamic> message;
    try {
      message = Map<String, dynamic>.from(jsonDecode(raw as String) as Map);
    } catch (_) {
      return;
    }
    final event = message['event']?.toString() ?? '';
    final channel = message['channel']?.toString();
    final data = _decodeData(message['data']);

    switch (event) {
      case 'pusher:connection_established':
        _socketId = data['socket_id']?.toString();
        final timeout = int.tryParse('${data['activity_timeout'] ?? ''}');
        if (timeout != null && timeout > 0) _activityTimeout = Duration(seconds: min(timeout, 120));
        _attempt = 0;
        _subscribed.clear();
        _confirmed.clear();
        _cancelAuthRetries();
        _setState(RealtimeState.connected);
        for (final name in _channels.keys.toList()) {
          unawaited(_subscribe(name));
        }
        _resetActivityTimer();
      case 'pusher:ping':
        _send({'event': 'pusher:pong', 'data': <String, dynamic>{}});
      case 'pusher:pong':
        _pongTimer?.cancel();
        _probeTimer?.cancel();
      case 'pusher:error':
        final code = int.tryParse('${data['code'] ?? ''}');
        if (code == 4009) {
          // A private channel was refused (the sign-in token had just expired, a slow answer): the connection itself is fine. Ask for a
          // fresh signature and try the channels that are not confirmed yet again, instead of giving up the whole socket.
          for (final name in _channels.keys) {
            if (_confirmed.contains(name)) continue;
            _subscribed.remove(name);
            _scheduleAuthRetry(name);
          }
        } else if (code != null && code >= 4000 && code < 4100) {
          // The rest of 4000–4099 (unknown app key, wrong protocol, disabled app): reconnecting cannot help.
          _closedByUser = true;
          unawaited(_closeSocket());
          _setState(RealtimeState.disconnected);
        }
      case 'pusher_internal:subscription_succeeded':
        if (channel != null) {
          _confirmed.add(channel);
          _authAttempts.remove(channel);
        }
      default:
        if (channel != null) {
          _channels[channel]?.add(PusherEvent(channel: channel, event: event, data: data));
        }
    }
  }

  static Map<String, dynamic> _decodeData(Object? data) {
    if (data is Map) return Map<String, dynamic>.from(data);
    if (data is String && data.isNotEmpty) {
      try {
        final decoded = jsonDecode(data);
        if (decoded is Map) return Map<String, dynamic>.from(decoded);
      } catch (_) {}
    }
    return <String, dynamic>{};
  }

  Future<void> _subscribe(String name) async {
    if (_subscribed.contains(name)) return;
    final payload = <String, dynamic>{'channel': name};
    final connection = _socketId;
    if (name.startsWith('private-') || name.startsWith('presence-')) {
      final socketId = connection;
      if (socketId == null || authorizer == null) return;
      try {
        final auth = await authorizer!(socketId, name);
        if (auth == null) {
          _scheduleAuthRetry(name);
          return;
        }
        payload['auth'] = auth;
      } catch (_) {
        // The sign-in call failed (offline, a server hiccup): the channel is not lost, try again shortly.
        _scheduleAuthRetry(name);
        return;
      }
    }
    if (_state != RealtimeState.connected || !_channels.containsKey(name) || connection != _socketId) return;
    _subscribed.add(name);
    _send({'event': 'pusher:subscribe', 'data': payload});
  }

  /// Asks for the channel's signature again after 2, 4, 8 … 30 seconds (only while the socket is up and somebody listens).
  void _scheduleAuthRetry(String name) {
    if (_authRetry[name]?.isActive == true) return;
    final attempt = (_authAttempts[name] ?? 0) + 1;
    _authAttempts[name] = attempt;
    final seconds = min(30, 2 * pow(2, min(attempt - 1, 4)).toInt());
    _authRetry[name] = Timer(Duration(seconds: seconds), () {
      _authRetry.remove(name);
      if (_state == RealtimeState.connected && _channels.containsKey(name)) unawaited(_subscribe(name));
    });
  }

  void _cancelAuthRetries() {
    for (final t in _authRetry.values) {
      t.cancel();
    }
    _authRetry.clear();
    _authAttempts.clear();
  }

  void _send(Map<String, dynamic> message) {
    try {
      _socket?.sink.add(jsonEncode(message));
    } catch (_) {}
  }

  void _resetActivityTimer() {
    _activityTimer?.cancel();
    _activityTimer = Timer(_activityTimeout, () {
      _send({'event': 'pusher:ping', 'data': <String, dynamic>{}});
      _pongTimer?.cancel();
      _pongTimer = Timer(const Duration(seconds: 30), () => unawaited(_closeSocket().then((_) => _onClosed())));
    });
  }

  void _onClosed() {
    _activityTimer?.cancel();
    _pongTimer?.cancel();
    _probeTimer?.cancel();
    _cancelAuthRetries();
    unawaited(_socketSub?.cancel());
    _socketSub = null;
    _socket = null;
    _socketId = null;
    _subscribed.clear();
    _confirmed.clear();
    if (_closedByUser || _channels.isEmpty) {
      _setState(RealtimeState.disconnected);
      return;
    }
    _setState(RealtimeState.reconnecting);
    _attempt++;
    final base = min(maxBackoff.inMilliseconds, 500 * pow(2, min(_attempt, 8)).toInt());
    final jitter = Random().nextInt(max(1, base ~/ 3));
    _reconnectTimer?.cancel();
    _reconnectTimer = Timer(Duration(milliseconds: base + jitter), _connect);
  }

  Future<void> _closeSocket() async {
    final socket = _socket;
    await _socketSub?.cancel();
    _socketSub = null;
    _socket = null;
    try {
      await socket?.sink.close();
    } catch (_) {}
  }

  /// Forces an immediate reconnect attempt (e.g. app resumed / back online).
  void reconnectNow() {
    if (_channels.isEmpty || _state == RealtimeState.connected) return;
    _reconnectTimer?.cancel();
    _attempt = 0;
    _closedByUser = false;
    if (_socket == null && !_opening) _connect();
  }

  /// Checks a connection that looks alive: after the phone slept or the network changed, a socket is often dead without knowing it.
  /// Sends a ping and, when no answer comes within [probeTimeout], drops the socket and reconnects at once.
  void probe() {
    if (_state != RealtimeState.connected || _socket == null) {
      reconnectNow();
      return;
    }
    _probeTimer?.cancel();
    _send({'event': 'pusher:ping', 'data': <String, dynamic>{}});
    _probeTimer = Timer(probeTimeout, () {
      unawaited(_closeSocket().then((_) => _onClosed()));
    });
  }

  Future<void> disconnect() async {
    _closedByUser = true;
    _connectGeneration++;
    _opening = false;
    _reconnectTimer?.cancel();
    _activityTimer?.cancel();
    _pongTimer?.cancel();
    _probeTimer?.cancel();
    _cancelAuthRetries();
    await _closeSocket();
    _subscribed.clear();
    _confirmed.clear();
    _setState(RealtimeState.disconnected);
  }

  Future<void> dispose() async {
    await disconnect();
    for (final c in _channels.values) {
      await c.close();
    }
    _channels.clear();
    await _stateController.close();
  }
}
