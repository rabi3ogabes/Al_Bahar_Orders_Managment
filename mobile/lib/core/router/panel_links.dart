import 'dart:async';

import 'package:albahar_customer/features/notifications/presentation/notifications_screen.dart';
import 'package:albahar_customer/features/orders/presentation/order_detail_screen.dart';
import 'package:albahar_customer/features/support/presentation/cms_page_screen.dart';
import 'package:albahar_customer/features/support/presentation/support_screens.dart';
import 'package:albahar_customer/features/wishlist/presentation/wishlist_screen.dart';
import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

/// Opens an in-app [location] beside the current page when it is a record or a small list (an order, a support
/// ticket, the wishlist, the notifications), and as a page for anything else. The pages stay reachable by their
/// own URL: a push notification, a link or "Open full page" still opens the full screen.
Future<void> openLocation(BuildContext context, String location) async {
  final uri = Uri.tryParse(location);
  final path = uri?.path ?? location;
  final order = RegExp(r'^/orders/([^/]+)$').firstMatch(path);
  if (order != null && uri?.queryParameters['placed'] != '1') {
    return showOrderPanel(context, order.group(1)!);
  }
  final ticket = RegExp(r'^/support/([^/]+)$').firstMatch(path);
  if (ticket != null && ticket.group(1) != 'new') return showTicketPanel(context, ticket.group(1)!);
  final page = RegExp(r'^/pages/([^/]+)$').firstMatch(path);
  if (page != null) return showCmsPage(context, page.group(1)!);
  if (path == '/wishlist') return showWishlistPanel(context);
  if (path == '/notifications') return showNotificationsPanel(context);
  unawaited(GoRouter.of(context).push(location));
}
