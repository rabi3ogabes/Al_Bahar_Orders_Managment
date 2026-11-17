import 'dart:async';

import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The one ticker of a screen: every deal widget below it reads the time from here, so a page with ten countdowns still
/// has exactly one timer. It ticks once a second, on the server's clock ([skew] is `server_time - device time`).
class DealClock extends ChangeNotifier {
  DealClock({this.skew, DateTime Function()? deviceNow}) : _deviceNow = deviceNow ?? DateTime.now;

  final DateTime Function() _deviceNow;

  /// Added to the phone's clock to get the server's. Null until the server has told its time: the clock then shows the phone's
  /// time for a countdown, but [trustedNow] is null, so no phase is decided by it.
  Duration? skew;
  Timer? _timer;

  /// The time a countdown counts against: the server's when it is known, else the phone's.
  DateTime now() => _deviceNow().add(skew ?? Duration.zero);

  /// The server's time, or null while only the phone's is known: what may be used to END or START a deal. A phone with a wrong clock
  /// must never hide a live deal; until a `server_time` arrives the phase the server reported wins.
  DateTime? trustedNow() => skew == null ? null : now();

  bool get running => _timer != null;

  /// Starts ticking (does nothing when it already ticks).
  void start() => _timer ??= Timer.periodic(const Duration(seconds: 1), (_) => notifyListeners());

  /// Stops ticking: used while the app is in the background or the screen is covered, so no timer runs for nobody.
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

  /// The time a countdown counts against. Rebuilds the caller every second when there is a clock above it; with none it is the phone's
  /// time and nothing ticks.
  static DateTime nowOf(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<DealClockScope>();
    return scope?.notifier?.now() ?? DateTime.now();
  }

  /// The server's time for deciding a phase (see [DealClock.trustedNow]), or null while only the phone's is known or there is no clock.
  /// Rebuilds the caller every second when there is a clock above it.
  static DateTime? trustedNowOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<DealClockScope>()?.notifier?.trustedNow();
}

/// Owns a [DealClock] for the subtree: keeps it on the server's clock and ticks it only while somebody can see it: not while the app
/// is in the background, not while the screen is covered by another page (its tickers are off then: [TickerMode]), and not while
/// [active] is false (a page with no deal on it).
class DealClockHost extends ConsumerStatefulWidget {
  const DealClockHost({super.key, required this.child, this.active = true});

  final Widget child;

  /// False keeps the clock stopped, whatever else: the host stays in the tree (so the page under it is never rebuilt from scratch when
  /// a deal appears or goes), it just does not tick.
  final bool active;

  @override
  ConsumerState<DealClockHost> createState() => _DealClockHostState();
}

class _DealClockHostState extends ConsumerState<DealClockHost> with WidgetsBindingObserver {
  late final DealClock _clock;
  bool _appVisible = true;
  bool _screenVisible = true;

  @override
  void initState() {
    super.initState();
    _clock = DealClock(skew: ref.read(dealSkewProvider), deviceNow: ref.read(dealDeviceNowProvider));
    WidgetsBinding.instance.addObserver(this);
    final state = WidgetsBinding.instance.lifecycleState;
    _appVisible = state == null || state == AppLifecycleState.resumed;
    ref.listenManual(dealSkewProvider, (_, skew) {
      _clock.skew = skew;
      _clock.refresh();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // A page covered by another one has its tickers switched off: it needs no clock until it is on top again.
    _screenVisible = TickerMode.valuesOf(context).enabled;
    _sync();
  }

  @override
  void didUpdateWidget(covariant DealClockHost oldWidget) {
    super.didUpdateWidget(oldWidget);
    _sync();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _appVisible = state == AppLifecycleState.resumed;
    _sync();
  }

  /// Ticks exactly while the app, the screen and the page all want it; shows the right time at once when it starts again.
  void _sync() {
    if (widget.active && _appVisible && _screenVisible) {
      if (!_clock.running) {
        _clock.start();
        // Whatever the clock missed while it was stopped is shown at once.
        _clock.refresh();
      }
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

/// Calls [onDue] once when the time reaches [dueAt] (a deal ended, or an upcoming one started), then again while the moment is still
/// past, with a growing pause ([retry], doubling up to [maxRetry]): the answer it asked for may still be stale, and a phone whose clock
/// is wrong (no `server_time` seen yet) must not make a page ask every few seconds for as long as it is open. Draws nothing.
class DealDueWatcher extends StatefulWidget {
  const DealDueWatcher({
    super.key,
    required this.dueAt,
    required this.onDue,
    this.retry = const Duration(seconds: 15),
    this.maxRetry = const Duration(minutes: 5),
  });

  final DateTime? dueAt;
  final VoidCallback onDue;
  final Duration retry;
  final Duration maxRetry;

  @override
  State<DealDueWatcher> createState() => _DealDueWatcherState();
}

class _DealDueWatcherState extends State<DealDueWatcher> {
  DateTime? _firedFor;
  DateTime? _firedAt;
  var _pause = Duration.zero;

  @override
  Widget build(BuildContext context) {
    final due = widget.dueAt;
    final now = DealClockScope.nowOf(context);
    if (due != null && !now.isBefore(due)) {
      final first = _firedFor != due || _firedAt == null;
      if (first || now.difference(_firedAt!) >= _pause) {
        _pause = first
            ? widget.retry
            : Duration(microseconds: (_pause.inMicroseconds * 2).clamp(0, widget.maxRetry.inMicroseconds));
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
