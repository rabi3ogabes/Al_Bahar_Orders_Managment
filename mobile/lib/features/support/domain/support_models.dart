import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

@immutable
class TicketMessage {
  const TicketMessage({
    required this.id,
    required this.body,
    required this.fromCustomer,
    this.authorName,
    this.createdAt,
  });
  factory TicketMessage.fromJson(JsonMap json) {
    // Backend: `from` = `customer` | `staff`.
    final authorType = asStringOrNull(json['from'] ?? json['author_type'] ?? json['sender_type']);
    return TicketMessage(
      id: asString(json['id']),
      body: asString(json['body']),
      fromCustomer: authorType == null
          ? asBool(json['is_customer'] ?? json['is_mine'], true)
          : authorType == 'customer',
      authorName: asStringOrNull(json['author_name'] ?? asMapOrNull(json['author'])?['name']),
      createdAt: asDate(json['created_at']),
    );
  }
  final String id;
  final String body;
  final bool fromCustomer;
  final String? authorName;
  final DateTime? createdAt;
}

@immutable
class SupportTicket {
  const SupportTicket({
    required this.id,
    required this.subject,
    required this.status,
    this.number,
    this.statusLabel,
    this.category,
    this.orderId,
    this.createdAt,
    this.updatedAt,
    this.messages = const [],
  });
  factory SupportTicket.fromJson(JsonMap json) => SupportTicket(
    id: asString(json['id']),
    number: asStringOrNull(json['number'] ?? json['reference']),
    subject: asString(json['subject']),
    status: asString(json['status'], 'open'),
    statusLabel: asStringOrNull(json['status_label']),
    category: asStringOrNull(json['category']),
    orderId: asStringOrNull(json['order_id'] ?? asMapOrNull(json['order'])?['id']),
    createdAt: asDate(json['created_at']),
    updatedAt: asDate(json['updated_at'] ?? json['last_message_at']),
    messages: mapList(json['messages'], TicketMessage.fromJson),
  );
  final String id;
  final String? number;
  final String subject;

  /// Backend: `open` | `pending_customer` | `pending_staff` | `resolved` | `closed`.
  final String status;

  /// Server-localised label (list rows only).
  final String? statusLabel;
  final String? category;
  final String? orderId;
  final DateTime? createdAt;
  final DateTime? updatedAt;
  final List<TicketMessage> messages;

  bool get isClosed => status == 'closed';
}

/// Ticket categories (subset of the backend's
/// `order, delivery, payment, product, auction, return, account, other`).
const ticketCategories = ['order', 'payment', 'delivery', 'product', 'auction', 'return', 'account', 'other'];

@immutable
class CmsPage {
  const CmsPage({required this.slug, required this.title, required this.bodyHtml, this.updatedAt});
  factory CmsPage.fromJson(JsonMap json) => CmsPage(
    slug: asString(json['slug']),
    title: asString(json['title']),
    bodyHtml: asString(json['body']),
    updatedAt: asDate(json['updated_at']),
  );
  final String slug;
  final String title;
  final String bodyHtml;
  final DateTime? updatedAt;
}
