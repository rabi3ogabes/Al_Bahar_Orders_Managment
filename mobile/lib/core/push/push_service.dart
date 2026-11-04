import 'dart:async';
import 'dart:convert';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:uuid/uuid.dart';

import '../network/api_client.dart';
import '../providers.dart';
import '../router/deep_links.dart';
import '../storage/app_preferences.dart';
import '../utils/platform.dart';

/// Platform-neutral push message.
@immutable
class PushMessage {
  const PushMessage({required this.data, this.title, this.body});
  final Map<String, dynamic> data;
  final String? title;
  final String? body;
}

/// Adapter over the messaging SDK (Firebase in production, fakes in tests).
abstract class PushMessaging {
  /// Returns false when push is unavailable (e.g. no Firebase config).
  Future<bool> initialize();
  Future<bool> requestPermission();
  Future<String?> getToken();
  Stream<String> get onTokenRefresh;
  Stream<PushMessage> get onForegroundMessage;
  Stream<PushMessage> get onMessageOpenedApp;
  Future<PushMessage?> getInitialMessage();
}

/// Background handler must be a top-level function.
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  // Notification messages are displayed by the OS; data handling happens
  // when the user taps (onMessageOpenedApp / getInitialMessage).
}

class FirebasePushMessaging implements PushMessaging {
  FirebaseMessaging? _fm;

  @override
  Future<bool> initialize() async {
    try {
      if (Firebase.apps.isEmpty) await Firebase.initializeApp();
      _fm = FirebaseMessaging.instance;
      if (!kIsWeb) FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
      await _fm!.setForegroundNotificationPresentationOptions(alert: false, badge: true, sound: false);
      return true;
    } catch (e) {
      // google-services.json / GoogleService-Info.plist missing → no push.
      debugPrint('Push disabled: Firebase not configured (${e.runtimeType})');
      _fm = null;
      return false;
    }
  }

  @override
  Future<bool> requestPermission() async {
    final fm = _fm;
    if (fm == null) return false;
    final settings = await fm.requestPermission();
    return settings.authorizationStatus == AuthorizationStatus.authorized ||
        settings.authorizationStatus == AuthorizationStatus.provisional;
  }

  @override
  Future<String?> getToken() async => _fm?.getToken();

  @override
  Stream<String> get onTokenRefresh => _fm?.onTokenRefresh ?? const Stream.empty();

  @override
  Stream<PushMessage> get onForegroundMessage =>
      _fm == null ? const Stream.empty() : FirebaseMessaging.onMessage.map(_map);

  @override
  Stream<PushMessage> get onMessageOpenedApp =>
      _fm == null ? const Stream.empty() : FirebaseMessaging.onMessageOpenedApp.map(_map);

  @override
  Future<PushMessage?> getInitialMessage() async {
    final m = await _fm?.getInitialMessage();
    return m == null ? null : _map(m);
  }

  static PushMessage _map(RemoteMessage m) =>
      PushMessage(data: Map<String, dynamic>.from(m.data), title: m.notification?.title, body: m.notification?.body);
}

class NoopPushMessaging implements PushMessaging {
  @override
  Future<bool> initialize() async => false;
  @override
  Future<bool> requestPermission() async => false;
  @override
  Future<String?> getToken() async => null;
  @override
  Stream<String> get onTokenRefresh => const Stream.empty();
  @override
  Stream<PushMessage> get onForegroundMessage => const Stream.empty();
  @override
  Stream<PushMessage> get onMessageOpenedApp => const Stream.empty();
  @override
  Future<PushMessage?> getInitialMessage() async => null;
}

/// Shows foreground notifications (FCM does not on Android).
abstract class LocalNotifier {
  Future<void> initialize(void Function(String? payload) onTap);
  Future<void> show(PushMessage message);
  Future<String?> launchPayload();
}

class FlutterLocalNotifier implements LocalNotifier {
  final _plugin = FlutterLocalNotificationsPlugin();
  bool _ready = false;

  static const _channel = AndroidNotificationDetails(
    'albahar_default',
    'Al Bahar',
    channelDescription: 'Orders, payments, deliveries and auctions',
    importance: Importance.high,
    priority: Priority.high,
  );

  @override
  Future<void> initialize(void Function(String? payload) onTap) async {
    if (kIsWeb) return;
    try {
      await _plugin.initialize(
        settings: const InitializationSettings(
          android: AndroidInitializationSettings('@mipmap/ic_launcher'),
          iOS: DarwinInitializationSettings(
            requestAlertPermission: false,
            requestBadgePermission: false,
            requestSoundPermission: false,
          ),
        ),
        onDidReceiveNotificationResponse: (r) => onTap(r.payload),
      );
      _ready = true;
    } catch (e) {
      debugPrint('Local notifications unavailable: ${e.runtimeType}');
    }
  }

  @override
  Future<void> show(PushMessage message) async {
    if (!_ready) return;
    await _plugin.show(
      id: DateTime.now().millisecondsSinceEpoch.remainder(1 << 31),
      title: message.title,
      body: message.body,
      notificationDetails: const NotificationDetails(android: _channel, iOS: DarwinNotificationDetails()),
      payload: jsonEncode(message.data),
    );
  }

  @override
  Future<String?> launchPayload() async {
    if (!_ready) return null;
    final details = await _plugin.getNotificationAppLaunchDetails();
    if (details?.didNotificationLaunchApp ?? false) return details!.notificationResponse?.payload;
    return null;
  }
}

/// Coordinates FCM token registration (`POST/DELETE /me/devices`), message
/// handling in foreground / background / terminated states and deep links.
class PushService {
  PushService({
    required this.messaging,
    required this.local,
    required this.api,
    required this.prefs,
    required this.enabled,
  });

  final PushMessaging messaging;
  final LocalNotifier local;
  final ApiClient api;
  final AppPreferences prefs;
  final bool enabled;

  /// Navigation callback, set by the app once the router exists.
  void Function(String location)? onNavigate;

  /// Called when a notification is opened (used to mark it read).
  void Function(String notificationId)? onOpened;

  bool _available = false;
  bool _started = false;
  String? _pendingLocation;
  final _subs = <StreamSubscription<Object?>>[];

  bool get available => _available;

  Future<void> start() async {
    if (_started) return;
    _started = true;
    if (!enabled) return;
    _available = await messaging.initialize();
    await local.initialize((payload) => _handleTap(_decode(payload)));
    if (!_available) return;

    _subs
      ..add(messaging.onMessageOpenedApp.listen((m) => _handleTap(m.data)))
      ..add(messaging.onForegroundMessage.listen(_handleForeground));

    final initial = await messaging.getInitialMessage();
    if (initial != null) _handleTap(initial.data);
    final launch = await local.launchPayload();
    if (launch != null) _handleTap(_decode(launch));
  }

  /// Registers this device for the signed-in customer.
  Future<void> register() async {
    if (!_available) return;
    final granted = await messaging.requestPermission();
    if (!granted) return;
    final token = await messaging.getToken();
    if (token != null) await _send(token);
    _subs.add(messaging.onTokenRefresh.listen((t) => unawaited(_send(t).catchError((Object _) {}))));
  }

  Future<void> unregister() async {
    final token = prefs.registeredPushToken;
    if (token == null) return;
    try {
      await api.delete('/me/devices', data: {'push_token': token});
    } finally {
      await prefs.setRegisteredPushToken(null);
    }
  }

  Future<void> _send(String token) async {
    var deviceId = prefs.installId;
    if (deviceId == null) {
      deviceId = const Uuid().v4();
      await prefs.setInstallId(deviceId);
    }
    String? version;
    try {
      final info = await PackageInfo.fromPlatform();
      version = '${info.version}+${info.buildNumber}';
    } catch (_) {}
    await api.post(
      '/me/devices',
      data: {'platform': platformCode(), 'push_token': token, 'device_id': deviceId, 'app_version': ?version},
    );
    await prefs.setRegisteredPushToken(token);
  }

  void _handleForeground(PushMessage message) {
    final category = notificationCategory(message.data['event']?.toString());
    final allowed = prefs.pushCategoryPrefs[category] ?? true;
    if (!allowed) return;
    unawaited(local.show(message));
  }

  void _handleTap(Map<String, dynamic> data) {
    final id = data['notification_id']?.toString();
    if (id != null && id.isNotEmpty) onOpened?.call(id);
    final location = notificationLocation(data);
    if (onNavigate != null) {
      onNavigate!(location);
    } else {
      _pendingLocation = location;
    }
  }

  /// Location from a tap that happened before the router was ready.
  String? takePendingLocation() {
    final l = _pendingLocation;
    _pendingLocation = null;
    return l;
  }

  static Map<String, dynamic> _decode(String? payload) {
    if (payload == null) return const {};
    try {
      final v = jsonDecode(payload);
      return v is Map ? Map<String, dynamic>.from(v) : const {};
    } catch (_) {
      return const {};
    }
  }

  @visibleForTesting
  void debugHandleTap(Map<String, dynamic> data) => _handleTap(data);

  @visibleForTesting
  void debugHandleForeground(PushMessage m) => _handleForeground(m);

  Future<void> dispose() async {
    for (final s in _subs) {
      await s.cancel();
    }
  }
}

final pushMessagingProvider = Provider<PushMessaging>((ref) => FirebasePushMessaging());
final localNotifierProvider = Provider<LocalNotifier>((ref) => FlutterLocalNotifier());

final pushServiceProvider = Provider<PushService>((ref) {
  final service = PushService(
    messaging: ref.watch(pushMessagingProvider),
    local: ref.watch(localNotifierProvider),
    api: ref.watch(apiClientProvider),
    prefs: ref.watch(appPreferencesProvider),
    enabled: ref.watch(appConfigProvider).pushEnabled,
  );
  ref.onDispose(service.dispose);
  return service;
});
