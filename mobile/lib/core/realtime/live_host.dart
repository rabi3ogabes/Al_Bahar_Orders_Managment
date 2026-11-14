import 'dart:async';
import 'dart:io' show Platform;

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Whether the phone has a network, as it changes (true = some connection). Replaced in tests; a phone without the plugin (web, tests)
/// simply never reports a change.
final networkChangesProvider = Provider<Stream<bool>>((ref) {
  // `flutter test` has no connectivity plugin (its missing channel is reported asynchronously and fails the test): tests give their own.
  if (!kIsWeb && Platform.environment.containsKey('FLUTTER_TEST')) return const Stream<bool>.empty();
  try {
    return Connectivity().onConnectivityChanged
        .map((results) => !results.contains(ConnectivityResult.none) || results.length > 1)
        .handleError((Object _) {});
  } catch (_) {
    return const Stream<bool>.empty();
  }
});

/// The phone's own lifecycle, as the apps need it: the app came to the front / went to the back.
class _Lifecycle with WidgetsBindingObserver {
  _Lifecycle(this.sync);
  final LiveSync sync;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      sync.resumed();
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      sync.paused();
    }
  }
}

/// Builds the [LiveSync] of one live screen group and wires it to what only the phone knows: the shared socket, the app being sent to
/// the background and brought back, and the network going and coming. Everything is released with [ref]. [resync] is the screen's own
/// way of reading again.
LiveSync bindLiveSync(Ref ref, {required void Function(LiveResync reason) resync, Duration? pollEvery}) {
  final PusherClient client = ref.watch(pusherClientProvider);
  final sync = LiveSync(
    readState: () => client.state,
    states: client.states,
    resync: resync,
    probeSocket: client.probe,
    reconnectSocket: client.reconnectNow,
    pollEvery: pollEvery ?? const Duration(seconds: 15),
  );
  final lifecycle = _Lifecycle(sync);
  WidgetsBinding.instance.addObserver(lifecycle);
  // The customer app keeps `onlineProvider` current itself; the two staff apps have no such watcher, so the live screens do it for them.
  final changes = ref.read(networkChangesProvider).listen((online) {
    ref.read(onlineProvider.notifier).set(online);
  });
  ref.listen<bool>(onlineProvider, (_, online) => sync.network(online: online));
  ref.onDispose(() {
    WidgetsBinding.instance.removeObserver(lifecycle);
    unawaited(changes.cancel());
    sync.dispose();
  });
  sync.network(online: ref.read(onlineProvider));
  sync.start();
  return sync;
}
