import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/presentation/state/session_controller.dart';
import '../providers.dart';
import '../push/push_service.dart';
import 'live_order_controller.dart';
import 'live_order_platform.dart';
import 'notification_permission.dart';

/// True when the page [context] belongs to is the one in front of the customer: it is the top page of its navigator and of the tab
/// shell it may sit in, its tab is the visible one, no window (sheet, dialog, side panel) is open above it, and the app is on screen.
bool isFrontPage(BuildContext context) {
  if (!context.mounted) return false;
  final own = ModalRoute.of(context);
  if (own != null && !own.isCurrent) return false;
  // A page inside the tab shell: windows are opened on the app's root navigator, above the shell's own route.
  final navigator = Navigator.maybeOf(context);
  final shell = navigator == null ? null : ModalRoute.of(navigator.context);
  if (shell != null && !shell.isCurrent) return false;
  // The tabs are kept alive side by side; the hidden ones have their tickers switched off.
  if (!TickerMode.of(context)) return false;
  final lifecycle = WidgetsBinding.instance.lifecycleState;
  return lifecycle == null || lifecycle == AppLifecycleState.resumed;
}

/// Asks a signed-in customer to allow notifications soon after they reach this page (Home), instead of only after the first order:
/// on Android 13+ a push that reaches the phone is hidden until the app has asked.
///
/// Waits [delay] after the page appears (or after the customer signs in on it), then runs [promptForNotificationsIfNeeded], whose
/// frequency rule (not now: two weeks, three times at most; the settings variant twice, a month apart) is the only counter there is.
/// It happens once while this page lives. When the moment is wrong (another window or page is in front, the app is in the background)
/// it looks again after another [delay], up to [maxDeferrals] times. Nothing is asked while signed out. The page itself is never
/// blocked or changed: the widget just returns [child].
class NotificationAsk extends ConsumerStatefulWidget {
  const NotificationAsk({
    super.key,
    required this.child,
    this.delay = defaultDelay,
    this.maxDeferrals = 10,
    this.now,
    this.isFront = isFrontPage,
    this.onAnswered,
  });

  /// About three seconds: long enough for the page to settle and the first thing the customer sees to be the page, not a question.
  static const defaultDelay = Duration(seconds: 3);

  final Widget child;
  final Duration delay;
  final int maxDeferrals;

  /// The clock the frequency rule reads (tests move it).
  final DateTime Function()? now;

  /// Whether this is a good moment to ask (see [isFrontPage]).
  final bool Function(BuildContext context) isFront;

  /// Told after the system answered; by default the push service tells the server the new state.
  final Future<void> Function()? onAnswered;

  @override
  ConsumerState<NotificationAsk> createState() => _NotificationAskState();
}

class _NotificationAskState extends ConsumerState<NotificationAsk> {
  Timer? _timer;
  bool _done = false;
  int _deferrals = 0;

  @override
  void initState() {
    super.initState();
    if (ref.read(isAuthenticatedProvider)) _arm();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _arm() {
    if (_done || _timer != null) return;
    _timer = Timer(widget.delay, () {
      _timer = null;
      unawaited(_ask());
    });
  }

  /// Signed out: nothing is waited for, and the next person who signs in on this page is asked afresh.
  void _disarm() {
    _timer?.cancel();
    _timer = null;
    _done = false;
    _deferrals = 0;
  }

  Future<void> _ask() async {
    if (!mounted || _done || !ref.read(isAuthenticatedProvider)) return;
    if (!widget.isFront(context)) {
      if (_deferrals++ < widget.maxDeferrals) _arm();
      return;
    }
    _done = true;
    var tooLate = false;
    try {
      final push = ref.read(pushServiceProvider);
      await promptForNotificationsIfNeeded(
        context,
        platform: ref.read(liveOrderPlatformProvider),
        prefs: ref.read(appPreferencesProvider),
        controller: ref.read(liveOrderControllerProvider),
        now: widget.now,
        early: true,
        // Checked again right before the sheet would show: a window may have opened while the permission was being read.
        stillWanted: () {
          final front = mounted && ref.read(isAuthenticatedProvider) && widget.isFront(context);
          tooLate = !front;
          return front;
        },
        onAnswered: widget.onAnswered ?? push.onPermissionChanged,
      );
    } catch (_) {
      // Asking is a courtesy: whatever goes wrong here never reaches the page.
    }
    if (tooLate && mounted) {
      _done = false;
      if (_deferrals++ < widget.maxDeferrals) _arm();
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen<bool>(isAuthenticatedProvider, (_, signedIn) => signedIn ? _arm() : _disarm());
    return widget.child;
  }
}
