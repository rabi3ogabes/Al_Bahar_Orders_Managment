import 'dart:async';
import 'dart:convert';

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
import 'firebase_options_source.dart';
import 'firebase_starter.dart';
import 'order_progress.dart';

/// Platform-neutral push message.
@immutable
class PushMessage {
  const PushMessage({required this.data, this.title, this.body});
  final Map<String, dynamic> data;
  final String? title;
  final String? body;
}

/// Where notification permission stands, as the messaging SDK reports it.
enum PushPermission {
  granted,

  /// iPhone: pushes arrive quietly in the notification centre until the person allows alerts.
  provisional,

  /// iPhone: the system question has not been asked yet.
  notDetermined,

  /// Off, and only the system settings can turn it on (iPhone: the question was answered no; Android: switched off there).
  denied,

  /// Firebase is not running, so there is nothing to ask for.
  unavailable,
}

/// Adapter over the messaging SDK (Firebase in production, fakes in tests).
abstract class PushMessaging {
  /// Returns false when push is unavailable (e.g. no Firebase config).
  Future<bool> initialize();

  /// Shows the system question when it can still be shown; true when pushes may be delivered afterwards. [provisional]
  /// (iPhone) asks for quiet delivery instead, which needs no question at all.
  Future<bool> requestPermission({bool provisional = false});

  /// Where permission stands right now, without asking.
  Future<PushPermission> permissionStatus();
  Future<String?> getToken();
  Stream<String> get onTokenRefresh;
  Stream<PushMessage> get onForegroundMessage;
  Stream<PushMessage> get onMessageOpenedApp;
  Future<PushMessage?> getInitialMessage();
}

/// Background handler must be a top-level function.
///
/// It must not call any Firebase API (no `Firebase.initializeApp()`): it runs in its own isolate, where Firebase is not
/// started, and a build without `google-services.json` / `GoogleService-Info.plist` gets its options from the server
/// at run time, so a call here without options would throw. Drawing the card needs only flutter_local_notifications.
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  // Notification messages are displayed by the OS; data handling happens
  // when the user taps (onMessageOpenedApp / getInitialMessage).
  // Order progress arrives data-only. On Android the Kotlin LiveOrderFcmReceiver draws it (this isolate cannot reach the
  // native renderer's method channel, and a second card on top of the native one would be wrong).
  if (!isOrderProgress(message.data) || defaultTargetPlatform == TargetPlatform.android) return;
  final plugin = FlutterLocalNotificationsPlugin();
  await plugin.initialize(
    settings: const InitializationSettings(android: AndroidInitializationSettings('ic_stat_notify')),
  );
  await showOrderProgress(plugin, Map<String, dynamic>.from(message.data));
}

class FirebasePushMessaging implements PushMessaging {
  FirebasePushMessaging({required this.starter});

  /// Starts Firebase from the native config files, else from the options the server hands out.
  final FirebaseStarter starter;

  FirebaseMessaging? _fm;

  @override
  Future<bool> initialize() async {
    try {
      if (!await starter.start()) {
        debugPrint('Push unavailable: Firebase is not configured yet');
        _fm = null;
        return false;
      }
      _fm = FirebaseMessaging.instance;
      if (!kIsWeb) FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
      await _fm!.setForegroundNotificationPresentationOptions(alert: false, badge: true, sound: false);
      return true;
    } catch (e) {
      debugPrint('Push disabled: Firebase could not start (${e.runtimeType})');
      _fm = null;
      return false;
    }
  }

  @override
  Future<bool> requestPermission({bool provisional = false}) async {
    final fm = _fm;
    if (fm == null) return false;
    final settings = await fm.requestPermission(provisional: provisional);
    return settings.authorizationStatus == AuthorizationStatus.authorized ||
        settings.authorizationStatus == AuthorizationStatus.provisional;
  }

  @override
  Future<PushPermission> permissionStatus() async {
    final fm = _fm;
    if (fm == null) return PushPermission.unavailable;
    final settings = await fm.getNotificationSettings();
    return switch (settings.authorizationStatus) {
      AuthorizationStatus.authorized => PushPermission.granted,
      AuthorizationStatus.provisional => PushPermission.provisional,
      AuthorizationStatus.notDetermined => PushPermission.notDetermined,
      // iPhone: `denied` is final (the system never asks twice). Android is handled by the live order channel, not by this.
      AuthorizationStatus.denied || AuthorizationStatus.deniedPermanently => PushPermission.denied,
    };
  }

  /// firebase_messaging 17 registers the installation and reports the
  /// direct-send identifier through `onRegistered` (replaces getToken()).
  @override
  Future<String?> getToken() async {
    final fm = _fm;
    if (fm == null) return null;
    final first = fm.onRegistered.first.timeout(const Duration(seconds: 20));
    try {
      await fm.register();
    } catch (_) {
      // Nobody waits for the answer any more: its time-out must not surface later as an error nobody catches.
      unawaited(first.then<void>((_) {}, onError: (Object _) {}));
      rethrow;
    }
    try {
      return await first;
    } on TimeoutException {
      return null;
    }
  }

  @override
  Stream<String> get onTokenRefresh => _fm?.onRegistered ?? const Stream.empty();

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
  Future<bool> requestPermission({bool provisional = false}) async => false;
  @override
  Future<PushPermission> permissionStatus() async => PushPermission.unavailable;
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
  Future<void> showProgress(Map<String, dynamic> data);
  Future<String?> launchPayload();
}

/// The Android notification channels of the customer app. Created natively when the app starts (PushChannels.kt, named in the phone's
/// language) and chosen by the server for every push (FcmClient::channel): orders and payments ring, auctions ring, offers stay quiet, so
/// each can be muted on its own in the system settings.
abstract final class PushChannels {
  static const defaults = 'albahar_default';
  static const offers = 'albahar_offers';
  static const auctions = 'albahar_auctions';

  /// The channel for a preference category (`transactional`, `marketing`, `auction`).
  static String forCategory(String category) => switch (category) {
    'auction' => auctions,
    'marketing' => offers,
    _ => defaults,
  };
}

/// The preference category of a push: the one the server names, else worked out from the event (older servers).
String pushCategory(Map<String, dynamic> data) {
  final named = data['category']?.toString();
  if (named == 'transactional' || named == 'marketing' || named == 'auction') return named!;
  return notificationCategory(data['event']?.toString());
}

/// What identifies one notification across repeated deliveries: the inbox entry, else the delivery or the send. Null when the push
/// carries none of them (a test push), which is then never treated as a repeat.
String? pushKey(Map<String, dynamic> data) {
  for (final name in const ['notification_id', 'delivery_id', 'log_id']) {
    final v = data[name]?.toString();
    if (v != null && v.isNotEmpty) return '$name:$v';
  }
  return null;
}

/// A notification id made from a key, the same on every run (String.hashCode is not promised to be): FNV-1a, kept positive.
@visibleForTesting
int stableId(String key) {
  var h = 0x811c9dc5;
  for (final unit in key.codeUnits) {
    h = ((h ^ unit) * 0x01000193) & 0x7fffffff;
  }
  return h;
}

class FlutterLocalNotifier implements LocalNotifier {
  final _plugin = FlutterLocalNotificationsPlugin();
  bool _ready = false;

  static AndroidNotificationDetails _android(String category) {
    final channel = PushChannels.forCategory(category);
    return switch (channel) {
      PushChannels.offers => const AndroidNotificationDetails(
        PushChannels.offers,
        'Offers and news',
        channelDescription: 'Offers, back in stock and news from Al Bahar',
        importance: Importance.defaultImportance,
        priority: Priority.defaultPriority,
      ),
      PushChannels.auctions => const AndroidNotificationDetails(
        PushChannels.auctions,
        'Auctions',
        channelDescription: 'Bids, outbids and results of the auctions you follow',
        importance: Importance.high,
        priority: Priority.high,
      ),
      _ => const AndroidNotificationDetails(
        PushChannels.defaults,
        'Orders and updates',
        channelDescription: 'Orders, payments, deliveries and messages from Al Bahar',
        importance: Importance.high,
        priority: Priority.high,
      ),
    };
  }

  @override
  Future<void> initialize(void Function(String? payload) onTap) async {
    if (kIsWeb) return;
    try {
      await _plugin.initialize(
        settings: const InitializationSettings(
          android: AndroidInitializationSettings('ic_stat_notify'),
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
    final key = pushKey(message.data);
    await _plugin.show(
      // The same notification delivered twice replaces the first card instead of stacking a second one.
      id: key == null ? DateTime.now().millisecondsSinceEpoch.remainder(1 << 31) : stableId(key),
      title: message.title,
      body: message.body,
      notificationDetails: NotificationDetails(
        android: _android(pushCategory(message.data)),
        iOS: const DarwinNotificationDetails(),
      ),
      payload: jsonEncode(message.data),
    );
  }

  @override
  Future<void> showProgress(Map<String, dynamic> data) async {
    if (_ready) await showOrderProgress(_plugin, data);
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
    this.devicesPath = customerDevicesPath,
    this.askPermissionOnRegister = true,
    this.localeCode,
    String Function()? platform,
    DateTime Function()? now,
  }) : _platform = platform ?? platformCode,
       _now = now ?? DateTime.now;

  /// Where the customer app registers its push token.
  static const customerDevicesPath = '/me/devices';

  /// When Firebase could not start (its settings are not on the server yet, no network), the app tries again, but not
  /// more often than this.
  static const retryInterval = Duration(minutes: 10);

  /// How many recent notifications are remembered to tell a repeated delivery from a new message.
  static const _rememberLast = 40;

  final PushMessaging messaging;
  final LocalNotifier local;
  final ApiClient api;
  final AppPreferences prefs;
  final bool enabled;

  /// Where the push token is registered: `/me/devices` (customers) or `/admin/devices` (driver and staff apps).
  final String devicesPath;

  /// Whether [register] shows the system notification dialog. The customer app turns it off: it asks in context, with a rationale,
  /// when the first order is placed (see core/live_order/notification_permission.dart); on an iPhone it asks for quiet delivery,
  /// which needs no dialog, until then.
  final bool askPermissionOnRegister;

  /// The app's language (`en` | `ar`), sent with the token so the server writes the live order card and the messages in it.
  final String Function()? localeCode;

  /// Receives `order_progress` pushes that reach the app while it is open (the live order notification); without it
  /// they are drawn as a plain progress notification.
  void Function(Map<String, dynamic> data)? onOrderProgress;

  /// Navigation callback, set by the app once the router exists.
  void Function(String location)? onNavigate;

  /// Called when a notification is opened (used to mark it read).
  void Function(String notificationId)? onOpened;

  /// Called when a push (other than an order card) reaches the app while it is open: the server has put it in the inbox, so the
  /// unread badge is out of date.
  void Function()? onReceived;

  final String Function() _platform;
  final DateTime Function() _now;
  bool _available = false;
  Future<void>? _startFuture;
  Future<void>? _retrying;
  Future<void>? _registering;
  DateTime? _lastAttempt;

  /// [register] ran while push was unavailable: finish it as soon as push starts.
  bool _registerWanted = false;

  /// Someone is signed in on this phone and wants pushes: set by [register], cleared by [unregister] and [onSignedOut].
  bool _signedIn = false;
  String? _pendingLocation;
  final _recent = <String>[];
  final _subs = <StreamSubscription<Object?>>[];
  StreamSubscription<String>? _tokenRefreshSub;

  bool get available => _available;

  /// Starts once; every caller (including [register]) waits for the same start-up to finish. A start that left push
  /// unavailable is tried again by a later call, at most every [retryInterval]; a start that worked is never repeated.
  Future<void> start() async {
    await (_startFuture ??= _start());
    await _retryStart();
  }

  Future<void> _start() async {
    if (!enabled) return;
    _available = await _connect();
    await local.initialize((payload) => _handleTap(_decode(payload)));
    if (!_available) return;

    _subscribe();
    final initial = await messaging.getInitialMessage();
    if (initial != null) _handleTap(initial.data);
    final launch = await local.launchPayload();
    if (launch != null) _handleTap(_decode(launch));
  }

  /// One attempt to start the messaging SDK; never throws.
  Future<bool> _connect() async {
    _lastAttempt = _now();
    try {
      return await messaging.initialize();
    } catch (_) {
      return false;
    }
  }

  void _subscribe() {
    _subs
      ..add(messaging.onMessageOpenedApp.listen((m) => _handleTap(m.data)))
      ..add(messaging.onForegroundMessage.listen(_handleForeground));
  }

  /// A later attempt after a failed start. A notification that opened the app earlier is not replayed.
  Future<void> _retryStart() {
    if (!enabled || _available) return Future.value();
    final running = _retrying;
    if (running != null) return running;
    final last = _lastAttempt;
    if (last == null) return Future.value();
    final elapsed = _now().difference(last);
    if (!elapsed.isNegative && elapsed < retryInterval) return Future.value();
    return _retrying = _retry().whenComplete(() => _retrying = null);
  }

  Future<void> _retry() async {
    _available = await _connect();
    if (_available) _subscribe();
  }

  /// The app is back on screen: if push could not start earlier, try again (throttled) and finish a [register] that
  /// was waiting for it. A signed-in person whose phone has no token yet (they had said no to notifications and have now
  /// switched them on in the system settings) is registered now, without any question.
  Future<void> onResumed() async {
    if (!enabled || _startFuture == null) return;
    if (!_available) {
      await start();
      if (_available && _registerWanted) await register();
      return;
    }
    if (_signedIn && prefs.registeredPushToken == null && await _mayRegisterWithoutAsking()) await register();
  }

  /// The customer app never shows a dialog from [register], so it may always try again. The staff apps ask in [register]; they try
  /// again on their own only once the permission is on (a refused question is not asked at every return to the app).
  Future<bool> _mayRegisterWithoutAsking() async {
    if (!askPermissionOnRegister) return true;
    final status = await messaging.permissionStatus();
    return status == PushPermission.granted || status == PushPermission.provisional;
  }

  /// Registers this device for the signed-in customer. Calls that overlap share one registration.
  Future<void> register() => _registering ??= _register().whenComplete(() => _registering = null);

  Future<void> _register() async {
    _signedIn = true;
    await start();
    if (!_available) {
      _registerWanted = enabled;
      return;
    }
    _registerWanted = false;
    if (!await _permitted()) return;
    // The SDK reports the token when the registration finishes; if it has nothing new to say, the install's last token is still right.
    final token = await messaging.getToken() ?? prefs.lastPushToken;
    try {
      if (token != null) await _send(token);
    } finally {
      // Listening even when this send failed (no network): a later token refresh must not be lost with it.
      await _tokenRefreshSub?.cancel();
      _tokenRefreshSub = messaging.onTokenRefresh.listen((t) => unawaited(_send(t).catchError((Object _) {})));
    }
  }

  /// Whether pushes may be delivered, asking where that is the right moment. The driver and staff apps ask at once (an alert is the
  /// point of them). The customer app on Android asks nothing here (a token needs no permission; the explanation sheet comes with the
  /// first order); on an iPhone it asks for quiet delivery, which shows no dialog and lets order news arrive from the first minute.
  Future<bool> _permitted() async {
    if (askPermissionOnRegister) return messaging.requestPermission();
    return switch (_platform()) {
      'android' => true,
      'ios' => messaging.requestPermission(provisional: true),
      _ => messaging.requestPermission(),
    };
  }

  Future<void> unregister() async {
    _registerWanted = false;
    _signedIn = false;
    await _tokenRefreshSub?.cancel();
    _tokenRefreshSub = null;
    await prefs.setPushCategoryPrefs(const {});
    final token = prefs.registeredPushToken;
    if (token == null) return;
    try {
      await api.delete(devicesPath, data: {'push_token': token});
    } finally {
      await prefs.setRegisteredPushToken(null);
    }
  }

  /// The person is no longer signed in (signed out, or the session ended and the server cannot be asked any more): nothing of theirs
  /// stays on this phone. Their banner preferences must not decide what the next person sees, and the phone stops reporting its token.
  Future<void> onSignedOut() async {
    _registerWanted = false;
    _signedIn = false;
    await _tokenRefreshSub?.cancel();
    _tokenRefreshSub = null;
    await prefs.setPushCategoryPrefs(const {});
    await prefs.setRegisteredPushToken(null);
  }

  /// The app's language changed: the server writes the live order card and its messages in the language each phone reports.
  Future<void> onLocaleChanged() async {
    final token = prefs.registeredPushToken;
    if (!_signedIn || !_available || token == null) return;
    await _send(token);
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
    final locale = localeCode?.call();
    await api.post(
      devicesPath,
      data: {
        'platform': _platform(),
        'push_token': token,
        'device_id': deviceId,
        'app_version': ?version,
        'locale': ?locale,
      },
    );
    await prefs.setRegisteredPushToken(token);
    await prefs.setLastPushToken(token);
  }

  void _handleForeground(PushMessage message) {
    if (isOrderProgress(message.data)) {
      final handler = onOrderProgress;
      if (handler != null) {
        handler(message.data);
      } else {
        unawaited(local.showProgress(message.data));
      }
      return;
    }
    // The server has put this message in the inbox already: the unread badge catches up, whatever the banner does.
    onReceived?.call();
    if (_isRepeat(message.data)) return;
    final allowed = prefs.pushCategoryPrefs[pushCategory(message.data)] ?? true;
    if (!allowed) return;
    unawaited(local.show(message));
  }

  /// True when this notification was already shown (FCM delivers a message twice now and then); remembers it otherwise.
  bool _isRepeat(Map<String, dynamic> data) {
    final key = pushKey(data);
    if (key == null) return false;
    if (_recent.contains(key)) return true;
    _recent.add(key);
    if (_recent.length > _rememberLast) _recent.removeAt(0);
    return false;
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
    await _tokenRefreshSub?.cancel();
    for (final s in _subs) {
      await s.cancel();
    }
  }
}

final pushMessagingProvider = Provider<PushMessaging>(
  (ref) =>
      FirebasePushMessaging(starter: FirebaseStarter(options: FirebaseOptionsSource.api(ref.watch(apiClientProvider)))),
);
final localNotifierProvider = Provider<LocalNotifier>((ref) => FlutterLocalNotifier());

final pushDevicesPathProvider = Provider<String>((ref) => '/me/devices');

final pushServiceProvider = Provider<PushService>((ref) {
  final service = PushService(
    messaging: ref.watch(pushMessagingProvider),
    local: ref.watch(localNotifierProvider),
    api: ref.watch(apiClientProvider),
    prefs: ref.watch(appPreferencesProvider),
    enabled: ref.watch(appConfigProvider).pushEnabled,
    devicesPath: ref.watch(pushDevicesPathProvider),
    askPermissionOnRegister: ref.watch(pushDevicesPathProvider) != PushService.customerDevicesPath,
    localeCode: () => ref.read(localeCodeProvider),
  );
  ref.onDispose(service.dispose);
  return service;
});
