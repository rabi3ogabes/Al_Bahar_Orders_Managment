import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import '../utils/platform.dart';
import 'live_order.dart';

/// What the iPhone did with an order.
enum LiveActivityResult {
  /// A new Live Activity is on the lock screen and in the Dynamic Island.
  started,

  /// The running activity was updated in place.
  updated,

  /// The activity showed its last state and was ended (the order is over).
  ended,

  /// There is no Live Activity here (not an iPhone, iOS before 16.2, the widget is not installed in the app, or the customer has
  /// switched Live Activities off in the iPhone settings): the caller keeps to its usual notification.
  unavailable,
}

/// A token Apple gave the app, to be reported to the server so it can update the activity (or start one) without the app running.
@immutable
class LiveActivityTokenEvent {
  const LiveActivityTokenEvent({required this.kind, required this.token, required this.environment, this.orderId});

  /// `activity` for one running Live Activity (then [orderId] is set), `start` for the phone's push-to-start token (iOS 17.2+).
  final String kind;
  final String token;

  /// `production` or `sandbox`: which Apple servers the token works on (a build run from Xcode is sandbox).
  final String environment;
  final String? orderId;

  bool get isStart => kind == 'start';

  /// What makes this token the same report as an earlier one: one activity token per order, one start token per phone.
  String get slot => isStart ? 'start' : 'activity:$orderId';

  /// Null for a map that is not a usable token (a native side that sends something unexpected is ignored, never an error).
  static LiveActivityTokenEvent? tryParse(Object? raw) {
    if (raw is! Map) return null;
    final kind = raw['kind']?.toString();
    final token = raw['token']?.toString().trim() ?? '';
    final order = raw['order_id']?.toString();
    if (token.isEmpty || (kind != 'activity' && kind != 'start')) return null;
    if (kind == 'activity' && (order == null || order.isEmpty)) return null;
    final env = raw['environment']?.toString() == 'sandbox' ? 'sandbox' : 'production';
    return LiveActivityTokenEvent(
      kind: kind!,
      token: token,
      environment: env,
      orderId: kind == 'activity' ? order : null,
    );
  }

  @override
  bool operator ==(Object other) =>
      other is LiveActivityTokenEvent &&
      other.kind == kind &&
      other.token == token &&
      other.environment == environment &&
      other.orderId == orderId;

  @override
  int get hashCode => Object.hash(kind, token, environment, orderId);

  @override
  String toString() => 'LiveActivityTokenEvent($slot)';
}

/// The iPhone Live Activity of an order (lock screen and Dynamic Island, like a match score). Best effort in every call: a missing
/// or failing native side never throws into the order flow, it just answers "unavailable".
abstract class LiveActivityPlatform {
  /// True on an iPhone with iOS 16.2 or later, with the widget extension in the app and Live Activities allowed in the settings.
  Future<bool> isAvailable();

  /// Starts the order's activity, or updates it in place when it is running. A finished order shows its last state and ends.
  Future<LiveActivityResult> show(LiveOrder order, {required String locale, String? stageLabel});

  /// Ends the order's activity at once (the order is gone from the server's list).
  Future<void> end(String orderId);

  /// Ends every activity (sign-out: nothing of the customer stays on a lock screen).
  Future<void> endAll();

  /// New tokens as Apple hands them over (an activity's token appears shortly after it starts and can change later).
  Stream<LiveActivityTokenEvent> get tokens;

  /// Tokens that arrived before anybody listened (the app was started in the background by a remote start).
  Future<List<LiveActivityTokenEvent>> pendingTokens();
}

/// Talks to Swift over the `albahar/live_activity` method channel (`mobile/ios/Runner/LiveOrderBridge.swift`). Not an iPhone, no native
/// side in this build (the Xcode steps of docs/guides/IOS_LIVE_ACTIVITY.md are not done yet), or a test: every call answers
/// "unavailable" without throwing, and nothing is retried.
class MethodChannelLiveActivityPlatform implements LiveActivityPlatform {
  MethodChannelLiveActivityPlatform({MethodChannel? channel, bool? ios})
    : _channel = channel ?? const MethodChannel(channelName),
      _ios = ios ?? (!kIsWeb && platformCode() == 'ios');

  static const channelName = 'albahar/live_activity';

  final MethodChannel _channel;
  final bool _ios;
  bool _missing = false;
  StreamController<LiveActivityTokenEvent>? _tokens;

  bool get _usable => _ios && !_missing;

  Future<T?> _call<T>(String method, [Object? arguments]) async {
    if (!_usable) return null;
    try {
      return await _channel.invokeMethod<T>(method, arguments);
    } on MissingPluginException {
      _missing = true;
    } on PlatformException catch (e) {
      debugPrint('Live activity $method failed: ${e.code}');
    } catch (_) {
      // No binding (a plain unit test) or the engine is going away: the same as no native side.
      _missing = true;
    }
    return null;
  }

  @override
  Future<bool> isAvailable() async => await _call<bool>('isAvailable') ?? false;

  @override
  Future<LiveActivityResult> show(LiveOrder order, {required String locale, String? stageLabel}) async {
    final args = <String, Object?>{
      ...order.toChannelMap(locale: locale),
      'brand_accent': order.brandAccent?.toARGB32(),
      'step_label': stageLabel,
    }..remove('driver_phone'); // a Live Activity is readable on a locked screen: the driver's number never goes near it
    final answer = await _call<String>('show', args);
    return switch (answer) {
      'started' => LiveActivityResult.started,
      'updated' => LiveActivityResult.updated,
      'ended' => LiveActivityResult.ended,
      _ => LiveActivityResult.unavailable,
    };
  }

  @override
  Future<void> end(String orderId) async {
    await _call<void>('end', orderId);
  }

  @override
  Future<void> endAll() async {
    await _call<void>('endAll');
  }

  @override
  Stream<LiveActivityTokenEvent> get tokens {
    if (!_ios) return const Stream.empty();
    final existing = _tokens;
    if (existing != null) return existing.stream;
    final controller = StreamController<LiveActivityTokenEvent>.broadcast();
    _tokens = controller;
    _channel.setMethodCallHandler((call) async {
      if (call.method != 'token') return null;
      final event = LiveActivityTokenEvent.tryParse(call.arguments);
      if (event != null && !controller.isClosed) controller.add(event);
      return null;
    });
    return controller.stream;
  }

  @override
  Future<List<LiveActivityTokenEvent>> pendingTokens() async {
    final rows = await _call<List<Object?>>('pendingTokens');
    return [for (final row in rows ?? const <Object?>[]) ?LiveActivityTokenEvent.tryParse(row)];
  }
}

/// No Live Activities: Android, the web, tests that do not care.
class NoLiveActivity implements LiveActivityPlatform {
  const NoLiveActivity();

  @override
  Future<bool> isAvailable() async => false;

  @override
  Future<LiveActivityResult> show(LiveOrder order, {required String locale, String? stageLabel}) async =>
      LiveActivityResult.unavailable;

  @override
  Future<void> end(String orderId) async {}

  @override
  Future<void> endAll() async {}

  @override
  Stream<LiveActivityTokenEvent> get tokens => const Stream.empty();

  @override
  Future<List<LiveActivityTokenEvent>> pendingTokens() async => const [];
}
