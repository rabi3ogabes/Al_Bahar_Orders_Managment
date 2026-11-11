import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// "order_progress" data messages become one live notification per order: a step bar (placed → preparing → on the way →
/// delivered) that is updated in place, stays pinned until delivery and then settles as a normal, dismissible card.
bool isOrderProgress(Map<String, dynamic> data) => data['kind'] == 'order_progress';

const _channel = 'albahar_order_progress';

Future<void> showOrderProgress(FlutterLocalNotificationsPlugin plugin, Map<String, dynamic> data) async {
  final step = int.tryParse('${data['step']}') ?? 0;
  final total = int.tryParse('${data['total']}') ?? 4;
  final done = data['done'] == '1' || step >= total - 1;
  final id = (data['order_id'] ?? data['number'] ?? '').toString().hashCode & 0x7fffffff;
  final label = (data['step_label'] ?? '').toString();
  final body = (data['body'] ?? '').toString();
  await plugin.show(
    id: id,
    title: (data['title'] ?? '').toString(),
    body: body,
    notificationDetails: NotificationDetails(
      android: AndroidNotificationDetails(
        _channel,
        'Order progress',
        channelDescription: 'Live status of your order',
        importance: Importance.high,
        priority: Priority.high,
        icon: 'ic_stat_notify',
        onlyAlertOnce: true,
        ongoing: !done,
        autoCancel: done,
        showProgress: !done,
        maxProgress: total - 1,
        progress: step.clamp(0, total - 1),
        subText: label,
        styleInformation: BigTextStyleInformation(
          body,
          contentTitle: (data['title'] ?? '').toString(),
          summaryText: label,
        ),
        category: AndroidNotificationCategory.progress,
      ),
      iOS: const DarwinNotificationDetails(threadIdentifier: 'order'),
    ),
    payload: jsonEncode(data),
  );
}

@visibleForTesting
int orderProgressId(String key) => key.hashCode & 0x7fffffff;
