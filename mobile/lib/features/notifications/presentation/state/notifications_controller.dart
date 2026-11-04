import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/notifications/data/notifications_repository.dart';
import 'package:albahar_customer/features/notifications/domain/notification_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class NotificationsController extends PagedNotifier<AppNotification> {
  @override
  void watchDependencies() {
    ref.watch(isAuthenticatedProvider);
    ref.watch(localeCodeProvider);
  }

  @override
  Future<Paginated<AppNotification>> fetchPage(int page) async {
    final result = await ref.read(notificationsRepositoryProvider).list(page: page);
    final unread = result.extraMeta['unread_count'];
    if (unread != null) ref.read(unreadCountProvider.notifier).set(asInt(unread));
    return result;
  }

  Future<void> markRead(AppNotification n) async {
    if (n.isRead) return;
    updateItems((items) => [for (final i in items) i.id == n.id ? i.markedRead() : i]);
    ref.read(unreadCountProvider.notifier).decrement();
    try {
      await ref.read(notificationsRepositoryProvider).markRead(n.id);
    } catch (_) {}
  }

  Future<void> markAllRead() async {
    await ref.read(notificationsRepositoryProvider).markAllRead();
    updateItems((items) => [for (final i in items) i.isRead ? i : i.markedRead()]);
    ref.read(unreadCountProvider.notifier).set(0);
  }
}

final notificationsProvider = AsyncNotifierProvider.autoDispose<NotificationsController, PagedState<AppNotification>>(
  NotificationsController.new,
);

/// Unread badge (refreshed on app resume / list load / push).
class UnreadCount extends Notifier<int> {
  @override
  int build() {
    if (ref.watch(isAuthenticatedProvider)) Future.microtask(refresh);
    return 0;
  }

  Future<void> refresh() async {
    if (!ref.read(isAuthenticatedProvider)) return;
    try {
      final c = await ref.read(notificationsRepositoryProvider).unreadCount();
      if (ref.mounted) state = c;
    } catch (_) {}
  }

  void set(int v) => state = v < 0 ? 0 : v;
  void decrement() => set(state - 1);
}

final unreadCountProvider = NotifierProvider<UnreadCount, int>(UnreadCount.new);
