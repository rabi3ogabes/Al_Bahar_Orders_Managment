import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/notifications/domain/notification_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class NotificationsRepository {
  NotificationsRepository(this._api);
  final ApiClient _api;

  Future<Paginated<AppNotification>> list({int page = 1}) =>
      _api.getPage('/me/notifications', AppNotification.fromJson, query: {'page': page});

  Future<int> unreadCount() async {
    final body = asMap(await _api.getRaw('/me/notifications', query: {'per_page': 1}));
    return asInt(asMap(body['meta'])['unread_count']);
  }

  Future<void> markRead(String id) async => _api.post('/me/notifications/$id/read');

  Future<void> markAllRead() async => _api.post('/me/notifications/read-all');
}

final notificationsRepositoryProvider = Provider<NotificationsRepository>(
  (ref) => NotificationsRepository(ref.watch(apiClientProvider)),
);
