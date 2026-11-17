import 'dart:async';

import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The one ticker of a screen: every deal widget below it reads the time from here, so a page with ten countdowns still
/// has exactly one timer. It ticks once a second, on the server's clock ([skew] is `server_time - device time`).
class DealClock extends ChangeNotifier {
  DealClock({this.skew = Duration.zero, DateTime Function()? deviceNow}) : _deviceNow = deviceNow ?? DateTime.now;

  final DateTime Function() _deviceNow;

  /// Added to the phone's clock to get the server's.
  Duration skew;
  Timer? _timer;

  /// The server's time now.
  DateTime now() => _deviceNow().add(skew);

  bool get running => _timer != null;

  /// Starts ticking (does nothing when it already ticks).
  void start() => _timer ??= Timer.periodic(const Duration(seconds: 1), (_) => notifyListeners());

  /// Stops ticking: used while the app is in the background, so no timer runs for nobody.
  void stop() {
    _timer?.cancel();
    _timer = null;
  }

  /// Tells every countdown to read the time again (the clock skew changed, or the app came back).
  void refresh() => notifyListeners();

  @override
  void dispose() {
    stop();
    super.dispose();
  }
}

/// Hands the screen's [DealClock] down the tree. Whoever reads [nowOf] rebuilds every second.
class DealClockScope extends InheritedNotifier<DealClock> {
  const DealClockScope({super.key, required DealClock clock, required super.child}) : super(notifier: clock);

  /// The nearest clock without subscribing to it, or null.
  static DealClock? peek(BuildContext context) => context.getInheritedWidgetOfExactType<DealClockScope>()?.notifier;

  /// The server's time now. Rebuilds the caller every second when there is a clock above it; with none it is the phone's
  /// time and nothing ticks.
  static DateTime nowOf(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<DealClockScope>();
    return scope?.notifier?.now() ?? DateTime.now();
  }
}

/// Owns a [DealClock] for the subtree: starts it, keeps it on the server's clock, stops it when the app goes to the
/// background and when the subtree leaves the screen.
class DealClockHost extends ConsumerStatefulWidget {
  const DealClockHost({super.key, required this.child});

  final Widget child;

  @override
  ConsumerState<DealClockHost> createState() => _DealClockHostState();
}

class _DealClockHostState extends ConsumerState<DealClockHost> with WidgetsBindingObserver {
  late final DealClock _clock;

  @override
  void initState() {
    super.initState();
    _clock = DealClock(skew: ref.read(dealSkewProvider), deviceNow: ref.read(dealDeviceNowProvider));
    WidgetsBinding.instance.addObserver(this);
    final state = WidgetsBinding.instance.lifecycleState;
    if (state == null || state == AppLifecycleState.resumed) _clock.start();
    ref.listenManual(dealSkewProvider, (_, skew) {
      _clock.skew = skew;
      _clock.refresh();
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _clock.start();
      // Whatever the clock missed while away is shown at once.
      _clock.refresh();
    } else {
      _clock.stop();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _clock.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => DealClockScope(clock: _clock, child: widget.child);
}

/// Gives [child] a clock: the screen's own when there is one above, else one of its own.
class DealClockBoundary extends StatelessWidget {
  const DealClockBoundary({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => DealClockScope.peek(context) == null ? DealClockHost(child: child) : child;
}

/// Calls [onDue] once when the server's time reaches [dueAt] (a deal ended, or an upcoming one started), then again at
/// most every [retry] while the moment is still past (the answer it refetched may still be stale). Draws nothing.
class DealDueWatcher extends StatefulWidget {
  const DealDueWatcher({super.key, required this.dueAt, required this.onDue, this.retry = const Duration(seconds: 15)});

  final DateTime? dueAt;
  final VoidCallback onDue;
  final Duration retry;

  @override
  State<DealDueWatcher> createState() => _DealDueWatcherState();
}

class _DealDueWatcherState extends State<DealDueWatcher> {
  DateTime? _firedFor;
  DateTime? _firedAt;

  @override
  Widget build(BuildContext context) {
    final due = widget.dueAt;
    final now = DealClockScope.nowOf(context);
    if (due != null && !now.isBefore(due)) {
      final again = _firedFor != due || _firedAt == null || now.difference(_firedAt!) >= widget.retry;
      if (again) {
        _firedFor = due;
        _firedAt = now;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) widget.onDue();
        });
      }
    }
    return const SizedBox.shrink();
  }
}
