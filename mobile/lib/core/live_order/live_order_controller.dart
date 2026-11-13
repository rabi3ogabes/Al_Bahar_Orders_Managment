import 'dart:async';
import 'dart:ui' show Color;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auctions/data/auction_repository.dart';
import '../../features/auth/presentation/state/session_controller.dart';
import '../../features/orders/data/order_repository.dart';
import '../providers.dart';
import '../push/push_service.dart';
import '../realtime/pusher_client.dart';
import '../storage/app_preferences.dart';
import '../theme/brand_theme.dart';
import '../utils/json.dart';
import 'live_order.dart';
import 'live_order_api.dart';
import 'live_order_platform.dart';
import 'live_order_texts.dart';

/// Where a state came from; the server's own words (push, REST) are trusted more than a guess from a status code.
enum LiveOrderSource { push, realtime, rest, local }

typedef LiveOrderBrand = ({Color primary, Color accent});

/// Single source of truth for the customer's live order notifications.
///
/// Four sources feed it, all turned into [LiveOrder] states: the `order_progress` push (foreground; with the app closed
/// the Kotlin receiver draws it), the Reverb `order.updated` events while the app lives, a REST re-read (app start,
/// resume, network back, and every minute while an order is active) and the order the customer has just placed, which
/// needs no Firebase at all. A state replaces the last one only when it is newer (version, then time, never backwards),
/// is drawn by the native renderer (or the plain progress notification where there is none) and is forgotten when the
/// order ends. Polling stops when no order is active.
class LiveOrderController {
  LiveOrderController({
    required this.platform,
    required this.api,
    required this.prefs,
    required this.localeCode,
    this.fallback,
    this.brand,
    this.arabicDigits = false,
    this.resyncInterval = const Duration(seconds: 60),
    this.eventDebounce = const Duration(milliseconds: 600),
    this.minResyncGap = const Duration(seconds: 5),
    DateTime Function()? now,
  }) : _now = now ?? DateTime.now;

  final LiveOrderPlatform platform;
  final LiveOrderApi api;
  final AppPreferences prefs;
  final String Function() localeCode;

  /// Draws the plain progress notification when the platform has no native renderer.
  final LocalNotifier? fallback;

  /// The look chosen in the dashboard; the card is tinted with it.
  final LiveOrderBrand? Function()? brand;

  /// Arabic-Indic digits in the times of Arabic cards (off: Western digits, like the rest of the app).
  final bool arabicDigits;

  final Duration resyncInterval;
  final Duration eventDebounce;
  final Duration minResyncGap;
  final DateTime Function() _now;

  /// A finished order older than this is not announced when it is found by a re-read (the app was closed for hours).
  static const _staleAfter = Duration(minutes: 30);
  static const _maxLookups = 5;

  final _orders = <String, LiveOrder>{};
  final _finishedAt = <String, DateTime>{};

  /// Orders with a live card (id → version), mirrored in preferences so a restart picks them up again.
  final _tracked = <String, int>{};

  bool _signedIn = false;
  bool _online = true;
  bool _resyncing = false;
  bool _again = false;
  DateTime? _lastSync;
  Timer? _timer;
  Timer? _debounce;
  StreamSubscription<PusherEvent>? _events;
  Future<void>? _tail;

  /// Orders being followed right now.
  List<LiveOrder> get active => [
    for (final o in _orders.values)
      if (!o.isFinal) o,
  ];

  @visibleForTesting
  Set<String> get trackedIds => _tracked.keys.toSet();

  @visibleForTesting
  bool get polling => _timer?.isActive ?? false;

  // ---- lifecycle ----------------------------------------------------------------------------------------------------

  /// The customer is signed in: pick up the orders of the last run and ask the server how they stand.
  void onSignedIn() {
    if (_signedIn) return;
    _signedIn = true;
    _tracked
      ..clear()
      ..addAll(prefs.liveOrders);
    _updateTimer();
    unawaited(resync(force: true));
  }

  /// Signed out: no card may outlive the account that placed the order.
  Future<void> onSignedOut() async {
    if (!_signedIn && _tracked.isEmpty && _orders.isEmpty) return;
    _signedIn = false;
    _debounce?.cancel();
    _timer?.cancel();
    unawaited(_events?.cancel());
    _events = null;
    await _enqueue(() async {
      _orders.clear();
      _finishedAt.clear();
      _tracked.clear();
      await prefs.setLiveOrders(const {});
      await platform.cancelAll();
    });
  }

  /// Follows the customer's private channel (`order.updated`); null stops. Called again with the same user is harmless.
  void bindRealtime(Stream<PusherEvent>? events) {
    unawaited(_events?.cancel());
    _events = events?.listen(_onEvent, onError: (Object _) {});
  }

  void onResumed() => unawaited(resync());

  void onOnline() {
    _online = true;
    unawaited(resync(force: true));
  }

  void onOffline() => _online = false;

  /// Language or brand colour changed: draw every active card again (the native side drops duplicates, so this forces it).
  Future<void> refreshPresentation() => _enqueue(() async {
    for (final o in active) {
      await _render(o, force: true);
    }
  });

  /// Notification permission has just been granted: the cards that could not be drawn before are drawn now.
  Future<void> onPermissionGranted() => refreshPresentation();

  Future<void> dispose() async {
    _debounce?.cancel();
    _timer?.cancel();
    unawaited(_events?.cancel());
    _events = null;
  }

  // ---- sources ------------------------------------------------------------------------------------------------------

  /// An `order_progress` push seen by the app (foreground). Safe to call twice for the same message.
  Future<void> ingestPush(Map<String, dynamic> data) async {
    if (!_signedIn) return;
    final live = LiveOrder.tryParse(data);
    if (live != null) await _accept(live, LiveOrderSource.push);
  }

  /// The customer has placed an order: show it at once (version unknown), then ask for the estimate.
  Future<void> orderPlaced({required String orderId, required String number, String? fulfillmentType}) async {
    if (!_signedIn || orderId.isEmpty) return;
    final live = LiveOrder(
      orderId: orderId,
      number: number,
      phase: LiveOrderPhase.placed,
      pickup: fulfillmentType == 'pickup',
      updatedAt: _now(),
    );
    await _accept(live, LiveOrderSource.local);
    _scheduleResync(const Duration(seconds: 2));
  }

  void _onEvent(PusherEvent e) {
    if (e.event != 'order.updated' || !_signedIn) return;
    final d = e.data;
    final id = asStringOrNull(d['order_id']);
    if (id == null) return;
    final prev = _orders[id];
    // A full progress payload is used as it is; the plain event only names the status, so the phase is derived from it.
    final live = d.containsKey('phase') || d.containsKey('step')
        ? LiveOrder.tryParse(d)
        : LiveOrder.fromStatus(
            orderId: id,
            number: asString(d['number'], prev?.number ?? id),
            status: asString(d['status']),
            fulfillmentType: prev == null ? null : (prev.pickup ? 'pickup' : 'delivery'),
            deliveryStatus: asStringOrNull(d['delivery_status']),
            updatedAt: _now(),
          );
    if (live == null) return;
    // Same stage as the card already shows: nothing new to say (the estimate and driver it has are better than none).
    if (prev != null && prev.phase == live.phase && live.seq == 0) {
      _scheduleResync(eventDebounce);
      return;
    }
    unawaited(_accept(live, LiveOrderSource.realtime));
    _scheduleResync(eventDebounce);
  }

  /// Asks the server how the orders stand. Quiet when offline or on a server error: the cards stay as they are.
  Future<void> resync({bool force = false}) async {
    if (!_signedIn || !_online) return;
    if (_resyncing) {
      _again = true;
      return;
    }
    final last = _lastSync;
    if (!force && last != null && _now().difference(last) < minResyncGap) return;
    _resyncing = true;
    try {
      do {
        _again = false;
        await _resyncOnce();
        _lastSync = _now();
      } while (_again && _signedIn);
    } finally {
      _resyncing = false;
      _updateTimer();
    }
  }

  Future<void> _resyncOnce() async {
    final List<LiveOrder>? list;
    try {
      list = await api.activeProgress();
    } catch (_) {
      return;
    }
    if (!_signedIn || list == null) return;
    final listed = <String>{};
    for (final o in list) {
      listed.add(o.orderId);
      await _accept(o, LiveOrderSource.rest);
    }
    // Cards we show that the server no longer lists: the order has finished (or was cancelled) in the meantime.
    final missing = _tracked.keys.where((id) => !listed.contains(id)).take(_maxLookups).toList();
    for (final id in missing) {
      if (!_signedIn) return;
      try {
        final o = await api.orderProgress(id);
        if (o == null) {
          await _forget(id);
        } else {
          await _accept(o, LiveOrderSource.rest);
        }
      } catch (_) {
        // Try again on the next round.
      }
    }
  }

  void _scheduleResync(Duration after) {
    _debounce?.cancel();
    _debounce = Timer(after, () => unawaited(resync(force: true)));
  }

  /// One poll timer, only while an order is active.
  void _updateTimer() {
    final needed = _signedIn && _tracked.isNotEmpty;
    if (!needed) {
      _timer?.cancel();
      _timer = null;
    } else if (_timer == null || !_timer!.isActive) {
      _timer = Timer.periodic(resyncInterval, (_) => unawaited(resync(force: true)));
    }
  }

  // ---- state --------------------------------------------------------------------------------------------------------

  Future<bool> _accept(LiveOrder next, LiveOrderSource source) => _enqueue(() async {
    if (!_signedIn) return false;
    final prev = _orders[next.orderId];
    if (!next.supersedes(prev)) return false;
    if (next.isFinal && source == LiveOrderSource.rest && !_finishIsFresh(next)) {
      // Found finished long after the fact (the app was closed): clear any old card, announce nothing.
      await _forget(next.orderId);
      return false;
    }
    _orders[next.orderId] = next;
    if (next.isFinal) {
      _finishedAt[next.orderId] = _now();
      _tracked.remove(next.orderId);
    } else {
      _tracked[next.orderId] = next.seq;
    }
    _purgeFinished();
    await prefs.setLiveOrders(Map.of(_tracked));
    await _render(next);
    _updateTimer();
    return true;
  });

  /// A finished order from a re-read is announced only if we were following it, or it ended moments ago.
  bool _finishIsFresh(LiveOrder o) {
    if (_tracked.containsKey(o.orderId)) return true;
    final at = o.updatedAt;
    return at != null && _now().difference(at) <= _staleAfter;
  }

  Future<void> _forget(String orderId) async {
    _orders.remove(orderId);
    _finishedAt.remove(orderId);
    _tracked.remove(orderId);
    await prefs.setLiveOrders(Map.of(_tracked));
    await platform.cancel(orderId);
    _updateTimer();
  }

  /// Finished orders are kept for a while only to refuse stale updates for them.
  void _purgeFinished() {
    final cutoff = _now().subtract(const Duration(hours: 2));
    _finishedAt.removeWhere((id, at) {
      final old = at.isBefore(cutoff);
      if (old) _orders.remove(id);
      return old;
    });
  }

  /// Texts and brand colour are added here, at the last moment, so a language or theme change shows on the next draw.
  LiveOrder _present(LiveOrder o) {
    final code = localeCode();
    final b = brand?.call();
    final withBrand = o.brandPrimary == null && b != null
        ? o.copyWith(brandPrimary: b.primary, brandAccent: b.accent)
        : o;
    return LiveOrderTexts.forLocale(code, arabicDigits: arabicDigits).localise(withBrand, now: _now());
  }

  Future<void> _render(LiveOrder o, {bool force = false}) async {
    final shown = _present(o);
    final code = localeCode();
    try {
      final result = await platform.show(shown, locale: code, force: force, arabicDigits: arabicDigits);
      if (result == LiveOrderShowResult.unsupported) {
        final texts = LiveOrderTexts.forLocale(code, arabicDigits: arabicDigits);
        await fallback?.showProgress(shown.toLegacyProgress(stageLabel: texts.stage(shown)));
      }
    } catch (e) {
      debugPrint('Live order notification failed: ${e.runtimeType}');
    }
  }

  Future<T> _enqueue<T>(Future<T> Function() job) {
    final next = (_tail ?? Future<void>.value()).then((_) => job());
    _tail = next.then((_) {}, onError: (Object _) {});
    return next;
  }
}

// ---- providers ------------------------------------------------------------------------------------------------------

final liveOrderPlatformProvider = Provider<LiveOrderPlatform>((ref) => MethodChannelLiveOrderPlatform());

final liveOrderApiProvider = Provider<LiveOrderApi>(
  (ref) => RestLiveOrderApi(ref.watch(apiClientProvider), ref.watch(orderRepositoryProvider)),
);

final liveOrderControllerProvider = Provider<LiveOrderController>((ref) {
  final controller = LiveOrderController(
    platform: ref.watch(liveOrderPlatformProvider),
    api: ref.watch(liveOrderApiProvider),
    prefs: ref.watch(appPreferencesProvider),
    fallback: ref.watch(localNotifierProvider),
    localeCode: () => ref.read(localeCodeProvider),
    brand: () {
      final b = ref.read(brandThemeProvider);
      return (primary: b.primary, accent: b.accent);
    },
  );
  ref.onDispose(controller.dispose);
  return controller;
});

/// Keeps the controller in step with the app: sign-in and sign-out, the customer's realtime channel, network and
/// language/brand changes. Watched once by the app widget; app lifecycle (resume) is forwarded by the widget itself.
final liveOrderBootProvider = Provider<void>((ref) {
  final controller = ref.watch(liveOrderControllerProvider);
  // Pushes the app sees while it is open go through the same controller (FCM, once Firebase is configured).
  ref.read(pushServiceProvider).onOrderProgress = (data) => unawaited(controller.ingestPush(data));
  ref
    ..listen<bool>(isAuthenticatedProvider, (prev, next) {
      if (next) {
        controller.onSignedIn();
      } else if (prev ?? false) {
        unawaited(controller.onSignedOut());
      }
    }, fireImmediately: true)
    ..listen<String?>(currentUserIdProvider, (prev, next) {
      controller.bindRealtime(next == null ? null : ref.read(pusherClientProvider).channel('private-users.$next'));
    }, fireImmediately: true)
    ..listen<bool>(onlineProvider, (prev, next) => next ? controller.onOnline() : controller.onOffline())
    ..listen<String>(localeCodeProvider, (prev, next) {
      if (prev != next) unawaited(controller.refreshPresentation());
    })
    ..listen<BrandTheme>(brandThemeProvider, (prev, next) {
      if (prev != null && prev.primary != next.primary) unawaited(controller.refreshPresentation());
    });
});
