import 'dart:async';

import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/ops/driver/driver_tracker.dart';
import 'package:albahar_customer/ops/ops_live.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final driverMeProvider = FutureProvider.autoDispose<DriverMe>((ref) => ref.watch(opsRepositoryProvider).driver());

/// Real-time pings for this driver (`private-drivers.{id}`): a job was assigned or changed.
final driverEventsProvider = StreamProvider.autoDispose<PusherEvent>((ref) {
  final id = ref.watch(driverMeProvider.select((m) => m.value?.id));
  if (id == null || id.isEmpty) return const Stream.empty();
  return ref.watch(pusherClientProvider).channel('private-drivers.$id');
});

final driverJobsProvider = FutureProvider.autoDispose.family<List<DriverJob>, bool>(
  (ref, history) => ref.watch(opsRepositoryProvider).jobs(history: history),
);

/// The job list as a window sees it: the rows, and whether the list is up to date (so a window can tell "this job left
/// my list" from "the list is still reloading").
final driverLiveJobsProvider = Provider.autoDispose.family<LiveList<DriverJob>, bool>(
  (ref, history) => liveOf(ref.watch(driverJobsProvider(history))),
);

/// Builds the location tracker (replaced in tests so no GPS or network is touched).
final driverTrackerProvider = Provider<DriverTracker>((ref) {
  final tracker = DriverTracker(ref.watch(opsRepositoryProvider));
  ref.onDispose(() => unawaited(tracker.dispose()));
  return tracker;
});
