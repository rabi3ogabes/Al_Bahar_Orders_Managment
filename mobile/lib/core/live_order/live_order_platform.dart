import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import '../utils/platform.dart';
import 'live_order.dart';

/// What the native renderer did with a state.
enum LiveOrderShowResult {
  /// Drawn or updated in place.
  posted,

  /// A duplicate or older update: nothing to change.
  skipped,

  /// The customer swiped this order's card away, so it stays away.
  dismissed,

  /// Notifications are off for the app.
  disabled,

  /// No native renderer here (iOS, tests, an old build): the caller falls back to the plain progress notification.
  unsupported,
}

/// Notification permission as the customer experiences it.
enum LiveOrderPermission {
  granted,

  /// Not allowed yet, and the system dialog can still be shown.
  denied,

  /// Off, and only the system settings can turn it on (denied for good, or switched off there).
  blocked;

  static LiveOrderPermission parse(Object? value) => switch (value) {
    'granted' => granted,
    'denied' => denied,
    'blocked' => blocked,
    _ => granted,
  };
}

/// The native live order notification (Android: `qa.albahar.customer.liveorder`). Everything here is best effort: a
/// failing or missing platform never throws into the order flow.
abstract class LiveOrderPlatform {
  Future<LiveOrderShowResult> show(
    LiveOrder order, {
    required String locale,
    bool force = false,
    bool arabicDigits = false,
  });
  Future<void> cancel(String orderId);
  Future<void> cancelAll();

  /// True when notifications of the app are on (and the live order channel has not been silenced). Platforms without a
  /// native side answer true: there is nothing of ours to switch off.
  Future<bool> areEnabled();

  /// Where notification permission stands. Platforms without a runtime permission of our own report `granted`.
  Future<LiveOrderPermission> permission();

  /// Shows the system permission dialog when it can still be shown; returns the new standing.
  Future<LiveOrderPermission> requestPermission();

  /// Opens the app's notification settings.
  Future<bool> openSettings();
}

/// Talks to Kotlin over the `albahar/live_order` method channel. On a platform or in a test without the channel
/// every call answers "unsupported" once, and the controller uses the plain progress notification instead.
class MethodChannelLiveOrderPlatform implements LiveOrderPlatform {
  MethodChannelLiveOrderPlatform({MethodChannel? channel, bool? android})
    : _channel = channel ?? const MethodChannel(channelName),
      _android = android ?? (!kIsWeb && platformCode() == 'android');

  static const channelName = 'albahar/live_order';

  final MethodChannel _channel;
  final bool _android;
  bool _missing = false;

  bool get _usable => _android && !_missing;

  /// Calls [method]; null when there is no native side (and remembers that) or the call failed.
  Future<T?> _call<T>(String method, [Object? arguments]) async {
    if (!_usable) return null;
    try {
      return await _channel.invokeMethod<T>(method, arguments);
    } on MissingPluginException {
      _missing = true;
    } on PlatformException catch (e) {
      debugPrint('Live order $method failed: ${e.code}');
    } catch (_) {
      // No binding (a plain unit test) or the engine is going away: same as no native side.
      _missing = true;
    }
    return null;
  }

  @override
  Future<LiveOrderShowResult> show(
    LiveOrder order, {
    required String locale,
    bool force = false,
    bool arabicDigits = false,
  }) async {
    final answer = await _call<String>(
      'show',
      order.toChannelMap(locale: locale, force: force, arabicDigits: arabicDigits),
    );
    if (!_usable) return LiveOrderShowResult.unsupported;
    return switch (answer) {
      'skipped' => LiveOrderShowResult.skipped,
      'dismissed' => LiveOrderShowResult.dismissed,
      'disabled' => LiveOrderShowResult.disabled,
      // `posted`, and `invalid` / a failed call: the native side exists, so no fallback card on top of it.
      _ => LiveOrderShowResult.posted,
    };
  }

  @override
  Future<void> cancel(String orderId) async {
    await _call<void>('cancel', orderId);
  }

  @override
  Future<void> cancelAll() async {
    await _call<void>('cancelAll');
  }

  @override
  Future<bool> areEnabled() async => await _call<bool>('areEnabled') ?? true;

  @override
  Future<LiveOrderPermission> permission() async => LiveOrderPermission.parse(await _call<String>('permissionStatus'));

  @override
  Future<LiveOrderPermission> requestPermission() async =>
      LiveOrderPermission.parse(await _call<String>('requestPermission'));

  @override
  Future<bool> openSettings() async => await _call<bool>('openSettings') ?? false;
}
