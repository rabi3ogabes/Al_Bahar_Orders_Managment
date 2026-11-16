import 'dart:async';

import 'package:albahar_customer/core/realtime/live_host.dart';
import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The inbox follows the server while the app is open, with or without push:
///  - a `notification.created` message on the customer's own channel (`private-users.{id}`) moves the unread badge at once (the message
///    carries the new count) and, one beat later, reads the list again;
///  - every reconnect of the socket, return to the foreground and return of the network reads the badge again (the list too when the badge
///    went up, so a notification that arrived while the app slept shows);
///  - while the socket is down, the badge is read every 30 seconds, and the list when it shows something new.
/// It is what makes a new notification show up in real time on a phone where Firebase push has not been set up or does not arrive: push
/// is the way to reach an app that is closed, this is the way to tell one that is open. Watched once by the app widget; it ends with the
/// sign-in.
final notificationLiveProvider = Provider<void>((ref) {
  if (!ref.watch(isAuthenticatedProvider)) return;

  late final LiveSync sync;
  sync = bindLiveSync(
    ref,
    pollEvery: const Duration(seconds: 30),
    resync: (reason) => unawaited(_readInbox(ref, listToo: reason == LiveResync.event)),
  );
  ref.listen(userAuctionEventsProvider, (_, next) {
    final e = next.value;
    if (e == null || e.event != 'notification.created') return;
    final unread = asIntOrNull(e.data['unread_count']);
    if (unread != null) ref.read(unreadCountProvider.notifier).set(unread);
    sync.eventArrived();
  });
});

/// Reads the unread badge again, and the list when asked or when the badge says there is something new.
Future<void> _readInbox(Ref ref, {required bool listToo}) async {
  if (!ref.mounted) return;
  final before = ref.read(unreadCountProvider);
  await ref.read(unreadCountProvider.notifier).refresh();
  if (!ref.mounted) return;
  if (listToo || ref.read(unreadCountProvider) > before) ref.invalidate(notificationsProvider);
}
