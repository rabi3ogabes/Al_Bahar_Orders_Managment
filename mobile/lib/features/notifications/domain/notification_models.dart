import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

@immutable
class AppNotification {
  const AppNotification({
    required this.id,
    required this.event,
    required this.title,
    required this.body,
    this.data = const {},
    this.readAt,
    this.createdAt,
  });

  factory AppNotification.fromJson(JsonMap json) => AppNotification(
    id: asString(json['id']),
    event: asString(json['event']),
    title: asString(json['title']),
    body: asString(json['body']),
    data: asMap(json['data']),
    readAt: asDate(json['read_at']),
    createdAt: asDate(json['created_at']),
  );

  final String id;
  final String event;
  final String title;
  final String body;
  final JsonMap data;
  final DateTime? readAt;
  final DateTime? createdAt;

  bool get isRead => readAt != null;

  /// Payload passed to the notification router (adds `event`).
  JsonMap get routingPayload => {...data, 'event': event, 'notification_id': id};

  AppNotification markedRead() =>
      AppNotification(id: id, event: event, title: title, body: body, data: data, readAt: DateTime.now(), createdAt: createdAt);
}
